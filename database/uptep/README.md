# Base UPTEP

[`schema.sql`](schema.sql) contient la structure de la base utilisée par le site et le bot : utilisateurs et droits, chaîne et associations Discord, statistiques personnelles, accès au userscript, demandes de ressources, convois et suivi des commandes API.

**Aucune donnée de membre, aucun mot de passe ni secret de production n’est fourni.**

## Installation sur une base vide

Prévoir PostgreSQL 17 et le rôle `uptep` (propriétaire et compte applicatif). Depuis la racine du dépôt, avec un compte PostgreSQL autorisé à créer la base et à attribuer les objets à ces rôles :

```sh
createdb --encoding=UTF8 --template=template0 --owner=uptep uptep
psql -X --dbname=uptep --set=ON_ERROR_STOP=1 --file=database/uptep/schema.sql
```

Le rôle applicatif `uptep` doit disposer des droits de lecture/écriture sur les tables de cette base et des droits nécessaires sur leurs séquences. Le schéma attribue les objets à `uptep` ; les droits des comptes d’administration et du collecteur sont à gérer séparément. La configuration et les identifiants restent dans le fichier privé du site, décrit dans le [README principal](../../README.md).

Ce fichier regroupe la structure et ses évolutions : **ne pas le réexécuter intégralement sur une base existante**. Pour reprendre une installation avec ses données, restaurer une sauvegarde compatible. Les migrations historiques restent dans `app/`.

## Différence avec FZZZ

UPTEP conserve les données propres à l’alliance et accepte les écritures de l’application. La base [FZZZ](../fzzz/README.md) fournit les relevés du jeu ; la connexion du site à cette base utilise `default_transaction_read_only`, bien que le rôle `uptep` soit aussi propriétaire de ses six tables.

La table `demandes_ajustements` conserve les modifications manuelles du restant (auteur, date, quantités avant/après), séparément des convois. Sur une base existante, appliquer uniquement `app/bot-demand-adjustments.sql` pour cette évolution ; ne pas rejouer le schéma complet.

Les numéros des Passeurs sont classés en ordre décroissant (5 → 1), les autres grades gardent leur ordre croissant. Le 12 octobre 2026, les affectations s5 existantes ont été inversées une seule fois (1 ↔ 5, 2 ↔ 4, 3 inchangé), avec incrément de version pour invalider les formulaires anciens. Ce changement de données ne doit pas être réappliqué à chaque installation.

Le suivi des floods utilise `floods` (attaques déclarées et arrivées) et `bot_flood_batches` (idempotence des confirmations). Les attaques arrivées sont conservées ; les listes actives filtrent `arrival_at > now()`. Migration pour une installation existante : `app/bot-floods.sql`.

L’encart personnel des floods/convois du userscript lit ces déclarations existantes, filtrées par joueur et arrivée future. `app/script-traffic.sql` ajoute uniquement les index de lecture (expéditeur, demande et destinataire) ; il ne modifie ni les trajets ni les soldes.

Les comptes créés via Discord utilisent un identifiant égal au nom affiché, une association `discord_login_id` unique et la notification `password_setup_required`. Les jetons de connexion sont stockés uniquement sous forme de hash dans `discord_login_tokens`. Le schéma inclut ces évolutions ; les migrations ciblées restent dans `app/`.
