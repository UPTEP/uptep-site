<?php
declare(strict_types=1);

function convoyDailyStatistics(PDO $p,PDO $game,array $query,array $user): array {
    botSiteReportAccess($p,$game,$query,$user);
    return convoyDailyData($p,$game,$query);
}
function convoyDailyData(PDO $p,PDO $game,array $query,?DateTimeImmutable $now=null): array {
    $resource=$query['ressource']??'pommes';$direction=$query['sens']??'envois';$player=$query['joueur']??'';
    if(!in_array($resource,['pommes','materiaux'],true)||!in_array($direction,['envois','receptions'],true)||!is_string($player)||mb_strlen($player)>100)throw new DomainException('Filtres de statistiques invalides.',422);
    $now=($now??new DateTimeImmutable())->setTimezone(new DateTimeZone('Europe/Paris'));
    $today=$now->setTime(0,0);$start=$today->modify('-6 days');$days=[];
    for($i=0;$i<7;$i++){$day=$today->modify('-'.$i.' days');$days[]=['date'=>$day->format('Y-m-d'),'label'=>$i===0?'J · Aujourd’hui':'J-'.$i,'display'=>$day->format('d/m')];}
    $quantity=$resource==='pommes'?'c.nbpomme':'c.nbmateriaux';
    $id=$direction==='envois'?'c.idenvoyeur':'d.iddemandeur';$name=$direction==='envois'?'c.pseudo_envoyeur':'d.pseudo_demandeur';
    // Only trusted identifiers above are interpolated. Quantities remain PostgreSQL numeric strings.
    $sql="WITH movements AS (
        SELECT $id AS player_id,$name AS pseudo,c.id,c.date_convoi,$quantity::numeric AS quantity,
               (c.date_convoi AT TIME ZONE 'Europe/Paris')::date AS day
        FROM convois_effectues c JOIN demandes_convois d ON d.id=c.iddemande
        WHERE d.serveur='s5' AND NOT c.annule AND $quantity>0 AND c.date_convoi>=?::timestamptz AND c.date_convoi<=?::timestamptz
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
           round(coalesce(t.quantity,0)/7,2)::text AS daily_average
    FROM people p LEFT JOIN daily d ON d.player_id=p.player_id LEFT JOIN totals t ON t.player_id=p.player_id
    WHERE p.pseudo ILIKE ? ESCAPE '!'
    ORDER BY coalesce(t.quantity,0) DESC,lower(p.pseudo),p.player_id,d.day DESC";
    $q=$p->prepare($sql);$q->execute([$start->format('c'),$now->format('c'),'%'.str_replace(['!','%','_'],['!!','!%','!_'],trim($player)).'%']);
    $rows=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
        $key=$r['player_id'];
        if(!isset($rows[$key])){
            $daily=[];foreach($days as $day)$daily[$day['date']]=['count'=>'0','quantity'=>'0','tdc'=>null,'samples'=>0];
            $rows[$key]=['player_id'=>$key,'pseudo'=>$r['pseudo'],'days'=>$daily,'total_count'=>$r['total_count'],'total_quantity'=>$r['total_quantity'],'daily_average'=>$r['daily_average']];
        }
        if($r['day']!==null)$rows[$key]['days'][$r['day']]=['count'=>$r['shipments'],'quantity'=>$r['quantity'],'tdc'=>null,'samples'=>0];
    }
    if($rows){
        // FZZZ export timestamps are stored without zone (UTC, as in the observatory).
        // Retain the latest corrected export per version, not repeated imports of the same snapshot.
        $sql="WITH exports AS (
            SELECT DISTINCT ON (export_version) id,exported_at FROM historique_exports
            WHERE server='s5' AND export_type='players'
              AND exported_at>=(?::timestamptz AT TIME ZONE 'UTC') AND exported_at<=(?::timestamptz AT TIME ZONE 'UTC')
            ORDER BY export_version,id DESC
        )
        SELECT h.player_id::text,((e.exported_at AT TIME ZONE 'UTC') AT TIME ZONE 'Europe/Paris')::date::text AS day,
               round(avg(h.field::numeric),2)::text AS tdc,count(h.field)::int AS samples
        FROM historique_players h JOIN exports e ON e.id=h.export_id
        WHERE h.player_id IN (".implode(',',array_fill(0,count($rows),'?')).")
        GROUP BY h.player_id,day";
        $q=$game->prepare($sql);$q->execute(array_merge([$start->format('c'),$now->format('c')],array_keys($rows)));
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){if(isset($rows[$r['player_id']]['days'][$r['day']])){$rows[$r['player_id']]['days'][$r['day']]['tdc']=$r['tdc'];$rows[$r['player_id']]['days'][$r['day']]['samples']=(int)$r['samples'];}}
    }
    return ['totals'=>convoyDailyTotals($p,array_values($rows),$days),'rows'=>array_values($rows),'days'=>$days,'resource'=>$resource,'direction'=>$direction,'player'=>$player,'start'=>$start->format('d/m/Y'),'end'=>$today->format('d/m/Y')];
}
function convoyStatNumber(?string $value): string {
    if($value===null)return '—';
    [$whole,$fraction]=array_pad(explode('.',$value,2),2,'');
    $fraction=rtrim($fraction,'0');
    return preg_replace('/(?<=\d)(?=(?:\d{3})+$)/u',' ',$whole).($fraction!==''?','.$fraction:'');
}

// Aggregate the displayed rows using numeric arithmetic, including totals above bigint.
function convoyDailyTotals(PDO $p,array $rows,array $days): array {
    $daily=[];foreach($days as $day)$daily[$day['date']]=['count'=>'0','quantity'=>'0','tdc'=>null,'covered'=>0];
    $result=['pseudo'=>'Total','days'=>$daily,'total_count'=>'0','total_quantity'=>'0','daily_average'=>'0','members'=>count($rows)];
    if(!$rows)return $result;
    $q=$p->prepare("WITH people AS (
        SELECT * FROM jsonb_to_recordset(?::jsonb) AS r(days jsonb,total_count numeric,total_quantity numeric)
    ), summary AS (
        SELECT sum(total_count)::text AS total_count,sum(total_quantity)::text AS total_quantity,
               round(sum(total_quantity)/7,2)::text AS daily_average FROM people
    )
    SELECT d.key AS day,sum((d.value->>'count')::numeric)::text AS count,
           sum((d.value->>'quantity')::numeric)::text AS quantity,
           sum((d.value->>'tdc')::numeric)::text AS tdc,
           count(d.value->>'tdc')::int AS covered,s.total_count,s.total_quantity,s.daily_average
    FROM people CROSS JOIN LATERAL jsonb_each(people.days) d CROSS JOIN summary s
    GROUP BY d.key,s.total_count,s.total_quantity,s.daily_average");
    $q->execute([json_encode($rows,JSON_THROW_ON_ERROR)]);
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
        $result['days'][$r['day']]=['count'=>$r['count'],'quantity'=>$r['quantity'],'tdc'=>$r['tdc'],'covered'=>(int)$r['covered']];
        foreach(['total_count','total_quantity','daily_average'] as $key)$result[$key]=$r[$key];
    }
    return $result;
}
