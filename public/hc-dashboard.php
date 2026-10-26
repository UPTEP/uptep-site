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
        'buildings'=>$r['buildings_synced_at'],'research'=>$r['research_synced_at'],
    ]];
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
        // Every building and research level, for the optional columns.
        $levels=[];
        foreach(['buildings'=>'b','research'=>'r'] as $group=>$prefix)foreach(STATS_GROUPS[$group][1] as $key=>$label)$levels[$prefix.'_'.$key]=$level($st[$group][$key]??null);
        $rows[]=[
            'levels'=>$levels,'buildings_at'=>$st['_at']['buildings']??null,'research_at'=>$st['_at']['research']??null,
            'id'=>$id,'convoys_out'=>count($sources['convoys_out'][$id]??[]),'convoys_in'=>count($sources['convoys_in'][$id]??[]),'floods_out'=>count($sources['floods_out'][$id]??[]),'floods_in'=>count($sources['floods_in'][$id]??[]),'name'=>$p['pseudo'],'tdp'=>($sol!==null&&$cou!==null&&$tech!==null)?$sol+$cou+$tech:null,'tdp_detail'=>"Solarium $sol + couveuse $cou + technique de ponte $tech",
            'workers'=>is_string($workers)&&preg_match('/^(0|[1-9][0-9]{0,18})$/D',$workers)?$workers:null,
            'weapons'=>$weapons,'shield'=>$shield,'speed'=>$speed,'fdf'=>$fdf,'cdf'=>$cdf,'army_at'=>$st['_at']['army']??null,'tdp_at'=>$st['_at']['tdp']??null,'workers_at'=>$st['_at']['workers']??null,'grade'=>$chain[$id]['grade']??null,'field'=>$p['field']===null?null:(int)$p['field'],
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
<link rel="stylesheet" href="/assets/hc-dashboard.css?v=9"><script src="/assets/hc-dashboard.js?v=4" defer></script>
<div class="chaine-page hc-page"><h1>HC - Dashboard</h1><p class="admin-intro">Vue d’ensemble des membres de l’alliance, réservée aux chefs.</p>
<?php if($unavailable): ?><div class="form-error" role="alert">Les informations sont momentanément indisponibles. Réessaie dans quelques instants.</div>
<?php else: ?>
<section class="users-panel" aria-labelledby="hc-members-title"><div class="users-panel-heading"><h2 id="hc-members-title">Membres UPTEP · Serveur 5</h2><span class="user-count"><?= count($rows) ?> membres</span><span class="user-count"><?= $countRegistered ?> inscrits</span><span class="user-count"><?= $countScript ?> avec UPTEP+</span><?php if($latest): ?><span class="chaine-updated">Dernier relevé : <?= e(chaineDate($latest)) ?> <span>(Paris)</span></span><?php endif; ?></div>
<?php if(!$rows): ?><p class="chaine-empty">Aucun membre dans le dernier relevé.</p><?php else: ?>
<?php $cols=hcColumns();$unknown='<span class="hc-unknown" title="Données UPTEP+ indisponibles">—</span>';
$titles=['cdf'=>'Capacité de flood : toutes les unités de l’armée additionnées'];
/** One table cell per column; level columns show the level and the date it was collected. */
$cell=static function(array $r,string $key)use($unknown):string{
    $note=static fn(?string $at):string=>hcDate($at)?'<span class="hc-status-note">'.e(hcDate($at,'d/m/Y')).'</span>':'';
    $flows=static function(int $out,int $in,string $what):string{
        if($out+$in===0)return '<span class="hc-unknown" title="Aucun '.$what.' en cours">—</span>';
        return ($out?'<span class="hc-flood is-out" title="'.$out.' '.$what.'(s) envoyé(s) en cours">↑ '.$out.'</span>':'').($in?'<span class="hc-flood is-in" title="'.$in.' '.$what.'(s) reçu(s) en cours">↓ '.$in.'</span>':'');
    };
    if(array_key_exists($key,$r['levels'])){
        $v=$r['levels'][$key];$at=$key[0]==='b'?$r['buildings_at']:$r['research_at'];
        return '<td class="hc-field">'.($v===null?$unknown:'<span>'.$v.'</span>'.$note($at)).'</td>';
    }
    return match($key){
        'grade'=>'<td><span class="chaine-badge '.($r['grade']===null?'is-unassigned':'').'" data-grade="'.e($r['grade']??'').'">'.e($r['grade']??'Non attribué').'</span></td>',
        'field'=>'<td class="hc-field">'.($r['field']===null?'—':'<span title="'.e(number_format($r['field'],0,',',' ')).'">'.e(hcCompact($r['field'])).'</span>').'</td>',
        'tdp'=>'<td class="hc-field">'.($r['tdp']===null?$unknown:'<span title="'.e($r['tdp_detail']).'">'.(int)$r['tdp'].'</span>'.$note($r['tdp_at'])).'</td>',
        'workers'=>'<td class="hc-field">'.($r['workers']===null?$unknown:'<span title="'.e(hcGroup($r['workers'])).'">'.e(hcCompact($r['workers'])).'</span>'.$note($r['workers_at'])).'</td>',
        'fdf'=>'<td class="hc-field">'.($r['fdf']===null?$unknown:'<span title="'.e(number_format($r['fdf'],0,',',' ').' · armée × attaque de base × (1 + 10 % × niveau d’armes)').'">'.e(hcCompact($r['fdf'])).'</span>'.$note($r['army_at'])).'</td>',
        'cdf'=>'<td class="hc-field">'.($r['cdf']===null?$unknown:'<span title="'.e(number_format($r['cdf'],0,',',' ').' unités · capacité de flood (toutes les unités de l’armée)').'">'.e(hcCompact($r['cdf'])).'</span>'.$note($r['army_at'])).'</td>',
        'convoys'=>'<td><div class="hc-access">'.$flows((int)$r['convoys_out'],(int)$r['convoys_in'],'convoi').'</div></td>',
        'floods'=>'<td><div class="hc-access">'.$flows((int)$r['floods_out'],(int)$r['floods_in'],'flood').'</div></td>',
        'access'=>'<td><div class="hc-access">'.($r['registered']==='yes'?hcStatus('yes','Site'):($r['registered']==='pending'?hcStatus('pending','Site · en attente'):hcStatus('no','Site'))).($r['script']!==null?hcStatus('yes','Userscript'):hcStatus('no','Userscript')).'</div>'.($r['script']!==null&&hcDate($r['script'])?'<span class="hc-status-note">Dernière activité : '.e(hcDate($r['script'])).'</span>':'').'</td>',
    };
};
$picker=[];foreach($cols as $key=>[$header,$label,$group,$default])$picker[$group][$key]=[$label,$default]; ?>
<details class="hc-columns" id="hc-columns"><summary>Colonnes <span class="hc-columns-count" data-hc-columns-count></span></summary>
<div class="hc-columns-panel"><div class="hc-columns-tools"><label class="sr-only" for="hc-columns-search">Filtrer les colonnes</label><input type="search" id="hc-columns-search" placeholder="Filtrer : étable, armes…" autocomplete="off"><button type="button" class="hc-columns-reset" data-hc-columns-reset>Par défaut</button></div>
<?php foreach($picker as $group=>$items): ?><fieldset class="hc-columns-group"><legend><?= e($group) ?></legend><?php foreach($items as $key=>[$label,$default]): ?><label><input type="checkbox" value="<?= e($key) ?>" data-default="<?= $default?'1':'0' ?>"<?= $default?' checked':'' ?>> <?= e($label) ?></label><?php endforeach; ?></fieldset>
<?php endforeach; ?><p class="hc-columns-empty" data-hc-columns-empty hidden>Aucune colonne ne correspond.</p></div></details>
<div class="users-scroll" role="region" aria-label="Tableau des membres de l’alliance" tabindex="0"><table class="users-table hc-table" id="hc-table"><thead><tr><th scope="col" aria-sort="none"><button class="table-sort" type="button" data-hc-sort="name" data-type="text">Nom<span class="sort-arrow" aria-hidden="true"></span></button></th><?php foreach($cols as $key=>[$header,$label,$group,$default,$type]): ?><th scope="col" data-col="<?= e($key) ?>" aria-sort="<?= $key==='field'?'descending':'none' ?>"<?= $default?'':' hidden' ?>><button class="table-sort" type="button" data-hc-sort="<?= e($key) ?>" data-type="num"<?= $type==='asc'?' data-first="asc"':'' ?><?= isset($titles[$key])?' title="'.e($titles[$key]).'"':($header!==$label?' title="'.e($label).'"':'') ?>><?= e($header) ?><span class="sort-arrow" aria-hidden="true"></span></button></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach($rows as $r): $gradeRank=array_search($r['grade'],CHAINE_GRADES,true);
$sort=['grade'=>$gradeRank===false?'':(int)$gradeRank,'field'=>$r['field']??'','tdp'=>$r['tdp']??'','workers'=>$r['workers']??'','fdf'=>$r['fdf']??'','cdf'=>$r['cdf']??'','convoys'=>(int)$r['convoys_out']+(int)$r['convoys_in'],'floods'=>(int)$r['floods_out']+(int)$r['floods_in'],'access'=>['no'=>0,'pending'=>1,'yes'=>2][$r['registered']]+($r['script']!==null?1:0)]+$r['levels']; ?><tr class="hc-row" data-player-id="<?= e($r['id']) ?>" data-name="<?= e($r['name']) ?>"<?php foreach($sort as $key=>$v): ?> data-<?= e($key) ?>="<?= e((string)($v??'')) ?>"<?php endforeach; ?>>
<th scope="row"><button type="button" class="hc-open user-display-name" aria-haspopup="dialog" title="Voir les informations de <?= e($r['name']) ?>"><?= e($r['name']) ?></button></th>
<?php foreach($cols as $key=>[,,,$default]): ?><?= preg_replace('/^<td/',$default?'<td data-col="'.e($key).'"':'<td data-col="'.e($key).'" hidden',$cell($r,$key)) ?><?php endforeach; ?>
</tr><?php endforeach; ?>
</tbody></table></div><span class="sr-only" data-hc-sort-status aria-live="polite"></span><?php endif; ?></section>
<?php foreach($rows as $r): ?><template id="hc-detail-<?= e($r['id']) ?>"><?= $details[$r['id']] ?></template>
<?php endforeach; ?>
<dialog id="hc-modal" class="hc-modal" aria-labelledby="hc-modal-title"><div class="hc-modal-head"><h2 id="hc-modal-title"></h2><button type="button" class="hc-modal-close" data-hc-close aria-label="Fermer">×</button></div><div class="hc-modal-body" id="hc-modal-body" tabindex="-1"></div></dialog>
<?php endif; ?></div>
<?php workspaceEnd(); ?>
