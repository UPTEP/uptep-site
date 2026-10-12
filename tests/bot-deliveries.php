<?php
require __DIR__.'/bot-convoys.php';require __DIR__.'/bot-deliveries.php';
function check($v,$m){if(!$v)throw new RuntimeException($m);}
$p=new PDO('pgsql:dbname=uptep','postgres',null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$p->beginTransaction();
try{
 $p->exec("CREATE TEMP TABLE chaine_members(server text,player_id bigint,iddiscord bigint);CREATE TEMP TABLE players(server text,player_id bigint,pseudo text,alliance_tag text,is_present boolean,x bigint,y bigint);CREATE TEMP TABLE demandes_convois(id bigint,iddemandeur bigint,pseudo_demandeur text,nbpomme_restant bigint,nbmateriaux_restant bigint,valide boolean,fini boolean,annulee boolean);
 INSERT INTO chaine_members VALUES('s5',1,123);
 INSERT INTO players VALUES('s5',1,'Origin','UPTEP',true,0,0),('s5',2,'Far','UPTEP',true,30,40),('s5',3,'Near','UPTEP',true,3,4);
 INSERT INTO demandes_convois VALUES(1,2,'Far',100,200,true,false,false),(2,3,'Near',10,20,true,false,false),(3,1,'Self',1,1,true,false,false),(4,2,'Unvalidated',1,1,false,false,false),(5,2,'Finished',1,1,true,true,false),(6,2,'Cancelled',1,1,true,false,true),(7,4,'Missing',1,1,true,false,false);");
 $r=botDeliveries($p,$p,['discord_id'=>'123','vitesse'=>'0']);check(array_column($r['lignes'],'id')===['2','1','7'],'eligible requests sorted; missing last');check($r['lignes'][0]['distance']===5.0,'Euclidean distance');check($r['lignes'][0]['seconds']===(int)round(86400*(1-exp(-5/350))*7.375),'formula');
 check(botDeliveryTravel(['x'=>0,'y'=>0],['x'=>0,'y'=>0],0)['seconds']===0,'zero coordinate valid');check(botDeliveryTravel(null,['x'=>0,'y'=>0],0)['distance']===null,'missing origin');
 foreach(['-1','1.5','1000'] as $v){try{botDeliveries($p,$p,['discord_id'=>'123','vitesse'=>$v]);throw new RuntimeException('invalid accepted');}catch(DomainException $e){check($e->getCode()===422,'invalid speed');}}
 try{botDeliveries($p,$p,['discord_id'=>'999','vitesse'=>'0']);throw new RuntimeException('unknown accepted');}catch(DomainException $e){check($e->getCode()===403&&str_contains($e->getMessage(),'NoobEnCDI'),'unknown association');}
 $p->exec("INSERT INTO demandes_convois SELECT n,3,'Near',1,1,true,false,false FROM generate_series(10,20) n");$r=botDeliveries($p,$p,['discord_id'=>'123','vitesse'=>'5','page'=>'2']);check($r['pages']===2&&count($r['lignes'])===6,'pagination');
 echo "OK deliveries: eligibility, identity, distances, speed, pagination and missing coordinates\n";
}finally{$p->rollBack();}
