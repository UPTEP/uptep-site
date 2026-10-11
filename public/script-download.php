<?php
declare(strict_types=1);
require '/home/uptep/private/app/bootstrap.php';
require '/home/uptep/private/app/userscript.php';
$u=requireUser();$install=$_SESSION['script_install']??null;
if(!$install || $install['expires']<time())redirect('/script.php');
$account=scriptAccount((int)$u['id']);$player=$account['game_player_id']?scriptPlayer((int)$account['game_player_id']):null;
try{authorizeScript($install['token'],$player['pseudo']??'','UPTEP');}catch(DomainException $e){http_response_code(403);echo 'Installation non autorisée.';exit;}
header('Content-Type: application/javascript; charset=utf-8');header('Content-Disposition: inline; filename="uptep.user.js"');
echo str_replace('__UPTEP_TOKEN__',json_encode($install['token'],JSON_THROW_ON_ERROR),file_get_contents('/home/uptep/private/app/script-loader.user.js'));
