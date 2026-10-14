<?php
declare(strict_types=1);
require '/home/uptep/private/app/bootstrap.php';
require '/home/uptep/private/app/view.php';
require '/home/uptep/private/app/userscript.php';
$u=requireUser();$error='';$account=scriptAccount((int)$u['id']);$player=null;
if (isset($u['_preview'])) $account['script_enabled'] = $u['script_enabled'];
try{if($account['game_player_id'])$player=scriptPlayer((int)$account['game_player_id']);}catch(Throwable $e){$error='Le relevé du jeu est momentanément indisponible.';}
if($_SERVER['REQUEST_METHOD']==='POST')try{
 if(!validCsrf())throw new DomainException('Le formulaire a expiré. Recharge la page.',403);
 $_SESSION['script_install']=['token'=>issueScriptToken($u),'expires'=>time()+600];redirect('/script.php?ready=1');
}catch(DomainException $e){http_response_code($e->getCode());$error=$e->getMessage();}
catch(Throwable $e){error_log('UPTEP script install: '.$e->getMessage());http_response_code(503);$error='Installation momentanément indisponible.';}
$ready=isset($_SESSION['script_install'])&&$_SESSION['script_install']['expires']>=time();
workspaceStart($u,'UPTEP+'); ?>
<div class="account-page"><h1>UPTEP+</h1><p class="admin-intro">Le compagnon de l’alliance sur Fourmizzz · Serveur 5</p>
<?php if($error): ?><div class="form-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<section class="users-panel"><div class="users-panel-heading"><h2>Installer le userscript</h2></div><div class="account-settings">
<?php if(!$account['script_enabled']): ?><p>L’accès à UPTEP+ a été désactivé par un administrateur.</p>
<?php elseif(!$player): ?><p><a href="/compte.php">Renseigne ton ID dans Mon compte</a>. Il doit correspondre à un joueur UPTEP présent dans le relevé du serveur 5.</p>
<?php else: ?><p>Joueur associé : <strong><?= e($player['pseudo']) ?></strong> · ID <?= (int)$player['player_id'] ?></p>
<p>Installe un gestionnaire de userscripts comme Tampermonkey, Violentmonkey ou Greasemonkey, puis ajoute UPTEP+.</p>
<?php if($ready): ?><p><a class="save-user script-install-link" href="/uptep.user.js">Installer UPTEP+</a></p><p class="field-hint">Lien valable 10 minutes. Le fichier contient ta clé personnelle : ne le partage pas.</p>
<?php else: ?><form method="post" action="/script.php"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><button class="save-user" type="submit">Préparer mon installation</button></form><?php endif; ?>
<p>Une seule installation par navigateur. Les fonctionnalités sont chargées depuis UPTEP et se mettent à jour automatiquement.</p><p>Sur <a href="https://s5.fourmizzz.fr/" target="_blank" rel="noopener noreferrer">s5.fourmizzz.fr</a>, le menu <strong>UPTEP+</strong> apparaît à la suite des blocs de gauche lorsque ton accès est autorisé.</p>
<?php endif; ?></div></section></div><?php workspaceEnd(); ?>
