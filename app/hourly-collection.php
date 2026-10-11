<?php
declare(strict_types=1);
function hourlyCollectionDue(int $id): bool {
 $q=db()->prepare("SELECT resources_synced_at IS NULL OR resources_synced_at<=now()-interval '1 hour' FROM user_stats WHERE user_id=?");$q->execute([$id]);$r=$q->fetch(PDO::FETCH_NUM);return !$r||(bool)$r[0];
}
/** Called inside the authorized settings transaction, with its preferences row locked. */
function saveHourlyCollection(int $id,array $body): bool {
 $values=$body['values']??null;$keys=['nourriture','materiaux','terrain','ouvrieres'];
 if(!is_array($values)||count($values)!==4)throw new DomainException('Relevé incomplet.',422);
 foreach($keys as $key){$v=$values[$key]??null;if(!is_string($v)||!preg_match('/^(0|[1-9][0-9]{0,18})$/D',$v)||strlen($v)>strlen((string)PHP_INT_MAX)||(strlen($v)===strlen((string)PHP_INT_MAX)&&strcmp($v,(string)PHP_INT_MAX)>0))throw new DomainException('Quantité invalide.',422);}
 if(!hourlyCollectionDue($id))return false;
 $json=json_encode(['resources'=>['nourriture'=>$values['nourriture'],'materiaux'=>$values['materiaux'],'terrain'=>$values['terrain']],'workers'=>['total'=>$values['ouvrieres']]],JSON_THROW_ON_ERROR);
 $q=db()->prepare("INSERT INTO user_stats(user_id,data,resources_synced_at,workers_synced_at) VALUES (?,?::jsonb,now(),now()) ON CONFLICT(user_id) DO UPDATE SET data=user_stats.data||EXCLUDED.data,resources_synced_at=now(),workers_synced_at=now(),version=user_stats.version+1,updated_at=now()");$q->execute([$id,$json]);return true;
}
