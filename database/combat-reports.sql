BEGIN;
CREATE TABLE IF NOT EXISTS combat_reports (
 id bigserial PRIMARY KEY,
 server varchar(8) NOT NULL DEFAULT 's5' CHECK(server='s5'),
 raw_text text NOT NULL CHECK(length(raw_text) BETWEEN 1 AND 100000),
 content_hash char(64) NOT NULL UNIQUE,
 combat_at timestamptz NOT NULL,
 date_source text NOT NULL CHECK(date_source IN ('detected','manual')),
 location text CHECK(location IN ('terrain','dome','loge')),
 target_side text NOT NULL CHECK(target_side IN ('attacker','defender')),
 created_by bigint REFERENCES users(id) ON DELETE SET NULL,
 created_at timestamptz NOT NULL DEFAULT now(),
 analysis_version integer NOT NULL DEFAULT 1
);
CREATE TABLE IF NOT EXISTS combat_observations (
 id bigserial PRIMARY KEY,
 report_id bigint NOT NULL REFERENCES combat_reports(id) ON DELETE CASCADE,
 side text NOT NULL CHECK(side IN ('attacker','defender')),
 player_id bigint CHECK(player_id>=0),
 player_name varchar(255),
 observed_at timestamptz NOT NULL,
 arms integer CHECK(arms BETWEEN 0 AND 100),
 shield integer CHECK(shield BETWEEN 0 AND 100),
 dome integer CHECK(dome BETWEEN 0 AND 100),
 loge integer CHECK(loge BETWEEN 0 AND 100),
 attack_base numeric(30,4) NOT NULL CHECK(attack_base>=0),
 attack_bonus numeric(30,4) CHECK(attack_bonus>=0),
 hp_base numeric(30,4) NOT NULL CHECK(hp_base>=0),
 hp_bonus numeric(30,4) CHECK(hp_bonus>=0),
 hp_min numeric(30,4), hp_max numeric(30,4),
 provenance jsonb NOT NULL DEFAULT '{}'::jsonb,
 UNIQUE(report_id,side)
);
CREATE TABLE IF NOT EXISTS combat_observation_units (
 observation_id bigint NOT NULL REFERENCES combat_observations(id) ON DELETE CASCADE,
 unit_id smallint NOT NULL CHECK(unit_id BETWEEN 1 AND 14),
 quantity bigint NOT NULL CHECK(quantity>=0),
 PRIMARY KEY(observation_id,unit_id)
);
-- Une ligne représente une combinaison complète : les niveaux des deux camps restent corrélés.
CREATE TABLE IF NOT EXISTS combat_observation_candidates (
 id bigserial PRIMARY KEY,
 attacker_observation_id bigint NOT NULL REFERENCES combat_observations(id) ON DELETE CASCADE,
 defender_observation_id bigint NOT NULL REFERENCES combat_observations(id) ON DELETE CASCADE,
 attacker_shield integer NOT NULL CHECK(attacker_shield BETWEEN 0 AND 40),
 defender_shield integer NOT NULL CHECK(defender_shield BETWEEN 0 AND 40),
 location text NOT NULL CHECK(location IN ('terrain','dome','loge')),
 building_level integer NOT NULL CHECK(building_level BETWEEN 0 AND 40)
);
CREATE INDEX IF NOT EXISTS combat_reports_date_idx ON combat_reports(combat_at DESC,id DESC);
CREATE INDEX IF NOT EXISTS combat_observations_player_idx ON combat_observations(player_id,observed_at DESC);
CREATE INDEX IF NOT EXISTS combat_candidates_attacker_idx ON combat_observation_candidates(attacker_observation_id);
CREATE INDEX IF NOT EXISTS combat_candidates_defender_idx ON combat_observation_candidates(defender_observation_id);
COMMIT;
