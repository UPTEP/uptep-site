# Structure FZZZ nécessaire à UPTEP

`schema.sql` est un export **sans données** de PostgreSQL 17, limité aux tables :

- `public.alliances`
- `public.players`
- `public.historique_alliances`
- `public.historique_exports`
- `public.historique_players`

Il inclut leurs colonnes, valeurs par défaut, contraintes, index et la séquence d’identité `historique_exports_id_seq`. Les deux clés étrangères des historiques pointent vers `historique_exports`, également incluse. Aucune autre table, aucun rôle, mot de passe, propriétaire ou droit de production n’est exporté.

## Restauration sur une installation neuve

Utiliser PostgreSQL 17 et un client `psql` à jour compatible avec les commandes `\restrict` de l’export. Créer la base avec un rôle propriétaire distinct du compte applicatif `uptep`, puis :

```sh
# Avec un compte PostgreSQL autorisé à créer la base et les tables :
createdb --encoding=UTF8 --template=template0 fzzz
psql -X --dbname=fzzz --set=ON_ERROR_STOP=1 --single-transaction --file=database/fzzz/schema.sql
psql -X --dbname=fzzz --set=ON_ERROR_STOP=1 --set=app_role=uptep --file=database/fzzz/read-only.sql
```

Le rôle `uptep` doit déjà exister (c’est le même rôle utilisé par le site). Son mot de passe reste dans la configuration privée. Ne pas restaurer le schéma sous ce rôle : un propriétaire conserve le pouvoir de modifier ses tables.

Ces commandes ciblent **une base vide**, pas une base FZZZ déjà en service. L’export n’est pas une migration ni un script à réexécuter sur des tables existantes. Sur une installation existante, examiner les différences avant toute modification.

## Accès restreint

`read-only.sql` donne CONNECT à la base, USAGE au schéma public et SELECT aux cinq tables uniquement. Il retire d’éventuels droits directs précédents sur ces cinq tables et la séquence, sans accorder de droit sur les futures tables.

Sur une base existante, des permissions peuvent également provenir de PUBLIC, d’autres rôles ou de la propriété des objets. Ce script ne révoque pas ces permissions indirectes et ne constitue pas un audit des autres tables. Utiliser un rôle applicatif non-superutilisateur, sans appartenance à un rôle privilégié, distinct du propriétaire. Le script ne modifie pas les droits sur la base `uptep`, où l’application doit conserver ses accès en lecture/écriture.

## Alimentation

Les tables restaurées sont vides. Le site ne récupérera pas de statistiques tant que des relevés compatibles n’y auront pas été importés. Le programme d’importation FZZZ, son accès aux exports et les données historiques sont à reprendre séparément. Un éventuel importeur doit utiliser un rôle d’écriture distinct du rôle de lecture du site.
