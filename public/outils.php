<?php
declare(strict_types=1);
require '/home/uptep/private/app/bootstrap.php';
require '/home/uptep/private/app/view.php';
require '/home/uptep/private/app/map-view.php';
require '/home/uptep/private/app/trajets.php';
$u = requireUser();
$tab = ($_GET['outil'] ?? '') === 'trajets' ? 'trajets' : 'combat';
$players = []; $alliances = []; $speeds = []; $result = null; $error = ''; $unavailable = false;
try {
    $players = trajetsPlayers();
    $alliances = trajetsAlliances($players);
    $speeds = trajetsKnownSpeeds($u);
    if ($tab === 'trajets' && isset($_GET['joueur'])) $result = trajetsCompute($players, $alliances, $speeds, $_GET);
} catch (DomainException $ex) { http_response_code(422); $error = $ex->getMessage(); }
catch (Throwable $ex) { error_log('UPTEP trajets: '.$ex->getMessage()); http_response_code(503); $unavailable = true; }
$picked = array_fill_keys($result['tags'] ?? array_values(array_filter((array)($_GET['alliances'] ?? []), 'is_string')), true);
$allianceName = static fn(string $tag): string => $tag === '' ? 'Sans alliance' : $tag;
// UPTEP+ speeds the viewer may see, keyed by lower-case pseudo, to prefill the form.
$autoSpeeds = [];
foreach ($players as $p) if (isset($speeds[(string)$p['player_id']])) $autoSpeeds[mb_strtolower($p['pseudo'], 'UTF-8')] = $speeds[(string)$p['player_id']];
workspaceStart($u, 'Outils');
?>
<link rel="stylesheet" href="/assets/tools.css?v=2">
<link rel="stylesheet" href="/assets/uptep-map.css?v=1">
<script src="/assets/tools-combat.js?v=1" defer></script>
<script src="/assets/tools-rc.js?v=1" defer></script>
<script src="/assets/tools.js?v=1" defer></script>
<script src="/assets/uptep-map.js?v=1" defer></script>
<script src="/assets/tools-trajets.js?v=1" defer></script>
<section class="tools-page" aria-labelledby="tools-title">
<header class="tools-heading"><span class="tools-eyebrow">LA BOÎTE À OUTILS DE LA COLONIE</span><h1 id="tools-title">Outils</h1><p>Les calculateurs utiles pour préparer tes actions sur Fourmizzz.</p></header>
<div class="tools-tabs" role="tablist" aria-label="Outils disponibles">
<button type="button" id="tab-combat" role="tab" aria-selected="<?= $tab === 'combat' ? 'true' : 'false' ?>" aria-controls="panel-combat" data-tool-tab<?= $tab === 'combat' ? '' : ' tabindex="-1"' ?>>Bonus de combat</button>
<button type="button" id="tab-trajets" role="tab" aria-selected="<?= $tab === 'trajets' ? 'true' : 'false' ?>" aria-controls="panel-trajets" data-tool-tab<?= $tab === 'trajets' ? '' : ' tabindex="-1"' ?>>Trajets</button>
</div>
<section id="panel-combat" class="tool-panel" role="tabpanel" aria-labelledby="tab-combat" tabindex="0"<?= $tab === 'combat' ? '' : ' hidden' ?>>
<div id="rc-calculator"><p>Chargement du calculateur…</p></div>
<noscript><p>Active JavaScript pour analyser un rapport de combat.</p></noscript>
<p class="tools-privacy">Le calcul reste dans ton navigateur : ton rapport n’est ni envoyé ni enregistré sur le serveur.</p>
</section>
<section id="panel-trajets" class="tool-panel" role="tabpanel" aria-labelledby="tab-trajets" tabindex="0"<?= $tab === 'trajets' ? '' : ' hidden' ?>>
<h2>Trajets vers des alliances</h2>
<p class="note">Distance et temps de trajet d’attaque (aller simple) depuis un joueur vers tous les membres des alliances choisies, d’après le dernier relevé du serveur 5.</p>
<?php if ($unavailable): ?><div class="form-error" role="alert">Les données du jeu sont momentanément indisponibles. Réessaie dans quelques instants.</div>
<?php else: ?>
<form class="card trajets-form" method="get" action="/outils.php" data-trajets-form>
<input type="hidden" name="outil" value="trajets">
<div class="trajets-fields">
<label class="trajets-field"><span>Joueur de départ</span><input type="text" name="joueur" list="trajets-joueurs" required autocomplete="off" spellcheck="false" placeholder="Pseudo du joueur" value="<?= e(is_string($_GET['joueur'] ?? null) ? $_GET['joueur'] : '') ?>"></label>
<label class="trajets-field"><span>Vitesse d’attaque (VA)</span><input type="number" name="va" min="0" max="999" step="1" inputmode="numeric" placeholder="Niveau, ex. 10" value="<?= e(is_string($_GET['va'] ?? null) ? $_GET['va'] : '') ?>"><small data-trajets-va-hint><?= !empty($u['is_chef']) ? 'Remplie depuis UPTEP+ pour les membres de l’alliance.' : 'Remplie depuis UPTEP+ pour ton propre joueur.' ?></small></label>
</div>
<fieldset class="trajets-alliances"><legend>Alliances visées <small data-trajets-picked></small></legend>
<input type="search" class="trajets-filter" placeholder="Filtrer les alliances…" aria-label="Filtrer les alliances" data-trajets-filter>
<div class="trajets-alliance-list">
<?php foreach ($alliances as $tag => $n): $tag = (string)$tag; ?><label class="trajets-alliance"><input type="checkbox" name="alliances[]" value="<?= e($tag) ?>"<?= isset($picked[$tag]) ? ' checked' : '' ?>> <span><?= e($allianceName($tag)) ?></span> <small><?= (int)$n ?></small></label>
<?php endforeach; ?></div>
<p class="trajets-empty" data-trajets-none hidden>Aucune alliance ne correspond.</p>
</fieldset>
<button class="btn" type="submit">Calculer les trajets</button>
<datalist id="trajets-joueurs"><?php foreach ($players as $p): ?><option value="<?= e($p['pseudo']) ?>"><?= e($allianceName($p['tag'])) ?></option><?php endforeach; ?></datalist>
<script type="application/json" id="trajets-speeds"><?= json_encode($autoSpeeds, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR|JSON_FORCE_OBJECT) ?></script>
</form>
<?php if ($error): ?><div class="form-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if ($result): $o = $result['origin']; $count = count($result['targets']); ?>
<div class="card trajets-summary">
<p><strong><?= e($o['pseudo']) ?></strong> <span class="trajets-tag"><?= e($allianceName($o['tag'])) ?></span> · X <?= (int)$o['x'] ?> · Y <?= (int)$o['y'] ?> · <?= e(number_format((int)$o['field'], 0, ',', ' ')) ?> TDC · <strong>VA <?= (int)$result['speed'] ?></strong> <span class="muted">(<?= $result['source'] === 'uptep' ? 'UPTEP+' : 'saisie' ?>)</span></p>
<p class="muted"><?= $count ?> joueur<?= $count > 1 ? 's' : '' ?> dans <?= e(implode(', ', array_map($allianceName, $result['tags']))) ?>, du plus proche au plus loin.</p>
</div>
<?php if (!$count): ?><p class="note">Aucun joueur dans ces alliances.</p><?php else: ?>
<div class="trajets-table-wrap" role="region" aria-label="Trajets classés par distance" tabindex="0"><table class="trajets-table"><thead><tr><th scope="col">#</th><th scope="col">Joueur</th><th scope="col">Alliance</th><th scope="col">TDC</th><th scope="col">Distance</th><th scope="col">Trajet</th><th scope="col"><span class="sr-only">Carte</span></th></tr></thead><tbody>
<?php foreach ($result['targets'] as $i => $t): ?><tr><td><?= $i + 1 ?></td><th scope="row"><?= e($t['pseudo']) ?><?php if ($t['on_holiday'] || $t['is_banned']): ?> <span class="trajets-state"><?= e(implode(' · ', array_filter([$t['on_holiday'] ? 'Vacances' : null, $t['is_banned'] ? 'Banni' : null]))) ?></span><?php endif; ?></th><td><?= e($allianceName($t['tag'])) ?></td><td><?= e(number_format((int)$t['field'], 0, ',', ' ')) ?></td><td><?= e(number_format((int)ceil($t['distance']), 0, ',', ' ')) ?></td><td><?= e(trajetsDuration($t['seconds'])) ?></td><td><button type="button" class="trajets-show" data-trajets-show="<?= (int)$t['player_id'] ?>" aria-label="<?= e('Voir '.$t['pseudo'].' sur la carte') ?>">Carte</button></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php endif; ?>
<?php
$mapPlayers = [['id' => (int)$o['player_id'], 'pseudo' => $o['pseudo'], 'x' => (int)$o['x'], 'y' => (int)$o['y'], 'field' => (int)$o['field'], 'tag' => $o['tag']]];
foreach ($result['targets'] as $t) $mapPlayers[] = ['id' => (int)$t['player_id'], 'pseudo' => $t['pseudo'], 'x' => (int)$t['x'], 'y' => (int)$t['y'], 'field' => (int)$t['field'], 'tag' => $t['tag']];
?>
<section class="trajets-map" aria-labelledby="trajets-map-title"><div class="trajets-map-heading"><h3 id="trajets-map-title">Carte</h3><span class="user-count" data-trajets-count></span></div>
<script type="application/json" id="trajets-map-data"><?= json_encode(['players' => $mapPlayers, 'origin' => (int)$o['player_id'], 'speed' => (int)$result['speed'], 'tags' => $result['tags']], JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) ?></script>
<?= uptepMapMarkup('trajets', 'trajets-map-title', 'Alliances', 'alliance', 'Clic sur un joueur pour mesurer une distance (temps de trajet depuis '.$o['pseudo'].') · molette ou +/− pour zoomer · glisser pour déplacer.') ?>
</section>
<?php endif; ?>
<?php endif; ?>
</section>
</section>
<?php workspaceEnd(); ?>
