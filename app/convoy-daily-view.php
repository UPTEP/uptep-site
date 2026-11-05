<?php
declare(strict_types=1);
$daysCount=(int)($data['days_count']??count($data['days']));
$baseQuery=array_filter([
    'vue'=>'convois',
    'ressource'=>$data['resource']??'materiaux',
    'sens'=>$data['direction']??'envois',
    'joueur'=>($data['player']??'')!==''?$data['player']:null
],static fn($v)=>$v!==null);
?>
<section class="convoy-daily" aria-labelledby="daily-title">
<h2 id="daily-title">Statistiques des convois sur <?= $daysCount ?> jour<?= $daysCount>1?'s':'' ?></h2>
<form method="get" class="convoy-filters resource-period">
<input type="hidden" name="vue" value="convois">
<label>Du<input type="date" name="debut" min="<?= e($data['first_day']) ?>" max="<?= e($data['today']) ?>" value="<?= e($data['debut']) ?>"></label>
<label>Au<input type="date" name="fin" min="<?= e($data['first_day']) ?>" max="<?= e($data['today']) ?>" value="<?= e($data['fin']) ?>"></label>
<label>Ressource<select name="ressource"><?php foreach(['materiaux'=>'Matériaux','pommes'=>'Pommes'] as $value=>$label):?><option value="<?=e($value)?>" <?=$data['resource']===$value?'selected':''?>><?=e($label)?></option><?php endforeach;?></select></label>
<label>Mouvements<select name="sens"><?php foreach(['envois'=>'Livraisons envoyées','receptions'=>'Réceptions'] as $value=>$label):?><option value="<?=e($value)?>" <?=$data['direction']===$value?'selected':''?>><?=e($label)?></option><?php endforeach;?></select></label>
<label>Pseudo<input name="joueur" maxlength="100" value="<?=e($data['player'])?>" placeholder="Tous les joueurs"></label>
<button class="save-user" type="submit">Afficher</button>
<?php foreach(['3j'=>'3 jours','7j'=>'7 jours','30j'=>'30 jours','tout'=>'Depuis le début'] as $pKey=>$pLabel): ?>
<a class="resource-reset" href="/statistiques.php?<?= e(http_build_query($baseQuery+['periode'=>$pKey])) ?>"<?= ($data['period']??'')===$pKey?' aria-current="true"':'' ?>><?= e($pLabel) ?></a>
<?php endforeach; ?>
</form>
<p>Du <?=e($data['start'])?> au <?=e($data['end'])?> (<?= $daysCount ?> jour<?= $daysCount>1?'s':'' ?>) · heure de Paris. <strong>Aujourd’hui est une journée en cours.</strong></p>
<p class="daily-note">Les envois et réceptions déclarés sont regroupés par date de départ du convoi. Seuls les convois non annulés contenant la ressource choisie sont comptés. La moyenne par jour est le total divisé par <?= $daysCount ?>, jours sans convoi inclus.</p>
<p class="daily-note">Production : calculée à partir du TdC des exports Fourmizzz, à raison d’une ressource par cm² de terrain de chasse et par cycle de 30 minutes, soit TdC × 48 par jour (nourriture et matériaux confondus). La ligne « Production brute / jour » ne tient pas compte des colonies ; la ligne « Prod. après colonies / jour » déduit la taxe versée par un joueur colonisé et ajoute les taxes perçues par un colonisateur au sein d’UPTEP (20 % + 1 % par niveau d’étable à pucerons du colonisateur). Ces taxes passent d’un membre à l’autre : cette ligne n’apparaît pas sur le total de l’alliance, qui ne change pas. La production moyenne sur la période utilise les jours qui ont au moins un relevé.</p>
<p class="daily-note">TdC : moyenne des relevés disponibles pour le joueur ce jour-là ; survole la valeur pour voir leur nombre. Pour le total de l’alliance, une valeur en italique est partielle : certains joueurs n’ont pas de relevé ce jour-là. « — » signifie qu’aucune donnée n’est disponible. Le TdC n’a pas de total sur la période, seulement une moyenne.</p>
<div class="users-panel users-scroll daily-scroll" tabindex="0" role="region" aria-label="Statistiques quotidiennes, tableau défilant horizontalement">
<?php
// Five rows per player, labels in their own column: the cells hold numbers only.
$isSend=$data['direction']==='envois';
$metrics=[
    ['tdc','TdC moyen'],
    ['production','Production brute / jour'],
    ['production_colonies','Prod. après colonies / jour'],
    ['count',$isSend?'Envois':'Réceptions'],
    ['quantity',$isSend?'Total livré':'Total reçu']
];
$dailyValue=static function(array $v,string $k):?string{
    if($k==='production')return convoyProduction($v['tdc']);
    if($k==='production_colonies')return convoyProduction($v['tdc_colonies']??null);
    return $v[$k];
};
// Period summary columns: total and average per day (TdC has no total; its average is the period mean).
$summary=static function(array $days,array $r,string $k)use($daysCount):array{
    $avgTdc=convoyTdcAverage($days);
    if($k==='tdc')return [null,$avgTdc];
    if($k==='production'){$sum=0.0;$any=false;foreach($days as $d)if(isset($d['tdc'])&&is_numeric($d['tdc'])){$sum+=(float)$d['tdc']*48;$any=true;}return [$any?(string)round($sum):null,convoyProduction($avgTdc)];}
    if($k==='production_colonies'){$avgCol=convoyTdcAverage($days,'tdc_colonies');$sum=0.0;$any=false;foreach($days as $d)if(isset($d['tdc_colonies'])&&is_numeric($d['tdc_colonies'])){$sum+=(float)$d['tdc_colonies']*48;$any=true;}return [$any?(string)round($sum):null,convoyProduction($avgCol)];}
    if($k==='count')return [$r['total_count'],(string)round((float)$r['total_count']/max(1,$daysCount),2)];
    return [$r['total_quantity'],$r['daily_average']];
};
$group=static function(string $name,string $sub,array $days,array $r,bool $total)use($metrics,$dailyValue,$summary,$data){
    // Colony taxes move production between members: the alliance total is unchanged, so its row is omitted there.
    $rows=$total?array_values(array_filter($metrics,static fn($m)=>$m[0]!=='production_colonies')):$metrics;
    $n=count($rows);
    foreach($rows as $index=>[$k,$label]){
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
<thead><tr><th scope="col">Pseudo</th><th scope="col">Indicateur</th><?php foreach($data['days'] as $day):?><th scope="col"><?=e($day['label'])?><br><small><?=e($day['display'])?></small></th><?php endforeach;?><th scope="col">Total <?= $daysCount ?> j</th><th scope="col">Moyenne / jour</th></tr></thead>
<?php $t=$data['totals']; ?>
<tbody class="daily-group"><?php $group('Total',$t['members'].' joueur'.($t['members']!==1?'s':'').' affiché'.($t['members']!==1?'s':''),$t['days'],$t,true); ?></tbody>
<?php foreach($data['rows'] as $r):?><tbody class="daily-group"><?php $group($r['pseudo'],'',$r['days'],$r,false); ?></tbody><?php endforeach;?>
</table><?php if(!$data['rows']):?><p class="chaine-empty">Aucun joueur pour ces critères.</p><?php endif;?></div></section>