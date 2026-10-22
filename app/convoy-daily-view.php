<?php declare(strict_types=1); ?>
<link rel="stylesheet" href="/assets/convoy-daily.css?v=9">
<?php $c=$data['consumption']??null;$cIn=fn($k)=>is_string($_GET[$k]??null)?$_GET[$k]:''; ?>
<section class="conso" aria-labelledby="conso-title">
<form method="get" class="convoy-filters"><input type="hidden" name="vue" value="statistiques"><?php foreach(['ressource','sens','joueur'] as $k)if($cIn($k)!==''):?><input type="hidden" name="<?=e($k)?>" value="<?=e($cIn($k))?>"><?php endif;?>
<label>Du<input type="date" name="conso_debut" min="<?=e(CONSUMPTION_FIRST_DAY)?>" max="<?=e((new DateTimeImmutable('now',new DateTimeZone('Europe/Paris')))->format('Y-m-d'))?>" value="<?=e($c?$c['from']->format('Y-m-d'):$cIn('conso_debut'))?>"></label>
<label>Au<input type="date" name="conso_fin" min="<?=e(CONSUMPTION_FIRST_DAY)?>" max="<?=e((new DateTimeImmutable('now',new DateTimeZone('Europe/Paris')))->format('Y-m-d'))?>" value="<?=e($c?$c['end']->modify('-1 second')->format('Y-m-d'):$cIn('conso_fin'))?>"></label>
<button class="save-user" type="submit">Afficher</button></form>
<?php if(isset($data['consumption_error'])):?><div class="form-error" role="alert"><?=e($data['consumption_error'])?></div>
<?php elseif($c):?>
<h2 id="conso-title">Consommation des membres : ce qui entre et ce qui sort (<?=e($c['from']->format('d/m H\h'))?> → <?=e($c['end']->format('d/m H\h'))?>)</h2>
<p id="conso-desc" class="conso-sub">Par jour. À droite ce que le membre gagne, à gauche ce qu’il cède ; trait noir et chiffre = conso.<?php if($c['average']!==null):?> Moyenne : <?=e(consumptionK($c['average']))?>k/j.<?php endif;?></p>
<?php if(!$c['rows']):?><p class="chaine-empty">Aucun relevé de terrain sur cette période.</p><?php else:?>
<ul class="conso-legend" aria-label="Légende">
<?php foreach(CONSUMPTION_LABELS as $k=>$label):?><li><svg width="14" height="14" aria-hidden="true"><rect width="14" height="14" rx="3" fill="<?=CONSUMPTION_COLORS[$k]?>"/></svg><?=e($label)?></li><?php endforeach;?>
<li><svg width="14" height="14" aria-hidden="true"><rect x="5.5" width="3" height="14" fill="#0b0b0b"/></svg>Conso (= entrées − sorties)</li>
<li><svg width="18" height="14" aria-hidden="true"><line x1="0" x2="18" y1="7" y2="7" stroke="#52514e" stroke-width="1.5" stroke-dasharray="6 4"/></svg>Conso moyenne<?php if($c['average']!==null):?> (<?=e(consumptionK($c['average']))?>k/j)<?php endif;?></li></ul>
<div class="conso-scroll" tabindex="0" role="region" aria-label="Graphique de consommation, défilant horizontalement"><?=convoyConsumptionSvg($c)?></div>
<p class="daily-note">Nom · TdC moyen, greniers en gras. Production = 48 × TdC par jour (exports horaires Fourmizzz, plafond théorique : une ressource par cm² et par cycle de 30 min). Taxe de colonie : 20 % de la production de la colonie, + 1 point par niveau d’étable à pucerons du colonisateur quand il est connu<?=$c['assumed_rates']?' (20 % sinon)':''?>. Convois déclarés (bot, site, UPTEP+), comptés à leur date de départ. <?=(int)$c['exports']?> exports sur <?=e(number_format($c['days'],1,',',' '))?> jours. Survole une ligne pour le détail.</p>
<details class="conso-table"><summary>Voir les chiffres</summary><div class="users-panel users-scroll"><table class="users-table"><thead><tr><th scope="col">Joueur</th><th scope="col">TdC moyen</th><?php foreach(CONSUMPTION_LABELS as $label):?><th scope="col"><?=e($label)?> / j</th><?php endforeach;?><th scope="col">Conso / j</th></tr></thead><tbody>
<?php foreach($c['rows'] as $r):?><tr><th scope="row"><?=e($r['pseudo'])?></th><td><?=convoyStatCompact((string)round($r['tdc']))?></td><?php foreach(array_keys(CONSUMPTION_LABELS) as $k):?><td><?=convoyStatCompact((string)round($r[$k]))?></td><?php endforeach;?><td><strong><?=$r['conso']<0?'−':''?><?=convoyStatCompact((string)round(abs($r['conso'])))?></strong></td></tr><?php endforeach;?>
</tbody></table></div></details>
<?php endif;?>
<?php endif;?>
</section>
<section class="convoy-daily" aria-labelledby="daily-title">
<h2 id="daily-title">Statistiques sur 7 jours</h2>
<form method="get" class="convoy-filters"><input type="hidden" name="vue" value="statistiques"><?php foreach(['conso_debut','conso_fin'] as $k)if($cIn($k)!==''):?><input type="hidden" name="<?=e($k)?>" value="<?=e($cIn($k))?>"><?php endif;?>
<label>Ressource<select name="ressource"><?php foreach(['pommes'=>'Pommes','materiaux'=>'Matériaux'] as $value=>$label):?><option value="<?=e($value)?>" <?=$data['resource']===$value?'selected':''?>><?=e($label)?></option><?php endforeach;?></select></label>
<label>Mouvements<select name="sens"><?php foreach(['envois'=>'Livraisons envoyées','receptions'=>'Réceptions'] as $value=>$label):?><option value="<?=e($value)?>" <?=$data['direction']===$value?'selected':''?>><?=e($label)?></option><?php endforeach;?></select></label>
<label>Pseudo<input name="joueur" maxlength="100" value="<?=e($data['player'])?>" placeholder="Tous les joueurs"></label><button class="save-user" type="submit">Afficher</button></form>
<p>Du <?=e($data['start'])?> au <?=e($data['end'])?> · heure de Paris. <strong>Aujourd’hui est une journée en cours.</strong></p>
<p class="daily-note">Les envois et réceptions déclarés sont regroupés par date de départ du convoi. Seuls les convois non annulés contenant la ressource choisie sont comptés. La moyenne par jour est le total divisé par 7, jours sans convoi inclus.</p>
<p class="daily-note">Production : calculée à partir du TdC des exports Fourmizzz, à raison d’une ressource par cm² de terrain de chasse et par cycle de 30 minutes, soit TdC × 48 par jour (nourriture et matériaux confondus). Pour l’alliance, c’est le TdC moyen cumulé de tous les joueurs affichés. C’est une production théorique : colonies, consommation et stocks ne sont pas pris en compte. La production moyenne sur 7 jours utilise les jours qui ont au moins un relevé.</p>
<p class="daily-note">TdC : moyenne des relevés disponibles pour le joueur ce jour-là ; survole la valeur pour voir leur nombre. Pour le total de l’alliance, une valeur en italique est partielle : certains joueurs n’ont pas de relevé ce jour-là. « — » signifie qu’aucune donnée n’est disponible. Le TdC n’a pas de total sur 7 jours, seulement une moyenne.</p>
<div class="users-panel users-scroll daily-scroll" tabindex="0" role="region" aria-label="Statistiques quotidiennes, tableau défilant horizontalement">
<?php
// Four rows per player, labels in their own column: the cells hold numbers only.
$isSend=$data['direction']==='envois';
$metrics=[['tdc','TdC moyen'],['production','Production / jour'],['count',$isSend?'Envois':'Réceptions'],['quantity',$isSend?'Total livré':'Total reçu']];
$dailyValue=static fn(array $v,string $k):?string=>$k==='production'?convoyProduction($v['tdc']):$v[$k];
// 7-day columns: total and average per day (TdC has no total; its average is the 7-day mean).
$summary=static function(array $days,array $r,string $k):array{
    $avgTdc=convoyTdcAverage($days);
    if($k==='tdc')return [null,$avgTdc];
    if($k==='production'){$sum=0.0;$any=false;foreach($days as $d)if(isset($d['tdc'])&&is_numeric($d['tdc'])){$sum+=(float)$d['tdc']*48;$any=true;}return [$any?(string)round($sum):null,convoyProduction($avgTdc)];}
    if($k==='count')return [$r['total_count'],(string)round((float)$r['total_count']/7,2)];
    return [$r['total_quantity'],$r['daily_average']];
};
$group=static function(string $name,string $sub,array $days,array $r,bool $total)use($metrics,$dailyValue,$summary,$data){
    $n=count($metrics);
    foreach($metrics as $index=>[$k,$label]){
        echo '<tr class="daily-row'.($index===0?' is-first':'').($total?' daily-grand-total':'').'" data-metric="'.e($k).'">';
        if($index===0)echo '<th scope="rowgroup" rowspan="'.$n.'" class="daily-player">'.e($name).($sub!==''?'<small>'.e($sub).'</small>':'').'</th>';
        echo '<th scope="row" class="daily-metric-name">'.e($label).'</th>';
        foreach($data['days'] as $day){
            $v=$days[$day['date']];$value=$dailyValue($v,$k);$title='';$cls='';
            if($k==='tdc'){
                if($total){$title=$v['covered'].' / '.$data['totals']['members'].' joueurs avec relevés';if($v['covered']<$data['totals']['members']&&$v['tdc']!==null)$cls=' is-partial';}
                else $title=$v['samples'].' relevé'.($v['samples']!==1?'s':'');
            }
            echo '<td class="daily-num'.$cls.'"'.($title!==''?' title="'.e($title).'"':'').'>'.convoyStatCompact($value).'</td>';
        }
        [$sum,$avg]=$summary($days,$r,$k);
        echo '<td class="daily-num daily-total">'.convoyStatCompact($sum).'</td><td class="daily-num daily-total">'.convoyStatCompact($avg).'</td></tr>';
    }
};
?>
<table class="users-table daily-table"><caption class="daily-caption"><?= $data['resource']==='pommes'?'Pommes':'Matériaux'?> · <?=$isSend?'Livraisons envoyées':'Réceptions'?></caption>
<thead><tr><th scope="col">Pseudo</th><th scope="col">Indicateur</th><?php foreach($data['days'] as $day):?><th scope="col"><?=e($day['label'])?><br><small><?=e($day['display'])?></small></th><?php endforeach;?><th scope="col">Total 7 jours</th><th scope="col">Moyenne / jour</th></tr></thead>
<?php $t=$data['totals']; ?>
<tbody class="daily-group"><?php $group('Total',$t['members'].' joueur'.($t['members']!==1?'s':'').' affiché'.($t['members']!==1?'s':''),$t['days'],$t,true); ?></tbody>
<?php foreach($data['rows'] as $r):?><tbody class="daily-group"><?php $group($r['pseudo'],'',$r['days'],$r,false); ?></tbody><?php endforeach;?>
</table><?php if(!$data['rows']):?><p class="chaine-empty">Aucun joueur pour ces critères.</p><?php endif;?></div></section>
