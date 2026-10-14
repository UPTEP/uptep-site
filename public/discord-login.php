<?php
declare(strict_types=1);
require '/home/uptep/private/app/bootstrap.php';
require '/home/uptep/private/app/view.php';
require '/home/uptep/private/app/discord-login.php';
header('Referrer-Policy: no-referrer');
$error = ''; $target = null;
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!discordLoginRequestAllowed($_SERVER, validCsrf())) throw new DomainException('Session du formulaire expirée. Rouvre le lien depuis Discord.', 403);
        if (!consumeAttempt('discord-login-web:'.($_SERVER['REMOTE_ADDR'] ?? ''), 30)) throw new DomainException('Trop de tentatives. Réessaie dans 15 minutes.', 429);
        if (post('action') === 'prepare') {
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
            $target = discordLoginConsume(db(), $hash);
            unset($_SESSION['discord_login_hash']);
            signIn($target);
            redirect($target['must_change_password'] ? '/password.php' : '/');
        } else throw new DomainException('Action invalide.', 400);
    }
    if (isset($_SESSION['discord_login_hash'])) $target = discordLoginLookup(db(), $_SESSION['discord_login_hash']);
} catch (DomainException $e) {
    unset($_SESSION['discord_login_hash']); $target = null;
    http_response_code($e->getCode()); $error = $e->getMessage();
}
authStart('Connexion Discord');
?>
<h1>Connexion depuis Discord</h1>
<?php if ($error): ?><div class="form-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if ($target): ?>
<?php if ($target['_login_test'] ?? false): ?><p><strong>Test NoobEnCDI : création du compte membre Hardware.</strong> Cette confirmation créera un vrai compte UPTEP pour Hardware.</p><?php endif; ?>
<?php if ($target['id'] === null): ?>
<p>Ton ID Discord est associé au joueur <strong><?= e($target['display_name']) ?></strong>.</p>
<p>En confirmant, tu créeras ton compte UPTEP avec des droits de membre et tu seras connecté.</p>
<?php else: ?>
<p>Tu vas te connecter au compte <strong><?= e($target['display_name']) ?></strong> (<?= e($target['username']) ?>).</p>
<?php endif; ?>
<p>Confirme uniquement si tu viens de demander ce lien avec <strong><?= ($target['_login_test'] ?? false) ? '/connexion-test' : '/connexion' ?></strong> sur Discord.</p>
<form method="post" action="/discord-login.php" class="login-form">
<input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="confirm">
<button class="primary-button" type="submit"><?= $target['id'] === null ? 'Créer mon compte et me connecter' : 'Me connecter à ce compte' ?></button>
</form>
<?php else: ?>
<p id="discord-login-status">Utilise la commande /connexion sur Discord, puis ouvre ton lien personnel.</p>
<?php endif; ?>
<form id="discord-login-prepare" method="post" action="/discord-login.php" hidden>
<input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="prepare"><input type="hidden" name="token" value="">
</form>
<noscript><p>Active JavaScript pour ouvrir ce lien de connexion.</p></noscript>
<p><a href="/login.php">Connexion habituelle</a></p>
<script src="/assets/discord-login.js" defer></script>
<?php authEnd(); ?>
