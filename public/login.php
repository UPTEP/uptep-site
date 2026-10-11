<?php
declare(strict_types=1);
require '/home/uptep/private/app/bootstrap.php';
require '/home/uptep/private/app/view.php';
if (user()) redirect('/');
$error = ''; $username = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = strtolower(trim(post('username')));
    if (!validCsrf()) { http_response_code(403); $error = 'La session du formulaire a expiré. Réessayez.'; }
    elseif (!allowedAttempt(substr($username, 0, 100))) { http_response_code(429); header('Retry-After: 900'); $error = 'Trop de tentatives. Réessayez dans 15 minutes.'; }
    else {
        $q = db()->prepare('SELECT * FROM users WHERE username = ?');
        $q->execute([substr($username, 0, 100)]); $u = $q->fetch(PDO::FETCH_ASSOC);
        $password = post('password');
        $valid = strlen($password) <= 512 && password_verify($password, $u['password_hash'] ?? config()['dummy_hash']);
        if ($valid && $u && $u['status'] === 'active') {
            if (password_needs_rehash($u['password_hash'], PASSWORD_ARGON2ID)) {
                db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_ARGON2ID), $u['id']]);
            }
            signIn($u); redirect($u['must_change_password'] ? '/password.php' : '/');
        }
        http_response_code(401); $error = 'Identifiant ou mot de passe incorrect, ou compte non autorisé.';
    }
}
authStart('Connexion');
?>
<span class="form-kicker"><?= icon('lock') ?> ACCÈS MEMBRES</span>
<h1>Bienvenue au repaire.</h1><p class="form-intro">Connecte-toi pour retrouver l’alliance.</p>
<?php if ($error): ?><div class="form-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<form method="post" action="/login.php" class="login-form">
<input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
<label for="username">Identifiant</label><input id="username" name="username" value="<?= e($username) ?>" autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="40" required placeholder="Ton identifiant">
<?php passwordField('password', 'Mot de passe', 'current-password'); ?>
<button class="primary-button" type="submit">Se connecter <?= icon('arrow') ?></button>
</form><p class="login-help">Un souci pour te connecter ?<br>Contacte un administrateur de l’alliance.</p>
<div class="secure-note"><?= icon('shield') ?> Connexion sécurisée · Accès réservé aux membres</div>
<?php authEnd(); ?>
