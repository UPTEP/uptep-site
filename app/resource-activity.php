<?php
declare(strict_types=1);

/* Flood and hunt statistics of the current UPTEP members over a period [from, end).
 * Floods: declared floods (bot, UPTEP+) by arrival date, per member; the alliance troops are summed from the declared
 * text ("15 888 Jeunes Soldates Naines, 627 Soldates Naines."), unknown when it was not given or
 * unreadable (a unit count above RESOURCE_TROOPS_MAX comes from a mangled Discord paste).
 * The terrain taken by a flood is not recorded. Hunts: hunts read by UPTEP+ or pasted on Discord,
 * not cancelled, by arrival date; only players whose hunts are collected appear. */

const RESOURCE_TROOPS_MAX=100000000;

/** Total units of a declared troops text (first line only), or null when unknown or unreadable. */
function resourceTroops(?string $text):?float{
    if($text===null)return null;
    $line=trim(strtok($text,"\n"));if($line==='')return null;
    $total=0.0;
    foreach(explode(',',rtrim($line,'.')) as $part){
        if(!preg_match('/\A\s*([0-9][0-9 \x{00a0}\x{202f}]*?)\s+\D/u',$part,$m))return null;
        $count=(float)preg_replace('/\D/','',$m[1]);if($count>RESOURCE_TROOPS_MAX)return null;
        $total+=$count;
    }
    return $total;
}

/** @param array<string,string> $members player id => pseudo */
function resourceFloodStats(PDO $p,array $members,DateTimeImmutable $from,DateTimeImmutable $end,?DateTimeImmutable $now=null):array{
    $now=$now??new DateTimeImmutable();
    $rows=[];foreach($members as $id=>$pseudo)$rows[$id]=['id'=>$id,'pseudo'=>$pseudo,'envoyes'=>0,'recus'=>0,'dernier'=>null];
    $total=['floods'=>0,'troupes'=>0.0,'inconnues'=>0,'loge'=>0,'en_route'=>0];
    $q=$p->prepare("SELECT sender_id::text AS sender,target_id::text AS target,location,troops,arrival_at::text AS arrival FROM floods WHERE arrival_at>=?::timestamptz AND arrival_at<?::timestamptz");
    $q->execute([$from->format('c'),$end->format('c')]);
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $f){
        $troops=resourceTroops($f['troops']);$arrival=new DateTimeImmutable($f['arrival']);
        $total['floods']++;if($troops===null)$total['inconnues']++;else $total['troupes']+=$troops;
        if($f['location']!=='Terrain de chasse')$total['loge']++;
        if(isset($rows[$f['sender']])){$r=&$rows[$f['sender']];$r['envoyes']++;if($r['dernier']===null||$arrival>$r['dernier'])$r['dernier']=$arrival;unset($r);}
        if(isset($rows[$f['target']]))$rows[$f['target']]['recus']++;
    }
    $q=$p->prepare('SELECT count(*) FROM floods WHERE arrival_at>?::timestamptz');$q->execute([$now->format('c')]);$total['en_route']=(int)$q->fetchColumn();
    $rows=array_values($rows);
    usort($rows,static fn($a,$b)=>[$b['envoyes'],$b['recus']]<=>[$a['envoyes'],$a['recus']]?:strnatcasecmp($a['pseudo'],$b['pseudo']));
    return ['rows'=>$rows,'total'=>$total];
}

/** @param array<string,string> $members player id => pseudo */
function resourceHuntStats(PDO $p,array $members,DateTimeImmutable $from,DateTimeImmutable $end,?DateTimeImmutable $now=null):array{
    $now=$now??new DateTimeImmutable();
    $total=['chasses'=>0,'terrain'=>0.0,'en_cours'=>0,'terrain_en_cours'=>0.0,'suivis'=>0];
    if(!$members)return ['rows'=>[],'total'=>$total];
    $ids=array_map('strval',array_keys($members));$marks=implode(',',array_fill(0,count($ids),'?'));
    // Players with any collected hunt are followed; the others have no data at all, not zero hunts.
    $q=$p->prepare("SELECT player_id::text AS id,
            count(*) FILTER (WHERE cancelled_at IS NULL AND arrival_at>=?::timestamptz AND arrival_at<?::timestamptz) AS chasses,
            coalesce(sum(area) FILTER (WHERE cancelled_at IS NULL AND arrival_at>=?::timestamptz AND arrival_at<?::timestamptz),0)::text AS terrain,
            max(arrival_at) FILTER (WHERE cancelled_at IS NULL AND arrival_at<=?::timestamptz)::text AS derniere,
            count(*) FILTER (WHERE cancelled_at IS NULL AND arrival_at>?::timestamptz) AS en_cours,
            coalesce(sum(area) FILTER (WHERE cancelled_at IS NULL AND arrival_at>?::timestamptz),0)::text AS terrain_en_cours
        FROM script_hunts WHERE player_id IN ($marks) GROUP BY player_id");
    [$f,$e,$n]=[$from->format('c'),$end->format('c'),$now->format('c')];
    $q->execute(array_merge([$f,$e,$f,$e,$n,$n,$n],$ids));
    $rows=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
        $row=['id'=>$r['id'],'pseudo'=>$members[$r['id']],'chasses'=>(int)$r['chasses'],'terrain'=>(float)$r['terrain'],
            'derniere'=>$r['derniere']!==null?new DateTimeImmutable($r['derniere']):null,'en_cours'=>(int)$r['en_cours'],'terrain_en_cours'=>(float)$r['terrain_en_cours']];
        $row['moyenne']=$row['chasses']>0?$row['terrain']/$row['chasses']:null;
        $total['chasses']+=$row['chasses'];$total['terrain']+=$row['terrain'];$total['en_cours']+=$row['en_cours'];$total['terrain_en_cours']+=$row['terrain_en_cours'];
        $rows[]=$row;
    }
    $total['suivis']=count($rows);
    usort($rows,static fn($a,$b)=>$b['terrain']<=>$a['terrain']?:strnatcasecmp($a['pseudo'],$b['pseudo']));
    return ['rows'=>$rows,'total'=>$total];
}
