<?php
declare(strict_types=1);
function icon(string $name): string {
    $paths = [
        'stats' => '<path d="M4 20h16M7 16V9m5 7V4m5 12v-5"/>',
        'home' => '<path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/>',
        'lock' => '<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3m-4 5v2"/>',
        'exit' => '<path d="M9 4H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h4m6-13 5 5-5 5m-7-5h12"/>',
        'arrow' => '<path d="M4 12h16m-6-6 6 6-6 6"/>',
        'eye' => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
        'chain' => '<path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-2 2m3 6a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l2-2"/>',
        'shield' => '<path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6z"/><path d="m8 12 3 3 5-6"/>',
    ];
    return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.($paths[$name] ?? '').'</svg>';
}
function head(string $title, string $class): void { ?>
<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><meta name="theme-color" content="#153e50"><title><?= e($title) ?> · UPTEP</title><link rel="icon" href="/assets/favicon.svg" type="image/svg+xml"><link rel="stylesheet" href="/assets/fonts.css"><link rel="stylesheet" href="/assets/site.css?v=20"><script src="/assets/site.js?v=6" defer></script></head><body class="<?= e($class) ?>">
<?php }
function previewBanner(array $u): void { ?>
<?php if (isset($u['_preview'])): ?>
<aside class="preview-banner" aria-label="Mode visualisation"><div><strong>Mode visualisation · <?= e($u['_preview']['label']) ?></strong><span>Lecture seule · droits <?= $u['role'] === 'admin' ? 'administrateur' : 'membre' ?><?= $u['is_chef'] ? ' · chef' : '' ?><?= $u['is_chaine'] ? ' · chaîne' : '' ?> · UPTEP+ <?= $u['script_enabled'] ? 'autorisé' : 'désactivé' ?></span></div><form method="post" action="/admin.php"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="preview_stop"><button class="save-user" type="submit">Revenir à mon compte admin</button></form></aside>
<?php endif; ?>
<?php }
function workspaceStart(array $u, string $page): void {
    head($page, 'dashboard-page'); ?>
<a class="skip-link" href="#main">Aller au contenu</a>
<header class="topbar"><?php brand(); ?><nav class="top-navigation" aria-label="Navigation principale"><a href="/" <?= $page === 'Accueil' ? 'class="nav-active" aria-current="page"' : '' ?>><?= icon('home') ?> Accueil</a><a href="/chaine.php" <?= $page === 'chaine' ? 'class="nav-active" aria-current="page"' : '' ?>><?= icon('chain') ?> chaine</a><a href="/convois.php" <?= $page === 'Demandes et convois' ? 'class="nav-active" aria-current="page"' : '' ?>><?= icon('arrow') ?> Demandes et convois</a><a href="/stats.php" <?= $page === 'Mes stats' ? 'class="nav-active" aria-current="page"' : '' ?>><?= icon('stats') ?> Mes stats</a><?php if ($u['role'] === 'admin'): ?><a href="/ressources.php" class="nav-admin-only<?= $page === 'Gestion des Ressources' ? ' nav-active' : '' ?>" <?= $page === 'Gestion des Ressources' ? 'aria-current="page"' : '' ?> title="Réservé aux administrateurs · masqué pour les autres comptes" aria-label="Ressources, réservé aux administrateurs et masqué pour les autres comptes"><?= icon('lock') ?><span>Ressources</span><span class="nav-admin-badge">Admin</span></a><?php endif; ?><a href="/script.php" <?= $page === 'UPTEP+' ? 'class="nav-active" aria-current="page"' : '' ?>>UPTEP+</a><?php if ($u['role'] === 'admin'): ?><a href="/admin.php" <?= $page === 'Administration' ? 'class="nav-active" aria-current="page"' : '' ?>><?= icon('shield') ?> Administration</a><?php endif; ?></nav></header>
<?php previewBanner($u); ?>
<div class="workspace"><aside class="sidebar" aria-label="Mon compte"><div class="account"><a class="account-link" href="/compte.php" aria-label="Mon compte" title="Mon compte"><span class="avatar" aria-hidden="true"><img src="/assets/avatar-ant.png" width="35" height="35" alt=""></span><span class="account-name"><?= e($u['display_name']) ?></span></a><form method="post" action="/logout.php"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><button class="logout-button" type="submit" aria-label="Se déconnecter" title="Se déconnecter"><?= icon('exit') ?></button></form></div><div class="sidebar-illustration"><?php bus(); ?><div class="art-caption"><span></span><p>Petites fourmis,<br>grandes aventures.</p><span></span></div></div><div class="sidebar-footer"><?= icon('lock') ?><span>Espace privé</span></div></aside><main class="dashboard-main" id="main">
<?php if ($u['password_setup_required'] ?? false): ?>
<div class="admin-success" role="status"><strong>Crée ton mot de passe.</strong> Ton identifiant est <strong><?= e($u['username']) ?></strong>. <a href="/password.php">Créer mon mot de passe</a> pour pouvoir aussi te connecter sans Discord.</div>
<?php endif; ?>
<?php }
function workspaceEnd(): void { ?></main></div></body></html><?php }
function brand(): void { ?>
<a class="brand" href="/" aria-label="UPTEP — Accueil"><img src="/assets/ant.png" width="60" height="72" alt=""><span><strong>UPTEP</strong><small>Un petit truc en plus</small></span></a>
<?php }
function bus(string $class = ''): void { ?>
<div class="bus-art <?= e($class) ?>"><img src="/assets/bus.png" width="1254" height="1254" alt="Deux fourmis à bord du petit bus jaune UPTEP"><span class="bus-note">Toujours<br>plus loin<br>ensemble !</span></div>
<?php }
function authStart(string $title): void { $viewer = user(); head($title, 'auth-page'); ?>
<header class="topbar auth-topbar"><?php brand(); ?><span class="private-label"><?= icon('lock') ?> Espace privé de l’alliance</span></header>
<?php if ($viewer) previewBanner($viewer); ?>
<main class="auth-main" id="main"><section class="auth-card"><aside class="auth-art" aria-label="Le repaire UPTEP"><span class="eyebrow">LE REPAIRE</span><?php bus(); ?><div class="art-caption"><span></span><p>Petites fourmis,<br>grandes aventures.</p><span></span></div></aside><div class="auth-form">
<?php }
function authEnd(): void { ?>
</div></section></main><footer class="auth-footer">UPTEP <span>·</span> Un petit truc en plus</footer></body></html>
<?php }
function passwordField(string $name, string $label, string $autocomplete, bool $newPassword = false): void { ?>
<label for="<?= e($name) ?>"><?= e($label) ?></label><div class="password-field"><input id="<?= e($name) ?>" name="<?= e($name) ?>" type="password" autocomplete="<?= e($autocomplete) ?>" required maxlength="128" <?= $newPassword ? 'minlength="15"' : '' ?>><button class="password-toggle" type="button" data-password="<?= e($name) ?>" aria-label="Afficher le mot de passe" aria-pressed="false"><?= icon('eye') ?></button></div>
<?php }
