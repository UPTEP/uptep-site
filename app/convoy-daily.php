<?php
declare(strict_types=1);
require_once __DIR__.'/script-convoy-colonies.php';

function convoyDailyStatistics(PDO $p,PDO $game,array $query,array $user): array {
    botSiteReportAccess($p,$game,$query,$user);
    return convoyDailyData($p,$game,$query);
}
function convoyDailyPeriod(array $query,?DateTimeImmutable $now=null,?string $minDateStr=null): array {
    $tz=new DateTimeZone('Europe/Paris');
    $now=($now??new DateTimeImmutable())->setTimezone($tz);
    $today=$now->setTime(0,0);
    $firstStr=$minDateStr??(defined('CONSUMPTION_FIRST_DAY')?CONSUMPTION_FIRST_DAY:'2026-10-11');
    $first=new DateTimeImmutable($firstStr,$tz);
    if($first>$today)$first=$today;
    $period=is_string($query['periode']??null)?$query['periode']:'';
    $rawStart=is_string($query['debut']??null)?trim($query['debut']):(is_string($query['conso_debut']??null)?trim($query['conso_debut']):'');
    $rawEnd=is_string($query['fin']??null)?trim($query['fin']):(is_string($query['conso_fin']??null)?trim($query['conso_fin']):'');
    if($rawStart!==''||$rawEnd!==''){
        $parse=static function(string $v)use($tz):DateTimeImmutable{
            $d=DateTimeImmutable::createFromFormat('!Y-m-d',$v,$tz)?:DateTimeImmutable::createFromFormat('!d-m-Y',$v,$tz);
            if(!$d||($d->format('Y-m-d')!==$v&&$d->format('d-m-Y')!==$v))throw new DomainException('Date invalide : utilise le sélecteur de dates.',422);
            return $d;
        };
        $start=$rawStart!==''?$parse($rawStart):$first;
        $endDay=$rawEnd!==''?$parse($rawEnd):$today;
        $period='personnalisee';
    }else{
        if(!in_array($period,['3j','7j','30j','tout'],true))$period='7j';
        $endDay=$today;
        $start=match($period){
            '3j'=>max($first,$today->modify('-2 days')),
            '30j'=>max($first,$today->modify('-29 days')),
            'tout'=>$first,
            default=>max($first,$today->modify('-6 days')),
        };
    }
    if($start<$first)throw new DomainException('Les relevés commencent le '.$first->format('d/m/Y').' : choisis une date de début à partir de ce jour.',422);
    if($start>$endDay||$endDay>$today)throw new DomainException('La date de début doit précéder la date de fin et ne pas être dans le futur.',422);
    $numDays=(int)$start->diff($endDay)->days+1;
    if($numDays>366)throw new DomainException('La période est limitée à 366 jours.',422);
    $endAt=$endDay==$today?$now:$endDay->modify('+1 day');
    if($endAt<=$start)$endAt=$start->modify('+1 minute');
    if($period==='personnalisee'&&$endDay==$today){
        if($start==max($first,$today->modify('-2 days')))$period='3j';
        elseif($start==max($first,$today->modify('-6 days')))$period='7j';
        elseif($start==max($first,$today->modify('-29 days')))$period='30j';
        elseif($start==$first)$period='tout';
    }
    return [
        'period'=>$period,
        'start'=>$start,
        'end_day'=>$endDay,
        'end_at'=>$endAt,
        'days_count'=>$numDays,
        'first_day'=>$first->format('Y-m-d'),
        'today'=>$today->format('Y-m-d')
    ];
}
function convoyDailyData(PDO $p,PDO $game,array $query,?DateTimeImmutable $now=null): array {
    $resource=$query['ressource']??'materiaux';$direction=$query['sens']??'envois';$player=$query['joueur']??'';
    if(!in_array($resource,['pommes','materiaux'],true)||!in_array($direction,['envois','receptions'],true)||!is_string($player)||mb_strlen($player)>100)throw new DomainException('Filtres de statistiques invalides.',422);
    $now=($now??new DateTimeImmutable())->setTimezone(new DateTimeZone('Europe/Paris'));
    $today=$now->setTime(0,0);
    // Declared convoys start on 11/10: an earlier start would dilute the daily averages with empty days.
    $pInfo=convoyDailyPeriod($query,$now,defined('RESOURCE_BALANCE_START')?RESOURCE_BALANCE_START:'2026-10-11');
    $start=$pInfo['start'];$endDay=$pInfo['end_day'];$endAt=$pInfo['end_at'];$numDays=$pInfo['days_count'];
    $days=[];
    for($i=0;$i<$numDays;$i++){
        $day=$endDay->modify('-'.$i.' days');
        $diff=(int)$day->diff($today)->days;
        $days[]=['date'=>$day->format('Y-m-d'),'label'=>$diff===0?'J · Aujourd’hui':'J-'.$diff,'display'=>$day->format('d/m')];
    }
    $quantity=$resource==='pommes'?'c.nbpomme':'c.nbmateriaux';
    $id=$direction==='envois'?'c.idenvoyeur':'d.iddemandeur';$name=$direction==='envois'?'c.pseudo_envoyeur':'d.pseudo_demandeur';
    // Only trusted identifiers above are interpolated. Quantities remain PostgreSQL numeric strings.
    $sql="WITH movements AS (
        SELECT $id AS player_id,$name AS pseudo,c.id,c.date_convoi,$quantity::numeric AS quantity,
               (c.date_convoi AT TIME ZONE 'Europe/Paris')::date AS day
        FROM convois_effectues c JOIN demandes_convois d ON d.id=c.iddemande
        WHERE d.serveur='s5' AND NOT c.annule AND $quantity>0 AND c.date_convoi>=?::timestamptz AND c.date_convoi<?::timestamptz
    ), participants AS (
        SELECT player_id,pseudo FROM chaine_members WHERE server='s5'
        UNION ALL SELECT player_id,pseudo FROM movements
    ), people AS (
        SELECT p.player_id,coalesce(max(c.pseudo),max(p.pseudo)) AS pseudo FROM participants p
        LEFT JOIN chaine_members c ON c.server='s5' AND c.player_id=p.player_id GROUP BY p.player_id
    ), daily AS (
        SELECT player_id,day,count(*) AS shipments,sum(quantity) AS quantity FROM movements GROUP BY player_id,day
    ), totals AS (
        SELECT player_id,count(*) AS shipments,sum(quantity) AS quantity FROM movements GROUP BY player_id
    )
    SELECT p.player_id::text,p.pseudo,d.day::text,d.shipments::text,d.quantity::text,
           coalesce(t.shipments,0)::text AS total_count,coalesce(t.quantity,0)::text AS total_quantity,
           round(coalesce(t.quantity,0)/$numDays,2)::text AS daily_average
    FROM people p LEFT JOIN daily d ON d.player_id=p.player_id LEFT JOIN totals t ON t.player_id=p.player_id
    WHERE p.pseudo ILIKE ? ESCAPE '!'
    ORDER BY coalesce(t.quantity,0) DESC,lower(p.pseudo),p.player_id,d.day DESC";
    $q=$p->prepare($sql);$q->execute([$start->format('c'),$endAt->format('c'),'%'.str_replace(['!','%','_'],['!!','!%','!_'],trim($player)).'%']);
    $rows=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
        $key=$r['player_id'];
        if(!isset($rows[$key])){
            $daily=[];foreach($days as $day)$daily[$day['date']]=['count'=>'0','quantity'=>'0','tdc'=>null,'tdc_colonies'=>null,'samples'=>0];
            $rows[$key]=['player_id'=>$key,'pseudo'=>$r['pseudo'],'days'=>$daily,'total_count'=>$r['total_count'],'total_quantity'=>$r['total_quantity'],'daily_average'=>$r['daily_average']];
        }
        if($r['day']!==null&&isset($rows[$key]['days'][$r['day']]))$rows[$key]['days'][$r['day']]=['count'=>$r['shipments'],'quantity'=>$r['quantity'],'tdc'=>null,'tdc_colonies'=>null,'samples'=>0];
    }
    if($rows){
        $levels=scriptConvoyCurrentColonyLevels($p);
        // FZZZ export timestamps are stored without zone (UTC, as in the observatory).
        // Retain the latest corrected export per version, not repeated imports of the same snapshot.
        $sql="WITH exports AS (
            SELECT DISTINCT ON (export_version) id,
                   ((exported_at AT TIME ZONE 'UTC') AT TIME ZONE 'Europe/Paris')::date::text AS day
            FROM historique_exports
            WHERE server='s5' AND export_type='players'
              AND exported_at>=(?::timestamptz AT TIME ZONE 'UTC') AND exported_at<(?::timestamptz AT TIME ZONE 'UTC')
            ORDER BY export_version,id DESC
        )
        SELECT e.id AS export_id,e.day,h.player_id::text,h.field::text,h.master_player_id::text,h.alliance_tag
        FROM historique_players h JOIN exports e ON e.id=h.export_id
        WHERE upper(h.alliance_tag)='UPTEP' OR h.player_id IN (".implode(',',array_fill(0,count($rows),'?')).")";
        $q=$game->prepare($sql);$q->execute(array_merge([$start->format('c'),$endAt->format('c')],array_keys($rows)));
        $exports=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
            $exports[$r['export_id']]['day']=$r['day'];
            $exports[$r['export_id']]['players'][$r['player_id']]=[
                'field'=>$r['field'],
                'master_player_id'=>$r['master_player_id'],
                'alliance_tag'=>$r['alliance_tag']
            ];
        }
        $acc=[];
        foreach($exports as $exp){
            $day=$exp['day'];$snapshot=$exp['players'];
            foreach($rows as $playerId=>$playerRow){
                if(!isset($snapshot[$playerId])||!isset($playerRow['days'][$day]))continue;
                $own=(float)$snapshot[$playerId]['field'];
                $net=$own;
                $cap=scriptConvoyColonyCapacity((string)$playerId,$snapshot,$levels,true);
                if($cap!==null){
                    if($cap['loss']!==null)$net-=floor($own*$cap['loss']['percent']/100);
                    foreach($cap['gains'] as $g)$net+=floor((float)$g['terrain']*$g['percent']/100);
                }
                if(!isset($acc[$playerId][$day]))$acc[$playerId][$day]=['tdc'=>0.0,'tdc_colonies'=>0.0,'samples'=>0];
                $acc[$playerId][$day]['tdc']+=$own;
                $acc[$playerId][$day]['tdc_colonies']+=$net;
                $acc[$playerId][$day]['samples']++;
            }
        }
        foreach($acc as $playerId=>$playerDays){
            foreach($playerDays as $day=>$vals){
                $s=$vals['samples'];
                $rows[$playerId]['days'][$day]['tdc']=(string)round($vals['tdc']/$s,2);
                $rows[$playerId]['days'][$day]['tdc_colonies']=(string)round($vals['tdc_colonies']/$s,2);
                $rows[$playerId]['days'][$day]['samples']=$s;
            }
        }
    }
    return [
        'totals'=>convoyDailyTotals($p,array_values($rows),$days),
        'rows'=>array_values($rows),
        'days'=>$days,
        'days_count'=>$numDays,
        'period'=>$pInfo['period'],
        'debut'=>$start->format('Y-m-d'),
        'fin'=>$endDay->format('Y-m-d'),
        'first_day'=>$pInfo['first_day'],
        'today'=>$pInfo['today'],
        'resource'=>$resource,
        'direction'=>$direction,
        'player'=>$player,
        'start'=>$start->format('d/m/Y'),
        'end'=>$endDay->format('d/m/Y')
    ];
}
/** Theoretical production per day: one resource per cm² of hunting ground per 30-minute cycle, i.e. 48 cycles. */
function convoyProduction(?string $tdc): ?string { return $tdc===null||!is_numeric($tdc)?null:(string)round((float)$tdc*48); }
/** Mean of the daily TdC values that have at least one export. */
function convoyTdcAverage(array $days,string $key='tdc'): ?string {
    $values=[];foreach($days as $d)if(isset($d[$key])&&is_numeric($d[$key]))$values[]=(float)$d[$key];
    return $values?(string)round(array_sum($values)/count($values),2):null;
}
/** Compact figure (3 significant digits, k/M/G/T/P) whose exact value is shown on hover. Returns escaped HTML. */
function convoyStatCompact(?string $value): string {
    $exact=convoyStatNumber($value);
    if($value===null||!is_numeric($value)||(float)$value<1000)return e($exact);
    $n=(float)$value;$units=['','k','M','G','T','P'];$scale=min(5,(int)floor(log10($n)/3));
    $v=round($n/(1000**$scale),max(0,3-strlen((string)(int)($n/(1000**$scale)))));
    if($v>=1000&&$scale<5){$scale++;$v=round($n/(1000**$scale),2);}
    $text=number_format($v,max(0,3-strlen((string)(int)$v)),',','');
    if(str_contains($text,','))$text=rtrim(rtrim($text,'0'),',');
    return '<span class="daily-compact" title="'.e($exact).'">'.e($text.$units[$scale]).'</span>';
}
function convoyStatNumber(?string $value): string {
    if($value===null)return '—';
    [$whole,$fraction]=array_pad(explode('.',$value,2),2,'');
    $fraction=rtrim($fraction,'0');
    return preg_replace('/(?<=\d)(?=(?:\d{3})+$)/u',' ',$whole).($fraction!==''?','.$fraction:'');
}

// Aggregate the displayed rows using numeric arithmetic, including totals above bigint.
function convoyDailyTotals(PDO $p,array $rows,array $days): array {
    $daily=[];foreach($days as $day)$daily[$day['date']]=['count'=>'0','quantity'=>'0','tdc'=>null,'tdc_colonies'=>null,'covered'=>0];
    $result=['pseudo'=>'Total','days'=>$daily,'total_count'=>'0','total_quantity'=>'0','daily_average'=>'0','members'=>count($rows)];
    if(!$rows)return $result;
    $numDays=max(1,count($days));
    $q=$p->prepare("WITH people AS (
        SELECT * FROM jsonb_to_recordset(?::jsonb) AS r(days jsonb,total_count numeric,total_quantity numeric)
    ), summary AS (
        SELECT sum(total_count)::text AS total_count,sum(total_quantity)::text AS total_quantity,
               round(sum(total_quantity)/$numDays,2)::text AS daily_average FROM people
    )
    SELECT d.key AS day,sum((d.value->>'count')::numeric)::text AS count,
           sum((d.value->>'quantity')::numeric)::text AS quantity,
           sum((d.value->>'tdc')::numeric)::text AS tdc,
           sum((d.value->>'tdc_colonies')::numeric)::text AS tdc_colonies,
           count(d.value->>'tdc')::int AS covered,s.total_count,s.total_quantity,s.daily_average
    FROM people CROSS JOIN LATERAL jsonb_each(people.days) d CROSS JOIN summary s
    GROUP BY d.key,s.total_count,s.total_quantity,s.daily_average");
    $q->execute([json_encode($rows,JSON_THROW_ON_ERROR)]);
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
        $result['days'][$r['day']]=['count'=>$r['count'],'quantity'=>$r['quantity'],'tdc'=>$r['tdc'],'tdc_colonies'=>$r['tdc_colonies'],'covered'=>(int)$r['covered']];
        foreach(['total_count','total_quantity','daily_average'] as $key)$result[$key]=$r[$key];
    }
    return $result;
}