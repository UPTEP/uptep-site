<?php
declare(strict_types=1);

function config(): array {
    static $config;
    return $config ??= json_decode(file_get_contents('/home/uptep/private/config.json'), true, 512, JSON_THROW_ON_ERROR);
}
function db(bool $fzzz = false): PDO {
    static $connections = [];
    $name = $fzzz ? 'fzzz' : 'uptep';
    if (!isset($connections[$name])) {
        $c = config();
        $connections[$name] = new PDO("pgsql:host=127.0.0.1;port=5432;dbname=$name;connect_timeout=5;options='--timezone=UTC'", $c['db_user'], $c['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
        if ($fzzz) $connections[$name]->exec('SET default_transaction_read_only = on');
    }
    return $connections[$name];
}
function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function post(string $name): string { return is_string($_POST[$name] ?? null) ? $_POST[$name] : ''; }
function redirect(string $path): never { header('Location: '.$path, true, 303); exit; }
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function validCsrf(): bool { return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], post('csrf')); }
function user(): ?array {
    if (!isset($_SESSION['user_id'])) return null;
    if (time() - ($_SESSION['last_active'] ?? 0) > 7200 || time() - ($_SESSION['signed_in'] ?? 0) > 43200) {
        $_SESSION = []; session_regenerate_id(true); return null;
    }
    $q = db()->prepare('SELECT id, username, display_name, game_player_id, role, is_chef, is_chaine, status, session_version, must_change_password, password_setup_required FROM users WHERE id = ?');
    $q->execute([$_SESSION['user_id']]); $u = $q->fetch(PDO::FETCH_ASSOC);
    if (!$u || $u['status'] !== 'active' || (int)$u['session_version'] !== ($_SESSION['version'] ?? 0)) {
        $_SESSION = []; session_regenerate_id(true); return null;
    }
    $_SESSION['last_active'] = time();
    if (time() - ($_SESSION['rotated_at'] ?? 0) > 900) { session_regenerate_id(true); $_SESSION['rotated_at'] = time(); }
    return $u;
}
function requireUser(bool $allowPasswordChange = false): array {
    $u = user();
    if (!$u) redirect('/login.php');
    if ($u['must_change_password'] && !$allowPasswordChange) redirect('/password.php');
    return $u;
}
function signIn(array $u): void {
    session_regenerate_id(true);
    $_SESSION = ['user_id' => (int)$u['id'], 'version' => (int)$u['session_version'], 'signed_in' => time(), 'last_active' => time(), 'rotated_at' => time(), 'csrf' => bin2hex(random_bytes(32))];
    db()->prepare('UPDATE users SET last_login_at = now() WHERE id = ?')->execute([$u['id']]);
}
function consumeAttempt(string $key, int $limit): bool {
    if (random_int(1, 100) === 1) db()->exec("DELETE FROM login_limits WHERE started_at < now() - interval '1 day'");
    $bucket = hash_hmac('sha256', $key, config()['rate_key']);
    // Atomic increment prevents concurrent requests from bypassing the limit.
    $q = db()->prepare("INSERT INTO login_limits (bucket, attempts) VALUES (?, 1)
      ON CONFLICT (bucket) DO UPDATE SET
      attempts = CASE WHEN login_limits.started_at < now() - interval '15 minutes' THEN 1 ELSE login_limits.attempts + 1 END,
      started_at = CASE WHEN login_limits.started_at < now() - interval '15 minutes' THEN now() ELSE login_limits.started_at END
      RETURNING attempts");
    $q->execute([$bucket]);
    return (int)$q->fetchColumn() <= $limit;
}
function allowedAttempt(string $username): bool {
    // Do not trust client-supplied forwarding headers; Nginx passes REMOTE_ADDR.
    if (!consumeAttempt('ip:'.($_SERVER['REMOTE_ADDR'] ?? ''), 30)) return false;
    return consumeAttempt('user:'.$username, 10);
}

if (PHP_SAPI !== 'cli') {
    ini_set('display_errors', '0');
    set_exception_handler(function (Throwable $error): never {
        error_log('UPTEP: '.get_class($error).' '.$error->getMessage());
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="fr"><meta charset="utf-8"><title>UPTEP</title><p>Le repaire est momentanément indisponible. Réessayez dans quelques instants.</p></html>';
        exit;
    });
    header('Cache-Control: no-store, private');
    header('Content-Type: text/html; charset=utf-8');
    if (!in_array($_SERVER['REQUEST_METHOD'], ['GET','POST'], true)) { header('Allow: GET, POST'); http_response_code(405); exit; }
    if (defined('UPTEP_SCRIPT_API')) return;
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.gc_maxlifetime', '43200');
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');
    session_save_path('/home/uptep/private/sessions');
    session_name('__Host-UPTEP');
    session_cache_limiter('');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
