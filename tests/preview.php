<?php
declare(strict_types=1);
require __DIR__.'/../app/preview.php';
function check(bool $v,string $label):void{if(!$v)throw new RuntimeException($label);}
$admin=['id'=>1,'role'=>'admin','status'=>'active','must_change_password'=>false,'is_chef'=>true,'is_chaine'=>true,'script_enabled'=>true,'display_name'=>'Admin'];
$member=array_replace($admin,['id'=>2,'role'=>'member','is_chef'=>false,'is_chaine'=>false,'display_name'=>'Membre']);
$state=['actor_id'=>1,'mode'=>'profile','profile'=>'member','script_enabled'=>false];
check(previewResolve($member,$state,fn()=>null)===null,'member cannot preview');
check(previewResolve($admin,array_replace($state,['actor_id'=>2]),fn()=>null)===null,'state bound to real admin');
check(previewResolve(array_replace($admin,['must_change_password'=>true]),$state,fn()=>null)===null,'password restriction');
foreach(PREVIEW_PROFILES as $profile=>[$label,$chef,$chaine]){
 $u=previewResolve($admin,array_replace($state,['profile'=>$profile]),fn()=>null);
 check($u['role']==='member'&&$u['is_chef']===$chef&&$u['is_chaine']===$chaine&&!$u['script_enabled'],'exact cumulative profile rights');
 check($u['id']===1&&$admin['role']==='admin','real actor unchanged');
}
$state=['actor_id'=>1,'mode'=>'account','user_id'=>2];
$u=previewResolve($admin,$state,fn($id)=>$id===2?$member:null);check($u['id']===2&&isset($u['_preview']),'real member data');
check(previewResolve($admin,$state,fn()=>null)===null,'deleted account');
check(previewResolve($admin,$state,fn()=>array_replace($member,['status'=>'disabled']))===null,'disabled target');
$u=previewResolve($admin,$state,fn()=>array_replace($member,['is_chaine'=>true]));check($u['is_chaine'],'target permissions freshly loaded');
check(!previewControlRequest('/stats.php','preview_stop')&&!previewControlRequest('/admin.php','script'),'no write route exempted');
check(previewControlRequest('/admin.php','preview_stop')&&previewControlRequest('/admin.php','preview_start'),'explicit controls');
echo "OK: admin-only preview, actor binding, cumulative rights, account identity, fresh rights, target revocation and route control\n";
