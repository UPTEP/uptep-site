BEGIN;
LOCK TABLE combat_reports,combat_observations,combat_observation_candidates,combat_observation_units IN ACCESS EXCLUSIVE MODE;
DO $$
BEGIN
 IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=current_schema() AND table_name='combat_observation_candidates' AND column_name='attacker_observation_id') THEN
  ALTER TABLE combat_observation_candidates ADD COLUMN observation_id bigint REFERENCES combat_observations(id) ON DELETE CASCADE;
  ALTER TABLE combat_observation_candidates ADD COLUMN shield integer CHECK(shield BETWEEN 0 AND 40);
  ALTER TABLE combat_observation_candidates ALTER COLUMN building_level DROP NOT NULL;
  UPDATE combat_observation_candidates c SET
   observation_id=CASE r.target_side WHEN 'attacker' THEN c.attacker_observation_id ELSE c.defender_observation_id END,
   shield=CASE r.target_side WHEN 'attacker' THEN c.attacker_shield ELSE c.defender_shield END,
   building_level=CASE WHEN r.target_side='defender' AND c.location<>'terrain' THEN c.building_level ELSE NULL END
   FROM combat_observations a JOIN combat_reports r ON r.id=a.report_id WHERE a.id=c.attacker_observation_id;
  ALTER TABLE combat_observation_candidates ALTER COLUMN observation_id SET NOT NULL;
  ALTER TABLE combat_observation_candidates ALTER COLUMN shield SET NOT NULL;
  ALTER TABLE combat_observation_candidates DROP COLUMN attacker_observation_id, DROP COLUMN defender_observation_id, DROP COLUMN attacker_shield, DROP COLUMN defender_shield;
  DELETE FROM combat_observation_candidates WHERE id IN (
   SELECT id FROM (SELECT id,row_number() OVER(PARTITION BY observation_id,shield,location,building_level ORDER BY id) n FROM combat_observation_candidates) duplicates WHERE n>1
  );
 END IF;
END $$;
-- Only the selected player's structured observation survives. The original RC is retained.
DELETE FROM combat_observations o USING combat_reports r WHERE o.report_id=r.id AND o.side<>r.target_side;
ALTER TABLE combat_observations ALTER COLUMN player_id SET NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS combat_single_observation_idx ON combat_observations(report_id);
CREATE INDEX IF NOT EXISTS combat_candidates_observation_idx ON combat_observation_candidates(observation_id);
COMMIT;
