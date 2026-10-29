<?php
declare(strict_types=1);
require '/home/uptep/private/app/bootstrap.php';
require '/home/uptep/private/app/view.php';
require '/home/uptep/private/app/resource-balance.php';
require '/home/uptep/private/app/resource-activity.php';
require '/home/uptep/private/app/convoy-consumption.php';
$u=requireUser();
if ($u['role'] !== 'admin' && empty($u['is_chef'])) {
    http_response_code(403); workspaceStart($u, 'Accès réservé');
    echo '<h1>Accès réservé</h1><p>Les statistiques de l’alliance sont réservées aux chefs et aux administrateurs.</p>';
    workspaceEnd(); exit;
}
$sort=is_string($_GET['tri']??null)&&in_array($_GET['tri'],RESOURCE_BALANCE_SORTS,true)?$_GET['tri']:'ecart';
$asc=($_GET['ordre']??null)==='asc';
$input=static fn(string $k):string=>is_string($_GET[$k]??null)?$_GET[$k]:'';
$rangeError=null;
try {[$from,$end]=resourceBalanceRange($_GET);}
catch(DomainException $ex) {$rangeError=$ex->getMessage();[$from,$end]=resourceBalanceRange([]);}
$filtered=$rangeError===null&&($input('debut')!==''||$input('fin')!=='');
$data=null;
try {$data=resourceBalanceData(db(),db(true),$from,$end);$data['rows']=resourceBalanceSort($data['rows'],$sort,$asc);}
catch(Throwable $ex) {error_log('UPTEP resources: '.$ex->getMessage());http_response_code(503);}
// Consumption chart, floods and hunts: same period, each section failing on its own.
$conso=$floods=$hunts=null;
if($data!==null){
    $memberNames=array_column($data['rows'],'pseudo','id');
    try {$conso=convoyConsumptionData(db(),db(true),$from,$end,true);} catch(Throwable $ex) {error_log('UPTEP stats consumption: '.$ex->getMessage());}
    try {$floods=resourceFloodStats(db(),$memberNames,$from,$end);} catch(Throwable $ex) {error_log('UPTEP stats floods: '.$ex->getMessage());}
    try {$hunts=resourceHuntStats(db(),$memberNames,$from,$end);} catch(Throwable $ex) {error_log('UPTEP stats hunts: '.$ex->getMessage());}
}
$paris=new DateTimeZone('Europe/Paris');
$date=static fn(DateTimeImmutable $d,string $f='d/m/Y H:i')=>$d->setTimezone($paris)->format($f);
// Column header: a GET link sorting on the key (keeping the period); the active column toggles its direction.
$period=$filtered?array_filter(['debut'=>$input('debut'),'fin'=>$input('fin')],'strlen'):[];
$th=static function(string $key,string $label)use($sort,$asc,$period):string{
    $active=$key===$sort;$next=$active?($asc?'desc':'asc'):($key==='pseudo'?'asc':'desc');
    $aria=$active?' aria-sort="'.($asc?'ascending':'descending').'"':'';
    return '<th scope="col"'.$aria.'><a class="resource-sort" href="?'.e(http_build_query($period+['tri'=>$key,'ordre'=>$next])).'">'.$label.'<span aria-hidden="true">'.($active?($asc?' ↑':' ↓'):' ↕').'</span></a></th>';
};
// Compact amount (k, M, G, T); the exact value stays in the tooltip.
$amount=static fn(float $v,bool $signed=false)=>'<span title="'.e(($signed&&$v>0.5?'+':'').resourceNumber($v)).'">'.e(($signed&&$v>=500?'+':'').resourceCompact(round($v))).'</span>';
$verdicts=['convoyer'=>'Convoyer','recevoir'=>'Recevoir','equilibre'=>'Équilibré','aucun'=>'—'];
$now=new DateTimeImmutable();
$ago=static function(DateTimeImmutable $at)use($now):string{
    $s=max(0,$now->getTimestamp()-$at->getTimestamp());
    return 'il y a '.($s<3600?max(1,intdiv($s,60)).' min':($s<172800?intdiv($s,3600).' h':intdiv($s,86400).' j'));
};
// Estimated warehouse: last reading + cycles − sent + received since; the detail is in the tooltip.
$stock=static function(array $r)use($ago,$date):string{
    if($r['stock']===null){
        $why=$r['releve']?'Dernier relevé le '.$date($r['releve']['at']).' : plus de 48 h.':'Aucun relevé de matériaux UPTEP+.';
        return '<td title="'.e($why).'">—<span class="resource-age">'.e($r['releve']?$ago($r['releve']['at']):'aucun relevé').'</span></td>';
    }
    $tip='Relevé du '.$date($r['releve']['at']).' : '.resourceNumber($r['releve']['materiaux'])
        ."\n+ production : ".resourceNumber($r['stock_production']).' ('.$r['stock_cycles'].' cycle'.($r['stock_cycles']>1?'s':'').', colonies comprises)'
        ."\n− convois envoyés : ".resourceNumber($r['stock_envoye'])
        ."\n+ convois reçus : ".resourceNumber($r['stock_recu'])
        ."\n= ".resourceNumber($r['stock']).' estimés';
    return '<td title="'.e($tip).'">'.e(resourceCompact(round($r['stock']))).'<span class="resource-age">'.e($ago($r['releve']['at'])).'</span></td>';
};
$count=static fn(int $n)=>'<span>'.e(number_format($n,0,',',' ')).'</span>';
$unavailable='<p class="form-error" role="alert">Cette partie est indisponible pour le moment. Réessaie dans quelques instants.</p>';
workspaceStart($u,'Statistiques');
?>
<link rel="stylesheet" href="/assets/convoy-daily.css?v=9">
<link rel="stylesheet" href="/assets/ressources.css?v=9">
<section class="resources-page">
<h1>Statistiques</h1>
<p class="admin-intro">Ressources, consommation, floods et chasses des membres actuels de l’alliance sur la période choisie.</p>
<?php $today=(new DateTimeImmutable('now',$paris))->format('Y-m-d');$firstDay=(new DateTimeImmutable(RESOURCE_BALANCE_START,$paris))->format('Y-m-d'); ?>
<form method="get" class="convoy-filters resource-period"><?php if($sort!=='ecart'||$asc): ?><input type="hidden" name="tri" value="<?= e($sort) ?>"><input type="hidden" name="ordre" value="<?= $asc?'asc':'desc' ?>"><?php endif; ?>
<label>Du<input type="date" name="debut" min="<?= e($firstDay) ?>" max="<?= e($today) ?>" value="<?= e($rangeError===null?$date($from,'Y-m-d'):$input('debut')) ?>"></label>
<label>Au<input type="date" name="fin" min="<?= e($firstDay) ?>" max="<?= e($today) ?>" value="<?= e($rangeError===null?$date($end->modify('-1 second'),'Y-m-d'):$input('fin')) ?>"></label>
<button class="save-user" type="submit">Afficher</button><?php if($filtered||$rangeError!==null): ?><a class="resource-reset" href="/statistiques.php">7 derniers jours</a><?php endif; ?><?php if($input('debut')!==RESOURCE_BALANCE_START||$input('fin')!==''): ?><a class="resource-reset" href="/statistiques.php?debut=<?= e(RESOURCE_BALANCE_START) ?>">Depuis le <?= e($date(new DateTimeImmutable(RESOURCE_BALANCE_START,$paris),'d/m')) ?></a><?php endif; ?></form>
<?php if($rangeError!==null): ?><p class="form-error" role="alert"><?= e($rangeError) ?> Affichage des 7 derniers jours.</p><?php endif; ?>
<nav class="stats-sections" aria-label="Parties de la page"><a href="#ressources">Ressources</a><a href="#consommation">Consommation</a><a href="#flood">Flood</a><a href="#chasse">Chasse</a></nav>
<?php if($data===null): ?><p class="form-error" role="alert">Les données sont indisponibles pour le moment. Réessaie dans quelques instants.</p>
<?php elseif(!$data['rows']): ?><p>Aucun membre UPTEP dans le dernier relevé.</p>
<?php else: $members=count($data['rows']); ?>
<h2 id="ressources" class="stats-title">Ressources</h2>
<div class="resource-note"><strong>Méthode</strong>
<p>Période du <?= e($date($data['start'])) ?> au <?= e($date($data['end']->setTimezone($paris)->format('H:i')==='00:00'?$data['end']->modify('-1 minute'):$data['end'])) ?> (Paris). Les convois déclarés commencent le <?= e($date(new DateTimeImmutable(RESOURCE_BALANCE_START,$paris),'d/m/Y')) ?>. Chaque cycle de 30 minutes produit 1 matériau par cm² de terrain, d’après le dernier relevé horaire. La production d’un cycle est additionnée pour l’alliance puis partagée à parts égales entre les membres présents à ce moment. Les anciens membres ne sont pas comptés.</p>
<p>Une colonie verse à son colonisateur 20 % + 1 point par niveau d’étable à pucerons de celui-ci (100 % au plus), lorsque les deux sont membres.<?= $data['assumed_rates']?' Étable inconnue pour au moins un colonisateur : 20 % retenus.':'' ?> Net = production + colonies − matériaux envoyés + matériaux reçus, sur la période. Écart = net − part : positif, le membre a gardé plus que sa part et doit convoyer ; négatif, il doit recevoir. Équilibré à ±<?= (int)(RESOURCE_BALANCE_TOLERANCE*100) ?> % de la part.</p>
<p>Entrepôt estimé : dernier relevé de matériaux UPTEP+ de moins de 48 h, plus la production de chaque cycle depuis (colonies comprises), moins les convois envoyés depuis (au départ), plus les convois reçus depuis (à l’arrivée). Les dépenses en constructions et recherches et la capacité de l’entrepôt ne sont pas connues : c’est un maximum. Il est calculé à maintenant, quelle que soit la période.</p></div>
<div class="resource-summary">
<div><span>Production de l’alliance</span><strong><?= $amount($data['total']) ?></strong><small>matériaux · <?= e(resourceNumber($data['cycles'])) ?> cycles</small></div>
<div><span><?= $data['end']<new DateTimeImmutable('-1 hour')?'Production / heure en fin de période':'Production actuelle / heure' ?></span><strong><?= $amount($data['current']) ?></strong><small><?= $amount($data['current']/$members) ?> par membre</small></div>
<div><span>Convois de matériaux</span><strong><?= $amount($data['materials']) ?></strong><small><?= e(resourceNumber($data['convoys'])) ?> convois déclarés</small></div>
<div><span>Membres · colonies</span><strong><?= $members ?> · <?= (int)$data['colonies'] ?></strong><small>Du <?= e($date($data['start'],'d/m')) ?> au <?= e($date($data['end']->modify('-1 second'),'d/m')) ?></small></div>
</div>
<section class="users-panel"><div class="users-panel-heading"><h3>Répartition par membre</h3><span class="user-count"><?= $members ?> membres</span></div>
<div class="users-scroll" tabindex="0" role="region" aria-label="Répartition des matériaux par membre"><table class="users-table resource-table"><thead><tr>
<?= $th('pseudo','Membre') ?><?= $th('terrain','Terrain<br>actuel') ?><?= $th('stock','Entrepôt<br>estimé') ?><?= $th('production','Production') ?><?= $th('colonie','Colonies') ?><?= $th('envoye','Envoyés') ?><?= $th('recu','Reçus') ?><?= $th('ecart','Écart') ?><?= $th('ratio','Ratio') ?><th scope="col">Verdict</th>
</tr></thead><tbody>
<?php foreach($data['rows'] as $r): ?>
<tr class="resource-<?= e($r['verdict']) ?>"><th scope="row"><?= e($r['pseudo']) ?></th>
<td><?= $amount($r['terrain']) ?></td>
<?= $stock($r) ?>
<td><?= $amount($r['production']) ?></td>
<td title="<?= e('Reçu : '.resourceNumber($r['taxe_recue']).' · Versé : '.resourceNumber($r['taxe_payee'])) ?>"><?= e(($r['colonie']>=500?'+':'').resourceCompact(round($r['colonie']))) ?></td>
<td><?= $amount($r['envoye']) ?></td>
<td><?= $amount($r['recu']) ?></td>
<td class="resource-gap" title="<?= e('Net '.resourceNumber($r['net']).' − part '.resourceNumber($r['part']).' = '.resourceNumber($r['ecart'])) ?>"><?= e(($r['ecart']>=500?'+':'').resourceCompact(round($r['ecart']))) ?></td>
<td><?= $r['ratio']===null?'—':e(number_format($r['ratio']*100,0,',',' ').' %') ?></td>
<td><span class="resource-verdict"><?= e($verdicts[$r['verdict']]) ?></span></td></tr>
<?php endforeach; ?></tbody></table></div></section>
<p class="resource-legend">Envoyés et reçus : convois déclarés non annulés, matériaux uniquement, à leur date de départ.<?= $data['outside']>0?' Dont '.$amount($data['outside']).' échangés avec d’anciens membres : comptés seulement du côté du membre actuel.':'' ?> Survole les colonnes Entrepôt estimé, Colonies et Écart (net et part) pour le détail.</p>

<h2 id="consommation" class="stats-title">Consommation</h2>
<?php if($conso===null): ?><?= $unavailable ?>
<?php elseif(!$conso['rows']): ?><p class="chaine-empty">Aucun relevé de terrain sur cette période.</p>
<?php else: ?>
<section class="conso" aria-labelledby="conso-title">
<h3 id="conso-title" class="stats-subtitle">Ce qui entre et ce qui sort, par jour</h3>
<p id="conso-desc" class="conso-sub">Matériaux uniquement pour les convois. À droite ce que le membre gagne, à gauche ce qu’il cède ; trait noir et chiffre = conso.<?php if($conso['average']!==null): ?> Moyenne : <?= e(consumptionK($conso['average'])) ?>k/j.<?php endif; ?></p>
<ul class="conso-legend" aria-label="Légende">
<?php foreach(CONSUMPTION_LABELS as $k=>$label): ?><li><svg width="14" height="14" aria-hidden="true"><rect width="14" height="14" rx="3" fill="<?= CONSUMPTION_COLORS[$k] ?>"/></svg><?= e(str_starts_with($k,'conv_')?str_replace('Convois','Convois de matériaux',$label):$label) ?></li><?php endforeach; ?>
<li><svg width="14" height="14" aria-hidden="true"><rect x="5.5" width="3" height="14" fill="#0b0b0b"/></svg>Conso (= entrées − sorties)</li>
<li><svg width="18" height="14" aria-hidden="true"><line x1="0" x2="18" y1="7" y2="7" stroke="#52514e" stroke-width="1.5" stroke-dasharray="6 4"/></svg>Conso moyenne<?php if($conso['average']!==null): ?> (<?= e(consumptionK($conso['average'])) ?>k/j)<?php endif; ?></li></ul>
<div class="conso-scroll" tabindex="0" role="region" aria-label="Graphique de consommation, défilant horizontalement"><?= convoyConsumptionSvg($conso) ?></div>
<p class="daily-note">Nom · TdC moyen, greniers en gras. Production = 48 × TdC par jour (une ressource par cm² et par cycle de 30 min). Taxe de colonie : 20 % de la production de la colonie, + 1 point par niveau d’étable à pucerons du colonisateur quand il est connu<?= $conso['assumed_rates']?' (20 % sinon)':'' ?>. Convois de matériaux déclarés, comptés à leur date de départ. Même graphique que dans Demandes et convois → Statistiques, où les pommes sont aussi comptées.</p>
</section>
<?php endif; ?>

<h2 id="flood" class="stats-title">Flood</h2>
<?php if($floods===null): ?><?= $unavailable ?>
<?php else: $ft=$floods['total']; ?>
<div class="resource-summary">
<div><span>Floods arrivés</span><strong><?= $count($ft['floods']) ?></strong><small><?= $ft['loge']>0?e($ft['loge'].' hors terrain de chasse'):'tous sur le terrain de chasse' ?></small></div>
<div><span>Troupes envoyées</span><strong><?= $amount($ft['troupes']) ?></strong><small><?= $ft['floods']>$ft['inconnues']?$amount($ft['troupes']/($ft['floods']-$ft['inconnues'])).' par flood':'—' ?></small></div>
<div><span>Troupes inconnues</span><strong><?= $count($ft['inconnues']) ?></strong><small>floods déclarés sans troupes lisibles</small></div>
<div><span>En route maintenant</span><strong><?= $count($ft['en_route']) ?></strong><small>floods pas encore arrivés</small></div>
</div>
<section class="users-panel"><div class="users-panel-heading"><h3>Floods par membre</h3><span class="user-count"><?= count($floods['rows']) ?> membres</span></div>
<div class="users-scroll" tabindex="0" role="region" aria-label="Floods par membre"><table class="users-table resource-table stats-table"><thead><tr><th scope="col">Membre</th><th scope="col">Envoyés</th><th scope="col">Reçus</th><th scope="col">Dernier<br>envoyé</th></tr></thead><tbody>
<?php foreach($floods['rows'] as $r): ?><tr><th scope="row"><?= e($r['pseudo']) ?></th><td><?= $count($r['envoyes']) ?></td><td><?= $count($r['recus']) ?></td><td><?= $r['dernier']?e($date($r['dernier'],'d/m H\hi')):'—' ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<p class="resource-legend">Floods déclarés (bot Discord et UPTEP+), comptés à leur heure d’arrivée sur la période. Troupes : total des unités déclarées pour l’alliance ; un flood sans troupes lisibles compte dans le nombre mais pas dans les troupes. Le terrain pris par un flood n’est pas enregistré.</p>
<?php endif; ?>

<h2 id="chasse" class="stats-title">Chasse</h2>
<?php if($hunts===null): ?><?= $unavailable ?>
<?php else: $ht=$hunts['total']; ?>
<div class="resource-summary">
<div><span>Chasses terminées</span><strong><?= $count($ht['chasses']) ?></strong><small>arrivées sur la période</small></div>
<div><span>Terrain chassé</span><strong><?= $amount($ht['terrain']) ?></strong><small><?= $ht['chasses']>0?$amount($ht['terrain']/$ht['chasses']).' cm² par chasse':'cm²' ?></small></div>
<div><span>En cours maintenant</span><strong><?= $count($ht['en_cours']) ?></strong><small><?= $amount($ht['terrain_en_cours']) ?> cm² attendus</small></div>
<div><span>Membres suivis</span><strong><?= (int)$ht['suivis'] ?> / <?= $members ?></strong><small>chasses relevées par UPTEP+ ou Discord</small></div>
</div>
<?php if($hunts['rows']): ?>
<section class="users-panel"><div class="users-panel-heading"><h3>Chasses par membre</h3><span class="user-count"><?= count($hunts['rows']) ?> membres suivis</span></div>
<div class="users-scroll" tabindex="0" role="region" aria-label="Chasses par membre"><table class="users-table resource-table stats-table"><thead><tr><th scope="col">Membre</th><th scope="col">Chasses</th><th scope="col">Terrain<br>chassé</th><th scope="col">Moyenne<br>par chasse</th><th scope="col">Dernière<br>arrivée</th><th scope="col">En cours</th></tr></thead><tbody>
<?php foreach($hunts['rows'] as $r): ?><tr><th scope="row"><?= e($r['pseudo']) ?></th><td><?= $count($r['chasses']) ?></td><td><?= $amount($r['terrain']) ?></td><td><?= $r['moyenne']===null?'—':$amount($r['moyenne']) ?></td><td><?= $r['derniere']?e($date($r['derniere'],'d/m H\hi')):'—' ?></td><td><?= $r['en_cours']>0?$count($r['en_cours']).' · '.$amount($r['terrain_en_cours']):'—' ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<?php endif; ?>
<?php $missing=array_diff_key($memberNames,array_flip(array_column($hunts['rows'],'id'))); ?>
<p class="resource-legend">Chasses non annulées, comptées à leur heure d’arrivée sur la période ; « En cours » est l’état actuel. Terrain en cm².<?php if($missing): ?> Sans chasse relevée : <?= e(implode(', ',array_map('strval',$missing))) ?>.<?php endif; ?></p>
<?php endif; ?>
<?php endif; ?></section>
<?php workspaceEnd(); ?>
