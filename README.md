# UPTEP — Site et API

Site privé de l’alliance UPTEP sur Fourmizzz, serveur 5 : comptes, chaîne, statistiques, répartition des ressources, API du userscript et API des convois Discord.

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

Le menu « Statistiques » et `/ressources.php` sont réservés aux chefs (`is_chef`) et au rôle `admin`, avec contrôle côté serveur et HTTP403 pour tout autre compte, même gestionnaire de chaîne. Les chefs y voient aussi l’entrepôt estimé, issu des relevés de ressources UPTEP+.

La page compare, sur une période choisie (du 11/10/2026, premier convoi déclaré, à maintenant par défaut ; dates `debut` et `fin` incluses, heure de Paris), la production de matériaux de chaque membre actuel à une part égale de la production de l’alliance (`app/resource-balance.php`). Chaque cycle de 30 minutes produit 1 matériau par cm² de terrain d’après le dernier relevé horaire FZZZ ; le total du cycle est partagé entre les membres UPTEP présents dans ce relevé. Les anciens membres ne sont pas comptés. Les colonies entre membres versent 20 % + 1 point par niveau d’étable à pucerons du colonisateur (20 % si inconnu, 100 % au plus). Net = production + colonies − matériaux envoyés + matériaux reçus (convois déclarés non annulés, à leur date de départ) ; écart = net − part, positif pour un membre qui doit convoyer. La colonne « Entrepôt estimé » part du dernier relevé de matériaux UPTEP+ de moins de 48 h, ajoute la production de chaque cycle depuis (colonies comprises), retire les convois envoyés depuis (au départ) et ajoute les convois reçus depuis (à l’arrivée) ; dépenses et capacité d’entrepôt non connues. L’entrepôt estimé est toujours calculé à maintenant, quelle que soit la période. La même période s’applique au graphique de consommation repris de Demandes et convois → Statistiques (convois de matériaux uniquement), aux floods (`app/resource-activity.php` : envoyés, reçus, troupes déclarées, à l’heure d’arrivée ; le terrain pris n’est pas enregistré) et aux chasses relevées par UPTEP+ ou Discord (terrain chassé, en cours). Test : `python3 tests/run-resource-balance.py`.

Dans Administration, « Mode visualisation » propose les profils Membre, Chef, Gestion de chaîne et Chef+Chaîne, avec UPTEP+ autorisé ou désactivé. Ces profils utilisent les données de l’administrateur avec les droits sélectionnés. Le bouton « Voir comme » d’un compte actif affiche ses données et ses droits actuels, relus à chaque page. Les comptes non validés ou désactivés ne peuvent pas être visualisés.

L’identité de connexion reste celle de l’administrateur. Un bandeau indique l’aperçu et propose « Revenir à mon compte admin ». Les écritures de page sont refusées avant les contrôleurs pendant l’aperçu, les connexions SQL sont placées en lecture seule et le téléchargement d’une installation personnelle UPTEP+ est bloqué. Les changements de profil, la sortie d’aperçu et la déconnexion restent possibles avec CSRF. Aucun droit de compte n’est modifié par la visualisation. La suppression du compte cible ou la perte du droit administrateur met fin à l’aperçu.

Vérification autonome : `php tests/preview.php`. Les tests fonctionnels HTTPS ont également vérifié les accès directs, les quatre profils, les droits relus, les refus de modification et le retour au compte admin.


Demandes et convois : `/convois.php` réutilise les services `bot-convoys.php`, `bot-deliveries.php` et `bot-reports.php`. L'identité provient exclusivement de `users.discord_login_id`, jamais de l'ID joueur modifiable du profil. Les droits de validation restent ceux du bot. Sur le site, les chefs (`is_chef`) et administrateurs peuvent consulter les historiques, statistiques et récapitulatifs ; les droits actifs et le lien Discord sont revérifiés côté serveur. Le mode visualisation applique les droits du profil choisi. Les formulaires utilisent CSRF, confirmation en session et commandes idempotentes ; le mode visualisation reste en lecture seule.

Appliquer `app/convoy-events.sql` dans la base UPTEP avec propriétaire `uptep`, autoriser `convois.php` dans la liste PHP Nginx et déployer `src/web-events.js` sur le bot. Les validations et convois du site enregistrent un événement dans la même transaction ; le bot les copie dans ses files persistantes avant d'accuser réception via `/api/bot/v1/notifications-web` (scopes existants `convois:read` / `convois:create`). Les notifications sont traitées à l'actualisation du tableau, environ chaque minute. Les ressources restent envoyées dans Fourmizzz ; la déclaration enregistre cet envoi.

Vérification : `php tests/web-convoys.php`, `python3 tests/run-bot-convoys.py` (PostgreSQL local, schéma temporaire), et `npm test` dans le dépôt du bot. Aucune notification Discord réelle n'est nécessaire aux tests.

Le lien de navigation UPTEP+ est temporairement visible uniquement pour les administrateurs, y compris en mode visualisation membre. Cette restriction porte sur le menu ; les autorisations du userscript restent gérées séparément.

### Outils et calculateur de bonus RC

`/outils.php` est accessible à tous les membres connectés, avec un menu commun et des onglets extensibles. Le premier onglet, « Bonus de combat », reprend le calculateur Dôme & Loge conservé dans `references/spyzzz-rc/`. Le moteur `public/assets/tools-combat.js` est identique à l’archive ; le parseur, la recherche des niveaux de 0 à 40 et les résultats du calculateur sont repris dans `tools-rc.js`. L’interface utilise les couleurs UPTEP, des contrôles accessibles et des styles externes compatibles avec la CSP du site. Aucun rapport n’est envoyé ni enregistré sur le serveur.

Déployer `public/outils.php`, les quatre fichiers `public/assets/tools*` et `app/view.php`. Ajouter `outils` à la liste des scripts PHP autorisés dans Nginx, puis valider et recharger sa configuration. Pour ajouter un outil, ajouter un bouton `data-tool-tab` et un panneau lié par `aria-controls` ; la navigation clavier est commune.

Vérification : `node tests/tools-rc.cjs` compare le moteur et les résultats à l’archive sur les trois lieux, les deux points de vue, les nombres espacés et les rapports invalides. La fidélité à l’archive ne constitue pas une nouvelle validation des règles du jeu.


## Renseignements joueurs (administrateurs)

`/combats.php` est une base de renseignements S5 centrée sur les joueurs. La recherche par pseudo ou ID affiche les joueurs suivis ; une correspondance unique ouvre directement sa fiche. La fiche présente les dernières valeurs non nulles connues, avec date, provenance manuelle/calculée et lien vers le RC source pour chaque valeur ; un relevé plus récent incomplet ne supprime pas un ancien niveau connu. Les niveaux peuvent provenir de relevés différents ; les statistiques de synthèse appliquent les derniers niveaux connus à la dernière armée observée, avec les sources distinctes de l’armée et des niveaux. L’armée affichée est celle du dernier relevé par date de combat, avec historique paginé.

L’import analyse un RC complet avec le calculateur archivé, puis permet de choisir **un seul camp**, attaquant ou défenseur. Seul ce joueur doit être associé à un ID `fzzz.players` via l’autocomplétion. Un rapport possède un unique relevé, ses 14 unités et les hypothèses portant sur le camp choisi. Le RC brut est conservé comme source secondaire repliée. La date est interprétée en Europe/Paris ; les pseudos et dates détectés restent à vérifier. Un même texte normalisé ne peut pas être importé deux fois. L’attaque indique la capacité offensive, même pour un défenseur ; la vie inclut uniquement la protection observée dans ce combat. Les niveaux ambigus restent NULL et les hypothèses sont conservées. Les corrections manuelles sont identifiées et l’analyse initiale du camp est conservée.

Installation neuve : appliquer `database/combat-reports.sql`, puis `database/combat-single-player.sql` sous le rôle propriétaire `uptep`. Mise à jour : sauvegarder les quatre tables combat, puis appliquer seulement `combat-single-player.sql`. La migration conserve le camp `target_side` des RC existants, ses unités et ses hypothèses ; les relevés du camp non sélectionné sont supprimés, le texte source reste inchangé. La migration peut être relancée et impose un relevé par RC et un ID joueur obligatoire. Il n’y a pas de clé étrangère vers la base séparée `fzzz`.

Déployer `app/combat-analyze.cjs`, `app/combat-reports.php`, `app/combat-profile-view.php`, `app/view.php`, `public/combats.php` et les assets `combats.js/css`. La route `combats` doit être autorisée dans Nginx. Dépendances d’analyse : `/usr/bin/node`, `/usr/bin/timeout`, PHP `proc_open`, texte limité à 100 Ko et exécution à 25 secondes. La recherche du calculateur explore les niveaux 0–40 ; les corrections manuelles autorisent 0–100.

Validation : `node tests/combat-reports.cjs`, `node tests/tools-rc.cjs`. Le test `tests/combat-storage.php` nécessite un accès PostgreSQL local à `uptep` (création/suppression de schéma) et à `fzzz` en lecture seule. Il crée et supprime un schéma temporaire, vérifie les migrations des deux camps, l’import unique, les sources des dernières valeurs connues, l’ordre chronologique, les doublons, les permissions et les unités. Ne pas déployer les tests dans le document root.

Les vies avec bonus sont séparées en `Vie AB (terrain)`, `Vie AB (dôme)` et `Vie AB (loge)` dans la liste, la fiche et l’historique. Les niveaux Dôme et Loge sont également visibles dans la liste. Pour chaque observation : terrain = HB × (1 + 0,1 × bouclier), dôme = HB × (1,1 + 0,1 × bouclier + 0,05 × dôme), loge = HB × (1,3 + 0,1 × bouclier + 0,15 × loge). Si les niveaux restent ambigus mais que le calculateur connaît exactement la vie AB du combat, cette valeur est conservée uniquement pour le lieu défensif observé (ou terrain pour l’attaquant). Les niveaux inconnus ne valent pas zéro. Dans la synthèse (liste et fiche), la vie HB et l’attaque HB proviennent toujours de la dernière armée observée. Les vies AB et l’attaque AB sont recalculées sur cette base avec les derniers niveaux connus, même issus de relevés plus anciens. Un niveau nécessaire inconnu laisse le résultat inconnu. Les dates des niveaux restent visibles dans la liste ; la fiche détaille les RC sources de l’armée et des niveaux utilisés. Le détail des RC et l’historique conservent leurs calculs d’origine. Aucun changement de schéma nécessaire.

### TDP confirmé à partir de deux sondes

Appliquer aussi `database/combat-tdp.sql` sous le rôle `uptep` avant de déployer `app/combat-tdp.php`, `app/combat-tdp-view.php`, le profil, le contrôleur et l’analyseur mis à jour. Migration additive et relançable : aucun RC existant n’est modifié. La table `combat_tdp_confirmations` conserve les deux observations sources, le total, les effectifs vérifiés et le calcul, l’auteur, la date et un jeton unique contre les doubles envois.

La colonne TDP affiche uniquement **Solarium + Couveuse + Technique de ponte**, après confirmation explicite par l’auteur du second RC. La fiche permet de sélectionner deux relevés du même joueur S5, dans l’ordre chronologique. Le parseur déduit les survivantes avant XP en retirant les pertes du premier combat dans l’ordre des unités. La différence avec la seconde armée préremplit un formulaire d’effectifs pondus : l’utilisateur doit corriger les promotions, mouvements et autres pertes. Les Concierges d’élite et Tanks d’élite ne sont pas pondables directement.

La durée de base est la somme des effectifs pondus × secondes de ponte de chaque unité ; la durée au niveau total N est `base × 0.9^N`. Constantes et formule : [code FYT/FYW](https://gist.github.com/lwr20/13b6a03b656a16215b72b4f42ff5a922). La comparaison cherche les totaux entiers 0–300 compatibles avec l’intervalle, avec une tolérance de 60 secondes liée aux dates à la minute et une seconde d’arrondi. L’intervalle doit dépasser une minute. Si plusieurs totaux sont compatibles, aucune valeur n’est choisie automatiquement. Une ponte continue et des effectifs corrigés sont nécessaires : deux RC seuls ne prouvent pas ces conditions. L’utilisateur valide explicitement ces conditions et le total avant enregistrement ; sans validation, aucune estimation n’apparaît dans la liste. Un relevé récent non confirmé ou une confirmation portant sur des sondes plus anciennes ne remplace pas la dernière information confirmée.

Les droits administrateur, la session, l’auteur du second RC, la paire, les effectifs, le total compatible, la case de confirmation et le jeton expirant sont vérifiés côté serveur ; toutes les mutations passent par le contrôle CSRF. `php tests/combat-tdp.php` vérifie ces règles dans un schéma PostgreSQL temporaire supprimé à la fin, sans modifier les données de production. `node tests/combat-reports.cjs` vérifie aussi les survivantes avant XP dans les deux points de vue et les trois lieux.

### Statistiques quotidiennes des convois (site)

L’onglet Statistiques du site propose les sélections pommes/matériaux et envois/réceptions, sur aujourd’hui et les six jours précédents (Europe/Paris). Le tableau inclut les joueurs de la chaîne et les acteurs des mouvements filtrés, y compris les jours sans activité. Les convois non annulés contenant strictement plus de zéro de la ressource sont groupés à leur date de départ déclarée, également dans la vue réceptions ; aucune arrivée effective n’est supposée. Les dates futures sont exclues. Totaux et moyennes sont calculés en PostgreSQL numeric, sans perte de précision des grands entiers. La moyenne par jour divise le total par sept, journée actuelle et jours sans activité inclus.

Le TdC moyen est la moyenne arithmétique des relevés FZZZ disponibles par jour, avec nombre de relevés affiché, et NULL en leur absence. Les timestamps d’export sans fuseau suivent la convention UTC de l’observatoire, puis sont regroupés en jours de Paris. Seul le dernier export d’une version est retenu pour ne pas compter les réimports. Le contrôle des droits existant des rapports du site est réutilisé. Le bot conserve ses statistiques et sa pagination. Déployer `app/convoy-daily.php`, `app/convoy-daily-view.php`, `public/convois.php` et `public/assets/convoy-daily.css` ; aucune migration nécessaire. Test PostgreSQL : `php tests/convoy-daily.php` avec une connexion locale à uptep autorisant CREATE SCHEMA ; tous les objets et données du test sont temporaires dans une transaction annulée à la fin.

La première ligne « Total » additionne les joueurs affichés après filtrage : TdC moyens quotidiens (avec couverture des relevés), nombre de convois et quantités. Le bilan additionne les totaux hebdomadaires une seule fois par joueur et recalcule la moyenne sur sept jours avant arrondi. Les agrégats utilisent PostgreSQL numeric, y compris au-delà de bigint ; un TdC absent reste inconnu et les sommes partielles sont signalées.


### Auto production des demandes de convois

Appliquer `app/convoy-auto-production.sql` avant de déployer les services mis à jour. Migration additive, relançable : les demandes existantes portent `auto_production=false`. La case est disponible à la création, lors de la modification d’une demande en attente et dans l’ajustement d’une demande validée, pour son auteur ou un responsable selon les droits existants. Un changement d’option seule conserve les quantités et ne crée pas de faux ajustement de ressources ; le reçu de commande garde l’auteur et le contenu de l’opération.

Le site, UPTEP+ et le bot affichent l’indicateur. Le bot conserve les cinq champs de création/modification et propose ensuite une modale de choix Auto production avant la confirmation finale ; les ajustements intègrent directement la case. Les déclarations manuelles nécessitent une confirmation explicite lorsque l’option est active. Le service vérifie cette confirmation sous verrou, y compris si l’option a changé depuis l’aperçu. Le bot sait présenter une nouvelle confirmation pour un envoi simple ou le reste d’un lot partiellement déclaré. Les reprises déjà enregistrées conservent leurs reçus.

Le userscript relit l’option via `convoy-check` et demande confirmation **avant** le POST du jeu. Une annulation de l’alerte n’envoie rien. La déclaration d’un convoi déjà parti et ses reprises restent possibles si l’option a changé ; aucun second envoi de ressources n’est effectué. Les montants restants suivent la comptabilité habituelle, sans déduction automatique liée à la production personnelle.

Validation : `php tests/convoy-auto-production.php` (schéma temporaire), les suites existantes bot/convois/livraisons et les tests navigateur du userscript. Aucun convoi réel ni message Discord de test n’est nécessaire.

### Dépassement des demandes de convois

Appliquer `app/convoy-overage.sql` avant le code. Tout dépassement du restant, même une seule unité et sans plafond de tolérance, est proposé après avertissement indiquant séparément les pommes et matériaux en trop. Le site et le bot demandent une confirmation explicite ; le userscript avertit avant le POST Fourmizzz. Une baisse concurrente du restant qui augmente le dépassement nécessite une nouvelle confirmation. Le montant réellement envoyé est conservé pour les statistiques ; le restant est borné à zéro et le surplus est conservé séparément, sans limite bigint cumulée, pour recalculer correctement les annulations. Les ajustements de production ne sont pas effacés. Les lignes supplémentaires d’un lot confirmé peuvent être enregistrées après le solde automatique, sans rouvrir la demande. Les demandes annulées ou closes manuellement restent fermées. Les reprises d’un envoi déjà parti n’effectuent aucun renvoi de ressources.

Vérification dédiée : `tests/convoy-overage.php` dans un schéma PostgreSQL temporaire, en complément de `tests/run-bot-convoys.py` et des scénarios Chromium de `tests/script-convoys-browser.cjs`.

### Cadre chef sur les profils membres

Appliquer `app/member-report.sql` avant de publier les fichiers PHP : nouvelles dates d’usage sur `users`, table `member_stat_history` et trigger transactionnel sur `user_stats`. Le cadre est réservé au droit exact `users.is_chef` et aux profils des membres S5 encore présents chez UPTEP. Les clés, mots de passe, emails et identifiants Discord ne font pas partie de sa réponse. Les nouvelles dates d’utilisation commencent à la mise en place ; une clé générée n’est pas une preuve d’installation du script. Les relevés, activités déclarées et variations de stocks sont présentés avec leur couverture et leurs limites, sans production fictive ni classement automatique des membres.

Validation : `tests/member-report.php` dans un schéma jetable PostgreSQL ; `tests/member-activity.php` avec tables temporaires ; `tests/member-report-browser.cjs` avec transport entièrement simulé et fixture produite par le test PHP.

Les lectures du bot `/demandes`, `/livraisons`, `/rapports/demandes` et `/rapports/demandes/{id}` acceptent `estimation=1` pour enrichir les demandes en cours avec les mêmes données de capacité et de projection historique que le userscript. Ce paramètre est facultatif ; les autres consommateurs gardent leur réponse habituelle. Les droits existants s’appliquent avant l’enrichissement. Les demandes en attente, annulées ou terminées n’ont pas de projection. Aucun restant ni état de clôture n’est modifié. Le calculateur du bot reflète les fonctions du userscript et leur parité est testée.
