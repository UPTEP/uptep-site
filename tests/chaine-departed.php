<?php
require '/home/uptep/private/app/bootstrap.php';
require '/root/uptep-site/app/chaine.php';
function check($v){if(!$v)throw new RuntimeException('Check failed');}
$p=db();$editor=$p->query("SELECT * FROM users WHERE username='qa_chaine_editor'")->fetch(PDO::FETCH_ASSOC);$reader=$p->query("SELECT * FROM users WHERE username='qa_chaine_reader'")->fetch(PDO::FETCH_ASSOC);
$id=2147483600;
try{
 $q=$p->prepare("INSERT INTO chaine_members(server,player_id,pseudo,grade) VALUES(?,?,'QA departed','Chasseur')");$q->execute([CHAINE_SERVER,$id]);
 foreach([[$reader,1,403],[$editor,2,409]] as [$actor,$version,$code]){try{deleteDepartedChaine($actor,$id,$version);throw new RuntimeException('Should reject');}catch(DomainException $e){check($e->getCode()===$code);}}
 $present=chainePlayers();$assignments=$p->query("SELECT player_id,version FROM chaine_members WHERE server='s5'")->fetchAll(PDO::FETCH_KEY_PAIR);
 foreach($present as $player)if(isset($assignments[$player['player_id']])){try{deleteDepartedChaine($editor,(int)$player['player_id'],(int)$assignments[$player['player_id']]);throw new RuntimeException('Should reject active member');}catch(DomainException $e){check($e->getCode()===409);}break;}
 deleteDepartedChaine($editor,$id,1);$q=$p->prepare('SELECT count(*) FROM chaine_members WHERE server=? AND player_id=?');$q->execute([CHAINE_SERVER,$id]);check((int)$q->fetchColumn()===0);
 echo "Departed deletion: permissions, version conflict, current member protection, scoped deletion OK.\n";
}finally{$q=$p->prepare('DELETE FROM chaine_members WHERE server=? AND player_id=? AND pseudo=?');$q->execute([CHAINE_SERVER,$id,'QA departed']);}
