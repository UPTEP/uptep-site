<?php
declare(strict_types=1);
require '/home/uptep/private/app/bootstrap.php';
require '/home/uptep/private/app/view.php';
require '/home/uptep/private/app/chaine.php';
require '/home/uptep/private/app/hc-dashboard.php';
$u=requireUser();
if(empty($u['is_chef'])){
    http_response_code(403);
    workspaceStart($u,'HC - Dashboard');
    echo '<h1>HC - Dashboard</h1><p class="admin-intro">Cette page est réservée aux chefs.</p>';
    workspaceEnd();exit;
}
$rows=[];$latest='';$unavailable=false;
try{
    $players=chainePlayers();
    $q=db()->prepare('SELECT player_id,grade,sort_order FROM chaine_members WHERE server=?');$q->execute([CHAINE_SERVER]);$chain=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$chain[(string)$r['player_id']]=$r;
    // One row per game player: an account counts when it is active; UPTEP+ use is the latest script contact.
    $accounts=[];
    $q=db()->query("SELECT game_player_id,bool_or(status='active') AS active,bool_or(status='pending') AS pending,max(last_script_seen_at) AS script_seen FROM users WHERE game_player_id IS NOT NULL GROUP BY game_player_id");
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$accounts[(string)$r['game_player_id']]=$r;
    // UPTEP+ statistics, latest synchronised account per game player.
    $stats=[];
    $q=db()->query("SELECT DISTINCT ON (u.game_player_id) u.game_player_id,s.data,s.buildings_synced_at,s.research_synced_at,s.workers_synced_at,s.army_synced_at FROM user_stats s JOIN users u ON u.id=s.user_id WHERE u.status='active' AND u.game_player_id IS NOT NULL ORDER BY u.game_player_id,s.updated_at DESC");
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$stats[(string)$r['game_player_id']]=(json_decode($r['data'],true)?:[])+['_at'=>[
        // A TDP is only as fresh as its oldest ingredient: buildings (solarium, couveuse) or research.
        'tdp'=>$r['buildings_synced_at']&&$r['research_synced_at']?min($r['buildings_synced_at'],$r['research_synced_at']):null,
        'workers'=>$r['workers_synced_at'],'army'=>$r['army_synced_at'],
    ]];
    // The game header excludes workers away on a convoy (10 per worker, +5 % per aphid stable level).
    // A collected figure therefore misses the convoys that were travelling when it was collected.
    $convoys=[];$nowTs=time();
    $q=db()->query("SELECT idenvoyeur,nbpomme,nbmateriaux,date_convoi,date_arrivee FROM convois_effectues WHERE annule=false AND date_arrivee>now()-interval '30 days'");
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$convoys[(string)$r['idenvoyeur']][]=['res'=>(int)$r['nbpomme']+(int)$r['nbmateriaux'],'from'=>(new DateTimeImmutable($r['date_convoi']))->getTimestamp(),'to'=>(new DateTimeImmutable($r['date_arrivee']))->getTimestamp()];
    $sources=hcDetailSources([]);
    // Base attack per army key (official unit order and values from the combat engine in combat-analyze.cjs).
    $attack=['jsn'=>3,'sn'=>5,'ne'=>7,'js'=>10,'s'=>15,'c'=>1,'ce'=>1,'a'=>30,'ae'=>35,'se'=>24,'t'=>55,'te'=>80,'tu'=>50,'tue'=>55];
    $level=static fn($v):?int=>is_string($v)&&preg_match('/^[0-9]{1,4}$/D',$v)?(int)$v:null;
    foreach($players as $p){
        $id=(string)$p['player_id'];$a=$accounts[$id]??null;
        $st=$stats[$id]??[];
        $sol=$level($st['buildings']['solarium']??null);$cou=$level($st['buildings']['couveuse']??null);$tech=$level($st['research']['technique_ponte']??null);
        $workers=$st['workers']['total']??null;
        $weapons=$level($st['research']['armes']??null);$shield=$level($st['research']['bouclier']??null);$speed=$level($st['research']['vitesse_attaque']??null);
        // Strike force: sum of units x base attack, +10 % per weapon level.
        $fdf=null;$army=$st['army']??null;
        if($weapons!==null&&is_array($army)&&$army){
            $base=0;$valid=true;
            foreach($attack as $key=>$atk){$n=$army[$key]??'0';if(!is_string($n)||!preg_match('/^[0-9]{1,13}$/D',$n)){$valid=false;break;}$base+=(int)$n*$atk;}
            if($valid)$fdf=intdiv($base*(10+$weapons),10);
        }
        // Flood capacity: all army units added up, whatever their type (no weapons level needed).
        $cdf=null;
        if(is_array($army)&&$army){$sum=0;foreach(array_keys($attack) as $key){$n=$army[$key]??'0';if(!is_string($n)||!preg_match('/^[0-9]{1,13}$/D',$n)){$sum=null;break;}$sum+=(int)$n;}$cdf=$sum;}
        $stable=$level($st['buildings']['etable_pucerons']??null)??0;
        // Capacity per worker is 10*(20+level)/20, so workers needed = ceil(resources*2/(20+level)).
        $syncAt=($st['_at']['workers']??null)?(new DateTimeImmutable($st['_at']['workers']))->getTimestamp():null;
        // Workers away when the figure was collected: still travelling now, or already back since.
        $still=0;$back=0;
        foreach($convoys[$id]??[] as $c){
            if($syncAt===null||$c['from']>$syncAt||$c['to']<=$syncAt)continue;
            $w=intdiv($c['res']*2+19+$stable,20+$stable);
            if($c['to']>$nowTs)$still+=$w;else $back+=$w;
        }
        if(is_string($workers)&&strlen($workers)<=17&&$still+$back>0)$workers=(string)((int)$workers+$still+$back);else{$still=0;$back=0;}
        $rows[]=[
            'id'=>$id,'convoys_out'=>count($sources['convoys_out'][$id]??[]),'convoys_in'=>count($sources['convoys_in'][$id]??[]),'floods_out'=>count($sources['floods_out'][$id]??[]),'floods_in'=>count($sources['floods_in'][$id]??[]),'name'=>$p['pseudo'],'tdp'=>($sol!==null&&$cou!==null&&$tech!==null)?$sol+$cou+$tech:null,'tdp_detail'=>"Solarium $sol + couveuse $cou + technique de ponte $tech",
            'workers'=>is_string($workers)&&preg_match('/^(0|[1-9][0-9]{0,18})$/D',$workers)?$workers:null,
            'workers_transit'=>$still,'workers_back'=>$back,'weapons'=>$weapons,'shield'=>$shield,'speed'=>$speed,'fdf'=>$fdf,'cdf'=>$cdf,'army_at'=>$st['_at']['army']??null,'tdp_at'=>$st['_at']['tdp']??null,'workers_at'=>$st['_at']['workers']??null,'grade'=>$chain[$id]['grade']??null,'field'=>$p['field']===null?null:(int)$p['field'],
            'registered'=>$a&&$a['active']?'yes':($a&&$a['pending']?'pending':'no'),
            'script'=>$a&&$a['active']&&$a['script_seen']?$a['script_seen']:null,
        ];
        if($p['export_version']>$latest)$latest=$p['export_version'];
    }
    usort($rows,static fn($a,$b)=>(($b['field']??-1)<=>($a['field']??-1))?:strnatcasecmp($a['name'],$b['name']));
    $details=[];
    foreach($rows as $r)$details[$r['id']]=hcDetailHtml($r,$sources,$chain[$r['id']]??null);
}catch(Throwable $ex){error_log('UPTEP hc-dashboard read: '.$ex->getMessage());http_response_code(503);$unavailable=true;}
$countRegistered=count(array_filter($rows,static fn($r)=>$r['registered']==='yes'));
$countScript=count(array_filter($rows,static fn($r)=>$r['script']!==null));
workspaceStart($u,'HC - Dashboard');
?>
<link rel="stylesheet" href="/assets/hc-dashboard.css?v=8"><script src="/assets/hc-dashboard.js?v=3" defer></script>
<div class="chaine-page hc-page"><h1>HC - Dashboard</h1><p class="admin-intro">Vue d’ensemble des membres de l’alliance, réservée aux chefs.</p>
<?php if($unavailable): ?><div class="form-error" role="alert">Les informations sont momentanément indisponibles. Réessaie dans quelques instants.</div>
<?php else: ?>
<section class="users-panel" aria-labelledby="hc-members-title"><div class="users-panel-heading"><h2 id="hc-members-title">Membres UPTEP · Serveur 5</h2><span class="user-count"><?= count($rows) ?> membres</span><span class="user-count"><?= $countRegistered ?> inscrits</span><span class="user-count"><?= $countScript ?> avec UPTEP+</span><?php if($latest): ?><span class="chaine-updated">Dernier relevé : <?= e(chaineDate($latest)) ?> <span>(Paris)</span></span><?php endif; ?></div>
<?php if(!$rows): ?><p class="chaine-empty">Aucun membre dans le dernier relevé.</p><?php else: ?>
<div class="users-scroll" role="region" aria-label="Tableau des membres de l’alliance" tabindex="0"><table class="users-table hc-table" id="hc-table"><thead><tr><th scope="col" aria-sort="none"><button class="table-sort" type="button" data-hc-sort="name" data-type="text">Nom<span class="sort-arrow" aria-hidden="true"></span></button></th><th scope="col" aria-sort="none"><button class="table-sort" type="button" data-hc-sort="grade" data-type="num" data-first="asc">Grade chaîne<span class="sort-arrow" aria-hidden="true"></span></button></th><th scope="col" aria-sort="descending"><button class="table-sort" type="button" data-hc-sort="field" data-type="num">TDC<span class="sort-arrow" aria-hidden="true"></span></button></th><th scope="col" aria-sort="none"><button class="table-sort" type="button" data-hc-sort="tdp" data-type="num">TDP<span class="sort-arrow" aria-hidden="true"></span></button></th><th scope="col" aria-sort="none"><button class="table-sort" type="button" data-hc-sort="workers" data-type="num">Ouvrières<span class="sort-arrow" aria-hidden="true"></span></button></th><th scope="col" aria-sort="none"><button class="table-sort" type="button" data-hc-sort="weapons" data-type="num">Arme<span class="sort-arrow" aria-hidden="true"></span></button></th><th scope="col" aria-sort="none"><button class="table-sort" type="button" data-hc-sort="shield" data-type="num">Bouclier<span class="sort-arrow" aria-hidden="true"></span></button></th><th scope="col" aria-sort="none"><button class="table-sort" type="button" data-hc-sort="speed" data-type="num">VA<span class="sort-arrow" aria-hidden="true"></span></button></th><th scope="col" aria-sort="none"><button class="table-sort" type="button" data-hc-sort="fdf" data-type="num">FDF<span class="sort-arrow" aria-hidden="true"></span></button></th><th scope="col" aria-sort="none"><button class="table-sort" type="button" data-hc-sort="cdf" data-type="num" title="Capacité de flood : toutes les unités de l’armée additionnées">CDF<span class="sort-arrow" aria-hidden="true"></span></button></th><th scope="col" aria-sort="none"><button class="table-sort" type="button" data-hc-sort="convoys" data-type="num">Convois en cours<span class="sort-arrow" aria-hidden="true"></span></button></th><th scope="col" aria-sort="none"><button class="table-sort" type="button" data-hc-sort="floods" data-type="num">Floods en cours<span class="sort-arrow" aria-hidden="true"></span></button></th><th scope="col" aria-sort="none"><button class="table-sort" type="button" data-hc-sort="access" data-type="num">Site &amp; userscript<span class="sort-arrow" aria-hidden="true"></span></button></th></tr></thead><tbody>
<?php foreach($rows as $r): $gradeRank=array_search($r['grade'],CHAINE_GRADES,true); ?><tr class="hc-row" data-player-id="<?= e($r['id']) ?>" data-name="<?= e($r['name']) ?>" data-grade="<?= $gradeRank===false?'':(int)$gradeRank ?>" data-field="<?= e((string)($r['field']??'')) ?>" data-tdp="<?= e((string)($r['tdp']??'')) ?>" data-workers="<?= e((string)($r['workers']??'')) ?>" data-weapons="<?= e((string)($r['weapons']??'')) ?>" data-shield="<?= e((string)($r['shield']??'')) ?>" data-speed="<?= e((string)($r['speed']??'')) ?>" data-fdf="<?= e((string)($r['fdf']??'')) ?>" data-cdf="<?= e((string)($r['cdf']??'')) ?>" data-convoys="<?= (int)$r['convoys_out']+(int)$r['convoys_in'] ?>" data-floods="<?= (int)$r['floods_out']+(int)$r['floods_in'] ?>" data-access="<?= ['no'=>0,'pending'=>1,'yes'=>2][$r['registered']]+($r['script']!==null?1:0) ?>">
<th scope="row"><button type="button" class="hc-open user-display-name" aria-haspopup="dialog" title="Voir les informations de <?= e($r['name']) ?>"><?= e($r['name']) ?></button></th>
<td><span class="chaine-badge <?= $r['grade']===null?'is-unassigned':'' ?>" data-grade="<?= e($r['grade']??'') ?>"><?= e($r['grade']??'Non attribué') ?></span></td>
<td class="hc-field"><?= $r['field']===null?'—':'<span title="'.e(number_format($r['field'],0,',',' ')).'">'.e(hcCompact($r['field'])).'</span>' ?></td>
<td class="hc-field"><?php if($r['tdp']!==null): ?><span title="<?= e($r['tdp_detail']) ?>"><?= (int)$r['tdp'] ?></span><?php if(hcDate($r['tdp_at'])): ?><span class="hc-status-note"><?= e(hcDate($r['tdp_at'],'d/m/Y')) ?></span><?php endif; ?><?php else: ?><span class="hc-unknown" title="Données UPTEP+ indisponibles">—</span><?php endif; ?></td>
<td class="hc-field"><?php if($r['workers']!==null): ?><span title="<?= e(strrev(trim(chunk_split(strrev($r['workers']),3,' '))).($r['workers_transit']>0?' · dont '.number_format($r['workers_transit'],0,',',' ').' en convoi':'').($r['workers_back']>0?' · dont '.number_format($r['workers_back'],0,',',' ').' revenus de convoi depuis la récolte':'')) ?>"><?= e(hcCompact($r['workers'])) ?></span><?php if($r['workers_transit']>0): ?><span class="hc-status-note">dont <?= e(hcCompact($r['workers_transit'])) ?> en convoi</span><?php endif; ?><?php if($r['workers_back']>0): ?><span class="hc-status-note">dont <?= e(hcCompact($r['workers_back'])) ?> revenus de convoi</span><?php endif; ?><?php if(hcDate($r['workers_at'])): ?><span class="hc-status-note"><?= e(hcDate($r['workers_at'],'d/m/Y')) ?></span><?php endif; ?><?php else: ?><span class="hc-unknown" title="Données UPTEP+ indisponibles">—</span><?php endif; ?></td>
<?php foreach(['weapons','shield','speed'] as $k): ?><td class="hc-field"><?= $r[$k]===null?'<span class="hc-unknown" title="Données UPTEP+ indisponibles">—</span>':(int)$r[$k] ?></td><?php endforeach; ?>
<td class="hc-field"><?php if($r['fdf']!==null): ?><span title="<?= e(number_format($r['fdf'],0,',',' ').' · armée × attaque de base × (1 + 10 % × niveau d’armes)') ?>"><?= e(hcCompact($r['fdf'])) ?></span><?php if(hcDate($r['army_at'])): ?><span class="hc-status-note"><?= e(hcDate($r['army_at'],'d/m/Y')) ?></span><?php endif; ?><?php else: ?><span class="hc-unknown" title="Données UPTEP+ indisponibles">—</span><?php endif; ?></td>
<td class="hc-field"><?php if($r['cdf']!==null): ?><span title="<?= e(number_format($r['cdf'],0,',',' ').' unités · capacité de flood (toutes les unités de l’armée)') ?>"><?= e(hcCompact($r['cdf'])) ?></span><?php if(hcDate($r['army_at'])): ?><span class="hc-status-note"><?= e(hcDate($r['army_at'],'d/m/Y')) ?></span><?php endif; ?><?php else: ?><span class="hc-unknown" title="Données UPTEP+ indisponibles">—</span><?php endif; ?></td>
<td><div class="hc-access"><?php if($r['convoys_out']+$r['convoys_in']===0): ?><span class="hc-unknown" title="Aucun convoi en cours">—</span><?php else: ?><?php if($r['convoys_out']): ?><span class="hc-flood is-out" title="<?= (int)$r['convoys_out'] ?> convoi(s) envoyé(s) en cours">↑ <?= (int)$r['convoys_out'] ?></span><?php endif; ?><?php if($r['convoys_in']): ?><span class="hc-flood is-in" title="<?= (int)$r['convoys_in'] ?> convoi(s) reçu(s) en cours">↓ <?= (int)$r['convoys_in'] ?></span><?php endif; ?><?php endif; ?></div></td>
<td><div class="hc-access"><?php if($r['floods_out']+$r['floods_in']===0): ?><span class="hc-unknown" title="Aucun flood en cours">—</span><?php else: ?><?php if($r['floods_out']): ?><span class="hc-flood is-out" title="<?= (int)$r['floods_out'] ?> flood(s) envoyé(s) en cours">↑ <?= (int)$r['floods_out'] ?></span><?php endif; ?><?php if($r['floods_in']): ?><span class="hc-flood is-in" title="<?= (int)$r['floods_in'] ?> flood(s) reçu(s) en cours">↓ <?= (int)$r['floods_in'] ?></span><?php endif; ?><?php endif; ?></div></td>
<td><div class="hc-access"><?= $r['registered']==='yes'?hcStatus('yes','Site'):($r['registered']==='pending'?hcStatus('pending','Site · en attente'):hcStatus('no','Site')) ?><?= $r['script']!==null?hcStatus('yes','Userscript'):hcStatus('no','Userscript') ?></div><?php if($r['script']!==null && hcDate($r['script'])): ?><span class="hc-status-note">Dernière activité : <?= e(hcDate($r['script'])) ?></span><?php endif; ?></td>
</tr><?php endforeach; ?>
</tbody></table></div><span class="sr-only" data-hc-sort-status aria-live="polite"></span><?php endif; ?></section>
<?php foreach($rows as $r): ?><template id="hc-detail-<?= e($r['id']) ?>"><?= $details[$r['id']] ?></template>
<?php endforeach; ?>
<dialog id="hc-modal" class="hc-modal" aria-labelledby="hc-modal-title"><div class="hc-modal-head"><h2 id="hc-modal-title"></h2><button type="button" class="hc-modal-close" data-hc-close aria-label="Fermer">×</button></div><div class="hc-modal-body" id="hc-modal-body" tabindex="-1"></div></dialog>
<?php endif; ?></div>
<?php workspaceEnd(); ?>
