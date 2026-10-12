CREATE TABLE IF NOT EXISTS bot_flood_batches (
 commande_id text PRIMARY KEY, payload_hash text NOT NULL, response jsonb NOT NULL,
 created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE IF NOT EXISTS floods (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 commande_id text NOT NULL REFERENCES bot_flood_batches(commande_id),
 ordinal integer NOT NULL CHECK(ordinal>=0),
 sender_id bigint NOT NULL, sender_pseudo text NOT NULL, sender_discord bigint NOT NULL,
 target_id bigint, target_pseudo text NOT NULL, target_discord bigint,
 location text NOT NULL, troops text, arrival_at timestamptz NOT NULL,
 captured_at timestamptz NOT NULL, created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(commande_id,ordinal)
);
CREATE INDEX IF NOT EXISTS floods_arrival ON floods(arrival_at,id);
CREATE INDEX IF NOT EXISTS floods_target_arrival ON floods(target_id,arrival_at,id);
ALTER TABLE bot_flood_batches OWNER TO uptep;
ALTER TABLE floods OWNER TO uptep;
DO $$ BEGIN IF EXISTS(SELECT 1 FROM pg_roles WHERE rolname='fzzz_admin') THEN
 GRANT ALL PRIVILEGES ON bot_flood_batches,floods TO fzzz_admin;
 GRANT ALL PRIVILEGES ON SEQUENCE floods_id_seq TO fzzz_admin;
END IF; END $$;
