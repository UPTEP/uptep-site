<?php
declare(strict_types=1);
require_once __DIR__.'/bot-convoys.php';

/* "Mouvements": the alliance's arrivals of one day — declared floods, declared convoys and hunts
 * recorded by UPTEP+ or Discord — including those already arrived. */

function movementsFormat(int|string $n): string { return number_format((float)$n,0,',',' '); }
function movementsTime(string $value): int { return (new DateTimeImmutable($value))->getTimestamp(); }

/** Arrivals in [from, to), sorted. Each one: type, at (timestamp), label, players (game ids), details. */
function movementsData(DateTimeImmutable $from,DateTimeImmutable $to,?string $viewer=null): array {
    $events=[];$range=[$from->format('c'),$to->format('c')];
    $q=db()->prepare("SELECT id,sender_id::text,sender_pseudo,target_id::text,target_pseudo,location,troops,arrival_at,created_at FROM floods WHERE arrival_at>=?::timestamptz AND arrival_at<?::timestamptz ORDER BY arrival_at,id");$q->execute($range);
    foreach($q as $f){
        $events[]=['type'=>'flood','at'=>movementsTime($f['arrival_at']),'label'=>$f['sender_pseudo'].' → '.$f['target_pseudo'],
            'players'=>array_values(array_filter([$f['sender_id'],$f['target_id']])),
            'people'=>array_values(array_filter([[$f['sender_id'],$f['sender_pseudo']],$f['target_id']?[$f['target_id'],$f['target_pseudo']]:null])),
            'details'=>['Attaquant'=>$f['sender_pseudo'],'Cible'=>$f['target_pseudo'],'Lieu'=>$f['location'],'Troupes'=>trim((string)$f['troops'])!==''?mb_strimwidth(trim((string)$f['troops']),0,160,'…'):'Non renseignées','Déclaré le'=>movementsDate($f['created_at'])]];
    }
    $q=db()->prepare("SELECT c.id,c.iddemande,c.idenvoyeur::text,c.pseudo_envoyeur,d.iddemandeur::text,d.pseudo_demandeur,c.nbpomme,c.nbmateriaux,c.date_convoi,c.date_arrivee
        FROM convois_effectues c JOIN demandes_convois d ON d.id=c.iddemande
        WHERE NOT c.annule AND c.date_arrivee>=?::timestamptz AND c.date_arrivee<?::timestamptz".botHiddenSql(db(),$viewer,'c.iddemande')." ORDER BY c.date_arrivee,c.id");$q->execute($range);
    foreach($q as $c){
        $total=(int)$c['nbpomme']+(int)$c['nbmateriaux'];
        $events[]=['type'=>'convoy','at'=>movementsTime($c['date_arrivee']),'label'=>$c['pseudo_envoyeur'].' → '.$c['pseudo_demandeur'],'amount'=>$total,
            'players'=>[$c['idenvoyeur'],$c['iddemandeur']],'people'=>[[$c['idenvoyeur'],$c['pseudo_envoyeur']],[$c['iddemandeur'],$c['pseudo_demandeur']]],
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
                'players'=>[$h['player_id']],'people'=>[[$h['player_id'],$name]],'details'=>['Joueur'=>$name,'Surface'=>movementsFormat($h['area']).' cm²','Relevé par'=>$h['source']==='discord'?'Discord':'UPTEP+']];
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

const MOVEMENTS_TARGET_LEVELS=5;
/** "Mes cibles": the chain players below the viewer, by level (one grade position = one level, ties share it),
 * as on the userscript's Chaîne page: Grenier 1→n, Passeur n→1, Chasseur, A flooder. A player is in range when
 * his TDC is between half and three times the viewer's. Returns null when the viewer is not ranked in the chain. */
function movementsTargets(?string $me): ?array {
    if($me===null||!preg_match('/\A[1-9][0-9]{0,17}\z/',$me))return null;
    $ranks=['Grenier','Passeur','Chasseur','A flooder'];
    $chain=db()->query("SELECT player_id::text AS id,grade,sort_order FROM chaine_members WHERE server='s5' AND grade IN ('Grenier','Passeur','Chasseur','A flooder')")->fetchAll(PDO::FETCH_ASSOC);
    if(!$chain)return null;
    $q=db(true)->prepare("SELECT player_id::text,pseudo,field::text FROM players WHERE server='s5' AND is_present AND player_id IN (".implode(',',array_fill(0,count($chain),'?')).")");$q->execute(array_column($chain,'id'));
    $players=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$players[$r['player_id']]=$r;
    $level=static fn(array $r):array=>[array_search($r['grade'],$ranks,true),$r['sort_order']===null?PHP_INT_MAX:($r['grade']==='Passeur'?-(int)$r['sort_order']:(int)$r['sort_order'])];
    $self=null;foreach($chain as $r)if($r['id']===$me)$self=$r;
    if(!$self||!isset($players[$me]))return null;
    $mine=$level($self);$field=(int)$players[$me]['field'];$below=[];
    foreach($chain as $r){
        if($r['id']===$me||!isset($players[$r['id']])||(int)$players[$r['id']]['field']<=0)continue;
        $l=$level($r);
        // Same grade without a position on either side: not ordered, so not a target (Chasseurs between themselves).
        if($l[0]<$mine[0]||($l[0]===$mine[0]&&($l[1]===PHP_INT_MAX||$mine[1]===PHP_INT_MAX||$l[1]<=$mine[1])))continue;
        $below[]=['level'=>$l,'row'=>$r];
    }
    usort($below,static fn($a,$b)=>$a['level']<=>$b['level']?:strnatcasecmp($players[$a['row']['id']]['pseudo'],$players[$b['row']['id']]['pseudo']));
    $targets=[];$levels=[];
    foreach($below as $b){
        $key=implode(':',$b['level']);$levels[$key]??=count($levels)+1;if($levels[$key]>MOVEMENTS_TARGET_LEVELS)break;
        $r=$b['row'];$t=(int)$players[$r['id']]['field'];
        $targets[]=['id'=>$r['id'],'pseudo'=>$players[$r['id']]['pseudo'],'grade'=>$r['grade'],'order'=>$r['sort_order']===null?null:(int)$r['sort_order'],'level'=>$levels[$key],'field'=>$t,'in_range'=>$field>0&&$t*2>=$field&&$t<=$field*3];
    }
    return ['grade'=>$self['grade'],'order'=>$self['sort_order']===null?null:(int)$self['sort_order'],'field'=>$field,'targets'=>$targets];
}

/** Saved Mouvements filters of an account, normalised: shown types, "Seulement moi", "Mes cibles", levels, members. */
function movementsPrefsNormalize(mixed $raw): array {
    $raw=is_array($raw)?$raw:[];$types=['flood','convoy','hunt'];
    $shown=is_array($raw['types']??null)?array_values(array_intersect($types,$raw['types'])):$types;
    $members=is_array($raw['members']??null)?array_values(array_unique(array_filter($raw['members'],static fn($v)=>is_string($v)&&preg_match('/\A[1-9][0-9]{0,17}\z/',$v)))):[];
    $depth=is_int($raw['depth']??null)&&$raw['depth']>=3&&$raw['depth']<=MOVEMENTS_TARGET_LEVELS?$raw['depth']:4;
    return ['types'=>$shown,'me'=>($raw['me']??false)===true,'targets'=>($raw['targets']??false)===true,'depth'=>$depth,'members'=>array_slice($members,0,60)];
}
function movementsPrefs(int $user): ?array {
    $q=db()->prepare("SELECT value FROM user_preferences WHERE user_id=? AND page='mouvements'");$q->execute([$user]);$v=$q->fetchColumn();
    return $v===false?null:movementsPrefsNormalize(json_decode((string)$v,true));
}
/** Saves (or, with null, forgets) the account's Mouvements filters. */
function movementsSavePrefs(int $user,?array $raw): void {
    if($raw===null){db()->prepare("DELETE FROM user_preferences WHERE user_id=? AND page='mouvements'")->execute([$user]);return;}
    db()->prepare("INSERT INTO user_preferences(user_id,page,value) VALUES(?,'mouvements',?::jsonb) ON CONFLICT(user_id,page) DO UPDATE SET value=EXCLUDED.value,updated_at=now()")->execute([$user,json_encode(movementsPrefsNormalize($raw),JSON_THROW_ON_ERROR)]);
}

/** UPTEP+ in the game (Alliance › Mouvements): the same day view, as JSON for the portal iframe. */
function scriptMovements(array $actor,array $body): array {
    if(array_diff(array_keys($body),['action','pseudo','alliance','day']))throw new DomainException('Champ inconnu.',422);
    $zone=new DateTimeZone('Europe/Paris');$now=new DateTimeImmutable('now',$zone);$today=$now->setTime(0,0);
    $day=movementsDayParam($body['day']??null,$today);$first=new DateTimeImmutable(MOVEMENTS_FIRST_DAY,$zone);$last=$today->modify('+31 days');
    $nav=static fn(DateTimeImmutable $d)=>$d<$first||$d>$last?null:['day'=>$d->format('Y-m-d'),'label'=>movementsDay($d,$today)];
    $events=array_map(static function(array $e):array{
        $details=[];foreach($e['details'] as $k=>$v)$details[]=[$k,(string)$v];
        return ['type'=>$e['type'],'at'=>$e['at'],'label'=>$e['label'],'amount'=>$e['amount']??null,'players'=>$e['players'],'people'=>$e['people'],'details'=>$details];
    },movementsData($day,$day->modify('+1 day'),(string)$actor['game_player_id']));
    return ['day'=>$day->format('Y-m-d'),'label'=>movementsDay($day,$today),'today'=>$today->format('Y-m-d'),'first'=>$first->format('Y-m-d'),'last'=>$last->format('Y-m-d'),
        'prev'=>$nav($day->modify('-1 day')),'next'=>$nav($day->modify('+1 day')),'now'=>$now->getTimestamp(),'me'=>(string)$actor['game_player_id'],'events'=>$events,
        'targets'=>movementsTargets((string)$actor['game_player_id']),'prefs'=>movementsPrefsWithNames((int)$actor['id'])];
}
/** Saved filters plus the names of saved members, so they can be listed on a day they do not appear. */
function movementsPrefsWithNames(int $user): array {
    $prefs=movementsPrefs($user)??movementsPrefsNormalize(null);$names=[];
    if($prefs['members']){$q=db(true)->prepare("SELECT player_id::text,pseudo FROM players WHERE server='s5' AND player_id IN (".implode(',',array_fill(0,count($prefs['members']),'?')).")");$q->execute($prefs['members']);foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$names[$r['player_id']]=$r['pseudo'];}
    return $prefs+['names'=>(object)$names];
}
/** UPTEP+ in the game: saves (or with reset, forgets) the same Mouvements filters as on uptep.top. */
function scriptMovementsPrefs(array $actor,array $body): array {
    if(array_diff(array_keys($body),['action','pseudo','alliance','prefs','reset']))throw new DomainException('Champ inconnu.',422);
    if(($body['reset']??false)===true){movementsSavePrefs((int)$actor['id'],null);return ['saved'=>true];}
    if(!is_array($body['prefs']??null))throw new DomainException('Préférences invalides.',422);
    movementsSavePrefs((int)$actor['id'],$body['prefs']);return ['saved'=>true];
}
