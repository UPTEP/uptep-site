<?php
require __DIR__.'/bot-convoys.php';require __DIR__.'/bot-chain.php';
$p=new PDO('pgsql:dbname=uptep','postgres',null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$p->beginTransaction();
try{
$p->exec("CREATE TEMP TABLE chaine_members(server text,player_id bigint,iddiscord bigint,grade text,sort_order int);CREATE TEMP TABLE players(server text,player_id bigint,pseudo text,alliance_tag text,is_present boolean);
INSERT INTO players VALUES('s5',1,'A','UPTEP',true),('s5',2,'B','UPTEP',true),('s5',3,'C','UPTEP',true),('s5',4,'D','UPTEP',true),('s5',5,'E','OTHER',true),('s5',6,'F','UPTEP',false);
INSERT INTO chaine_members VALUES('s5',1,123,'Passeur',1),('s5',2,null,'Passeur',5),('s5',3,null,'Grenier',1),('s5',5,null,'Grenier',1),('s5',6,null,'Grenier',1);");
$r=botChain($p,$p,['discord_id'=>'123']);if(array_column($r['membres'],'player_id')!==['3','2','1','4'])throw new RuntimeException('Wrong chain roster or order');
try{botChain($p,$p,['discord_id'=>'999']);throw new RuntimeException('Unknown accepted');}catch(DomainException $e){if($e->getCode()!==403)throw $e;}
echo "OK chain: current alliance only, descending passers, unassigned included, association required\n";
}finally{$p->rollBack();}
