<?php
declare(strict_types=1);
require __DIR__.'/../app/convoy-daily.php';
function check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function e(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function botSiteReportAccess(PDO $p,PDO $g,array $q,array $u):void{if(($u['role']??'')!=='admin')throw new DomainException('Forbidden',403);}
$p=new PDO('pgsql:dbname=uptep',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$schema='daily_test_'.bin2hex(random_bytes(5));
$p->beginTransaction();
try{
$p->exec('CREATE SCHEMA '.$schema);$p->exec('SET LOCAL search_path TO '.$schema);
$p->exec("CREATE TABLE chaine_members(server text,player_id bigint,pseudo text);
CREATE TABLE demandes_convois(id bigint,serveur text,iddemandeur bigint,pseudo_demandeur text);
CREATE TABLE convois_effectues(id bigint,iddemande bigint,idenvoyeur bigint,pseudo_envoyeur text,nbpomme bigint,nbmateriaux bigint,date_convoi timestamptz,annule boolean);
CREATE TABLE historique_exports(id bigint,export_version text,exported_at timestamp,server text,export_type text);
CREATE TABLE historique_players(export_id bigint,player_id bigint,field bigint);
INSERT INTO chaine_members VALUES('s5',1,'Alpha'),('s5',2,'Beta'),('s5',3,'<script>Zero</script>'),('s1',99,'Wrong server');
INSERT INTO demandes_convois VALUES(1,'s5',2,'Beta'),(2,'s1',99,'Wrong server');
INSERT INTO convois_effectues VALUES
(1,1,1,'Alpha',100,0,'2026-10-14T22:30:00Z',false),
(2,1,1,'Alpha',200,400,'2026-10-15T10:00:00Z',false),
(3,1,1,'Alpha',0,500,'2026-10-14T10:00:00Z',false),
(4,1,1,'Alpha',999,999,'2026-10-15T11:00:00Z',true),
(5,1,1,'Alpha',999,999,'2026-10-15T15:00:00Z',false),
(6,1,1,'Alpha',999,999,'2026-10-08T21:59:00Z',false),
(7,1,1,'Alpha',700,0,'2026-10-08T22:00:00Z',false),
(8,2,99,'Wrong server',999,999,'2026-10-15T10:00:00Z',false);
INSERT INTO historique_exports VALUES
(1,'v1','2026-10-14 22:30:00','s5','players'),
(2,'v1','2026-10-14 22:30:00','s5','players'),
(3,'v2','2026-10-15 10:00:00','s5','players'),
(4,'future','2026-10-15 15:00:00','s5','players'),
(5,'other','2026-10-15 10:00:00','s1','players');
INSERT INTO historique_players VALUES(1,1,900),(2,1,100),(3,1,300),(4,1,9999),(5,1,9999);");
$now=new DateTimeImmutable('2026-10-15T12:00:00Z');
$data=convoyDailyData($p,$p,[],$now);$a=$data['rows'][0];
check(count($data['days'])===7&&$data['days'][6]['date']==='2026-10-09','seven Paris dates');
check(count($data['rows'])===3,'include zero members, exclude other server');
check($a['total_count']==='3'&&$a['total_quantity']==='1000'&&$a['daily_average']==='142.86','totals, exclude cancelled/future/outside period');
check($a['days']['2026-10-15']['quantity']==='300'&&$a['days']['2026-10-15']['count']==='2','Paris midnight and mixed convoy');
check($a['days']['2026-10-15']['tdc']==='200.00'&&$a['days']['2026-10-15']['samples']===2,'TdC dedup and daily mean');
check($a['days']['2026-10-14']['tdc']===null&&$a['days']['2026-10-14']['count']==='0','unknown TdC distinct from zero activity');
$wood=convoyDailyData($p,$p,['ressource'=>'materiaux'],$now)['rows'][0];check($wood['total_quantity']==='900'&&$wood['total_count']==='2','resource-only shipments');
$in=convoyDailyData($p,$p,['sens'=>'receptions'],$now)['rows'][0];check($in['player_id']==='2'&&$in['total_quantity']==='1000','recipient grouping');
check(count(convoyDailyData($p,$p,['joueur'=>'pha'],$now)['rows'])===1,'name filter');
check(count(convoyDailyData($p,$p,['joueur'=>'%'],$now)['rows'])===0,'escaped wildcard');
foreach([['sens'=>'bad'],['ressource'=>'DROP'],['joueur'=>[]]] as $q){try{convoyDailyData($p,$p,$q,$now);throw new RuntimeException('invalid filter accepted');}catch(DomainException $e){}}
try{convoyDailyStatistics($p,$p,[],['role'=>'member']);throw new RuntimeException('access bypass');}catch(DomainException $e){check($e->getCode()===403,'access guard');}
ob_start();require __DIR__.'/../app/convoy-daily-view.php';$html=ob_get_clean();check(!str_contains($html,'<script>Zero')&&str_contains($html,'&lt;script&gt;'),'escaped pseudo');check(substr_count($html,'scope="col"')===9,'nine column table');
$p->exec("INSERT INTO convois_effectues VALUES(9,1,1,'Alpha',9007199254740993,0,'2026-10-15T11:00:00Z',false)");
$large=convoyDailyData($p,$p,[],$now)['rows'][0];check($large['total_quantity']==='9007199254741993','large integer precision');check(convoyStatNumber($large['total_quantity'])==='9 007 199 254 741 993','exact formatted total');
$p->exec("INSERT INTO convois_effectues VALUES(10,1,1,'Alpha',1,0,'2026-10-25T00:30:00Z',false),(11,1,1,'Alpha',2,0,'2026-10-25T01:30:00Z',false)");
$dst=convoyDailyData($p,$p,[],new DateTimeImmutable('2026-10-26T12:00:00Z'));check($dst['rows'][0]['days']['2026-10-25']['quantity']==='3','DST repeated hour stays in same day');
check($data['totals']['total_quantity']==='1000'&&$data['totals']['total_count']==='3','grand total per member, not repeated per day');
check($data['totals']['days']['2026-10-15']['quantity']==='300','daily grand total');
check($data['totals']['days']['2026-10-15']['tdc']==='200.00'&&$data['totals']['days']['2026-10-15']['covered']===1,'partial TdC sum and coverage');
check($data['totals']['days']['2026-10-14']['tdc']===null,'missing TdC not zero');
check(str_contains($html,'daily-grand-total')&&str_contains($html,'3 joueurs affichés'),'total row rendered first');
$empty=convoyDailyData($p,$p,['joueur'=>'absent'],$now);check($empty['totals']['members']===0&&$empty['totals']['total_quantity']==='0','empty filtered total');
$one=convoyDailyData($p,$p,['joueur'=>'Beta'],$now);check($one['totals']['members']===1&&$one['totals']['total_quantity']==='0','filter applied to totals');
$fixtureRow=['days'=>['2026-10-15'=>['count'=>'1','quantity'=>'1','tdc'=>'0.10']],'total_count'=>'1','total_quantity'=>'1'];
$exact=convoyDailyTotals($p,[$fixtureRow,$fixtureRow],$data['days']);check($exact['daily_average']==='0.29'&&$exact['days']['2026-10-15']['tdc']==='0.20','mean from total, exact decimals');
$fixtureRow['total_quantity']='9223372036854775807';$fixtureRow['days']['2026-10-15']['quantity']='9223372036854775807';
$huge=convoyDailyTotals($p,[$fixtureRow,$fixtureRow],$data['days']);check($huge['total_quantity']==='18446744073709551614'&&$huge['days']['2026-10-15']['quantity']==='18446744073709551614','totals above bigint remain exact');
echo "OK: seven days, Paris/DST boundaries, four resource/direction views, zero days, cancelled/future exclusion, TdC averages/dedup, exact large totals, escaping and access guard.\n";
}finally{$p->rollBack();}
