<?php
declare(strict_types=1);
require '/home/uptep/private/app/bootstrap.php';
require '/root/uptep-site/app/administration.php';
// A session-local temporary table shadows users. No real accounts are modified.
$p = db();
$p->exec('CREATE TEMP TABLE users (LIKE public.users INCLUDING DEFAULTS INCLUDING CONSTRAINTS)');
$p->exec("INSERT INTO users (id,username,display_name,password_hash,role,status,must_change_password) VALUES (901,'testadmin','Test Admin','test','admin','active',false),(902,'testmember','Test Member','test','member','pending',false)");
function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function rejected(callable $fn, int $code): void { try { $fn(); } catch (DomainException $e) { check($e->getCode() === $code, 'Unexpected rejection'); return; } throw new RuntimeException('Expected rejection'); }
$actor = ['id'=>901,'session_version'=>1];
rejected(fn()=>saveUserPermissions(['id'=>902,'session_version'=>1],902,1,true,true,true,true),403);
rejected(fn()=>saveUserPermissions($actor,901,1,false,false,false,true),422);
rejected(fn()=>saveUserPermissions($actor,901,1,true,false,false,false),422);
saveUserPermissions($actor,902,1,false,true,true,true);
$m=$p->query('SELECT * FROM users WHERE id=902')->fetch(PDO::FETCH_ASSOC);
check($m['role']==='member' && $m['is_chef'] && $m['is_chaine'] && $m['status']==='active','Independent rights and approval');
check((int)$m['approved_by']===901 && $m['approved_at']!==null && (int)$m['session_version']===2,'Approval metadata and session version');
rejected(fn()=>saveUserPermissions($actor,902,1,true,false,false,true),409);
saveUserPermissions($actor,902,2,true,true,true,true);
check($p->query('SELECT role FROM users WHERE id=902')->fetchColumn()==='admin','Grant administrator');
saveUserPermissions($actor,901,1,false,false,false,true);
rejected(fn()=>saveUserPermissions($actor,902,3,false,false,false,true),403);
saveUserPermissions(['id'=>902,'session_version'=>2],901,2,false,false,false,false);
$m=$p->query('SELECT * FROM users WHERE id=901')->fetch(PDO::FETCH_ASSOC);
check($m['status']==='pending' && $m['approved_at']===null && $m['approved_by']===null && (int)$m['session_version']===2,'Revocation');
check($p->query("SELECT count(*) FROM public.users WHERE id IN (901,902)")->fetchColumn()===0,'Real user table unaffected');
echo "OK: droits cumulables, validation/révocation, autorisation serveur, dernier admin et formulaires concurrents.\n";
