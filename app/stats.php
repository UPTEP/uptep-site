<?php
declare(strict_types=1);
// Labels checked against the Fourmizzz construction/research tutorials.
const STATS_GROUPS = [
 'buildings'=>['Bâtiments', ['champignoniere'=>'Champignonière','entrepot_nourriture'=>'Entrepôt de nourriture','entrepot_materiaux'=>'Entrepôt de matériaux','couveuse'=>'Couveuse','solarium'=>'Solarium','laboratoire'=>'Laboratoire','salle_analyse'=>'Salle d’analyse','salle_combat'=>'Salle de combat','caserne'=>'Caserne','dome'=>'Dôme','loge_imperiale'=>'Loge impériale','etable_pucerons'=>'Étable à pucerons','etable_cochenilles'=>'Étable à cochenilles']],
 'research'=>['Laboratoire', ['technique_ponte'=>'Technique de ponte','bouclier'=>'Bouclier thoracique','armes'=>'Armes','architecture'=>'Architecture','communication'=>'Communication avec les animaux','vitesse_chasse'=>'Vitesse de chasse','vitesse_attaque'=>'Vitesse d’attaque','genetique'=>'Génétique','acide'=>'Acide','poison'=>'Poison']],
 'army'=>['Armée', ['jsn'=>'Jeunes soldates naines','sn'=>'Soldates naines','ne'=>'Naines d’élite','js'=>'Jeunes soldates','s'=>'Soldates','se'=>'Soldates d’élite','c'=>'Concierges','ce'=>'Concierges d’élite','a'=>'Artilleuses','ae'=>'Artilleuses d’élite','t'=>'Tanks','te'=>'Tanks d’élite','tu'=>'Tueuses','tue'=>'Tueuses d’élite']],
 'workers'=>['Ouvrières', ['total'=>'Nombre total d’ouvrières']],
 'resources'=>['Ressources', ['nourriture'=>'Nourriture','materiaux'=>'Matériaux','terrain'=>'Terrain de chasse']]
];
function statsValues(array $input): array {
 $values=[];
 foreach(STATS_GROUPS as $group=>[$title,$fields])foreach($fields as $key=>$label){
  if(!isset($input[$group]) || !is_array($input[$group]) || !array_key_exists($key,$input[$group]) || !is_string($input[$group][$key]))throw new DomainException('Formulaire incomplet. Recharge la page avant de réessayer.',422);
  $value=preg_replace('/[\x{0020}\x{00A0}\x{202F}]/u','',trim($input[$group][$key]));
  if($value===''){$values[$group][$key]=null;continue;}
  $max=in_array($group,['buildings','research'],true)?'999':(string)PHP_INT_MAX;
  $number=ltrim($value??'','0');if($number==='')$number='0';
  if($value===null || !preg_match('/^[0-9]+$/D',$value) || strlen($number)>strlen($max) || (strlen($number)===strlen($max) && strcmp($number,$max)>0))throw new DomainException($label.' : indique un entier positif ou nul, au maximum '.$max.'.',422);
  $values[$group][$key]=$number;
 }
 return $values;
}
function loadStats(int $userId): array {
 $q=db()->prepare('SELECT data,version,updated_at,buildings_synced_at,research_synced_at,army_synced_at,resources_synced_at,workers_synced_at FROM user_stats WHERE user_id=?');$q->execute([$userId]);$row=$q->fetch(PDO::FETCH_ASSOC);
 return $row?['data'=>json_decode($row['data'],true,512,JSON_THROW_ON_ERROR),'version'=>(int)$row['version'],'updated_at'=>$row['updated_at'],'synced'=>['buildings'=>$row['buildings_synced_at'],'research'=>$row['research_synced_at'],'army'=>$row['army_synced_at'],'resources'=>$row['resources_synced_at'],'workers'=>$row['workers_synced_at']]]:['data'=>[],'version'=>0,'updated_at'=>null,'synced'=>['buildings'=>null,'research'=>null,'army'=>null,'resources'=>null,'workers'=>null]];
}
function saveStats(array $actor,array $values,int $version): void {
 $p=db();$p->beginTransaction();
 try{
  $q=$p->prepare("SELECT id FROM users WHERE id=? AND status='active' AND session_version=? AND must_change_password=false FOR SHARE");$q->execute([$actor['id'],$actor['session_version']]);
  if(!$q->fetchColumn())throw new DomainException('Ta session a changé. Reconnecte-toi.',403);
  $json=json_encode($values,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
  if($version===0){$q=$p->prepare('INSERT INTO user_stats(user_id,data) VALUES (?,?::jsonb) ON CONFLICT(user_id) DO NOTHING RETURNING version');$q->execute([$actor['id'],$json]);}
  else{$q=$p->prepare('UPDATE user_stats SET data=?::jsonb,version=version+1,updated_at=now() WHERE user_id=? AND version=? RETURNING version');$q->execute([$json,$actor['id'],$version]);}
  if(!$q->fetchColumn())throw new DomainException('Tes statistiques ont été modifiées dans un autre onglet. Recharge la page avant de les modifier à nouveau.',409);
  $p->commit();
 }catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
}
