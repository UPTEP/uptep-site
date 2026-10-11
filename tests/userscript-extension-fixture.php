<?php
require '/home/uptep/private/app/bootstrap.php';require '/home/uptep/private/app/userscript.php';
$u=db()->query("SELECT id,session_version FROM users WHERE username='qa_chaine_editor'")->fetch(PDO::FETCH_ASSOC);$token=issueScriptToken($u);umask(0077);file_put_contents('/root/uptep-script-install-test.user.js',str_replace('__UPTEP_TOKEN__',json_encode($token),file_get_contents('/root/uptep-site/userscript/loader.user.js')));
