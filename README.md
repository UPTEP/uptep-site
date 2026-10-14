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
2. Préparer les bases `uptep` et `fzzz`. Le rôle `uptep` possède désormais les deux bases et leurs objets. Le code ouvre sa connexion à `fzzz` en lecture seule et consulte : `alliances`, `players`, `historique_alliances`, `historique_exports`, `historique_players`. Le schéma de ces cinq tables et de `export_sync`, avec leurs index, contraintes et séquence, est fourni dans [`database/fzzz/schema.sql`](database/fzzz/schema.sql). Les données et l’importeur FZZZ ne sont pas inclus : prévoir leur sauvegarde et leur reprise séparément. Les étapes de restauration et les droits sont décrits dans [`database/fzzz/README.md`](database/fzzz/README.md).
3. Sur une nouvelle base uniquement, examiner puis appliquer [`database/uptep/schema.sql`](database/uptep/schema.sql) avec le compte PostgreSQL chargé du déploiement. Le fichier cible le rôle propriétaire `uptep` ; il n’est pas une migration idempotente pour une base existante. Pour une reprise existante, restaurer une sauvegarde compatible plutôt que réappliquer tout le schéma.
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

## Taille des lots de rapports Discord

L’emplacement Nginx `/api/bot/v1/terrain/` doit accepter `client_max_body_size 2m;` et reprendre le même routage FastCGI, authentification et limitation de débit que `/api/bot/v1/`. Conserver les limites plus basses sur les autres routes. Le contrôleur PHP limite lui-même ces lots à 2 000 000 octets. Une limite serveur de 16 Ko provoque un HTTP 413 avant l’exécution PHP lors de l’initialisation d’un historique. Valider avec `nginx -t` avant de recharger Nginx.

### Connexion Discord privée (test NoobEnCDI)

La commande `/connexion` du bot crée un lien d'authentification temporaire, sans OAuth2, avec création du compte à la confirmation si l’association Discord a été saisie par un administrateur. Activation : appliquer `app/discord-login.sql` puis `app/discord-login-registration.sql`, `app/discord-login-test.sql` et `app/discord-login-password.sql` à la base UPTEP, déployer `app/discord-login.php`, `public/discord-login.php`, `public/assets/discord-login.js` et la route ajoutée à `public/bot-api.php`. Autoriser `discord-login.php` dans la liste Nginx des scripts PHP exécutables. Dans le fichier privé `bot-api.json`, ajouter le scope `login:create` et `login_guild_id` égal au serveur Discord autorisé. Ne jamais versionner ce fichier ou son secret.

L'ID Discord `1544667516364652659` est le seul autorisé, vérifié côté bot et côté serveur. L’association saisie par l’administrateur dans `chaine_members(server='s5', iddiscord)` doit désigner exactement un joueur. L’identité du compte est liée par `users.discord_login_id` (index unique), jamais déduite du simple champ de profil modifiable `game_player_id`. Un administrateur doit confirmer explicitement ce lien pour un compte existant non lié. L’unique compte de test existant noobencdi a été lié lors du déploiement après vérification de son ID, de son nom et de l’association en chaîne.

Si aucun compte n’est lié à Discord et qu’aucun compte n’existe pour ce joueur, le site propose de créer le compte après confirmation POST. La demande slash et l’aperçu ne créent rien. Le compte est actif avec `role=member`, `is_chef=false`, `is_chaine=false`, identifiant et nom affiché identiques au pseudo de la chaîne (1 à 80 caractères sans caractères de contrôle). Un mot de passe aléatoire inconnu est haché puis oublié ; `must_change_password=false` permet la connexion Discord. Le nouveau compte porte `password_setup_required=true` : une notification commune aux pages privées invite à créer son mot de passe via `/password.php`. La session Discord reste utilisable. La connexion par mot de passe reste interdite jusqu’à sa définition. L’enregistrement vérifie CSRF, longueur et confirmation, enlève la notification et incrémente session_version. L’identifiant est unique sans distinction de casse et la connexion accepte les variantes de casse. Les réinitialisations administratives continuent d’utiliser le parcours obligatoire existant. Les comptes existants conservent leurs droits et leur obligation éventuelle de changement de mot de passe. Aucun compte suspendu/en attente n’est réactivé. Une collision d’identifiant ou un compte préexistant non lié exige l’intervention d’un administrateur.

La création et la consommation du jeton sont transactionnelles, avec verrou pour empêcher une double création, y compris face à une écriture concurrente de l’administration. Le joueur, le compte lié et sa session_version sont revérifiés à la confirmation. Les changements de rattachement invalident le lien. Les tables `users` et `chaine_members` sont verrouillées brièvement pendant cette transaction.

Les liens utilisent 32 octets aléatoires ; seul SHA-256 est stocké en base. Durée 120 secondes, consommation atomique sous verrou, ancien lien annulé lors d'une nouvelle demande, nouvelle vérification du compte et de sa session_version au moment de l'utilisation. Le secret est placé dans le fragment URL, retiré de l'historique navigateur puis transmis par POST même origine avec CSRF. La prévisualisation ne connecte personne : une confirmation POST distincte indiquant le compte est nécessaire. Les sessions existantes d'un autre utilisateur ne sont pas remplacées. La connexion utilise ensuite les sessions habituelles Secure/HttpOnly du site. Limites : 10 émissions / 15 minutes, 30 requêtes web POST / IP / 15 minutes. Les lignes expirées sont purgées à l'émission suivante.

Un lien partagé ou volé reste utilisable par son détenteur jusqu'à son expiration/consommation. La compromission du bot et de son secret API permettrait d'émettre un lien pour le seul compte autorisé ; protéger ces secrets. Les principes de durée courte, usage unique et stockage sûr suivent les [recommandations OWASP pour les jetons privés](https://cheatsheetseries.owasp.org/cheatsheets/Forgot_Password_Cheat_Sheet.html).

Tests : `python3 tests/run-discord-login.py` avec accès postgres (schéma temporaire supprimé après tests), et tests du bot. Désactivation immédiate : retirer `login:create` du fichier privé ; pour invalider aussi les liens déjà émis, vider uniquement `discord_login_tokens`.

La simulation `/connexion-test` a été retirée du bot et de l’API après validation. Les migrations et tests isolés correspondants restent présents pour assurer la compatibilité du schéma ; ils ne réactivent pas la commande.

### Guide public

Le guide « L’attaque à la 59e seconde » est accessible sans compte depuis la page de connexion et depuis l’accueil. Le PDF et ses fichiers de présentation sont versionnés dans `public/assets/`.
