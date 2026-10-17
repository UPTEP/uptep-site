<?php
declare(strict_types=1);
require_once __DIR__.'/legal-acceptance.php';
const DISCORD_LOGIN_USER = '1544667516364652659';
const DISCORD_LOGIN_TEST_TARGET = '757857297903910954';
function discordLoginTestAllowed(string $discord): bool {
    $path = defined('UPTEP_LOGIN_TEST_CONFIG') ? UPTEP_LOGIN_TEST_CONFIG : '/home/uptep/private/bot-api.json';
    $settings = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
    return $discord === DISCORD_LOGIN_TEST_TARGET
        && ($settings['login_test_target'] ?? '') === DISCORD_LOGIN_TEST_TARGET
        && ($settings['login_test_enabled'] ?? false) === true
        && is_int($settings['login_test_until'] ?? null)
        && $settings['login_test_until'] > time();
}

function discordLoginRequestAllowed(array $server, bool $csrfValid): bool {
    // no-referrer can make Origin null on ordinary browser form POSTs.
    // The unpredictable, session-bound CSRF token remains mandatory in every case.
    $origin = $server['HTTP_ORIGIN'] ?? '';
    return $csrfValid
        && in_array($origin, ['', 'null', 'https://uptep.top'], true)
        && !in_array($server['HTTP_SEC_FETCH_SITE'] ?? '', ['cross-site', 'same-site'], true);
}

function discordLoginIdValid(mixed $discord): bool {
    return is_string($discord) && preg_match('/\A[1-9][0-9]{0,18}\z/', $discord)
        && (strlen($discord) < 19 || strcmp($discord, '9223372036854775807') <= 0);
}
function discordLoginPayloadAllowed(mixed $input, mixed $guild): bool {
    return is_array($input) && !array_diff(array_keys($input), ['discord_id', 'guild_id'])
        && discordLoginIdValid($input['discord_id'] ?? null)
        && is_string($guild) && $guild !== '' && ($input['guild_id'] ?? null) === $guild;
}

function discordLoginAccount(PDO $p, string $discord, bool $test = false): array {
    if (!discordLoginIdValid($discord)) throw new DomainException('Identifiant Discord invalide.', 403);
    if ($test && !discordLoginTestAllowed($discord)) throw new DomainException('Simulation de connexion non autorisée.', 403);
    $q = $p->prepare("SELECT player_id, pseudo FROM chaine_members WHERE server = 's5' AND iddiscord = ?");
    $q->execute([$discord]); $players = $q->fetchAll(PDO::FETCH_ASSOC);
    if (count($players) !== 1) throw new DomainException('Association Discord absente ou ambiguë. Contacte un administrateur.', 403);
    $player = $players[0];
    // A profile's editable game_player_id must never select the authenticated account.
    $q = $p->prepare('SELECT * FROM users WHERE discord_login_id = ?');
    $q->execute([$discord]); $linked = $q->fetchAll(PDO::FETCH_ASSOC);
    if ($linked) {
        if ($test) throw new DomainException('Le compte de test existe désormais. La simulation est terminée.', 403);
        if (count($linked) !== 1 || $linked[0]['status'] !== 'active' || (string)$linked[0]['game_player_id'] !== (string)$player['player_id']) throw new DomainException('Compte désactivé ou association modifiée. Contacte un administrateur.', 403);
        return $linked[0];
    }
    $q = $p->prepare('SELECT id FROM users WHERE game_player_id = ?');
    $q->execute([$player['player_id']]);
    if ($q->fetchColumn() !== false) throw new DomainException('Un compte existe déjà pour ce joueur. Un administrateur doit confirmer son association Discord.', 403);
    $username = trim($player['pseudo'] ?? '');
    if (!preg_match('/\A[^\p{C}]{1,80}\z/u', $username)) throw new DomainException('Le pseudo du joueur doit contenir de 1 à 80 caractères sans caractères de contrôle.', 403);
    $q = $p->prepare('SELECT id FROM users WHERE lower(username) = lower(?)'); $q->execute([$username]);
    if ($q->fetchColumn() !== false) throw new DomainException('Identifiant déjà utilisé. Contacte un administrateur.', 403);
    // Preview only: no account exists until the explicit confirmation POST.
    return ['id' => null, 'session_version' => null, 'game_player_id' => $player['player_id'],
        'discord_login_id' => $discord, 'username' => $username,
        'display_name' => $username,
        'must_change_password' => false, 'password_setup_required' => true];
}
function discordLoginIssue(PDO $p, string $discord, bool $test = false): array {
    $p->beginTransaction();
    try {
        $p->query("SELECT pg_advisory_xact_lock(721820282)");
        $u = discordLoginAccount($p, $discord, $test);
        $p->exec('DELETE FROM discord_login_tokens WHERE expires_at <= now()');
        $p->prepare('DELETE FROM discord_login_tokens WHERE discord_id = ?')->execute([$discord]);
        $token = bin2hex(random_bytes(32));
        $p->prepare('INSERT INTO discord_login_tokens(token_hash, user_id, discord_id, session_version, player_id, test_mode) VALUES (?, ?, ?, ?, ?, ?::boolean)')->execute([hash('sha256', $token), $u['id'], $discord, $u['session_version'], $u['game_player_id'], $test ? 'true' : 'false']);
        $p->commit();
        return ['ok' => true, 'url' => 'https://uptep.top/discord-login.php#'.$token, 'expires_in' => 120];
    } catch (Throwable $e) { if ($p->inTransaction()) $p->rollBack(); throw $e; }
}
function discordLoginLookup(PDO $p, string $hash, bool $lock = false): array {
    if (!preg_match('/\A[a-f0-9]{64}\z/', $hash)) throw new DomainException('Lien invalide ou expiré. Relance /connexion sur Discord.', 403);
    $q = $p->prepare('SELECT * FROM discord_login_tokens WHERE token_hash = ? AND expires_at > clock_timestamp()'.($lock ? ' FOR UPDATE' : ''));
    $q->execute([$hash]); $token = $q->fetch(PDO::FETCH_ASSOC);
    if (!$token) throw new DomainException('Lien invalide, expiré ou déjà utilisé. Relance /connexion sur Discord.', 403);
    $u = discordLoginAccount($p, $token['discord_id'], (bool)$token['test_mode']);
    $u['_login_test'] = (bool)$token['test_mode'];
    if ((string)$u['id'] !== (string)$token['user_id'] || (string)$u['session_version'] !== (string)$token['session_version'] || (string)$u['game_player_id'] !== (string)$token['player_id']) throw new DomainException('Le compte a changé. Relance /connexion sur Discord.', 403);
    return $u;
}
function discordLoginConsume(PDO $p, string $hash, ?string $acceptedTermsVersion = null): array {
    $p->beginTransaction();
    try {
        $p->query("SELECT pg_advisory_xact_lock(721820282)");
        // Freeze account/mapping writes through the short check-and-create transaction,
        // including admin/profile operations that do not use this advisory lock.
        $p->exec('LOCK TABLE users IN SHARE ROW EXCLUSIVE MODE');
        $p->exec('LOCK TABLE chaine_members IN SHARE MODE');
        $u = discordLoginLookup($p, $hash, true);
        $test = $u['_login_test'];
        if ($u['id'] === null) {
            if ($acceptedTermsVersion !== DISCORD_REGISTRATION_TERMS_VERSION) throw new DomainException('Accepte les CGU et confirme avoir pris connaissance de la confidentialité avant de créer ton compte.', 403);
            // Random, undisclosed password; no default password or forced password step.
            $passwordHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_ARGON2ID);
            $q = $p->prepare("INSERT INTO users (username, display_name, password_hash, role, status, must_change_password, is_chef, is_chaine, game_player_id, discord_login_id, password_setup_required, approved_at, terms_accepted_at, terms_version, privacy_notice_version) VALUES (?, ?, ?, 'member', 'active', false, false, false, ?, ?, true, now(), clock_timestamp(), ?, ?) RETURNING *");
            $q->execute([$u['username'], $u['display_name'], $passwordHash, $u['game_player_id'], $u['discord_login_id'], DISCORD_REGISTRATION_TERMS_VERSION, DISCORD_REGISTRATION_TERMS_VERSION]);
            $u = $q->fetch(PDO::FETCH_ASSOC);
        }
        $q = $p->prepare('DELETE FROM discord_login_tokens WHERE token_hash = ? AND expires_at > clock_timestamp() RETURNING token_hash');
        $q->execute([$hash]);
        if ($q->fetchColumn() === false) throw new DomainException('Lien expiré. Relance /connexion sur Discord.', 403);
        $p->commit();
        if ($test) error_log('UPTEP Discord registration test completed: actor='.DISCORD_LOGIN_USER.' target='.DISCORD_LOGIN_TEST_TARGET.' account='.$u['id']);
        return $u;
    } catch (Throwable $e) { if ($p->inTransaction()) $p->rollBack(); throw $e; }
}
