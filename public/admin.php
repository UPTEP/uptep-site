<?php
declare(strict_types=1);
require '/home/uptep/private/app/bootstrap.php';
require '/home/uptep/private/app/view.php';
require '/home/uptep/private/app/administration.php';
require '/home/uptep/private/app/userscript.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && previewControlRequest('/admin.php', post('action'))) {
    $actor = authenticatedUser();
    if (!$actor || $actor['role'] !== 'admin' || $actor['must_change_password']) { http_response_code(403); exit('Accès réservé aux administrateurs.'); }
    if (!validCsrf()) { http_response_code(403); exit('Le formulaire a expiré. Recharge la page.'); }
    if (post('action') === 'preview_stop') { unset($_SESSION['preview']); redirect('/admin.php#visualisation'); }
    $state = ['actor_id' => (int)$actor['id'], 'mode' => post('mode')];
    if (post('mode') === 'account') {
        $id = filter_var(post('user_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $target = $id ? userRecord($id) : null;
        if (!$target || $target['status'] !== 'active') { http_response_code(422); exit('Choisis un compte actif pour le visualiser.'); }
        $state['user_id'] = (int)$id;
    } elseif (post('mode') === 'profile' && isset(PREVIEW_PROFILES[post('profile')])) {
        $state['profile'] = post('profile'); $state['script_enabled'] = post('script_enabled') === '1';
    } else { http_response_code(422); exit('Profil de visualisation invalide.'); }
    $_SESSION['preview'] = $state;
    unset($_SESSION['script_install']);
    redirect('/');
}
$u = requireUser();
if ($u['role'] !== 'admin') {
    http_response_code(403); workspaceStart($u, 'Accès réservé');
    echo '<h1>Accès réservé</h1><p>Cette page est réservée aux administrateurs.</p>';
    workspaceEnd(); exit;
}
$error = ''; $success = $_SESSION['permissions_saved'] ?? '';
unset($_SESSION['permissions_saved']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validCsrf()) { http_response_code(403); $error = 'Le formulaire a expiré. Recharge la page avant de réessayer.'; }
    else {
        $targetId = filter_var(post('user_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $version = filter_var(post('version'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $valid = $targetId !== false && $version !== false;
        foreach (['admin', 'chef', 'chaine', 'approved'] as $key) {
            if (isset($_POST[$key]) && (!is_string($_POST[$key]) || $_POST[$key] !== '1')) $valid = false;
        }
        if (!$valid) { http_response_code(400); $error = 'Le formulaire est invalide. Recharge la page avant de réessayer.'; }
        else try {
            if(post('action')==='script'){
                if(!in_array(post('script_enabled'),['0','1'],true))throw new DomainException('Formulaire invalide.',400);
                setScriptAccess($u,$targetId,$version,post('script_enabled')==='1');
                $_SESSION['permissions_saved']='L’accès UPTEP+ a été '.(post('script_enabled')==='1'?'activé.':'désactivé.');redirect('/admin.php');
            }
            $name = saveUserPermissions($u, $targetId, $version, post('admin') === '1', post('chef') === '1', post('chaine') === '1', post('approved') === '1');
            $current = user();
            if (!$current) redirect('/login.php');
            if ($current['role'] !== 'admin') redirect('/');
            $_SESSION['permissions_saved'] = 'Les droits de '.$name.' ont été enregistrés.';
            redirect('/admin.php');
        } catch (DomainException $ex) {
            http_response_code($ex->getCode()); $error = $ex->getMessage();
            if ($ex->getCode() === 403) { workspaceStart($u, 'Accès réservé'); echo '<h1>Accès réservé</h1><p>'.e($error).'</p>'; workspaceEnd(); exit; }
        }
    }
}
$users = db()->query('SELECT id, username, display_name, role, is_chef, is_chaine, status, script_enabled, permissions_version FROM users ORDER BY lower(display_name), id')->fetchAll(PDO::FETCH_ASSOC);
workspaceStart($u, 'Administration');
?>
<div class="administration"><h1>Administration</h1><p class="admin-intro">Gère les droits et la validation des utilisateurs.</p>
<section class="users-panel preview-panel" id="visualisation" aria-labelledby="preview-title">
<div class="users-panel-heading"><h2 id="preview-title">Mode visualisation</h2></div>
<div class="account-settings"><p>Choisis un profil pour essayer ses droits avec tes données, ou utilise « Voir comme » sur un compte pour afficher ses données et ses droits actuels. Les modifications sont bloquées pendant l’aperçu.</p>
<form method="post" action="/admin.php" class="preview-controls">
<input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="preview_start"><input type="hidden" name="mode" value="profile">
<label>Profil<select name="profile"><?php foreach (PREVIEW_PROFILES as $key => [$label]): ?><option value="<?= e($key) ?>"><?= e($label) ?></option><?php endforeach; ?></select></label>
<label><input type="checkbox" name="script_enabled" value="1" checked> UPTEP+ autorisé</label>
<button class="save-user" type="submit">Visualiser ce profil</button>
</form></div></section>
<?php if ($error): ?><div class="form-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="admin-success" role="status"><?= e($success) ?></div><?php endif; ?>
<section class="users-panel" aria-labelledby="users-title"><div class="users-panel-heading"><h2 id="users-title">Utilisateurs</h2><span class="user-count"><?= count($users) ?></span></div>
<div class="users-scroll" role="region" aria-label="Droits des utilisateurs" tabindex="0"><table class="users-table"><thead><tr><th scope="col">Utilisateur</th><th scope="col">Admin</th><th scope="col">Chef</th><th scope="col">chaine</th><th scope="col">Inscription<br>validée</th><th scope="col"><span class="sr-only">Enregistrer</span></th><th scope="col">UPTEP+</th><th scope="col">Visualiser</th></tr></thead><tbody>
<?php foreach ($users as $member): $form = 'user-'.$member['id']; ?>
<tr data-user-id="<?= (int)$member['id'] ?>"><th scope="row"><span class="user-display-name"><?= e($member['display_name']) ?></span><span class="user-identifier">@<?= e($member['username']) ?></span></th>
<?php foreach (['admin' => ['Admin', $member['role'] === 'admin'], 'chef' => ['Chef', $member['is_chef']], 'chaine' => ['chaine', $member['is_chaine']], 'approved' => ['Inscription validée', $member['status'] === 'active']] as $key => [$label, $checked]): ?>
<td><label class="permission-check"><input type="checkbox" name="<?= e($key) ?>" value="1" form="<?= e($form) ?>" <?= $checked ? 'checked' : '' ?>><span class="sr-only"><?= e($label.' pour '.$member['display_name']) ?></span></label></td>
<?php endforeach; ?>
<td><form id="<?= e($form) ?>" method="post" action="/admin.php"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="user_id" value="<?= (int)$member['id'] ?>"><input type="hidden" name="version" value="<?= (int)$member['permissions_version'] ?>"><button class="save-user" type="submit" aria-label="<?= e('Enregistrer les droits de '.$member['display_name']) ?>">Enregistrer</button></form></td><td><form method="post" action="/admin.php"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="script"><input type="hidden" name="user_id" value="<?= (int)$member['id'] ?>"><input type="hidden" name="version" value="<?= (int)$member['permissions_version'] ?>"><input type="hidden" name="script_enabled" value="<?= $member['script_enabled']?'0':'1' ?>"><button class="save-user" type="submit" aria-label="<?= e(($member['script_enabled']?'Désactiver':'Activer').' UPTEP+ pour '.$member['display_name']) ?>"><?= $member['script_enabled']?'Désactiver':'Activer' ?></button><span class="user-identifier"><?= $member['script_enabled']?'Autorisé':'Désactivé' ?></span></form></td><td><form method="post" action="/admin.php"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="preview_start"><input type="hidden" name="mode" value="account"><input type="hidden" name="user_id" value="<?= (int)$member['id'] ?>"><button class="save-user" type="submit" <?= $member['status'] !== 'active' ? 'disabled' : '' ?>>Voir comme</button></form></td></tr>
<?php endforeach; ?>
</tbody></table></div><p class="users-help">Les droits sont cumulables. Une inscription non validée ne permet pas de se connecter.</p></section></div>
<?php workspaceEnd(); ?>
