# Journal des versions

## 0.2.3

- Une lecture ratée isolée de swde.be n'est plus journalisée en erreur, et ne
  crée donc plus de message dans le centre de messages : c'est un
  avertissement. L'erreur n'est levée qu'au troisième échec d'affilée, une
  seule fois, et le retour à la normale est alors signalé dans le journal.
- Un appel en échec réseau (délai dépassé, DNS) est retenté une fois aussitôt
  avant de conclure à l'échec.
- Délai d'attente par défaut porté de 10 à 20 secondes.
- Une lecture en échec sans état connu n'est plus journalisée deux fois.
- Le message que le coeur pose pour cette erreur au centre de messages est
  retiré au premier succès.

## 0.2.2

- Nouvelles commandes **Dernière vérification**, **Erreur de lecture** et **Dernière
  erreur** : quand swde.be ne répond plus, l'ancien état reste affiché mais
  n'est plus muet — « 0 incident » porte la date de la dernière lecture réussie
  et l'erreur reste levée jusqu'à la suivante, backoff compris.
- Pendant une erreur, l'état republié ne compte plus comme une
  communication : le timeout de l'équipement, posé à 60 minutes s'il était
  vide, signale une lecture bloquée.
- Page Santé : âge de la dernière lecture réussie et dernière erreur de
  chaque équipement.

## 0.2.1

- Un incident dont la fiche détaillée n'a pas pu être lue n'est plus pris
  pour un incident terminé : les actions de fin ne partent plus à tort, et
  l'alerte de début n'est plus rejouée au cycle suivant.

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
- Cron 15 min (configurable, minimum 15 min).
- Cache 6 h des détails d'incident, backoff 1 h après échec.
