<?php
require '/home/uptep/private/app/bootstrap.php';
$p=db();$names=['qa_chaine_editor','qa_chaine_reader'];
if(($argv[1]??'')==='clean') {
    // Remove only the unchanged blank row inserted by the UI test.
    $p->exec("DELETE FROM chaine_members WHERE updated_by IN (SELECT id FROM users WHERE username='qa_chaine_editor') AND grade IS NULL AND sort_order IS NULL AND version=1");
    $p->prepare('DELETE FROM users WHERE username IN (?,?)')->execute($names);
    $q=$p->prepare('DELETE FROM login_limits WHERE bucket=?');foreach($names as $name)$q->execute([hash_hmac('sha256','user:'.$name,config()['rate_key'])]);
    if(is_file('/root/uptep-chaine-tests.json'))unlink('/root/uptep-chaine-tests.json');
    echo "Comptes de test supprimés.\n";exit;
}
$out=[];
foreach($names as $i=>$name) {
    $password=bin2hex(random_bytes(20));
    $q=$p->prepare('INSERT INTO users (username,display_name,password_hash,role,is_chaine,must_change_password) VALUES (?,?,?,?,?,false) RETURNING id');
    $q->execute([$name,$name,password_hash($password,PASSWORD_ARGON2ID),$i===0?'member':'admin',$i===0?'true':'false']);
    $out[]=['id'=>(int)$q->fetchColumn(),'username'=>$name,'password'=>$password];
}
umask(0077);file_put_contents('/root/uptep-chaine-tests.json',json_encode($out));echo "Comptes temporaires prêts.\n";
