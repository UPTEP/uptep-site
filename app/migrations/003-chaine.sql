CREATE TABLE IF NOT EXISTS chaine_members (
 server varchar(16) NOT NULL,
 player_id bigint NOT NULL,
 grade text CHECK (grade IN ('Grenier','Passeur','Chasseur','A flooder','Ne pas toucher','Hors chaîne')),
 sort_order integer CHECK (sort_order BETWEEN 1 AND 999999),
 version integer NOT NULL DEFAULT 1,
 updated_by bigint REFERENCES users(id) ON DELETE SET NULL,
 updated_at timestamptz NOT NULL DEFAULT now(),
 PRIMARY KEY (server,player_id)
);
