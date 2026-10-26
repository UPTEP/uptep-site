<?php
declare(strict_types=1);
require_once __DIR__.'/chaine.php';

/* ---- Trajets tool: distances and attack travel times from one player to whole alliances ---- */
const TRAJETS_MAX_ALLIANCES = 20;

/** Every player present on the server, from the latest game export. */
function trajetsPlayers(): array {
    $q = db(true)->prepare("SELECT player_id,pseudo,coalesce(alliance_tag,'') AS tag,x,y,field,on_holiday,is_banned FROM players WHERE server = ? AND is_present = true ORDER BY lower(pseudo),player_id");
    $q->execute([CHAINE_SERVER]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}
/** Alliance tags with their member counts, biggest first; '' stands for players without alliance. */
function trajetsAlliances(array $players): array {
    $counts = [];
    foreach ($players as $p) $counts[$p['tag']] = ($counts[$p['tag']] ?? 0) + 1;
    uksort($counts, static fn($a, $b) => ($counts[$b] <=> $counts[$a]) ?: strnatcasecmp((string)$a, (string)$b));
    return $counts;
}
/**
 * Attack speeds (UPTEP+ research) the viewer may see, by game player id:
 * every UPTEP member for chefs, as on the HC dashboard; only their own player otherwise.
 */
function trajetsKnownSpeeds(array $viewer): array {
    $q = db()->query("SELECT DISTINCT ON (u.game_player_id) u.game_player_id,s.data->'research'->>'vitesse_attaque' AS speed FROM user_stats s JOIN users u ON u.id=s.user_id WHERE u.status='active' AND u.game_player_id IS NOT NULL ORDER BY u.game_player_id,s.updated_at DESC");
    $own = $viewer['game_player_id'] === null ? null : (string)$viewer['game_player_id'];
    $speeds = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (string)$row['game_player_id'];
        if (empty($viewer['is_chef']) && $id !== $own) continue;
        if (is_string($row['speed']) && preg_match('/\A[0-9]{1,3}\z/', $row['speed'])) $speeds[$id] = (int)$row['speed'];
    }
    return $speeds;
}
/** Distance and one-way attack travel time, same formula as the userscript (checked against Toolzzz). */
function trajetsTravel(array $from, array $to, int $speed): array {
    $distance = hypot((float)$to['x'] - (float)$from['x'], (float)$to['y'] - (float)$from['y']);
    return ['distance' => $distance, 'seconds' => (int)round(86400 * (1 - exp(-$distance / 350)) * 7.375 * 0.9 ** $speed)];
}
function trajetsDuration(int $seconds): string {
    $days = intdiv($seconds, 86400);
    return ($days ? $days.'j ' : '').sprintf('%02d:%02d:%02d', intdiv($seconds % 86400, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
}
/**
 * Validates the request and returns the origin, its speed and the targets sorted by distance.
 * An empty speed falls back to the UPTEP+ speed the viewer may see.
 */
function trajetsCompute(array $players, array $alliances, array $speeds, array $input): array {
    $pseudo = is_string($input['joueur'] ?? null) ? trim($input['joueur']) : '';
    $origin = null;
    foreach ($players as $p) if (mb_strtolower($p['pseudo'], 'UTF-8') === mb_strtolower($pseudo, 'UTF-8')) { $origin = $p; break; }
    if ($origin === null) throw new DomainException($pseudo === '' ? 'Choisis le joueur de départ.' : 'Aucun joueur « '.$pseudo.' » sur le serveur 5 dans le dernier relevé.');
    $raw = is_string($input['va'] ?? null) ? trim($input['va']) : '';
    if ($raw === '') {
        $speed = $speeds[(string)$origin['player_id']] ?? null;
        if ($speed === null) throw new DomainException('Indique la vitesse d’attaque de '.$origin['pseudo'].'.');
        $source = 'uptep';
    } else {
        if (!preg_match('/\A[0-9]{1,3}\z/', $raw)) throw new DomainException('La vitesse d’attaque est un niveau entier entre 0 et 999.');
        $speed = (int)$raw; $source = 'manual';
    }
    $tags = $input['alliances'] ?? [];
    if (!is_array($tags) || !$tags) throw new DomainException('Choisis au moins une alliance.');
    $tags = array_values(array_unique(array_filter($tags, 'is_string')));
    if (count($tags) > TRAJETS_MAX_ALLIANCES) throw new DomainException('Choisis au plus '.TRAJETS_MAX_ALLIANCES.' alliances.');
    foreach ($tags as $tag) if (!array_key_exists($tag, $alliances)) throw new DomainException('Alliance inconnue : '.$tag.'.');
    $selected = array_fill_keys($tags, true);
    $targets = [];
    foreach ($players as $p) {
        if (!isset($selected[$p['tag']]) || (string)$p['player_id'] === (string)$origin['player_id']) continue;
        $targets[] = $p + trajetsTravel($origin, $p, $speed);
    }
    usort($targets, static fn($a, $b) => ($a['distance'] <=> $b['distance']) ?: strnatcasecmp($a['pseudo'], $b['pseudo']));
    return ['origin' => $origin, 'speed' => $speed, 'source' => $source, 'tags' => $tags, 'targets' => $targets];
}
