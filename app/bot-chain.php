<?php
declare(strict_types=1);
require_once __DIR__.'/chaine.php';
function botChain(PDO $p,PDO $game,array $query): array {
 if(array_diff(array_keys($query),['discord_id']))throw new DomainException('Paramètre inconnu.',422);
 botActor($p,$game,botNumber($query['discord_id']??null,true),false);
 $q=$game->prepare("SELECT player_id::text,pseudo FROM players WHERE server=? AND upper(alliance_tag)='UPTEP' AND is_present=true");$q->execute([CHAINE_SERVER]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
 $q=$p->prepare('SELECT player_id::text,grade,sort_order FROM chaine_members WHERE server=?');$q->execute([CHAINE_SERVER]);$saved=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$saved[$r['player_id']]=$r;
 foreach($rows as &$r){$s=$saved[$r['player_id']]??[];$r['chaine_grade']=$s['grade']??null;$r['sort_order']=isset($s['sort_order'])?(int)$s['sort_order']:null;}unset($r);
 usort($rows,static function($a,$b){$ga=array_search($a['chaine_grade'],CHAINE_GRADES,true);$gb=array_search($b['chaine_grade'],CHAINE_GRADES,true);return ($ga===false?99:$ga)<=>($gb===false?99:$gb) ?: chaineOrderCompare($a,$b) ?: strnatcasecmp($a['pseudo'],$b['pseudo']) ?: (int)$a['player_id']<=>(int)$b['player_id'];});
 return ['ok'=>true,'membres'=>$rows,'total'=>count($rows)];
}
