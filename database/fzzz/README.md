# Base FZZZ utilisée par UPTEP

`schema.sql` est un export PostgreSQL 17 **sans données** des six tables actuelles :

- `alliances` et `players` : derniers relevés du jeu ;
- `historique_alliances`, `historique_players` et `historique_exports` : archives ;
- `export_sync` : dernière version importée et dates de vérification du collecteur.

Le fichier inclut les contraintes, index et la séquence d’identité des exports. Tous les objets appartiennent désormais au rôle `uptep`. Aucun mot de passe, donnée de joueur ou secret de production n’est inclus ; les autorisations des autres rôles sont gérées séparément.

## Installation sur une base vide

Créer préalablement le rôle PostgreSQL `uptep`, puis utiliser un compte autorisé :

```sh
createdb --encoding=UTF8 --template=template0 --owner=uptep fzzz
psql -X --dbname=fzzz --set=ON_ERROR_STOP=1 --single-transaction --file=database/fzzz/schema.sql
```

Ce fichier est destiné à une base vide. Pour une reprise, restaurer une sauvegarde compatible ; ne pas réexécuter le schéma complet sur une base en service.

## Accès et alimentation

Les bases `uptep` et `fzzz` ont le même propriétaire `uptep`. Le code du site configure sa connexion `fzzz` en lecture seule ; cette restriction de connexion ne retire pas les droits intrinsèques du propriétaire. `read-only.sql` reste un exemple historique destiné à un rôle de lecture distinct : il ne transforme pas un propriétaire en compte limité.

Le collecteur est installé séparément sur le serveur : `/opt/uptep-exports`, rôle système et PostgreSQL `uptep-exports` / `uptep_exports`, cron `/etc/cron.d/uptep-exports` à H:15. Il récupère directement les exports publics Fourmizzz S5 et utilise SELECT/INSERT/UPDATE sur les six tables, CONNECT/TEMP sur `fzzz`, USAGE sur le schéma public et USAGE/SELECT sur `historique_exports_id_seq`. Son code, sa configuration et ses secrets sont sauvegardés séparément du dépôt. Aucun navigateur n’est nécessaire.

`export_sync` est nécessaire à chaque exécution. Les règles actuelles rejettent les valeurs négatives dans les surfaces ; certains anciens exports d’alliances de l’API ne sont donc pas importés. Le point de reprise des historiques et les détails opérationnels sont conservés sur le serveur.
