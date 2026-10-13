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

Le formulaire reprend l’organisation du simulateur natif : attaquant, cibles, terrains, objectifs de terrain final, prises maximales et arrondis. Les cibles sont dédupliquées et affichées par distance. Les calculs utilisent des entiers exacts, la portée inclusive 50–300 %, le plafond de prise de 20 % et, si utile, une prise intermédiaire pour rester à portée avant la dernière attaque. Décocher une attaque retire ses effets du calcul sans ajouter une attaque de remplacement.

Chaque joueur a ses boutons **Préparer** et **Envoyer**, et son propre état d’envoi. La préparation actualise le terrain du compte, l’armée, les attaques libres et la VA depuis `/simulateurFlood.php`, puis les terrains des cibles depuis l’autocomplétion native `/appelAjaxFlood.php?term=…`. Elle vérifie l’autorisation d’attaquer la cible et relit le jeton avant l’envoi. Elle ne dépend pas du HTML de la page Membres téléchargée ; les erreurs sont affichées près du bouton Préparer. Elle reste valable 90 secondes, en mémoire uniquement ; modifier la sélection, préparer un autre joueur ou recharger le runtime invalide cette préparation. Le clic final déclenche directement `GET /appelAjaxFlood.php?t=…&codeFlood=ID|effectif|…`, uniquement pour ce joueur, sans requête de contrôle préalable ni envoi automatique. Ce protocole est celui observé dans le simulateur natif le 13/10/2026.

Le repère de clic applique le tutoriel : **59 − (durée du trajet modulo 60)**. Exemple : trajet `15:09:08`, clic à `:51`, arrivée visée à `:59`. Le compte à rebours et la prochaine heure d’arrivée utilisent le cache valide de l’horloge HTTP S5, sans nouvelles requêtes. Une source absente, incohérente ou trop ancienne masque le compte à rebours ; la seconde théorique reste affichée. Les heures sont celles de Paris. La durée calculée est explicitement une estimation ; le champ **Durée exacte du jeu**, au format `HH:MM:SS`, permet de la remplacer. La latence et l’incertitude de l’horloge empêchent de garantir une arrivée exacte à :59.

Les propositions sont désormais calculées par cible pour permettre des départs séparés dans n’importe quel ordre. Après un envoi confirmé, l’armée et les attaques restantes sont mises à jour. Les gains estimés des envois de cette préparation sont projetés seulement s’ils arrivent avant le prochain joueur ; les gains déjà intégrés au dernier relevé du terrain du compte ne sont pas ajoutés deux fois. Les prises déjà envoyées sont déduites de l’objectif global. Les attaques sur les autres joueurs ne sont pas considérées comme envoyées avant le clic sur leur bouton.

Chaque cible propose la case **Ajouter le reste de mon armée au dernier flood**, décochée par défaut. Elle ajoute toutes les fourmis restantes au dernier flood coché pour ce joueur, sans ajouter d’attaque. Changer cette option invalide la préparation. Les colonnes **Fourmis** et **Prise** distinguent les effectifs envoyés du terrain gagné, plafonné à 20 % sur chaque attaque ; renforcer le dernier flood peut dépasser un objectif de prise saisi. La vérification après envoi porte sur les effectifs, tandis que les projections de terrain utilisent uniquement la prise estimée.

Un marqueur par cible est enregistré avant l’envoi et conservé après rechargement pour empêcher les doublons. Après réponse, le script vérifie la baisse du nombre d’attaques libres et des effectifs disponibles. Une erreur, un résultat partiel ou ambigu bloque les nouveaux départs et demande de consulter Armée ; aucun renvoi automatique. **Nouvelle préparation** réinitialise les marqueurs, avec confirmation en cas d’envoi incertain. Si le jeu demande de confirmer l’annulation d’une suppression de compte, le script renvoie vers son simulateur natif sans confirmer ni retenter automatiquement à une mauvaise seconde. Les anciens marqueurs globaux restent bloquants jusqu’à une nouvelle préparation explicite.

Les estimations ne simulent ni les combats ni les mouvements extérieurs à cette préparation ; les floods ne sont pas automatiquement déclarés en BDD. Le jeton d’envoi reste en mémoire, jamais dans le stockage du navigateur.

Validation : `node tests/multiflood.cjs` et `node tests/multiflood-browser.cjs`. Cas couverts : règle :59 et changement de minute, durée saisie, horloge périmée, ordre des arrivées, objectifs globaux, expiration de préparation, envoi immédiat et isolé par joueur, maintien des autres cibles, reprise après rafraîchissement et résultat incertain. Les envois des tests sont interceptés par un serveur simulé ; aucune attaque réelle n’est lancée. Recompiler et déployer le runtime suffit, sans migration ni réinstallation du chargeur.
