ALTER TABLE discord_login_tokens ADD COLUMN IF NOT EXISTS test_mode boolean NOT NULL DEFAULT false;
