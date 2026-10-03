<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

require_once __DIR__ . '/../../../core/php/core.inc.php';

function swdebe_install() {
}

function swdebe_update() {
    swdebe_migrateCommands();
}

/*
 * Donne aux équipements existants les commandes apparues depuis leur création.
 * Le plugin ne les crée qu'à l'enregistrement : sans ce passage, elles
 * n'apparaîtraient qu'au jour où quelqu'un rouvre le code postal et clique
 * sur Sauvegarder.
 */
function swdebe_migrateCommands() {
    foreach (eqLogic::byType('swdebe') as $eqLogic) {
        try {
            $eqLogic->ensureCommands();
            /*
             * update(false) : on recalcule les commandes depuis l'état en
             * cache sans taper swde.be. Une mise à jour du plugin peut
             * concerner N équipements ; un fetch synchrone de 10 s par
             * équipement bloquerait l'installation pendant une minute si
             * le service est lent. Le cron15 prendra le relais pour la
             * vraie lecture.
             */
            if ($eqLogic->getIsEnable() == 1 && $eqLogic->isConfigured()) {
                $eqLogic->update(false);
            }
        } catch (Throwable $e) {
            log::add('swdebe', 'error', 'migration ' . $eqLogic->getHumanName() . ' : ' . $e->getMessage());
        }
    }
}

function swdebe_remove() {
    /*
     * Jeedom supprime les eqLogic du plugin AVANT d'appeler cette fonction
     * et chacun passe par preRemove() : les caches `swdebe::state::<id>`,
     * `swdebe::retry::<id>` et `swdebe::detail::<md5>` qu'il connaissait
     * sont donc déjà nettoyés. Ce filet rattrape les cas où un eqLogic
     * aurait survécu (plugin désactivé sans suppression, puis réinstallation
     * avec un autre id).
     */
    foreach (eqLogic::byType('swdebe') as $eqLogic) {
        try {
            $eqLogic->preRemove();
        } catch (Throwable $e) {
            log::add('swdebe', 'debug', 'nettoyage cache ' . $eqLogic->getHumanName() . ' : ' . $e->getMessage());
        }
    }
}
