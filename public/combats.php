<?php
declare(strict_types=1);
require '/home/uptep/private/app/bootstrap.php';
require '/home/uptep/private/app/view.php';
require '/home/uptep/private/app/combat-reports.php';
$u=requireUser();
if($u['role']!=='admin'){http_response_code(403);workspaceStart($u,'Accès réservé');echo '<h1>Accès réservé aux administrateurs</h1>';workspaceEnd();exit;}
header('Cache-Control: no-store');
if(($_GET['action']??'')==='players'){
    header('Content-Type: application/json; charset=utf-8');
    $query=is_string($_GET['q']??null)?$_GET['q']:'';
    echo json_encode(combatPlayers($query),JSON_THROW_ON_ERROR);exit;
}
$error='';$raw=post('raw');$location=post('location')?:'auto';
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        if(!validCsrf()||isset($u['_preview']))throw new DomainException('Session expirée ou visualisation en lecture seule.');
        if(post('action')==='analyze'){
            if(time()-($_SESSION['combat_analysis_at']??0)<5)throw new DomainException('Patiente quelques secondes avant une nouvelle analyse.');
            $_SESSION['combat_analysis_at']=time();
            $a=combatAnalyze($raw,$location);
            $_SESSION['combat_draft']=['raw'=>$raw,'analysis'=>$a,'token'=>bin2hex(random_bytes(24)),'expires'=>time()+3600];
        }elseif(post('action')==='save'){
            $draft=$_SESSION['combat_draft']??null;
            if(!$draft||$draft['expires']<time()||!hash_equals($draft['token'],post('draft_token')))throw new DomainException('Analyse expirée. Relance l’analyse.');
            $id=combatSave($u,$draft);unset($_SESSION['combat_draft']);redirect('/combats.php?id='.$id);
        }else throw new DomainException('Action invalide.');
    }catch(DomainException $e){$error=$e->getMessage();http_response_code(422);}
    catch(Throwable $e){error_log('Combat archive: '.$e->getMessage());$error='L’opération a échoué. Aucun rapport n’a été enregistré. Réessaie dans un instant.';http_response_code(500);}
}
$draft=$_SESSION['combat_draft']??null;
if($draft && $draft['expires']<time()){$draft=null;unset($_SESSION['combat_draft']);}
$id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:null;
$report=null;$observations=[];$candidates=[];$totalCandidates=0;
if($id){
    $q=db()->prepare('SELECT * FROM combat_reports WHERE id=?');$q->execute([$id]);$report=$q->fetch(PDO::FETCH_ASSOC);
    if(!$report){http_response_code(404);$error='Rapport introuvable.';}
    else{
        $q=db()->prepare('SELECT * FROM combat_observations WHERE report_id=? ORDER BY side');$q->execute([$id]);$observations=$q->fetchAll(PDO::FETCH_ASSOC);
        $q=db()->prepare('SELECT c.* FROM combat_observation_candidates c JOIN combat_observations o ON o.id=c.attacker_observation_id WHERE o.report_id=? ORDER BY c.id LIMIT 200');$q->execute([$id]);$candidates=$q->fetchAll(PDO::FETCH_ASSOC);
        $q=db()->prepare('SELECT count(*) FROM combat_observation_candidates c JOIN combat_observations o ON o.id=c.attacker_observation_id WHERE o.report_id=?');$q->execute([$id]);$totalCandidates=(int)$q->fetchColumn();
    }
}
workspaceStart($u,'Rapports de combat');
?>
<link rel="stylesheet" href="/assets/combats.css?v=1">
<script src="/assets/combats.js?v=1" defer></script>
<section class="combat-page">
<header><span class="eyebrow">LES ARCHIVES DE LA COLONIE · S5 · ADMIN</span><h1>Rapports de combat</h1><p>Conserver les combats utiles et suivre les armées dans le temps.</p><nav><a href="/combats.php">Tous les rapports</a> · <a href="/combats.php?new=1">Ajouter un rapport</a></nav></header>
<?php if($error):?><p class="combat-error" role="alert"><?=e($error)?></p><?php endif;?>
<?php if($report):?>
<section class="combat-card"><h2>RC #<?=e((string)$report['id'])?> · <?=e(combatDisplayDate($report['combat_at']))?></h2><p>Heure de Paris · <?=e($report['location']??'Lieu inconnu')?> · ajouté le <?=e(combatDisplayDate($report['created_at']))?></p></section>
<div class="combat-grid">
<?php foreach($observations as $o):$provenance=json_decode($o['provenance'],true);?>
<section class="combat-card"><h2><?=$o['side']==='attacker'?'Attaquant':'Défenseur'?><?=$report['target_side']===$o['side']?' · Cible':''?></h2><h3><?=e($o['player_name']??'Joueur non identifié')?></h3><p><?= $o['player_id']!==null?'ID S5 : '.e((string)$o['player_id']):'Sans identifiant associé'?> · relevé du <?=e(combatDisplayDate($o['observed_at']))?></p>
<dl class="combat-stats"><?php foreach(['arms'=>'Armes','shield'=>'Bouclier','dome'=>'Dôme','loge'=>'Loge','attack_base'=>'Attaque hors bonus','attack_bonus'=>'Attaque avec bonus','hp_base'=>'Vie hors bonus','hp_bonus'=>'Vie avec bonus'] as $key=>$label):?><div><dt><?=e($label)?><?=array_key_exists($key,$provenance['manual']??[])?' · manuel':''?></dt><dd><?=e(combatNumber($o[$key]))?></dd></div><?php endforeach;?></dl>
<?php if($o['hp_bonus']===null && $o['hp_min']!==null):?><p>Vie estimée avec bonus : <?=e(combatNumber($o['hp_min']))?> à <?=e(combatNumber($o['hp_max']))?>.</p><?php endif;?>
<h3>Armée au début du combat</h3><table><thead><tr><th>Unité</th><th>Effectif</th></tr></thead><tbody>
<?php $q=db()->prepare('SELECT unit_id,quantity FROM combat_observation_units WHERE observation_id=? ORDER BY CASE unit_id WHEN 14 THEN 7 WHEN 7 THEN 8 WHEN 8 THEN 9 WHEN 9 THEN 10 WHEN 10 THEN 11 WHEN 13 THEN 12 WHEN 11 THEN 13 WHEN 12 THEN 14 ELSE unit_id END');$q->execute([$o['id']]);$names=[1=>'Jeune Soldate Naine',2=>'Soldate Naine',3=>'Naine d’élite',4=>'Jeune Soldate',5=>'Soldate',6=>'Concierge',14=>'Concierge d’élite',7=>'Artilleuse',8=>'Artilleuse d’élite',9=>'Soldate d’élite',10=>'Tank',13=>'Tank d’élite',11=>'Tueuse',12=>'Tueuse d’élite'];foreach($q as $unit):?><tr><td><?=e($names[$unit['unit_id']])?></td><td><?=e(combatNumber($unit['quantity']))?></td></tr><?php endforeach;?></tbody></table></section>
<?php endforeach;?></div>
<section class="combat-card"><h2>Hypothèses issues du RC</h2><p><?=e((string)$totalCandidates)?> combinaisons compatibles dans la recherche de niveaux 0 à 40. Elles décrivent l’analyse initiale, avant toute correction manuelle. Un bâtiment non observé reste inconnu.</p>
<?php if($candidates):?><details><summary>Voir les combinaisons (200 premières au maximum)</summary><div class="combat-scroll"><table><thead><tr><th>Bouclier attaquant</th><th>Bouclier défenseur</th><th>Lieu</th><th>Niveau bâtiment</th></tr></thead><tbody><?php foreach($candidates as $c):?><tr><td><?=e((string)$c['attacker_shield'])?></td><td><?=e((string)$c['defender_shield'])?></td><td><?=e($c['location'])?></td><td><?=$c['location']==='terrain'?'Sans objet':e((string)$c['building_level'])?></td></tr><?php endforeach;?></tbody></table></div></details><?php else:?><p>Aucune combinaison trouvée : les niveaux non déduits restent inconnus.</p><?php endif;?></section>
<section class="combat-card"><h2>Rapport original</h2><pre><?=e($report['raw_text'])?></pre></section>
<?php elseif(isset($_GET['new']) || $_SERVER['REQUEST_METHOD']==='POST'):?>
<section class="combat-card"><h2>1. Analyser le rapport</h2><form method="post" action="/combats.php?new=1"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="analyze"><label for="raw">Rapport complet, avec son en-tête si disponible</label><textarea id="raw" name="raw" required maxlength="100000" rows="12"><?=e($raw?:($draft['raw']??''))?></textarea><label for="location">Lieu du combat</label><select id="location" name="location"><?php foreach(['auto'=>'Détection automatique','terrain'=>'Terrain de chasse','dome'=>'Dôme','loge'=>'Loge impériale'] as $value=>$label):?><option value="<?=e($value)?>" <?=$location===$value?'selected':''?>><?=e($label)?></option><?php endforeach;?></select><button type="submit">Analyser le RC</button></form></section>
<?php if($draft):$a=$draft['analysis'];$resub=post('action')==='save';?>
<section class="combat-card"><h2>2. Vérifier et enregistrer</h2><p><?=count($a['candidates'])?> combinaisons possibles. Les effectifs sont ceux du début du combat. Vérifie la date et sélectionne la cible dans les suggestions S5. Les champs de niveau vides conservent le résultat calculé ; une valeur saisie sera marquée comme manuelle.</p><form method="post" action="/combats.php?new=1"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="save"><input type="hidden" name="draft_token" value="<?=e($draft['token'])?>">
<label for="combat-at">Date et heure du combat · heure de Paris<?=$a['date']?' · détectées, à vérifier':' · à renseigner'?></label><input id="combat-at" type="datetime-local" name="combat_at" required value="<?=e($resub?post('combat_at'):($a['date']??''))?>"><label for="target-side">Camp de la cible</label><select name="target_side" id="target-side"><?php $target=$resub?post('target_side'):($a['view']==='att'?'defender':'attacker');foreach(['attacker'=>'Attaquant','defender'=>'Défenseur'] as $side=>$label):?><option value="<?=e($side)?>" <?=$target===$side?'selected':''?>><?=e($label)?></option><?php endforeach;?></select>
<div class="combat-grid"><?php foreach(['attacker'=>'Attaquant','defender'=>'Défenseur'] as $side=>$label):$s=$a['sides'][$side==='attacker'?0:1];?>
<fieldset><legend><?=e($label)?></legend><div data-player-picker><label for="<?=$side?>-name">Joueur S5 (pseudo ou ID)</label><input id="<?=$side?>-name" name="<?=$side?>_name" data-player-query autocomplete="off" maxlength="255" value="<?=e($resub?post($side.'_name'):($a['names'][$side]??''))?>"><input type="hidden" name="<?=$side?>_id" data-player-id value="<?=e($resub?post($side.'_id'):'')?>"><div data-player-results class="combat-suggestions" aria-live="polite"></div><small data-player-status>Choisir une suggestion pour associer l’identifiant.</small></div>
<dl class="combat-stats"><?php foreach(['arms'=>'Armes','shield'=>'Bouclier','dome'=>'Dôme','loge'=>'Loge'] as $key=>$title):?><div><dt><label for="<?=$side.'-'.$key?>"><?=e($title)?> · calcul : <?=e(combatNumber($s[$key]))?></label></dt><dd><input id="<?=$side.'-'.$key?>" name="<?=$side.'_'.$key?>" type="number" min="0" max="100" placeholder="Correction facultative" value="<?=e($resub?post($side.'_'.$key):'')?>"></dd></div><?php endforeach;?></dl><p>Attaque hors bonus : <?=e(combatNumber($s['attack_base']))?><br>Vie hors bonus : <?=e(combatNumber($s['hp_base']))?></p><details><summary>Vérifier les 14 unités</summary><ul><?php foreach($a['units'] as $j=>$unit):?><li><?=e($unit['name'])?> : <?=e(combatNumber($s['army'][$j]))?></li><?php endforeach;?></ul></details></fieldset><?php endforeach;?></div><p>Le second joueur peut rester non identifié. Aucun niveau inconnu n’est remplacé par zéro.</p><button type="submit">Enregistrer le RC et les deux relevés</button></form></section><?php endif;?>
<?php else:
$filter=is_string($_GET['player']??null)?trim($_GET['player']):'';$from=is_string($_GET['from']??null)?$_GET['from']:'';$to=is_string($_GET['to']??null)?$_GET['to']:'';
$where=[];$params=[];
if($filter!==''){$where[]="EXISTS (SELECT 1 FROM combat_observations o WHERE o.report_id=r.id AND (o.player_name ILIKE ? ESCAPE '!' OR o.player_id::text=?))";$params[]='%'.str_replace(['!','%','_'],['!!','!%','!_'],mb_substr($filter,0,255)).'%';$params[]=$filter;}
foreach([[$from,'>='],[$to,'<']] as [$value,$op]){if($value==='')continue;$d=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('Europe/Paris'));if($d&&$d->format('Y-m-d')===$value){$where[]='r.combat_at '.$op.' ?';$params[]=($op==='<'?$d->modify('+1 day'):$d)->format(DateTimeInterface::ATOM);}}
$page=max(1,min(100000,(int)($_GET['page']??1)));$sql='SELECT r.*,a.player_name attacker_name,d.player_name defender_name FROM combat_reports r LEFT JOIN combat_observations a ON a.report_id=r.id AND a.side=\'attacker\' LEFT JOIN combat_observations d ON d.report_id=r.id AND d.side=\'defender\''.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY r.combat_at DESC,r.id DESC LIMIT 26 OFFSET '.(($page-1)*25);$q=db()->prepare($sql);$q->execute($params);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$more=count($rows)>25;$rows=array_slice($rows,0,25);
?>
<section class="combat-card"><form method="get" class="combat-filters"><label>Joueur ou ID S5<input name="player" value="<?=e($filter)?>" maxlength="255"></label><label>Depuis le<input type="date" name="from" value="<?=e($from)?>"></label><label>Jusqu’au<input type="date" name="to" value="<?=e($to)?>"></label><button type="submit">Filtrer</button></form><div class="combat-scroll"><table><thead><tr><th>Date (Paris)</th><th>Attaquant</th><th>Défenseur</th><th>Lieu</th><th>Rapport</th></tr></thead><tbody><?php foreach($rows as $row):?><tr><td><?=e(combatDisplayDate($row['combat_at']))?></td><td><?=e($row['attacker_name']??'Inconnu')?></td><td><?=e($row['defender_name']??'Inconnu')?></td><td><?=e($row['location']??'Inconnu')?></td><td><a href="/combats.php?id=<?=e((string)$row['id'])?>">RC #<?=e((string)$row['id'])?></a></td></tr><?php endforeach;?></tbody></table></div><?php if(!$rows):?><p>Aucun rapport pour ces critères.</p><?php endif;?><nav aria-label="Pages"><?php foreach([$page-1=>'Précédent',$page+1=>'Suivant'] as $p=>$label):if($p<1||($p>$page&&!$more))continue;?><a href="?<?=e(http_build_query(['page'=>$p,'player'=>$filter,'from'=>$from,'to'=>$to]))?>"><?=e($label)?></a> <?php endforeach;?></nav></section>
<?php endif;?></section><?php workspaceEnd(); ?>
