<?php
declare(strict_types=1);
$adminFixture=tempnam(sys_get_temp_dir(),'uptep-script-admins-');
file_put_contents($adminFixture,"ADMIN_DISCORD_IDS=102\n");
define('UPTEP_BOT_ENV_FILE',$adminFixture);
register_shutdown_function(static function()use($adminFixture){unlink($adminFixture);});
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

// The management API must use database chain rights even for a bot admin.
$manager=['id'=>3,'game_player_id'=>3];$owner=['id'=>1,'game_player_id'=>1];
$seq=0;
$manage=function(array $who,string $op,string $id,array $extra=[])use($p,&$seq){return scriptConvoys($p,$p,$who,['action'=>'convoy-manage','operation'=>$op,'demand_id'=>$id,'request_key'=>sprintf('aaaaaaaa-aaaa-4aaa-8aaa-%012d',++$seq),'confirmed'=>true]+$extra);};
$createdDemand=botDemand($p,$created['demand_id']);
check(!scriptConvoys($p,$p,$u,['action'=>'convoy-demands'])['permissions']['is_chaine'],'bot admin is not a script chain manager');
rejects(fn()=>scriptConvoys($p,$p,$u,['action'=>'convoy-list','scope'=>'chain']),403);
rejects(fn()=>$manage($u,'validation',$createdDemand['id'],['version'=>$createdDemand['version_modification']]),403);
rejects(fn()=>$manage($u,'annulation',$ids[0]),403);
$foreign=botConvoyAction($p,$p,'demande',null,['commande_id'=>'foreign-manage','discord_id'=>'101','nbpomme'=>'100','nbmateriaux'=>'100','objet'=>'Dôme','niveau'=>'3'])[1]['demande'];
rejects(fn()=>$manage($u,'modification',$foreign['id'],['version'=>$foreign['version_modification'],'apples'=>'20','wood'=>'30','object'=>'Loge','level'=>'4']),403);
$edited=$manage($owner,'modification',$foreign['id'],['version'=>$foreign['version_modification'],'apples'=>'200','wood'=>'300','object'=>'Loge','level'=>'4','wanted_date'=>null])['demande'];
check($edited['nbpomme_original']==='200'&&$edited['objet']==='Loge'&&!$edited['valide'],'owner modifies all fields before validation');
rejects(fn()=>$manage($manager,'validation',$foreign['id'],['version'=>$foreign['version_modification']]),409);
$eventsBefore=(int)$p->query('SELECT count(*) FROM convoy_web_events')->fetchColumn();
$job=['action'=>'convoy-manage','operation'=>'validation','demand_id'=>$foreign['id'],'version'=>$edited['version_modification'],'request_key'=>'bbbbbbbb-bbbb-4bbb-8bbb-111111111111','confirmed'=>true];
$validated=scriptConvoys($p,$p,$manager,$job);
check(scriptConvoys($p,$p,$manager,$job)==$validated,'management replay');
check((int)$p->query('SELECT count(*) FROM convoy_web_events')->fetchColumn()===$eventsBefore+1,'one validation event for replay');
rejects(fn()=>$manage($u,'suspension',$foreign['id']),403);
$suspended=$manage($manager,'suspension',$foreign['id'])['demande'];check(!$suspended['valide']&&$suspended['nbpomme_restant']==='200','chain suspension preserves remaining');
$manage($manager,'validation',$foreign['id'],['version'=>$suspended['version_modification']]);
rejects(fn()=>$manage($u,'ajustement',$foreign['id'],['apples'=>'100','wood'=>'100','expected_apples'=>'200','expected_wood'=>'300']),403);
$adjusted=$manage($owner,'ajustement',$foreign['id'],['apples'=>'100','wood'=>'120','expected_apples'=>'200','expected_wood'=>'300'])['demande'];
check($adjusted['nbpomme_restant']==='100'&&count($adjusted['ajustements'])===1,'owner adjusts remaining');
rejects(fn()=>$manage($owner,'ajustement',$foreign['id'],['apples'=>'50','wood'=>'50','expected_apples'=>'200','expected_wood'=>'300']),409);
$closed=$manage($owner,'terminaison',$foreign['id'])['demande'];check($closed['fin_manuelle']&&$closed['nbpomme_restant']==='100','manual close preserves remaining');
rejects(fn()=>$manage($owner,'annulation',$foreign['id']),409);
$cancelled=$manage($u,'annulation',$datedId)['demande'];check($cancelled['annulee'],'owner cancels pending request');
$detail=scriptConvoys($p,$p,$u,['action'=>'convoy-detail','demand_id'=>$ids[0]]);
check(count($detail['convoys'])===2&&$detail['demand']['id']===$ids[0],'detail and convoy history');
$cancelJob=['action'=>'convoy-manage','operation'=>'annulation_convoi','convoy_id'=>$out['convoy_id'],'confirmed'=>true,'request_key'=>'cccccccc-cccc-4ccc-8ccc-111111111111'];
rejects(fn()=>scriptConvoys($p,$p,$owner,$cancelJob),403); // Recipient is not the sender.
$cancelOut=scriptConvoys($p,$p,$u,$cancelJob);check($cancelOut['convoi']['annule'],'sender cancels own declaration');
check(scriptConvoys($p,$p,$u,$cancelJob)==$cancelOut,'cancel replay');
$otherConvoy=botConvoyAction($p,$p,'convoi',$ids[1],['commande_id'=>'foreign-convoy','discord_id'=>'103','nbpomme'=>'1','nbmateriaux'=>'0'])[1]['convoi']['id'];
rejects(fn()=>scriptConvoys($p,$p,$u,array_replace($cancelJob,['convoy_id'=>(string)$otherConvoy,'request_key'=>'cccccccc-cccc-4ccc-8ccc-222222222222'])),403);
$ownSecond=$p->query("SELECT id::text FROM convois_effectues WHERE idenvoyeur=2 AND NOT annule ORDER BY id LIMIT 1")->fetchColumn();
check($manage($manager,'annulation_convoi',$ids[1],['convoy_id'=>$ownSecond])['convoi']['annule'],'chain cancels another sender declaration');
$p->exec('UPDATE users SET is_chaine=false WHERE id=3');
rejects(fn()=>$manage($manager,'validation',$createdDemand['id'],['version'=>$createdDemand['version_modification']]),403);
$p->exec('UPDATE users SET is_chaine=true WHERE id=3');
$mine=scriptConvoys($p,$p,$u,['action'=>'convoy-list','scope'=>'mine']);check(!array_filter($mine['demands'],fn($d)=>$d['iddemandeur']!=='2'),'personal list isolated');
check(count(scriptConvoys($p,$p,$u,['action'=>'convoy-list','scope'=>'history','state'=>'annulee'])['demands'])>=1,'cancelled history');
rejects(fn()=>scriptConvoys($p,$p,$manager,array_replace($job,['confirmed'=>false])),422);
rejects(fn()=>scriptConvoys($p,$p,$manager,$job+['discord_id'=>'101']),422);
echo "Management OK: database chain policy, ownership, versions, adjustment, suspension, closure, cancellation, history, revocation, replay and validation notification.\n";

for($i=0;$i<130;$i++)botConvoyAction($p,$p,'demande',null,['commande_id'=>'pagination'.$i,'discord_id'=>'101','nbpomme'=>'1','nbmateriaux'=>'1']);
$expected=$p->query('SELECT id::text FROM demandes_convois WHERE NOT annulee AND NOT fini ORDER BY demandes_convois.id')->fetchAll(PDO::FETCH_COLUMN);
$seen=[];$after='0';$pages=0;
do{$page=botListDemands($p,['limite'=>'100','apres'=>$after,'include'=>'convois']);foreach($page['demandes'] as $d)$seen[]=$d['id'];$after=$page['suite_apres'];check(++$pages<10,'pagination terminates');}while($after!==null);
check($seen===$expected,'pagination returns every demand once in numeric order');
$expectedHistory=$p->query("SELECT id::text FROM demandes_convois WHERE serveur='s5' ORDER BY demandes_convois.id DESC")->fetchAll(PDO::FETCH_COLUMN);
$historyIds=[];$after='0';$pages=0;
do{$page=scriptConvoys($p,$p,$u,['action'=>'convoy-list','scope'=>'history','after'=>$after]);foreach($page['demands'] as $d)$historyIds[]=$d['id'];$after=$page['next'];check(++$pages<20,'management pagination terminates');}while($after!==null);
check($historyIds===$expectedHistory,'management history pages include closed/cancelled requests exactly once');
$expectedMine=$p->query("SELECT id::text FROM demandes_convois WHERE serveur='s5' AND iddemandeur=1 ORDER BY demandes_convois.id DESC")->fetchAll(PDO::FETCH_COLUMN);
$mineIds=[];$after='0';$pages=0;
do{$page=scriptConvoys($p,$p,$owner,['action'=>'convoy-list','scope'=>'mine','after'=>$after]);foreach($page['demands'] as $d)$mineIds[]=$d['id'];$after=$page['next'];check(++$pages<20,'personal pagination terminates');}while($after!==null);
check($mineIds===$expectedMine,'personal pages are newest first without omissions or duplicates');
$options=scriptConvoys($p,$p,$u,['action'=>'convoy-list','scope'=>'history'])['players'];
check(array_column($options,'player_id')===['2','1'],'player options are sorted, span all pages and omit members without requests');
$filtered=[];$after='0';$pages=0;
do{$page=scriptConvoys($p,$p,$u,['action'=>'convoy-list','scope'=>'history','player_id'=>'1','after'=>$after]);foreach($page['demands'] as $d)$filtered[]=$d['id'];$after=$page['next'];check(++$pages<20,'filtered pagination terminates');}while($after!==null);
check($filtered===$expectedMine,'player filter covers all pages newest first');
$cancelledPlayer=scriptConvoys($p,$p,$u,['action'=>'convoy-list','scope'=>'history','player_id'=>'2','state'=>'annulee']);
check(count($cancelledPlayer['demands'])===1&&$cancelledPlayer['demands'][0]['id']===$datedId,'player and state filters combine');
check(scriptConvoys($p,$p,$u,['action'=>'convoy-list','scope'=>'mine','player_id'=>'1'])['demands']===[],'player filter cannot escape personal scope');
rejects(fn()=>scriptConvoys($p,$p,$u,['action'=>'convoy-list','scope'=>'history','player_id'=>'invalid']),422);
$p->exec("UPDATE players SET alliance_tag='OTHER' WHERE player_id=1");
check(array_column(scriptConvoys($p,$p,$u,['action'=>'convoy-list','scope'=>'history'])['players'],'player_id')===['2'],'only current alliance members offered');
$p->exec("UPDATE players SET alliance_tag='UPTEP' WHERE player_id=1");
echo "Player filter OK: current members with requests, complete options, combined states and descending pagination.\n";
// The default bot policy is preserved, including its configured administrator override.
check(botMember($p,$p,'102')['membre']['is_chaine'],'bot allowlist still authorizes the bot');
$cancelledBefore=$p->query("SELECT nbpomme_restant::text FROM demandes_convois WHERE id=".$ids[0])->fetchColumn();
check((int)$cancelledBefore===90,'own cancellation restores only its quantities, once');
echo "Management history pagination and unchanged bot admin policy OK.\n";
echo "Pagination over 130 demands OK.\n";
}finally{if($p->inTransaction())$p->rollBack();$p->exec('DROP SCHEMA '.$schema.' CASCADE');}
