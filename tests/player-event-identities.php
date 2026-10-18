<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/player-events.php';
$p=new PDO('pgsql:dbname=uptep',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$p->exec("CREATE TEMP TABLE players(server text,player_id bigint,pseudo text,alliance_tag text,is_present boolean);
INSERT INTO players VALUES('s5',1507,'Colonisateur','TAG',true),('s5',12,'Libre',NULL,false),('s3',1507,'Autre serveur','OTHER',true)");
$events=[['type'=>'colonization','before'=>null,'after'=>'1507'],['type'=>'colonization','before'=>'1507','after'=>'12'],['type'=>'colonization','before'=>'9999','after'=>null],['type'=>'alliance','before'=>'TAG','after'=>'OTHER']];
$map=playerEventIdentities($p,$events);
if(count($map)!==2||$map[1507]!==['pseudo'=>'Colonisateur','alliance_tag'=>'TAG']||$map[12]!==['pseudo'=>'Libre','alliance_tag'=>null]||isset($map[9999]))throw new RuntimeException('Identités incorrectes');
if(playerEventIdentities($p,[])!==[])throw new RuntimeException('Historique vide incorrect');
echo "Identités des colonisateurs OK : S5, pseudos/tags, joueur absent, sans alliance et identifiant inconnu. Tables temporaires uniquement.\n";
