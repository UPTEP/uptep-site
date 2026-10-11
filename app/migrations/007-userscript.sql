ALTER TABLE users ADD COLUMN IF NOT EXISTS script_enabled boolean NOT NULL DEFAULT true;
CREATE TABLE IF NOT EXISTS script_tokens (
 token_hash char(64) PRIMARY KEY,
 user_id bigint NOT NULL REFERENCES users(id) ON DELETE CASCADE,
 player_id bigint NOT NULL CHECK(player_id > 0),
 created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS script_tokens_user ON script_tokens(user_id);
ALTER TABLE script_tokens OWNER TO fzzz;
