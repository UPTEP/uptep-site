-- Additive migration; does not change roles, passwords or existing associations.
ALTER TABLE users ADD COLUMN IF NOT EXISTS discord_login_id bigint;
CREATE UNIQUE INDEX IF NOT EXISTS users_discord_login_id_unique ON users(discord_login_id) WHERE discord_login_id IS NOT NULL;
ALTER TABLE discord_login_tokens ALTER COLUMN user_id DROP NOT NULL;
ALTER TABLE discord_login_tokens ALTER COLUMN session_version DROP NOT NULL;
ALTER TABLE discord_login_tokens ADD COLUMN IF NOT EXISTS player_id bigint;
UPDATE discord_login_tokens t SET player_id = u.game_player_id FROM users u WHERE t.user_id = u.id AND t.player_id IS NULL;
-- Old tokens with no player are no longer usable, but no business data is removed.
DELETE FROM discord_login_tokens WHERE player_id IS NULL;
ALTER TABLE discord_login_tokens ALTER COLUMN player_id SET NOT NULL;
