BEGIN;
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_site_seen_at timestamptz;
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_script_seen_at timestamptz;
CREATE TABLE IF NOT EXISTS member_stat_history (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 user_id bigint NOT NULL REFERENCES users(id) ON DELETE CASCADE,
 stat_group text NOT NULL CHECK(stat_group IN ('buildings','research','army','resources','workers')),
 data jsonb NOT NULL CHECK(jsonb_typeof(data)='object'),
 observed_at timestamptz NOT NULL DEFAULT now(),
 source text NOT NULL CHECK(source IN ('script','manual','initial'))
);
CREATE INDEX IF NOT EXISTS member_stat_history_lookup ON member_stat_history(user_id,stat_group,observed_at,id);
ALTER TABLE member_stat_history OWNER TO uptep;
GRANT SELECT,INSERT ON member_stat_history TO uptep;
GRANT USAGE,SELECT ON SEQUENCE member_stat_history_id_seq TO uptep;
CREATE OR REPLACE FUNCTION record_member_stat_history() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE group_name text; sync_name text; previous jsonb; current_data jsonb; origin text;
BEGIN
 FOREACH group_name IN ARRAY ARRAY['buildings','research','army','workers','resources'] LOOP
  current_data=NEW.data->group_name;
  IF current_data IS NULL OR current_data='null'::jsonb OR current_data='{}'::jsonb THEN CONTINUE; END IF;
  sync_name=group_name||'_synced_at';previous=NULL;
  IF TG_OP='UPDATE' THEN previous=OLD.data->group_name; END IF;
  IF current_data IS DISTINCT FROM previous OR (group_name IN ('workers','resources') AND (TG_OP='INSERT' OR to_jsonb(NEW)->sync_name IS DISTINCT FROM to_jsonb(OLD)->sync_name)) THEN
   origin='manual';
   IF (TG_OP='INSERT' AND to_jsonb(NEW)->>sync_name IS NOT NULL) OR (TG_OP='UPDATE' AND to_jsonb(NEW)->sync_name IS DISTINCT FROM to_jsonb(OLD)->sync_name) THEN origin='script'; END IF;
   INSERT INTO member_stat_history(user_id,stat_group,data,observed_at,source) VALUES(NEW.user_id,group_name,current_data,statement_timestamp(),origin);
  END IF;
 END LOOP;
 RETURN NEW;
END;$$;
ALTER FUNCTION record_member_stat_history() OWNER TO uptep;
DROP TRIGGER IF EXISTS member_stat_history_capture ON user_stats;
CREATE TRIGGER member_stat_history_capture AFTER INSERT OR UPDATE ON user_stats FOR EACH ROW EXECUTE FUNCTION record_member_stat_history();
-- Start with the current known state at installation time, never backdate an observation.
INSERT INTO member_stat_history(user_id,stat_group,data,observed_at,source)
SELECT s.user_id,g.key,g.value,statement_timestamp(),'initial'
FROM user_stats s CROSS JOIN LATERAL jsonb_each(s.data) g
WHERE g.key IN ('buildings','research','army','workers','resources') AND jsonb_typeof(g.value)='object' AND g.value<>'{}'::jsonb
AND NOT EXISTS(SELECT 1 FROM member_stat_history h WHERE h.user_id=s.user_id AND h.stat_group=g.key);
COMMIT;
