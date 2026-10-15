<?php
declare(strict_types=1);
require '/home/uptep/private/app/bootstrap.php';
require '/home/uptep/private/app/view.php';
$u = requireUser();
workspaceStart($u, 'Outils');
?>
<link rel="stylesheet" href="/assets/tools.css?v=1">
<script src="/assets/tools-combat.js?v=1" defer></script>
<script src="/assets/tools-rc.js?v=1" defer></script>
<script src="/assets/tools.js?v=1" defer></script>
<section class="tools-page" aria-labelledby="tools-title">
<header class="tools-heading"><span class="tools-eyebrow">LA BOÎTE À OUTILS DE LA COLONIE</span><h1 id="tools-title">Outils</h1><p>Les calculateurs utiles pour préparer tes actions sur Fourmizzz.</p></header>
<div class="tools-tabs" role="tablist" aria-label="Outils disponibles">
<button type="button" id="tab-combat" role="tab" aria-selected="true" aria-controls="panel-combat" data-tool-tab>Bonus de combat</button>
</div>
<section id="panel-combat" class="tool-panel" role="tabpanel" aria-labelledby="tab-combat" tabindex="0">
<div id="rc-calculator"><p>Chargement du calculateur…</p></div>
<noscript><p>Active JavaScript pour analyser un rapport de combat.</p></noscript>
<p class="tools-privacy">Le calcul reste dans ton navigateur : ton rapport n’est ni envoyé ni enregistré sur le serveur.</p>
</section>
</section>
<?php workspaceEnd(); ?>
