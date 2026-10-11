<?php
declare(strict_types=1);
function scriptPlayer(int $id): ?array {
 $q=db(true)->prepare("SELECT player_id,pseudo FROM players WHERE server='s5' AND player_id=? AND upper(alliance_tag)='UPTEP' AND is_present=true");$q->execute([$id]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
}
function scriptAccount(int $id): ?array {
 $q=db()->prepare('SELECT id,game_player_id,script_enabled,status,must_change_password,session_version FROM users WHERE id=?');$q->execute([$id]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
}
function issueScriptToken(array $actor): string {
 $p=db();$p->beginTransaction();
 try{
  $q=$p->prepare('SELECT * FROM users WHERE id=? FOR SHARE');$q->execute([$actor['id']]);$u=$q->fetch(PDO::FETCH_ASSOC);
  if(!$u || $u['status']!=='active' || !$u['script_enabled'] || $u['must_change_password'] || (int)$u['session_version']!==(int)$actor['session_version'] || !$u['game_player_id'] || !scriptPlayer((int)$u['game_player_id']))throw new DomainException('Installation réservée aux comptes validés et autorisés, avec un ID de joueur UPTEP du serveur 5.',403);
  $token=bin2hex(random_bytes(32));
  $q=$p->prepare('INSERT INTO script_tokens(token_hash,user_id,player_id) VALUES (?,?,?)');$q->execute([hash('sha256',$token),$u['id'],$u['game_player_id']]);$p->commit();return $token;
 }catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
}
function authorizeScript(string $token,string $pseudo,string $alliance): array {
 if(!preg_match('/^[a-f0-9]{64}$/D',$token) || $alliance!=='UPTEP' || $pseudo==='' || mb_strlen($pseudo)>100)throw new DomainException('Accès UPTEP+ refusé.',403);
 $q=db()->prepare("SELECT u.id,u.game_player_id FROM script_tokens t JOIN users u ON u.id=t.user_id WHERE t.token_hash=? AND u.status='active' AND u.script_enabled=true AND u.must_change_password=false AND u.game_player_id=t.player_id");$q->execute([hash('sha256',$token)]);$u=$q->fetch(PDO::FETCH_ASSOC);
 $player=$u?scriptPlayer((int)$u['game_player_id']):null;
 if(!$player || $player['pseudo']!==$pseudo)throw new DomainException('Accès UPTEP+ refusé.',403);
 return $u;
}
function setScriptAccess(array $actor,int $target,int $version,bool $enabled): void {
 $p=db();$p->beginTransaction();
 try{
  $p->exec('LOCK TABLE users IN SHARE ROW EXCLUSIVE MODE');
  $q=$p->prepare("SELECT id FROM users WHERE id=? AND role='admin' AND status='active' AND session_version=? AND must_change_password=false");$q->execute([$actor['id'],$actor['session_version']]);
  if(!$q->fetchColumn())throw new DomainException('Accès réservé aux administrateurs.',403);
  $q=$p->prepare('UPDATE users SET script_enabled=?,permissions_version=permissions_version+1 WHERE id=? AND permissions_version=? RETURNING id');$q->execute([$enabled?'true':'false',$target,$version]);
  if(!$q->fetchColumn())throw new DomainException('Ce compte a changé. Recharge la page et réessaie.',409);
  $p->commit();
 }catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
}
