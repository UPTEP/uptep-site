<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/script-convoys.php';
require dirname(__DIR__).'/app/web-convoys.php';
function db(bool $game=false):PDO{return $GLOBALS['overage_db'];}
function post(string $key):string{return $_POST[$key]??'';}
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function rejects(callable $fn,int $status):void{try{$fn();}catch(DomainException $e){check($e->getCode()===$status,$e->getMessage());return;}throw new RuntimeException('Expected rejection');}
$p=new PDO('pgsql:dbname=uptep',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$GLOBALS['overage_db']=$p;
if(($argv[1]??'')==='worker'){$p->exec('SET search_path TO '.$argv[2]);try{echo botConvoyAction($p,$p,'convoi',$argv[3],['commande_id'=>$argv[4],'discord_id'=>'102','nbpomme'=>'110','nbmateriaux'=>'110','overage_confirmed'=>['pommes'=>'10','materiaux'=>'10']])[0];}catch(DomainException $e){echo $e->getCode();}exit;}
$schema='qa_overage_'.bin2hex(random_bytes(6));$p->exec('CREATE SCHEMA '.$schema);$p->exec('SET search_path TO '.$schema);
try{
 foreach(['bot-convoys.sql','bot-ui.sql','bot-convoy-cancel.sql','bot-demand-adjustments.sql','convoy-events.sql'] as $file){$sql=file_get_contents(dirname(__DIR__).'/app/'.$file);$sql=preg_replace('/^ surplus_(pommes|materiaux).*\n/m','',$sql);$p->exec(preg_replace('/^(ALTER TABLE .* OWNER TO|GRANT ).*;$/m','',$sql));}
 $p->exec("CREATE TABLE chaine_members(server text,player_id bigint,iddiscord bigint);CREATE TABLE players(server text,player_id bigint,pseudo text,alliance_tag text,is_present boolean);CREATE TABLE users(id bigint,game_player_id bigint,discord_login_id bigint,status text,script_enabled boolean,is_chaine boolean,must_change_password boolean);
 INSERT INTO chaine_members VALUES('s5',1,101),('s5',2,102),('s5',3,103);
 INSERT INTO players VALUES('s5',1,'Owner','UPTEP',true),('s5',2,'Sender','UPTEP',true),('s5',3,'Manager','UPTEP',true);
 INSERT INTO users VALUES(1,1,101,'active',true,false,false),(2,2,102,'active',true,false,false),(3,3,103,'active',true,true,false)");
 $seq=0;$act=function(string $action,?string $id,array $data=[],string $discord='101')use($p,&$seq){return botConvoyAction($p,$p,$action,$id,['commande_id'=>'overage-'.++$seq,'discord_id'=>$discord]+$data)[1];};
 $legacy=$act('demande',null,['nbpomme'=>'100','nbmateriaux'=>'100'])['demande'];
 $migration=file_get_contents(dirname(__DIR__).'/app/convoy-overage.sql');$p->exec($migration);$p->exec($migration);check(botDemand($p,$legacy['id'])['surplus_pommes']==='0','legacy demand retains balance');
 $make=function(string $apple='100',string $wood='100',bool $auto=false)use($act){$d=$act('demande',null,['nbpomme'=>$apple,'nbmateriaux'=>$wood,'auto_production'=>$auto])['demande'];return $act('validation',$d['id'],[],'103')['demande'];};
 $send=function(array $d,string $a,string $w,?array $ack=null,array $extra=[])use($act){return $act('convoi',$d['id'],['nbpomme'=>$a,'nbmateriaux'=>$w]+($ack===null?[]:['overage_confirmed'=>$ack])+$extra,'102');};
 $cancel=function(array $sent)use($p,&$seq){return botCancelConvoy($p,$p,$sent['convoi']['id'],['commande_id'=>'cancel-'.++$seq,'discord_id'=>'102'])[1]['demande'];};
 foreach([['0','101'],['1000000000000','0'],['123','456']] as [$a,$w]){
  $d=$make();$before=$p->query('SELECT count(*) FROM convois_effectues')->fetchColumn();rejects(fn()=>$send($d,$a,$w),409);check($before===$p->query('SELECT count(*) FROM convois_effectues')->fetchColumn(),'no write before confirmation');
  $sent=$send($d,$a,$w,botConvoyOverage($d,$a,$w));check($sent['convoi']['nbpomme']===$a&&$sent['convoi']['nbmateriaux']===$w,'actual sent values retained');check($sent['demande']['nbpomme_restant']===(string)max(0,100-(int)$a),'remaining never negative');
  $restored=$cancel($sent);check($restored['nbpomme_restant']==='100'&&$restored['nbmateriaux_restant']==='100','cancellation restores original need without excess');
 }
 $d=$make();$first=$send($d,'0','150',['pommes'=>'0','materiaux'=>'50']);$second=$send($first['demande'],'0','20',['pommes'=>'0','materiaux'=>'20']);$r=$cancel($first);check($r['nbmateriaux_restant']==='80','other convoy surplus still covers demand after cancellation');$r=$cancel($second);check($r['nbmateriaux_restant']==='100','second cancellation restores exact demand');
 // Stale preview: extra resources since confirmation require renewed approval.
 $d=$make();$send($d,'0','10');rejects(fn()=>$send($d,'0','110',['pommes'=>'0','materiaux'=>'10']),409);$sent=$send($d,'0','110',['pommes'=>'0','materiaux'=>'20']);check($sent['demande']['nbmateriaux_restant']==='0','updated acknowledgement succeeds');
 // Two already-confirmed previews racing cannot approve an increased excess silently.
 $d=$make();$workers=[];foreach(['a','b'] as $suffix){$pipes=[];$proc=proc_open([PHP_BINARY,__FILE__,'worker',$schema,$d['id'],'race-overage-'.$suffix],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$workers[]=[$proc,$pipes];}
 $codes=[];foreach($workers as [$proc,$pipes]){$codes[]=trim(stream_get_contents($pipes[1]));$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($proc)===0,$err);}sort($codes);check($codes===['201','409'],'racing confirmation requires consent to increased excess');check(botDemand($p,$d['id'])['surplus_pommes']==='10','race debits exactly once');
 // Production adjustment + surplus + cancellation preserve manually produced resources.
 $d=$make();$d=$act('ajustement',$d['id'],['nbpomme_restant'=>'60','nbmateriaux_restant'=>'100','nbpomme_attendu'=>'100','nbmateriaux_attendu'=>'100'])['demande'];$sent=$send($d,'80','0',['pommes'=>'20','materiaux'=>'0']);$r=$cancel($sent);check($r['nbpomme_restant']==='60','cancellation respects earlier production adjustment');
 // Fully delivered batch: later confirmed lines are retained, zero remainder is not negative.
 $d=$make();$a=$send($d,'150','150',['pommes'=>'50','materiaux'=>'50']);$b=$send($a['demande'],'20','20',['pommes'=>'20','materiaux'=>'20']);check($b['demande']['fini'],'batch can record extra lines after automatic completion');$r=$cancel($b);check($r['fini']&&$r['nbpomme_restant']==='0','canceling an excess-only line does not reopen need');$r=$cancel($a);check(!$r['fini']&&$r['nbpomme_restant']==='100','canceling final coverage reopens request');
 // Arbitrarily large cumulative surplus and replay safety, no bigint addition overflow.
 $d=$make();$max='9223372036854775807';$ack=['pommes'=>$max,'materiaux'=>$max];$body=['commande_id'=>'huge-stable','discord_id'=>'102','nbpomme'=>$max,'nbmateriaux'=>$max,'overage_confirmed'=>$ack];$a=botConvoyAction($p,$p,'convoi',$d['id'],$body)[1];check(botConvoyAction($p,$p,'convoi',$d['id'],$body)[2],'lost response is replayed');$b=$send($a['demande'],$max,$max,$ack);check($b['demande']['surplus_pommes']==='18446744073709551514','surplus numeric handles cumulative overflow');$cancel($a);$r=$cancel($b);check($r['nbpomme_restant']==='100','huge cancellation exact');
 $d=$make('100','100',true);rejects(fn()=>$send($d,'101','101',['pommes'=>'1','materiaux'=>'1']),409);$send($d,'101','101',['pommes'=>'1','materiaux'=>'1'],['auto_production_confirmed'=>true]);
 $d=$make();$order=['demand_id'=>$d['id'],'target'=>'Owner','apples'=>'101','wood'=>'120'];$ready=scriptConvoys($p,$p,['id'=>2,'game_player_id'=>2],['action'=>'convoy-check']+$order);check($ready['overage']===['pommes'=>'1','materiaux'=>'20'],'script preflight warns precisely');
 $_POST=['operation'=>'convoi','id'=>$d['id'],'pommes'=>'101','materiaux'=>'120','duree'=>'1h'];$web=convoyPrepare(['discord_id'=>'102']);check($web['overage']===$ready['overage']&&!isset($web['body']['overage_confirmed']),'website warns without pre-confirming');
 $declare=['action'=>'convoy-declare','game_id'=>'8765','arrival'=>gmdate('Y-m-d\TH:i:s\Z',time()+3600),'sent_at'=>gmdate('Y-m-d\TH:i:s\Z'),'confirmed'=>true]+$order;$out=scriptConvoys($p,$p,['id'=>2,'game_player_id'=>2],$declare);check($out['declared']&&scriptConvoys($p,$p,['id'=>2,'game_player_id'=>2],$declare)===$out,'already sent overage recovers once');
 echo "Overage OK: any amount, exact warnings, no write before consent, stale consent rejected, actual values, batches, production adjustments, surplus/cancellation, huge integers, replay, site and script.\n";
}finally{if($p->inTransaction())$p->rollBack();$p->exec('DROP SCHEMA '.$schema.' CASCADE');}
