# Plugin SWDE

Surveille les incidents et interventions publiés par la **SWDE** (Société
Wallonne des Eaux) pour un ou plusieurs codes postaux belges, et expose l'état
en commandes Jeedom.

## À quoi ça sert

La SWDE publie en temps réel sur [swde.be](https://www.swde.be/fr/works) les
coupures, baisses de pression et problèmes de qualité d'eau en cours sur son
réseau. Pas d'API publique officielle : le plugin lit le site, détecte les
incidents actifs pour les codes postaux configurés, et met à jour ses
commandes.

## Installation

1. **Plugins → Gestion des plugins → Ajouter → Github** : utilisateur
   `replicatorbe`, dépôt `jeedom-plugin-swdebe`, branche `beta` ou `master`.
2. Activer le plugin.
3. Pas de dépendance à installer : tout est en PHP natif Jeedom.

## Configuration

### Paramètres du plugin

- **Délai d'attente des requêtes** : secondes avant d'abandonner un appel au
  site SWDE (défaut 10).
- **Fréquence de rafraîchissement** : minutes entre deux lectures (défaut et
  minimum 15 : le plugin ne lit pas le site plus souvent).

### Ajouter un code postal

1. Depuis la page du plugin, cliquer sur **Ajouter un code postal**.
2. Donner un nom à l'équipement (par exemple « SWDE Naast »).
3. Saisir le code postal belge (4 chiffres).
4. **Tester le code postal** interroge le site sans enregistrer : il affiche
   le nombre d'incidents actifs annoncés.
5. **Sauvegarder** : les commandes sont créées, le site est consulté, et le
   premier état est stocké.

## Commandes exposées

| Commande | Type | Contenu |
|---|---|---|
| **Incident en cours** | info / binary | 1 si au moins un incident est actif, 0 sinon |
| **Nombre d'incidents** | info / numeric | compteur d'incidents actifs sur le code postal |
| **Dernier incident** | info / string | titre de l'incident le plus récent |
| **Perturbation** | info / string | nature de la perturbation (coupure, pression, qualité…) |
| **Date de début** | info / string | ISO 8601 — par exemple `2026-10-03T09:00:00Z` |
| **Date de fin prévue** | info / string | ISO 8601 |
| **Localité** | info / string | commune/village précisé par la SWDE |
| **URL de l'incident** | info / string | lien direct vers la page SWDE |
| **Détails (JSON)** | info / string | liste complète des incidents, structurée |
| **Dernière vérification** | info / string | date de la dernière lecture réussie de swde.be |
| **Erreur de lecture** | info / binary | 1 tant que la dernière lecture a échoué (les incidents affichés sont alors ceux de la dernière vérification) |
| **Dernière erreur** | info / string | date et message de l'échec en cours, vide sinon |
| **Rafraîchir** | action | force une relecture immédiate |

## Scénarios typiques

- **Notification de coupure** : conditionner sur `Incident en cours == 1` et
  envoyer un message avec `Dernier incident` et `Date de fin prévue`.
- **Message vocal** : si `Perturbation` contient « coupure », annoncer sur TTS.
- **Dashboard** : afficher la tuile de l'équipement, qui porte le titre et
  l'état.

## Alertes intégrées (sans scénario)

L'onglet **Alertes** de chaque équipement permet d'être prévenu dès qu'un
nouvel incident apparaît sans écrire de scénario. Chaque alerte est une
liste d'actions — notification mobile, SMS, message vocal, allumage lampe,
etc. — jouées dans l'instant où la SWDE publie un nouvel incident pour le
code postal surveillé. Le sélecteur est celui des scénarios Jeedom : toutes
les commandes d'action et tous les blocs de message sont utilisables.

### Jetons disponibles dans le titre et le message

| Jeton | Contenu |
|---|---|
| `#titre#` | Titre complet de l'incident |
| `#perturbation#` | Coupure d'eau / Baisse de pression / Eau colorée |
| `#debut#` | Date et heure de début, format FR lisible (`sam 03/10/2026 09:00`) |
| `#fin#` | Date et heure de fin prévue |
| `#lieu#` | Localités concernées |
| `#url#` | Lien direct vers la page SWDE |
| `#equipement#` | Nom de l'équipement Jeedom |
| `#cp#` | Code postal surveillé |

Les jetons Jeedom restent utilisables par-dessus : `#[Objet][Équipement][Commande]#`,
`variable()`, `date()`, etc. Les jetons du cœur sont résolus avant les
jetons du plugin.

### Détection d'un « nouvel incident »

Le plugin garde en mémoire la liste des `nodeId` SWDE déjà vus pour cet
équipement. À chaque passage du cron, les `nodeId` qui n'y figuraient pas
sont considérés comme nouveaux et déclenchent les alertes actives. Un même
incident qui dure plusieurs cycles ne re-déclenche pas. Un incident résolu
puis republié par SWDE sous un nouvel identifiant interne déclenche à
nouveau.

**Premier cycle après installation** : la mémoire s'amorce sans alerter.
Les incidents actifs au moment de l'installation ne génèrent pas d'alerte,
sinon chaque mise en route provoquerait un pic de notifications pour des
situations déjà connues de l'utilisateur. Les incidents suivants, eux,
alertent normalement.

### Bouton Tester

Chaque alerte expose un bouton **Tester** qui joue les actions sur le
dernier incident en cours connu (ou sur un incident fictif en l'absence de
donnée). Permet de valider le canal — notification mobile, TTS, lampe —
sans attendre une vraie coupure d'eau.

## Fonctionnement

Le plugin interroge trois chemins du site SWDE :

1. L'**autocomplete JSON** (`/fr/search_api_autocomplete/search`) pour
   détecter rapidement si un code postal a quelque chose à annoncer.
2. La **page de recherche** (`/fr/works?text=<CP>`) pour la liste des
   incidents actifs.
3. La **page de détail** de chaque incident actif pour en extraire les
   champs structurés (dates ISO, perturbation, localité, géolocalisation).

Les détails d'incident sont mis en cache 6 heures : une intervention annoncée
n'évolue pratiquement pas une fois son créneau publié. Après un échec HTTP,
le plugin attend une heure avant de retenter, pour ne pas marteler le service.

## Note sur l'historisation

La commande **Détails (JSON)** peut dépasser 127 caractères (longueur de la
colonne `history.value` dans Jeedom). **Ne pas l'historiser** : SQL
tronquerait silencieusement les lignes passées cette limite et l'historique
serait inutilisable. Les commandes utiles pour l'historisation sont
`Incident en cours`, `Nombre d'incidents`, et éventuellement `Date de début`
/ `Date de fin prévue`.

## Limites connues

- Pas d'API officielle : si la SWDE refond son site, le parseur devra être
  mis à jour. Les classes CSS `field--name-field-inc-*` utilisées sont celles
  d'un Drupal standard et devraient rester stables tant qu'il n'y a pas de
  refonte complète.
- Un code postal couvre parfois plusieurs communes (ex. 7062 → Naast + Soignies) ;
  le plugin ne filtre pas par commune : tous les incidents du code postal sont
  remontés.
- Pas de notion d'incident « nouveau » / « clos » entre deux lectures : seuls
  les incidents actifs sont exposés à un instant donné. Un historique peut
  être construit via l'historisation Jeedom sur les commandes.
