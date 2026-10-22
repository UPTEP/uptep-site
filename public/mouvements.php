<?php
declare(strict_types=1);
require '/home/uptep/private/app/bootstrap.php';
require '/home/uptep/private/app/view.php';
require '/home/uptep/private/app/movements.php';
$u=requireUser();
$zone=new DateTimeZone('Europe/Paris');$now=new DateTimeImmutable('now',$zone);$today=$now->setTime(0,0);
$day=movementsDayParam($_GET['jour']??null,$today);$next=$day->modify('+1 day');$prev=$day->modify('-1 day');
$first=new DateTimeImmutable(MOVEMENTS_FIRST_DAY,$zone);$last=$today->modify('+31 days');
$events=[];$unavailable=false;
try{$events=movementsData($day,$next);}catch(Throwable $ex){error_log('UPTEP mouvements: '.$ex->getMessage());http_response_code(503);$unavailable=true;}
$me=$u['game_player_id']!==null?(string)$u['game_player_id']:null;
$types=['flood'=>['Floods','Flood'],'convoy'=>['Convois','Convoi'],'hunt'=>['Chasses','Chasse']];
$counts=array_fill_keys(array_keys($types),0);$hours=[];
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
<link rel="stylesheet" href="/assets/mouvements.css?v=6"><script src="/assets/mouvements.js?v=3" defer></script>
<div class="moves-page" data-me="<?= e($me??'') ?>" data-today="<?= $day==$today?'1':'0' ?>">
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
<?php foreach($types as $type=>[$plural]): ?><button type="button" class="moves-filter" data-filter="<?= e($type) ?>" aria-pressed="true"><span class="moves-dot is-<?= e($type) ?>" aria-hidden="true"></span><?= e($plural) ?> <span class="moves-count"><?= (int)$counts[$type] ?></span></button><?php endforeach; ?>
<?php if($me): ?><button type="button" class="moves-filter moves-me" data-filter="me" aria-pressed="false">Seulement moi</button><?php endif; ?>
<span class="sr-only" data-moves-status aria-live="polite"></span>
</div>
<?php if(!$hours): ?><p class="moves-empty"><?= $day<$today?'Aucune arrivée enregistrée ce jour-là.':'Aucune arrivée prévue ce jour-là pour le moment.' ?></p><?php else: ?>
<ol class="moves-hours">
<?php $n=0; foreach($hours as $hour=>$list): ?>
<li class="moves-hour"><span class="moves-hour-label"><?= sprintf('%02d h',$hour) ?></span><div class="moves-items">
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
