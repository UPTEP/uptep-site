<?php
declare(strict_types=1);
require '/home/uptep/private/app/bootstrap.php';
require '/home/uptep/private/app/view.php';
require '/home/uptep/private/app/registration-form.php';
require '/home/uptep/private/app/discord-login.php';
header('Referrer-Policy: no-referrer');
$error = ''; $target = null;
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!discordLoginRequestAllowed($_SERVER, validCsrf())) throw new DomainException('Session du formulaire expirée. Rouvre le lien depuis Discord.', 403);
        if (!consumeAttempt('discord-login-web:'.($_SERVER['REMOTE_ADDR'] ?? ''), 30)) throw new DomainException('Trop de tentatives. Réessaie dans 15 minutes.', 429);
        if (post('action') === 'cancel') {
            unset($_SESSION['discord_login_hash']);
            redirect('/login.php');
        } elseif (post('action') === 'prepare') {
            unset($_SESSION['discord_login_hash']);
            $token = post('token');
            if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) throw new DomainException('Lien invalide. Relance /connexion sur Discord.', 403);
            $hash = hash('sha256', $token);
            $target = discordLoginLookup(db(), $hash);
            $_SESSION['discord_login_hash'] = $hash;
            redirect('/discord-login.php');
        } elseif (post('action') === 'confirm') {
            $hash = $_SESSION['discord_login_hash'] ?? '';
            $target = discordLoginLookup(db(), $hash);
            $current = user();
            if ($current && (string)$current['id'] !== (string)$target['id']) throw new DomainException('Un autre compte est déjà connecté. Déconnecte-toi avant de réessayer.', 403);
            $acceptedVersion = registrationAcceptedVersion($_POST);
            $target = discordLoginConsume(db(), $hash, $acceptedVersion);
            unset($_SESSION['discord_login_hash']);
            signIn($target);
            redirect(legalAcceptanceRequired($target) ? '/conditions.php' : ($target['must_change_password'] ? '/password.php' : '/'));
        } else throw new DomainException('Action invalide.', 400);
    }
    if (isset($_SESSION['discord_login_hash'])) $target = discordLoginLookup(db(), $_SESSION['discord_login_hash']);
} catch (DomainException $e) {
    unset($_SESSION['discord_login_hash']); $target = null;
    http_response_code($e->getCode()); $error = $e->getMessage();
}
authStart('Connexion Discord');
?>
<link rel="stylesheet" href="/assets/discord-registration.css?v=2">
<h1>Connexion depuis Discord</h1>
<?php if ($error): ?><div class="form-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if ($target): ?>
<?php if ($target['_login_test'] ?? false): ?><p><strong>Test NoobEnCDI : création du compte membre Hardware.</strong> Cette confirmation créera un vrai compte UPTEP pour Hardware.</p><?php endif; ?>
<?php if ($target['id'] === null): ?>
<p>Ton compte n’est pas encore créé. Consulte les conditions ci-dessous pour rejoindre l’espace privé de l’alliance.</p>
<?php registrationForm($target, true); ?>
<?php else: ?>
<p>Tu vas te connecter au compte <strong><?= e($target['display_name']) ?></strong> (<?= e($target['username']) ?>).</p>
<p>Confirme uniquement si tu viens de demander ce lien avec <strong>/connexion</strong> sur Discord.</p>
<form method="post" action="/discord-login.php" class="login-form">
<input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="confirm">
<button class="primary-button" type="submit">Me connecter à ce compte</button>
</form>
<?php endif; ?>
<?php else: ?>
<p id="discord-login-status">Utilise la commande /connexion sur Discord, puis ouvre ton lien personnel.</p>
<?php endif; ?>
<form id="discord-login-prepare" method="post" action="/discord-login.php" hidden>
<input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="prepare"><input type="hidden" name="token" value="">
</form>
<noscript><p>Active JavaScript pour ouvrir ce lien de connexion.</p></noscript>
<p><a href="/login.php">Connexion habituelle</a></p>
<script src="/assets/discord-login.js?v=2" defer></script>
<?php authEnd(); ?>
