ALTER TABLE users ADD COLUMN IF NOT EXISTS password_setup_required boolean NOT NULL DEFAULT false;
ALTER TABLE users ALTER COLUMN username TYPE varchar(80);
ALTER TABLE users DROP CONSTRAINT IF EXISTS users_username_check;
ALTER TABLE users ADD CONSTRAINT users_username_check CHECK (username = btrim(username) AND char_length(username) BETWEEN 1 AND 80 AND username !~ '[[:cntrl:]]');
CREATE UNIQUE INDEX IF NOT EXISTS users_username_lower_unique ON users(lower(username));
