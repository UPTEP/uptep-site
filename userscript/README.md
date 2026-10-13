# UPTEP+ — Userscript Fourmizzz

Sources du chargeur et du runtime UPTEP+ pour les membres autorisés de l’alliance UPTEP sur `s5.fourmizzz.fr`.

## Construction

```sh
npm ci --ignore-scripts
npm run build
```

Le résultat se trouve dans `dist/script-runtime.min.js` et `dist/script-loader.user.js`. Installer ces deux fichiers dans le répertoire privé `app/` du site UPTEP, puis les déployer ensemble. Le chargeur utilise le marqueur `__UPTEP_TOKEN__` : le site le remplace par une clé personnelle au téléchargement. Ne jamais publier un script téléchargé depuis un compte utilisateur réel.

L’installation pour les joueurs passe par la page `script.php` du site. Le fichier source brut n’est pas une installation personnelle prête à l’emploi.

## Fonctionnalités

Menu UPTEP+, chaîne dans le tableau des membres, horloge, collecte autorisée des bâtiments/recherches, synchronisation manuelle de l’armée et collecte horaire des ressources/ouvrières. Les permissions et la révocation sont contrôlées par le site. Le runtime distant est actualisable sans réinstallation du chargeur.

Le serveur PHP, l’API, les pages intégrées et la configuration d’accès sont à la racine de ce dépôt. Le code dépend de la structure HTML de Fourmizzz et doit être revérifié lors des changements du jeu. La minification n’est pas un mécanisme de protection des secrets.

Code sous licence MIT.

## Distance et trajet des membres

Le tableau des membres affiche la distance euclidienne entre les coordonnées du compte connecté et celles de chaque membre, ainsi que le temps de trajet aller estimé. Les coordonnées proviennent des relevés UPTEP/FZZZ ; le niveau `research.vitesse_attaque` est lu uniquement dans les statistiques du compte authentifié sur UPTEP.

Formule vérifiée dans le calculateur [Toolzzz](https://toolzzz.fr/durees2.php) : `secondes = arrondi(86400 × (1 − exp(−distance / 350)) × 7,375 × 0,9^VA)`, avec `distance = hypot(x_cible − x_origine, y_cible − y_origine)`. La distance affichée est arrondie à l’entier supérieur ; le calcul du temps et le tri utilisent la valeur exacte. Un clic sur Distance trie du plus proche au plus loin, le suivant inverse le tri. Les distances inconnues restent à la fin. Le temps s’affiche en jours éventuels puis HH:MM:SS. Les infobulles indiquent les coordonnées utilisées et le niveau de vitesse d’attaque.

Une coordonnée ou une vitesse manquante donne `—` au lieu d’une estimation avec un niveau supposé. Une vitesse de zéro renseignée reste valide. Pour compléter la vitesse d’attaque, synchroniser le laboratoire via le script ou renseigner Mes stats sur UPTEP, puis recharger la page des membres.

## Comparaison Armes / Bouclier et ponte

Dans le Laboratoire, le script compare le gain du prochain niveau à celui des unités qui pourraient être pondues pendant le temps nécessaire au remplacement des ouvrières consommées. Il utilise tes bâtiments, technologies et ton armée en BDD ; les prérequis des unités sont intégrés au code, sans requête à Reine. Le panneau affiche les dates des relevés, les ressources à prévoir et le détail par unité. Option désactivable dans UPTEP+ → Paramètres. [Formules, prérequis, limites et tests](docs/laboratory.md).

## Mes floods et convois en cours

Un encart à droite du jeu, large de 300 px quand la place le permet, propose les onglets **Attaques** et **Convois** avec leurs compteurs. Chaque onglet sépare **Vers moi** (vert) et **Envoyés** (ocre), triés par arrivée : les flèches « Joueur → Moi » et « Moi → Joueur » explicitent le sens. Il affiche les ressources des convois ou le lieu des floods, l’heure de Paris et le compte à rebours. Le choix de l’onglet est conservé lors des actualisations ; les onglets sont également utilisables au clavier. Il montre uniquement les déclarations de la BDD liées à l’ID de jeu du compte authentifié. Les attaques identiques restent distinctes.

Le chargeur existant actualise les données toutes les 30 secondes ; les lignes arrivées disparaissent aussi localement sans rechargement. Les convois annulés ou sans heure d’arrivée sont exclus. Une demande de ressources clôturée ou annulée ne masque pas un convoi encore en route et non annulé. Aucun trajet non déclaré ni trajet de retour n’est inventé.

L’encart se replie au clic et conserve ce choix par joueur pour l’onglet. Quand la colonne droite manque, il démarre replié en bas à droite et s’ouvre à la demande. Désactivation dans UPTEP+ → Paramètres → Floods et convois en cours. Une révocation de l’accès ou un changement de joueur retire l’encart via le nettoyage du chargeur.

Déploiement : ajouter `app/script-traffic.php`, appliquer `app/script-traffic.sql` (index uniquement, idempotent), puis mettre à jour `app/script-settings.php`, `public/script-api.php` et le runtime reconstruit. La préférence est activée par défaut sans réécrire les choix existants. Le chargeur personnel installé reste compatible : aucune réinstallation nécessaire. La date serveur des comptes à rebours est indépendante de la révision pour éviter un rechargement inutile du script à chaque vérification.

Validation : `python3 tests/run-script-traffic.py` (tables temporaires PostgreSQL, droits et sélection personnelle) et `node tests/traffic-browser.cjs` (affichage, échéance, repli, mobile, runtime et nettoyage).

## Multiflood depuis les membres

Les colonnes **Choix** et **Multiflood** ouvrent la même page intégrée `/alliance.php?uptepMultiflood=1`, avec une ou plusieurs cibles. Les terrains sont lus dans le tableau Membres, y compris après les tris de la chaîne ; le compte connecté, les joueurs en vacances et les joueurs hors de portée ne sont pas sélectionnables. La préparation est conservée dans `sessionStorage`, par joueur et par onglet, sans jeton de jeu.

Le formulaire reprend l’organisation du simulateur natif : attaquant, cibles, terrains, objectifs de terrain final, prises maximales, arrondis, distances et trajets. Les cibles sont dédupliquées et calculées de la plus proche à la plus éloignée. Les calculs utilisent des entiers exacts, la portée inclusive 50–300 %, le plafond de prise de 20 % et, si utile, une prise intermédiaire pour rester à portée avant la dernière attaque. Le budget d’armée et les attaques libres limitent les propositions. Décocher une attaque recalcule les terrains des suivantes et des autres cibles ; une attaque décochée n’est pas remplacée silencieusement par une attaque supplémentaire.

Les disponibilités, la VA et le jeton d’envoi sont lus sur `/simulateurFlood.php`. Juste avant chaque envoi, le script relit les membres et les disponibilités, puis vérifie les cibles par l’autocomplétion native. Tout changement de terrain ou de disponibilité exige de revoir le calcul avant de recliquer. L’envoi utilise le protocole natif observé le 13/10/2026 : `GET /appelAjaxFlood.php`, paramètres `t` et `codeFlood` (paires `ID|effectif|` dans l’ordre prévu). Aucune requête d’envoi ne part lors du montage de la page, d’un calcul ou d’une actualisation.

Un marqueur est enregistré avant l’envoi pour empêcher les doubles clics et les renvois après rechargement du runtime. Après réponse, le script vérifie la baisse du nombre d’attaques libres et des effectifs disponibles. Une erreur, un résultat partiel ou une réponse ambiguë conserve le verrou et demande de consulter Armée ; aucun renvoi automatique. Le message natif de confirmation d’annulation d’une suppression de compte est respecté. Le jeton reste uniquement en mémoire. Les estimations ne simulent pas les combats ni les autres mouvements intervenant avant l’arrivée ; cette fonctionnalité ne déclare pas automatiquement les floods en BDD.

Validation : `node tests/multiflood.cjs` et `node tests/multiflood-browser.cjs`. Les envois des tests sont interceptés par un serveur simulé ; aucune attaque réelle n’est lancée. Recompiler et déployer le runtime suffit, sans migration ni réinstallation du chargeur.
