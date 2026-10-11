<?php
require '/home/uptep/private/app/bootstrap.php';
require '/root/uptep-site/app/stats.php';
function check($v,$m){if(!$v)throw new RuntimeException($m);}
function denied($f,$code){try{$f();}catch(DomainException $e){check($e->getCode()===$code,'Wrong error');return;}throw new RuntimeException('Expected rejection');}
$input=[];foreach(STATS_GROUPS as $group=>[$title,$fields])foreach($fields as $key=>$label)$input[$group][$key]='';
$input['army']['jsn']='9 007 199 254 740 993';$input['buildings']['couveuse']='0';$data=statsValues($input);
check($data['army']['jsn']==='9007199254740993','Large integer precision');check($data['buildings']['couveuse']==='0','Zero preserved');check($data['research']['armes']===null,'Blank preserved');
foreach(['-1','1.5','1e4','9223372036854775808',[], '<script>'] as $bad){$invalid=$input;$invalid['army']['jsn']=$bad;denied(fn()=>statsValues($invalid),422);}
$invalid=$input;unset($invalid['research']['armes']);denied(fn()=>statsValues($invalid),422);
$p=db();$p->exec('CREATE TEMP TABLE users (LIKE public.users INCLUDING DEFAULTS INCLUDING CONSTRAINTS)');$p->exec('CREATE TEMP TABLE user_stats (LIKE public.user_stats INCLUDING DEFAULTS INCLUDING CONSTRAINTS INCLUDING INDEXES)');
$p->exec("INSERT INTO users(id,username,display_name,password_hash,role,status,must_change_password) VALUES (901,'statsone','One','test','member','active',false),(902,'statstwo','Two','test','member','active',false)");
$a=['id'=>901,'session_version'=>1];$b=['id'=>902,'session_version'=>1];
saveStats($a,$data,0);check(loadStats(901)['data']==$data,'Roundtrip');check(loadStats(902)['version']===0,'User isolation');
denied(fn()=>saveStats($a,$data,0),409);saveStats($a,$data,1);denied(fn()=>saveStats($a,$data,1),409);
saveStats($b,statsValues(array_replace($input,['army'=>array_fill_keys(array_keys($input['army']),'0')])),0);check(loadStats(901)['data']['army']['jsn']==='9007199254740993','Other user unaffected');
$p->exec("UPDATE users SET status='disabled' WHERE id=901");denied(fn()=>saveStats($a,$data,2),403);
echo "OK : valeurs, grands entiers, champs vides, persistance, isolation des comptes, concurrence et session révoquée.\n";
