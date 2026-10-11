<?php
declare(strict_types=1);
require '/home/uptep/private/app/bootstrap.php';
require '/home/uptep/private/app/view.php';
require '/home/uptep/private/app/account.php';
$u=requireUser();$error='';$success=$_SESSION['account_saved']??false;unset($_SESSION['account_saved']);
$value=(string)($u['game_player_id']??'');
if($_SERVER['REQUEST_METHOD']==='POST') {
    $value=trim(post('game_player_id'));
    if(!validCsrf()) {http_response_code(403);$error='Le formulaire a expiré. Recharge la page avant de réessayer.';}
    elseif(!isset($_POST['game_player_id']) || !is_string($_POST['game_player_id'])) {http_response_code(422);$error='Indique un ID de joueur valide.';}
    else {
        $id=$value===''?null:filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($id===false) {http_response_code(422);$error='L’ID doit être un nombre entier positif.';}
        else try {saveGamePlayer($u,$id);$_SESSION['account_saved']=true;redirect('/compte.php');}
        catch(DomainException $ex) {http_response_code($ex->getCode());$error=$ex->getMessage();}
        catch(Throwable $ex) {error_log('UPTEP account save: '.$ex->getMessage());http_response_code(503);$error='Vérification du joueur indisponible. Réessaie dans quelques instants.';}
    }
}
$linked=null;$lookupError=false;
if($u['game_player_id']!==null)try{$linked=gamePlayer((int)$u['game_player_id']);}catch(Throwable $ex){$lookupError=true;error_log('UPTEP account read: '.$ex->getMessage());}
workspaceStart($u,'Mon compte');
?>
<div class="account-page"><h1>Mon compte</h1><p class="admin-intro"><?= e($u['display_name']) ?></p>
<?php if($error): ?><div class="form-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if($success): ?><div class="admin-success" role="status">Ton ID de joueur a été enregistré.</div><?php endif; ?>
<section class="users-panel"><div class="users-panel-heading"><h2>Mon joueur Fourmizzz</h2><span class="user-count">Serveur 5</span></div><div class="account-settings">
<p>Associe ton ID en jeu pour retrouver ta ligne et les cibles à portée dans la chaîne.</p>
<form method="post" action="/compte.php"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><label for="game-player-id">ID du joueur en jeu</label><div class="account-field"><input id="game-player-id" name="game_player_id" inputmode="numeric" pattern="[1-9][0-9]*" maxlength="19" value="<?= e($value) ?>" aria-describedby="game-id-help" autocomplete="off"><button class="save-user" type="submit">Enregistrer</button></div><p class="field-hint" id="game-id-help">Saisis ton identifiant numérique Fourmizzz, pas ton pseudo. Laisse vide pour retirer l’association.</p></form>
<?php if($linked): ?><p class="linked-player">Joueur associé : <strong><?= e($linked['pseudo']) ?></strong> · ID <?= (int)$linked['player_id'] ?><?php if($linked['alliance_tag']): ?> · <?= e($linked['alliance_tag']) ?><?php endif; ?></p>
<?php elseif($u['game_player_id']!==null): ?><p class="field-hint"><?= $lookupError?'Le relevé du jeu est momentanément indisponible.':'Ton ID est enregistré, mais ce joueur n’apparaît plus dans le dernier relevé.' ?></p><?php endif; ?>
<a class="account-chain-link" href="/chaine.php">Voir ma chaîne <?= icon('arrow') ?></a>
</div></section></div>
<?php workspaceEnd(); ?>
