<?php
// PostgreSQL TEMP tables only. No production records are changed.
declare(strict_types=1);
require __DIR__.'/../app/bot-reports.php';
function botNumber($value,bool $positive=false): string {if(!is_string($value)||!preg_match('/^[1-9][0-9]*$/D',$value))throw new DomainException('Invalid number',422);return $value;}
function botConfiguredAdmins(): array {return ['102','104'];}
function botActor(PDO $p,PDO $game,string $id,bool $admin): array {if($id!=='102')throw new DomainException('Unassociated',403);return [];}
function check(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
$p=new PDO('pgsql:dbname=uptep',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$p->exec("CREATE TEMP TABLE chaine_members(server text,player_id bigint,pseudo text);
CREATE TEMP TABLE demandes_convois(id bigint,serveur text,iddemandeur bigint,pseudo_demandeur text,valide bool,fini bool,annulee bool,date_demande timestamptz);
INSERT INTO chaine_members SELECT 's5',n,'Joueur '||n FROM generate_series(1,12) n;
INSERT INTO chaine_members VALUES ('s4',99,'Autre serveur');
INSERT INTO demandes_convois VALUES
(1,'s5',1,'Ancien pseudo',false,false,false,'2020-01-01Z'),
(2,'s5',1,'Nouveau pseudo',true,false,false,'2026-10-10Z'),
(3,'s5',1,'Nouveau pseudo',true,true,false,'2026-10-11Z'),
(4,'s5',1,'Nouveau pseudo',true,false,true,'2026-10-12Z'),
(5,'s5',99,'Ancien demandeur',false,false,false,'2026-10-13Z'),
(6,'s4',1,'Autre serveur',true,true,false,'2026-10-14Z');");
$r=botReports($p,$p,'recap-demandes',['discord_id'=>'102']);
check($r['total']===13&&$r['pages']===2&&count($r['lignes'])===8,'All chain players and former requesters, paginated');
$first=$r['lignes'][0];check($first['id_joueur']==='1'&&$first['pseudo']==='Joueur 1','Stable player identity and chain name');
foreach(['effectuees'=>'4','validees'=>'2','terminees'=>'1','annulees'=>'1'] as $key=>$value)check($first['demandes_'.$key]===$value,'Count '.$key);
check(str_starts_with($first['derniere_demande'],'2026-10-12'),'Latest demand');
check($r['lignes'][1]['id_joueur']==='99','Former requester retained');
$next=botReports($p,$p,'recap-demandes',['discord_id'=>'102','page'=>'2']);
check(!array_intersect(array_column($r['lignes'],'id_joueur'),array_column($next['lignes'],'id_joueur')),'Stable distinct pages');
foreach(array_merge(array_slice($r['lignes'],2),$next['lignes']) as $row)check($row['demandes_effectuees']==='0'&&$row['derniere_demande']===null,'Zero and null for no demands');
foreach([['discord_id'=>'103'],['discord_id'=>'104'],['discord_id'=>'102','periode'=>'7j']] as $query){
 try{botReports($p,$p,'recap-demandes',$query);throw new RuntimeException('Invalid request accepted');}catch(DomainException $e){check($e->getCode()===(isset($query['periode'])?422:403),'Access and unsupported filters');}
}
echo "OK: all-time demand recap, zeros, former requesters, S5, statuses, pagination and admin access.\n";
