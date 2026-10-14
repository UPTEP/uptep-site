CREATE TABLE IF NOT EXISTS discord_login_tokens (
    token_hash char(64) PRIMARY KEY,
    user_id bigint NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    discord_id varchar(20) NOT NULL,
    session_version integer NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    expires_at timestamptz NOT NULL DEFAULT (now() + interval '2 minutes')
);
CREATE INDEX IF NOT EXISTS discord_login_tokens_expiry ON discord_login_tokens(expires_at);
ALTER TABLE discord_login_tokens OWNER TO uptep;
