<?php
declare(strict_types=1);
const DISCORD_REGISTRATION_TERMS_VERSION = '2026-10-17';
function legalAcceptanceRequired(array $user): bool {
    return empty($user['terms_accepted_at']) || ($user['terms_version'] ?? null) !== DISCORD_REGISTRATION_TERMS_VERSION || ($user['privacy_notice_version'] ?? null) !== DISCORD_REGISTRATION_TERMS_VERSION;
}
function legalAcceptExisting(PDO $p, array $user, ?string $version): void {
    if ($version !== DISCORD_REGISTRATION_TERMS_VERSION) throw new DomainException('Coche les trois cases pour confirmer les conditions d’accès, accepter les CGU et confirmer la lecture de la confidentialité.', 403);
    $q = $p->prepare("UPDATE users SET terms_accepted_at=clock_timestamp(), terms_version=?, privacy_notice_version=? WHERE id=? AND status='active' AND session_version=? AND (terms_accepted_at IS NULL OR terms_version IS DISTINCT FROM ? OR privacy_notice_version IS DISTINCT FROM ?)");
    $q->execute([$version,$version,$user['id'],$user['session_version'],$version,$version]);
}

function registrationAcceptedVersion(array $input): ?string {
    foreach (['accept_membership', 'accept_terms', 'privacy_notice'] as $key) {
        if (($input[$key] ?? null) !== '1') return null;
    }
    return is_string($input['terms_version'] ?? null) ? $input['terms_version'] : null;
}
