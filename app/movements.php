<?php
declare(strict_types=1);

/* "Mouvements": the alliance's arrivals of one day — declared floods, declared convoys and hunts
 * recorded by UPTEP+ or Discord — including those already arrived. */

function movementsFormat(int|string $n): string { return number_format((float)$n,0,',',' '); }
function movementsTime(string $value): int { return (new DateTimeImmutable($value))->getTimestamp(); }

/** Arrivals in [from, to), sorted. Each one: type, at (timestamp), label, players (game ids), details. */
function movementsData(DateTimeImmutable $from,DateTimeImmutable $to): array {
    $events=[];$range=[$from->format('c'),$to->format('c')];
    $q=db()->prepare("SELECT id,sender_id::text,sender_pseudo,target_id::text,target_pseudo,location,troops,arrival_at,created_at FROM floods WHERE arrival_at>=?::timestamptz AND arrival_at<?::timestamptz ORDER BY arrival_at,id");$q->execute($range);
    foreach($q as $f){
        $events[]=['type'=>'flood','at'=>movementsTime($f['arrival_at']),'label'=>$f['sender_pseudo'].' → '.$f['target_pseudo'],
            'players'=>array_values(array_filter([$f['sender_id'],$f['target_id']])),
            'details'=>['Attaquant'=>$f['sender_pseudo'],'Cible'=>$f['target_pseudo'],'Lieu'=>$f['location'],'Troupes'=>trim((string)$f['troops'])!==''?mb_strimwidth(trim((string)$f['troops']),0,160,'…'):'Non renseignées','Déclaré le'=>movementsDate($f['created_at'])]];
    }
    $q=db()->prepare("SELECT c.id,c.iddemande,c.idenvoyeur::text,c.pseudo_envoyeur,d.iddemandeur::text,d.pseudo_demandeur,c.nbpomme,c.nbmateriaux,c.date_convoi,c.date_arrivee
        FROM convois_effectues c JOIN demandes_convois d ON d.id=c.iddemande
        WHERE NOT c.annule AND c.date_arrivee>=?::timestamptz AND c.date_arrivee<?::timestamptz ORDER BY c.date_arrivee,c.id");$q->execute($range);
    foreach($q as $c){
        $total=(int)$c['nbpomme']+(int)$c['nbmateriaux'];
        $events[]=['type'=>'convoy','at'=>movementsTime($c['date_arrivee']),'label'=>$c['pseudo_envoyeur'].' → '.$c['pseudo_demandeur'],'amount'=>$total,
            'players'=>[$c['idenvoyeur'],$c['iddemandeur']],
            'details'=>['Expéditeur'=>$c['pseudo_envoyeur'],'Destinataire'=>$c['pseudo_demandeur'],'Nourriture'=>movementsFormat($c['nbpomme']),'Matériaux'=>movementsFormat($c['nbmateriaux']),'Parti le'=>movementsDate($c['date_convoi']),'Demande'=>'#'.$c['iddemande']]];
    }
    $q=db()->prepare("SELECT player_id::text,area,arrival_at,source FROM script_hunts WHERE cancelled_at IS NULL AND arrival_at>=?::timestamptz AND arrival_at<?::timestamptz ORDER BY arrival_at,id");$q->execute($range);$hunts=$q->fetchAll(PDO::FETCH_ASSOC);
    if($hunts){
        $ids=array_values(array_unique(array_column($hunts,'player_id')));$names=[];
        $q=db(true)->prepare("SELECT player_id::text,pseudo FROM players WHERE server='s5' AND player_id IN (".implode(',',array_fill(0,count($ids),'?')).")");$q->execute($ids);
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$names[$r['player_id']]=$r['pseudo'];
        foreach($hunts as $h){
            $name=$names[$h['player_id']]??('Joueur '.$h['player_id']);
            $events[]=['type'=>'hunt','at'=>movementsTime($h['arrival_at']),'label'=>$name.' +'.movementsFormat($h['area']).' cm²','amount'=>(int)$h['area'],
                'players'=>[$h['player_id']],'details'=>['Joueur'=>$name,'Surface'=>movementsFormat($h['area']).' cm²','Relevé par'=>$h['source']==='discord'?'Discord':'UPTEP+']];
        }
    }
    usort($events,static fn($a,$b)=>$a['at']<=>$b['at']?:strcmp($a['type'],$b['type']));
    return $events;
}
function movementsDate(string $value): string { return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('Europe/Paris'))->format('d/m à H:i'); }

/** Day shown, from ?jour=YYYY-MM-DD (Paris). Defaults to today; limited to the first records and a month ahead. */
function movementsDayParam(mixed $value,DateTimeImmutable $today): DateTimeImmutable {
    if(!is_string($value)||$value==='')return $today;
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$value,$today->getTimezone());
    if(!$d||$d->format('Y-m-d')!==$value)return $today;
    $min=new DateTimeImmutable(MOVEMENTS_FIRST_DAY,$today->getTimezone());$max=$today->modify('+31 days');
    return $d<$min?$min:($d>$max?$max:$d);
}
const MOVEMENTS_FIRST_DAY='2026-10-01';
/** Day heading in Paris time: "Aujourd'hui · mercredi 22 octobre". */
function movementsDay(DateTimeImmutable $day,DateTimeImmutable $today): string {
    $days=['dimanche','lundi','mardi','mercredi','jeudi','vendredi','samedi'];
    $months=['janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
    $label=$days[(int)$day->format('w')].' '.(int)$day->format('j').' '.$months[(int)$day->format('n')-1];
    $diff=(int)$today->diff($day)->format('%r%a');
    return ($diff===0?'Aujourd’hui · ':($diff===1?'Demain · ':($diff===-1?'Hier · ':''))).$label;
}

/** UPTEP+ in the game (Alliance › Mouvements): the same day view, as JSON for the portal iframe. */
function scriptMovements(array $actor,array $body): array {
    if(array_diff(array_keys($body),['action','pseudo','alliance','day']))throw new DomainException('Champ inconnu.',422);
    $zone=new DateTimeZone('Europe/Paris');$now=new DateTimeImmutable('now',$zone);$today=$now->setTime(0,0);
    $day=movementsDayParam($body['day']??null,$today);$first=new DateTimeImmutable(MOVEMENTS_FIRST_DAY,$zone);$last=$today->modify('+31 days');
    $nav=static fn(DateTimeImmutable $d)=>$d<$first||$d>$last?null:['day'=>$d->format('Y-m-d'),'label'=>movementsDay($d,$today)];
    $events=array_map(static function(array $e):array{
        $details=[];foreach($e['details'] as $k=>$v)$details[]=[$k,(string)$v];
        return ['type'=>$e['type'],'at'=>$e['at'],'label'=>$e['label'],'amount'=>$e['amount']??null,'players'=>$e['players'],'details'=>$details];
    },movementsData($day,$day->modify('+1 day')));
    return ['day'=>$day->format('Y-m-d'),'label'=>movementsDay($day,$today),'today'=>$today->format('Y-m-d'),'first'=>$first->format('Y-m-d'),'last'=>$last->format('Y-m-d'),
        'prev'=>$nav($day->modify('-1 day')),'next'=>$nav($day->modify('+1 day')),'now'=>$now->getTimestamp(),'me'=>(string)$actor['game_player_id'],'events'=>$events];
}
