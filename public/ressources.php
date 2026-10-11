<?php
declare(strict_types=1);
require '/home/uptep/private/app/bootstrap.php';
require '/home/uptep/private/app/view.php';
require '/home/uptep/private/app/chaine.php';
$u=requireUser();$players=[];$latest='';$unavailable=false;
try {
    $q=db()->prepare('SELECT player_id,grade,sort_order FROM chaine_members WHERE server=?');
    $q->execute([CHAINE_SERVER]);$grades=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$grades[(string)$row['player_id']]=$row;
    foreach(chainePlayers() as $player) {
        $grade=$grades[(string)$player['player_id']]??null;
        if(!$grade || !in_array($grade['grade'],CHAINE_GRADES,true) || $grade['grade']==='Hors chaîne')continue;
        $player['chain']=$grade;$players[]=$player;
        $latest=max($latest,$player['export_version']);
    }
    usort($players,static fn($a,$b)=>(array_search($a['chain']['grade'],CHAINE_GRADES,true)<=>array_search($b['chain']['grade'],CHAINE_GRADES,true)) ?: (($a['chain']['sort_order']??PHP_INT_MAX)<=>($b['chain']['sort_order']??PHP_INT_MAX)) ?: strnatcasecmp($a['pseudo'],$b['pseudo']));
} catch(Throwable $ex) {error_log('UPTEP resources: '.$ex->getMessage());http_response_code(503);$unavailable=true;}
workspaceStart($u,'Gestion des Ressources');
?>
<link rel="stylesheet" href="/assets/ressources.css?v=2">
<script src="/assets/ressources.js?v=2" defer></script>
<section class="resources-page">
<h1>Gestion des Ressources</h1>
<p class="admin-intro">Simule la production de bois et sa redistribution entre les membres de la chaîne.</p>
<?php if($unavailable): ?><p class="form-error" role="alert">Les données sont indisponibles pour le moment. Réessaie dans quelques instants.</p>
<?php elseif(!$players): ?><p>Aucun membre avec un grade dans la chaîne.</p>
<?php else: ?>
<div class="resource-note"><strong>Simulation · TDP fictifs</strong><p>Modifie les niveaux pour essayer différentes répartitions. TDP = technique de ponte + couveuse + solarium ; poids = 1,20<sup>TDP</sup>. Un niveau supplémentaire donne un poids 20 % plus élevé.</p><p>Chaque clic ajoute une heure avec les terrains du relevé ci-dessous. Les modifications de TDP s’appliquent aux heures suivantes. Rien n’est enregistré : recharger la page remet la simulation à zéro.</p></div>
<div class="resource-summary"><div><span>Production / heure</span><strong id="resource-pool">—</strong></div><div><span>Heures simulées</span><strong id="resource-hours">0</strong></div><div><span>Total des soldes</span><strong id="resource-total">0</strong></div></div>
<div class="resource-actions"><button id="resource-simulate" class="save-user" type="button" disabled>Simuler une heure</button><button id="resource-reset" type="button">Réinitialiser la simulation</button><span id="resource-status" role="status" aria-live="polite"></span></div>
<p id="resource-error" class="form-error" role="alert" hidden></p>
<section class="users-panel"><div class="users-panel-heading"><h2>UPTEP · Serveur 5</h2><span class="user-count"><?= count($players) ?> membres</span><span class="chaine-updated">Dernier relevé : <?= e(chaineDate($latest)) ?> (Paris)</span></div>
<div class="users-scroll" tabindex="0" role="region" aria-label="Simulation des ressources"><table class="users-table resource-table"><thead><tr><th scope="col">Joueur</th><th scope="col" aria-sort="none"><button class="table-sort" type="button" data-resource-sort="field">Terrain<span aria-hidden="true"> ↕</span></button></th><th scope="col">Technique<br>de ponte</th><th scope="col">Couveuse</th><th scope="col">Solarium</th><th scope="col" aria-sort="none"><button class="table-sort" type="button" data-resource-sort="tdp">TDP total<span aria-hidden="true"> ↕</span></button></th><th scope="col">Production / h</th><th scope="col">Part attribuée / h</th><th scope="col" aria-sort="none"><button class="table-sort" type="button" data-resource-sort="balance">Solde cumulé<span aria-hidden="true"> ↕</span></button></th></tr></thead><tbody>
<?php foreach($players as $i=>$player): ?>
<tr data-resource-player="<?= (int)$player['player_id'] ?>" data-field="<?= e((string)($player['field']??'')) ?>"><th scope="row"><span class="user-display-name"><?= e($player['pseudo']) ?></span><span class="user-identifier"><?= e($player['chain']['grade']) ?><?= $player['chain']['sort_order']!==null?' · '.(int)$player['chain']['sort_order']:'' ?></span></th><td><?= $player['field']===null?'Indisponible':number_format((int)$player['field'],0,',',' ') ?></td>
<?php foreach(['Technique de ponte','Couveuse','Solarium'] as $j=>$label): ?><td><input type="number" min="0" max="999" step="1" value="<?= 5+(($i*3+$j*2)%7) ?>" aria-label="<?= e($label.' fictif de '.$player['pseudo']) ?>"></td><?php endforeach; ?>
<td data-value="tdp"></td><td data-value="production"></td><td data-value="share"></td><td data-value="balance">0</td></tr>
<?php endforeach; ?></tbody></table></div></section>
<p class="resource-legend"><span class="resource-negative">Solde négatif : bois à envoyer.</span><span class="resource-positive">Solde positif : bois à recevoir.</span> Les arrondis sont répartis pour équilibrer exactement les soldes.</p>
<section class="users-panel resource-convoy"><div class="users-panel-heading"><h2>Simuler un convoi</h2></div>
<form id="resource-convoy-form"><label>Expéditeur<select id="convoy-sender" required></select></label><label>Destinataire<select id="convoy-recipient" required></select></label><label>Quantité de bois<input id="convoy-amount" type="text" inputmode="numeric" pattern="[0-9]+" maxlength="30" required placeholder="Ex. : 10000"></label><button class="save-user" type="submit">Simuler le convoi</button></form>
<p class="resource-convoy-help">Le solde de l’expéditeur augmente du montant envoyé ; celui du destinataire diminue du même montant. Aucun convoi n’est envoyé dans le jeu.</p><p id="convoy-status" role="status" aria-live="polite"></p>
</section>
<section class="users-panel resource-convoy"><div class="users-panel-heading"><h2>Historique des convois simulés</h2><span id="convoy-count" class="user-count">0 convoi</span></div><p id="convoy-empty" class="chaine-empty">Aucun convoi simulé.</p><ol id="convoy-history" aria-label="Convois du plus récent au plus ancien"></ol></section>
<noscript><p>Active JavaScript pour utiliser le simulateur.</p></noscript>
<?php endif; ?></section>
<?php workspaceEnd(); ?>
