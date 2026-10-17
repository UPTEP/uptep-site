<?php
header('Cache-Control: no-store, private');
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>UPTEP+ · Statistiques</title><link rel="stylesheet" href="/assets/portal.css?v=12"><script src="/assets/portal-charts.js?v=1" defer></script><script src="/assets/portal-map.js?v=1" defer></script><script src="/assets/portal-alliance.js?v=2" defer></script><script src="/assets/portal.js?v=14" defer></script></head><body>
<header class="portal-header"><nav class="portal-menu" aria-label="Rubriques UPTEP+"><button type="button" aria-current="page" id="menu-statistics"><svg class="menu-icon" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M2 14h5v8H2zm7-6h5v14H9zm7-6h5v20h-5z"/></svg><span>Statistiques</span></button><button type="button" id="menu-settings"><span aria-hidden="true">⚙</span><span>Paramètres</span></button></nav></header>
<div class="portal-shell"><main>
<h1>Statistiques</h1><p class="intro">Les joueurs et les alliances, au fil des relevés.</p>
<div id="access-status" role="status">Vérification de ton accès…</div>
<section id="statistics" hidden>
<div class="tabs" role="tablist" aria-label="Type de statistiques"><button id="tab-players" role="tab" aria-selected="true" aria-controls="stats-panel" data-kind="players">Joueurs</button><button id="tab-alliances" role="tab" aria-selected="false" aria-controls="stats-panel" tabindex="-1" data-kind="alliances">Alliances</button></div>
<div id="stats-panel" role="tabpanel" aria-labelledby="tab-players">
<form id="search-form"><label for="search">Rechercher un joueur</label><div class="search-row"><div class="autocomplete"><input id="search" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="suggestions" placeholder="Pseudo du joueur" autocomplete="off" maxlength="100" required><ul id="suggestions" role="listbox" hidden></ul></div><button type="submit">Consulter</button></div></form>
<div id="result-status" role="status"></div><div id="results"></div>
</div></section><section id="script-settings" hidden><p>Choisis les fonctions et les collectes autorisées pour ton compte. Les niveaux récupérés alimentent « Mes stats ». Les changements s’appliquent au prochain rechargement de la page du jeu.</p><p>L’accès global au userscript reste soumis à la validation de l’administrateur.</p><form id="settings-form"><div id="settings-options"></div><button type="submit" class="more">Enregistrer les paramètres</button></form><p id="settings-status" role="status"></p></section></main></div></body></html>
