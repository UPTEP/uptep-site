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
<link rel="stylesheet" href="/assets/script-install.css?v=2">
<div class="script-setup"><div class="setup-heading"><h1>UPTEP+</h1><span class="setup-badge">Fourmizzz · S5</span></div><p class="admin-intro">Les outils de l’alliance, directement dans ton jeu.</p>
<?php if($error): ?><div class="form-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if(!$account['script_enabled']): ?>
<section class="setup-notice"><strong>Accès désactivé</strong><p>Contacte un administrateur pour accéder à UPTEP+.</p></section>
<?php elseif(!$player): ?>
<section class="setup-notice"><strong>Associe ton joueur S5</strong><p>Ton ID doit correspondre à un membre UPTEP présent dans le relevé du jeu.</p><a href="/compte.php" class="save-user">Ouvrir mon compte</a></section>
<?php else: ?>
<div class="setup-player"><?= icon('shield') ?><span>Joueur associé <strong><?= e($player['pseudo']) ?></strong></span><span class="setup-player-id">ID <?= (int)$player['player_id'] ?></span></div>
<section class="setup-panel" aria-labelledby="setup-title"><div class="setup-panel-heading"><h2 id="setup-title">Prêt à installer UPTEP+ ?</h2><span class="setup-badge">1 installation par navigateur</span></div>
<ol class="setup-steps">
<li><span class="setup-number">1</span><div><h3>Ajoute un gestionnaire</h3><p>Choisis une seule extension pour ton navigateur.</p><div class="setup-browsers"><section class="setup-browser" aria-label="Extensions Chrome"><h4>Chrome</h4><div class="setup-extension-links"><a href="https://chromewebstore.google.com/detail/tampermonkey/dhdgffkkebhmkfjojejmpbldmpobfkfo" target="_blank" rel="noopener noreferrer" aria-label="Installer Tampermonkey pour Chrome (nouvel onglet)">Tampermonkey <span aria-hidden="true">↗</span></a><a href="https://chromewebstore.google.com/detail/violent-monkey/jinjaccalgkegednnccohejagnlnfdag" target="_blank" rel="noopener noreferrer" aria-label="Installer Violentmonkey pour Chrome (nouvel onglet)">Violentmonkey <span aria-hidden="true">↗</span></a></div></section><section class="setup-browser" aria-label="Extensions Firefox"><h4>Firefox</h4><div class="setup-extension-links"><a href="https://addons.mozilla.org/fr/firefox/addon/tampermonkey/" target="_blank" rel="noopener noreferrer" aria-label="Installer Tampermonkey pour Firefox (nouvel onglet)">Tampermonkey <span aria-hidden="true">↗</span></a><a href="https://addons.mozilla.org/fr/firefox/addon/violentmonkey/" target="_blank" rel="noopener noreferrer" aria-label="Installer Violentmonkey pour Firefox (nouvel onglet)">Violentmonkey <span aria-hidden="true">↗</span></a><a href="https://addons.mozilla.org/fr/firefox/addon/greasemonkey/" target="_blank" rel="noopener noreferrer" aria-label="Installer Greasemonkey pour Firefox (nouvel onglet)">Greasemonkey <span aria-hidden="true">↗</span></a></div></section></div></div></li>
<li class="setup-step-main"><span class="setup-number">2</span><div><h3>Installe UPTEP+</h3><p>Prépare ton lien, puis confirme l’installation dans ton extension.</p>
<?php if($ready): ?><a class="save-user script-install-link setup-action" href="/uptep.user.js"><?= icon('arrow') ?> Installer UPTEP+</a><p class="setup-key-note"><?= icon('lock') ?> Lien valable 10 min · Clé personnelle, ne pas partager.</p>
<?php else: ?><form method="post" action="/script.php"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><button class="save-user setup-action" type="submit"><?= icon('arrow') ?> Préparer mon installation</button></form><?php endif; ?>
</div></li>
<li><span class="setup-number">3</span><div><h3>Retrouve tes outils en jeu</h3><p>Le menu <strong>UPTEP+</strong> apparaît sous les blocs de gauche.</p><a class="setup-game-link" href="https://s5.fourmizzz.fr/" target="_blank" rel="noopener noreferrer">Ouvrir Fourmizzz S5 <?= icon('arrow') ?></a></div></li>
</ol>
</section>
<div class="setup-reassurance"><span><?= icon('lock') ?> Collectes facultatives à activer</span><span><?= icon('arrow') ?> Fonctions actualisées au chargement</span></div>
<details class="setup-details"><summary>Paramètres et informations utiles</summary><p>Pour les nouveaux comptes, les collectes de ressources, d’armée, de bâtiments et de laboratoire sont désactivées par défaut. Tes choix existants sont conservés lors d’une réinstallation.</p><p>Les fonctionnalités sont chargées depuis UPTEP à chaque chargement de page. Une seule installation suffit par navigateur.</p></details>
<nav class="setup-legal" aria-label="Informations avant installation"><span>Avant installation :</span> <a href="/cgu.html">Conditions d’utilisation</a><span aria-hidden="true">·</span><a href="/confidentialite.html">Données et confidentialité</a></nav>
<?php endif; ?></div><?php workspaceEnd(); ?>
