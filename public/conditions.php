<?php
declare(strict_types=1);
require '/home/uptep/private/app/bootstrap.php';
require '/home/uptep/private/app/view.php';
require '/home/uptep/private/app/registration-form.php';
require '/home/uptep/private/app/discord-login.php';
$target = authenticatedUser();
if (!$target) redirect('/login.php');
unset($_SESSION['preview']);
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!discordLoginRequestAllowed($_SERVER, validCsrf())) throw new DomainException('Session expirée. Recharge la page.', 403);
        if (post('action') === 'cancel') {
            $_SESSION = []; session_regenerate_id(true);
            redirect('/login.php');
        }
        if (post('action') !== 'accept') throw new DomainException('Action invalide.', 400);
        $version = registrationAcceptedVersion($_POST);
        legalAcceptExisting(db(), $target, $version);
        redirect('/');
    } catch (DomainException $e) { http_response_code($e->getCode()); $error = $e->getMessage(); }
}
if (!legalAcceptanceRequired($target)) redirect('/');
authStart('Conditions d’utilisation');
?>
<link rel="stylesheet" href="/assets/discord-registration.css?v=2">
<h1>Conditions d’utilisation</h1>
<p>Merci de consulter les conditions de l’espace privé de l’alliance avant de continuer.</p>
<?php if ($error): ?><div class="form-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php registrationForm($target, false); ?>
<script src="/assets/discord-login.js?v=2" defer></script>
<?php authEnd(); ?>
