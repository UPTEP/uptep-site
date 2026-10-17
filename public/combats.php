<?php
declare(strict_types=1);
require '/home/uptep/private/app/bootstrap.php';
require '/home/uptep/private/app/view.php';
require '/home/uptep/private/app/combat-reports.php';
require '/home/uptep/private/app/combat-tdp.php';
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
            $id=combatSave($u,$draft);unset($_SESSION['combat_draft']);$q=db()->prepare('SELECT player_id FROM combat_observations WHERE report_id=?');$q->execute([$id]);redirect('/combats.php?joueur='.$q->fetchColumn());
        }elseif(post('action')==='tdp_compare'){
            unset($_SESSION['tdp_draft'],$_SESSION['tdp_comparison']);
            if(time()-($_SESSION['combat_analysis_at']??0)<5)throw new DomainException('Patiente quelques secondes avant une nouvelle analyse.');
            $_SESSION['combat_analysis_at']=time();
            $pair=combatTdpPair(post('first'),post('second'),$u);
            $_SESSION['tdp_comparison']=['first'=>post('first'),'second'=>post('second'),'player_id'=>$pair['player_id'],'actor'=>$u['id'],'elapsed'=>$pair['elapsed'],'rows'=>combatTdpCompare($pair),'expires'=>time()+3600];
            redirect('/combats.php?joueur='.$pair['player_id'].'#tdp');
        }elseif(post('action')==='tdp_prepare'){
            unset($_SESSION['tdp_draft']);
            $comparison=$_SESSION['tdp_comparison']??null;
            if(!$comparison||$comparison['expires']<time()||(string)$comparison['actor']!==(string)$u['id'])throw new DomainException('Comparaison expirée. Sélectionne à nouveau les sondes.');
            $pair=combatTdpPair($comparison['first'],$comparison['second'],$u);
            $calc=combatTdpCalculation(is_array($_POST['laid']??null)?$_POST['laid']:[],$pair['elapsed']);
            $_SESSION['tdp_draft']=['first'=>$comparison['first'],'second'=>$comparison['second'],'actor'=>$u['id'],'player_id'=>$pair['player_id'],'comparison'=>$comparison['rows'],'calculation'=>$calc,'token'=>bin2hex(random_bytes(24)),'expires'=>time()+3600];
            redirect('/combats.php?joueur='.$pair['player_id'].'#tdp');
        }elseif(post('action')==='tdp_confirm'){
            $player=combatTdpConfirm($u,$_SESSION['tdp_draft']??[],post('tdp_token'),post('total'),post('verified')==='1');
            unset($_SESSION['tdp_draft'],$_SESSION['tdp_comparison']);redirect('/combats.php?joueur='.$player.'&tdp_saved=1#tdp');
        }else throw new DomainException('Action invalide.');
    }catch(DomainException $e){$error=$e->getMessage();http_response_code(422);}
    catch(Throwable $e){error_log('Combat archive: '.$e->getMessage());$error='L’opération a échoué. Aucune modification n’a été enregistrée. Réessaie dans un instant.';http_response_code(500);}
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
        $q=db()->prepare('SELECT c.* FROM combat_observation_candidates c JOIN combat_observations o ON o.id=c.observation_id WHERE o.report_id=? ORDER BY c.id LIMIT 200');$q->execute([$id]);$candidates=$q->fetchAll(PDO::FETCH_ASSOC);
        $q=db()->prepare('SELECT count(*) FROM combat_observation_candidates c JOIN combat_observations o ON o.id=c.observation_id WHERE o.report_id=?');$q->execute([$id]);$totalCandidates=(int)$q->fetchColumn();
    }
}
$profile=null;$playerId=is_string($_GET['joueur']??null)?$_GET['joueur']:'';
$historyPage=max(1,min(100000,(int)($_GET['page']??1)));
$filter=is_string($_GET['player']??null)?trim($_GET['player']):'';$rows=[];$more=false;
if($playerId!==''){
    try{$profile=combatProfile($playerId,$historyPage);if(!$profile){http_response_code(404);$error='Aucun renseignement enregistré pour ce joueur.';}}
    catch(DomainException $e){http_response_code(422);$error=$e->getMessage();}
}elseif(!$id&&!isset($_GET['new'])&&$_SERVER['REQUEST_METHOD']!=='POST'){
    $rows=combatTrackedPlayers($filter,$historyPage);
    if($filter!==''&&$historyPage===1&&count($rows)===1)redirect('/combats.php?joueur='.$rows[0]['player_id']);
    $more=count($rows)>25;$rows=array_slice($rows,0,25);
}
$tdpValues=combatTdpLatest($profile?[$playerId]:array_column($rows,'player_id'));
workspaceStart($u,'Renseignements');
?>
<link rel="stylesheet" href="/assets/combats.css?v=2">
<script src="/assets/combats.js?v=2" defer></script>
<section class="combat-page">
<header><span class="eyebrow">RENSEIGNEMENTS DE LA COLONIE · S5 · ADMIN</span><h1>Renseignements joueurs</h1><p>Retrouver les armées et niveaux connus de chaque joueur, avec leur historique et leurs sources.</p><nav><a href="/combats.php">Rechercher un joueur</a> · <a href="/combats.php?new=1">Ajouter un relevé</a></nav></header>
<?php if($error):?><p class="combat-error" role="alert"><?=e($error)?></p><?php endif;?>
<?php if($profile):require '/home/uptep/private/app/combat-profile-view.php'; ?>
<?php elseif($report):?>
<section class="combat-card"><h2>RC #<?=e((string)$report['id'])?> · <?=e(combatDisplayDate($report['combat_at']))?></h2><p>Heure de Paris · <?=e($report['location']??'Lieu inconnu')?> · ajouté le <?=e(combatDisplayDate($report['created_at']))?></p></section>
<div>
<?php foreach($observations as $o):$provenance=json_decode($o['provenance'],true);$o+=combatLifeValues($o+['location'=>$report['location']]);?>
<section class="combat-card"><h2><?=$o['side']==='attacker'?'Attaquant':'Défenseur'?><?=$report['target_side']===$o['side']?' · Cible':''?></h2><h3><a href="/combats.php?joueur=<?=e((string)$o['player_id'])?>"><?=e($o['player_name']??'Joueur non identifié')?></a></h3><p><a href="/combats.php?joueur=<?=e((string)$o['player_id'])?>">← Revenir à la fiche du joueur</a></p><p><?= $o['player_id']!==null?'ID S5 : '.e((string)$o['player_id']):'Sans identifiant associé'?> · relevé du <?=e(combatDisplayDate($o['observed_at']))?></p>
<dl class="combat-stats"><?php foreach(['arms'=>'Armes','shield'=>'Bouclier','dome'=>'Dôme','loge'=>'Loge','attack_base'=>'Attaque hors bonus','attack_bonus'=>'Attaque avec bonus','hp_base'=>'Vie hors bonus','hp_terrain'=>'Vie AB (terrain)','hp_dome'=>'Vie AB (dôme)','hp_loge'=>'Vie AB (loge)'] as $key=>$label):?><div><dt><?=e($label)?><?=array_key_exists($key,$provenance['manual']??[])?' · manuel':''?></dt><dd><?=e(combatNumber($o[$key]))?></dd></div><?php endforeach;?></dl>
<?php if($o['hp_bonus']===null && $o['hp_min']!==null):?><p>Vie estimée avec bonus dans ce combat (<?=e($report['location']??'lieu inconnu')?>) : <?=e(combatNumber($o['hp_min']))?> à <?=e(combatNumber($o['hp_max']))?>.</p><?php endif;?>
<h3>Armée au début du combat</h3><table><thead><tr><th>Unité</th><th>Effectif</th></tr></thead><tbody>
<?php $q=db()->prepare('SELECT unit_id,quantity FROM combat_observation_units WHERE observation_id=? ORDER BY CASE unit_id WHEN 14 THEN 7 WHEN 7 THEN 8 WHEN 8 THEN 9 WHEN 9 THEN 10 WHEN 10 THEN 11 WHEN 13 THEN 12 WHEN 11 THEN 13 WHEN 12 THEN 14 ELSE unit_id END');$q->execute([$o['id']]);$names=[1=>'Jeune Soldate Naine',2=>'Soldate Naine',3=>'Naine d’élite',4=>'Jeune Soldate',5=>'Soldate',6=>'Concierge',14=>'Concierge d’élite',7=>'Artilleuse',8=>'Artilleuse d’élite',9=>'Soldate d’élite',10=>'Tank',13=>'Tank d’élite',11=>'Tueuse',12=>'Tueuse d’élite'];foreach($q as $unit):?><tr><td><?=e($names[$unit['unit_id']])?></td><td><?=e(combatNumber($unit['quantity']))?></td></tr><?php endforeach;?></tbody></table></section>
<?php endforeach;?></div>
<section class="combat-card"><h2>Hypothèses issues du RC</h2><p><?=e((string)$totalCandidates)?> combinaisons compatibles dans la recherche de niveaux 0 à 40. Elles décrivent l’analyse initiale, avant toute correction manuelle. Un bâtiment non observé reste inconnu.</p>
<?php if($candidates):?><details><summary>Voir les combinaisons (200 premières au maximum)</summary><div class="combat-scroll"><table><thead><tr><th>Bouclier du joueur</th><th>Lieu</th><th>Niveau bâtiment</th></tr></thead><tbody><?php foreach($candidates as $c):?><tr><td><?=e((string)$c['shield'])?></td><td><?=e($c['location'])?></td><td><?=$c['building_level']===null?'Non observé':e((string)$c['building_level'])?></td></tr><?php endforeach;?></tbody></table></div></details><?php else:?><p>Aucune combinaison trouvée : les niveaux non déduits restent inconnus.</p><?php endif;?></section>
<section class="combat-card"><details><summary>Consulter le RC original (source du relevé)</summary><pre><?=e($report['raw_text'])?></pre></details></section>
<?php elseif(isset($_GET['new']) || $_SERVER['REQUEST_METHOD']==='POST'):?>
<section class="combat-card"><h2>1. Analyser le rapport</h2><form method="post" action="/combats.php?new=1"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="analyze"><label for="raw">Rapport complet, avec son en-tête si disponible</label><textarea id="raw" name="raw" required maxlength="100000" rows="12"><?=e($raw?:($draft['raw']??''))?></textarea><label for="location">Lieu du combat</label><select id="location" name="location"><?php foreach(['auto'=>'Détection automatique','terrain'=>'Terrain de chasse','dome'=>'Dôme','loge'=>'Loge impériale'] as $value=>$label):?><option value="<?=e($value)?>" <?=$location===$value?'selected':''?>><?=e($label)?></option><?php endforeach;?></select><button type="submit">Analyser le RC</button></form></section>
<?php if($draft):$a=$draft['analysis'];$resub=post('action')==='save';?>
<section class="combat-card"><h2>2. Choisir le joueur à enregistrer</h2><p><?=count($a['candidates'])?> combinaisons possibles. Les effectifs sont ceux du début du combat. Choisis un seul camp, vérifie la date et sélectionne son joueur dans les suggestions S5. Les champs de niveau vides conservent le résultat calculé ; une valeur saisie sera marquée comme manuelle.</p><form method="post" action="/combats.php?new=1"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="save"><input type="hidden" name="draft_token" value="<?=e($draft['token'])?>">
<label for="combat-at">Date et heure du combat · heure de Paris<?=$a['date']?' · détectées, à vérifier':' · à renseigner'?></label><input id="combat-at" type="datetime-local" name="combat_at" required value="<?=e($resub?post('combat_at'):($a['date']??''))?>"><label for="target-side">Joueur à suivre : attaquant ou défenseur</label><select name="target_side" id="target-side"><?php $target=$resub?post('target_side'):($a['view']==='att'?'defender':'attacker');foreach(['attacker'=>'Attaquant','defender'=>'Défenseur'] as $side=>$label):?><option value="<?=e($side)?>" <?=$target===$side?'selected':''?>><?=e($label)?></option><?php endforeach;?></select>
<div><?php foreach(['attacker'=>'Attaquant','defender'=>'Défenseur'] as $side=>$label):$s=$a['sides'][$side==='attacker'?0:1];?>
<fieldset data-combat-side="<?=e($side)?>"><legend><?=e($label)?></legend><div data-player-picker><label for="<?=$side?>-name">Joueur S5 (pseudo ou ID)</label><input id="<?=$side?>-name" name="<?=$side?>_name" data-player-query autocomplete="off" maxlength="255" value="<?=e($resub?post($side.'_name'):($a['names'][$side]??''))?>"><input type="hidden" name="<?=$side?>_id" data-player-id value="<?=e($resub?post($side.'_id'):'')?>"><div data-player-results class="combat-suggestions" aria-live="polite"></div><small data-player-status>Choisir une suggestion pour associer l’identifiant.</small></div>
<dl class="combat-stats"><?php foreach(['arms'=>'Armes','shield'=>'Bouclier','dome'=>'Dôme','loge'=>'Loge'] as $key=>$title):?><div><dt><label for="<?=$side.'-'.$key?>"><?=e($title)?> · calcul : <?=e(combatNumber($s[$key]))?></label></dt><dd><input id="<?=$side.'-'.$key?>" name="<?=$side.'_'.$key?>" type="number" min="0" max="100" placeholder="Correction facultative" value="<?=e($resub?post($side.'_'.$key):'')?>"></dd></div><?php endforeach;?></dl><p>Attaque hors bonus : <?=e(combatNumber($s['attack_base']))?><br>Vie hors bonus : <?=e(combatNumber($s['hp_base']))?></p><details><summary>Vérifier les 14 unités</summary><ul><?php foreach($a['units'] as $j=>$unit):?><li><?=e($unit['name'])?> : <?=e(combatNumber($s['army'][$j]))?></li><?php endforeach;?></ul></details></fieldset><?php endforeach;?></div><p>Seul le camp sélectionné sera enregistré. Le RC complet est conservé comme source ; les niveaux inconnus restent inconnus.</p><button type="submit">Enregistrer le relevé de ce joueur</button></form></section><?php endif;?>
<?php else: ?>
<section class="combat-card"><h2>Rechercher un joueur</h2><form method="get" class="combat-filters combat-player-search"><label>Pseudo ou ID S5<input name="player" value="<?=e($filter)?>" maxlength="255" placeholder="Pseudo du joueur"></label><button type="submit">Voir les renseignements</button></form>
<p>HB : hors bonus · AB : avec bonus. Vie et attaque calculées sur la dernière armée observée, avec les derniers niveaux connus. Les dates des niveaux sont indiquées dans leurs colonnes. Vie AB selon le lieu de défense : terrain, dôme ou loge ; un niveau inconnu n’est pas assimilé à zéro.</p><div class="combat-scroll"><table><thead><tr><th>Joueur</th><th>Armes</th><th>Bouclier</th><th>Dôme</th><th>Loge</th><th>Vie HB</th><th>Attaque HB</th><th>Vie AB (terrain)</th><th>Vie AB (dôme)</th><th>Vie AB (loge)</th><th>Attaque AB</th><th>TDP</th><th>Dernier relevé</th><th>Nombre de relevés</th><th>Renseignements</th></tr></thead><tbody><?php foreach($rows as $row):?><tr><td><a href="/combats.php?joueur=<?=e((string)$row['player_id'])?>"><?=e($row['player_name'])?></a><br><small>ID S5 : <?=e((string)$row['player_id'])?></small></td><?php foreach(['arms','shield','dome','loge','hp_base','attack_base','hp_terrain','hp_dome','hp_loge','attack_bonus'] as $key):$value=$row['known'][$key]??null;?><td><strong><?=e(combatNumber($value['value']??null))?></strong><?php if($value):?><br><small><a href="/combats.php?id=<?=e((string)$value['report_id'])?>" title="<?=isset($value['levels'])?'Armée de référence ; niveaux : voir les colonnes correspondantes':'Consulter le relevé source'?>"><?=e(combatDisplayDate($value['observed_at']))?></a></small><?php endif;?></td><?php endforeach;?><?php $tdp=$tdpValues[$row['player_id']]??null;?><td><strong><?=e(combatNumber($tdp['total']??null))?></strong><?php if($tdp):?><br><small>Confirmé · <?=e(combatDisplayDate($tdp['observed_at']))?><br>par <?=e($tdp['author']??'Utilisateur supprimé')?></small><?php endif;?></td><td><?=e(combatDisplayDate($row['observed_at']))?></td><td><?=e((string)$row['observations'])?></td><td><a href="/combats.php?joueur=<?=e((string)$row['player_id'])?>">Armée, niveaux et historique</a></td></tr><?php endforeach;?></tbody></table></div>
<?php if(!$rows):?><p>Aucun joueur enregistré pour ces critères. <a href="/combats.php?new=1">Ajouter un premier relevé à partir d’un RC</a>.</p><?php endif;?>
<nav aria-label="Joueurs"><?php foreach([$historyPage-1=>'Précédent',$historyPage+1=>'Suivant'] as $p=>$label):if($p<1||($p>$historyPage&&!$more))continue;?><a href="?<?=e(http_build_query(['page'=>$p,'player'=>$filter]))?>"><?=e($label)?></a><?php endforeach;?></nav></section>
<?php endif;?></section><?php workspaceEnd(); ?>
