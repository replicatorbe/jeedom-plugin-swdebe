<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

require_once __DIR__ . '/../../../../core/php/core.inc.php';

class swdebe extends eqLogic {

    /*
     * swde.be ne publie pas d'API : le plugin interroge trois pages du site.
     * L'autocomplete renvoie du JSON et sert de détection rapide ; la page de
     * recherche /fr/works?text=<CP> liste les incidents du code postal, et la
     * page de détail /fr/incident/... porte les champs utiles — dates ISO,
     * perturbation, localité, coordonnées GPS — dans un balisage Drupal
     * déterministe (classes field--name-field-inc-*).
     */
    const BASE_URL         = 'https://www.swde.be';
    const WORKS_URL        = 'https://www.swde.be/fr/works?text=';
    const AUTOCOMPLETE_URL = 'https://www.swde.be/fr/search_api_autocomplete/search?display=page_search&filter=keywords&q=';

    const DEFAULT_TIMEOUT  = 10;
    const USER_AGENT       = 'Jeedom-SWDE-Plugin/0.1 (+https://github.com/replicatorbe/jeedom-plugin-swdebe)';

    /*
     * Un incident SWDE dure typiquement quelques heures. Quinze minutes entre
     * deux lectures suffisent largement pour un usage domotique, tout en
     * restant poli vis-à-vis du service. La fréquence effective minimum est
     * de 15 min, imposée par le cron plugin cron15() : une valeur plus basse
     * dans la configuration n'avancerait rien.
     */
    const DEFAULT_REFRESH_MINUTES = 15;
    const MIN_REFRESH_MINUTES     = 15;

    /* Un forçage ne relit pas un état tout frais : sans ce plancher, un
     * scénario appelant « Rafraîchir » chaque minute matraquerait le site. */
    const FORCE_MIN_INTERVAL = 300;

    /* Après un échec, on laisse le service tranquille une heure : c'est au
     * moment où il va le plus mal qu'il ne faut pas le marteler. */
    const RETRY_DELAY = 3600;

    /* Un détail d'incident n'évolue pratiquement pas une fois l'intervention
     * annoncée : six heures de cache évitent de retélécharger la même page à
     * chaque cycle de rafraîchissement. */
    const DETAIL_TTL = 21600;

    /*
     * Blocs du cœur qu'une alerte ne doit pas jouer. « Attendre », « Pause »,
     * « Faire une demande » et les rapports retiennent leur processus pendant
     * des secondes ou des minutes : les actions d'une alerte sont jouées dans
     * le cron partagé par tous les plugins, qui serait tué au bout de cinq
     * minutes en accusant celui-ci. Les autres n'ont de sens que dans un
     * scénario (icon/tag mettent à jour un scénario, scenario_return le quitte).
     *
     * Le sélecteur de l'interface les écarte déjà ; ce contrôle vaut pour une
     * expression tapée à la main dans le champ, qui reste libre.
     */
    const NOTIFICATION_REFUSED = array(
        'wait', 'sleep', 'ask', 'report', 'exportHistory',
        'stop', 'log', 'scenario_return', 'icon', 'tag',
    );

    /*
     * Cause du dernier échec de lecture, pour que le contrôleur ajax puisse
     * répondre autre chose qu'un succès quand rien n'a été rafraîchi. Préfixée
     * par un souligné : sans cela, DB::save() la traite comme une colonne de
     * la table eqLogic et la création d'un équipement échoue sur « Unknown
     * column 'refreshError' ».
     */
    private $_refreshError = '';

    /*
     * Message informatif quand une lecture a été volontairement sautée (anti-
     * spam, retry en cours) : la distinction d'avec _refreshError évite que
     * le bouton « Rafraîchir » peigne en rouge une situation qui n'est pas
     * une erreur mais une protection.
     */
    private $_refreshInfo = '';

    /* ======================================================================= CRON */

    /*
     * Le cron plugin est réglé à l'heure dans Jeedom ; on honore la fréquence
     * configurée par l'utilisateur en lisant au bon moment seulement. 15 min
     * par défaut : un passage sur quatre fait vraiment du travail, les trois
     * autres sortent immédiatement.
     */
    public static function cron15() {
        $minutes = (int) config::byKey('refresh_minutes', __CLASS__, self::DEFAULT_REFRESH_MINUTES);
        if ($minutes < 5) {
            $minutes = 5;
        }
        foreach (self::byType(__CLASS__, true) as $eqLogic) {
            try {
                $eqLogic->update();
            } catch (Throwable $e) {
                // Un code postal en échec ne doit pas priver les autres de leur mise à jour.
                log::add(__CLASS__, 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
            }
        }
    }

    /* ===================================================== CYCLE DE VIE eqLogic */

    public function preSave() {
        /*
         * Un code postal belge est un entier à quatre chiffres. On nettoie
         * sans refuser : le coeur crée l'équipement avec son seul nom, et
         * toute exception ici rendrait le bouton « Ajouter » inutilisable.
         */
        $zipcode = trim((string) $this->getConfiguration('zipcode'));
        if (!preg_match('/^\d{4}$/', $zipcode)) {
            $zipcode = '';
        }
        $this->setConfiguration('zipcode', $zipcode);

        /* Les alertes arrivent du formulaire telles que le JS les a ramassées :
         * c'est ici, et pas à l'envoi, qu'on leur impose une forme. */
        $this->setConfiguration('notifications', self::cleanNotifications($this->getConfiguration('notifications')));

        /* 230 px est la largeur par défaut du coeur : trop étroit pour un
         * titre d'incident lisible. L'utilisateur reste libre de redimensionner. */
        if ($this->getDisplay('width') == '') {
            $this->setDisplay('width', '320px');
        }
    }

    public function postSave() {
        $this->createCommands();

        if ($this->isConfigured()) {
            try {
                /*
                 * Pas d'appel réseau à l'enregistrement : un postSave bloquant
                 * fait attendre l'utilisateur jusqu'à N × 10 s (page works +
                 * un détail par incident), et affiche un enregistrement vert
                 * même quand le service est inaccessible, donnant l'illusion
                 * que les commandes sont peuplées. update(false) écrit juste
                 * les commandes à partir du cache si l'état existe déjà, le
                 * cron cron15 fera la première lecture. Si le code postal a
                 * changé, l'état en cache est bon à jeter : le prochain
                 * passage du cron ira le remplacer, et on remet les commandes
                 * à zéro dans l'intervalle plutôt que d'afficher les anciens
                 * incidents d'un autre code postal.
                 */
                $state   = $this->getState();
                $changed = isset($state['zipcode']) && $state['zipcode'] !== $this->getConfiguration('zipcode');
                if ($changed) {
                    $this->clearState();
                }
                $this->update(false);
            } catch (Throwable $e) {
                // L'enregistrement ne doit pas échouer parce que le service est
                // indisponible : le code postal est valide, le cron réessaiera.
                log::add(__CLASS__, 'error', $this->getHumanName() . ' : ' . $e->getMessage());
            }
        }
    }

    public function preRemove() {
        $this->clearState();
        $this->clearDetails();
        // Sans cela, une installation future du même code postal verrait les
        // incidents actuellement actifs comme « déjà notifiés » et les
        // alertes resteraient muettes jusqu'à leur résolution.
        cache::delete($this->seenKey());
        return true;
    }

    public function isConfigured() {
        return preg_match('/^\d{4}$/', (string) $this->getConfiguration('zipcode')) === 1;
    }

    /* ======================================================================= CACHE */

    private function stateKey() {
        return 'swdebe::state::' . $this->getId();
    }

    /* Clé posée après un échec, qui expire d'elle-même : tant qu'elle existe,
     * on ne retente pas. */
    private function retryKey() {
        return 'swdebe::retry::' . $this->getId();
    }

    private function clearState() {
        cache::delete($this->stateKey());
        cache::delete($this->retryKey());
    }

    /*
     * Vide les entrées « détail d'incident » stockées pour cet équipement.
     * L'API cache de Jeedom ne sait pas chercher par préfixe : on part donc
     * du dernier état connu pour retrouver les clés à retirer. Un détail dont
     * l'URL ne figure plus dans l'état finira par expirer de lui-même via
     * son TTL de 6 h, mais nettoyer tout de suite ce qu'on connaît évite une
     * accumulation à long terme et sépare proprement deux installations
     * successives du plugin.
     */
    private function clearDetails() {
        $state = $this->getState();
        if (!isset($state['incidents']) || !is_array($state['incidents'])) {
            return;
        }
        foreach ($state['incidents'] as $incident) {
            $path = isset($incident['path']) ? $incident['path'] : '';
            if ($path === '' && isset($incident['url'])) {
                // Compat migration : avant le fix d'URL absolue, 'url' portait
                // le chemin relatif ; on retrouve le chemin pour hasher la clé.
                $parts = parse_url($incident['url']);
                $path  = isset($parts['path']) ? $parts['path'] : '';
            }
            if ($path !== '') {
                cache::delete('swdebe::detail::' . md5($path));
            }
        }
    }

    /* L'état en cache, ou un tableau vide s'il n'y en a pas encore. */
    public function getState() {
        $value = cache::byKey($this->stateKey())->getValue('');
        if ($value === '') {
            return array();
        }
        $state = json_decode($value, true);
        return is_array($state) ? $state : array();
    }

    /* ================================================================ COMMANDES */

    /* Crée les commandes manquantes sans jamais écraser la personnalisation. */
    private function addCmdIfMissing($_logicalId, $_name, $_type, $_subType, $_options = array()) {
        $cmd = $this->getCmd(null, $_logicalId);
        if (is_object($cmd)) {
            return $cmd;
        }
        $cmd = new swdebeCmd();
        $cmd->setEqLogic_id($this->getId());
        $cmd->setLogicalId($_logicalId);
        /*
         * La table cmd impose l'unicité du couple (eqLogic_id, name) : un nom
         * déjà pris ferait échouer l'enregistrement de tout l'équipement. On
         * suffixe plutôt que de laisser planter.
         */
        $name = __($_name, __FILE__);
        if (is_object(cmd::byEqLogicIdCmdName($this->getId(), $name))) {
            $name .= ' (' . $_logicalId . ')';
        }
        $cmd->setName($name);
        $cmd->setType($_type);
        $cmd->setSubType($_subType);
        $cmd->setIsVisible(isset($_options['isVisible']) ? $_options['isVisible'] : 1);
        $cmd->setIsHistorized(isset($_options['isHistorized']) ? $_options['isHistorized'] : 0);
        if (isset($_options['order'])) {
            $cmd->setOrder($_options['order']);
        }
        if (isset($_options['unite'])) {
            $cmd->setUnite($_options['unite']);
        }
        if (isset($_options['icon'])) {
            $cmd->setDisplay('icon', '<i class="' . $_options['icon'] . '"></i>');
        }
        $cmd->save();
        return $cmd;
    }

    private function createCommands() {
        $order = 0;
        $this->addCmdIfMissing('etat', 'Incident en cours', 'info', 'binary', array(
            'order'        => $order++,
            'isHistorized' => 1,
            'icon'         => 'fas fa-tint',
        ));
        $this->addCmdIfMissing('nbIncidents', 'Nombre d\'incidents', 'info', 'numeric', array(
            'order'        => $order++,
            'isHistorized' => 1,
            'icon'         => 'fas fa-list',
        ));
        $this->addCmdIfMissing('titre', 'Dernier incident', 'info', 'string', array(
            'order' => $order++,
        ));
        $this->addCmdIfMissing('perturbation', 'Perturbation', 'info', 'string', array(
            'order' => $order++,
            'icon'  => 'fas fa-exclamation-triangle',
        ));
        $this->addCmdIfMissing('dateDebut', 'Date de début', 'info', 'string', array(
            'order'     => $order++,
            'isVisible' => 0,
        ));
        $this->addCmdIfMissing('dateFin', 'Date de fin prévue', 'info', 'string', array(
            'order'     => $order++,
            'isVisible' => 0,
        ));
        $this->addCmdIfMissing('lieu', 'Localité', 'info', 'string', array(
            'order'     => $order++,
            'isVisible' => 0,
        ));
        $this->addCmdIfMissing('url', 'URL de l\'incident', 'info', 'string', array(
            'order'     => $order++,
            'isVisible' => 0,
        ));
        $this->addCmdIfMissing('details', 'Détails (JSON)', 'info', 'string', array(
            'order'     => $order++,
            'isVisible' => 0,
        ));
        $this->addCmdIfMissing('refresh', 'Rafraîchir', 'action', 'other', array(
            'order' => $order++,
            'icon'  => 'fas fa-sync',
        ));
    }

    /*
     * Rattrape les commandes apparues avec une version du plugin postérieure
     * à la création de l'équipement. Appelée par swdebe_update() ; le coeur ne
     * les crée qu'à l'enregistrement (postSave).
     */
    public function ensureCommands() {
        $this->createCommands();
    }

    /*
     * Écrit une commande, sauf quand elle est déjà vide et le reste. Le coeur
     * traite « valeur vide » comme un changement systématique
     * (eqLogic::checkAndUpdateCmd) : sans ce filtre, les commandes qui restent
     * vides une bonne partie du temps (titre, perturbation hors incident)
     * émettraient un évènement à chaque cron.
     *
     * Surtout pas nommée setCmd() : à l'enregistrement, le coeur passe le
     * formulaire à utils::a2o() qui construit « set » + clé reçue et l'appelle
     * sur la classe. La page envoie toujours une clé « cmd » portant les
     * lignes du tableau des commandes : setCmd() privée tuerait l'enregistrement
     * sur une erreur fatale, avant toute écriture.
     */
    private function publishCmd($_logicalId, $_value) {
        if ($_value === '') {
            $cmd = $this->getCmd(null, $_logicalId);
            if (is_object($cmd) && $cmd->execCmd() === '') {
                return;
            }
        }
        $this->checkAndUpdateCmd($_logicalId, $_value);
    }

    /* ============================================================== RAFRAICHISSEMENT */

    /*
     * Point d'entrée unique. L'état est relu au service seulement s'il est
     * absent, périmé, ou si l'appel est forcé ; les commandes, elles, sont
     * recalculées à chaque passage pour refléter l'état en cache.
     */
    public function update($_force = false) {
        $this->_refreshError = '';
        $this->_refreshInfo  = '';

        if (!$this->isConfigured()) {
            $this->_refreshError = __('Code postal invalide : quatre chiffres attendus.', __FILE__);
            return array();
        }

        $state = $this->getState();
        $age   = isset($state['fetchedAt']) ? (time() - (int) $state['fetchedAt']) : PHP_INT_MAX;

        if ($_force && $age <= self::FORCE_MIN_INTERVAL) {
            /*
             * L'état a moins de cinq minutes : le relire n'apprendrait rien.
             * On trace via _refreshInfo (pas _refreshError) pour que le bouton
             * « Rafraîchir » affiche la raison explicite comme une info et
             * non comme une panne.
             */
            $_force = false;
            $this->_refreshInfo = __('Lecture ignorée : état mis à jour il y a moins de 5 minutes.', __FILE__);
        }

        $minutes = (int) config::byKey('refresh_minutes', __CLASS__, self::DEFAULT_REFRESH_MINUTES);
        if ($minutes < self::MIN_REFRESH_MINUTES) {
            $minutes = self::MIN_REFRESH_MINUTES;
        }
        $ttl = $minutes * 60;
        $waiting = (cache::byKey($this->retryKey())->getValue('') !== '');
        if ($waiting && $_force) {
            // Backoff en cours : on refuse même un forçage, et on le dit.
            $_force = false;
            $this->_refreshInfo = __('Lecture ignorée : le service a échoué récemment, nouvelle tentative dans un instant.', __FILE__);
        }

        if ($_force || ($age > $ttl && !$waiting)) {
            try {
                $fresh = $this->fetchIncidents();
                $state = $fresh;
                cache::set($this->stateKey(), json_encode($state), 0);
                cache::delete($this->retryKey());

                /*
                 * Détection événementielle des nouveaux incidents : on diffère
                 * à AVANT refreshCommands pour que `etat` passe à 1 au même
                 * cycle où les alertes partent — un scénario qui écoute `etat`
                 * et une alerte intégrée arrivent à la même seconde.
                 */
                try {
                    $this->checkNewIncidents(isset($state['incidents']) ? $state['incidents'] : array());
                } catch (Throwable $e) {
                    // Une alerte en échec ne doit pas rater le refreshCommands
                    // qui suit : les commandes doivent refléter l'état lu.
                    log::add(__CLASS__, 'error', $this->getHumanName() . ' alertes : ' . $e->getMessage());
                }
            } catch (Throwable $e) {
                /*
                 * L'état déjà connu reste affiché : une panne du service ne
                 * doit pas vider les commandes.
                 */
                $this->_refreshError = $e->getMessage();
                cache::set($this->retryKey(), time(), self::RETRY_DELAY);
                log::add(__CLASS__, 'error', $this->getHumanName() . ' : ' . $e->getMessage());
                if (empty($state)) {
                    throw $e;
                }
            }
        }

        $this->refreshCommands($state);
        return $state;
    }

    public function getRefreshError() {
        return $this->_refreshError;
    }

    public function getRefreshInfo() {
        return $this->_refreshInfo;
    }

    /* ================================================================== ALERTES */

    /*
     * Une alerte dit « quand un nouvel incident apparaît, joue ces actions ».
     * Pas d'heure, pas de filtre : la SWDE publie quand elle publie, et le
     * code postal est la seule qualification pertinente.
     *
     * Modèle (après cleanNotifications) :
     *   [ 'id' => '12-alnum', 'enable' => 0|1, 'actions' => [...] ]
     *
     * Chaque action est au format du sélecteur de scénarios du cœur :
     *   [ 'cmd' => '#id#' | '>>bloc<<...', 'options' => ['title'=>…,'message'=>…,'enable'=>…,'background'=>…] ]
     */

    public static function cleanNotifications($_notifications) {
        if (!is_array($_notifications)) {
            return array();
        }
        $clean = array();
        foreach ($_notifications as $notification) {
            if (!is_array($notification)) {
                continue;
            }
            $id = isset($notification['id']) ? preg_replace('/[^a-zA-Z0-9]/', '', (string) $notification['id']) : '';
            if ($id === '') {
                $id = substr(md5(uniqid('swdebe', true)), 0, 12);
            }
            $clean[] = array(
                'id'         => $id,
                'enable'     => (isset($notification['enable']) && $notification['enable'] == 1) ? 1 : 0,
                'actions'    => self::cleanActions(isset($notification['actions']) ? $notification['actions'] : array()),
                /*
                 * Actions optionnelles jouées quand un incident déjà notifié
                 * disparaît : utile pour effacer un affichage matrix dont la
                 * durée n'était pas encore écoulée. Vide = pas de nettoyage,
                 * les actions start se taisent toutes seules (durée courte,
                 * notification push qui s'efface d'elle-même, etc.).
                 */
                'endActions' => self::cleanActions(isset($notification['endActions']) ? $notification['endActions'] : array()),
            );
        }
        return $clean;
    }

    public static function cleanActions($_actions) {
        $actions = array();
        if (!is_array($_actions)) {
            return $actions;
        }
        foreach ($_actions as $action) {
            if (!is_array($action) || trim((string) (isset($action['cmd']) ? $action['cmd'] : '')) === '') {
                // Une ligne d'action vide est une ligne que l'utilisateur a
                // ajoutée sans la remplir : la garder n'apporte rien.
                continue;
            }
            $actions[] = array(
                'cmd'     => trim((string) $action['cmd']),
                'options' => (isset($action['options']) && is_array($action['options'])) ? $action['options'] : array(),
            );
        }
        return $actions;
    }

    public function notifications() {
        return self::cleanNotifications($this->getConfiguration('notifications'));
    }

    /* Mémoire des nodeId déjà notifiés pour cet équipement.
     *
     * TTL 0 (permanent) : un incident résolu disparaît du site, son nodeId
     * sort de l'état courant, le diff ne le re-déclenche jamais. Si le même
     * incident est réouvert (nouveau nodeId côté SWDE), il déclenche à nouveau.
     * Pas besoin de peremption. */
    private function seenKey() {
        return 'swdebe::seen::' . $this->getId();
    }

    private function getSeenIds() {
        $raw = cache::byKey($this->seenKey())->getValue('');
        if ($raw === '') {
            return null;  // null = jamais initialisé, distinct du tableau vide
        }
        $ids = json_decode($raw, true);
        return is_array($ids) ? $ids : array();
    }

    private function saveSeenIds($_ids) {
        cache::set($this->seenKey(), json_encode(array_values(array_unique(array_map('intval', $_ids)))), 0);
    }

    /*
     * Compare les nodeId du cycle courant à la mémoire : les incidents
     * nouvellement apparus déclenchent les alertes « start », ceux qui ont
     * disparu depuis la dernière lecture déclenchent les alertes « end »
     * (seulement si l'alerte avait été envoyée à l'ouverture — pas de
     * scene_stop fantôme pour un incident qu'on n'a jamais annoncé).
     *
     * Au tout premier passage (mémoire absente), on initialise sans
     * déclencher — l'utilisateur ne veut pas être harcelé par les incidents
     * déjà en cours au moment où il installe le plugin.
     */
    private function checkNewIncidents($_incidents) {
        // Un incident sans nodeId n'est qu'à moitié parsé : l'inclure forcerait
        // un re-déclenchement à chaque cycle (son identité changerait à chaque
        // lecture). On l'ignore pour la mémoire.
        $valid = array();
        $unparsed = 0;
        foreach ($_incidents as $incident) {
            if (isset($incident['nodeId']) && (int) $incident['nodeId'] > 0) {
                $valid[(int) $incident['nodeId']] = $incident;
            } else {
                $unparsed++;
            }
        }
        $currentIds = array_keys($valid);

        $lastIds = $this->getSeenIds();
        if ($lastIds === null) {
            // Premier cycle : on amorce la mémoire sans alerter.
            $this->saveSeenIds($currentIds);
            log::add(__CLASS__, 'info', $this->getHumanName() . ' : ' . __('mémoire des alertes initialisée', __FILE__)
                   . ' (' . count($currentIds) . ' ' . __('incident(s)', __FILE__) . ')');
            return;
        }

        $newIds        = array_diff($currentIds, $lastIds);
        $disappearedIds = array_diff($lastIds, $currentIds);

        // Un détail illisible ne dit pas quel incident il est : il peut s'agir
        // d'un incident déjà connu. Le déclarer terminé ferait partir les
        // alertes de fin, puis celles de début au cycle suivant. On attend
        // une lecture complète pour conclure à une disparition.
        if ($unparsed > 0) {
            $disappearedIds = array();
            $currentIds = array_values(array_unique(array_merge($currentIds, $lastIds)));
        }

        // Mémoire mise à jour tout de suite : si une alerte part en fatal, on
        // ne relance pas sa famille à chaque cron.
        $this->saveSeenIds($currentIds);

        if (count($newIds) === 0 && count($disappearedIds) === 0) {
            return;
        }

        $notifications = $this->notifications();
        $active = array_values(array_filter($notifications, function ($_n) {
            return $_n['enable'] == 1 && (count($_n['actions']) > 0 || count($_n['endActions']) > 0);
        }));
        if (count($active) === 0) {
            return;
        }

        /* ----- Incidents nouveaux : actions « start » ----- */
        foreach ($newIds as $nodeId) {
            $incident = $valid[$nodeId];
            foreach ($active as $notification) {
                /* Trace « cette alerte a vu cet incident à l'ouverture »
                 * posée AVANT d'écarter les alertes sans actions start :
                 * une alerte configurée uniquement avec des endActions
                 * (« juste effacer la matrix à la fin, pas de push ») doit
                 * voir son flag posé pour que son end se déclenche à la
                 * disparition — sinon le flag reste vide et le code du bloc
                 * suivant la laisse muette pour toujours. L'incident
                 * complet est stocké en valeur : les endActions auront
                 * ainsi #titre#, #lieu#, #perturbation# même quand la page
                 * SWDE a disparu. */
                cache::set($this->sentKey($notification['id'], $nodeId), json_encode($incident), self::SENT_MEMORY);
                if (count($notification['actions']) === 0) {
                    continue;
                }
                $this->runNotification($notification, $incident, 'actions');
            }
        }

        /* ----- Incidents disparus : actions « end » ----- */
        foreach ($disappearedIds as $nodeId) {
            foreach ($active as $notification) {
                if (count($notification['endActions']) === 0) {
                    continue;
                }
                $key   = $this->sentKey($notification['id'], $nodeId);
                $cached = cache::byKey($key)->getValue('');
                if ($cached === '') {
                    // Pas annoncé par cette alerte : rien à nettoyer.
                    continue;
                }
                $incident = json_decode($cached, true);
                if (!is_array($incident)) {
                    $incident = array('nodeId' => $nodeId);
                }
                $this->runNotification($notification, $incident, 'endActions');
                cache::delete($key);
            }
        }
    }

    /* Mémoire par couple (notification, incident) : « cette alerte a envoyé
     * son start pour cet incident ». La valeur est le snapshot JSON de
     * l'incident à l'instant du start — ses jetons sont ainsi disponibles
     * pour les endActions longtemps après que le détail aura disparu du
     * site. TTL confortable : un incident SWDE dure quelques heures ;
     * sept jours couvrent les plus longs sans encrasser le cache. */
    const SENT_MEMORY = 604800;

    private function sentKey($_notificationId, $_nodeId) {
        return 'swdebe::sent::' . $this->getId() . '::' . $_notificationId . '::' . (int) $_nodeId;
    }

    private function runNotification($_notification, $_incident, $_which = 'actions') {
        $actions = isset($_notification[$_which]) ? $_notification[$_which] : array();
        if (count($actions) === 0) {
            return array();
        }
        $tags = $this->notificationTags($_incident);
        $errors = $this->runActions($actions, $tags);

        $key = 'swdebe::notified::' . $this->getId() . '::' . $_notification['id'] . '::' . $_which . '::' . (int) $_incident['nodeId'];
        message::removeAll(__CLASS__, $key);
        if (count($errors) > 0) {
            message::add(__CLASS__, $this->getHumanName() . ' '
                       . __('alerte en échec :', __FILE__) . ' ' . implode(' ; ', $errors), '', $key);
        }
        return $errors;
    }

    /*
     * Joue une liste d'actions. Les jetons du cœur (variable(), date(),
     * #[Obj][Eq][Cmd]#) sont résolus AVANT les jetons du plugin, sinon un
     * libellé contenant une parenthèse — ex. « Baisse de pression (côté EST) »
     * — serait relu comme une expression par l'évaluateur du cœur.
     */
    private function runActions($_actions, $_tags) {
        $errors = array();

        foreach ($_actions as $action) {
            $options = is_array($action['options']) ? $action['options'] : array();
            if (isset($options['enable']) && $options['enable'] == 0) {
                continue;
            }
            $background = (isset($options['background']) && $options['background'] == 1);
            unset($options['enable'], $options['background']);

            // Jetons dans les options string (title, message, etc.).
            foreach ($options as $k => $v) {
                if (is_string($v)) {
                    $v = scenarioExpression::setTags($v);
                    $options[$k] = str_replace(array_keys($_tags), array_values($_tags), $v);
                }
            }

            $expression = (string) $action['cmd'];
            if (in_array($expression, self::NOTIFICATION_REFUSED, true)) {
                $errors[] = __('bloc inutilisable dans une alerte :', __FILE__) . ' ' . $expression;
                continue;
            }

            try {
                if (preg_match('/^#(\d+)#$/', $expression, $m)) {
                    $cmd = cmd::byId($m[1]);
                    if (!is_object($cmd)) {
                        throw new Exception(__('commande introuvable :', __FILE__) . ' ' . $expression);
                    }
                    if ($cmd->getType() !== 'action') {
                        throw new Exception(__("ce n'est pas une commande d'action :", __FILE__) . ' ' . $cmd->getHumanName());
                    }
                    $cmd->execCmd($options);
                } else {
                    // Jetons du cœur dans l'expression elle-même (une variable()
                    // en guise de bloc), puis les nôtres.
                    $expression = scenarioExpression::setTags($expression);
                    $expression = str_replace(array_keys($_tags), array_values($_tags), $expression);
                    $opts = $options;
                    $opts['source'] = $this->getHumanName();
                    scenarioExpression::createAndExec(
                        $background ? 'actionBackground' : 'action',
                        $expression,
                        $opts
                    );
                }
            } catch (Throwable $e) {
                $errors[] = $expression . ' : ' . $e->getMessage();
                log::add(__CLASS__, 'error', __('action en échec :', __FILE__) . ' ' . $expression . ' — ' . $e->getMessage());
            }
        }
        return $errors;
    }

    /*
     * Jetons substitués dans title / message / options des actions. Les dates
     * ISO 8601 sont rendues en format FR lisible : un push « incident jusque
     * sam 03/10/2026 22:00 » parle mieux que « 2026-10-03T22:00:00Z ».
     */
    private function notificationTags($_incident) {
        return array(
            '#titre#'        => isset($_incident['titre']) ? (string) $_incident['titre'] : '',
            '#perturbation#' => isset($_incident['perturbation']) ? (string) $_incident['perturbation'] : '',
            '#debut#'        => isset($_incident['dateDebut']) ? self::humanDate($_incident['dateDebut']) : '',
            '#fin#'          => isset($_incident['dateFin']) ? self::humanDate($_incident['dateFin']) : '',
            '#lieu#'         => isset($_incident['lieu']) ? (string) $_incident['lieu'] : '',
            '#url#'          => isset($_incident['url']) ? (string) $_incident['url'] : '',
            '#equipement#'   => $this->getName(),
            '#cp#'           => (string) $this->getConfiguration('zipcode'),
        );
    }

    /* ISO 8601 → « sam 03/10/2026 22:00 » en Europe/Brussels. Jamais lever :
     * un timestamp mal formé revient tel quel plutôt que de casser le texte.
     * Chaîne vide traitée explicitement : `new DateTimeImmutable('')` est
     * accepté par PHP comme « maintenant », et un jeton #fin# sur un
     * incident sans date de fin publierait l'heure courante — mensonge au
     * destinataire du push. */
    public static function humanDate($_iso) {
        $iso = (string) $_iso;
        if ($iso === '') {
            return '';
        }
        try {
            $tz = new DateTimeZone('Europe/Brussels');
            $date = new DateTimeImmutable($iso);
            $date = $date->setTimezone($tz);
            $days = array('dim', 'lun', 'mar', 'mer', 'jeu', 'ven', 'sam');
            return $days[(int) $date->format('w')] . ' ' . $date->format('d/m/Y H:i');
        } catch (Throwable $e) {
            return $iso;
        }
    }

    /*
     * Joue une alerte sur le dernier incident en cours connu, ou sur un
     * incident fictif si aucun. Appelée depuis le bouton Tester : on veut que
     * l'utilisateur voie passer son push/SMS sans avoir à attendre une vraie
     * coupure d'eau.
     */
    public function testNotification($_notificationId, $_which = 'actions') {
        if ($_which !== 'actions' && $_which !== 'endActions') {
            $_which = 'actions';
        }
        $notifications = $this->notifications();
        $target = null;
        foreach ($notifications as $n) {
            if ($n['id'] === $_notificationId) {
                $target = $n;
                break;
            }
        }
        if ($target === null) {
            throw new Exception(__("Alerte introuvable : enregistrez l'équipement avant de la tester.", __FILE__));
        }
        if (!isset($target[$_which]) || count($target[$_which]) === 0) {
            throw new Exception(__('Cette alerte ne déclenche aucune action pour ce test.', __FILE__));
        }

        $state = $this->getState();
        $incident = null;
        if (isset($state['incidents']) && is_array($state['incidents'])) {
            foreach ($state['incidents'] as $i) {
                if (isset($i['nodeId'])) {
                    $incident = $i;
                    break;
                }
            }
        }
        if ($incident === null) {
            // Faux incident : la machinerie reste exercée, l'utilisateur voit
            // bien son canal se déclencher, sans dépendre d'une vraie coupure.
            $incident = array(
                'nodeId'       => 0,
                'titre'        => __('Test — nouvel incident SWDE', __FILE__),
                'perturbation' => __("Coupure d'eau (test)", __FILE__),
                'dateDebut'    => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
                'dateFin'      => (new DateTimeImmutable('+2 hours', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
                'lieu'         => __('Localité fictive', __FILE__),
                'url'          => self::BASE_URL . '/fr/works',
            );
        }

        $errors = $this->runActions($target[$_which], $this->notificationTags($incident));
        return array(
            'incident' => $incident,
            'errors'   => $errors,
            'which'    => $_which,
        );
    }

    /* ======================================================================= SCRAPING */

    private function fetchIncidents() {
        $zipcode = (string) $this->getConfiguration('zipcode');

        /*
         * La page de recherche /fr/works?text=<CP> est la SEULE source
         * autoritaire : elle porte le badge « En cours » / « Résolu » et c'est
         * ce badge qui dit si un incident doit être exposé. L'autocomplete,
         * exploré pendant le prototypage, indexe aussi des pages archivées :
         * s'en servir pour nourrir la liste d'URLs faisait remonter des
         * incidents résolus comme actifs.
         */
        $html   = self::httpGet(self::WORKS_URL . rawurlencode($zipcode));
        $listed = self::parseIncidentList($html);

        $urls = array();
        foreach ($listed as $incident) {
            if ($incident['status'] !== 'en_cours') {
                continue;
            }
            $urls[$incident['url']] = true;
        }

        $incidents = array();
        foreach (array_keys($urls) as $path) {
            // URL absolue dès le stockage : la commande « url » reste cohérente
            // avec le JSON « details », et le lien du dashboard ne dépend plus
            // de la concat au moment du rendu.
            $absoluteUrl = self::BASE_URL . $path;
            try {
                $detail = $this->fetchIncidentDetail($path);
                $detail['url']  = $absoluteUrl;
                $detail['path'] = $path;
                $incidents[] = $detail;
            } catch (Throwable $e) {
                log::add(__CLASS__, 'debug', 'Détail incident échoué : ' . $path . ' ' . $e->getMessage());
                // On garde quand même une entrée minimale pour le compteur.
                $incidents[] = array('url' => $absoluteUrl, 'path' => $path);
            }
        }

        /*
         * Ordre stable : par date de début si disponible, sinon par URL. Sans
         * tri, deux lectures successives peuvent permuter l'ordre et faire
         * passer un incident B pour « le dernier » alors que A n'a pas bougé.
         */
        usort($incidents, function ($_a, $_b) {
            $da = isset($_a['dateDebut']) ? $_a['dateDebut'] : '';
            $db = isset($_b['dateDebut']) ? $_b['dateDebut'] : '';
            if ($da !== $db) {
                return strcmp($db, $da);  // plus récent d'abord
            }
            return strcmp((string) $_a['url'], (string) $_b['url']);
        });

        return array(
            'fetchedAt' => time(),
            'zipcode'   => $zipcode,
            'incidents' => $incidents,
        );
    }

    private function fetchIncidentDetail($_url) {
        $cacheKey = 'swdebe::detail::' . md5($_url);
        $cached = cache::byKey($cacheKey)->getValue('');
        if ($cached !== '') {
            $data = json_decode($cached, true);
            if (is_array($data) && isset($data['_cachedAt']) && (time() - $data['_cachedAt']) < self::DETAIL_TTL) {
                unset($data['_cachedAt']);
                return $data;
            }
        }

        $html = self::httpGet(self::BASE_URL . $_url);
        $detail = self::parseIncidentDetail($html);
        cache::set($cacheKey, json_encode(array_merge($detail, array('_cachedAt' => time()))), self::DETAIL_TTL);
        return $detail;
    }

    /*
     * Parse la liste de la page /fr/works?text=<CP>. Chaque lien d'incident
     * porte une classe CSS stable qui donne le statut sans ambiguïté :
     * `works-status-in-progress` pour un incident en cours, `works-status-solved`
     * pour un résolu. On ne lit PAS le textContent pour détecter le statut :
     * l'URL d'un incident en cours contient littéralement « ...est-en-cours »
     * dans son slug, et un libellé résolu dont la description contient le mot
     * « en cours » (« coupure en cours de réparation ») serait mal classé.
     *
     * Si la liste contient des liens mais qu'aucun n'expose de classe
     * `works-status-*`, on log en warning : SWDE a probablement changé son
     * balisage et le plugin devient aveugle sans cette alerte.
     *
     * Public pour que le jeu d'essai puisse valider le parseur sans HTTP.
     */
    public static function parseIncidentList($_html) {
        $doc = self::loadHtml($_html);
        $xpath = new DOMXPath($doc);

        $incidents = array();
        $seen = array();
        $links = $xpath->query('//a[contains(@href, "/fr/incident/")]');
        $withStatus = 0;
        foreach ($links as $link) {
            $href = $link->getAttribute('href');
            if (strpos($href, 'http') === 0) {
                $parts = parse_url($href);
                $href = isset($parts['path']) ? $parts['path'] : $href;
            }
            if (isset($seen[$href])) {
                continue;
            }
            $seen[$href] = true;

            $text   = self::squashSpaces($link->textContent);
            $status = 'inconnu';

            /*
             * Le badge est un enfant du <a> : on cherche le descendant
             * portant la classe `works-status-*`. Classement sur la variante
             * (-in-progress / -solved), pas sur son contenu textuel, qui est
             * localisable et donc fragile.
             */
            $badge = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " works-status ")]', $link);
            if ($badge->length > 0) {
                $classes = ' ' . $badge->item(0)->getAttribute('class') . ' ';
                if (strpos($classes, ' works-status-in-progress ') !== false) {
                    $status = 'en_cours';
                    $withStatus++;
                } elseif (strpos($classes, ' works-status-solved ') !== false) {
                    $status = 'resolu';
                    $withStatus++;
                }
            }

            $incidents[] = array(
                'url'    => $href,
                'text'   => $text,
                'status' => $status,
            );
        }

        if (count($incidents) > 0 && $withStatus === 0) {
            log::add(__CLASS__, 'warning', __('Aucun badge de statut reconnu dans la liste SWDE : le balisage a peut-être changé (classes works-status-*).', __FILE__));
        }

        return $incidents;
    }

    /*
     * Parse une page de détail /fr/incident/… en s'appuyant sur les classes
     * Drupal field--name-field-inc-* : le balisage est déterministe et stable
     * tant que le site n'est pas refondu.
     */
    public static function parseIncidentDetail($_html) {
        $doc = self::loadHtml($_html);
        $xpath = new DOMXPath($doc);

        $data = array();

        $titles = $xpath->query('//h1');
        if ($titles->length > 0) {
            $data['titre'] = self::squashSpaces($titles->item(0)->textContent);
        }

        /*
         * Node ID de l'incident : stable d'une lecture à l'autre. L'id de la
         * carte Leaflet (`leaflet-map-node-incident-<id>-field-geofield`) le
         * porte directement. data-drupal-link-system-path pointe sur TOUS les
         * liens de la page, y compris ceux du menu principal (works, 4953…) :
         * s'y fier donnerait l'id du dernier lien vu, pas celui de l'incident.
         */
        $maps = $xpath->query('//*[starts-with(@id, "leaflet-map-node-incident-")]');
        if ($maps->length > 0 && preg_match('/leaflet-map-node-incident-(\d+)/', $maps->item(0)->getAttribute('id'), $m)) {
            $data['nodeId'] = (int) $m[1];
        }

        // Dates ISO 8601 présentes en attribut datetime : pas de parsing FR foireux.
        $start = $xpath->query('//*[contains(@class, "field--name-field-inc-startdate")]//time[@datetime]');
        if ($start->length > 0) {
            $data['dateDebut'] = $start->item(0)->getAttribute('datetime');
        }
        $end = $xpath->query('//*[contains(@class, "field--name-field-inc-end-date")]//time[@datetime]');
        if ($end->length > 0) {
            $data['dateFin'] = $end->item(0)->getAttribute('datetime');
        }

        $desc = $xpath->query('//*[contains(@class, "field--name-field-inc-desc")]');
        if ($desc->length > 0) {
            $data['description'] = self::squashSpaces($desc->item(0)->textContent);
        }

        /*
         * Locality : le champ est en texte riche et contient parfois un
         * <style> inline qu'un textContent naïf aspire aussi. La vraie liste
         * est portée par des <li> à l'intérieur du field__item. S'il n'y en
         * a pas, on tombe sur le texte brut en retirant les <style>.
         */
        $localities = $xpath->query('//*[contains(@class, "field--name-field-locality")]//*[contains(@class, "field__item")]//li');
        if ($localities->length > 0) {
            $names = array();
            foreach ($localities as $li) {
                $t = self::squashSpaces($li->textContent);
                if ($t !== '') {
                    $names[] = $t;
                }
            }
            if (!empty($names)) {
                $data['lieu']   = implode(', ', $names);
                $data['lieux']  = $names;
            }
        }
        if (!isset($data['lieu'])) {
            $loc = $xpath->query('//*[contains(@class, "field--name-field-locality")]//*[contains(@class, "field__item")]');
            if ($loc->length > 0) {
                $node = $loc->item(0)->cloneNode(true);
                // Retirer les <style>/<meta> qui n'ont pas à partir dans le texte.
                $toRemove = array();
                foreach ($node->getElementsByTagName('style') as $s) { $toRemove[] = $s; }
                foreach ($node->getElementsByTagName('meta')  as $s) { $toRemove[] = $s; }
                foreach ($toRemove as $s) { $s->parentNode->removeChild($s); }
                $text = self::squashSpaces($node->textContent);
                if ($text !== '') {
                    $data['lieu'] = $text;
                }
            }
        }

        /*
         * Perturbations : liste à puces (eau coupée, baisse de pression,
         * eau colorée…). On concatène proprement avec séparateur ; chaque
         * entrée reste accessible via la commande JSON « details ».
         */
        $perturbations = array();
        $items = $xpath->query('//*[contains(@class, "field--name-field-perturbation")]//*[contains(@class, "field__item")]');
        foreach ($items as $item) {
            $txt = self::squashSpaces($item->textContent);
            if ($txt !== '' && !in_array($txt, $perturbations)) {
                $perturbations[] = $txt;
            }
        }
        if (!empty($perturbations)) {
            $data['perturbation']  = implode(', ', $perturbations);
            $data['perturbations'] = $perturbations;
        }

        // Géolocalisation (champ Drupal geofield → WKT POINT(lon lat)).
        $geo = $xpath->query('//*[contains(@class, "field--name-field-geofield")]');
        if ($geo->length > 0) {
            $raw = $geo->item(0)->textContent;
            if (preg_match('/POINT\s*\(\s*([-\d.]+)\s+([-\d.]+)\s*\)/i', $raw, $m)) {
                $data['lon'] = (float) $m[1];
                $data['lat'] = (float) $m[2];
            }
        }

        return $data;
    }

    private function refreshCommands($_state) {
        $incidents = isset($_state['incidents']) ? $_state['incidents'] : array();
        $n = count($incidents);

        $this->publishCmd('etat', $n > 0 ? 1 : 0);
        $this->publishCmd('nbIncidents', $n);

        if ($n > 0) {
            // Les incidents sont rangés plus récent d'abord par fetchIncidents().
            $i = $incidents[0];
            $this->publishCmd('titre', isset($i['titre']) ? $i['titre'] : '');
            $this->publishCmd('perturbation', isset($i['perturbation']) ? $i['perturbation'] : '');
            $this->publishCmd('dateDebut', isset($i['dateDebut']) ? $i['dateDebut'] : '');
            $this->publishCmd('dateFin', isset($i['dateFin']) ? $i['dateFin'] : '');
            $this->publishCmd('lieu', isset($i['lieu']) ? $i['lieu'] : '');
            // URL déjà absolue depuis fetchIncidents().
            $this->publishCmd('url', isset($i['url']) ? $i['url'] : '');
        } else {
            $this->publishCmd('titre', '');
            $this->publishCmd('perturbation', '');
            $this->publishCmd('dateDebut', '');
            $this->publishCmd('dateFin', '');
            $this->publishCmd('lieu', '');
            $this->publishCmd('url', '');
        }

        $this->publishCmd('details', json_encode($incidents));
    }

    /* ======================================================================= HTTP */

    private static function httpGet($_url) {
        $timeout = (int) config::byKey('http_timeout', __CLASS__, self::DEFAULT_TIMEOUT);
        if ($timeout <= 0) {
            $timeout = self::DEFAULT_TIMEOUT;
        }

        $ch = curl_init($_url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_HTTPHEADER     => array(
                'Accept: text/html,application/json,*/*;q=0.8',
                'Accept-Language: fr,fr-BE;q=0.9,en;q=0.5',
            ),
        ));
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new Exception(__('Erreur réseau SWDE : ', __FILE__) . $err);
        }
        if ($code >= 400) {
            throw new Exception(__('HTTP ', __FILE__) . $code . ' ' . __('sur', __FILE__) . ' ' . $_url);
        }
        return (string) $body;
    }

    /* ===================================================================== OUTILS */

    private static function loadHtml($_html) {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        // Préfixe utf-8 : sans cela, DOMDocument interprète la page comme ISO-8859-1
        // et abîme les accents dès le parsing. Préfixe <?xml... > suffit en PHP 7+.
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $_html);
        libxml_clear_errors();
        return $doc;
    }

    private static function squashSpaces($_text) {
        return trim(preg_replace('/\s+/u', ' ', (string) $_text));
    }

    /*
     * Bouton de test de la page d'équipement : on vérifie juste que le code
     * postal donne une réponse valide côté SWDE. Rend un petit résumé pour
     * l'interface, utilisé par l'ajax.
     */
    public static function testZipcode($_zipcode) {
        $zipcode = trim((string) $_zipcode);
        if (!preg_match('/^\d{4}$/', $zipcode)) {
            throw new Exception(__('Code postal invalide : quatre chiffres attendus.', __FILE__));
        }
        $html = self::httpGet(self::WORKS_URL . rawurlencode($zipcode));
        $listed = self::parseIncidentList($html);
        $actifs = array_values(array_filter($listed, function ($_i) {
            return $_i['status'] === 'en_cours';
        }));
        return array(
            'zipcode'  => $zipcode,
            'total'    => count($listed),
            'actifs'   => count($actifs),
            'titres'   => array_slice(array_column($actifs, 'text'), 0, 5),
        );
    }
}

class swdebeCmd extends cmd {

    /*
     * Classe cmd obligatoire, même limitée à l'action « Rafraîchir » : sans
     * elle, l'enregistrement d'un équipement échoue (le coeur l'instancie
     * par réflexion dès qu'une commande est présente).
     */
    public function execute($_options = null) {
        $eqLogic = $this->getEqLogic();
        if ($this->getLogicalId() === 'refresh') {
            $eqLogic->update(true);
        }
    }
}
