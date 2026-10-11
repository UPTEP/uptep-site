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
