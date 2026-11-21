<?php
declare(strict_types=1);
require '/home/uptep/private/app/bootstrap.php';
require '/home/uptep/private/app/view.php';
require '/home/uptep/private/app/movements.php';
$u=requireUser();
// The page's filters are saved on the account (not while an admin previews another member).
if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
    header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
    if(!validCsrf()||post('action')!=='preferences'){http_response_code(400);echo '{"ok":false}';exit;}
    if(isset($_SESSION['preview'])){echo '{"ok":true,"saved":false}';exit;}
    $raw=post('reset')==='1'?null:json_decode(post('prefs'),true);
    if($raw!==null&&!is_array($raw)){http_response_code(422);echo '{"ok":false}';exit;}
    try{movementsSavePrefs((int)$u['id'],$raw);echo '{"ok":true,"saved":true}';}catch(Throwable $ex){error_log('UPTEP mouvements prefs: '.$ex->getMessage());http_response_code(503);echo '{"ok":false}';}
    exit;
}
$zone=new DateTimeZone('Europe/Paris');$now=new DateTimeImmutable('now',$zone);$today=$now->setTime(0,0);
$day=movementsDayParam($_GET['jour']??null,$today);$next=$day->modify('+1 day');$prev=$day->modify('-1 day');
$first=new DateTimeImmutable(MOVEMENTS_FIRST_DAY,$zone);$last=$today->modify('+31 days');
$events=[];$unavailable=false;
try{$events=movementsData($day,$next,(string)$u['game_player_id']);}catch(Throwable $ex){error_log('UPTEP mouvements: '.$ex->getMessage());http_response_code(503);$unavailable=true;}
$me=$u['game_player_id']!==null?(string)$u['game_player_id']:null;
$prefs=null;$targets=null;
try{$prefs=movementsPrefs((int)$u['id']);}catch(Throwable $ex){error_log('UPTEP mouvements prefs: '.$ex->getMessage());}
$prefs??=movementsPrefsNormalize(null);
try{$targets=movementsTargets($me);}catch(Throwable $ex){error_log('UPTEP mouvements cibles: '.$ex->getMessage());}
$types=['flood'=>['Floods','Flood'],'convoy'=>['Convois','Convoi'],'hunt'=>['Chasses','Chasse']];
$counts=array_fill_keys(array_keys($types),0);$hours=[];
// Member filter: everyone involved in the day's movements, plus members already selected (?membres=1,2).
$people=[];foreach($events as $ev)foreach($ev['people'] as [$pid,$pname]){$pid=(string)$pid;$people[$pid]??=['name'=>$pname,'count'=>0];$people[$pid]['count']++;}
$selected=isset($_GET['membres'])?array_values(array_unique(array_filter(explode(',',is_string($_GET['membres'])?$_GET['membres']:''),static fn($v)=>preg_match('/\A[1-9][0-9]{0,17}\z/',$v)))):$prefs['members'];
$missing=array_values(array_diff($selected,array_keys($people)));
if($missing){try{$q=db(true)->prepare("SELECT player_id::text,pseudo FROM players WHERE server='s5' AND player_id IN (".implode(',',array_fill(0,count($missing),'?')).")");$q->execute($missing);foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$people[$r['player_id']]=['name'=>$r['pseudo'],'count'=>0];}catch(Throwable){}}
uasort($people,static fn($a,$b)=>strnatcasecmp($a['name'],$b['name']));
foreach($events as $ev){
    $counts[$ev['type']]++;
    $at=(new DateTimeImmutable('@'.$ev['at']))->setTimezone($zone);
    // Identical movements at the same minute (several floods or hunts launched together) share one chip "×n".
    $key=$ev['type'].'|'.$ev['label'].'|'.($ev['amount']??'').'|'.$at->format('H:i');$hour=&$hours[(int)$at->format('G')];
    if(isset($hour[$key])){$hour[$key]['count']++;$hour[$key]['players']=array_values(array_unique([...$hour[$key]['players'],...$ev['players']]));}
    else $hour[$key]=$ev+['local'=>$at,'count'=>1];
    unset($hour);
}
function movementsLeft(int $seconds): string {
    if($seconds<=0)return 'arrivé';
    $d=intdiv($seconds,86400);$h=intdiv($seconds%86400,3600);$m=intdiv($seconds%3600,60);
    return trim('dans '.($d?$d.' j ':'').($d||$h?$h.' h ':'').($d?'':sprintf($h?'%02d':'%d min',$m)));
}
$dayLink=static fn(DateTimeImmutable $d)=>'/mouvements.php'.($d==$today?'':'?jour='.$d->format('Y-m-d'));
workspaceStart($u,'Mouvements');
?>
<link rel="stylesheet" href="/assets/mouvements.css?v=9"><script src="/assets/mouvements.js?v=10" defer></script>
<div class="moves-page" data-me="<?= e($me??'') ?>" data-today="<?= $day==$today?'1':'0' ?>" data-csrf="<?= e(csrf()) ?>">
<h1>Mouvements</h1>
<p class="admin-intro">Les arrivées de la journée pour l’alliance : floods, convois et chasses, heure par heure (heure de Paris). Survole ou touche un élément pour le détail.</p>
<nav class="moves-nav" aria-label="Changer de jour">
<?php if($prev>=$first): ?><a class="moves-nav-step" href="<?= e($dayLink($prev)) ?>" rel="prev" aria-label="<?= e('Jour précédent : '.movementsDay($prev,$today)) ?>">‹ <span><?= e(movementsDay($prev,$today)) ?></span></a><?php else: ?><span class="moves-nav-step is-off" aria-hidden="true">‹</span><?php endif; ?>
<form class="moves-nav-form" method="get" action="/mouvements.php"><label class="sr-only" for="moves-day">Jour affiché</label><input id="moves-day" type="date" name="jour" value="<?= e($day->format('Y-m-d')) ?>" min="<?= e($first->format('Y-m-d')) ?>" max="<?= e($last->format('Y-m-d')) ?>"><button class="moves-nav-go" type="submit">Afficher</button><?php if($day!=$today): ?><a class="moves-nav-today" href="/mouvements.php">Aujourd’hui</a><?php endif; ?></form>
<?php if($next<=$last): ?><a class="moves-nav-step" href="<?= e($dayLink($next)) ?>" rel="next" aria-label="<?= e('Jour suivant : '.movementsDay($next,$today)) ?>"><span><?= e(movementsDay($next,$today)) ?></span> ›</a><?php else: ?><span class="moves-nav-step is-off" aria-hidden="true">›</span><?php endif; ?>
</nav>
<h2 class="moves-day-title"><?= e(movementsDay($day,$today)) ?></h2>
<?php if($unavailable): ?><div class="form-error" role="alert">Les mouvements sont momentanément indisponibles. Réessaie dans quelques instants.</div>
<?php else: ?>
<div class="moves-toolbar" role="group" aria-label="Filtrer les mouvements">
<?php foreach($types as $type=>[$plural]): ?><button type="button" class="moves-filter" data-filter="<?= e($type) ?>" aria-pressed="<?= in_array($type,$prefs['types'],true)?'true':'false' ?>"><span class="moves-dot is-<?= e($type) ?>" aria-hidden="true"></span><?= e($plural) ?> <span class="moves-count"><?= (int)$counts[$type] ?></span></button><?php endforeach; ?>
<?php if($me): ?><button type="button" class="moves-filter moves-me" data-filter="me" aria-pressed="<?= $prefs['me']?'true':'false' ?>">Seulement moi</button><?php endif; ?>
<?php if($targets!==null): ?><button type="button" class="moves-filter moves-targets" data-filter="targets" aria-pressed="<?= $prefs['targets']?'true':'false' ?>" aria-controls="moves-targets-panel">🎯 Mes cibles</button><?php endif; ?>
<button type="button" class="moves-reset" data-moves-reset>Réinitialiser</button>
<span class="sr-only" data-moves-status aria-live="polite"></span>
</div>
<?php if($targets!==null): ?><div class="moves-targets-panel" id="moves-targets-panel" data-moves-targets<?= $prefs['targets']?'':' hidden' ?>>
<p><strong>Mes cibles</strong> · <?= e($targets['grade'].($targets['order']!==null?' '.$targets['order']:'')) ?> · TDC <?= e(movementsFormat($targets['field'])) ?><?php if($targets['targets']): ?> · <label>jusqu’à <select data-targets-depth><?php for($n=3;$n<=MOVEMENTS_TARGET_LEVELS;$n++): ?><option value="<?= $n ?>"<?= $n===$prefs['depth']?' selected':'' ?>><?= $n ?> rangs</option><?php endfor; ?></select> en dessous</label><?php endif; ?></p>
<?php if(!$targets['targets']): ?><p class="moves-targets-none">Aucune cible : personne n’est classé en dessous de toi dans la chaîne<?= $targets['grade']==='Chasseur'?' (les Chasseurs n’ont que les joueurs « A flooder » en dessous)':'' ?>.</p>
<?php else: ?><ul class="moves-targets-list"><?php foreach($targets['targets'] as $t): ?><li data-player="<?= e($t['id']) ?>" data-level="<?= (int)$t['level'] ?>" class="<?= $t['in_range']?'':'is-out' ?>" title="<?= e($t['grade'].($t['order']!==null?' '.$t['order']:'').' · TDC '.movementsFormat($t['field']).($t['in_range']?'':' · hors portée')) ?>"><span class="moves-targets-level"><?= (int)$t['level'] ?></span><?= e($t['pseudo']) ?><?= $t['in_range']?'':' <small>hors portée</small>' ?></li><?php endforeach; ?></ul>
<p class="moves-targets-note">Floods et chasses où une de ces cibles est concernée. Portée : TDC entre la moitié et 3 fois le tien.</p><?php endif; ?>
</div><?php endif; ?>
<?php if($people): ?><details class="moves-members" data-moves-members><summary>Membres : <span data-members-label>tous</span></summary>
<div class="moves-members-panel"><div class="moves-members-actions"><input type="search" placeholder="Rechercher un membre" aria-label="Rechercher un membre" data-members-search><button type="button" data-members-clear>Tout effacer</button></div>
<ul><?php foreach($people as $pid=>$person): ?><li><label><input type="checkbox" value="<?= e((string)$pid) ?>"<?= in_array((string)$pid,$selected,true)?' checked':'' ?>> <span><?= e($person['name']) ?></span> <span class="moves-count"><?= (int)$person['count'] ?></span></label></li><?php endforeach; ?></ul></div></details><?php endif; ?>
<?php if(!$hours): ?><p class="moves-empty"><?= $day<$today?'Aucune arrivée enregistrée ce jour-là.':'Aucune arrivée prévue ce jour-là pour le moment.' ?></p><?php else: ?>
<button type="button" class="moves-earlier" data-moves-earlier hidden aria-expanded="false"></button>
<ol class="moves-hours">
<?php $n=0; foreach($hours as $hour=>$list): ?>
<li class="moves-hour" data-hour="<?= (int)$hour ?>"><span class="moves-hour-label"><?= sprintf('%02d h',$hour) ?></span><div class="moves-items">
<?php foreach($list as $ev): $n++; $tip='moves-tip-'.$n; ?>
<div class="moves-item is-<?= e($ev['type']) ?><?= $day==$today&&$ev['at']<=$now->getTimestamp()?' is-past':'' ?>" tabindex="0" data-type="<?= e($ev['type']) ?>" data-at="<?= (int)$ev['at'] ?>" data-players="<?= e(implode(',',$ev['players'])) ?>" aria-describedby="<?= e($tip) ?>">
<span class="moves-dot is-<?= e($ev['type']) ?>" aria-hidden="true"></span><span class="moves-time"><?= e($ev['local']->format('H:i')) ?></span><span class="moves-label"><?= e($ev['label']) ?><?php if($ev['type']==='convoy'): ?> · <?= e(movementsFormat($ev['amount'])) ?><?php endif; ?></span><?php if($ev['count']>1): ?><span class="moves-times">×<?= (int)$ev['count'] ?></span><?php endif; ?>
<span class="moves-tip" role="tooltip" id="<?= e($tip) ?>"><strong><?= e($types[$ev['type']][1]) ?> · arrivée le <?= e($ev['local']->format('d/m à H:i')) ?></strong><span class="moves-left" data-at="<?= (int)$ev['at'] ?>"><?= e(movementsLeft($ev['at']-$now->getTimestamp())) ?></span><?php if($ev['count']>1): ?><span class="moves-tip-row"><span><?= e($ev['type']==='flood'?'Floods identiques':($ev['type']==='hunt'?'Chasses identiques':'Convois identiques')) ?></span><span><?= (int)$ev['count'] ?><?= $ev['type']==='hunt'?' · '.e(movementsFormat($ev['amount']*$ev['count'])).' cm² au total':'' ?></span></span><?php endif; ?>
<?php foreach($ev['details'] as $label=>$value): ?><span class="moves-tip-row"><span><?= e($label) ?></span><span><?= e((string)$value) ?></span></span><?php endforeach; ?></span>
</div>
<?php endforeach; ?>
</div></li>
<?php endforeach; ?>
</ol>
<p class="moves-empty" data-moves-none hidden>Aucun mouvement pour ces filtres.</p>
<?php endif; ?>
<p class="moves-note">Floods et convois déclarés (bot, site, UPTEP+), chasses relevées par UPTEP+ ou déclarées sur Discord. Les convois et chasses annulés ne sont pas affichés. Les heures sont celles déclarées : ce ne sont pas des confirmations du jeu.</p>
<?php endif; ?>
</div>
<?php workspaceEnd(); ?>
