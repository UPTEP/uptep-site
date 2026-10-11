# UPTEP — Documentation API

Documentation OpenAPI et interface Swagger de l’API privée des convois Discord.

```sh
npm ci --ignore-scripts
python3 build-spec.py
node -e "require('@apidevtools/swagger-parser').validate('openapi.json').then(()=>console.log('OpenAPI valide'))"
```

Pour publier, servir `index.html`, `openapi.json` et `assets/` en HTTPS, par exemple sur `api.uptep.top`. Ne pas exposer `node_modules`. L’API autorise actuellement les appels navigateur provenant de `https://api.uptep.top` uniquement ; adapter aussi cette règle côté serveur pour un autre domaine.

Les essais interactifs ciblent l’API réelle indiquée dans la spécification. Le jeton doit rester privé et n’est jamais inclus dans les sources. L’implémentation PHP et les migrations sont dans le dépôt `site`.

Code spécifique UPTEP sous licence MIT. Swagger UI conserve sa licence Apache-2.0, fournie dans `assets/swagger-LICENSE`.
