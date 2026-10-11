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
