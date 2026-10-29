<?php
declare(strict_types=1);
require '/home/uptep/private/app/bootstrap.php';
require '/home/uptep/private/app/view.php';
require '/home/uptep/private/app/resource-balance.php';
$u=requireUser();
if ($u['role'] !== 'admin') {
    http_response_code(403); workspaceStart($u, 'Accès réservé');
    echo '<h1>Accès réservé</h1><p>La gestion des ressources est réservée aux administrateurs.</p>';
    workspaceEnd(); exit;
}
$sort=is_string($_GET['tri']??null)&&in_array($_GET['tri'],RESOURCE_BALANCE_SORTS,true)?$_GET['tri']:'ecart';
$asc=($_GET['ordre']??null)==='asc';
$data=null;
try {$data=resourceBalanceData(db(),db(true));$data['rows']=resourceBalanceSort($data['rows'],$sort,$asc);}
catch(Throwable $ex) {error_log('UPTEP resources: '.$ex->getMessage());http_response_code(503);}
$paris=new DateTimeZone('Europe/Paris');
$date=static fn(DateTimeImmutable $d,string $f='d/m/Y H:i')=>$d->setTimezone($paris)->format($f);
// Column header: a GET link sorting on the key; the active column toggles its direction.
$th=static function(string $key,string $label)use($sort,$asc):string{
    $active=$key===$sort;$next=$active?($asc?'desc':'asc'):($key==='pseudo'||$key==='entree'?'asc':'desc');
    $aria=$active?' aria-sort="'.($asc?'ascending':'descending').'"':'';
    return '<th scope="col"'.$aria.'><a class="resource-sort" href="?tri='.$key.'&amp;ordre='.$next.'">'.$label.'<span aria-hidden="true">'.($active?($asc?' ↑':' ↓'):' ↕').'</span></a></th>';
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
workspaceStart($u,'Gestion des Ressources');
?>
<link rel="stylesheet" href="/assets/ressources.css?v=6">
<section class="resources-page">
<h1>Gestion des Ressources</h1>
<p class="admin-intro">Production de matériaux de l’alliance mise en commun et partagée à parts égales, comparée à ce que chaque membre a gardé après colonies et convois.</p>
<?php if($data===null): ?><p class="form-error" role="alert">Les données sont indisponibles pour le moment. Réessaie dans quelques instants.</p>
<?php elseif(!$data['rows']): ?><p>Aucun membre UPTEP dans le dernier relevé.</p>
<?php else: $members=count($data['rows']); ?>
<div class="resource-note"><strong>Méthode</strong>
<p>Depuis le <?= e($date($data['start'],'d/m/Y')) ?>, date du premier convoi déclaré, chaque cycle de 30 minutes produit 1 matériau par cm² de terrain, d’après le dernier relevé horaire. La production d’un cycle est additionnée pour l’alliance puis partagée à parts égales entre les membres présents à ce moment. Les anciens membres ne sont pas comptés.</p>
<p>Une colonie verse à son colonisateur 20 % + 1 point par niveau d’étable à pucerons de celui-ci (100 % au plus), lorsque les deux sont membres.<?= $data['assumed_rates']?' Étable inconnue pour au moins un colonisateur : 20 % retenus.':'' ?> Net = production + colonies − matériaux envoyés + matériaux reçus. Écart = net − part : positif, le membre a gardé plus que sa part et doit convoyer ; négatif, il doit recevoir. Équilibré à ±<?= (int)(RESOURCE_BALANCE_TOLERANCE*100) ?> % de la part.</p>
<p>Entrepôt estimé : dernier relevé de matériaux UPTEP+ de moins de 48 h, plus la production de chaque cycle depuis (colonies comprises), moins les convois envoyés depuis (au départ), plus les convois reçus depuis (à l’arrivée). Les dépenses en constructions et recherches et la capacité de l’entrepôt ne sont pas connues : c’est un maximum.</p></div>
<div class="resource-summary">
<div><span>Production de l’alliance</span><strong><?= $amount($data['total']) ?></strong><small>matériaux · <?= e(resourceNumber($data['cycles'])) ?> cycles</small></div>
<div><span>Production actuelle / heure</span><strong><?= $amount($data['current']) ?></strong><small><?= $amount($data['current']/$members) ?> par membre</small></div>
<div><span>Convois de matériaux</span><strong><?= $amount($data['materials']) ?></strong><small><?= e(resourceNumber($data['convoys'])) ?> convois déclarés</small></div>
<div><span>Membres · colonies</span><strong><?= $members ?> · <?= (int)$data['colonies'] ?></strong><small>Jusqu’au <?= e($date($data['end'])) ?> (Paris)</small></div>
</div>
<?php if($svg=resourceBalanceSvg($data)): ?><section class="users-panel resource-chart-panel"><div class="users-panel-heading"><h2>Production de l’alliance par heure</h2></div><div class="users-scroll"><?= $svg ?></div></section><?php endif; ?>
<section class="users-panel"><div class="users-panel-heading"><h2>Répartition par membre</h2><span class="user-count"><?= $members ?> membres</span></div>
<div class="users-scroll" tabindex="0" role="region" aria-label="Répartition des matériaux par membre"><table class="users-table resource-table"><thead><tr>
<?= $th('pseudo','Membre') ?><?= $th('entree','Entrée') ?><?= $th('terrain','Terrain<br>actuel') ?><?= $th('stock','Entrepôt<br>estimé') ?><?= $th('production','Production') ?><?= $th('colonie','Colonies') ?><?= $th('part','Part') ?><?= $th('envoye','Envoyés') ?><?= $th('recu','Reçus') ?><?= $th('net','Net') ?><?= $th('ecart','Écart') ?><?= $th('ratio','Ratio') ?><th scope="col">Verdict</th>
</tr></thead><tbody>
<?php foreach($data['rows'] as $r): ?>
<tr class="resource-<?= e($r['verdict']) ?>"><th scope="row"><?= e($r['pseudo']) ?></th>
<td><?= $r['entree']?e($date($r['entree'],'d/m H\h')):'—' ?></td>
<td><?= $amount($r['terrain']) ?></td>
<?= $stock($r) ?>
<td><?= $amount($r['production']) ?></td>
<td title="<?= e('Reçu : '.resourceNumber($r['taxe_recue']).' · Versé : '.resourceNumber($r['taxe_payee'])) ?>"><?= e(($r['colonie']>=500?'+':'').resourceCompact(round($r['colonie']))) ?></td>
<td><?= $amount($r['part']) ?></td>
<td><?= $amount($r['envoye']) ?></td>
<td><?= $amount($r['recu']) ?></td>
<td><?= $amount($r['net']) ?></td>
<td class="resource-gap"><?= $amount($r['ecart'],true) ?></td>
<td><?= $r['ratio']===null?'—':e(number_format($r['ratio']*100,0,',',' ').' %') ?></td>
<td><span class="resource-verdict"><?= e($verdicts[$r['verdict']]) ?></span></td></tr>
<?php endforeach; ?></tbody></table></div></section>
<p class="resource-legend">Envoyés et reçus : convois déclarés non annulés, matériaux uniquement, à leur date de départ.<?= $data['outside']>0?' Dont '.$amount($data['outside']).' échangés avec d’anciens membres : comptés seulement du côté du membre actuel.':'' ?> Survole les colonnes Colonies et Entrepôt estimé pour le détail.</p>
<?php endif; ?></section>
<?php workspaceEnd(); ?>
