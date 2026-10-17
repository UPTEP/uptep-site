<?php
declare(strict_types=1);
// Seconds at level zero; ordered by the game's unit IDs, not array position.
// Source: FYT/FYW nUnitsLayingTimes, https://gist.github.com/lwr20/13b6a03b656a16215b72b4f42ff5a922
const COMBAT_LAYING_SECONDS=[1=>300,2=>450,3=>570,4=>740,5=>1000,6=>1410,7=>1440,8=>1520,9=>1450,10=>1860,11=>2740,12=>2740];
function combatTdpLatest(array $players):array {
    if(!$players)return [];
    $q=db()->prepare('SELECT DISTINCT ON(t.player_id) t.*,u.display_name AS author,o.report_id FROM combat_tdp_confirmations t LEFT JOIN users u ON u.id=t.confirmed_by JOIN combat_observations o ON o.id=t.second_observation_id WHERE t.player_id IN ('.implode(',',array_fill(0,count($players),'?')).') ORDER BY t.player_id,t.observed_at DESC,t.confirmed_at DESC,t.id DESC');
    $q->execute($players);$out=[];foreach($q as $r)$out[$r['player_id']]=$r;return $out;
}
function combatTdpPair(string $first,string $second,array $u):array {
    foreach([$first,$second] as $id)if(!ctype_digit($id)||strlen($id)>18||$id==='0')throw new DomainException('Choisis deux relevés valides.');
    $q=db()->prepare('SELECT o.*,r.created_by,r.server,r.raw_text,r.location FROM combat_observations o JOIN combat_reports r ON r.id=o.report_id WHERE o.id IN (?,?) ORDER BY o.observed_at,o.id');$q->execute([$first,$second]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    if(count($rows)!==2||(string)$rows[0]['id']!==$first||(string)$rows[1]['id']!==$second||$rows[0]['player_id']!==$rows[1]['player_id']||$rows[0]['server']!=='s5'||$rows[1]['server']!=='s5')throw new DomainException('Choisis deux sondes du même joueur S5, dans l’ordre chronologique.');
    if((string)$rows[1]['created_by']!==(string)$u['id'])throw new DomainException('Seule la personne ayant ajouté le second RC peut confirmer ce TDP.');
    $elapsed=(new DateTimeImmutable($rows[1]['observed_at']))->getTimestamp()-(new DateTimeImmutable($rows[0]['observed_at']))->getTimestamp();
    if($elapsed<=60)throw new DomainException('Les deux sondes doivent être espacées de plus d’une minute.');
    return ['first'=>$rows[0],'second'=>$rows[1],'elapsed'=>$elapsed,'player_id'=>$rows[1]['player_id']];
}
function combatTdpCompare(array $pair):array {
    $a=combatAnalyze($pair['first']['raw_text'],$pair['first']['location']??'auto');
    $baseline=$a['sides'][$pair['first']['side']==='attacker'?0:1]['survivors_before_xp'];
    $q=db()->prepare('SELECT unit_id,quantity FROM combat_observation_units WHERE observation_id=?');$q->execute([$pair['second']['id']]);$current=$q->fetchAll(PDO::FETCH_KEY_PAIR);
    $rows=[];foreach($a['units'] as $i=>$unit){$id=$unit['id'];if(!array_key_exists($id,$current))throw new DomainException('Armée incomplète dans le second relevé.');$rows[$id]=['before'=>$baseline[$i],'after'=>(int)$current[$id],'delta'=>(int)$current[$id]-$baseline[$i]];}
    return $rows;
}
function combatTdpCalculation(array $counts,int $elapsed):array {
    if($elapsed<=60)throw new DomainException('Intervalle trop court.');
    $base=0;$normalized=[];
    foreach(COMBAT_LAYING_SECONDS as $id=>$seconds){$n=$counts[$id]??null;if(!is_string($n)||!ctype_digit($n)||strlen($n)>13||(float)$n>1e12)throw new DomainException('Renseigne des effectifs entiers entre 0 et 1 000 000 000 000.');$normalized[$id]=(int)$n;$base+=(int)$n*$seconds;}
    if($base<=0)throw new DomainException('Aucune unité pondue : impossible de calculer un TDP.');
    // RC timestamps have minute precision (interval uncertainty < 60 seconds).
    // One additional second covers whole-second duration rounding.
    $levels=[];for($level=0;$level<=300;$level++){ $duration=$base*pow(.9,$level);if(abs($duration-$elapsed)<=61)$levels[]=$level; }
    if(!$levels)throw new DomainException('Aucun total entier compatible avec ces effectifs et ces dates. Vérifie les pertes, l’XP, les mouvements d’armée et la ponte continue.');
    return ['counts'=>$normalized,'base_seconds'=>$base,'elapsed_seconds'=>$elapsed,'compatible_levels'=>$levels,'time_tolerance_seconds'=>61];
}
function combatTdpConfirm(array $u,array $draft,string $token,string $total,bool $confirmed):string {
    if(!$confirmed||!isset($draft['token'],$draft['expires'],$draft['actor'])||$draft['expires']<time()||!hash_equals($draft['token'],$token)||(string)$draft['actor']!==(string)$u['id'])throw new DomainException('Confirmation manquante ou calcul expiré. Relance la comparaison.');
    if(!ctype_digit($total)||strlen($total)>3)throw new DomainException('Sélectionne un total compatible et vérifié.');
    $db=db();$db->beginTransaction();
    try{
        $q=$db->prepare('SELECT role,status,must_change_password,session_version FROM users WHERE id=? FOR SHARE');$q->execute([$u['id']]);$actor=$q->fetch(PDO::FETCH_ASSOC);
        if(!$actor||$actor['role']!=='admin'||$actor['status']!=='active'||$actor['must_change_password']||(int)$actor['session_version']!==(int)$u['session_version']||isset($u['_preview']))throw new DomainException('Accès administrateur requis.');
        $pair=combatTdpPair((string)$draft['first'],(string)$draft['second'],$u);
        $calc=combatTdpCalculation(array_map('strval',$draft['calculation']['counts']),$pair['elapsed']);
        if(!in_array((int)$total,$calc['compatible_levels'],true))throw new DomainException('Ce total ne correspond pas au calcul vérifié.');
        $q=$db->prepare('INSERT INTO combat_tdp_confirmations(player_id,first_observation_id,second_observation_id,total,observed_at,confirmed_by,evidence,token) VALUES(?,?,?,?,?,?,?::jsonb,?) ON CONFLICT(token) DO NOTHING');
        $q->execute([$pair['player_id'],$pair['first']['id'],$pair['second']['id'],(int)$total,$pair['second']['observed_at'],$u['id'],json_encode($calc+['comparison'=>$draft['comparison'],'assumptions_confirmed'=>true],JSON_THROW_ON_ERROR),$draft['token']]);
        $db->commit();return (string)$pair['player_id'];
    }catch(Throwable $e){$db->rollBack();throw $e;}
}
