<?php
declare(strict_types=1);
require '/home/uptep/private/app/bootstrap.php';
$p=db();
$names=['qa_permissions_admin','qa_permissions_member','qa_permissions_pending'];
if (($argv[1]??'')==='clean') {
    $p->prepare('UPDATE users SET approved_by = NULL WHERE username IN (?,?,?)')->execute($names);
    $p->prepare('DELETE FROM users WHERE username IN (?,?,?)')->execute($names);
    $q=$p->prepare('DELETE FROM login_limits WHERE bucket=?');
    foreach($names as $name) $q->execute([hash_hmac('sha256','user:'.$name,config()['rate_key'])]);
    if(is_file('/root/uptep-admin-tests.json')) unlink('/root/uptep-admin-tests.json');
    echo "Comptes de test supprimés.\n";exit;
}
$entries=[];
$p->beginTransaction();
try {
 foreach($names as $index=>$name) {
    $password=bin2hex(random_bytes(20));
    $q=$p->prepare('INSERT INTO users (username,display_name,password_hash,role,status,must_change_password) VALUES (?,?,?,?,?,false) RETURNING id');
    $q->execute([$name,$name,password_hash($password,PASSWORD_ARGON2ID),$index===0?'admin':'member',$index===2?'pending':'active']);
    $entries[]=['id'=>(int)$q->fetchColumn(),'username'=>$name,'password'=>$password];
 }
 umask(0077);file_put_contents('/root/uptep-admin-tests.json',json_encode($entries));
 $p->commit();echo "Comptes temporaires préparés.\n";
} catch(Throwable $e) {$p->rollBack();throw $e;}
