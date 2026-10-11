<?php
declare(strict_types=1);
require __DIR__.'/bot-terrain.php';
function check($v,$label){if(!$v)throw new RuntimeException($label);}
function reject($fn,$code){try{$fn();throw new RuntimeException('Expected rejection');}catch(DomainException $e){check($e->getCode()===$code,'Wrong code');}}
$p=new PDO('pgsql:dbname=uptep','postgres',null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$schema='qa_terrain_'.bin2hex(random_bytes(6));$p->exec('CREATE SCHEMA '.$schema);$p->exec('SET search_path TO '.$schema);
function batch(string $id,string $expected,string $last,string $mid='100',string $author='11'): array {
 $text="11/10/26\nVol par Cible : 4 153 cm²\nCible vous a pris 800 cm² lors de sa dernière attaque.\nCible vous a pris 800 cm² lors de sa dernière attaque.\nCible vous a pris 800 cm² lors de sa dernière attaque.";
 return ['batch_id'=>$id,'expected_message_id'=>$expected,'last_message_id'=>$last,'reports'=>[['id'=>$mid,'author'=>$author,'text'=>$text]],'movements'=>array_map(fn($offset)=>['id'=>$mid.':'.$offset,'source_id'=>$mid,'direction'=>'loss','amount'=>'800','enemy'=>'Cible','member'=>null,'date'=>null,'quote'=>'Cible vous a pris 800 cm² lors de sa dernière attaque.'],[10,80,150]),'warnings'=>['Test répété']];
}
try{
 $sql=preg_replace('/^ALTER TABLE .* OWNER TO uptep;$/m','',file_get_contents(__DIR__.'/bot-terrain.sql'));$p->exec($sql);$p->exec($sql);
 check(terrainState($p,'1','2')['last_message_id']==='0','initial cursor');
 $b=batch('1000','0','100');$r=terrainCommit($p,'1','2',$b);check($r['totals']['balance']==='-2400','three equal attacks');check(terrainCommit($p,'1','2',$b)==$r,'idempotent replay');
 reject(fn()=>terrainCommit($p,'1','2',array_replace($b,['last_message_id'=>'101'])),409);
 check(terrainState($p,'1','2')['pending']['batch_id']==='1000','pending publication');
 reject(fn()=>terrainCommit($p,'1','2',batch('1001','100','110','110')),409);
 terrainPublished($p,'1','2',['batch_id'=>'1000','published_message_id'=>'105']);terrainPublished($p,'1','2',['batch_id'=>'1000','published_message_id'=>'105']);
 reject(fn()=>terrainPublished($p,'1','3',['batch_id'=>'1000','published_message_id'=>'105']),409);
 $r=terrainCommit($p,'1','2',batch('1002','100','110','110'));check($r['totals']['balance']==='-2400'&&$r['duplicate_reports']===1,'cross batch duplicate excluded');
 terrainPublished($p,'1','2',['batch_id'=>'1002','published_message_id'=>'115']);
 $bad=batch('1003','110','120','120','12');$bad['movements'][2]['quote']='invented';reject(fn()=>terrainCommit($p,'1','2',$bad),422);
 check(terrainState($p,'1','2')['last_message_id']==='110','cursor rollback');check((int)$p->query("SELECT count(*) FROM terrain_discord_reports WHERE message_id='120'")->fetchColumn()===0,'report rollback');
 reject(fn()=>terrainCommit($p,'1','2',batch('1004','100','120','120')),409);
 $r=terrainCommit($p,'1','2',batch('1005','110','120','120','12'));check($r['totals']['balance']==='-4800','other author retained');
 reject(fn()=>terrainCommit($p,'1','3',$b),409);
 check(terrainState($p,'1','3')['last_message_id']==='0','channels isolated');
 echo "OK: persistence, repeated attacks, deduplication across batches, rollback, cursor conflicts, idempotence and publication recovery\n";
}finally{$p->exec('DROP SCHEMA '.$schema.' CASCADE');}
