<?php
declare(strict_types=1);
require '/home/uptep/private/app/bootstrap.php';
require '/home/uptep/private/app/view.php';
$u = requireUser(true);
if (!$u['must_change_password']) redirect('/');
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validCsrf()) { http_response_code(403); $error = 'La session du formulaire a expiré. Réessayez.'; }
    elseif (!consumeAttempt('password:'.$u['id'], 10)) { http_response_code(429); $error = 'Trop de tentatives. Réessayez dans 15 minutes.'; }
    else {
        $password = post('password');
        $q = db()->prepare('SELECT password_hash FROM users WHERE id = ?'); $q->execute([$u['id']]); $oldHash = $q->fetchColumn();
        if (mb_strlen($password) < 15 || mb_strlen($password) > 128) $error = 'Choisis un mot de passe de 15 à 128 caractères.';
        elseif (!hash_equals($password, post('confirmation'))) $error = 'Les deux mots de passe ne correspondent pas.';
        elseif (password_verify($password, $oldHash)) $error = 'Choisis un mot de passe différent du mot de passe temporaire.';
        else {
            $q = db()->prepare('UPDATE users SET password_hash = ?, must_change_password = false, session_version = session_version + 1 WHERE id = ? AND session_version = ? RETURNING session_version');
            $q->execute([password_hash($password, PASSWORD_ARGON2ID), $u['id'], $u['session_version']]);
            $version = $q->fetchColumn();
            if (!$version) redirect('/login.php');
            $u['session_version'] = $version; signIn($u); redirect('/');
        }
    }
}
authStart('Premier accès');
?>
<span class="form-kicker"><?= icon('shield') ?> PREMIÈRE CONNEXION</span><h1>À toi de choisir.</h1><p class="form-intro">Remplace ton mot de passe temporaire pour accéder au repaire.</p>
<?php if ($error): ?><div class="form-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<form method="post" action="/password.php" class="login-form"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="username" autocomplete="username" value="<?= e($u['username']) ?>">
<?php passwordField('password', 'Nouveau mot de passe', 'new-password', true); ?>
<p class="field-hint">15 caractères minimum. Une longue phrase convient très bien.</p>
<?php passwordField('confirmation', 'Confirmer le mot de passe', 'new-password', true); ?>
<button class="primary-button" type="submit">Enregistrer et entrer <?= icon('arrow') ?></button></form>
<form class="back-form" method="post" action="/logout.php"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><button type="submit">Se déconnecter</button></form>
<?php authEnd(); ?>
