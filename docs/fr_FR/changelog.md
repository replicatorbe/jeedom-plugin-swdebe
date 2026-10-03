# Journal des versions

## 0.2 — alertes intégrées

- Nouvel onglet **Alertes** sur chaque équipement : à l'apparition d'un
  incident nouveau sur le code postal surveillé, le plugin joue les actions
  configurées (notification mobile, SMS, message vocal, lampe…). Le
  sélecteur est celui des scénarios Jeedom — toutes les commandes d'action
  et tous les blocs de message du cœur sont utilisables.
- Jetons dans les titres et messages : `#titre#`, `#perturbation#`, `#debut#`,
  `#fin#`, `#lieu#`, `#url#`, `#equipement#`, `#cp#`.
- Bouton **Tester** : joue l'alerte sur le dernier incident connu, ou sur un
  incident fictif en l'absence de donnée, pour valider le canal sans
  attendre une vraie coupure.
- Mémoire des incidents déjà notifiés par équipement : un même `nodeId`
  SWDE ne re-déclenche pas. Au premier passage après installation, la
  mémoire s'initialise sans alerter — pas de spam avec les incidents déjà
  en cours au moment où l'on branche le plugin.

## 0.1 — version initiale

- Surveillance d'un ou plusieurs codes postaux belges sur le réseau SWDE.
- Détection via l'autocomplete JSON du site + page de recherche + pages de
  détail.
- Commandes exposées : état binaire, compteur, titre, perturbation, dates de
  début et de fin, localité, URL, détails JSON, bouton rafraîchir.
- Cron 15 min (configurable, minimum 5 min).
- Cache 6 h des détails d'incident, backoff 1 h après échec.
