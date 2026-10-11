CREATE TABLE IF NOT EXISTS user_stats (
 user_id bigint PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
 data jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(data) = 'object'),
 version integer NOT NULL DEFAULT 1 CHECK (version > 0),
 updated_at timestamptz NOT NULL DEFAULT now()
);
ALTER TABLE user_stats OWNER TO fzzz;
