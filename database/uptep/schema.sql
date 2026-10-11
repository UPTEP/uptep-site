CREATE TABLE IF NOT EXISTS users (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 username varchar(40) NOT NULL UNIQUE CHECK (username ~ '^[a-z0-9][a-z0-9_.-]{2,39}$'),
 display_name varchar(80) NOT NULL,
 game_player_id bigint CHECK (game_player_id > 0),
 password_hash text NOT NULL,
 role varchar(12) NOT NULL DEFAULT 'member' CHECK (role IN ('admin','member')),
 is_chef boolean NOT NULL DEFAULT false,
 is_chaine boolean NOT NULL DEFAULT false,
 permissions_version integer NOT NULL DEFAULT 1,
 status varchar(12) NOT NULL DEFAULT 'active' CHECK (status IN ('pending','active','disabled')),
 must_change_password boolean NOT NULL DEFAULT true,
 session_version integer NOT NULL DEFAULT 1,
 created_at timestamptz NOT NULL DEFAULT now(),
 approved_at timestamptz,
 approved_by bigint REFERENCES users(id),
 last_login_at timestamptz
);
CREATE TABLE IF NOT EXISTS login_limits (
 bucket char(64) PRIMARY KEY,
 attempts integer NOT NULL,
 started_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS login_limits_expiry ON login_limits(started_at);

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

CREATE TABLE IF NOT EXISTS user_stats (
 user_id bigint PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
 data jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(data) = 'object'),
 version integer NOT NULL DEFAULT 1 CHECK (version > 0),
 updated_at timestamptz NOT NULL DEFAULT now()
);
ALTER TABLE user_stats OWNER TO fzzz;

ALTER TABLE users ADD COLUMN IF NOT EXISTS script_enabled boolean NOT NULL DEFAULT true;
CREATE TABLE IF NOT EXISTS script_tokens (
 token_hash char(64) PRIMARY KEY,
 user_id bigint NOT NULL REFERENCES users(id) ON DELETE CASCADE,
 player_id bigint NOT NULL CHECK(player_id > 0),
 created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS script_tokens_user ON script_tokens(user_id);
ALTER TABLE script_tokens OWNER TO fzzz;

BEGIN;
CREATE TABLE IF NOT EXISTS script_settings (
 user_id bigint PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
 preferences jsonb NOT NULL DEFAULT '{"buildings":true,"research":true,"chaine":true,"clock":true,"statistics":true}'::jsonb,
 version integer NOT NULL DEFAULT 1 CHECK(version>0),
 buildings_synced_at timestamptz,
 research_synced_at timestamptz,
 updated_at timestamptz NOT NULL DEFAULT now()
);
ALTER TABLE script_settings OWNER TO fzzz;
GRANT SELECT,INSERT,UPDATE,DELETE ON script_settings TO uptep;
COMMIT;

BEGIN;
ALTER TABLE user_stats ADD COLUMN IF NOT EXISTS buildings_synced_at timestamptz;
ALTER TABLE user_stats ADD COLUMN IF NOT EXISTS research_synced_at timestamptz;
ALTER TABLE user_stats ADD COLUMN IF NOT EXISTS army_synced_at timestamptz;
-- Only transfer known collection dates; never infer a collection from updated_at.
UPDATE user_stats u SET buildings_synced_at=coalesce(u.buildings_synced_at,s.buildings_synced_at),research_synced_at=coalesce(u.research_synced_at,s.research_synced_at) FROM script_settings s WHERE s.user_id=u.user_id;
COMMIT;
BEGIN;
UPDATE script_settings SET preferences=preferences||'{"army":true}'::jsonb,version=version+1 WHERE NOT preferences ? 'army';
ALTER TABLE script_settings ALTER COLUMN preferences SET DEFAULT '{"buildings":true,"research":true,"army":true,"chaine":true,"clock":true,"statistics":true}'::jsonb;
COMMIT;

BEGIN;
ALTER TABLE user_stats ADD COLUMN IF NOT EXISTS resources_synced_at timestamptz;
ALTER TABLE user_stats ADD COLUMN IF NOT EXISTS workers_synced_at timestamptz;
UPDATE script_settings SET preferences=preferences||'{"economy":true}'::jsonb,version=version+1 WHERE NOT preferences ? 'economy';
ALTER TABLE script_settings ALTER COLUMN preferences SET DEFAULT '{"buildings":true,"research":true,"army":true,"economy":true,"chaine":true,"clock":true,"statistics":true}'::jsonb;
COMMIT;

-- Player display name cache and optional Discord identifier (text preserves exact IDs).
ALTER TABLE chaine_members ADD COLUMN IF NOT EXISTS pseudo text;
ALTER TABLE chaine_members ADD COLUMN IF NOT EXISTS iddiscord text;
-- Discord IDs are exact 64-bit integers, never floating-point numbers.
ALTER TABLE chaine_members ALTER COLUMN iddiscord TYPE bigint USING iddiscord::bigint;

BEGIN;
CREATE TABLE demandes_convois (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 serveur varchar(16) NOT NULL DEFAULT 's5',
 iddemandeur bigint NOT NULL CHECK(iddemandeur>0), pseudo_demandeur text NOT NULL, discord_demandeur bigint NOT NULL,
 nbpomme_original bigint NOT NULL CHECK(nbpomme_original>=0), nbpomme_restant bigint NOT NULL,
 nbmateriaux_original bigint NOT NULL CHECK(nbmateriaux_original>=0), nbmateriaux_restant bigint NOT NULL,
 valide boolean NOT NULL DEFAULT false, idvalidateur bigint, pseudo_validateur text, discord_validateur bigint,
 date_demande timestamptz NOT NULL DEFAULT now(), date_validation timestamptz,
 fini boolean NOT NULL DEFAULT false, date_fin timestamptz,
 CHECK(nbpomme_original>0 OR nbmateriaux_original>0),
 CHECK(nbpomme_restant BETWEEN 0 AND nbpomme_original), CHECK(nbmateriaux_restant BETWEEN 0 AND nbmateriaux_original),
 CHECK((valide AND idvalidateur IS NOT NULL AND pseudo_validateur IS NOT NULL AND discord_validateur IS NOT NULL AND date_validation IS NOT NULL) OR (NOT valide AND idvalidateur IS NULL AND pseudo_validateur IS NULL AND discord_validateur IS NULL AND date_validation IS NULL)),
 CHECK(fini=(nbpomme_restant=0 AND nbmateriaux_restant=0)),
 CHECK((fini AND valide AND date_fin IS NOT NULL) OR (NOT fini AND date_fin IS NULL))
);
CREATE INDEX demandes_convois_etat ON demandes_convois(valide,id) WHERE NOT fini;
CREATE TABLE convois_effectues (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 iddemande bigint NOT NULL REFERENCES demandes_convois(id) ON DELETE RESTRICT,
 idenvoyeur bigint NOT NULL CHECK(idenvoyeur>0), pseudo_envoyeur text NOT NULL, discord_envoyeur bigint NOT NULL,
 nbpomme bigint NOT NULL CHECK(nbpomme>=0), nbmateriaux bigint NOT NULL CHECK(nbmateriaux>=0),
 date_convoi timestamptz NOT NULL, date_enregistrement timestamptz NOT NULL DEFAULT now(),
 CHECK(nbpomme>0 OR nbmateriaux>0)
);
CREATE INDEX convois_effectues_demande ON convois_effectues(iddemande,id);
-- Transactional command receipts prevent duplicate writes and retain their original response.
CREATE TABLE bot_commandes (
 commande_id varchar(100) PRIMARY KEY,
 empreinte char(64) NOT NULL,
 discord_acteur bigint NOT NULL,
 action text NOT NULL,
 statut integer NOT NULL,
 reponse jsonb NOT NULL,
 date_creation timestamptz NOT NULL DEFAULT now()
);
ALTER TABLE demandes_convois OWNER TO fzzz;
ALTER TABLE convois_effectues OWNER TO fzzz;
ALTER TABLE bot_commandes OWNER TO fzzz;
GRANT SELECT,INSERT,UPDATE,DELETE ON demandes_convois,convois_effectues,bot_commandes TO uptep;
GRANT USAGE,SELECT ON SEQUENCE demandes_convois_id_seq,convois_effectues_id_seq TO uptep;
COMMIT;
BEGIN;
ALTER TABLE demandes_convois ADD COLUMN objet text CHECK (length(objet) BETWEEN 1 AND 160);
ALTER TABLE demandes_convois ADD COLUMN niveau integer CHECK(niveau BETWEEN 1 AND 999);
ALTER TABLE demandes_convois ADD COLUMN date_souhaitee date;
ALTER TABLE demandes_convois ADD COLUMN annulee boolean NOT NULL DEFAULT false;
ALTER TABLE demandes_convois ADD COLUMN date_annulation timestamptz;
ALTER TABLE demandes_convois ADD COLUMN idannulateur bigint;
ALTER TABLE demandes_convois ADD COLUMN pseudo_annulateur text;
ALTER TABLE demandes_convois ADD COLUMN discord_annulateur bigint;
ALTER TABLE demandes_convois ADD CONSTRAINT demande_annulation CHECK(
 (annulee AND NOT fini AND date_annulation IS NOT NULL AND idannulateur IS NOT NULL AND pseudo_annulateur IS NOT NULL AND discord_annulateur IS NOT NULL)
 OR (NOT annulee AND date_annulation IS NULL AND idannulateur IS NULL AND pseudo_annulateur IS NULL AND discord_annulateur IS NULL));
ALTER TABLE convois_effectues ADD COLUMN date_arrivee timestamptz;
COMMIT;

BEGIN;
ALTER TABLE demandes_convois ADD COLUMN fin_manuelle boolean NOT NULL DEFAULT false;
ALTER TABLE demandes_convois ADD COLUMN discord_cloture bigint;
ALTER TABLE demandes_convois ADD COLUMN pseudo_cloture text;
ALTER TABLE demandes_convois DROP CONSTRAINT demandes_convois_check4;
ALTER TABLE demandes_convois ADD CONSTRAINT demande_solde_fin CHECK(fini=(fin_manuelle OR (nbpomme_restant=0 AND nbmateriaux_restant=0)));
ALTER TABLE demandes_convois ADD CONSTRAINT demande_fin_audit CHECK((fin_manuelle AND fini AND discord_cloture IS NOT NULL AND pseudo_cloture IS NOT NULL) OR (NOT fin_manuelle AND discord_cloture IS NULL AND pseudo_cloture IS NULL));
ALTER TABLE convois_effectues ADD COLUMN annule boolean NOT NULL DEFAULT false;
ALTER TABLE convois_effectues ADD COLUMN date_annulation timestamptz;
ALTER TABLE convois_effectues ADD COLUMN discord_annulateur bigint;
ALTER TABLE convois_effectues ADD COLUMN pseudo_annulateur text;
ALTER TABLE convois_effectues ADD CONSTRAINT convoi_annulation_audit CHECK((annule AND date_annulation IS NOT NULL AND discord_annulateur IS NOT NULL AND pseudo_annulateur IS NOT NULL) OR (NOT annule AND date_annulation IS NULL AND discord_annulateur IS NULL AND pseudo_annulateur IS NULL));
COMMIT;

-- Manual adjustments of remaining convoy demand, with separate audit.
BEGIN;
CREATE TABLE demandes_ajustements (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 iddemande bigint NOT NULL REFERENCES demandes_convois(id) ON DELETE RESTRICT,
 discord_acteur bigint NOT NULL,
 pseudo_acteur text NOT NULL,
 pommes_avant bigint NOT NULL CHECK(pommes_avant>=0),
 pommes_apres bigint NOT NULL CHECK(pommes_apres>=0),
 materiaux_avant bigint NOT NULL CHECK(materiaux_avant>=0),
 materiaux_apres bigint NOT NULL CHECK(materiaux_apres>=0),
 date_ajustement timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX demandes_ajustements_demande ON demandes_ajustements(iddemande,id);
ALTER TABLE demandes_ajustements OWNER TO fzzz;
GRANT SELECT,INSERT ON demandes_ajustements TO uptep;
GRANT USAGE,SELECT ON SEQUENCE demandes_ajustements_id_seq TO uptep;
COMMIT;
-- Add the optional laboratory comparison without overwriting saved choices.
UPDATE script_settings SET preferences=preferences||'{"laboratory":true}'::jsonb,version=version+1 WHERE NOT preferences ? 'laboratory';
ALTER TABLE script_settings ALTER COLUMN preferences SET DEFAULT '{"buildings":true,"research":true,"army":true,"economy":true,"chaine":true,"clock":true,"statistics":true,"laboratory":true}'::jsonb;
