BEGIN;
CREATE TABLE IF NOT EXISTS script_flood_links (
 sender_id bigint NOT NULL, game_id bigint NOT NULL CHECK(game_id>0),
 flood_id bigint NOT NULL UNIQUE REFERENCES floods(id),
 PRIMARY KEY(sender_id,game_id)
);
ALTER TABLE script_flood_links OWNER TO uptep;
ALTER TABLE convoy_web_events DROP CONSTRAINT IF EXISTS convoy_web_events_kind_check;
ALTER TABLE convoy_web_events ADD CONSTRAINT convoy_web_events_kind_check CHECK(kind IN ('validation','convoi','flood'));
COMMIT;
