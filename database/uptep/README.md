# Base UPTEP

[`schema.sql`](schema.sql) contient la structure de la base utilisée par le site et le bot : utilisateurs et droits, chaîne et associations Discord, statistiques personnelles, accès au userscript, demandes de ressources, convois et suivi des commandes API.

**Aucune donnée de membre, aucun mot de passe ni secret de production n’est fourni.**

## Installation sur une base vide

Prévoir PostgreSQL 17 et les rôles `fzzz` (propriétaire historique de certaines tables) et `uptep` (compte applicatif). Depuis la racine du dépôt, avec un compte PostgreSQL autorisé à créer la base et à attribuer les objets à ces rôles :

```sh
createdb --encoding=UTF8 --template=template0 uptep
psql -X --dbname=uptep --set=ON_ERROR_STOP=1 --file=database/uptep/schema.sql
```

Le rôle applicatif `uptep` doit disposer des droits de lecture/écriture sur les tables de cette base et des droits nécessaires sur leurs séquences. Le schéma contient certaines attributions explicites ; compléter les droits selon le rôle utilisé pour la restauration. La configuration et les identifiants restent dans le fichier privé du site, décrit dans le [README principal](../../README.md).

Ce fichier regroupe la structure et ses évolutions : **ne pas le réexécuter intégralement sur une base existante**. Pour reprendre une installation avec ses données, restaurer une sauvegarde compatible. Les migrations historiques restent dans `app/`.

## Différence avec FZZZ

UPTEP conserve les données propres à l’alliance et accepte les écritures de l’application. La base [FZZZ](../fzzz/README.md) fournit les relevés du jeu ; le compte `uptep` ne doit y avoir qu’un accès en lecture sur les cinq tables prévues.
