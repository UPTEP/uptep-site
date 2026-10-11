# UPTEP — Site et API

Site privé de l’alliance UPTEP sur Fourmizzz, serveur 5 : comptes, chaîne, statistiques, simulateur de ressources, API du userscript et API des convois Discord.

## Organisation des sources

- `public/` : seul répertoire à exposer sur le web.
- `app/` : PHP privé, migrations historiques et runtime compilé du userscript.
- `database/uptep/schema.sql` : schéma de la base UPTEP.
- `database/fzzz/` : schéma FZZZ restreint, droits de lecture et instructions de restauration.
- `bin/account.php` : administration locale des comptes.
- `tests/` : tests existants ; certains nécessitent un environnement local reproduisant les chemins du serveur.
- `licenses/` : licences des polices livrées.

Le bot Discord est maintenu dans le dépôt `uptep-discord-bot`. Les sources du userscript sont dans `userscript/` et la documentation interactive de l’API dans `api-docs/`.

## Prérequis et installation

PHP 8.4 avec PDO PostgreSQL et mbstring, PostgreSQL 17, serveur web avec PHP-FPM. Le code actuel cible `/home/uptep` et le domaine `uptep.top`. Une installation sur un autre domaine nécessite d’adapter les chemins absolus et URL dans les sources et les autres dépôts.

1. Créer le compte système `uptep`. Installer `public/` dans `/home/uptep/public_html`, `app/` dans `/home/uptep/private/app` et `bin/` dans `/home/uptep/private/bin`.
2. Préparer les bases `uptep` et `fzzz`. Le rôle applicatif `uptep` doit pouvoir lire/écrire la première et seulement lire les tables nécessaires de la seconde : `alliances`, `players`, `historique_alliances`, `historique_exports`, `historique_players`. Le schéma restreint de ces cinq tables, avec leurs index, contraintes et séquence, est fourni dans [`database/fzzz/schema.sql`](database/fzzz/schema.sql). Les données et l’importeur FZZZ ne sont pas inclus : prévoir leur sauvegarde et leur reprise séparément. Les étapes de restauration et les droits de lecture sont décrits dans [`database/fzzz/README.md`](database/fzzz/README.md).
3. Sur une nouvelle base uniquement, examiner puis appliquer [`database/uptep/schema.sql`](database/uptep/schema.sql) avec le compte PostgreSQL chargé du déploiement. Le fichier cible les rôles `fzzz` (propriétaire historique) et `uptep` ; il n’est pas une migration idempotente pour une base existante. Pour une reprise existante, restaurer une sauvegarde compatible plutôt que réappliquer tout le schéma.
4. Copier `config.example.json` vers `/home/uptep/private/config.json`. Renseigner un mot de passe de base, une clé aléatoire et un hash de mot de passe factice généré avec `password_hash`. Le code privé et les fichiers de configuration doivent être lisibles uniquement par le compte du site.
5. Pour l’API Discord, copier `bot-api.example.json` vers `/home/uptep/private/bot-api.json`. Générer un secret `uptep_bot_` suivi de 64 caractères hexadécimaux aléatoires ; stocker son SHA-256 dans `token_hash`, et le secret complet uniquement dans le `.env` privé du bot.
6. Configurer HTTPS et les routes du serveur web. `/api/bot/v1/*` doit être servi par `public/bot-api.php`, en conservant `REQUEST_URI` et l’en-tête Authorization. Le déploiement actuel limite cette API à 30 requêtes/minute par IP, rafale 10.
7. Créer un compte avec `php /home/uptep/private/bin/account.php create pseudo admin /chemin/prive/premier-acces.json`. Protéger puis supprimer le fichier de premier accès après transmission.

Le compte PostgreSQL, les certificats, les sessions, les clés personnelles du userscript et les données ne sont pas fournis avec le code. Les associations Discord et les demandes de convois sont stockées dans PostgreSQL, pas dans GitHub.

## Tests

`python3 tests/run-bot-convoys.py` nécessite un PostgreSQL local, une base nommée `uptep` et la possibilité d’exécuter PHP comme `postgres`. Il crée et supprime un schéma de test isolé. Les autres tests historiques peuvent cibler les URL et comptes d’un environnement déployé : les examiner avant utilisation, ne pas lancer leur ensemble contre la production.

## Continuité

Conserver séparément des sauvegardes chiffrées des bases, les secrets et la configuration web. Donner à plusieurs responsables l’accès aux sauvegardes et au domaine. Tester la restauration sur une machine distincte ; ce dépôt n’est pas à lui seul une sauvegarde du service.

## Licence

Code UPTEP sous licence MIT. Les polices et les bibliothèques externes conservent leurs licences propres. Fourmizzz est un service tiers ; ce projet n’est pas son code source.

## Rapports du bot Discord

Les routes GET `/api/bot/v1/rapports/demandes`, `/rapports/convois`, `/rapports/statistiques` et `/rapports/demandes/{id}` fournissent les historiques et agrégations. Elles nécessitent le scope `rapports:read` dans la configuration privée du secret, plus un `discord_id` issu de l’interaction réelle : cet ID doit figurer dans `ADMIN_DISCORD_IDS` et correspondre à un membre actuel UPTEP. Le droit chaîne seul ne donne pas cet accès.

`app/bot-reports.php` effectue des lectures sur les tables existantes, sans migration. La documentation dans `api-docs/openapi.json` détaille les filtres, dates JJ-MM-AAAA inclusives en heure de Paris, pages de 8 résultats et moyennes. Les statistiques portent sur les déclarations non annulées à leur date d’envoi. Les historiques conservent les annulations et les dates de clôture, qui ne prouvent pas une livraison dans Fourmizzz.

Les tests `tests/run-bot-convoys.py` créent puis suppriment un schéma de test isolé via l’utilisateur PostgreSQL local ; ils couvrent aussi les droits des rapports, limites de dates, changement d’heure, pagination et totaux supérieurs à bigint. Ne pas les exécuter avec une connexion non isolée modifiée.
