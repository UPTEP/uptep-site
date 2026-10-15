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

### Connexion Discord privée

La commande `/connexion` du bot crée un lien d'authentification temporaire, sans OAuth2, avec création du compte à la confirmation si l’association Discord a été saisie par un administrateur. Activation : appliquer `app/discord-login.sql` puis `app/discord-login-registration.sql`, `app/discord-login-test.sql` et `app/discord-login-password.sql` à la base UPTEP, déployer `app/discord-login.php`, `public/discord-login.php`, `public/assets/discord-login.js` et la route ajoutée à `public/bot-api.php`. Autoriser `discord-login.php` dans la liste Nginx des scripts PHP exécutables. Dans le fichier privé `bot-api.json`, ajouter le scope `login:create` et `login_guild_id` égal au serveur Discord autorisé. Ne jamais versionner ce fichier ou son secret.

Tous les membres du serveur Discord configuré peuvent utiliser `/connexion`. Le bot transmet uniquement l’identité de l’auteur de l’interaction ; l’API vérifie le serveur et le format de l’ID Discord. L’association saisie par l’administrateur dans `chaine_members(server='s5', iddiscord)` doit désigner exactement un joueur. L’identité du compte est liée par `users.discord_login_id` (index unique), jamais déduite du simple champ de profil modifiable `game_player_id`. Un administrateur doit confirmer explicitement ce lien pour un compte existant non lié.

Si aucun compte n’est lié à Discord et qu’aucun compte n’existe pour ce joueur, le site propose de créer le compte après confirmation POST. La demande slash et l’aperçu ne créent rien. Le compte est actif avec `role=member`, `is_chef=false`, `is_chaine=false`, identifiant et nom affiché identiques au pseudo de la chaîne (1 à 80 caractères sans caractères de contrôle). Un mot de passe aléatoire inconnu est haché puis oublié ; `must_change_password=false` permet la connexion Discord. Le nouveau compte porte `password_setup_required=true` : une notification commune aux pages privées invite à créer son mot de passe via `/password.php`. Le formulaire demande explicitement un mot de passe propre à UPTEP, différent de celui de Fourmizzz. La session Discord reste utilisable. La connexion par mot de passe reste interdite jusqu’à sa définition. L’enregistrement vérifie CSRF, longueur et confirmation, enlève la notification et incrémente session_version. L’identifiant est unique sans distinction de casse et la connexion accepte les variantes de casse. Les réinitialisations administratives continuent d’utiliser le parcours obligatoire existant. Les comptes existants conservent leurs droits et leur obligation éventuelle de changement de mot de passe. Aucun compte suspendu/en attente n’est réactivé. Une collision d’identifiant ou un compte préexistant non lié exige l’intervention d’un administrateur.

La création et la consommation du jeton sont transactionnelles, avec verrou pour empêcher une double création, y compris face à une écriture concurrente de l’administration. Le joueur, le compte lié et sa session_version sont revérifiés à la confirmation. Les changements de rattachement invalident le lien. Les tables `users` et `chaine_members` sont verrouillées brièvement pendant cette transaction.

Les liens utilisent 32 octets aléatoires ; seul SHA-256 est stocké en base. Durée 120 secondes, consommation atomique sous verrou, ancien lien annulé lors d'une nouvelle demande, nouvelle vérification du compte et de sa session_version au moment de l'utilisation. Le secret est placé dans le fragment URL, retiré de l'historique navigateur puis transmis par POST même origine avec CSRF. La prévisualisation ne connecte personne : une confirmation POST distincte indiquant le compte est nécessaire. Les sessions existantes d'un autre utilisateur ne sont pas remplacées. La connexion utilise ensuite les sessions habituelles Secure/HttpOnly du site. Limites : 10 émissions / 15 minutes, 30 requêtes web POST / IP / 15 minutes. Les lignes expirées sont purgées à l'émission suivante.

Un lien partagé ou volé reste utilisable par son détenteur jusqu'à son expiration/consommation. La compromission du bot et de son secret API permettrait d'émettre un lien pour les comptes associés ; protéger ces secrets. Les principes de durée courte, usage unique et stockage sûr suivent les [recommandations OWASP pour les jetons privés](https://cheatsheetseries.owasp.org/cheatsheets/Forgot_Password_Cheat_Sheet.html).

Tests : `python3 tests/run-discord-login.py` avec accès postgres (schéma temporaire supprimé après tests), et tests du bot. Désactivation immédiate : retirer `login:create` du fichier privé ; pour invalider aussi les liens déjà émis, vider uniquement `discord_login_tokens`.

La simulation `/connexion-test` a été retirée du bot et de l’API après validation. Les migrations et tests isolés correspondants restent présents pour assurer la compatibilité du schéma ; ils ne réactivent pas la commande.

### Guide public

Le guide « L’attaque à la 59e seconde » est présenté sur l’accueil après connexion. Le PDF et ses fichiers de présentation sont versionnés dans `public/assets/`.

### Ressources et visualisation des droits

Le menu et `/ressources.php` sont réservés au rôle `admin`, avec contrôle côté serveur et HTTP403 pour tout autre rôle, même chef ou gestionnaire de chaîne.

Dans Administration, « Mode visualisation » propose les profils Membre, Chef, Gestion de chaîne et Chef+Chaîne, avec UPTEP+ autorisé ou désactivé. Ces profils utilisent les données de l’administrateur avec les droits sélectionnés. Le bouton « Voir comme » d’un compte actif affiche ses données et ses droits actuels, relus à chaque page. Les comptes non validés ou désactivés ne peuvent pas être visualisés.

L’identité de connexion reste celle de l’administrateur. Un bandeau indique l’aperçu et propose « Revenir à mon compte admin ». Les écritures de page sont refusées avant les contrôleurs pendant l’aperçu, les connexions SQL sont placées en lecture seule et le téléchargement d’une installation personnelle UPTEP+ est bloqué. Les changements de profil, la sortie d’aperçu et la déconnexion restent possibles avec CSRF. Aucun droit de compte n’est modifié par la visualisation. La suppression du compte cible ou la perte du droit administrateur met fin à l’aperçu.

Vérification autonome : `php tests/preview.php`. Les tests fonctionnels HTTPS ont également vérifié les accès directs, les quatre profils, les droits relus, les refus de modification et le retour au compte admin.


Demandes et convois : `/convois.php` réutilise les services `bot-convoys.php`, `bot-deliveries.php` et `bot-reports.php`. L'identité provient exclusivement de `users.discord_login_id`, jamais de l'ID joueur modifiable du profil. Les droits de validation restent ceux du bot. Sur le site, les chefs (`is_chef`) et administrateurs peuvent consulter les historiques, statistiques et récapitulatifs ; les droits actifs et le lien Discord sont revérifiés côté serveur. Le mode visualisation applique les droits du profil choisi. Les formulaires utilisent CSRF, confirmation en session et commandes idempotentes ; le mode visualisation reste en lecture seule.

Appliquer `app/convoy-events.sql` dans la base UPTEP avec propriétaire `uptep`, autoriser `convois.php` dans la liste PHP Nginx et déployer `src/web-events.js` sur le bot. Les validations et convois du site enregistrent un événement dans la même transaction ; le bot les copie dans ses files persistantes avant d'accuser réception via `/api/bot/v1/notifications-web` (scopes existants `convois:read` / `convois:create`). Les notifications sont traitées à l'actualisation du tableau, environ chaque minute. Les ressources restent envoyées dans Fourmizzz ; la déclaration enregistre cet envoi.

Vérification : `php tests/web-convoys.php`, `python3 tests/run-bot-convoys.py` (PostgreSQL local, schéma temporaire), et `npm test` dans le dépôt du bot. Aucune notification Discord réelle n'est nécessaire aux tests.

Le lien de navigation UPTEP+ est temporairement visible uniquement pour les administrateurs, comme Ressources, y compris en mode visualisation membre. Cette restriction porte sur le menu ; les autorisations du userscript restent gérées séparément.

### Outils et calculateur de bonus RC

`/outils.php` est accessible à tous les membres connectés, avec un menu commun et des onglets extensibles. Le premier onglet, « Bonus de combat », reprend le calculateur Dôme & Loge conservé dans `references/spyzzz-rc/`. Le moteur `public/assets/tools-combat.js` est identique à l’archive ; le parseur, la recherche des niveaux de 0 à 40 et les résultats du calculateur sont repris dans `tools-rc.js`. L’interface utilise les couleurs UPTEP, des contrôles accessibles et des styles externes compatibles avec la CSP du site. Aucun rapport n’est envoyé ni enregistré sur le serveur.

Déployer `public/outils.php`, les quatre fichiers `public/assets/tools*` et `app/view.php`. Ajouter `outils` à la liste des scripts PHP autorisés dans Nginx, puis valider et recharger sa configuration. Pour ajouter un outil, ajouter un bouton `data-tool-tab` et un panneau lié par `aria-controls` ; la navigation clavier est commune.

Vérification : `node tests/tools-rc.cjs` compare le moteur et les résultats à l’archive sur les trois lieux, les deux points de vue, les nombres espacés et les rapports invalides. La fidélité à l’archive ne constitue pas une nouvelle validation des règles du jeu.


## Archives des rapports de combat (administrateurs)

`/combats.php` conserve les RC bruts et les armées initiales des deux camps, datées à l’heure du combat. Import en deux étapes : analyse serveur, puis vérification de la date (Europe/Paris), du lieu et des joueurs S5 avec autocomplétion. La cible doit être liée à un ID de `fzzz.players` ; le second joueur peut rester non identifié. Les noms sont conservés en instantané. Les dates et pseudos reconnus dans les en-têtes restent à vérifier ; aucune date manquante n’est inventée.

La migration additive `database/combat-reports.sql` crée les rapports, les relevés, leurs 14 unités et les combinaisons corrélées de niveaux. Lancer avec le rôle propriétaire `uptep`. Les références aux joueurs de la base séparée `fzzz` n’ont pas de clé étrangère ; la recherche y reste en lecture seule. Un RC identique après normalisation des espaces est refusé comme doublon. L’historique est consultable par joueur et période.

`app/combat-analyze.cjs` reprend le moteur et la recherche du calculateur archivé Spyzzz (niveaux 0–40). Le processus Node est lancé sans shell via PHP, avec une limite de 25 secondes et un texte de 100 Ko maximum. Dépendances serveur : `/usr/bin/node`, `/usr/bin/timeout`, `proc_open`. Une valeur indéterminée reste NULL ; les corrections facultatives 0–100 sont identifiées comme manuelles et l’analyse initiale est conservée. L’attaque stockée est la capacité offensive de l’armée, y compris pour le défenseur, et non ses dégâts de riposte. Les PV du défenseur n’incluent que le bâtiment du lieu effectivement observé. Les hypothèses initiales restent visibles même après une correction manuelle.

Déploiement : appliquer la migration, copier les deux fichiers privés `app/combat-*`, `app/view.php`, la page et ses deux assets, puis ajouter `combats` à la liste PHP Nginx autorisée. Les scripts de tests ne doivent jamais être déployés sous le document root. Les sauvegardes habituelles de la base `uptep` incluent automatiquement ces tables.

Vérification : `node tests/combat-reports.cjs` et `node tests/tools-rc.cjs`. Le test `tests/combat-storage.php` nécessite des connexions PostgreSQL locales aux bases `uptep` et `fzzz`, avec création/suppression de schéma autorisée : il crée un schéma au nom aléatoire, y teste les écritures et les permissions, puis le supprime dans un bloc finally. Aucune ligne métier existante n’est modifiée.
