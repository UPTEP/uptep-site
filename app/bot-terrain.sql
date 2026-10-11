CREATE TABLE IF NOT EXISTS terrain_discord_channels (
 guild_id text NOT NULL, channel_id text NOT NULL, last_message_id numeric(20,0) NOT NULL DEFAULT 0,
 updated_at timestamptz NOT NULL DEFAULT now(), PRIMARY KEY(guild_id,channel_id)
);
CREATE TABLE IF NOT EXISTS terrain_discord_batches (
 id text PRIMARY KEY, guild_id text NOT NULL, channel_id text NOT NULL, payload_hash text NOT NULL,
 response jsonb NOT NULL, warnings jsonb NOT NULL DEFAULT '[]', published_message_id text,
 created_at timestamptz NOT NULL DEFAULT now(),
 FOREIGN KEY(guild_id,channel_id) REFERENCES terrain_discord_channels(guild_id,channel_id)
);
CREATE TABLE IF NOT EXISTS terrain_discord_reports (
 guild_id text NOT NULL, channel_id text NOT NULL, message_id text NOT NULL,
 author_id text, content text NOT NULL, fingerprint text, duplicate_of text,
 batch_id text NOT NULL REFERENCES terrain_discord_batches(id), PRIMARY KEY(guild_id,channel_id,message_id)
);
CREATE UNIQUE INDEX IF NOT EXISTS terrain_discord_report_fingerprint ON terrain_discord_reports(guild_id,channel_id,fingerprint) WHERE fingerprint IS NOT NULL AND duplicate_of IS NULL;
CREATE TABLE IF NOT EXISTS terrain_discord_movements (
 guild_id text NOT NULL, channel_id text NOT NULL, event_id text NOT NULL, message_id text NOT NULL,
 direction text NOT NULL CHECK(direction IN ('gain','loss')), amount numeric(30,0) NOT NULL CHECK(amount>0),
 enemy text NOT NULL, member text, occurred_at_text text, quote text NOT NULL,
 PRIMARY KEY(guild_id,channel_id,event_id),
 FOREIGN KEY(guild_id,channel_id,message_id) REFERENCES terrain_discord_reports(guild_id,channel_id,message_id)
);
ALTER TABLE terrain_discord_channels OWNER TO uptep;
ALTER TABLE terrain_discord_batches OWNER TO uptep;
ALTER TABLE terrain_discord_reports OWNER TO uptep;
ALTER TABLE terrain_discord_movements OWNER TO uptep;

-- Optional existing phpPgAdmin administrator; application access remains owned by uptep.
DO $$ BEGIN
 IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'fzzz_admin') THEN
  GRANT ALL PRIVILEGES ON TABLE terrain_discord_channels, terrain_discord_batches, terrain_discord_reports, terrain_discord_movements TO fzzz_admin;
 END IF;
END $$;
