# Recherche ou ponte

Le panneau de `laboratoire.php` compare le prochain niveau d’Armes/Bouclier thoracique avec la ponte d’unités à temps de ponte égal. Aucun accès à Reine, aucune action de jeu, aucune nouvelle collecte. Désactivation dans UPTEP+ → Paramètres → Comparaison du laboratoire.

## Données et sources

- Bâtiments, technologies, armée et dates de collecte : `user_stats` du **seul compte authentifié**. Aucune donnée d’armée d’un autre membre n’est envoyée. Une valeur absente n’est pas remplacée par zéro. Les dates absentes ou antérieures de plus de 24 h sont signalées.
- Coûts ouvrières/nourriture/matériaux et durée : ligne de recherche affichée dans le jeu. Le [tableau Toolzzz](https://toolzzz.fr/couts.php) confirme ces catégories (niveau 1 : 80 ouvrières pour les deux recherches). On ne stocke pas une formule de prix extrapolée : les prix affichés sont la référence. Une file de recherche ou un niveau différent de celui en BDD suspend le calcul.
- Temps de base et nourriture : [Toolzzz TDP](https://toolzzz.fr/tdp.php), vérifié le 11 octobre 2026. Une ouvrière : 60 s et 5 nourriture.
- Statistiques hors bonus et bonus additifs : [ZzzelpScript/Armee.js](https://github.com/flaviendelangle/ZzzelpScript/blob/master/Armee.js). Somme TDP : [ZzzelpScript/Compte.js](https://github.com/flaviendelangle/ZzzelpScript/blob/master/Compte.js).
- Prérequis : relevé de la page Reine S5 fourni par le propriétaire le 11 octobre 2026. Ils sont enregistrés dans `src/laboratory.js`, pas redécouverts à chaque visite.

| Unité | Prérequis cumulés | Temps de base (s) |
| --- | --- | ---: |
| Jeune soldate naine | Salle de combat 1 | 300 |
| Soldate naine | Salle de combat 3 | 450 |
| Naine d’élite | Caserne 4 | 570 |
| Jeune soldate | Salle de combat 7 | 740 |
| Soldate | Caserne 9 | 1000 |
| Concierge | Génétique 5 + Salle de combat 10 | 1410 |
| Artilleuse | Acide 1 + Salle de combat 11 | 1440 |
| Artilleuse d’élite | Acide 5 + Caserne 13 | 1520 |
| Soldate d’élite | Caserne 17 | 1450 |
| Tank | Génétique 15 + Caserne 23 | 1860 |
| Tueuse | Poison 4 + Salle de combat 25 | 2740 |
| Tueuse d’élite | Poison 7 + Caserne 28 | 2740 |

Concierges d’élite et tanks d’élite : pris en compte dans l’armée actuelle, exclus des alternatives de ponte (XP uniquement). Les clés `t`/`te` désignent les tanks et `tu`/`tue` les tueuses dans UPTEP.

## Calcul

`TDP = couveuse + solarium + technique_ponte`.

Pour un coût W ouvrières : `temps = W × 60 × 0,9^TDP`. Pour une unité de temps de base U : `quantité = plancher(W × 60 / U)`. Le coefficient de ponte commun s’annule. Les effectifs, gains et divisions sont calculés avec BigInt ; seule la durée estimée utilise un flottant, arrondi à la seconde supérieure pour l’affichage.

Avec B = total hors bonus de l’armée et L = niveau actuel : gain de recherche = `plancher(B × (11+L)/10) − plancher(B × (10+L)/10)`. Armes utilise l’attaque, Bouclier la vie. L’alternative applique le bonus **actuel** aux unités ajoutées, sans XP. Le pourcentage compare le gain supérieur au gain inférieur ; pas de pourcentage si le gain inférieur est nul.

C’est une comparaison militaire, pas une rentabilité économique complète : les ressources, la production perdue pendant le remplacement des ouvrières, les files de ponte et l’XP ne sont pas intégrées au verdict. La vie est comparée hors bonus de lieu. La recherche se déroule en parallèle de la ponte. Les coûts en ressources sont affichés séparément. Les unités aux prérequis inconnus empêchent un verdict global.

## Déploiement et vérification

Appliquer `app/laboratory-settings.sql` (idempotent), déployer `app/script-settings.php`, `public/script-api.php` et le runtime reconstruit. Le schéma complet inclut cette migration. Le réglage est enregistré dans `script_settings.preferences`.

`node tests/laboratory.cjs` vérifie seuils, données manquantes, bonus marginaux, XP, TDP et grands entiers. `node tests/laboratory-browser.cjs` teste le DOM avec coûts, file de recherche, incohérences BDD, révocation et absence de requête Reine. Pour le test navigateur, fournir `PUPPETEER_MODULE` et `CHROMIUM` si leurs chemins diffèrent de ceux du serveur.
