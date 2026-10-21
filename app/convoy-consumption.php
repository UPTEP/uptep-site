<?php
declare(strict_types=1);
require_once __DIR__.'/script-convoy-colonies.php';

/* Member consumption over a date range: what comes in (TdC production, colony tax received,
 * convoys received) and what goes out (convoys delivered, colony tax paid), per day.
 * Production = 48 × TdC per day (one resource per cm² per 30-minute cycle), from the hourly
 * Fourmizzz exports. Colony tax = 20 % + 1 point per aphid stable level of the colonizer when
 * it is known (capped at 100 %), else 20 %. Convoys are the declared ones, by departure date. */

const CONSUMPTION_FIRST_DAY='2026-10-01';
const CONSUMPTION_MAX_DAYS=92;

/** Reads the conso_debut / conso_fin date inputs (Y-m-d, Paris). Default: the last 7 days up to now. */
function convoyConsumptionRange(array $get,?DateTimeImmutable $now=null):array{
    $zone=new DateTimeZone('Europe/Paris');
    $now=($now??new DateTimeImmutable())->setTimezone($zone);
    $day=static function($value)use($zone):?DateTimeImmutable{
        if(!is_string($value)||$value==='')return null;
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$value,$zone);
        if(!$d||$d->format('Y-m-d')!==$value)throw new DomainException('Date invalide : utilise le sélecteur de dates.',422);
        return $d;
    };
    $from=$day($get['conso_debut']??null)??$now->setTime(0,0)->modify('-7 days');
    $to=$day($get['conso_fin']??null);
    $end=$to?$to->modify('+1 day'):$now;
    if($end>$now)$end=$now;
    if($from<new DateTimeImmutable(CONSUMPTION_FIRST_DAY,$zone))throw new DomainException('Les relevés commencent le '.(new DateTimeImmutable(CONSUMPTION_FIRST_DAY))->format('d/m/Y').'.',422);
    if($from>=$end)throw new DomainException('La date de début doit précéder la date de fin.',422);
    if($end->getTimestamp()-$from->getTimestamp()>CONSUMPTION_MAX_DAYS*86400)throw new DomainException('La plage est limitée à '.CONSUMPTION_MAX_DAYS.' jours.',422);
    return [$from,$end];
}

function convoyConsumptionData(PDO $p,PDO $game,DateTimeImmutable $from,DateTimeImmutable $end):array{
    $members=[];
    foreach($game->query("SELECT player_id::text,pseudo FROM players WHERE server='s5' AND upper(alliance_tag)='UPTEP' AND is_present=true") as $r)
        $members[$r['player_id']]=['id'=>$r['player_id'],'pseudo'=>$r['pseudo'],'grade'=>null,'snapshots'=>0,'field'=>0.0,'tax_in'=>0.0,'tax_out'=>0.0,'conv_in'=>0.0,'conv_out'=>0.0];
    if(!$members)return ['rows'=>[],'from'=>$from,'end'=>$end,'days'=>0,'exports'=>0,'average'=>null,'assumed_rates'=>false];
    foreach($p->query("SELECT player_id::text,grade FROM chaine_members WHERE server='s5'") as $r)if(isset($members[$r['player_id']]))$members[$r['player_id']]['grade']=$r['grade'];
    $levels=scriptConvoyCurrentColonyLevels($p);$assumed=false;
    $rate=static function(string $master)use($levels,$members,&$assumed):int{
        $level=$levels[$master]??null;
        if(isset($members[$master])&&is_string($level)&&preg_match('/\A[0-9]{1,3}\z/',$level))return min(100,20+(int)$level);
        $assumed=true;return 20;
    };
    $ids=array_keys($members);$marks=implode(',',array_fill(0,count($ids),'?'));
    $range=[$from->format('c'),$end->format('c')];
    // One snapshot per export version (latest corrected import), as in the daily statistics.
    $q=$game->prepare("WITH exports AS (
        SELECT DISTINCT ON (export_version) id FROM historique_exports
        WHERE server='s5' AND export_type='players'
          AND exported_at>=(?::timestamptz AT TIME ZONE 'UTC') AND exported_at<(?::timestamptz AT TIME ZONE 'UTC')
        ORDER BY export_version,id DESC)
      SELECT h.export_id,h.player_id::text,h.field::text,h.master_player_id::text
      FROM historique_players h JOIN exports e ON e.id=h.export_id
      WHERE h.player_id IN ($marks) OR h.master_player_id IN ($marks)");
    $q->execute(array_merge($range,$ids,$ids));
    $exports=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
        $exports[$r['export_id']]=true;$field=(float)($r['field']??0);$id=$r['player_id'];$master=(string)($r['master_player_id']??'');
        $colonized=$master!==''&&$master!=='0'&&$master!==$id;
        if(isset($members[$id])){
            $members[$id]['snapshots']++;$members[$id]['field']+=$field;
            if($colonized)$members[$id]['tax_out']+=$field*$rate($master)/100;
        }
        if($colonized&&isset($members[$master]))$members[$master]['tax_in']+=$field*$rate($master)/100;
    }
    $q=$p->prepare("SELECT c.idenvoyeur::text AS sender,d.iddemandeur::text AS receiver,(c.nbpomme+c.nbmateriaux)::numeric AS amount
        FROM convois_effectues c JOIN demandes_convois d ON d.id=c.iddemande
        WHERE d.serveur='s5' AND NOT c.annule AND c.date_convoi>=?::timestamptz AND c.date_convoi<?::timestamptz");
    $q->execute($range);
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
        if(isset($members[$r['sender']]))$members[$r['sender']]['conv_out']+=(float)$r['amount'];
        if(isset($members[$r['receiver']]))$members[$r['receiver']]['conv_in']+=(float)$r['amount'];
    }
    $days=($end->getTimestamp()-$from->getTimestamp())/86400;$rows=[];
    foreach($members as $m){
        if(!$m['snapshots'])continue;// No export for this player in the range: no production basis.
        $n=$m['snapshots'];
        $row=['id'=>$m['id'],'pseudo'=>$m['pseudo'],'grenier'=>$m['grade']==='Grenier','tdc'=>$m['field']/$n,
            'production'=>48*$m['field']/$n,'tax_in'=>48*$m['tax_in']/$n,'conv_in'=>$m['conv_in']/$days,
            'conv_out'=>$m['conv_out']/$days,'tax_out'=>48*$m['tax_out']/$n];
        $row['in']=$row['production']+$row['tax_in']+$row['conv_in'];$row['out']=$row['conv_out']+$row['tax_out'];
        $row['conso']=$row['in']-$row['out'];$rows[]=$row;
    }
    usort($rows,static fn($a,$b)=>$b['conso']<=>$a['conso']?:strnatcasecmp($a['pseudo'],$b['pseudo']));
    $average=$rows?array_sum(array_column($rows,'conso'))/count($rows):null;
    return ['rows'=>$rows,'from'=>$from,'end'=>$end,'days'=>$days,'exports'=>count($exports),'average'=>$average,'assumed_rates'=>$assumed];
}

/** Thousands of resources, as on the axis ("1342"). */
function consumptionK(float $v):string{return number_format($v/1000,0,',',' ');}
function consumptionExact(float $v):string{return number_format($v,0,',',' ');}

const CONSUMPTION_COLORS=['production'=>'#2a78d6','tax_in'=>'#eda100','conv_in'=>'#1baf7a','conv_out'=>'#eb6834','tax_out'=>'#6250d6'];
const CONSUMPTION_LABELS=['production'=>'Production (TdC)','tax_in'=>'Taxe reçue (colonie)','conv_in'=>'Convois reçus','conv_out'=>'Convois livrés','tax_out'=>'Taxe payée (colonisé)'];

/** Horizontal bar segment; only the outer data-end is rounded (4px), the baseline stays square. */
function consumptionBar(float $a,float $b,float $y,float $h,string $fill,bool $round):string{
    $x0=min($a,$b);$x1=max($a,$b);$w=$x1-$x0;if($w<0.5)return '';
    $r=$round?min(4,$w/2,$h/2):0;$f=static fn($n)=>number_format($n,1,'.','');
    if(!$r)return '<rect x="'.$f($x0).'" y="'.$f($y).'" width="'.$f($w).'" height="'.$f($h).'" fill="'.$fill.'"/>';
    if($b>=$a)$d='M'.$f($x0).','.$f($y).'H'.$f($x1-$r).'Q'.$f($x1).','.$f($y).' '.$f($x1).','.$f($y+$r).'V'.$f($y+$h-$r).'Q'.$f($x1).','.$f($y+$h).' '.$f($x1-$r).','.$f($y+$h).'H'.$f($x0).'Z';
    else $d='M'.$f($x1).','.$f($y).'H'.$f($x0+$r).'Q'.$f($x0).','.$f($y).' '.$f($x0).','.$f($y+$r).'V'.$f($y+$h-$r).'Q'.$f($x0).','.$f($y+$h).' '.$f($x0+$r).','.$f($y+$h).'H'.$f($x1).'Z';
    return '<path d="'.$d.'" fill="'.$fill.'"/>';
}

/** Server-rendered SVG: presentation attributes only, so it renders under the site's strict CSP. */
function convoyConsumptionSvg(array $data):string{
    $rows=$data['rows'];if(!$rows)return '';
    $W=1100;$labelRight=196;$x0=212;$x1=$W-70;$rowH=26;$barH=16;$top=8;
    $maxIn=max(1,max(array_column($rows,'in')));$maxOut=max(0,max(array_column($rows,'out')));
    $raw=($maxIn+$maxOut)/1000/6;$mag=10**floor(log10(max($raw,1)));$step=$mag*(($raw/$mag)<=1.5?1:(($raw/$mag)<=3?2:(($raw/$mag)<=7?5:10)));
    $lo=-ceil($maxOut/1000/$step)*$step;$hi=ceil($maxIn/1000/$step)*$step;
    $scale=($x1-$x0)/max(1,$hi-$lo);$X=static fn(float $v)=>$x0+($v/1000-$lo)*$scale;$zero=$X(0);
    $plotBottom=$top+count($rows)*$rowH;$H=$plotBottom+56;
    $s='<svg class="conso-chart" viewBox="0 0 '.$W.' '.$H.'" role="img" aria-labelledby="conso-title conso-desc" xmlns="http://www.w3.org/2000/svg" font-family="inherit">';
    foreach($rows as $i=>$r)if($i%2===1)$s.='<rect x="0" y="'.($top+$i*$rowH).'" width="'.$W.'" height="'.$rowH.'" fill="#f3f2ee"/>';
    // Recessive hairline grid and tick labels (absolute values: the left side is what goes out).
    for($t=$lo;$t<=$hi+1e-9;$t+=$step){$x=number_format($X($t*1000),1,'.','');
        $s.='<line x1="'.$x.'" x2="'.$x.'" y1="'.$top.'" y2="'.$plotBottom.'" stroke="#e1e0d9" stroke-width="1"/>';
        $s.='<text x="'.$x.'" y="'.($plotBottom+16).'" text-anchor="middle" font-size="12" fill="#898781">'.e(number_format(abs($t),0,',',' ')).'</text>';}
    $s.='<line x1="'.number_format($zero,1,'.','').'" x2="'.number_format($zero,1,'.','').'" y1="'.$top.'" y2="'.$plotBottom.'" stroke="#52514e" stroke-width="1"/>';
    $s.='<text x="'.number_format($zero,1,'.','').'" y="'.($plotBottom+32).'" text-anchor="middle" font-size="11" fill="#52514e">← sort   entre →</text>';
    $s.='<text x="'.(($x0+$x1)/2).'" y="'.($plotBottom+50).'" text-anchor="middle" font-size="12" fill="#52514e">milliers de ressources par jour</text>';
    foreach($rows as $i=>$r){
        $y=$top+$i*$rowH+($rowH-$barH)/2;$cy=$top+$i*$rowH+$rowH/2;
        $tip=$r['pseudo'].' · TdC moyen '.consumptionExact($r['tdc'])."\n";
        foreach(['production','tax_in','conv_in','conv_out','tax_out'] as $k)$tip.=CONSUMPTION_LABELS[$k].' : '.consumptionExact($r[$k])." / jour\n";
        $tip.='Conso (entrées − sorties) : '.consumptionExact($r['conso']).' / jour';
        $s.='<g class="conso-row"><title>'.e($tip).'</title><rect x="0" y="'.($top+$i*$rowH).'" width="'.$W.'" height="'.$rowH.'" fill="transparent"/>';
        $s.='<text x="'.$labelRight.'" y="'.($cy+4).'" text-anchor="end" font-size="13" fill="#0b0b0b"'.($r['grenier']?' font-weight="700"':'').'>'.e($r['pseudo'].' · '.hcConsumptionCompact($r['tdc'])).'</text>';
        // Stacks grow from the zero baseline; a 2px surface gap separates touching segments.
        foreach([['production','tax_in','conv_in',1],['conv_out','tax_out',-1]] as $stack){
            $dir=array_pop($stack);$keys=array_values(array_filter($stack,static fn($k)=>$r[$k]>0));$at=0.0;
            foreach($keys as $j=>$k){$next=$at+$r[$k];$last=$j===count($keys)-1;
                $a=$X($dir*$at);$b=$X($dir*$next);if(!$last)$b-=$dir*2;
                $s.=consumptionBar($a,$b,$y,$barH,CONSUMPTION_COLORS[$k],$last);$at=$next;}
        }
        $cx=$X($r['conso']);$s.='<rect x="'.number_format($cx-1.5,1,'.','').'" y="'.($y-3).'" width="3" height="'.($barH+6).'" fill="#0b0b0b"/>';
        $s.='<text x="'.number_format(max($X($r['in']),$cx)+7,1,'.','').'" y="'.($cy+4).'" font-size="12" font-weight="700" fill="#0b0b0b">'.e(consumptionK($r['conso'])).'</text></g>';
    }
    if($data['average']!==null){$ax=number_format($X($data['average']),1,'.','');
        $s.='<line x1="'.$ax.'" x2="'.$ax.'" y1="'.$top.'" y2="'.$plotBottom.'" stroke="#52514e" stroke-width="1.5" stroke-dasharray="6 4"><title>Conso moyenne : '.e(consumptionExact($data['average'])).' / jour</title></line>';}
    return $s.'</svg>';
}
function hcConsumptionCompact(float $v):string{
    if($v<1000)return (string)round($v);
    $units=['','k','M','G','T'];$scale=min(4,(int)floor(log10($v)/3));$n=round($v/(1000**$scale),max(0,3-strlen((string)(int)($v/(1000**$scale)))));
    if($n>=1000&&$scale<4){$scale++;$n=round($v/(1000**$scale),2);}
    $t=number_format($n,max(0,3-strlen((string)(int)$n)),',','');if(str_contains($t,','))$t=rtrim(rtrim($t,'0'),',');return $t.$units[$scale];
}
