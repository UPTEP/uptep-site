CREATE TABLE IF NOT EXISTS convoy_web_events (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 kind text NOT NULL CHECK(kind IN ('validation','convoi')),
 payload jsonb NOT NULL,
 created_at timestamptz NOT NULL DEFAULT now(),
 acknowledged_at timestamptz
);
CREATE INDEX IF NOT EXISTS convoy_web_events_pending ON convoy_web_events(id) WHERE acknowledged_at IS NULL;
