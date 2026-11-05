<?php
declare(strict_types=1);
require '/home/uptep/private/app/bootstrap.php';
require '/home/uptep/private/app/view.php';
require '/home/uptep/private/app/resource-balance.php';
require '/home/uptep/private/app/resource-activity.php';
require '/home/uptep/private/app/convoy-consumption.php';
require '/home/uptep/private/app/convoy-daily.php';
require '/home/uptep/private/app/stats.php';

$u=requireUser();
if ($u['role'] !== 'admin' && empty($u['is_chef'])) {
    http_response_code(403); workspaceStart($u, 'Accès réservé');
    echo '<h1>Accès réservé</h1><p>Les statistiques de l’alliance sont réservées aux chefs et aux administrateurs.</p>';
    workspaceEnd(); exit;
}

$tabs=[
    'repartition'=>'Répartition & Conso',
    'convois'=>'Convois',
    'gains'=>'Gains de l’alliance',
    'tdp'=>'Temps de ponte (TdP)',
    'bilan'=>'Bilan matériaux',
    'activite'=>'Floods & Chasses'
];
$tab=is_string($_GET['vue']??null)&&isset($tabs[$_GET['vue']])?$_GET['vue']:'repartition';
$paris=new DateTimeZone('Europe/Paris');
$date=static fn(DateTimeImmutable $d,string $f='d/m/Y H:i')=>$d->setTimezone($paris)->format($f);
$input=static fn(string $k):string=>is_string($_GET[$k]??null)?$_GET[$k]:'';
$amount=static fn(float $v,bool $signed=false)=>'<span title="'.e(($signed&&$v>0.5?'+':'').resourceNumber($v)).'">'.e(($signed&&$v>=500?'+':'').resourceCompact(round($v))).'</span>';
$count=static fn(int $n)=>'<span>'.e(number_format($n,0,',',' ')).'</span>';
$unavailable='<p class="form-error" role="alert">Cette partie est indisponible pour le moment. Réessaie dans quelques instants.</p>';

/** Renders the period filter bar with manual Du/Au inputs and 3j, 7j, 30j, Depuis le début shortcuts. */
function statsPeriodForm(string $tab,array $pInfo,array $extraHidden=[],string $extraFieldsHtml=''):void{
    $baseQuery=['vue'=>$tab]+$extraHidden;
    ?>
    <form method="get" class="convoy-filters resource-period">
    <input type="hidden" name="vue" value="<?= e($tab) ?>">
    <?php foreach($extraHidden as $k=>$v): ?><input type="hidden" name="<?= e((string)$k) ?>" value="<?= e((string)$v) ?>"><?php endforeach; ?>
    <label>Du<input type="date" name="debut" min="<?= e($pInfo['first_day']) ?>" max="<?= e($pInfo['today']) ?>" value="<?= e($pInfo['start']->format('Y-m-d')) ?>"></label>
    <label>Au<input type="date" name="fin" min="<?= e($pInfo['first_day']) ?>" max="<?= e($pInfo['today']) ?>" value="<?= e($pInfo['end_day']->format('Y-m-d')) ?>"></label>
    <?= $extraFieldsHtml ?>
    <button class="save-user" type="submit">Afficher</button>
    <?php foreach(['3j'=>'3 jours','7j'=>'7 jours','30j'=>'30 jours','tout'=>'Depuis le début'] as $pKey=>$pLabel): ?>
    <a class="resource-reset" href="/statistiques.php?<?= e(http_build_query($baseQuery+['periode'=>$pKey])) ?>"<?= ($pInfo['period']??'')===$pKey?' aria-current="true"':'' ?>><?= e($pLabel) ?></a>
    <?php endforeach; ?>
    </form>
    <?php
}

/** Signed compact formatter for daily gains (+12,4k / −3,2k / 0). */
function statsSignedCompact(?float $value,string $extraTip=''):string{
    if($value===null)return '—';
    $round=round($value);
    $sign=$round>0?'+':($round<0?'−':'');
    $abs=abs($round);
    $exact=($round>0?'+':($round<0?'−':'')).number_format($abs,0,',',' ');
    $tip=$extraTip!==''?$exact.' ('.$extraTip.')':$exact;
    if($abs<1000)return '<span class="daily-compact" title="'.e($tip).'">'.e($sign.number_format($abs,0,',',' ')).'</span>';
    return '<span class="daily-compact" title="'.e($tip).'">'.e(($round>0?'+':'').resourceCompact($round)).'</span>';
}

/** Daily gains (TdC, TdC/membre, Fourmilière, Technologie, Trophées) over the selected period. */
function statsPeriodGains(PDO $game,array $pInfo,?DateTimeImmutable $now=null):array{
    $paris=new DateTimeZone('Europe/Paris');
    $now=($now??new DateTimeImmutable())->setTimezone($paris);
    $today=$now->setTime(0,0);
    $start=$pInfo['start'];$endDay=$pInfo['end_day'];$endAt=$pInfo['end_at'];$numDays=$pInfo['days_count'];
    $baselineDay=$start->modify('-1 day');
    $days=[];
    for($i=0;$i<$numDays;$i++){
        $d=$endDay->modify('-'.$i.' days');
        $diff=(int)$d->diff($today)->days;
        $days[]=['date'=>$d->format('Y-m-d'),'label'=>$diff===0?'J · Aujourd’hui':'J-'.$diff,'display'=>$d->format('d/m')];
    }
    $members=[];
    foreach($game->query("SELECT player_id::text,pseudo FROM players WHERE server='s5' AND upper(alliance_tag)='UPTEP' AND is_present=true ORDER BY lower(pseudo),player_id") as $r){
        $members[$r['player_id']]=$r['pseudo'];
    }
    if(!$members)return ['days'=>$days,'days_count'=>$numDays,'alliance'=>[],'players'=>[],'members_count'=>0,'start'=>$start->format('d/m/Y'),'end'=>$endDay->format('d/m/Y')];
    $ids=array_keys($members);
    $marks=implode(',',array_fill(0,count($ids),'?'));
    $sql="WITH exports AS (
        SELECT DISTINCT ON (export_version) id,exported_at,
               ((exported_at AT TIME ZONE 'UTC') AT TIME ZONE 'Europe/Paris')::date::text AS day
        FROM historique_exports
        WHERE server='s5' AND export_type='players'
          AND exported_at>=(?::timestamptz AT TIME ZONE 'UTC') AND exported_at<(?::timestamptz AT TIME ZONE 'UTC')
        ORDER BY export_version,id DESC
    )
    SELECT e.day,e.exported_at,h.player_id::text,
           h.field::numeric AS tdc,
           coalesce(h.building_score,0)::numeric AS fourmiliere,
           coalesce(h.technology_score,0)::numeric AS techno,
           coalesce(h.trophy_score,0)::numeric AS trophee
    FROM historique_players h JOIN exports e ON e.id=h.export_id
    WHERE h.player_id IN ($marks)
    ORDER BY e.exported_at ASC,e.id ASC";
    $q=$game->prepare($sql);
    $q->execute(array_merge([$baselineDay->format('c'),$endAt->format('c')],$ids));
    $firstByDay=[];$lastByDay=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
        $pid=$r['player_id'];$day=$r['day'];
        $pt=['tdc'=>(float)$r['tdc'],'fourmiliere'=>(float)$r['fourmiliere'],'techno'=>(float)$r['techno'],'trophee'=>(float)$r['trophee']];
        if(!isset($firstByDay[$pid][$day]))$firstByDay[$pid][$day]=$pt;
        $lastByDay[$pid][$day]=$pt;
    }
    $chronDays=[$baselineDay->format('Y-m-d')];
    for($i=$numDays-1;$i>=0;$i--)$chronDays[]=$endDay->modify('-'.$i.' days')->format('Y-m-d');
    $keys=['tdc','fourmiliere','techno','trophee'];
    $alliance=[];
    foreach($days as $d){
        $alliance[$d['date']]=['tdc'=>null,'tdc_membre'=>null,'fourmiliere'=>null,'techno'=>null,'trophee'=>null,'covered'=>0,'totals'=>['tdc'=>0.0,'fourmiliere'=>0.0,'techno'=>0.0,'trophee'=>0.0]];
    }
    $playerRows=[];
    foreach($members as $pid=>$pseudo){
        $pDays=[];
        foreach($days as $d)$pDays[$d['date']]=['tdc'=>null,'fourmiliere'=>null,'techno'=>null,'trophee'=>null];
        $prev=$lastByDay[$pid][$chronDays[0]]??null;
        for($idx=1;$idx<=$numDays;$idx++){
            $dayStr=$chronDays[$idx];
            $curr=$lastByDay[$pid][$dayStr]??null;
            if($curr===null)continue;
            $base=$prev??($firstByDay[$pid][$dayStr]??null);
            if($base!==null){
                foreach($keys as $k){
                    $delta=$curr[$k]-$base[$k];
                    $pDays[$dayStr][$k]=$delta;
                    $alliance[$dayStr][$k]=($alliance[$dayStr][$k]??0.0)+$delta;
                    $alliance[$dayStr]['totals'][$k]+=$curr[$k];
                }
                $alliance[$dayStr]['covered']++;
            }
            $prev=$curr;
        }
        $sums=['tdc'=>0.0,'fourmiliere'=>0.0,'techno'=>0.0,'trophee'=>0.0,'any'=>false];
        foreach($pDays as $vals){
            if($vals['tdc']!==null){
                $sums['any']=true;
                foreach($keys as $k)$sums[$k]+=$vals[$k];
            }
        }
        $playerRows[]=['id'=>$pid,'pseudo'=>$pseudo,'days'=>$pDays,'sums'=>$sums];
    }
    foreach($alliance as &$aRow){
        if($aRow['covered']>0&&$aRow['tdc']!==null){
            $aRow['tdc_membre']=$aRow['tdc']/$aRow['covered'];
        }
    }
    unset($aRow);
    usort($playerRows,static fn($a,$b)=>($b['sums']['tdc']<=>$a['sums']['tdc'])?:strnatcasecmp($a['pseudo'],$b['pseudo']));
    return ['days'=>$days,'days_count'=>$numDays,'alliance'=>$alliance,'players'=>$playerRows,'members_count'=>count($members),'start'=>$start->format('d/m/Y'),'end'=>$endDay->format('d/m/Y')];
}

/** TdP summary (Top, Average, Min) + historical timeline over the selected period from member_stat_history. */
function statsTdpData(PDO $p,PDO $game,array $pInfo):array{
    $members=[];
    foreach($game->query("SELECT player_id::text,pseudo,field::numeric AS field FROM players WHERE server='s5' AND upper(alliance_tag)='UPTEP' AND is_present=true") as $r){
        $members[$r['player_id']]=['id'=>$r['player_id'],'pseudo'=>$r['pseudo'],'field'=>(float)$r['field']];
    }
    if(!$members)return ['rows'=>[],'top'=>null,'avg'=>null,'median'=>null,'min'=>null,'days'=>[],'series'=>[],'covered_count'=>0,'total_members'=>0];
    $level=static fn($v):?int=>(is_string($v)||is_int($v))&&preg_match('/^[0-9]{1,4}$/D',(string)$v)?(int)$v:null;
    $q=$p->query("SELECT DISTINCT ON (u.game_player_id) u.game_player_id::text AS player_id,s.data,s.buildings_synced_at,s.research_synced_at,s.updated_at
        FROM user_stats s JOIN users u ON u.id=s.user_id
        WHERE u.status='active' AND u.game_player_id IS NOT NULL
        ORDER BY u.game_player_id,s.updated_at DESC");
    $current=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
        $pid=$r['player_id'];
        if(!isset($members[$pid]))continue;
        $d=json_decode($r['data'],true)?:[];
        $sol=$level($d['buildings']['solarium']??null);
        $cou=$level($d['buildings']['couveuse']??null);
        $tech=$level($d['research']['technique_ponte']??null);
        $tdp=($sol!==null&&$cou!==null&&$tech!==null)?$sol+$cou+$tech:null;
        $at=$r['buildings_synced_at']&&$r['research_synced_at']?min($r['buildings_synced_at'],$r['research_synced_at']):($r['updated_at']??null);
        $current[$pid]=['solarium'=>$sol,'couveuse'=>$cou,'technique_ponte'=>$tech,'tdp'=>$tdp,'at'=>$at];
    }
    $rows=[];$validTdps=[];
    foreach($members as $pid=>$m){
        $c=$current[$pid]??['solarium'=>null,'couveuse'=>null,'technique_ponte'=>null,'tdp'=>null,'at'=>null];
        $row=$m+$c;
        $rows[]=$row;
        if($row['tdp']!==null)$validTdps[]=$row;
    }
    usort($rows,static fn($a,$b)=>(($b['tdp']??-1)<=>(($a['tdp']??-1)))?:strnatcasecmp($a['pseudo'],$b['pseudo']));
    $top=$min=$avg=$median=null;
    if($validTdps){
        $vals=array_column($validTdps,'tdp');
        $maxVal=max($vals);$minVal=min($vals);
        $topNames=array_column(array_filter($validTdps,static fn($r)=>$r['tdp']===$maxVal),'pseudo');
        $minNames=array_column(array_filter($validTdps,static fn($r)=>$r['tdp']===$minVal),'pseudo');
        $top=['value'=>$maxVal,'names'=>$topNames];
        $min=['value'=>$minVal,'names'=>$minNames];
        $avg=round(array_sum($vals)/count($vals),1);
        sort($vals);$mid=intdiv(count($vals),2);
        $median=count($vals)%2===1?(float)$vals[$mid]:round(($vals[$mid-1]+$vals[$mid])/2,1);
    }
    // Reconstruct daily TdP evolution from member_stat_history over [start, end_day]
    $paris=new DateTimeZone('Europe/Paris');
    $qH=$p->prepare("SELECT u.game_player_id::text AS player_id,h.stat_group,h.observed_at,
            h.data->>'solarium' AS solarium,h.data->>'couveuse' AS couveuse,h.data->>'technique_ponte' AS technique_ponte
        FROM users u JOIN member_stat_history h ON h.user_id=u.id
        WHERE u.status='active' AND u.game_player_id IS NOT NULL AND h.stat_group IN ('buildings','research')
          AND h.observed_at<=?::timestamptz
        ORDER BY h.observed_at ASC,h.id ASC");
    $qH->execute([$pInfo['end_at']->format('c')]);
    $state=[];$byPlayerDay=[];
    foreach($qH->fetchAll(PDO::FETCH_ASSOC) as $r){
        $pid=$r['player_id'];
        if(!isset($members[$pid]))continue;
        $st=&$state[$pid];
        if($r['stat_group']==='buildings'){
            $s=$level($r['solarium']);$c=$level($r['couveuse']);
            if($s!==null)$st['sol']=$s;
            if($c!==null)$st['cou']=$c;
        }else{
            $t=$level($r['technique_ponte']);
            if($t!==null)$st['tech']=$t;
        }
        if(isset($st['sol'],$st['cou'],$st['tech'])){
            $day=(new DateTimeImmutable($r['observed_at']))->setTimezone($paris)->format('Y-m-d');
            $byPlayerDay[$pid][$day]=$st['sol']+$st['cou']+$st['tech'];
        }
        unset($st);
    }
    $todayStr=$pInfo['today'];
    foreach($rows as $r){
        if($r['tdp']!==null){
            $dStr=$r['at']?(new DateTimeImmutable($r['at']))->setTimezone($paris)->format('Y-m-d'):$todayStr;
            if($dStr<=$pInfo['end_day']->format('Y-m-d'))$byPlayerDay[$r['id']][$dStr]=$r['tdp'];
            if($todayStr<=$pInfo['end_day']->format('Y-m-d'))$byPlayerDay[$r['id']][$todayStr]=$r['tdp'];
        }
    }
    $daysList=[];
    $numDays=$pInfo['days_count'];
    for($i=0;$i<$numDays;$i++){
        $daysList[]=$pInfo['start']->modify('+'.$i.' days')->format('Y-m-d');
    }
    $series=[];
    foreach($rows as $r){
        $pid=$r['id'];
        if(empty($byPlayerDay[$pid]))continue;
        ksort($byPlayerDay[$pid]);
        $lastVal=null;
        foreach($byPlayerDay[$pid] as $histDay=>$histVal){
            if($histDay<$daysList[0])$lastVal=$histVal;
        }
        $pts=[];$hasPoint=false;
        foreach($daysList as $dStr){
            if(isset($byPlayerDay[$pid][$dStr]))$lastVal=$byPlayerDay[$pid][$dStr];
            $pts[$dStr]=$lastVal;
            if($lastVal!==null)$hasPoint=true;
        }
        if($hasPoint)$series[]=['id'=>$pid,'pseudo'=>$r['pseudo'],'tdp'=>$r['tdp'],'points'=>$pts];
    }
    return ['rows'=>$rows,'top'=>$top,'avg'=>$avg,'median'=>$median,'min'=>$min,'days'=>$daysList,'series'=>$series,'covered_count'=>count($validTdps),'total_members'=>count($members)];
}

/** Pure SVG multi-line chart for TdP evolution. */
function statsTdpSvg(array $tdpData):string{
    $days=$tdpData['days']??[];$series=$tdpData['series']??[];
    if(!$days||!$series)return '<p class="chaine-empty">Pas encore assez d’historique sur cette période pour tracer la courbe d’évolution.</p>';
    $allVals=[];
    foreach($series as $s)foreach($s['points'] as $v)if($v!==null)$allVals[]=$v;
    if(!$allVals)return '<p class="chaine-empty">Aucune valeur de TdP disponible sur cette période.</p>';
    $minY=max(0,(int)floor((min($allVals)-2)/5)*5);
    $maxY=(int)ceil((max($allVals)+2)/5)*5;
    if($maxY<=$minY)$maxY=$minY+10;
    $w=860;$h=360;$padL=48;$padR=130;$padT=24;$padB=42;
    $plotW=$w-$padL-$padR;$plotH=$h-$padT-$padB;
    $nDays=count($days);
    $xOf=static fn(int $i):float=>$padL+($nDays>1?$i*($plotW/($nDays-1)):$plotW/2);
    $yOf=static fn(float $v):float=>$padT+$plotH-(($v-$minY)/($maxY-$minY))*$plotH;
    $palette=['#153e50','#985e29','#4f6536','#7e503a','#655975','#2b6cb0','#b7791f','#2f855a','#9b2c2c','#4a5568','#c05621','#2c7a7b'];
    $svg='<svg viewBox="0 0 '.$w.' '.$h.'" width="100%" height="'.$h.'" role="group" aria-label="Évolution des TdP des membres, détail par jour au survol">';
    $steps=5;
    for($s=0;$s<=$steps;$s++){
        $val=$minY+($maxY-$minY)*($s/$steps);
        $y=round($yOf($val),1);
        $svg.='<line x1="'.$padL.'" x2="'.($w-$padR).'" y1="'.$y.'" y2="'.$y.'" stroke="#e2e0d8" stroke-width="1"/>';
        $svg.='<text x="'.($padL-8).'" y="'.($y+4).'" text-anchor="end" font-size="11" fill="#52514e">'.(int)round($val).'</text>';
    }
    $stepX=max(1,(int)ceil($nDays/8));
    foreach($days as $i=>$dStr){
        if($i%$stepX!==0&&$i!==$nDays-1)continue;
        $x=round($xOf($i),1);
        $lbl=substr($dStr,8,2).'/'.substr($dStr,5,2);
        $svg.='<line x1="'.$x.'" x2="'.$x.'" y1="'.($padT+$plotH).'" y2="'.($padT+$plotH+5).'" stroke="#8c8a84"/>';
        $svg.='<text x="'.$x.'" y="'.($h-12).'" text-anchor="middle" font-size="11" fill="#52514e">'.e($lbl).'</text>';
    }
    // Many members share a TdP: names go in a per-day tooltip (assets/stats-tdp.js), not on the lines.
    $lastByValue=[];
    foreach($series as $idx=>$s){
        $color=$palette[$idx%count($palette)];
        $coords=[];$circles='';$lastPt=null;
        foreach($days as $i=>$dStr){
            $v=$s['points'][$dStr]??null;
            if($v===null)continue;
            $px=round($xOf($i),1);$py=round($yOf((float)$v),1);
            $coords[]=$px.','.$py;
            $circles.='<circle cx="'.$px.'" cy="'.$py.'" r="3.2" fill="'.$color.'"/>';
            $lastPt=[$px,$py,$v];
        }
        if(count($coords)>1){
            $svg.='<polyline fill="none" stroke="'.$color.'" stroke-width="2" stroke-opacity="0.82" points="'.implode(' ',$coords).'"/>';
        }
        $svg.=$circles;
        if($lastPt)$lastByValue[$lastPt[2]][]=['pseudo'=>$s['pseudo'],'color'=>$color];
    }
    // One label per final value: a member's name when alone, else the count; pushed apart when values are close.
    krsort($lastByValue);$labelY=-INF;
    foreach($lastByValue as $v=>$group){
        $text=count($group)===1?mb_strimwidth($group[0]['pseudo'],0,14,'…').' ('.$v.')':$v.' · '.count($group).' membres';
        $labelY=max(round($yOf((float)$v),1)+4,$labelY+12);
        $svg.='<text x="'.($w-$padR+8).'" y="'.$labelY.'" font-size="11" fill="'.(count($group)===1?$group[0]['color']:'#52514e').'" font-weight="600">'.e($text).'</text>';
    }
    $avgCoords=[];$hits='';
    $half=$nDays>1?$plotW/($nDays-1)/2:$plotW/2;
    foreach($days as $i=>$dStr){
        $byValue=[];
        foreach($series as $s)if(isset($s['points'][$dStr])&&$s['points'][$dStr]!==null)$byValue[$s['points'][$dStr]][]=$s['pseudo'];
        if(!$byValue)continue;
        krsort($byValue);
        $count=array_sum(array_map('count',$byValue));
        $mean=array_sum(array_map(static fn($v,$names)=>$v*count($names),array_keys($byValue),$byValue))/$count;
        $x=round($xOf($i),1);
        $avgCoords[]=$x.','.round($yOf($mean),1);
        $date=substr($dStr,8,2).'/'.substr($dStr,5,2).'/'.substr($dStr,0,4);$moyenne=number_format($mean,1,',','');
        $groups=[];$spoken=[];
        foreach($byValue as $v=>$names){natcasesort($names);$names=array_values($names);$groups[]=[$v,$names];$spoken[]=$v.' : '.implode(', ',$names);}
        $left=max($padL,$x-$half);$right=min($w-$padR,$x+$half);
        $hits.='<rect class="tdp-hit" tabindex="0" x="'.round($left,1).'" y="'.$padT.'" width="'.round($right-$left,1).'" height="'.$plotH.'" data-x="'.$x.'" data-tip="'.e(json_encode(['date'=>$date,'moyenne'=>$moyenne,'groupes'=>$groups],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)).'" aria-label="'.e($date.' · moyenne '.$moyenne.' · '.implode(' ; ',$spoken)).'"/>';
    }
    if(count($avgCoords)>1){
        $svg.='<polyline fill="none" stroke="#0b0b0b" stroke-width="2.5" stroke-dasharray="6 4" points="'.implode(' ',$avgCoords).'"/>';
    }
    $svg.='<line class="tdp-guide" x1="0" x2="0" y1="'.$padT.'" y2="'.($padT+$plotH).'"/>'.$hits.'</svg>';
    return '<div class="tdp-chart">'.$svg.'<div class="tdp-tooltip" hidden></div></div>';
}

workspaceStart($u,'Statistiques');
?>
<link rel="stylesheet" href="/assets/convoy-daily.css?v=9">
<link rel="stylesheet" href="/assets/ressources.css?v=11">
<section class="resources-page">
<h1>Statistiques</h1>
<p class="admin-intro">Analyses des ressources, convois, gains de l’alliance, temps de ponte (TdP) et activités sur la période choisie.</p>
<nav class="convoy-tabs" aria-label="Rubriques des statistiques">
<?php foreach($tabs as $value=>$label): ?>
<a href="/statistiques.php?vue=<?= e($value) ?>" <?= $value===$tab?'aria-current="page"':'' ?>><?= e($label) ?></a>
<?php endforeach; ?>
</nav>

<?php if($tab==='repartition'): ?>
<?php
// Materials only by default, as the Bilan matériaux: apples are opt-in.
$scope=($input('perimetre')==='tous')?'tous':'materiaux';
$consoLabel=static fn(string $k,string $label):string=>!str_starts_with($k,'conv_')?$label:($scope==='materiaux'?str_replace('Convois','Convois de matériaux',$label):$label.' (pommes + matériaux)');
$consoError=null;$conso=null;$pInfo=null;
try{
    $pInfo=convoyDailyPeriod($_GET,null,defined('CONSUMPTION_FIRST_DAY')?CONSUMPTION_FIRST_DAY:'2026-10-11');
    $conso=convoyConsumptionData(db(),db(true),$pInfo['start'],$pInfo['end_at'],$scope==='materiaux');
}catch(DomainException $ex){
    $consoError=$ex->getMessage();
    $pInfo=convoyDailyPeriod([],null,defined('CONSUMPTION_FIRST_DAY')?CONSUMPTION_FIRST_DAY:'2026-10-11');
}catch(Throwable $ex){
    error_log('UPTEP stats repartition: '.$ex->getMessage());
    $consoError='Le graphique de répartition est momentanément indisponible.';
    $pInfo=convoyDailyPeriod([],null,defined('CONSUMPTION_FIRST_DAY')?CONSUMPTION_FIRST_DAY:'2026-10-11');
}
$scopeSelect='<label>Convois pris en compte<select name="perimetre"><option value="materiaux"'.($scope==='materiaux'?' selected':'').'>Matériaux uniquement</option><option value="tous"'.($scope==='tous'?' selected':'').'>Pommes + Matériaux</option></select></label>';
?>
<section class="conso" aria-labelledby="conso-title">
<?php statsPeriodForm('repartition',$pInfo,$scope==='tous'?['perimetre'=>'tous']:[],$scopeSelect); ?>
<?php if($consoError!==null): ?><div class="form-error" role="alert"><?= e($consoError) ?></div>
<?php elseif($conso): ?>
<h2 id="conso-title">Consommation et répartition des membres : ce qui entre et ce qui sort (<?= e($conso['from']->format('d/m H\h')) ?> → <?= e($conso['end']->format('d/m H\h')) ?>)</h2>
<p id="conso-desc" class="conso-sub">Par jour. À droite ce que le membre gagne, à gauche ce qu’il cède ; trait noir et chiffre = conso.<?php if($conso['average']!==null): ?> Moyenne : <?= e(consumptionK($conso['average'])) ?>k/j.<?php endif; ?></p>
<?php if(!$conso['rows']): ?><p class="chaine-empty">Aucun relevé de terrain sur cette période.</p>
<?php else: ?>
<ul class="conso-legend" aria-label="Légende">
<?php foreach(CONSUMPTION_LABELS as $k=>$label): ?><li><svg width="14" height="14" aria-hidden="true"><rect width="14" height="14" rx="3" fill="<?= CONSUMPTION_COLORS[$k] ?>"/></svg><?= e($consoLabel($k,$label)) ?></li><?php endforeach; ?>
<li><svg width="14" height="14" aria-hidden="true"><rect x="5.5" width="3" height="14" fill="#0b0b0b"/></svg>Conso (= entrées − sorties)</li>
<li><svg width="18" height="14" aria-hidden="true"><line x1="0" x2="18" y1="7" y2="7" stroke="#52514e" stroke-width="1.5" stroke-dasharray="6 4"/></svg>Conso moyenne<?php if($conso['average']!==null): ?> (<?= e(consumptionK($conso['average'])) ?>k/j)<?php endif; ?></li>
</ul>
<div class="conso-scroll" tabindex="0" role="region" aria-label="Graphique de consommation, défilant horizontalement"><?= convoyConsumptionSvg($conso) ?></div>
<p class="daily-note">Nom · TdC moyen, greniers en gras. Production = 48 × TdC par jour (exports horaires Fourmizzz, plafond théorique : une ressource par cm² et par cycle de 30 min). Taxe de colonie : 20 % de la production de la colonie, + 1 point par niveau d’étable à pucerons du colonisateur quand il est connu<?= $conso['assumed_rates']?' (20 % sinon)':'' ?>. Convois déclarés (bot, site, UPTEP+), comptés à leur date de départ. <?= (int)$conso['exports'] ?> exports sur <?= e(number_format($conso['days'],1,',',' ')) ?> jours. Survole une ligne pour le détail.</p>
<details class="conso-table"><summary>Voir les chiffres détaillés</summary><div class="users-panel users-scroll"><table class="users-table"><thead><tr><th scope="col">Joueur</th><th scope="col">TdC moyen</th><?php foreach(CONSUMPTION_LABELS as $k=>$label): ?><th scope="col"><?= e($consoLabel($k,$label)) ?> / j</th><?php endforeach; ?><th scope="col">Conso / j</th></tr></thead><tbody>
<?php foreach($conso['rows'] as $r): ?><tr><th scope="row"><?= e($r['pseudo']) ?></th><td><?= convoyStatCompact((string)round($r['tdc'])) ?></td><?php foreach(array_keys(CONSUMPTION_LABELS) as $k): ?><td><?= convoyStatCompact((string)round($r[$k])) ?></td><?php endforeach; ?><td><strong><?= $r['conso']<0?'−':'' ?><?= convoyStatCompact((string)round(abs($r['conso']))) ?></strong></td></tr><?php endforeach; ?>
</tbody></table></div></details>
<?php endif; ?>
<?php endif; ?>
</section>

<?php elseif($tab==='convois'): ?>
<?php
$convError=null;$data=[];
try{
    $query=[];
    foreach(['ressource','sens','joueur','periode','debut','fin'] as $k)if(isset($_GET[$k]))$query[$k]=$_GET[$k];
    $data=convoyDailyData(db(),db(true),$query);
}catch(DomainException $ex){
    $convError=$ex->getMessage();
    $data=convoyDailyData(db(),db(true),[]);
}catch(Throwable $ex){
    error_log('UPTEP stats convois: '.$ex->getMessage());
    $convError='Les statistiques de convois sont momentanément indisponibles.';
}
if($convError!==null): ?><div class="form-error" role="alert"><?= e($convError) ?></div><?php endif; ?>
<?php if($data): require '/home/uptep/private/app/convoy-daily-view.php'; endif; ?>

<?php elseif($tab==='gains'): ?>
<?php
$gainsError=null;$gains=null;$pInfo=null;
try{
    $pInfo=convoyDailyPeriod($_GET,null,'2026-10-01');
    $gains=statsPeriodGains(db(true),$pInfo);
}catch(DomainException $ex){
    $gainsError=$ex->getMessage();
    $pInfo=convoyDailyPeriod([],null,'2026-10-01');
    $gains=statsPeriodGains(db(true),$pInfo);
}catch(Throwable $ex){
    error_log('UPTEP stats gains: '.$ex->getMessage());
}
?>
<?php if($gains===null): ?><?= $unavailable ?>
<?php else: $daysCount=(int)$gains['days_count']; ?>
<section class="convoy-daily" aria-labelledby="gains-title">
<h2 id="gains-title">Gains de l’alliance sur <?= $daysCount ?> jour<?= $daysCount>1?'s':'' ?></h2>
<?php statsPeriodForm('gains',$pInfo); ?>
<?php if($gainsError!==null): ?><div class="form-error" role="alert"><?= e($gainsError) ?> Affichage des 7 derniers jours.</div><?php endif; ?>
<p>Du <?= e($gains['start']) ?> au <?= e($gains['end']) ?> (<?= $daysCount ?> jour<?= $daysCount>1?'s':'' ?>) · heure de Paris (<?= (int)$gains['members_count'] ?> membres UPTEP actuels). <strong>Aujourd’hui est une journée en cours.</strong></p>
<p class="daily-note">Gains journaliers calculés par différence entre le dernier relevé Fourmizzz de chaque jour et celui de la veille pour l’ensemble des membres actuels de l’alliance. Survole une cellule pour voir la valeur exacte et le cumul atteint.</p>
<div class="users-panel users-scroll daily-scroll" tabindex="0" role="region" aria-label="Gains quotidiens de l’alliance">
<?php
$gainMetrics=[
    ['tdc','TdC gagné','cm²'],
    ['tdc_membre','TdC / membre','cm²/j'],
    ['fourmiliere','Fourmilière','pts'],
    ['techno','Technologie','pts'],
    ['trophee','Trophées','pts']
];
?>
<table class="users-table daily-table">
<caption class="daily-caption">Progression quotidienne UPTEP · <?= $daysCount ?> jour<?= $daysCount>1?'s':'' ?></caption>
<thead><tr><th scope="col">Indicateur</th><?php foreach($gains['days'] as $d): ?><th scope="col"><?= e($d['label']) ?><br><small><?= e($d['display']) ?></small></th><?php endforeach; ?><th scope="col">Total <?= $daysCount ?> j</th><th scope="col">Moyenne / jour</th></tr></thead>
<tbody class="daily-group">
<?php foreach($gainMetrics as $idx=>[$k,$label,$unit]):
    $sum=0.0;$any=false;
?>
<tr class="daily-row<?= $idx===0?' is-first':'' ?> daily-grand-total">
<th scope="row" class="daily-metric-name"><?= e($label) ?></th>
<?php foreach($gains['days'] as $d):
    $cell=$gains['alliance'][$d['date']]??null;
    $val=$cell[$k]??null;
    if($val!==null){$sum+=$val;$any=true;}
    $extra='';
    if($cell && $cell['covered']>0){
        if($k==='tdc')$extra='Total : '.number_format($cell['totals']['tdc'],0,',',' ').' cm²';
        elseif($k==='tdc_membre')$extra='Moyenne : '.number_format($cell['totals']['tdc']/$cell['covered'],0,',',' ').' cm²/membre';
        elseif(isset($cell['totals'][$k]))$extra='Total : '.number_format($cell['totals'][$k],0,',',' ').' pts';
    }
?>
<td class="daily-num"><?= statsSignedCompact($val,$extra) ?></td>
<?php endforeach; ?>
<td class="daily-num daily-total"><?= statsSignedCompact($any?$sum:null) ?></td>
<td class="daily-num daily-total"><?= statsSignedCompact($any?$sum/max(1,$daysCount):null) ?></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>

<h3 class="stats-subtitle stats-subtitle-spaced">Détail des gains par membre (<?= $daysCount ?> jour<?= $daysCount>1?'s':'' ?>)</h3>
<div class="users-panel users-scroll daily-scroll" tabindex="0" role="region" aria-label="Gains par membre">
<table class="users-table resource-table stats-table">
<thead><tr><th scope="col">Membre</th><?php foreach($gains['days'] as $d): ?><th scope="col">TdC <?= e($d['display']) ?></th><?php endforeach; ?><th scope="col">TdC (<?= $daysCount ?>j)</th><th scope="col">TdC / j</th><th scope="col">Fourmilière (<?= $daysCount ?>j)</th><th scope="col">Techno (<?= $daysCount ?>j)</th><th scope="col">Trophées (<?= $daysCount ?>j)</th></tr></thead>
<tbody>
<?php foreach($gains['players'] as $p): $s=$p['sums']; ?>
<tr>
<th scope="row"><?= e($p['pseudo']) ?></th>
<?php foreach($gains['days'] as $d): ?>
<td class="daily-num"><?= statsSignedCompact($p['days'][$d['date']]['tdc']) ?></td>
<?php endforeach; ?>
<td class="daily-num"><strong><?= statsSignedCompact($s['any']?$s['tdc']:null) ?></strong></td>
<td class="daily-num"><?= statsSignedCompact($s['any']?$s['tdc']/max(1,$daysCount):null) ?></td>
<td class="daily-num"><?= statsSignedCompact($s['any']?$s['fourmiliere']:null) ?></td>
<td class="daily-num"><?= statsSignedCompact($s['any']?$s['techno']:null) ?></td>
<td class="daily-num"><?= statsSignedCompact($s['any']?$s['trophee']:null) ?></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
</section>
<?php endif; ?>

<?php elseif($tab==='tdp'): ?>
<?php
$tdpError=null;$tdpData=null;$pInfo=null;
try{
    $pInfo=convoyDailyPeriod($_GET,null,'2026-10-01');
    $tdpData=statsTdpData(db(),db(true),$pInfo);
}catch(DomainException $ex){
    $tdpError=$ex->getMessage();
    $pInfo=convoyDailyPeriod([],null,'2026-10-01');
    $tdpData=statsTdpData(db(),db(true),$pInfo);
}catch(Throwable $ex){
    error_log('UPTEP stats tdp: '.$ex->getMessage());
}
?>
<?php if($tdpData===null): ?><?= $unavailable ?>
<?php else: ?>
<h2 class="stats-title">Temps de ponte (TdP) de l’alliance</h2>
<?php statsPeriodForm('tdp',$pInfo); ?>
<?php if($tdpError!==null): ?><div class="form-error" role="alert"><?= e($tdpError) ?> Affichage des 7 derniers jours.</div><?php endif; ?>
<div class="resource-summary">
<div><span>Top TdP</span><strong><?= $tdpData['top']!==null?(int)$tdpData['top']['value']:'—' ?></strong><small><?= $tdpData['top']?e(implode(', ',$tdpData['top']['names'])):'Aucun relevé' ?></small></div>
<div><span>TdP moyen</span><strong><?= $tdpData['avg']!==null?e(number_format($tdpData['avg'],1,',',' ')):'—' ?></strong><small><?= $tdpData['median']!==null?'Médiane : '.e(number_format($tdpData['median'],1,',',' ')):'—' ?></small></div>
<div><span>Plus petit TdP</span><strong><?= $tdpData['min']!==null?(int)$tdpData['min']['value']:'—' ?></strong><small><?= $tdpData['min']?e(implode(', ',$tdpData['min']['names'])):'Aucun relevé' ?></small></div>
<div><span>Membres relevés</span><strong><?= (int)$tdpData['covered_count'] ?> / <?= (int)$tdpData['total_members'] ?></strong><small>Solarium + Couveuse + Ponte</small></div>
</div>

<section class="users-panel stats-panel-spaced">
<div class="users-panel-heading"><h3>Évolution des TdP (du <?= e($pInfo['start']->format('d/m/Y')) ?> au <?= e($pInfo['end_day']->format('d/m/Y')) ?>)</h3><span class="user-count">Courbe noire pointillée = moyenne alliance</span></div>
<div class="conso-scroll" tabindex="0" role="region" aria-label="Graphique d’évolution des TdP"><?= statsTdpSvg($tdpData) ?></div>
<p class="daily-note">Historique reconstitué à partir des synchronisations de bâtiments (Solarium, Couveuse) et de laboratoire (Technique de ponte) enregistrées dans <code>member_stat_history</code>. Survole le graphique pour voir, jour par jour, les membres regroupés par TdP.</p>
</section>

<section class="users-panel">
<div class="users-panel-heading"><h3>Détail des TdP par membre</h3><span class="user-count"><?= count($tdpData['rows']) ?> membres</span></div>
<div class="users-scroll" tabindex="0" role="region" aria-label="Tableau des TdP par membre">
<table class="users-table resource-table stats-table">
<thead><tr><th scope="col">Membre</th><th scope="col">TdP total</th><th scope="col">Solarium</th><th scope="col">Couveuse</th><th scope="col">Technique de ponte</th><th scope="col">Dernier relevé</th></tr></thead>
<tbody>
<?php foreach($tdpData['rows'] as $r): ?>
<tr>
<th scope="row"><?= e($r['pseudo']) ?></th>
<td><strong><?= $r['tdp']!==null?(int)$r['tdp']:'—' ?></strong></td>
<td><?= $r['solarium']!==null?(int)$r['solarium']:'—' ?></td>
<td><?= $r['couveuse']!==null?(int)$r['couveuse']:'—' ?></td>
<td><?= $r['technique_ponte']!==null?(int)$r['technique_ponte']:'—' ?></td>
<td><?= $r['at']?e($date(new DateTimeImmutable($r['at']),'d/m/Y H:i')):'—' ?></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
</section>
<?php endif; ?>

<?php elseif($tab==='bilan' || $tab==='activite'): ?>
<?php
$sort=is_string($_GET['tri']??null)&&in_array($_GET['tri'],RESOURCE_BALANCE_SORTS,true)?$_GET['tri']:'ecart';
$asc=($_GET['ordre']??null)==='asc';
$rangeError=null;$pInfo=null;
try {
    $pInfo=convoyDailyPeriod($_GET,null,RESOURCE_BALANCE_START);
}catch(DomainException $ex) {
    $rangeError=$ex->getMessage();
    $pInfo=convoyDailyPeriod([],null,RESOURCE_BALANCE_START);
}
$from=$pInfo['start'];$end=$pInfo['end_at'];
$data=null;
try {$data=resourceBalanceData(db(),db(true),$from,$end);$data['rows']=resourceBalanceSort($data['rows'],$sort,$asc);}
catch(Throwable $ex) {error_log('UPTEP resources: '.$ex->getMessage());http_response_code(503);}
$floods=$hunts=null;
if($tab==='activite' && $data!==null){
    $memberNames=array_column($data['rows'],'pseudo','id');
    try {$floods=resourceFloodStats(db(),$memberNames,$from,$end);} catch(Throwable $ex) {error_log('UPTEP stats floods: '.$ex->getMessage());}
    try {$hunts=resourceHuntStats(db(),$memberNames,$from,$end);} catch(Throwable $ex) {error_log('UPTEP stats hunts: '.$ex->getMessage());}
}
$period=['vue'=>$tab,'debut'=>$pInfo['start']->format('Y-m-d'),'fin'=>$pInfo['end_day']->format('Y-m-d')];
$th=static function(string $key,string $label)use($sort,$asc,$period):string{
    $active=$key===$sort;$next=$active?($asc?'desc':'asc'):($key==='pseudo'?'asc':'desc');
    $aria=$active?' aria-sort="'.($asc?'ascending':'descending').'"':'';
    return '<th scope="col"'.$aria.'><a class="resource-sort" href="?'.e(http_build_query($period+['tri'=>$key,'ordre'=>$next])).'">'.$label.'<span aria-hidden="true">'.($active?($asc?' ↑':' ↓'):' ↕').'</span></a></th>';
};
$verdicts=['convoyer'=>'Convoyer','recevoir'=>'Recevoir','equilibre'=>'Équilibré','aucun'=>'—'];
$now=new DateTimeImmutable();
$ago=static function(DateTimeImmutable $at)use($now):string{
    $s=max(0,$now->getTimestamp()-$at->getTimestamp());
    return 'il y a '.($s<3600?max(1,intdiv($s,60)).' min':($s<172800?intdiv($s,3600).' h':intdiv($s,86400).' j'));
};
$stock=static function(array $r)use($ago,$date):string{
    if($r['stock']===null){
        $why=$r['releve']?'Dernier relevé le '.$date($r['releve']['at']).' : plus de 48 h.':'Aucun relevé de matériaux UPTEP+.';
        return '<td title="'.e($why).'">—<span class="resource-age">'.e($r['releve']?$ago($r['releve']['at']):'aucun relevé').'</span></td>';
    }
    $tip='Relevé du '.$date($r['releve']['at']).' : '.resourceNumber($r['releve']['materiaux'])
        ."\n+ production : ".resourceNumber($r['stock_production']).' ('.$r['stock_cycles'].' cycle'.($r['stock_cycles']>1?'s':'').', colonies comprises)'
        ."\n− convois envoyés : ".resourceNumber($r['stock_envoye'])
        ."\n+ convois reçus : ".resourceNumber($r['stock_recu'])
        ."\n= ".resourceNumber($r['stock']).' estimés';
    return '<td title="'.e($tip).'">'.e(resourceCompact(round($r['stock']))).'<span class="resource-age">'.e($ago($r['releve']['at'])).'</span></td>';
};
statsPeriodForm($tab,$pInfo,($sort!=='ecart'||$asc)?['tri'=>$sort,'ordre'=>$asc?'asc':'desc']:[]);
?>
<?php if($rangeError!==null): ?><p class="form-error" role="alert"><?= e($rangeError) ?> Affichage des 7 derniers jours.</p><?php endif; ?>

<?php if($data===null): ?><p class="form-error" role="alert">Les données sont indisponibles pour le moment. Réessaie dans quelques instants.</p>
<?php elseif(!$data['rows']): ?><p>Aucun membre UPTEP dans le dernier relevé.</p>
<?php elseif($tab==='bilan'): $members=count($data['rows']); ?>
<h2 id="ressources" class="stats-title">Bilan matériaux</h2>
<div class="resource-note"><strong>Méthode</strong>
<p>Période du <?= e($date($data['start'])) ?> au <?= e($date($data['end']->setTimezone($paris)->format('H:i')==='00:00'?$data['end']->modify('-1 minute'):$data['end'])) ?> (Paris). Les convois déclarés commencent le <?= e($date(new DateTimeImmutable(RESOURCE_BALANCE_START,$paris),'d/m/Y')) ?>. Chaque cycle de 30 minutes produit 1 matériau par cm² de terrain, d’après le dernier relevé horaire. La production d’un cycle est additionnée pour l’alliance puis partagée à parts égales entre les membres présents à ce moment. Les anciens membres ne sont pas comptés.</p>
<p>Une colonie verse à son colonisateur 20 % + 1 point par niveau d’étable à pucerons de celui-ci (100 % au plus), lorsque les deux sont membres.<?= $data['assumed_rates']?' Étable inconnue pour au moins un colonisateur : 20 % retenus.':'' ?> Net = production + colonies − matériaux envoyés + matériaux reçus, sur la période. Écart = net − part : positif, le membre a gardé plus que sa part et doit convoyer ; négatif, il doit recevoir. Équilibré à ±<?= (int)(RESOURCE_BALANCE_TOLERANCE*100) ?> % de la part.</p>
<p>Entrepôt estimé : dernier relevé de matériaux UPTEP+ de moins de 48 h, plus la production de chaque cycle depuis (colonies comprises), moins les convois envoyés depuis (au départ), plus les convois reçus depuis (à l’arrivée).</p></div>
<div class="resource-summary">
<div><span>Production de l’alliance</span><strong><?= $amount($data['total']) ?></strong><small>matériaux · <?= e(resourceNumber($data['cycles'])) ?> cycles</small></div>
<div><span><?= $data['end']<new DateTimeImmutable('-1 hour')?'Production / heure en fin de période':'Production actuelle / heure' ?></span><strong><?= $amount($data['current']) ?></strong><small><?= $amount($data['current']/$members) ?> par membre</small></div>
<div><span>Convois de matériaux</span><strong><?= $amount($data['materials']) ?></strong><small><?= e(resourceNumber($data['convoys'])) ?> convois déclarés</small></div>
<div><span>Membres · colonies</span><strong><?= $members ?> · <?= (int)$data['colonies'] ?></strong><small>Du <?= e($date($data['start'],'d/m')) ?> au <?= e($date($data['end']->modify('-1 second'),'d/m')) ?></small></div>
</div>
<section class="users-panel"><div class="users-panel-heading"><h3>Répartition par membre</h3><span class="user-count"><?= $members ?> membres</span></div>
<div class="users-scroll" tabindex="0" role="region" aria-label="Répartition des matériaux par membre"><table class="users-table resource-table"><thead><tr>
<?= $th('pseudo','Membre') ?><?= $th('terrain','Terrain<br>actuel') ?><?= $th('stock','Entrepôt<br>estimé') ?><?= $th('production','Production') ?><?= $th('colonie','Colonies') ?><?= $th('envoye','Envoyés') ?><?= $th('recu','Reçus') ?><?= $th('ecart','Écart') ?><?= $th('ratio','Ratio') ?><th scope="col">Verdict</th>
</tr></thead><tbody>
<?php foreach($data['rows'] as $r): ?>
<tr class="resource-<?= e($r['verdict']) ?>"><th scope="row"><?= e($r['pseudo']) ?></th>
<td><?= $amount($r['terrain']) ?></td>
<?= $stock($r) ?>
<td><?= $amount($r['production']) ?></td>
<td title="<?= e('Reçu : '.resourceNumber($r['taxe_recue']).' · Versé : '.resourceNumber($r['taxe_payee'])) ?>"><?= e(($r['colonie']>=500?'+':'').resourceCompact(round($r['colonie']))) ?></td>
<td><?= $amount($r['envoye']) ?></td>
<td><?= $amount($r['recu']) ?></td>
<td class="resource-gap" title="<?= e('Net '.resourceNumber($r['net']).' − part '.resourceNumber($r['part']).' = '.resourceNumber($r['ecart'])) ?>"><?= e(($r['ecart']>=500?'+':'').resourceCompact(round($r['ecart']))) ?></td>
<td><?= $r['ratio']===null?'—':e(number_format($r['ratio']*100,0,',',' ').' %') ?></td>
<td><span class="resource-verdict"><?= e($verdicts[$r['verdict']]) ?></span></td></tr>
<?php endforeach; ?></tbody></table></div></section>
<p class="resource-legend">Envoyés et reçus : convois déclarés non annulés, matériaux uniquement, à leur date de départ.<?= $data['outside']>0?' Dont '.$amount($data['outside']).' échangés avec d’anciens membres : comptés seulement du côté du membre actuel.':'' ?> Survole les colonnes Entrepôt estimé, Colonies et Écart (net et part) pour le détail.</p>

<?php else: $members=count($data['rows']); $memberNames=array_column($data['rows'],'pseudo','id'); ?>
<h2 id="flood" class="stats-title">Flood</h2>
<?php if($floods===null): ?><?= $unavailable ?>
<?php else: $ft=$floods['total']; ?>
<div class="resource-summary">
<div><span>Floods arrivés</span><strong><?= $count($ft['floods']) ?></strong><small><?= $ft['loge']>0?e($ft['loge'].' hors terrain de chasse'):'tous sur le terrain de chasse' ?></small></div>
<div><span>Troupes envoyées</span><strong><?= $amount($ft['troupes']) ?></strong><small><?= $ft['floods']>$ft['inconnues']?$amount($ft['troupes']/($ft['floods']-$ft['inconnues'])).' par flood':'—' ?></small></div>
<div><span>Troupes inconnues</span><strong><?= $count($ft['inconnues']) ?></strong><small>floods déclarés sans troupes lisibles</small></div>
<div><span>En route maintenant</span><strong><?= $count($ft['en_route']) ?></strong><small>floods pas encore arrivés</small></div>
</div>
<section class="users-panel"><div class="users-panel-heading"><h3>Floods par membre</h3><span class="user-count"><?= count($floods['rows']) ?> membres</span></div>
<div class="users-scroll" tabindex="0" role="region" aria-label="Floods par membre"><table class="users-table resource-table stats-table"><thead><tr><th scope="col">Membre</th><th scope="col">Envoyés</th><th scope="col">Reçus</th><th scope="col">Dernier<br>envoyé</th></tr></thead><tbody>
<?php foreach($floods['rows'] as $r): ?><tr><th scope="row"><?= e($r['pseudo']) ?></th><td><?= $count($r['envoyes']) ?></td><td><?= $count($r['recus']) ?></td><td><?= $r['dernier']?e($date($r['dernier'],'d/m H\hi')):'—' ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<p class="resource-legend">Floods déclarés (bot Discord et UPTEP+), comptés à leur heure d’arrivée sur la période.</p>
<?php endif; ?>

<h2 id="chasse" class="stats-title">Chasse</h2>
<?php if($hunts===null): ?><?= $unavailable ?>
<?php else: $ht=$hunts['total']; ?>
<div class="resource-summary">
<div><span>Chasses terminées</span><strong><?= $count($ht['chasses']) ?></strong><small>arrivées sur la période</small></div>
<div><span>Terrain chassé</span><strong><?= $amount($ht['terrain']) ?></strong><small><?= $ht['chasses']>0?$amount($ht['terrain']/$ht['chasses']).' cm² par chasse':'cm²' ?></small></div>
<div><span>En cours maintenant</span><strong><?= $count($ht['en_cours']) ?></strong><small><?= $amount($ht['terrain_en_cours']) ?> cm² attendus</small></div>
<div><span>Membres suivis</span><strong><?= (int)$ht['suivis'] ?> / <?= $members ?></strong><small>chasses relevées par UPTEP+ ou Discord</small></div>
</div>
<?php if($hunts['rows']): ?>
<section class="users-panel"><div class="users-panel-heading"><h3>Chasses par membre</h3><span class="user-count"><?= count($hunts['rows']) ?> membres suivis</span></div>
<div class="users-scroll" tabindex="0" role="region" aria-label="Chasses par membre"><table class="users-table resource-table stats-table"><thead><tr><th scope="col">Membre</th><th scope="col">Chasses</th><th scope="col">Terrain<br>chassé</th><th scope="col">Moyenne<br>par chasse</th><th scope="col">Dernière<br>arrivée</th><th scope="col">En cours</th></tr></thead><tbody>
<?php foreach($hunts['rows'] as $r): ?><tr><th scope="row"><?= e($r['pseudo']) ?></th><td><?= $count($r['chasses']) ?></td><td><?= $amount($r['terrain']) ?></td><td><?= $r['moyenne']===null?'—':$amount($r['moyenne']) ?></td><td><?= $r['derniere']?e($date($r['derniere'],'d/m H\hi')):'—' ?></td><td><?= $r['en_cours']>0?$count($r['en_cours']).' · '.$amount($r['terrain_en_cours']):'—' ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<?php endif; ?>
<?php $missing=array_diff_key($memberNames,array_flip(array_column($hunts['rows'],'id'))); ?>
<p class="resource-legend">Chasses non annulées, comptées à leur heure d’arrivée sur la période ; « En cours » est l’état actuel. Terrain en cm².<?php if($missing): ?> Sans chasse relevée : <?= e(implode(', ',array_map('strval',$missing))) ?>.<?php endif; ?></p>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>
</section>
<script src="/assets/convoy-tables.js?v=1" defer></script>
<script src="/assets/stats-tdp.js?v=1" defer></script>
<?php workspaceEnd(); ?>