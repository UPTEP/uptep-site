ALTER TABLE users ADD COLUMN IF NOT EXISTS game_player_id bigint CHECK (game_player_id > 0);
