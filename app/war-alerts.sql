-- Alertes « préparation de guerre » : relevés évalués, épisodes et file d'événements Discord.
-- La détection est calculée par une requête sur la base fzzz (app/war-alerts.php) ;
-- son résultat JSON est enregistré ici par war_alert_record(), en une seule transaction.
CREATE TABLE IF NOT EXISTS war_alert_runs (
 server text NOT NULL,
 export_version text NOT NULL,
 export_id bigint NOT NULL,
 exported_at timestamptz NOT NULL,
 evaluated boolean NOT NULL,
 alert_count integer NOT NULL CHECK (alert_count >= 0),
 result jsonb NOT NULL,
 created_at timestamptz NOT NULL DEFAULT now(),
 PRIMARY KEY (server, export_version)
);

CREATE TABLE IF NOT EXISTS war_alert_episodes (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 server text NOT NULL,
 tag text NOT NULL,
 episode_start timestamptz NOT NULL,
 last_seen timestamptz NOT NULL,
 pumped_ids bigint[] NOT NULL,
 last_payload jsonb NOT NULL,
 message_id text CHECK (message_id ~ '^[1-9][0-9]{16,19}$'),
 created_at timestamptz NOT NULL DEFAULT now(),
 CHECK (last_seen >= episode_start)
);
CREATE INDEX IF NOT EXISTS war_alert_episodes_recent ON war_alert_episodes(server, tag, last_seen DESC);

CREATE TABLE IF NOT EXISTS war_alert_events (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 episode_id bigint NOT NULL REFERENCES war_alert_episodes(id),
 kind text NOT NULL CHECK (kind IN ('nouvelle','mise_a_jour')),
 payload jsonb NOT NULL,
 created_at timestamptz NOT NULL DEFAULT now(),
 acknowledged_at timestamptz,
 message_id text CHECK (message_id ~ '^[1-9][0-9]{16,19}$')
);
CREATE INDEX IF NOT EXISTS war_alert_events_pending ON war_alert_events(id) WHERE acknowledged_at IS NULL;

-- Enregistre le résultat d'une détection. Idempotent par (server, export_version) :
-- rejouer le même relevé renvoie recorded=false sans rien créer.
-- Un épisode continue si sa dernière détection date de p_gap_hours ou moins avant ce relevé ;
-- il ne produit alors un événement que si de nouveaux comptes gonflés apparaissent.
CREATE OR REPLACE FUNCTION war_alert_record(p_result jsonb, p_gap_hours numeric) RETURNS jsonb
LANGUAGE plpgsql AS $$
DECLARE
 v_server text := p_result->>'server';
 v_at timestamptz := (p_result->>'exported_at')::timestamptz;
 v_alert jsonb;
 v_ids bigint[];
 v_new bigint[];
 v_episode war_alert_episodes%ROWTYPE;
 v_id bigint;
 v_events integer := 0;
BEGIN
 IF v_server IS NULL OR v_at IS NULL OR p_result->>'export_version' IS NULL THEN
  RAISE EXCEPTION 'war_alert_record: résultat incomplet';
 END IF;
 INSERT INTO war_alert_runs(server, export_version, export_id, exported_at, evaluated, alert_count, result)
 VALUES (v_server, p_result->>'export_version', (p_result->>'export_id')::bigint, v_at,
         coalesce((p_result->>'evaluated')::boolean, false), jsonb_array_length(coalesce(p_result->'alerts', '[]')), p_result)
 ON CONFLICT DO NOTHING;
 IF NOT FOUND THEN
  RETURN jsonb_build_object('recorded', false, 'events', 0);
 END IF;
 FOR v_alert IN SELECT value || jsonb_build_object('exported_at', p_result->'exported_at', 'export_version', p_result->'export_version', 'span_hours', p_result->'span_hours')
                 FROM jsonb_array_elements(coalesce(p_result->'alerts', '[]')) LOOP
  v_ids := ARRAY(SELECT (x->>'player_id')::bigint FROM jsonb_array_elements(v_alert->'pumped') x ORDER BY 1);
  SELECT * INTO v_episode FROM war_alert_episodes
   WHERE server = v_server AND tag = v_alert->>'tag'
     AND last_seen <= v_at AND last_seen >= v_at - make_interval(secs => p_gap_hours * 3600)
   ORDER BY last_seen DESC LIMIT 1 FOR UPDATE;
  IF FOUND THEN
   v_new := ARRAY(SELECT unnest(v_ids) EXCEPT SELECT unnest(v_episode.pumped_ids) ORDER BY 1);
   UPDATE war_alert_episodes
      SET last_seen = v_at, last_payload = v_alert,
          pumped_ids = ARRAY(SELECT unnest(v_episode.pumped_ids) UNION SELECT unnest(v_new) ORDER BY 1)
    WHERE id = v_episode.id;
   IF cardinality(v_new) > 0 THEN
    INSERT INTO war_alert_events(episode_id, kind, payload)
    VALUES (v_episode.id, 'mise_a_jour', v_alert || jsonb_build_object(
      'episode_start', to_char(v_episode.episode_start AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"'),
      'new_player_ids', to_jsonb(ARRAY(SELECT x::text FROM unnest(v_new) x))));
    v_events := v_events + 1;
   END IF;
  ELSE
   INSERT INTO war_alert_episodes(server, tag, episode_start, last_seen, pumped_ids, last_payload)
   VALUES (v_server, v_alert->>'tag', v_at, v_at, v_ids, v_alert) RETURNING id INTO v_id;
   INSERT INTO war_alert_events(episode_id, kind, payload) VALUES (v_id, 'nouvelle', v_alert);
   v_events := v_events + 1;
  END IF;
 END LOOP;
 RETURN jsonb_build_object('recorded', true, 'events', v_events);
END $$;

-- Acquittement par le bot après publication ; le premier message d'un épisode sert de fil aux mises à jour.
CREATE OR REPLACE FUNCTION war_alert_ack(p_event bigint, p_message text) RETURNS boolean
LANGUAGE plpgsql AS $$
DECLARE v_episode bigint; v_kind text;
BEGIN
 UPDATE war_alert_events SET acknowledged_at = coalesce(acknowledged_at, now()), message_id = coalesce(message_id, p_message)
  WHERE id = p_event RETURNING episode_id, kind INTO v_episode, v_kind;
 IF NOT FOUND THEN RETURN false; END IF;
 IF v_kind = 'nouvelle' THEN
  UPDATE war_alert_episodes SET message_id = coalesce(message_id, p_message) WHERE id = v_episode;
 END IF;
 RETURN true;
END $$;
