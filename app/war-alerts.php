<?php
declare(strict_types=1);
// Alertes « préparation de guerre » : une alliance gonfle quelques comptes par floods internes
// (la chaîne) pendant que ses gros comptes cessent de gagner du terrain.
// Tout le calcul est fait par PostgreSQL : PHP ne transporte qu'un JSON de quelques Ko.

const WAR_ALERT_DEFAULTS = [
    'server' => 's5',
    'home_tag' => 'UPTEP',
    'window_snapshots' => 12,
    'growth_factor' => 2.0,
    'gain_pct_of_top' => 0.05,
    'chain_share' => 0.5,
    'top_n' => 10,
    'idle_share' => 0.5,
    'min_pumped' => 2,
    'min_members' => 10,
    'episode_gap_hours' => 12,
    'window_span_min_hours' => 10,
    'window_span_max_hours' => 15,
    'donors' => 3,
    'neighbours' => 3,
    // Un relevé incomplet (import en cours) est ignoré jusqu'à ce délai après son import.
    'import_grace_minutes' => 10,
];

/** Paramètres figés par défaut, surchargeables par la clé "war_alerts" de config.json. */
function warAlertSettings(array $override = []): array {
    $s = WAR_ALERT_DEFAULTS;
    foreach ($override as $key => $value) {
        if (!array_key_exists($key, $s)) throw new InvalidArgumentException('Paramètre d’alerte inconnu : '.$key);
        if (is_string($s[$key]) ? !is_string($value) : !is_int($value) && !is_float($value)) throw new InvalidArgumentException('Paramètre d’alerte invalide : '.$key);
        $s[$key] = is_int($s[$key]) ? (int)$value : (is_float($s[$key]) ? (float)$value : $value);
    }
    if ($s['window_snapshots'] < 2 || $s['top_n'] < 1 || $s['window_span_min_hours'] > $s['window_span_max_hours']) throw new InvalidArgumentException('Paramètres d’alerte incohérents.');
    return $s;
}

const WAR_ALERT_DETECT_SQL = <<<'SQL'
WITH cfg AS MATERIALIZED (
  SELECT CAST(:server AS text) AS server, CAST(:version AS text) AS version, CAST(:win AS int) AS win,
         CAST(:growth AS numeric) AS growth, CAST(:gain_pct AS numeric) AS gain_pct, CAST(:chain_share AS numeric) AS chain_share,
         CAST(:top_n AS int) AS top_n, CAST(:idle_share AS numeric) AS idle_share, CAST(:min_pumped AS int) AS min_pumped,
         CAST(:min_members AS int) AS min_members, CAST(:span_min AS numeric) AS span_min, CAST(:span_max AS numeric) AS span_max,
         CAST(:home_tag AS text) AS home_tag, CAST(:donors AS int) AS donors, CAST(:neighbours AS int) AS neighbours
),
-- Un relevé = une export_version ; seule la dernière importation d'une même version compte.
versions AS (
  SELECT DISTINCT ON (e.export_version) e.id, e.export_version, e.exported_at
  FROM historique_exports e, cfg
  WHERE e.server = cfg.server AND e.export_type = 'players'
  ORDER BY e.export_version, e.id DESC
),
target AS (
  SELECT v.* FROM versions v, cfg
  WHERE cfg.version IS NULL OR v.export_version = cfg.version
  ORDER BY v.exported_at DESC, v.id DESC LIMIT 1
),
-- R0 (i = 0) … R12 (i = win), du plus récent au plus ancien.
win AS MATERIALIZED (
  SELECT v.id, v.export_version, v.exported_at,
         (row_number() OVER (ORDER BY v.exported_at DESC, v.id DESC) - 1)::int AS i
  FROM versions v, target t
  WHERE v.exported_at <= t.exported_at
  ORDER BY v.exported_at DESC, v.id DESC
  LIMIT (SELECT win + 1 FROM cfg)
),
info AS (
  SELECT max(id) FILTER (WHERE i = 0) AS export_id, max(export_version) FILTER (WHERE i = 0) AS export_version,
         max(exported_at) FILTER (WHERE i = 0) AS exported_at, min(exported_at) AS oldest_at, count(*)::int AS snapshots,
         extract(epoch FROM max(exported_at) - min(exported_at)) / 3600 AS span_hours
  FROM win
),
ok AS (
  SELECT 1 FROM info, cfg
  WHERE info.snapshots = cfg.win + 1 AND info.span_hours BETWEEN cfg.span_min AND cfg.span_max
),
snap AS MATERIALIZED (
  SELECT w.i, h.player_id, h.pseudo, h.alliance_tag, h.x, h.y, h.field, h.on_holiday, h.is_banned
  FROM win w JOIN historique_players h ON h.export_id = w.id
  WHERE EXISTS (SELECT 1 FROM ok)
),
-- Étape 1 : variation de chaque joueur présent dans Ri et Ri+1.
delta AS MATERIALIZED (
  SELECT a.i, a.player_id, a.alliance_tag, a.field - b.field AS d
  FROM snap a JOIN snap b ON b.player_id = a.player_id AND b.i = a.i + 1
),
-- Étape 2 : transfert certain = seul perdant et seul gagnant de ce montant exact sur le serveur pour ce relevé.
pairs AS (
  SELECT i, abs(d) AS amount,
         min(player_id) FILTER (WHERE d < 0) AS donor, min(player_id) FILTER (WHERE d > 0) AS receiver,
         min(alliance_tag) FILTER (WHERE d < 0) AS donor_tag, min(alliance_tag) FILTER (WHERE d > 0) AS receiver_tag
  FROM delta WHERE d <> 0
  GROUP BY i, abs(d)
  HAVING count(*) FILTER (WHERE d < 0) = 1 AND count(*) FILTER (WHERE d > 0) = 1
),
internal AS MATERIALIZED (
  SELECT receiver, donor, sum(amount) AS amount
  FROM pairs WHERE donor_tag = receiver_tag
  GROUP BY receiver, donor
),
received AS (SELECT receiver, sum(amount) AS amount FROM internal GROUP BY receiver),
gains AS (SELECT player_id, sum(d) AS gained FROM delta WHERE d > 0 GROUP BY player_id),
-- Étape 3 : membres en R0 (bannis exclus) et comptes gonflés.
members AS MATERIALIZED (
  SELECT m.player_id, m.pseudo, m.alliance_tag AS tag, m.x, m.y, m.field AS f0, m.on_holiday, o.field AS f_old,
         max(m.field) OVER t AS top_field, count(*) OVER t AS n_members, sum(m.field) OVER t AS total_field
  FROM snap m LEFT JOIN snap o ON o.player_id = m.player_id AND o.i = (SELECT win FROM cfg)
  WHERE m.i = 0 AND m.alliance_tag IS NOT NULL AND NOT m.is_banned
  WINDOW t AS (PARTITION BY m.alliance_tag)
),
pumped AS MATERIALIZED (
  SELECT m.*, r.amount AS chain_amount
  FROM members m JOIN received r ON r.receiver = m.player_id, cfg
  WHERE m.n_members >= cfg.min_members AND m.f_old IS NOT NULL
    AND m.f0 >= cfg.growth * m.f_old
    AND m.f0 - m.f_old >= cfg.gain_pct * m.top_field
    AND r.amount >= cfg.chain_share * (m.f0 - m.f_old)
),
-- Étape 4 : gros comptes hors vacances ; à l'arrêt = aucun gain sur les relevés où ils sont présents.
top AS (
  SELECT r.*, coalesce(g.gained, 0) AS gained
  FROM (SELECT m.*, row_number() OVER (PARTITION BY m.tag ORDER BY m.f0 DESC, m.player_id) AS rk
        FROM members m, cfg WHERE NOT m.on_holiday AND m.n_members >= cfg.min_members) r
  LEFT JOIN gains g ON g.player_id = r.player_id, cfg
  WHERE r.rk <= cfg.top_n
),
idle AS (
  SELECT tag, count(*)::int AS top_count, count(*) FILTER (WHERE gained = 0)::int AS idle_count,
         coalesce(jsonb_agg(jsonb_build_object('player_id', player_id::text, 'pseudo', pseudo, 'field', f0::text) ORDER BY rk)
                  FILTER (WHERE gained = 0), '[]') AS idle
  FROM top GROUP BY tag
),
-- Étape 5 : déclenchement.
alerts AS (
  SELECT p.tag, count(*)::int AS pumped_count
  FROM pumped p JOIN idle i USING (tag), cfg
  GROUP BY p.tag, i.idle_count, cfg.min_pumped, cfg.idle_share, cfg.top_n
  HAVING count(*) >= cfg.min_pumped AND i.idle_count::numeric / cfg.top_n >= cfg.idle_share
),
names AS (SELECT DISTINCT ON (player_id) player_id, pseudo FROM snap ORDER BY player_id, i),
home AS (SELECT s.* FROM snap s, cfg WHERE s.i = 0 AND upper(s.alliance_tag) = upper(cfg.home_tag) AND NOT s.is_banned),
pumped_json AS (
  SELECT p.tag, jsonb_agg(jsonb_build_object(
    'player_id', p.player_id::text, 'pseudo', p.pseudo, 'x', p.x::text, 'y', p.y::text,
    'field_before', p.f_old::text, 'field_now', p.f0::text, 'chain_amount', p.chain_amount::text,
    'donors', (SELECT coalesce(jsonb_agg(jsonb_build_object('player_id', x.donor::text, 'pseudo', n.pseudo, 'amount', x.amount::text)
                                ORDER BY x.amount DESC, x.donor), '[]')
               FROM (SELECT * FROM internal WHERE receiver = p.player_id ORDER BY amount DESC, donor LIMIT (SELECT donors FROM cfg)) x
               LEFT JOIN names n ON n.player_id = x.donor),
    'neighbours', (SELECT coalesce(jsonb_agg(jsonb_build_object('player_id', u.player_id::text, 'pseudo', u.pseudo, 'field', u.field::text,
                                   'distance', round(u.dist::numeric, 1)) ORDER BY u.dist, u.player_id), '[]')
                   FROM (SELECT h.player_id, h.pseudo, h.field,
                                sqrt(power((h.x - p.x)::float8, 2) + power((h.y - p.y)::float8, 2)) AS dist
                         FROM home h WHERE h.player_id <> p.player_id
                         ORDER BY dist, h.player_id LIMIT (SELECT neighbours FROM cfg)) u)
  ) ORDER BY p.f0 DESC, p.player_id) AS pumped
  FROM pumped p JOIN alerts a USING (tag)
  GROUP BY p.tag
),
alliance_export AS (
  SELECT e.id FROM historique_exports e, cfg, info
  WHERE e.server = cfg.server AND e.export_type = 'alliances' AND e.exported_at <= info.exported_at + interval '1 hour'
  ORDER BY e.exported_at DESC, e.id DESC LIMIT 1
),
alliance_info AS (
  SELECT ha.tag, ha.name, ha.diplomacy,
         CASE WHEN jsonb_typeof(ha.diplomacy->'pacts') = 'array' THEN ha.diplomacy->'pacts' ELSE '[]' END AS pacts,
         CASE WHEN jsonb_typeof(ha.diplomacy->'wars') = 'array' THEN ha.diplomacy->'wars' ELSE '[]' END AS wars
  FROM historique_alliances ha JOIN alliance_export ae ON ae.id = ha.export_id
  WHERE ha.tag IN (SELECT tag FROM alerts)
),
result AS (
  SELECT jsonb_agg(jsonb_build_object(
    'tag', a.tag, 'name', ai.name,
    'members', m.n_members, 'total_field', m.total_field::text,
    'pumped_count', a.pumped_count, 'pumped', pj.pumped,
    'top_count', i.top_count, 'idle_count', i.idle_count, 'idle', i.idle,
    'wars', coalesce(ai.wars, '[]'), 'pacts', coalesce(ai.pacts, '[]'),
    'home_pact', coalesce((SELECT bool_or(upper(CASE WHEN jsonb_typeof(x) = 'string' THEN x #>> '{}' ELSE x->>'tag' END) = upper(cfg.home_tag))
                           FROM jsonb_array_elements(ai.pacts) x), false)
  ) ORDER BY a.tag) AS alerts
  FROM alerts a
  JOIN (SELECT DISTINCT tag, n_members, total_field FROM members) m USING (tag)
  JOIN idle i USING (tag)
  JOIN pumped_json pj USING (tag)
  LEFT JOIN alliance_info ai USING (tag), cfg
)
SELECT jsonb_build_object(
  'server', cfg.server, 'home_tag', cfg.home_tag, 'top_n', cfg.top_n,
  'export_id', info.export_id::text, 'export_version', info.export_version,
  'exported_at', to_char(info.exported_at, 'YYYY-MM-DD"T"HH24:MI:SS"Z"'),
  'oldest_at', to_char(info.oldest_at, 'YYYY-MM-DD"T"HH24:MI:SS"Z"'),
  'snapshots', info.snapshots, 'span_hours', round(info.span_hours, 2),
  'evaluated', EXISTS (SELECT 1 FROM ok),
  'alerts', coalesce((SELECT alerts FROM result), '[]')
)::text
FROM cfg, info
SQL;

/** Détection sur un relevé (le dernier par défaut). Lecture seule sur fzzz. */
function warAlertDetect(PDO $game, array $s, ?string $version = null): array {
    $q = $game->prepare(WAR_ALERT_DETECT_SQL);
    $q->execute([
        'server' => $s['server'], 'version' => $version, 'win' => $s['window_snapshots'],
        'growth' => $s['growth_factor'], 'gain_pct' => $s['gain_pct_of_top'], 'chain_share' => $s['chain_share'],
        'top_n' => $s['top_n'], 'idle_share' => $s['idle_share'], 'min_pumped' => $s['min_pumped'],
        'min_members' => $s['min_members'], 'span_min' => $s['window_span_min_hours'], 'span_max' => $s['window_span_max_hours'],
        'home_tag' => $s['home_tag'], 'donors' => $s['donors'], 'neighbours' => $s['neighbours'],
    ]);
    return json_decode((string)$q->fetchColumn(), true, 64, JSON_THROW_ON_ERROR);
}

/** Dernier relevé players et état de son import (complet ou import récent encore en cours). */
function warAlertLatest(PDO $game, array $s): ?array {
    $q = $game->prepare("SELECT e.export_version, e.row_count,
        (SELECT count(*) FROM historique_players h WHERE h.export_id = e.id) AS imported,
        e.imported_at > (now() AT TIME ZONE 'UTC') - make_interval(mins => CAST(:grace AS int)) AS recent
      FROM historique_exports e WHERE e.server = :server AND e.export_type = 'players'
      ORDER BY e.exported_at DESC, e.id DESC LIMIT 1");
    $q->execute(['server' => $s['server'], 'grace' => $s['import_grace_minutes']]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Évalue le dernier relevé s'il ne l'a pas encore été, et enregistre épisodes et événements. */
function warAlertEvaluate(PDO $site, PDO $game, array $s): array {
    $latest = warAlertLatest($game, $s);
    if (!$latest) return ['status' => 'no_export'];
    $version = $latest['export_version'];
    $known = $site->prepare('SELECT 1 FROM war_alert_runs WHERE server = ? AND export_version = ?');
    $known->execute([$s['server'], $version]);
    if ($known->fetchColumn()) return ['status' => 'already_evaluated', 'export_version' => $version];
    if ((int)$latest['imported'] < (int)$latest['row_count'] && $latest['recent']) return ['status' => 'import_in_progress', 'export_version' => $version];
    $result = warAlertDetect($game, $s, $version);
    if (!$result['evaluated']) error_log(sprintf('UPTEP war alerts: fenêtre ignorée pour %s (%d relevés sur %.2f h)', $version, $result['snapshots'], (float)$result['span_hours']));
    $q = $site->prepare('SELECT war_alert_record(CAST(? AS jsonb), CAST(? AS numeric))::text');
    $q->execute([json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $s['episode_gap_hours']]);
    $recorded = json_decode((string)$q->fetchColumn(), true, 8, JSON_THROW_ON_ERROR);
    return ['status' => $recorded['recorded'] ? ($result['evaluated'] ? 'evaluated' : 'window_skipped') : 'already_evaluated',
            'export_version' => $version, 'alerts' => count($result['alerts']), 'events' => $recorded['events']];
}

function warAlertPending(PDO $site, int $limit = 20): array {
    $q = $site->prepare("SELECT ev.id::text AS id, ev.kind, ev.payload::text AS payload, ep.id::text AS episode_id, ep.message_id AS episode_message_id,
        to_char(ep.episode_start AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS episode_start
      FROM war_alert_events ev JOIN war_alert_episodes ep ON ep.id = ev.episode_id
      WHERE ev.acknowledged_at IS NULL ORDER BY ev.id LIMIT ?");
    $q->execute([$limit]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) $row['payload'] = json_decode($row['payload'], true, 64, JSON_THROW_ON_ERROR);
    return $rows;
}

function warAlertAck(PDO $site, string $id, ?string $messageId): bool {
    if (!preg_match('/\A[1-9][0-9]{0,18}\z/', $id) || ($messageId !== null && !preg_match('/\A[1-9][0-9]{16,19}\z/', $messageId))) throw new DomainException('Acquittement invalide.', 422);
    $q = $site->prepare('SELECT war_alert_ack(CAST(? AS bigint), ?)');
    $q->execute([$id, $messageId]);
    return (bool)$q->fetchColumn();
}
