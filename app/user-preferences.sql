BEGIN;
-- Per-account display preferences (one JSON document per page), e.g. the Mouvements filters.
CREATE TABLE IF NOT EXISTS user_preferences (
 user_id bigint NOT NULL REFERENCES users(id) ON DELETE CASCADE,
 page text NOT NULL CHECK (page ~ '^[a-z][a-z0-9_-]{0,39}$'),
 value jsonb NOT NULL CHECK (jsonb_typeof(value)='object' AND octet_length(value::text)<=8192),
 updated_at timestamptz NOT NULL DEFAULT now(),
 PRIMARY KEY (user_id,page)
);
COMMIT;
