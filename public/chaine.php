<?php
declare(strict_types=1);
require '/home/uptep/private/app/bootstrap.php';
require '/home/uptep/private/app/view.php';
require '/home/uptep/private/app/chaine.php';
require '/home/uptep/private/app/chaine-focus.php';
$u=requireUser();$canEdit=(bool)$u['is_chaine'];$error='';$unavailable=false;
$success=$_SESSION['chaine_saved']??false;unset($_SESSION['chaine_saved']);
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!$canEdit) {http_response_code(403);$error='Le droit chaine est nécessaire pour modifier ce tableau.';}
    elseif (!validCsrf()) {http_response_code(403);$error='Le formulaire a expiré. Recharge la page avant de réessayer.';}
    elseif(post('action')==='delete_departed') {
        try {
            $id=filter_var(post('player_id'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            $version=filter_var(post('version'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if($id===false || $version===false)throw new DomainException('Ligne invalide.',422);
            deleteDepartedChaine($u,$id,$version);
            $_SESSION['chaine_saved']='deleted';redirect('/chaine.php');
        } catch(DomainException $ex){http_response_code($ex->getCode());$error=$ex->getMessage();}
        catch(Throwable $ex){error_log('UPTEP chaine delete: '.$ex->getMessage());http_response_code(503);$error='Suppression impossible pour le moment. Réessaie dans quelques instants.';}
    }
    else {
        $id=filter_var(post('player_id'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $version=filter_var(post('version'),FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
        $order=post('sort_order')===''?null:filter_var(post('sort_order'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>999999]]);
        if ($id===false || $version===false || $order===false || !isset($_POST['grade'],$_POST['sort_order']) || !is_string($_POST['grade']) || !is_string($_POST['sort_order'])) {http_response_code(422);$error='Choisis un grade et un ordre entier entre 1 et 999 999, ou laisse l’ordre vide.';}
        else try {
            saveChaine($u,$id,$version,post('grade'),$order);
            $_SESSION['chaine_saved']=true;redirect('/chaine.php');
        } catch(DomainException $ex) {http_response_code($ex->getCode());$error=$ex->getMessage();}
        catch(Throwable $ex) {error_log('UPTEP chaine save: '.$ex->getMessage());http_response_code(503);$error='Enregistrement impossible pour le moment. Réessaie dans quelques instants.';}
    }
}
$players=[];$departed=[];$latest='';$movements=[];$focus=['self'=>null,'targets'=>[],'reason'=>'unlinked'];
try {
    $players=chainePlayers();
    $q=db()->prepare('SELECT player_id,pseudo,grade,sort_order,version FROM chaine_members WHERE server=?');$q->execute([CHAINE_SERVER]);$assignments=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$assignments[(string)$row['player_id']]=$row;
    $present=array_fill_keys(array_map(static fn($p)=>(string)$p['player_id'],$players),true);
    foreach($assignments as $id=>$saved)if(!isset($present[$id]))$departed[]=$saved;
    usort($departed,static fn($a,$b)=>strnatcasecmp($a['pseudo']??'', $b['pseudo']??''));
    foreach($players as &$player) {
        $saved=$assignments[(string)$player['player_id']]??[];
        $player['chaine_grade']=$saved['grade']??null;$player['sort_order']=$saved['sort_order']??null;$player['version']=$saved['version']??0;
        if($player['export_version']>$latest)$latest=$player['export_version'];
    } unset($player);
    $movements=chaineMovements($players);
    $focus=chaineFocus($players,$u['game_player_id']===null?null:(int)$u['game_player_id']);
    usort($players,static function(array $a,array $b):int {
        $ga=array_search($a['chaine_grade'],CHAINE_GRADES,true);$gb=array_search($b['chaine_grade'],CHAINE_GRADES,true);
        return (($ga===false?99:$ga)<=>($gb===false?99:$gb)) ?: chaineOrderCompare($a,$b) ?: strnatcasecmp($a['pseudo'],$b['pseudo']) ?: ((int)$a['player_id']<=>(int)$b['player_id']);
    });
} catch(Throwable $ex) {error_log('UPTEP chaine read: '.$ex->getMessage());http_response_code(503);$unavailable=true;}
$groups=['main'=>[], 'outside'=>[]];
foreach($players as $player)$groups[$player['chaine_grade']==='Hors chaîne'?'outside':'main'][]=$player;
workspaceStart($u,'chaine');
?>
<div class="chaine-page"><h1>chaine</h1><p class="admin-intro">Les membres UPTEP et leur place dans la chaîne.</p>
<?php if($error): ?><div class="form-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if($success): ?><div class="admin-success" role="status"><?= $success==='deleted'?'Le membre a été supprimé de la base chaîne.':'Le grade et l’ordre ont été enregistrés.' ?></div><?php endif; ?>
<?php if($unavailable): ?><div class="form-error" role="alert">Les dernières informations du jeu sont momentanément indisponibles. Réessaie dans quelques instants.</div>
<?php else: ?>
<div class="chaine-personal-note">
<?php if($focus['reason']==='unlinked'): ?><a href="/compte.php">Renseigne ton ID en jeu dans Mon compte</a> pour afficher ta position et les cibles à portée.
<?php elseif($focus['reason']==='absent'): ?>Ton joueur n’apparaît pas dans le relevé UPTEP actuel. <a href="/compte.php">Vérifier mon ID</a>.
<?php else: ?><span class="personal-key key-self">Votre position</span><span class="personal-key key-target">Cibles à portée sous vous : <?= count($focus['targets']) ?></span><?php if($focus['reason']==='unranked'): ?> Un grade Grenier, Passeur ou Chasseur est nécessaire pour calculer tes cibles.<?php elseif($focus['reason']==='unavailable'): ?> Cibles indisponibles avec ton état actuel.<?php endif; ?>
<?php endif; ?></div>
<?php ob_start(); ?><?php // Alliance map: one point per member at its in-game coordinates, filtered in the browser.
$mapPlayers=[];
foreach($players as $player){
    if($player['x']===null||$player['y']===null)continue;
    $mapPlayers[]=['id'=>(int)$player['player_id'],'pseudo'=>$player['pseudo'],'x'=>(int)$player['x'],'y'=>(int)$player['y'],'field'=>$player['field']===null?null:(int)$player['field'],
        'grade'=>$player['chaine_grade'],'order'=>$player['sort_order']===null?null:(int)$player['sort_order'],
        'self'=>$focus['self']===(int)$player['player_id']];
} ?>
<link rel="stylesheet" href="/assets/chaine-map.css?v=8"><script src="/assets/chaine-map.js?v=6" defer></script>
<section class="users-panel chaine-map-panel" aria-labelledby="chaine-map-title"><div class="users-panel-heading"><h2 id="chaine-map-title">Carte de l’alliance</h2><span class="user-count" data-map-count><?= count($mapPlayers) ?> membres</span></div>
<?php if(!$mapPlayers): ?><p class="chaine-empty">Aucun membre avec des coordonnées dans le dernier relevé.</p><?php else: ?>
<script type="application/json" id="chaine-map-data"><?= json_encode(['players'=>$mapPlayers,'grades'=>CHAINE_GRADES],JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) ?></script>
<div class="chaine-map" data-chaine-map><form class="chaine-map-filters" data-map-filters aria-label="Filtres de la carte">
<fieldset><legend>Grade de chaîne</legend><div class="chaine-map-chips" data-map-options="grade"></div></fieldset>
<fieldset><legend>Affichage</legend><div class="chaine-map-display"><label class="chaine-map-select"><span class="sr-only">Étiquettes</span><select name="labels"><option value="grade">Étiquettes : pseudo + grade</option><option value="pseudo">Étiquettes : pseudo</option><option value="none">Sans étiquettes</option></select></label><label class="chaine-map-toggle"><input type="checkbox" name="sized"> Taille selon le TDC</label></div></fieldset>
<button type="reset" class="chaine-map-reset">Réinitialiser</button>
</form>
<div class="chaine-map-view"><div class="chaine-map-toolbar"><button type="button" data-map-zoom="in" aria-label="Zoom avant">+</button><button type="button" data-map-zoom="out" aria-label="Zoom arrière">−</button><button type="button" data-map-zoom="fit" aria-label="Recentrer sur les membres affichés">↺</button><button type="button" class="chaine-map-full" data-map-fullscreen aria-pressed="false">Plein écran</button><span class="chaine-map-hint">Molette ou +/− pour zoomer, glisser pour déplacer, survoler un membre pour ses infos.</span></div>
<div class="chaine-map-surface" data-map-surface><svg data-map-svg role="img" aria-labelledby="chaine-map-title" tabindex="0"></svg><div class="chaine-map-labels" data-map-labels aria-hidden="true"></div><div class="chaine-map-tip" data-map-tip hidden></div></div>
<div class="chaine-map-legend" data-map-legend aria-hidden="true"></div>
</div></div><?php endif; ?></section>
<?php $mapSection=ob_get_clean(); ?>
<?php foreach($groups as $group=>$tablePlayers): $outside=$group==='outside'; ?>
<section class="users-panel" aria-labelledby="chaine-title-<?= e($group) ?>"><div class="users-panel-heading"><h2 id="chaine-title-<?= e($group) ?>"><?= $outside?'Hors chaîne':'UPTEP · Serveur 5' ?></h2><span class="user-count"><?= count($tablePlayers) ?> membres</span><?php if($latest): ?><span class="chaine-updated">Dernier relevé : <?= e(chaineDate($latest)) ?> <span>(Paris)</span></span><?php endif; ?></div>
<?php if(!$tablePlayers): ?><p class="chaine-empty"><?= $outside?'Aucun membre hors chaîne.':'Aucun membre dans la chaîne.' ?></p><?php else: ?>
<div class="users-scroll" role="region" aria-label="<?= $outside?'Membres hors chaîne':'Membres et grades de la chaîne' ?>" tabindex="0"><table class="users-table chaine-table" data-chaine-group="<?= e($group) ?>"><thead><tr><th scope="col" aria-sort="none"><button class="table-sort" type="button" data-sort="player">Joueur<span class="sort-arrow" aria-hidden="true"></span></button></th><th scope="col" aria-sort="none"><button class="table-sort" type="button" data-sort="field">Terrain de chasse<span class="sort-arrow" aria-hidden="true"></span></button></th><th scope="col" aria-sort="ascending"><button class="table-sort" type="button" data-sort="grade">Grade de chaîne<span class="sort-arrow" aria-hidden="true"></span></button></th><th scope="col" aria-sort="none"><button class="table-sort" type="button" data-sort="movement">Position<span class="sort-arrow" aria-hidden="true"></span></button></th><?php if($canEdit): ?><th scope="col" aria-sort="none"><button class="table-sort" type="button" data-sort="order">Ordre<span class="sort-arrow" aria-hidden="true"></span></button></th><th scope="col"><span class="sr-only">Enregistrer</span></th><?php endif; ?></tr></thead><tbody>
<?php foreach($tablePlayers as $player): $form='player-'.$player['player_id']; $movement=$movements[(string)$player['player_id']]; $isSelf=$focus['self']===(int)$player['player_id']; $isTarget=in_array((int)$player['player_id'],$focus['targets'],true); ?>
<tr class="<?= $isSelf?'is-self':($isTarget?'is-target':'') ?>" data-movement="<?= e($movement['sort']) ?>" data-player-id="<?= (int)$player['player_id'] ?>" data-player="<?= e($player['pseudo']) ?>" data-field="<?= (int)$player['field'] ?>" data-grade="<?= e($player['chaine_grade']??'') ?>" data-order="<?= e((string)($player['sort_order']??'')) ?>" title="<?= e('Relevé du '.chaineDate($player['export_version'])) ?>"><th scope="row"><span class="user-display-name"><?= e($player['pseudo']) ?><?php if($isSelf): ?><span class="personal-badge">Vous</span><?php elseif($isTarget): ?><span class="personal-badge">À portée</span><?php endif; ?></span><?php if($player['on_holiday'] || $player['is_banned']): ?><span class="user-identifier"><?= e(implode(' · ',array_filter([$player['on_holiday']?'Vacances':null,$player['is_banned']?'Banni':null]))) ?></span><?php endif; ?></th><td class="chaine-field"><?= number_format((int)$player['field'],0,',',' ') ?></td>
<?php if($canEdit): ?>
<td><select data-grade="<?= e($player['chaine_grade']??'') ?>" class="chaine-grade" name="grade" form="<?= e($form) ?>" aria-label="<?= e('Grade de chaîne de '.$player['pseudo']) ?>"><option value="">Non attribué</option><?php foreach(CHAINE_GRADES as $grade): ?><option value="<?= e($grade) ?>" <?= $player['chaine_grade']===$grade?'selected':'' ?>><?= e($grade) ?></option><?php endforeach; ?></select></td>
<td><span class="chain-move chain-move-<?= e($movement['kind']) ?>" role="img" aria-label="<?= e($movement['label']) ?>" title="<?= e($movement['label']) ?>"><span aria-hidden="true"><?= e($movement['symbol']) ?></span></span></td>
<td><input class="chaine-order" type="number" name="sort_order" min="1" max="999999" step="1" placeholder="—" value="<?= e((string)($player['sort_order']??'')) ?>" form="<?= e($form) ?>" aria-label="<?= e('Ordre de '.$player['pseudo']) ?>"></td>
<td><form id="<?= e($form) ?>" action="/chaine.php" method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="player_id" value="<?= (int)$player['player_id'] ?>"><input type="hidden" name="version" value="<?= (int)$player['version'] ?>"><button class="save-user" type="submit" aria-label="<?= e('Enregistrer '.$player['pseudo']) ?>">Enregistrer</button></form></td>
<?php else: ?><td><span data-grade="<?= e($player['chaine_grade']??'') ?>" class="chaine-badge <?= $player['chaine_grade']===null?'is-unassigned':'' ?>"><?= e($player['chaine_grade']??'Non attribué') ?><?php if($player['sort_order']!==null): ?><span class="chaine-badge-order"> · <?= (int)$player['sort_order'] ?></span><?php endif; ?></span></td><td><span class="chain-move chain-move-<?= e($movement['kind']) ?>" role="img" aria-label="<?= e($movement['label']) ?>" title="<?= e($movement['label']) ?>"><span aria-hidden="true"><?= e($movement['symbol']) ?></span></span></td><?php endif; ?>
</tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
<span class="sr-only" data-sort-status aria-live="polite"></span></section><?php if($group==='main')echo $mapSection; ?><?php endforeach; ?>
<section class="users-panel" aria-labelledby="departed-title"><div class="users-panel-heading"><h2 id="departed-title">Membres ayant quitté l’alliance</h2><span class="user-count"><?= count($departed) ?> membres</span></div>
<p class="users-help">Présents dans la base chaîne, mais absents des membres UPTEP du dernier relevé. La suppression retire uniquement leur fiche de la chaîne.</p>
<?php if(!$departed): ?><p class="chaine-empty">Aucun ancien membre à retirer.</p><?php else: ?>
<div class="users-scroll" tabindex="0" role="region" aria-label="Anciens membres de la chaîne"><table class="users-table"><thead><tr><th scope="col">Joueur</th><th scope="col">Grade de chaîne</th><th scope="col">Ordre</th><?php if($canEdit): ?><th scope="col">Action</th><?php endif; ?></tr></thead><tbody>
<?php foreach($departed as $member): ?><tr data-departed-id="<?= (int)$member['player_id'] ?>"><th scope="row"><span class="user-display-name"><?= e($member['pseudo']??('Joueur #'.$member['player_id'])) ?></span><span class="user-identifier">ID : <?= (int)$member['player_id'] ?></span></th><td><?= e($member['grade']??'Non attribué') ?></td><td><?= e((string)($member['sort_order']??'—')) ?></td>
<?php if($canEdit): ?><td><form method="post" action="/chaine.php"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="delete_departed"><input type="hidden" name="player_id" value="<?= (int)$member['player_id'] ?>"><input type="hidden" name="version" value="<?= (int)$member['version'] ?>"><button class="save-user" type="submit" aria-label="<?= e('Supprimer '.($member['pseudo']??$member['player_id']).' de la chaîne') ?>">Supprimer</button></form></td><?php endif; ?></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></section>
<?php endif; ?></div>
<?php workspaceEnd(); ?>
