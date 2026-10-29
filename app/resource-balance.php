<?php
declare(strict_types=1);

/* Alliance materials balance: what each current UPTEP member produced, the equal share of the
 * alliance production they would get, and the materials convoys between members.
 * Production = 1 material per cm² of hunting field per 30-minute cycle (:00 and :30); each cycle
 * uses the latest hourly Fourmizzz export at or before it. A cycle's alliance total is split
 * equally between the current members carrying the UPTEP tag in that export: departed players
 * are left out entirely. A colony pays its colonizer 20 % + 1 point per aphid stable level of the
 * colonizer (current level, 20 % when unknown, capped at 100 %) when both are counted members in
 * that export, as in the convoy estimates: an internal transfer, so the alliance total is unchanged.
 * Convoys are the declared, non-cancelled ones (materials only), counted at their departure date.
 * Net = production − colony tax paid + colony tax received − sent + received; gap = net − share. */

require_once __DIR__.'/script-convoy-colonies.php';

const RESOURCE_BALANCE_START='2026-10-11'; // Paris. The first declared convoy dates from 11/10 14:56.
const RESOURCE_BALANCE_CYCLE=1800;
const RESOURCE_BALANCE_TOLERANCE=0.05; // Within ±5 % of the share: balanced.
const RESOURCE_BALANCE_SORTS=['pseudo','entree','terrain','production','colonie','part','envoye','recu','net','ecart','ratio'];

function resourceBalanceData(PDO $p,PDO $game,?DateTimeImmutable $now=null):array{
    $start=new DateTimeImmutable(RESOURCE_BALANCE_START,new DateTimeZone('Europe/Paris'));
    $now=$now??new DateTimeImmutable();
    $members=[];
    foreach($game->query("SELECT player_id::text,pseudo,field::text FROM players WHERE server='s5' AND upper(alliance_tag)='UPTEP' AND is_present=true") as $r)
        $members[$r['player_id']]=['id'=>$r['player_id'],'pseudo'=>$r['pseudo'],'terrain'=>(float)$r['field'],'entree'=>null,'production'=>0.0,'taxe_payee'=>0.0,'taxe_recue'=>0.0,'part'=>0.0,'envoye'=>0.0,'recu'=>0.0,'cycles'=>0];
    $result=['rows'=>[],'start'=>$start,'end'=>$now,'cycles'=>0,'hours'=>[],'total'=>0.0,'current'=>0.0,'convoys'=>0,'materials'=>0.0,'outside'=>0.0,'colonies'=>0,'assumed_rates'=>false];
    if(!$members||$now<=$start)return $result;
    $ids=array_keys($members);$marks=implode(',',array_fill(0,count($ids),'?'));

    // Entry date: first UPTEP export after the member's last export outside the alliance.
    $q=$game->prepare("WITH exports AS (
        SELECT DISTINCT ON (export_version) id,exported_at FROM historique_exports
        WHERE server='s5' AND export_type='players' ORDER BY export_version,id DESC),
      r AS (
        SELECT h.player_id,e.exported_at,upper(coalesce(h.alliance_tag,''))='UPTEP' AS uptep
        FROM historique_players h JOIN exports e ON e.id=h.export_id WHERE h.player_id IN ($marks)),
      o AS (SELECT player_id,max(exported_at) FILTER (WHERE NOT uptep) AS last_out FROM r GROUP BY player_id)
      SELECT r.player_id::text,min(r.exported_at)::text AS entree FROM r JOIN o USING (player_id)
      WHERE r.uptep AND (o.last_out IS NULL OR r.exported_at>o.last_out) GROUP BY r.player_id");
    $q->execute($ids);
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$members[$r['player_id']]['entree']=new DateTimeImmutable($r['entree'],new DateTimeZone('UTC'));

    // One snapshot per export version (latest corrected import), from the one in force at the start.
    $from=$start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');$to=$now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $q=$game->prepare("WITH exports AS (
        SELECT DISTINCT ON (export_version) id,exported_at FROM historique_exports
        WHERE server='s5' AND export_type='players' AND exported_at<=?::timestamp
          AND exported_at>=coalesce((SELECT max(exported_at) FROM historique_exports WHERE server='s5' AND export_type='players' AND exported_at<=?::timestamp),?::timestamp)
        ORDER BY export_version,id DESC)
      SELECT e.id::text AS export_id,e.exported_at::text,h.player_id::text,h.field::text,h.master_player_id::text
      FROM exports e LEFT JOIN historique_players h ON h.export_id=e.id AND upper(h.alliance_tag)='UPTEP' AND h.player_id IN ($marks)
      ORDER BY e.exported_at,e.id");
    $q->execute(array_merge([$to,$from,$from],$ids));
    $exports=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
        $exports[$r['export_id']]??=['at'=>(new DateTimeImmutable($r['exported_at'],new DateTimeZone('UTC')))->getTimestamp(),'fields'=>[],'masters'=>[]];
        if($r['player_id']===null)continue;
        $exports[$r['export_id']]['fields'][$r['player_id']]=(float)$r['field'];
        $master=(string)($r['master_player_id']??'');
        if($master!==''&&$master!=='0'&&$master!==$r['player_id'])$exports[$r['export_id']]['masters'][$r['player_id']]=$master;
    }
    $exports=array_values($exports);
    $levels=scriptConvoyCurrentColonyLevels($p);$rates=[];
    $rate=static function(string $master)use($levels,&$result):float{
        $level=$levels[$master]??null;
        if(is_string($level)&&preg_match('/\A(?:0|[1-9][0-9]{0,2})\z/',$level))return min(100,20+(int)$level)/100;
        $result['assumed_rates']=true;return 0.2;
    };
    // Per export: each member's production and colony taxes, all within the counted members.
    foreach($exports as &$e){
        $e['tax']=[];
        foreach($e['masters'] as $id=>$master)if(isset($e['fields'][$master])){$rates[$master]??=$rate($master);$e['tax'][$id]=[$master,$e['fields'][$id]*$rates[$master]];}
    }
    unset($e);

    // Each cycle takes the latest export at or before it.
    $first=intdiv($start->getTimestamp()+RESOURCE_BALANCE_CYCLE-1,RESOURCE_BALANCE_CYCLE)*RESOURCE_BALANCE_CYCLE;
    $last=$now->getTimestamp();$i=-1;$hours=[];
    for($t=$first;$t<=$last;$t+=RESOURCE_BALANCE_CYCLE){
        while(isset($exports[$i+1])&&$exports[$i+1]['at']<=$t)$i++;
        if($i<0||!$exports[$i]['fields'])continue;
        $fields=$exports[$i]['fields'];$pool=array_sum($fields);$share=$pool/count($fields);
        foreach($fields as $id=>$field){$members[$id]['production']+=$field;$members[$id]['part']+=$share;$members[$id]['cycles']++;}
        foreach($exports[$i]['tax'] as $id=>[$master,$tax]){$members[$id]['taxe_payee']+=$tax;$members[$master]['taxe_recue']+=$tax;}
        $hour=intdiv($t,3600)*3600;$hours[$hour]=($hours[$hour]??0.0)+$pool;
        $result['total']+=$pool;$result['cycles']++;
        $result['current']=$pool*2;$result['colonies']=count($exports[$i]['tax']);
    }
    // The current hour is still incomplete: charting it would show a false drop.
    if($hours&&array_key_last($hours)+3600>$last)array_pop($hours);
    $result['hours']=$hours;

    $q=$p->prepare("SELECT c.idenvoyeur::text AS sender,d.iddemandeur::text AS receiver,c.nbmateriaux::text AS amount
        FROM convois_effectues c JOIN demandes_convois d ON d.id=c.iddemande
        WHERE d.serveur='s5' AND NOT c.annule AND c.nbmateriaux>0 AND c.date_convoi>=?::timestamptz AND c.date_convoi<=?::timestamptz");
    $q->execute([$start->format('c'),$now->format('c')]);
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
        $amount=(float)$r['amount'];$counted=false;
        if(isset($members[$r['sender']])){$members[$r['sender']]['envoye']+=$amount;$counted=true;}
        if(isset($members[$r['receiver']])){$members[$r['receiver']]['recu']+=$amount;$counted=true;}
        if(!$counted)continue;
        $result['convoys']++;$result['materials']+=$amount;
        if(!isset($members[$r['sender']],$members[$r['receiver']]))$result['outside']+=$amount;
    }

    foreach($members as $m){
        $m['colonie']=$m['taxe_recue']-$m['taxe_payee'];
        $m['net']=$m['production']+$m['colonie']-$m['envoye']+$m['recu'];$m['ecart']=$m['net']-$m['part'];
        $m['ratio']=$m['part']>0?$m['net']/$m['part']:null;
        $m['verdict']=$m['ratio']===null?'aucun':(abs($m['ratio']-1)<=RESOURCE_BALANCE_TOLERANCE?'equilibre':($m['ecart']>0?'convoyer':'recevoir'));
        $result['rows'][]=$m;
    }
    return $result;
}

/** Sorts rows on one of RESOURCE_BALANCE_SORTS; unknown keys fall back to the gap, largest first. */
function resourceBalanceSort(array $rows,string $key,bool $asc):array{
    if(!in_array($key,RESOURCE_BALANCE_SORTS,true)){$key='ecart';$asc=false;}
    usort($rows,static function($a,$b)use($key,$asc){
        $va=$a[$key];$vb=$b[$key];
        if($va===null||$vb===null)return ($va===null)<=>($vb===null);// Missing values last.
        $c=$key==='pseudo'?strnatcasecmp($va,$vb):$va<=>$vb;
        return ($asc?$c:-$c)?:strnatcasecmp($a['pseudo'],$b['pseudo']);
    });
    return $rows;
}

function resourceNumber(float $v):string{return number_format($v,0,',',' ');}
/** Three significant digits with k, M, G or T ("46,5M", "400k", "−1,5k"); 999 950 rounds up to "1M". */
function resourceCompact(float $v):string{
    $a=abs($v);$sign=$a>=0.5&&$v<0?'−':'';
    if(round($a)<1000)return $sign.number_format($a,0,',',' ');
    $suffixes=['k','M','G','T'];$scale=min(4,(int)floor(log10($a)/3));
    $n=$a/1000**$scale;$decimals=$n>=100?0:($n>=10?1:2);$n=round($n,$decimals);
    if($n>=1000&&$scale<4){$scale++;$n=round($a/1000**$scale,2);$decimals=2;}
    $text=number_format($n,$decimals,',',' ');
    if($decimals>0)$text=rtrim(rtrim($text,'0'),',');
    return $sign.$text.$suffixes[$scale-1];
}

/** Server-rendered SVG of the alliance production per hour (presentation attributes only, for the strict CSP). */
function resourceBalanceSvg(array $data):string{
    $hours=$data['hours'];if(count($hours)<2)return '';
    // Production per hour = sum of the cycle pools in that hour.
    $W=1100;$H=300;$x0=64;$x1=$W-16;$top=12;$bottom=$H-44;
    $t0=array_key_first($hours);$t1=array_key_last($hours);$max=max(1.0,max($hours));
    $raw=$max/5;$mag=10**floor(log10($raw));$step=$mag*(($raw/$mag)<=1.5?1:(($raw/$mag)<=3?2:(($raw/$mag)<=7?5:10)));$hi=ceil($max/$step)*$step;
    $X=static fn(int $t)=>$x0+($t-$t0)/max(1,$t1-$t0)*($x1-$x0);$Y=static fn(float $v)=>$bottom-$v/$hi*($bottom-$top);
    $f=static fn(float $n)=>number_format($n,1,'.','');
    $s='<svg class="resource-chart" viewBox="0 0 '.$W.' '.$H.'" role="img" aria-labelledby="resource-chart-title" xmlns="http://www.w3.org/2000/svg" font-family="inherit">';
    $s.='<title id="resource-chart-title">Production de matériaux de l’alliance par heure</title>';
    for($v=0;$v<=$hi+1e-6;$v+=$step){$y=$f($Y($v));
        $s.='<line x1="'.$x0.'" x2="'.$x1.'" y1="'.$y.'" y2="'.$y.'" stroke="'.($v==0?'#52514e':'#e1e0d9').'" stroke-width="1"/>';
        $s.='<text x="'.($x0-8).'" y="'.$f($Y($v)+4).'" text-anchor="end" font-size="12" fill="#898781">'.e(resourceCompact($v)).'</text>';}
    // One tick per Paris day; labels thinned so they never overlap.
    $zone=new DateTimeZone('Europe/Paris');$day=(new DateTimeImmutable('@'.$t0))->setTimezone($zone)->setTime(0,0)->modify('+1 day');
    $days=[];for(;$day->getTimestamp()<=$t1;$day=$day->modify('+1 day'))$days[]=$day;
    $every=max(1,(int)ceil(count($days)*58/($x1-$x0)));
    foreach($days as $k=>$d){$x=$f($X($d->getTimestamp()));
        $s.='<line x1="'.$x.'" x2="'.$x.'" y1="'.$bottom.'" y2="'.($bottom+5).'" stroke="#52514e" stroke-width="1"/>';
        if($k%$every===0)$s.='<text x="'.$x.'" y="'.($bottom+20).'" text-anchor="middle" font-size="12" fill="#898781">'.e($d->format('d/m')).'</text>';}
    $line='';foreach($hours as $t=>$v)$line.=($line===''?'M':'L').$f($X($t)).','.$f($Y($v));
    $s.='<path d="'.$line.'L'.$f($X($t1)).','.$bottom.'L'.$f($X($t0)).','.$bottom.'Z" fill="#2a78d6" fill-opacity="0.14"/>';
    $s.='<path d="'.$line.'" fill="none" stroke="#2a78d6" stroke-width="2" stroke-linejoin="round"/>';
    // Hover bands: one per hour, exact value in the tooltip.
    $band=($x1-$x0)/max(1,($t1-$t0)/3600);
    foreach($hours as $t=>$v){$label=(new DateTimeImmutable('@'.$t))->setTimezone($zone)->format('d/m H\h');
        $s.='<rect x="'.$f($X($t)-$band/2).'" y="'.$top.'" width="'.$f($band).'" height="'.($bottom-$top).'" fill="transparent"><title>'.e($label.' : '.resourceNumber($v).' matériaux').'</title></rect>';}
    $s.='<text x="'.(($x0+$x1)/2).'" y="'.($H-6).'" text-anchor="middle" font-size="12" fill="#52514e">matériaux produits par heure · heure de Paris</text>';
    return $s.'</svg>';
}
