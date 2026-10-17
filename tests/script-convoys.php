<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/script-convoys.php';
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function rejects(callable $fn,int $code):void{try{$fn();}catch(DomainException $e){check($e->getCode()===$code,$e->getMessage());return;}throw new RuntimeException('Expected rejection');}
$p=new PDO('pgsql:dbname=uptep',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if(($argv[1]??'')==='worker'){$p->exec('SET search_path TO '.$argv[2]);echo json_encode(scriptConvoys($p,$p,['id'=>2,'game_player_id'=>2],json_decode($argv[3],true)));exit;}
$schema='qa_script_convoys_'.bin2hex(random_bytes(6));$p->exec('CREATE SCHEMA '.$schema);$p->exec('SET search_path TO '.$schema);
try{
foreach(['bot-convoys.sql','bot-ui.sql','bot-convoy-cancel.sql','bot-demand-adjustments.sql','convoy-events.sql'] as $file){$sql=file_get_contents(dirname(__DIR__).'/app/'.$file);$p->exec(preg_replace('/^(ALTER TABLE .* OWNER TO|GRANT ).*;$/m','',$sql));}
$p->exec("CREATE TABLE chaine_members(server text,player_id bigint,iddiscord bigint);CREATE TABLE players(server text,player_id bigint,pseudo text,alliance_tag text,is_present boolean);CREATE TABLE users(id bigint,game_player_id bigint,discord_login_id bigint,status text,script_enabled boolean,is_chaine boolean,must_change_password boolean);
INSERT INTO chaine_members VALUES('s5',1,101),('s5',2,102),('s5',3,103);
INSERT INTO players VALUES('s5',1,'Target','UPTEP',true),('s5',2,'Sender','UPTEP',true),('s5',3,'Validator','UPTEP',true);
INSERT INTO users VALUES(1,1,101,'active',true,false,false),(2,2,102,'active',true,false,false),(3,3,103,'active',true,true,false)");
$u=['id'=>2,'game_player_id'=>2];$ids=[];
foreach([1,2,3] as $i){$d=botConvoyAction($p,$p,'demande',null,['commande_id'=>'create'.$i,'discord_id'=>'101','nbpomme'=>'100','nbmateriaux'=>'100'])[1]['demande'];$ids[]=$d['id'];botConvoyAction($p,$p,'validation',$d['id'],['commande_id'=>'validate'.$i,'discord_id'=>'103']);}
check(count(scriptConvoys($p,$p,$u,['action'=>'convoy-demands'])['demands'])===3,'all ongoing demands');
$order=['target'=>'Target','apples'=>'10','wood'=>'20','demand_id'=>$ids[0]];
check(scriptConvoys($p,$p,$u,['action'=>'convoy-check']+$order)['ready'],'preflight');
check((int)$p->query('SELECT count(*) FROM convois_effectues')->fetchColumn()===0,'preflight does not declare');
rejects(fn()=>scriptConvoys($p,$p,$u,['action'=>'convoy-check']+array_replace($order,['apples'=>'101'])),422);
rejects(fn()=>scriptConvoys($p,$p,$u,['action'=>'convoy-check']+array_replace($order,['target'=>'Other'])),409);
$body=['action'=>'convoy-declare','game_id'=>'8102','arrival'=>gmdate('Y-m-d\TH:i:s\Z',time()+3600),'sent_at'=>gmdate('Y-m-d\TH:i:s\Z'),'confirmed'=>true]+$order;
rejects(fn()=>scriptConvoys($p,$p,$u,array_replace($body,['confirmed'=>false])),422);
rejects(fn()=>scriptConvoys($p,$p,$u,array_replace($body,['discord_id'=>'101'])),422);
$p->exec('UPDATE users SET discord_login_id=101 WHERE id=2');rejects(fn()=>scriptConvoys($p,$p,$u,$body),403);$p->exec('UPDATE users SET discord_login_id=102 WHERE id=2');
$p->exec('UPDATE users SET script_enabled=false WHERE id=2');rejects(fn()=>scriptConvoys($p,$p,$u,$body),403);$p->exec('UPDATE users SET script_enabled=true WHERE id=2');
$out=scriptConvoys($p,$p,$u,$body);check($out['declared'],'declare');
$again=scriptConvoys($p,$p,$u,array_replace($body,['arrival'=>gmdate('Y-m-d\TH:i:s\Z',time()+3590)]));check($out===$again,'reload with shifted arrival is idempotent');
check(botDemand($p,$ids[0])['nbpomme_restant']==='90','balance decremented once');check((int)$p->query('SELECT count(*) FROM convoy_web_events')->fetchColumn()===1,'one notification event');
rejects(fn()=>scriptConvoys($p,$p,$u,array_replace($body,['apples'=>'11'])),409);rejects(fn()=>scriptConvoys($p,$p,$u,array_replace($body,['demand_id'=>$ids[1]])),409);
// Concurrent retries of one game ID cannot create two declarations.
$workers=[];foreach([3600,3601] as $delay){$b=array_replace($body,['game_id'=>'999','demand_id'=>$ids[1],'arrival'=>gmdate('Y-m-d\TH:i:s\Z',time()+$delay)]);$pipes=[];$proc=proc_open([PHP_BINARY,__FILE__,'worker',$schema,json_encode($b)],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$workers[]=[$proc,$pipes];}
$results=[];foreach($workers as [$proc,$pipes]){$results[]=json_decode(stream_get_contents($pipes[1]),true);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($proc)===0,'worker '.$error);}check($results[0]['convoy_id']===$results[1]['convoy_id'],'concurrent same convoy');
check((int)$p->query('SELECT count(*) FROM convoy_web_events')->fetchColumn()===2,'notifications once after concurrent requests');
// Failure after sending: a closed demand refuses registration without any extra debit.
botConvoyAction($p,$p,'terminaison',$ids[2],['commande_id'=>'close-third','discord_id'=>'101']);rejects(fn()=>scriptConvoys($p,$p,$u,array_replace($body,['game_id'=>'1000','demand_id'=>$ids[2]])),409);
check((int)$p->query('SELECT count(*) FROM convois_effectues')->fetchColumn()===2,'failure inserts nothing');
// Recovery may happen after arrival, preserving the actual original send date.
$past=array_replace($body,['game_id'=>'1001','arrival'=>gmdate('Y-m-d\TH:i:s\Z',time()-60),'sent_at'=>gmdate('Y-m-d\TH:i:s\Z',time()-3600)]);check(scriptConvoys($p,$p,$u,$past)['declared'],'recovery after arrival');
$new=['action'=>'convoy-create','request_key'=>'11111111-1111-4111-8111-111111111111','confirmed'=>true,'apples'=>'1000','wood'=>'2500','object'=>'Étable à pucerons','level'=>'12','wanted_date'=>null];
rejects(fn()=>scriptConvoys($p,$p,$u,array_replace($new,['confirmed'=>false])),422);
rejects(fn()=>scriptConvoys($p,$p,$u,array_replace($new,['discord_id'=>'101'])),422);
$created=scriptConvoys($p,$p,$u,$new);check($created['state']==='attente','new request awaits approval');
check(scriptConvoys($p,$p,$u,$new)===$created,'creation replay');
rejects(fn()=>scriptConvoys($p,$p,$u,array_replace($new,['wood'=>'2501'])),409);
$pending=scriptConvoys($p,$p,$u,['action'=>'convoy-pending'])['demands'];check(count($pending)===1&&$pending[0]['id']===$created['demand_id']&&$pending[0]['self'],'pending list identity and state');
check(!array_filter(scriptConvoys($p,$p,$u,['action'=>'convoy-demands'])['demands'],fn($r)=>$r['id']===$created['demand_id']),'pending excluded from sending');
rejects(fn()=>scriptConvoys($p,$p,['id'=>1,'game_player_id'=>1],['action'=>'convoy-check','demand_id'=>$created['demand_id'],'target'=>'Sender','apples'=>'1','wood'=>'0']),409);
$dated=array_replace($new,['request_key'=>'22222222-2222-4222-8222-222222222222','wanted_date'=>(new DateTimeImmutable('now',new DateTimeZone('Europe/Paris')))->format('Y-m-d')]);
$datedId=scriptConvoys($p,$p,$u,$dated)['demand_id'];check(scriptConvoys($p,$p,$u,$dated)['demand_id']===$datedId,'dated replay');
echo "Tabs OK: creation, pending state, recipient binding, replay and pending send refused.\n";
echo "Script convoys OK: demands, preflight, identity, rights, confirmation, recipient, amounts, replay, concurrent retries, events, failures and late recovery.\n";

for($i=0;$i<130;$i++)botConvoyAction($p,$p,'demande',null,['commande_id'=>'pagination'.$i,'discord_id'=>'101','nbpomme'=>'1','nbmateriaux'=>'1']);
$expected=$p->query('SELECT id::text FROM demandes_convois WHERE NOT annulee AND NOT fini ORDER BY demandes_convois.id')->fetchAll(PDO::FETCH_COLUMN);
$seen=[];$after='0';$pages=0;
do{$page=botListDemands($p,['limite'=>'100','apres'=>$after,'include'=>'convois']);foreach($page['demandes'] as $d)$seen[]=$d['id'];$after=$page['suite_apres'];check(++$pages<10,'pagination terminates');}while($after!==null);
check($seen===$expected,'pagination returns every demand once in numeric order');
echo "Pagination over 130 demands OK.\n";
}finally{if($p->inTransaction())$p->rollBack();$p->exec('DROP SCHEMA '.$schema.' CASCADE');}
