<?php
declare(strict_types=1);
// Server-side account administration only. Never install under public_html.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require '/home/uptep/private/app/bootstrap.php';
$action = $argv[1] ?? ''; $username = strtolower($argv[2] ?? '');
if (!preg_match('/^[a-z0-9][a-z0-9_.-]{2,39}$/D', $username) || !in_array($action, ['create','reset','disable','enable'], true)) {
    fwrite(STDERR, "Usage: php account.php create USER admin|member OUTPUT_FILE\n       php account.php reset USER OUTPUT_FILE\n       php account.php disable|enable USER\n"); exit(1);
}
if (in_array($action, ['disable','enable'], true)) {
    $q = db()->prepare('UPDATE users SET status = ?, session_version = session_version + 1, permissions_version = permissions_version + 1 WHERE lower(username) = lower(?) RETURNING id');
    $q->execute([$action === 'disable' ? 'disabled' : 'active', $username]);
    if (!$q->fetchColumn()) { fwrite(STDERR, "Compte introuvable.\n"); exit(1); }
    echo "Compte mis à jour.\n"; exit;
}
$role = $action === 'create' ? ($argv[3] ?? 'member') : 'member';
$output = $argv[$action === 'create' ? 4 : 3] ?? '';
if (!in_array($role, ['admin','member'], true) || !str_starts_with($output, '/') || file_exists($output)) { fwrite(STDERR, "Rôle invalide ou chemin de sortie absent/déjà existant.\n"); exit(1); }
$password = bin2hex(random_bytes(16));
$hash = password_hash($password, PASSWORD_ARGON2ID);
umask(0077);
$file = fopen($output, 'x');
if (!$file) exit(1);
try {
    if ($action === 'create') {
        $q = db()->prepare("INSERT INTO users (username, display_name, password_hash, role, approved_at) VALUES (?, ?, ?, ?, now())");
        $q->execute([$username, $username === 'admin' ? 'Administrateur' : $username, $hash, $role]);
    } else {
        $q = db()->prepare('UPDATE users SET password_hash = ?, must_change_password = true, password_setup_required = false, session_version = session_version + 1 WHERE lower(username) = lower(?) RETURNING id');
        $q->execute([$hash, $username]);
        if (!$q->fetchColumn()) throw new RuntimeException('Compte introuvable.');
    }
    fwrite($file, json_encode(['site' => 'https://uptep.top', 'username' => $username, 'password' => $password, 'notice' => 'Mot de passe temporaire à remplacer lors de la première connexion.'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
    fclose($file); echo "Compte préparé ; identifiants enregistrés dans le fichier privé indiqué.\n";
} catch (Throwable $error) { fclose($file); unlink($output); throw $error; }
