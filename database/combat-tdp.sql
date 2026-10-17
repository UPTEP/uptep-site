BEGIN;
CREATE TABLE IF NOT EXISTS combat_tdp_confirmations (
 id bigserial PRIMARY KEY,
 player_id bigint NOT NULL,
 first_observation_id bigint NOT NULL REFERENCES combat_observations(id),
 second_observation_id bigint NOT NULL REFERENCES combat_observations(id),
 total integer NOT NULL CHECK(total BETWEEN 0 AND 300),
 observed_at timestamptz NOT NULL,
 confirmed_by bigint REFERENCES users(id) ON DELETE SET NULL,
 confirmed_at timestamptz NOT NULL DEFAULT now(),
 evidence jsonb NOT NULL,
 token varchar(48) NOT NULL UNIQUE,
 CHECK(first_observation_id <> second_observation_id)
);
CREATE INDEX IF NOT EXISTS combat_tdp_player_date ON combat_tdp_confirmations(player_id,observed_at DESC,confirmed_at DESC,id DESC);
COMMIT;
