-- Hunts in progress read by UPTEP+ on AcquerirTerrain.php (opt-in "hunts" collection).
BEGIN;
CREATE TABLE IF NOT EXISTS script_hunts (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 player_id bigint NOT NULL CHECK(player_id>0),
 user_id bigint REFERENCES users(id) ON DELETE SET NULL,
 game_hunt_id bigint CHECK(game_hunt_id>0),
 area bigint NOT NULL CHECK(area>0),
 arrival_at timestamptz NOT NULL,
 first_seen_at timestamptz NOT NULL DEFAULT now(),
 last_seen_at timestamptz NOT NULL DEFAULT now(),
 cancelled_at timestamptz,
 UNIQUE(player_id,game_hunt_id)
);
CREATE INDEX IF NOT EXISTS script_hunts_player_arrival ON script_hunts(player_id,arrival_at);
ALTER TABLE script_hunts OWNER TO uptep;
ALTER TABLE script_settings ADD COLUMN IF NOT EXISTS hunts_synced_at timestamptz;
COMMIT;
