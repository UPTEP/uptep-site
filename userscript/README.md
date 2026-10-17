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

L’option **Multiflood** dans **UPTEP+ → Paramètres** contrôle l’affichage des colonnes de sélection, des boutons et du calculateur. Elle est activée par défaut et indépendante de l’option Chaîne. La désactivation prend effet au prochain rafraîchissement du runtime (sous 30 secondes ou au rechargement), y compris sur une page de préparation déjà ouverte.

Les colonnes **Choix** et **Multiflood** ouvrent la même page intégrée `/alliance.php?uptepMultiflood=1`, avec une ou plusieurs cibles. Les terrains sont lus dans le tableau Membres, y compris après les tris de la chaîne ; le compte connecté, les joueurs en vacances et les joueurs hors de portée ne sont pas sélectionnables. La préparation est conservée dans `sessionStorage`, par joueur et par onglet, sans jeton de jeu.

Le formulaire reprend l’organisation du simulateur natif : attaquant, cibles, terrains, objectifs de terrain final, prises maximales et arrondis. Les cibles sont dédupliquées et les résultats affichés dans l’ordre d’arrivée prévu. Les calculs utilisent des entiers exacts, la portée inclusive 50–300 %, le plafond de prise de 20 % et, si utile, une prise intermédiaire pour rester à portée avant la dernière attaque. Décocher une attaque retire ses effets du calcul sans ajouter une attaque de remplacement.

Chaque joueur a ses boutons **Préparer** et **Envoyer**, et son propre état d’envoi. La préparation actualise le terrain du compte, l’armée, les attaques libres et la VA depuis `/simulateurFlood.php`, puis les terrains des cibles depuis l’autocomplétion native `/appelAjaxFlood.php?term=…`. Elle vérifie l’autorisation d’attaquer la cible et relit le jeton avant l’envoi. Elle ne dépend pas du HTML de la page Membres téléchargée ; les erreurs sont affichées près du bouton Préparer. Elle reste valable 90 secondes, en mémoire uniquement ; modifier la sélection, préparer un autre joueur ou recharger le runtime invalide cette préparation. Le clic final déclenche directement `GET /appelAjaxFlood.php?t=…&codeFlood=ID|effectif|…`, uniquement pour ce joueur, sans requête de contrôle préalable ni envoi automatique. Ce protocole est celui observé dans le simulateur natif le 13/10/2026.

Le repère de clic applique le tutoriel : **59 − (durée du trajet modulo 60)**. Exemple : trajet `15:09:08`, clic à `:51`, arrivée visée à `:59`. Le compte à rebours et la prochaine heure d’arrivée utilisent le cache valide de l’horloge HTTP S5, sans nouvelles requêtes. Une source absente, incohérente ou trop ancienne masque le compte à rebours ; la seconde théorique reste affichée. Les heures sont celles de Paris. Le champ **Durée exacte du jeu**, au format `HH:MM:SS`, est prérempli avec le trajet calculé et reste modifiable. La latence et l’incertitude de l’horloge empêchent de garantir une arrivée exacte à :59.

Les propositions forment un calcul commun dans l’ordre d’arrivée prévu, tout en gardant les départs séparés par joueur. Chaque série cochée réserve les fourmis et les places d’attaque pour les séries suivantes ; sa prise augmente le terrain prévu de l’attaquant avant les arrivées suivantes. Les objectifs globaux sont partagés. Décocher un flood, modifier un objectif ou une durée recalcule toutes les cibles. Un départ vers une cible plus lointaine peut précéder celui vers une cible proche : les effectifs prévus pour cette dernière restent disponibles. Les projections des floods encore prévus supposent leur envoi ; seuls les clics réellement confirmés sont enregistrés comme envoyés. Après confirmation, les disponibilités du jeu remplacent les réservations correspondantes et les gains confirmés remplacent les gains prévus, sans ajout en double ni ajout des gains déjà intégrés au terrain relevé. Le clic final revérifie localement les quantités et les terrains de la série préparée ; si l’ordre d’arrivée modifie le calcul, une nouvelle préparation est nécessaire.

Chaque cible propose la case **Ajouter le reste de mon armée au dernier flood**, décochée par défaut. Elle ajoute toutes les fourmis restantes au dernier flood coché pour ce joueur, sans ajouter d’attaque. Changer cette option invalide la préparation. Les colonnes **Fourmis** et **Prise** distinguent les effectifs envoyés du terrain gagné, plafonné à 20 % sur chaque attaque ; renforcer le dernier flood peut dépasser un objectif de prise saisi. La vérification après envoi porte sur les effectifs, tandis que les projections de terrain utilisent uniquement la prise estimée.

Un marqueur par cible est enregistré avant l’envoi et conservé après rechargement pour empêcher les doublons. Après réponse, le script vérifie la baisse du nombre d’attaques libres et des effectifs disponibles. Une erreur, un résultat partiel ou ambigu bloque les nouveaux départs et demande de consulter Armée ; aucun renvoi automatique. Une nouvelle sélection depuis Membres archive les envois confirmés : ils ne consomment plus la prise maximale de la nouvelle sélection, mais leurs arrivées restent intégrées au terrain prévu. Les anciens marqueurs hors sélection suivent la même règle. **Nouvelle préparation** archive également les envois confirmés et réinitialise les marqueurs actifs, avec confirmation en cas d’envoi incertain. Changer de sélection ne lève jamais le blocage d’un envoi incertain. Si le jeu demande de confirmer l’annulation d’une suppression de compte, le script renvoie vers son simulateur natif sans confirmer ni retenter automatiquement à une mauvaise seconde. Les anciens marqueurs globaux restent bloquants jusqu’à une nouvelle préparation explicite.

Les estimations ne simulent ni les combats ni les mouvements extérieurs à cette préparation ; les floods ne sont pas automatiquement déclarés en BDD. Le jeton d’envoi reste en mémoire, jamais dans le stockage du navigateur.

Validation : `node tests/multiflood.cjs` et `node tests/multiflood-browser.cjs`. Cas couverts : règle :59 et changement de minute, durée saisie, horloge périmée, ordre des arrivées, objectifs globaux, expiration de préparation, envoi immédiat et isolé par joueur, maintien des autres cibles, reprise après rafraîchissement et résultat incertain. Les envois des tests sont interceptés par un serveur simulé ; aucune attaque réelle n’est lancée. Recompiler et déployer le runtime suffit, sans migration ni réinstallation du chargeur.


## Envoi des convois depuis commerce.php

Sur S5, le module `src/convoys.js` affiche les demandes validées en cours avec un formulaire par demande. Un clic sur Envoyer vérifie la demande via `convoy-check`, recharge le formulaire natif du jeu (jeton et capacités actuels), puis poste sur `/commerce.php`. Les noms de champs et les constantes de transport viennent de la capture de commerce fournie ; une structure non reconnue bloque avant le POST. La réponse doit contenir exactement un nouvel identifiant de convoi correspondant au destinataire et aux quantités avant toute déclaration UPTEP.

Le traitement utilise le pont iframe `portal.php?bridge=convoys` et les actions authentifiées `convoy-demands`, `convoy-check`, `convoy-declare`. L’identité joueur/Discord est dérivée du compte autorisé. La déclaration réutilise `botConvoyAction` et la file de notifications existante. La clé de commande stable associe le serveur, l’expéditeur et l’identifiant du convoi du jeu ; le même convoi ne peut pas être débité ou notifié deux fois par le userscript. La protection ne rapproche pas automatiquement les anciennes déclarations manuelles faites sur Discord ou sur le site.

Le suivi est écrit dans localStorage avant le POST, sous une clé propre au joueur. Un Web Lock évite les envois concurrents entre onglets du module. Après une réponse ambiguë ou perdue, le bouton de reprise vérifie la page du jeu en GET, sans renvoyer les ressources. Après confirmation du jeu, la reprise ne réessaie que la déclaration UPTEP. La date d’envoi et les quantités sont conservées. Une demande modifiée/terminée entre le POST du jeu et la déclaration peut nécessiter une régularisation dans UPTEP ; le jeu et UPTEP ne forment pas une transaction atomique. Aucun envoi automatique au chargement : les quantités sont initialement à zéro et chaque départ nécessite le clic de l’utilisateur.

Tests : `php tests/script-convoys.php` depuis la racine du site sous un rôle PostgreSQL pouvant créer un schéma de test dans `uptep` (supprimé à la fin). `tests/script-convoys-browser.cjs` utilise Playwright, fourni par `UPTEP_PLAYWRIGHT_MODULE` si nécessaire, et intercepte toutes les requêtes : aucun accès ni envoi réel au jeu. Il utilise la capture `tests/fixtures/commerce.html` (jetons remplacés), des variantes structurelles de cette capture et les copier-coller enregistrés dans les tests du dépôt frère `discord-bot`. Les variantes synthétiques ne sont pas des captures indépendantes de toutes les offres Compte+.

Le build normal utilise Terser (`npm ci --ignore-scripts && npm run build`). `UPTEP_UNMINIFIED=1 node build.cjs` assemble exactement les mêmes sources sans minification lorsque les dépendances de build ne sont pas disponibles ; le fichier de sortie conserve le nom attendu par le serveur. Aucune réinstallation du loader n’est requise : sa révision distante change automatiquement.

Le bloc de demandes reprend le fond et la police du tableau natif de commerce (repli ocre `#d7c384`). La durée du trajet aller est estimée avec `chaineTravel`, les coordonnées S5 et le dernier niveau de vitesse d’attaque connu. La source/date du niveau est accessible au survol. Si le niveau ou les coordonnées manquent, aucune durée n’est inventée. La réponse du jeu reste la référence pour l’arrivée enregistrée lors de la déclaration. Référence du calculateur : https://www.toolzzz.fr/durees2.php (attaques ou convois).

Chaque formulaire affiche en direct les ouvrières mobilisées : plafond de `(pommes + matériaux) / (10 × (1 + 0,05 × étable))`. Le niveau est lu dans les données natives de la page commerce ; le calcul entier BigInt est partagé avec le POST. Le jeu est relu avant chaque envoi pour vérifier les stocks, le niveau et les ouvrières actuels. Un niveau non reconnu ne devient pas zéro : l’affichage signale le calcul indisponible.

Le bloc utilise les icônes natives nourriture, matériaux et ouvrières de Fourmizzz. Seul le trajet aller est affiché.

Le module propose trois onglets : demandes validées en cours, demandes en attente (consultation seule) et création pour le joueur connecté. `convoy-pending` liste les demandes non validées ; `convoy-create` réutilise le circuit de création du bot et conserve la validation habituelle. Le formulaire demande les quantités, la construction/recherche, le niveau et une date souhaitée facultative. Une clé persistée avant création permet de reprendre après coupure ou rechargement sans doublon. La création ne poste jamais de convoi dans le jeu.

Après confirmation du convoi dans le jeu et enregistrement UPTEP, le suivi local est marqué terminé puis le navigateur recharge `/commerce.php` en GET pour afficher les convois natifs. Ce comportement vaut aussi après une reprise réussie. Aucun rechargement automatique si l’envoi ou la déclaration reste à vérifier.

## Déclaration des floods depuis Armée

`src/floods.js` ajoute un bouton à chaque attaque sortante reconnue dans Armee.php, près du compte à rebours `attaque_ID`. Le build intègre directement le parseur de `../discord-bot/src/flood-parser.js` : lieux explicites ou absents, durées longues ou compactes, alliance, troupes et heure facultatives suivent les mêmes règles que Discord. Les avertissements demandent confirmation ; un relevé non reconnu reste à vérifier. Aucun POST n’est envoyé au jeu.

Les actions authentifiées `flood-check` et `flood-declare` passent par le pont du portail. L’identité vient du compte autorisé. La table `script_flood_links` associe expéditeur, identifiant du jeu et flood UPTEP ; les retries sont idempotents, y compris après réponse perdue. Avant déclaration, les anciens floods sont rapprochés par expéditeur, cible, lieu, arrivée à trois secondes près (jusqu’à deux minutes lorsque les troupes normalisées sont identiques et renseignées) et troupes quand connues. Plusieurs correspondances sont signalées sans création. Une correspondance Discord sans identifiant reste un rapprochement, pas une preuve de l’identifiant du jeu.

Appliquer `app/script-floods.sql` et mettre à jour le consommateur Discord avant le runtime : l’événement `flood` est écrit dans la même transaction que la déclaration puis transmis à la file de MP existante du bot. Pas de notification au simple chargement ni sur retry. Les tests PHP créent un schéma isolé ; les tests navigateur utilisent le HTML fourni avec ses jetons remplacés et interceptent toutes les requêtes.
