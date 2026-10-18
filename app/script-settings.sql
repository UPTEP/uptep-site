BEGIN;
CREATE TABLE script_settings (
 user_id bigint PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
 preferences jsonb NOT NULL DEFAULT '{"playerProfile":true,"convoys":true,"floodDeclarations":true,"multiflood":true,"traffic":true,"laboratory":true,"economy":false,"army":false,"buildings":false,"research":false,"chaine":true,"clock":true,"statistics":true}'::jsonb,
 version integer NOT NULL DEFAULT 1 CHECK(version>0),
 buildings_synced_at timestamptz,
 research_synced_at timestamptz,
 updated_at timestamptz NOT NULL DEFAULT now()
);
ALTER TABLE script_settings OWNER TO fzzz;
GRANT SELECT,INSERT,UPDATE,DELETE ON script_settings TO uptep;
COMMIT;
