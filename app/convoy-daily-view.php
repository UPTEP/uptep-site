<?php declare(strict_types=1); ?>
<link rel="stylesheet" href="/assets/convoy-daily.css?v=3">
<section class="convoy-daily" aria-labelledby="daily-title">
<h2 id="daily-title">Statistiques sur 7 jours</h2>
<form method="get" class="convoy-filters"><input type="hidden" name="vue" value="statistiques">
<label>Ressource<select name="ressource"><?php foreach(['pommes'=>'Pommes','materiaux'=>'Matériaux'] as $value=>$label):?><option value="<?=e($value)?>" <?=$data['resource']===$value?'selected':''?>><?=e($label)?></option><?php endforeach;?></select></label>
<label>Mouvements<select name="sens"><?php foreach(['envois'=>'Livraisons envoyées','receptions'=>'Réceptions'] as $value=>$label):?><option value="<?=e($value)?>" <?=$data['direction']===$value?'selected':''?>><?=e($label)?></option><?php endforeach;?></select></label>
<label>Pseudo<input name="joueur" maxlength="100" value="<?=e($data['player'])?>" placeholder="Tous les joueurs"></label><button class="save-user" type="submit">Afficher</button></form>
<p>Du <?=e($data['start'])?> au <?=e($data['end'])?> · heure de Paris. <strong>Aujourd’hui est une journée en cours.</strong></p>
<p class="daily-note">Les envois et réceptions déclarés sont regroupés par date de départ du convoi. Seuls les convois non annulés contenant la ressource choisie sont comptés. La moyenne par jour est le total divisé par 7, jours sans convoi inclus.</p>
<p class="daily-note">TdC : moyenne des relevés disponibles pour le joueur ce jour-là ; leur nombre est indiqué sous la valeur. « — » signifie qu’aucun relevé n’est disponible.</p>
<div class="users-panel users-scroll daily-scroll" tabindex="0" role="region" aria-label="Statistiques quotidiennes, tableau défilant horizontalement">
<table class="users-table daily-table"><caption class="daily-caption"><?= $data['resource']==='pommes'?'Pommes':'Matériaux'?> · <?=$data['direction']==='envois'?'Livraisons envoyées':'Réceptions'?></caption>
<thead><tr><th scope="col">Pseudo</th><?php foreach($data['days'] as $day):?><th scope="col"><?=e($day['label'])?><br><small><?=e($day['display'])?></small></th><?php endforeach;?><th scope="col">Bilan sur 7 jours</th></tr></thead>
<tbody>
<?php $t=$data['totals']; ?>
<tr class="daily-grand-total"><th scope="row">Total<small><?=$t['members']?> joueur<?=$t['members']!==1?'s':''?> affiché<?=$t['members']!==1?'s':''?></small></th>
<?php foreach($data['days'] as $day):$v=$t['days'][$day['date']];?><td>
<div class="daily-metric"><span>TdC moyen cumulé</span><strong><?=e(convoyStatNumber($v['tdc']))?></strong><small><?=$v['covered']?> / <?=$t['members']?> joueurs avec relevés<?=$v['covered']<$t['members']?' · partiel':''?></small></div>
<div class="daily-metric"><span><?=$data['direction']==='envois'?'Envois':'Réceptions'?></span><strong><?=e(convoyStatNumber($v['count']))?></strong></div>
<div class="daily-metric"><span><?=$data['direction']==='envois'?'Total livré':'Total reçu'?></span><strong><?=e(convoyStatNumber($v['quantity']))?></strong></div></td><?php endforeach;?>
<td class="daily-total"><div class="daily-metric"><span>Total des convois</span><strong><?=e(convoyStatNumber($t['total_count']))?></strong></div><div class="daily-metric"><span>Quantité totale</span><strong><?=e(convoyStatNumber($t['total_quantity']))?></strong></div><div class="daily-metric"><span>Moyenne / jour</span><strong><?=e(convoyStatNumber($t['daily_average']))?></strong></div></td></tr>
<?php foreach($data['rows'] as $r):?><tr><th scope="row"><?=e($r['pseudo'])?></th><?php foreach($data['days'] as $day):$v=$r['days'][$day['date']];?><td><div class="daily-metric"><span>TdC moyen</span><strong><?=e(convoyStatNumber($v['tdc']))?></strong><small><?=$v['samples']?> relevé<?=$v['samples']!==1?'s':''?></small></div><div class="daily-metric"><span><?=$data['direction']==='envois'?'Envois':'Réceptions'?></span><strong><?=e(convoyStatNumber($v['count']))?></strong></div><div class="daily-metric"><span><?=$data['direction']==='envois'?'Total livré':'Total reçu'?></span><strong><?=e(convoyStatNumber($v['quantity']))?></strong></div></td><?php endforeach;?><td class="daily-total"><div class="daily-metric"><span>Total des convois</span><strong><?=e(convoyStatNumber($r['total_count']))?></strong></div><div class="daily-metric"><span>Quantité totale</span><strong><?=e(convoyStatNumber($r['total_quantity']))?></strong></div><div class="daily-metric"><span>Moyenne / jour</span><strong><?=e(convoyStatNumber($r['daily_average']))?></strong></div></td></tr><?php endforeach;?></tbody>
</table><?php if(!$data['rows']):?><p class="chaine-empty">Aucun joueur pour ces critères.</p><?php endif;?></div></section>
