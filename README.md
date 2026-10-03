# jeedom-plugin-swdebe

Plugin Jeedom qui surveille les incidents et interventions publiés par la
**SWDE** (Société Wallonne des Eaux) pour un ou plusieurs codes postaux
belges.

Pas d'API publique officielle : le plugin fait du scraping HTML sur
[swde.be](https://www.swde.be/fr/works), en PHP natif, sans dépendance.

Un équipement = un code postal. Les commandes exposées (état binaire,
compteur, titre, perturbation, dates de début/fin, localité, URL, JSON) sont
exploitables en scénario pour notifier une coupure d'eau, afficher un
dashboard, déclencher un message vocal.

Voir [`docs/fr_FR/index.md`](docs/fr_FR/index.md) pour la documentation
complète.
