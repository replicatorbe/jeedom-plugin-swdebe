<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

try {
    require_once __DIR__ . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }

    ajax::init();

    /*
     * eqLogic::byId() rend un équipement dans sa propre classe : sans ce
     * contrôle, un id étranger ferait agir le plugin sur l'équipement d'un
     * autre.
     */
    $getEqLogic = function ($_id) {
        $eqLogic = swdebe::byId($_id);
        if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'swdebe') {
            throw new Exception(__('Équipement introuvable', __FILE__));
        }
        return $eqLogic;
    };

    if (init('action') == 'testZipcode') {
        unautorizedInDemo();
        ajax::success(swdebe::testZipcode(init('zipcode')));
    }

    if (init('action') == 'refresh') {
        unautorizedInDemo();
        $eqLogic = $getEqLogic(init('id'));
        if (!$eqLogic->isConfigured()) {
            throw new Exception(__('Code postal invalide : quatre chiffres attendus.', __FILE__));
        }

        $state = $eqLogic->update(true);
        /*
         * update() ne lève pas quand un état est déjà en cache : sans ce
         * contrôle, une panne du service produirait un message vert
         * « rafraîchi » et l'utilisateur croirait son état à jour. Un
         * _refreshInfo (anti-spam, backoff) n'est pas une erreur : on le
         * remonte en success avec un flag.
         */
        if ($eqLogic->getRefreshError() != '') {
            throw new Exception($eqLogic->getRefreshError());
        }

        $count = isset($state['incidents']) ? count($state['incidents']) : 0;
        $info  = $eqLogic->getRefreshInfo();
        $summary = ($info !== '')
            ? $info
            : ($count . ' ' . __('incident(s) actif(s) pour ce code postal.', __FILE__));

        ajax::success(array(
            'count'     => $count,
            'summary'   => $summary,
            'skipped'   => ($info !== '') ? 1 : 0,
            'incidents' => isset($state['incidents']) ? $state['incidents'] : array(),
            'fetchedAt' => isset($state['fetchedAt']) ? $state['fetchedAt'] : 0,
            'zipcode'   => isset($state['zipcode']) ? $state['zipcode'] : '',
        ));
    }

    if (init('action') == 'incidents') {
        $eqLogic = $getEqLogic(init('id'));
        $state = $eqLogic->getState();
        ajax::success(array(
            'incidents' => isset($state['incidents']) ? $state['incidents'] : array(),
            'fetchedAt' => isset($state['fetchedAt']) ? $state['fetchedAt'] : 0,
            'zipcode'   => isset($state['zipcode']) ? $state['zipcode'] : '',
        ));
    }

    if (init('action') == 'testNotification') {
        unautorizedInDemo();
        $eqLogic = $getEqLogic(init('id'));
        $which   = init('which', 'actions');  // 'actions' (start) ou 'endActions' (fin)
        $result  = $eqLogic->testNotification(init('notificationId'), $which);
        if (count($result['errors']) > 0) {
            throw new Exception(implode(' ; ', $result['errors']));
        }
        $label = ($result['which'] === 'endActions')
            ? __('Actions de fin jouées avec succès (incident fictif) :', __FILE__)
            : __('Alerte jouée avec succès sur l\'incident :', __FILE__);
        ajax::success(array(
            'incident' => $result['incident'],
            'which'    => $result['which'],
            'summary'  => $label . ' ' . (isset($result['incident']['titre']) ? $result['incident']['titre'] : ''),
        ));
    }

    throw new Exception(__('Aucune méthode correspondante à : ', __FILE__) . init('action'));
} catch (Throwable $e) {
    ajax::error(displayException($e), $e->getCode());
}
