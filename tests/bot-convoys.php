<?php
declare(strict_types=1);
define('UPTEP_BOT_ENV_FILE',__DIR__.'/test-admins.env');
require __DIR__.'/bot-convoys-service.php';
function connection(string $schema): PDO {$p=new PDO('pgsql:dbname=uptep','postgres',null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);$p->exec('SET search_path TO '.$schema);return $p;}
if(($argv[1]??'')==='cancel-worker'){
 $p=connection($argv[2]);try{botCancelConvoy($p,$p,$argv[3],['commande_id'=>$argv[4],'discord_id'=>'102']);echo '200';}catch(DomainException $e){echo $e->getCode();}exit;
}
if(($argv[1]??'')==='worker'){
 $p=connection($argv[2]);try{botConvoyAction($p,$p,'convoi',$argv[3],['commande_id'=>$argv[4],'discord_id'=>'102','nbpomme'=>'80','nbmateriaux'=>'0']);echo '201';}catch(DomainException $e){echo $e->getCode();}exit;
}
function check(bool $v,string $message): void{if(!$v)throw new RuntimeException($message);}
function rejects(callable $f,int $code): void{try{$f();throw new RuntimeException('Expected rejection '.$code);}catch(DomainException $e){check($e->getCode()===$code,'Wrong rejection '.$e->getCode().' '.$e->getMessage());}}
$schema='qa_bot_'.bin2hex(random_bytes(6));$p=connection('public');$p->exec('CREATE SCHEMA '.$schema);$p=connection($schema);
try{
 $sql=file_get_contents(__DIR__.'/bot-convoys-schema.sql');$sql=preg_replace('/^(ALTER TABLE .* OWNER TO|GRANT ).*;$/m','',$sql);$p->exec($sql);
 $p->exec("CREATE TABLE chaine_members(server text, player_id bigint, iddiscord bigint);CREATE TABLE players(server text, player_id bigint,pseudo text,alliance_tag text,is_present boolean);CREATE TABLE users(game_player_id bigint,status text,is_chaine boolean,must_change_password boolean);
 INSERT INTO chaine_members VALUES('s5',1,101),('s5',2,102),('s5',3,103),('s5',4,104);
 INSERT INTO players VALUES('s5',1,'Requestor','UPTEP',true),('s5',2,'Sender','UPTEP',true),('s5',3,'Validator','UPTEP',true),('s5',4,'Former','OTHER',true);
 INSERT INTO users VALUES(3,'active',true,false),(1,'active',false,false);");
 $create=['commande_id'=>'create-1','discord_id'=>'101','nbpomme'=>'100','nbmateriaux'=>'200'];
 [$status,$out]=botConvoyAction($p,$p,'demande',null,$create);check($status===201,'create status');$id=$out['demande']['id'];check($out['demande']['etat']==='attente','initial state');check(is_string($out['demande']['iddemandeur']),'IDs string');
 [$s,$replay,$yes]=botConvoyAction($p,$p,'demande',null,$create);check($yes&&$replay==$out,'create replay');
 rejects(fn()=>botConvoyAction($p,$p,'demande',null,array_replace($create,['nbpomme'=>'101'])),409);
 rejects(fn()=>botConvoyAction($p,$p,'demande',null,array_replace($create,['commande_id'=>'bad-quantity','nbpomme'=>'9223372036854775808'])),422);
 rejects(fn()=>botConvoyAction($p,$p,'demande',null,array_replace($create,['commande_id'=>'former','discord_id'=>'104'])),403);
 rejects(fn()=>botConvoyAction($p,$p,'validation',$id,['commande_id'=>'unauthorized','discord_id'=>'101']),403);
 $send=['commande_id'=>'send-1','discord_id'=>'102','nbpomme'=>'40','nbmateriaux'=>'50'];
 rejects(fn()=>botConvoyAction($p,$p,'convoi',$id,$send),409);
 check(count(botListDemands($p,['etat'=>'attente'])['demandes'])===1,'pending list');
 $validation=['commande_id'=>'validate-1','discord_id'=>'103'];botConvoyAction($p,$p,'validation',$id,$validation);check(botConvoyAction($p,$p,'validation',$id,$validation)[2],'validation replay');
 check(count(botListDemands($p,['etat'=>'en_cours'])['demandes'])===1,'ongoing list');
 rejects(fn()=>botConvoyAction($p,$p,'convoi',$id,array_replace($send,['discord_id'=>'101'])),422);
 rejects(fn()=>botConvoyAction($p,$p,'convoi',$id,array_replace($send,['date_convoi'=>'2026-02-31T10:00:00Z'])),422);
 $r=botConvoyAction($p,$p,'convoi',$id,$send)[1];check($r['demande']['nbpomme_restant']==='60'&&$r['demande']['nbmateriaux_restant']==='150','partial convoy');
 check(botConvoyAction($p,$p,'convoi',$id,$send)[2],'convoy replay');
 rejects(fn()=>botConvoyAction($p,$p,'convoi',$id,array_replace($send,['commande_id'=>'overflow','nbpomme'=>'61'])),409);
 $r=botConvoyAction($p,$p,'convoi',$id,array_replace($send,['commande_id'=>'finish','nbpomme'=>'60','nbmateriaux'=>'150']))[1];check($r['demande']['fini']&&$r['demande']['date_fin']!==null,'completed');
 check(count(botListDemands($p,[])['demandes'])===0,'completed excluded');
 $history=botListConvoys($p,$id,[]);check(count($history['convois'])===2,'completed request history');check($history['convois'][0]['nbpomme']==='40','exact amount string');check($history['convois'][0]['idenvoyeur']==='2','sender string');
 $first=botListConvoys($p,$id,['limite'=>'1']);check(count($first['convois'])===1&&$first['suite_apres']!==null,'convoy pagination');
 $second=botListConvoys($p,$id,['apres'=>$first['suite_apres']]);check(count($second['convois'])===1&&$second['suite_apres']===null,'last convoy page');
 rejects(fn()=>botListConvoys($p,'9223372036854775807',[]),404);
 rejects(fn()=>botListConvoys($p,$id,['limite'=>'101']),422);
 rejects(fn()=>botListConvoys($p,$id,['apres'=>'-1']),422);

 rejects(fn()=>botConvoyAction($p,$p,'convoi',$id,array_replace($send,['commande_id'=>'after-finish'])),409);
 // Two real concurrent connections compete for 100 units; only one 80-unit convoy can commit.
 $id2=botConvoyAction($p,$p,'demande',null,array_replace($create,['commande_id'=>'parallel-create','nbmateriaux'=>'0']))[1]['demande']['id'];
 check(botListConvoys($p,$id2,[])['convois']===[],'empty history');
 botConvoyAction($p,$p,'validation',$id2,['commande_id'=>'parallel-validation','discord_id'=>'103']);
 $workers=[];foreach(['parallel-a','parallel-b'] as $command){$pipes=[];$proc=proc_open([PHP_BINARY,__FILE__,'worker',$schema,$id2,$command],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$workers[]=[$proc,$pipes];}
 $codes=[];foreach($workers as [$proc,$pipes]){$codes[]=trim(stream_get_contents($pipes[1]));$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($proc)===0,'worker '.$err);}sort($codes);check($codes===['201','409'],'parallel oversend protection');check(botDemand($p,$id2)['nbpomme_restant']==='20','atomic remaining');
 // Simultaneous retries produce exactly one persisted convoy.
 $id3=botConvoyAction($p,$p,'demande',null,array_replace($create,['commande_id'=>'retry-create','nbmateriaux'=>'0']))[1]['demande']['id'];
 botConvoyAction($p,$p,'validation',$id3,['commande_id'=>'retry-validation','discord_id'=>'103']);
 $workers=[];foreach([1,2] as $_){$pipes=[];$proc=proc_open([PHP_BINARY,__FILE__,'worker',$schema,$id3,'same-retry'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$workers[]=[$proc,$pipes];}
 foreach($workers as [$proc,$pipes]){check(trim(stream_get_contents($pipes[1]))==='201','concurrent replay status');fclose($pipes[1]);fclose($pipes[2]);check(proc_close($proc)===0,'concurrent replay process');}
 check(botDemand($p,$id3)['nbpomme_restant']==='20','concurrent replay deducted once');
 $q=$p->prepare('SELECT count(*) FROM convois_effectues WHERE iddemande=?');$q->execute([$id3]);check((int)$q->fetchColumn()===1,'one convoy for concurrent retries');
 $page=botListDemands($p,['limite'=>'1']);check(count($page['demandes'])===1&&$page['suite_apres']!==null,'pagination first page');check(count(botListDemands($p,['apres'=>$page['suite_apres']])['demandes'])===1,'pagination next page');
 check(botMember($p,$p,'103')['membre']['is_chaine']===true,'member permission');
 check(botMember($p,$p,'101')['membre']['is_chaine']===false,'ordinary member');
 $metadata=array_replace($create,['commande_id'=>'metadata','objet'=>'Test — Dépôt','niveau'=>'20','date_souhaitee'=>date('Y-m-d',time()+86400)]);
 $new=botConvoyAction($p,$p,'demande',null,$metadata)[1]['demande'];$id4=$new['id'];check($new['objet']==='Test — Dépôt'&&(string)$new['niveau']==='20','metadata');
 rejects(fn()=>botConvoyAction($p,$p,'annulation',$id4,['commande_id'=>'cancel-unauthorized','discord_id'=>'102']),403);
 $cancel=['commande_id'=>'cancel-own','discord_id'=>'101'];
 check(botConvoyAction($p,$p,'annulation',$id4,$cancel)[1]['demande']['etat']==='annulee','cancel own');
 check(botConvoyAction($p,$p,'annulation',$id4,$cancel)[2],'cancel replay');
 rejects(fn()=>botConvoyAction($p,$p,'validation',$id4,['commande_id'=>'validate-cancelled','discord_id'=>'103']),409);
 rejects(fn()=>botConvoyAction($p,$p,'annulation',$id,['commande_id'=>'cancel-finished','discord_id'=>'103']),409);
 $future=gmdate('Y-m-d\TH:i:s\Z',time()+3600);
 $r=botConvoyAction($p,$p,'convoi',$id3,['commande_id'=>'arrival','discord_id'=>'102','nbpomme'=>'1','nbmateriaux'=>'0','date_arrivee'=>$future]);check($r[1]['convoi']['date_arrivee']!==null,'future arrival');
 $list=botListDemands($p,['include'=>'convois','terminees_depuis'=>gmdate('Y-m-d\TH:i:s\Z',time()-3600)])['demandes'];
 $finished=array_values(array_filter($list,fn($d)=>$d['id']===$id));check(count($finished)===1&&count($finished[0]['convois'])===2,'grouped completed convoys');
 check(!array_filter($list,fn($d)=>$d['id']===$id4),'cancelled excluded');
 check(botConvoyAction($p,$p,'annulation',$id2,['commande_id'=>'cancel-manager','discord_id'=>'103'])[1]['demande']['annulee'],'manager cancel');
 // Cancellation restores exact balances once, keeps history, and reopens automatically finished requests.
 $convoyId=$history['convois'][0]['id'];$cancelBody=['commande_id'=>'cancel-convoy-own','discord_id'=>'102'];
 rejects(fn()=>botCancelConvoy($p,$p,$convoyId,['commande_id'=>'cancel-convoy-forbidden','discord_id'=>'101']),403);
 $r=botCancelConvoy($p,$p,$convoyId,$cancelBody)[1];check($r['demande']['nbpomme_restant']==='40'&&$r['demande']['nbmateriaux_restant']==='50'&&!$r['demande']['fini'],'cancel restores/reopens');
 check(botCancelConvoy($p,$p,$convoyId,$cancelBody)[2],'cancellation replay');
 check(botDemand($p,$id)['nbpomme_restant']==='40','cancel replay no extra credit');
 rejects(fn()=>botCancelConvoy($p,$p,$convoyId,array_replace($cancelBody,['commande_id'=>'cancel-again'])),409);
 check(botListConvoys($p,$id,[])['convois'][0]['annule']===true,'cancel history retained');
 check(botCancelableConvoys($p,$p,['discord_id'=>'101'])['convois']===[],'requester cannot cancel sender convoys');
 check(!array_filter(botCancelableConvoys($p,$p,['discord_id'=>'102'])['convois'],fn($c)=>$c['id']===$convoyId),'cancelled hidden from selector');
 // Manual closure keeps the unpaid balance. Cancelling a convoy cannot reopen it.
 $finish=['commande_id'=>'manual-finish','discord_id'=>'101'];
 rejects(fn()=>botConvoyAction($p,$p,'terminaison',$id,['commande_id'=>'forbidden-finish','discord_id'=>'102']),403);
 $r=botConvoyAction($p,$p,'terminaison',$id,$finish)[1]['demande'];check($r['fin_manuelle']&&$r['fini']&&$r['nbpomme_restant']==='40','manual finish preserves balances');
 check(botConvoyAction($p,$p,'terminaison',$id,$finish)[2],'finish replay');
 $r=botCancelConvoy($p,$p,$history['convois'][1]['id'],['commande_id'=>'cancel-manager-convoy','discord_id'=>'103'])[1]['demande'];check($r['fini']&&$r['nbpomme_restant']==='100'&&$r['nbmateriaux_restant']==='200','manual finish remains closed after convoy cancellation');
 rejects(fn()=>botCancelConvoy($p,$p,'9223372036854775807',['commande_id'=>'missing-convoy','discord_id'=>'102']),404);
 $cancelledHistory=botListConvoys($p,$id2,[])['convois'];
 $r=botCancelConvoy($p,$p,$cancelledHistory[0]['id'],['commande_id'=>'cancel-on-cancelled-demand','discord_id'=>'102'])[1]['demande'];check($r['annulee']&&!$r['fini'],'cancelled request stays closed');
 // Two independent cancellation commands cannot credit twice.
 $idConcurrent=botConvoyAction($p,$p,'demande',null,array_replace($create,['commande_id'=>'cancel-parallel-create','nbmateriaux'=>'0']))[1]['demande']['id'];
 botConvoyAction($p,$p,'validation',$idConcurrent,['commande_id'=>'cancel-parallel-validate','discord_id'=>'103']);
 $cid=botConvoyAction($p,$p,'convoi',$idConcurrent,['commande_id'=>'cancel-parallel-send','discord_id'=>'102','nbpomme'=>'80','nbmateriaux'=>'0'])[1]['convoi']['id'];
 $workers=[];foreach(['cancel-parallel-a','cancel-parallel-b'] as $command){$pipes=[];$proc=proc_open([PHP_BINARY,__FILE__,'cancel-worker',$schema,$cid,$command],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$workers[]=[$proc,$pipes];}
 $codes=[];foreach($workers as [$proc,$pipes]){$codes[]=trim(stream_get_contents($pipes[1]));$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($proc)===0,'cancel worker '.$err);}sort($codes);check($codes===['200','409'],'parallel cancellation protection');check(botDemand($p,$idConcurrent)['nbpomme_restant']==='100','parallel cancellation credited once');
 $p->exec("UPDATE users SET is_chaine=false WHERE game_player_id=3");rejects(fn()=>botConvoyAction($p,$p,'validation',$id,$validation),403);

 // Allowlist grants only admin privileges; unique association and current membership still apply.
 file_put_contents(UPTEP_BOT_ENV_FILE,"ADMIN_DISCORD_IDS=102, 102, 104, 999\n");
 check(botConfiguredAdmins()===['102','104','999'],'allowlist deduplicated');
 check(botMember($p,$p,'102')['membre']['is_chaine']===true,'allowlisted associated member is admin without site account');
 rejects(fn()=>botMember($p,$p,'999'),403);
 rejects(fn()=>botMember($p,$p,'104'),403);
 $testId=botConvoyAction($p,$p,'demande',null,array_replace($create,['commande_id'=>'allowlist-create']))[1]['demande']['id'];
 $allowValidation=['commande_id'=>'allowlist-validate','discord_id'=>'102'];
 check(botConvoyAction($p,$p,'validation',$testId,$allowValidation)[1]['demande']['valide'],'allowlist validation');
 file_put_contents(UPTEP_BOT_ENV_FILE,"ADMIN_DISCORD_IDS=\n");
 check(!botMember($p,$p,'102')['membre']['is_chaine'],'allowlist removal immediate');
 rejects(fn()=>botConvoyAction($p,$p,'validation',$testId,$allowValidation),403);
 $p->exec("UPDATE users SET is_chaine=true WHERE game_player_id=3");
 check(botMember($p,$p,'103')['membre']['is_chaine'],'database role independent of empty allowlist');
 file_put_contents(UPTEP_BOT_ENV_FILE,"ADMIN_DISCORD_IDS=103,103\n");check(botMember($p,$p,'103')['membre']['is_chaine'],'overlapping roles accepted');
 file_put_contents(UPTEP_BOT_ENV_FILE,"ADMIN_DISCORD_IDS=102,not-an-id\n");check(botConfiguredAdmins()===[],'invalid allowlist fails closed');
 unlink(UPTEP_BOT_ENV_FILE);
 // Adjustments reduce or correct the remaining need, without inventing convoy deliveries.
 $adjId=botConvoyAction($p,$p,'demande',null,array_replace($create,['commande_id'=>'adjust-create']))[1]['demande']['id'];
 $adjust=['commande_id'=>'adjust-1','discord_id'=>'101','nbpomme_restant'=>'30','nbmateriaux_restant'=>'80','nbpomme_attendu'=>'60','nbmateriaux_attendu'=>'150'];
 rejects(fn()=>botConvoyAction($p,$p,'ajustement',$adjId,$adjust),409);
 botConvoyAction($p,$p,'validation',$adjId,['commande_id'=>'adjust-validate','discord_id'=>'103']);
 $sendId=botConvoyAction($p,$p,'convoi',$adjId,['commande_id'=>'adjust-send','discord_id'=>'102','nbpomme'=>'40','nbmateriaux'=>'50'])[1]['convoi']['id'];
 rejects(fn()=>botConvoyAction($p,$p,'ajustement',$adjId,array_replace($adjust,['discord_id'=>'102'])),403);
 rejects(fn()=>botConvoyAction($p,$p,'ajustement',$adjId,array_replace($adjust,['nbpomme_restant'=>'61'])),422);
 rejects(fn()=>botConvoyAction($p,$p,'ajustement',$adjId,array_replace($adjust,['nbpomme_restant'=>'-1'])),422);
 $adjusted=botConvoyAction($p,$p,'ajustement',$adjId,$adjust)[1]['demande'];
 check($adjusted['nbpomme_restant']==='30'&&$adjusted['nbmateriaux_restant']==='80'&&$adjusted['nbpomme_original']==='100','remaining adjusted without altering original');
 check(count($adjusted['ajustements'])===1&&$adjusted['ajustements'][0]['pommes_avant']==='60','audit trail');
 check(botConvoyAction($p,$p,'ajustement',$adjId,$adjust)[2],'adjustment idempotent replay');
 check(count(botListConvoys($p,$adjId,[])['convois'])===1,'adjustment creates no convoy');
 rejects(fn()=>botConvoyAction($p,$p,'ajustement',$adjId,array_replace($adjust,['commande_id'=>'adjust-stale'])),409);
 $up=array_replace($adjust,['commande_id'=>'adjust-correct','discord_id'=>'103','nbpomme_attendu'=>'30','nbmateriaux_attendu'=>'80','nbpomme_restant'=>'50','nbmateriaux_restant'=>'100']);
 check(botConvoyAction($p,$p,'ajustement',$adjId,$up)[1]['demande']['nbpomme_restant']==='50','responsible can correct within original minus sent');
 botConvoyAction($p,$p,'convoi',$adjId,['commande_id'=>'adjust-new-send','discord_id'=>'102','nbpomme'=>'10','nbmateriaux'=>'10']);
 rejects(fn()=>botConvoyAction($p,$p,'ajustement',$adjId,array_replace($up,['commande_id'=>'adjust-after-send','nbpomme_attendu'=>'50','nbmateriaux_attendu'=>'100'])),409);
 $zero=array_replace($adjust,['commande_id'=>'adjust-zero','nbpomme_attendu'=>'40','nbmateriaux_attendu'=>'90','nbpomme_restant'=>'0','nbmateriaux_restant'=>'0']);
 $closed=botConvoyAction($p,$p,'ajustement',$adjId,$zero)[1]['demande'];check($closed['fini']&&$closed['fin_manuelle'],'zero remaining closes manually');
 $cancelled=botCancelConvoy($p,$p,$sendId,['commande_id'=>'adjust-cancel-send','discord_id'=>'102'])[1]['demande'];check($cancelled['fini']&&$cancelled['nbpomme_restant']==='40','cancellation credits real send, manual closure retained');
 rejects(fn()=>botConvoyAction($p,$p,'ajustement',$adjId,array_replace($zero,['commande_id'=>'adjust-closed'])),409);
 echo "OK: adjustment ownership, admin permissions, bounds, stale forms, audit, idempotency and unchanged convoy accounting.\n";
 require __DIR__.'/bot-reports-checks.php';
 echo "OK: creation, validation permissions, membership, precise integers, pagination filters, partial/completed convoys, replays/conflicts, date checks and concurrent over-send protection.\n";
}finally{$p->exec('DROP SCHEMA '.$schema.' CASCADE');}
