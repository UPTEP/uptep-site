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
 * Net = production − colony tax paid + colony tax received − sent + received; gap = net − share.
 * All of this covers the chosen period [from, end): cycles and convoy departures inside it.
 * Estimated stock, always as of now whatever the period: the member's last UPTEP+ materials reading (at most 48 h old), plus the same
 * production and colony taxes for each cycle after it, minus convoys sent since (departure) plus
 * convoys received since (arrival). Spending and the warehouse capacity are not known. */

require_once __DIR__.'/script-convoy-colonies.php';

const RESOURCE_BALANCE_START='2026-10-11'; // Paris. The first declared convoy dates from 11/10 14:56.
const RESOURCE_BALANCE_CYCLE=1800;
const RESOURCE_BALANCE_TOLERANCE=0.05; // Within ±5 % of the share: balanced.
const RESOURCE_BALANCE_SORTS=['pseudo','terrain','stock','production','colonie','envoye','recu','ecart','ratio'];
const RESOURCE_STOCK_MAX_AGE=172800; // 48 h: an older reading is too far off to estimate a stock.

/** Reads the debut / fin date inputs (Y-m-d, Paris; fin inclusive). Default: from the start to now. */
function resourceBalanceRange(array $get,?DateTimeImmutable $now=null):array{
    $zone=new DateTimeZone('Europe/Paris');$now=$now??new DateTimeImmutable();
    $first=new DateTimeImmutable(RESOURCE_BALANCE_START,$zone);
    $day=static function($value)use($zone):?DateTimeImmutable{
        if(!is_string($value)||$value==='')return null;
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$value,$zone);
        if(!$d||$d->format('Y-m-d')!==$value)throw new DomainException('Date invalide : utilise le sélecteur de dates.',422);
        return $d;
    };
    $from=$day($get['debut']??null)??$first;$to=$day($get['fin']??null);
    $end=$to?$to->modify('+1 day'):$now;if($end>$now)$end=$now;
    if($from<$first)throw new DomainException('Les convois déclarés commencent le '.$first->format('d/m/Y').' : choisis une date de début à partir de ce jour.',422);
    if($from>=$end)throw new DomainException('La date de début doit précéder la date de fin et ne pas être dans le futur.',422);
    return [$from,$end];
}

function resourceBalanceData(PDO $p,PDO $game,?DateTimeImmutable $from=null,?DateTimeImmutable $end=null,?DateTimeImmutable $now=null):array{
    $now=$now??new DateTimeImmutable();
    $start=$from??new DateTimeImmutable(RESOURCE_BALANCE_START,new DateTimeZone('Europe/Paris'));
    $end=$end===null||$end>$now?$now:$end;
    $members=[];
    foreach($game->query("SELECT player_id::text,pseudo,field::text FROM players WHERE server='s5' AND upper(alliance_tag)='UPTEP' AND is_present=true") as $r)
        $members[$r['player_id']]=['id'=>$r['player_id'],'pseudo'=>$r['pseudo'],'terrain'=>(float)$r['field'],'production'=>0.0,'taxe_payee'=>0.0,'taxe_recue'=>0.0,'part'=>0.0,'envoye'=>0.0,'recu'=>0.0,'cycles'=>0,
            'releve'=>null,'stock'=>null,'stock_production'=>0.0,'stock_cycles'=>0,'stock_envoye'=>0.0,'stock_recu'=>0.0];
    $result=['rows'=>[],'start'=>$start,'end'=>$end,'cycles'=>0,'total'=>0.0,'current'=>0.0,'convoys'=>0,'materials'=>0.0,'outside'=>0.0,'colonies'=>0,'assumed_rates'=>false];
    if(!$members||$end<=$start)return $result;
    $ids=array_keys($members);$marks=implode(',',array_fill(0,count($ids),'?'));

    // Last UPTEP+ materials reading, for accounts linked to exactly one active user.
    $q=$p->query("SELECT u.game_player_id::text AS player_id,s.data->'resources'->>'materiaux' AS materiaux,s.resources_synced_at::text AS at
        FROM users u JOIN user_stats s ON s.user_id=u.id
        WHERE u.status='active' AND u.game_player_id IS NOT NULL AND s.resources_synced_at IS NOT NULL
          AND (SELECT count(*) FROM users other WHERE other.game_player_id=u.game_player_id AND other.status='active')=1");
    $since=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
        if(!isset($members[$r['player_id']])||!is_string($r['materiaux'])||!preg_match('/\A[0-9]{1,18}\z/',$r['materiaux']))continue;
        $at=new DateTimeImmutable($r['at']);$members[$r['player_id']]['releve']=['at'=>$at,'materiaux'=>(float)$r['materiaux']];
        if($at<=$now&&$now->getTimestamp()-$at->getTimestamp()<=RESOURCE_STOCK_MAX_AGE)$since[$r['player_id']]=$at->getTimestamp();
    }

    // One snapshot per export version (latest corrected import), from the one in force at the
    // earliest cycle needed (period start or oldest recent reading) up to now.
    $begin=$since?min($start->getTimestamp(),min($since)):$start->getTimestamp();
    $from=(new DateTimeImmutable('@'.$begin))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');$to=$now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
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

    // Each cycle takes the latest export at or before it; only cycles in [start, end) count for the balance.
    $first=intdiv($begin+RESOURCE_BALANCE_CYCLE-1,RESOURCE_BALANCE_CYCLE)*RESOURCE_BALANCE_CYCLE;
    $periodStart=$start->getTimestamp();$periodEnd=$end->getTimestamp();
    $last=$now->getTimestamp();$i=-1;
    for($t=$first;$t<=$last;$t+=RESOURCE_BALANCE_CYCLE){
        while(isset($exports[$i+1])&&$exports[$i+1]['at']<=$t)$i++;
        if($i<0||!$exports[$i]['fields'])continue;
        $fields=$exports[$i]['fields'];$pool=array_sum($fields);$share=$pool/count($fields);
        $inPeriod=$t>=$periodStart&&$t<$periodEnd;
        if($inPeriod){
            foreach($fields as $id=>$field){$members[$id]['production']+=$field;$members[$id]['part']+=$share;$members[$id]['cycles']++;}
            foreach($exports[$i]['tax'] as $id=>[$master,$tax]){$members[$id]['taxe_payee']+=$tax;$members[$master]['taxe_recue']+=$tax;}
            $result['total']+=$pool;$result['cycles']++;
            $result['current']=$pool*2;$result['colonies']=count($exports[$i]['tax']);
        }
        // Estimated stocks: only the cycles after each member's reading.
        foreach($since as $id=>$at)if($t>$at&&isset($fields[$id])){$members[$id]['stock_production']+=$fields[$id];$members[$id]['stock_cycles']++;}
        foreach($exports[$i]['tax'] as $id=>[$master,$tax]){
            if(isset($since[$id])&&$t>$since[$id])$members[$id]['stock_production']-=$tax;
            if(isset($since[$master])&&$t>$since[$master])$members[$master]['stock_production']+=$tax;
        }
    }

    $q=$p->prepare("SELECT c.idenvoyeur::text AS sender,d.iddemandeur::text AS receiver,c.nbmateriaux::text AS amount
        FROM convois_effectues c JOIN demandes_convois d ON d.id=c.iddemande
        WHERE d.serveur='s5' AND NOT c.annule AND c.nbmateriaux>0 AND c.date_convoi>=?::timestamptz AND c.date_convoi<?::timestamptz");
    $q->execute([$start->format('c'),$end->format('c')]);
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
        $amount=(float)$r['amount'];$counted=false;
        if(isset($members[$r['sender']])){$members[$r['sender']]['envoye']+=$amount;$counted=true;}
        if(isset($members[$r['receiver']])){$members[$r['receiver']]['recu']+=$amount;$counted=true;}
        if(!$counted)continue;
        $result['convoys']++;$result['materials']+=$amount;
        if(!isset($members[$r['sender']],$members[$r['receiver']]))$result['outside']+=$amount;
    }
    if($since){
        // Sent materials leave the warehouse at departure; received ones enter it on arrival.
        $oldest=(new DateTimeImmutable('@'.min($since)))->format('c');
        $q=$p->prepare("SELECT c.idenvoyeur::text AS sender,d.iddemandeur::text AS receiver,c.nbmateriaux::text AS amount,
                c.date_convoi::text AS depart,coalesce(c.date_arrivee,c.date_convoi)::text AS arrivee
            FROM convois_effectues c JOIN demandes_convois d ON d.id=c.iddemande
            WHERE d.serveur='s5' AND NOT c.annule AND c.nbmateriaux>0 AND (c.date_convoi>?::timestamptz OR coalesce(c.date_arrivee,c.date_convoi)>?::timestamptz)");
        $q->execute([$oldest,$oldest]);
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
            $depart=(new DateTimeImmutable($r['depart']))->getTimestamp();$arrival=(new DateTimeImmutable($r['arrivee']))->getTimestamp();
            if(isset($since[$r['sender']])&&$depart>$since[$r['sender']]&&$depart<=$last)$members[$r['sender']]['stock_envoye']+=(float)$r['amount'];
            if(isset($since[$r['receiver']])&&$arrival>$since[$r['receiver']]&&$arrival<=$last)$members[$r['receiver']]['stock_recu']+=(float)$r['amount'];
        }
    }

    foreach($members as $m){
        $m['colonie']=$m['taxe_recue']-$m['taxe_payee'];
        $m['net']=$m['production']+$m['colonie']-$m['envoye']+$m['recu'];$m['ecart']=$m['net']-$m['part'];
        $m['ratio']=$m['part']>0?$m['net']/$m['part']:null;
        if(isset($since[$m['id']]))$m['stock']=max(0.0,$m['releve']['materiaux']+$m['stock_production']-$m['stock_envoye']+$m['stock_recu']);
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
