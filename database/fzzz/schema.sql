--
-- PostgreSQL database dump
--

\restrict NUXc1fPBRo5qRcl2cRkhAQ5nNplagMkgA7iIV3AXyAIDaEDHAOjf1XpJmox3ylV

-- Dumped from database version 17.11 (Debian 17.11-1.pgdg13+2)
-- Dumped by pg_dump version 17.11 (Debian 17.11-1.pgdg13+2)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: pg_trgm; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS pg_trgm WITH SCHEMA public;


--
-- Name: EXTENSION pg_trgm; Type: COMMENT; Schema: -; Owner:
--

COMMENT ON EXTENSION pg_trgm IS 'text similarity measurement and index searching based on trigrams';


--
-- Name: unaccent; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS unaccent WITH SCHEMA public;


--
-- Name: EXTENSION unaccent; Type: COMMENT; Schema: -; Owner:
--

COMMENT ON EXTENSION unaccent IS 'text search dictionary that removes accents';


--
-- Name: fzzz_normalize(text); Type: FUNCTION; Schema: public; Owner: fzzz_owner
--

CREATE FUNCTION public.fzzz_normalize(text) RETURNS text
    LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
    AS $_$
 SELECT public.unaccent('public.unaccent'::regdictionary,lower($1))
$_$;


ALTER FUNCTION public.fzzz_normalize(text) OWNER TO fzzz_owner;

SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: alliances; Type: TABLE; Schema: public; Owner: uptep
--

CREATE TABLE public.alliances (
    server character varying(16) NOT NULL,
    tag character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    players_count bigint NOT NULL,
    total_field bigint NOT NULL,
    total_building_score bigint NOT NULL,
    total_technology_score bigint NOT NULL,
    total_trophy_score bigint NOT NULL,
    diplomacy jsonb NOT NULL,
    is_present boolean DEFAULT true NOT NULL,
    export_version character(12) NOT NULL,
    imported_at timestamp(3) without time zone NOT NULL,
    CONSTRAINT alliances_players_count_check CHECK ((players_count >= 0)),
    CONSTRAINT alliances_total_building_score_check CHECK ((total_building_score >= 0)),
    CONSTRAINT alliances_total_field_check CHECK ((total_field >= 0)),
    CONSTRAINT alliances_total_technology_score_check CHECK ((total_technology_score >= 0)),
    CONSTRAINT alliances_total_trophy_score_check CHECK ((total_trophy_score >= 0))
);


ALTER TABLE public.alliances OWNER TO uptep;

--
-- Name: export_sync; Type: TABLE; Schema: public; Owner: uptep
--

CREATE TABLE public.export_sync (
    server character varying(16) NOT NULL,
    export_type text NOT NULL,
    export_version character(12) NOT NULL,
    last_modified character varying(128),
    row_count bigint NOT NULL,
    imported_at timestamp(3) without time zone NOT NULL,
    checked_at timestamp(3) without time zone NOT NULL,
    CONSTRAINT export_sync_export_type_check CHECK ((export_type = ANY (ARRAY['players'::text, 'alliances'::text]))),
    CONSTRAINT export_sync_row_count_check CHECK ((row_count >= 0))
);


ALTER TABLE public.export_sync OWNER TO uptep;

--
-- Name: historique_alliances; Type: TABLE; Schema: public; Owner: uptep
--

CREATE TABLE public.historique_alliances (
    export_id bigint NOT NULL,
    tag character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    players_count bigint NOT NULL,
    total_field bigint NOT NULL,
    total_building_score bigint NOT NULL,
    total_technology_score bigint NOT NULL,
    total_trophy_score bigint NOT NULL,
    diplomacy jsonb NOT NULL,
    CONSTRAINT historique_alliances_export_id_check CHECK ((export_id >= 0)),
    CONSTRAINT historique_alliances_players_count_check CHECK ((players_count >= 0)),
    CONSTRAINT historique_alliances_total_building_score_check CHECK ((total_building_score >= 0)),
    CONSTRAINT historique_alliances_total_field_check CHECK ((total_field >= 0)),
    CONSTRAINT historique_alliances_total_technology_score_check CHECK ((total_technology_score >= 0)),
    CONSTRAINT historique_alliances_total_trophy_score_check CHECK ((total_trophy_score >= 0))
);


ALTER TABLE public.historique_alliances OWNER TO uptep;

--
-- Name: historique_exports; Type: TABLE; Schema: public; Owner: uptep
--

CREATE TABLE public.historique_exports (
    id bigint NOT NULL,
    server character varying(16) NOT NULL,
    export_type text NOT NULL,
    export_version character(12) NOT NULL,
    exported_at timestamp without time zone NOT NULL,
    last_modified character varying(128),
    content_sha256 character(64) NOT NULL,
    row_count bigint NOT NULL,
    imported_at timestamp(3) without time zone NOT NULL,
    checked_at timestamp(3) without time zone NOT NULL,
    CONSTRAINT historique_exports_export_type_check CHECK ((export_type = ANY (ARRAY['players'::text, 'alliances'::text]))),
    CONSTRAINT historique_exports_id_check CHECK ((id >= 0)),
    CONSTRAINT historique_exports_row_count_check CHECK ((row_count >= 0))
);


ALTER TABLE public.historique_exports OWNER TO uptep;

--
-- Name: historique_exports_id_seq; Type: SEQUENCE; Schema: public; Owner: uptep
--

ALTER TABLE public.historique_exports ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.historique_exports_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: historique_players; Type: TABLE; Schema: public; Owner: uptep
--

CREATE TABLE public.historique_players (
    export_id bigint NOT NULL,
    player_id bigint NOT NULL,
    pseudo character varying(255) NOT NULL,
    alliance_tag character varying(255),
    master_player_id bigint,
    x bigint NOT NULL,
    y bigint NOT NULL,
    field bigint NOT NULL,
    grade text,
    building_score bigint NOT NULL,
    technology_score bigint NOT NULL,
    trophy_score bigint NOT NULL,
    on_holiday boolean NOT NULL,
    is_banned boolean NOT NULL,
    CONSTRAINT historique_players_building_score_check CHECK ((building_score >= 0)),
    CONSTRAINT historique_players_export_id_check CHECK ((export_id >= 0)),
    CONSTRAINT historique_players_field_check CHECK ((field >= 0)),
    CONSTRAINT historique_players_master_player_id_check CHECK ((master_player_id >= 0)),
    CONSTRAINT historique_players_player_id_check CHECK ((player_id >= 0)),
    CONSTRAINT historique_players_technology_score_check CHECK ((technology_score >= 0)),
    CONSTRAINT historique_players_trophy_score_check CHECK ((trophy_score >= 0))
);


ALTER TABLE public.historique_players OWNER TO uptep;

--
-- Name: players; Type: TABLE; Schema: public; Owner: uptep
--

CREATE TABLE public.players (
    server character varying(16) NOT NULL,
    player_id bigint NOT NULL,
    pseudo character varying(255) NOT NULL,
    alliance_tag character varying(255),
    master_player_id bigint,
    x bigint NOT NULL,
    y bigint NOT NULL,
    field bigint NOT NULL,
    grade text,
    building_score bigint NOT NULL,
    technology_score bigint NOT NULL,
    trophy_score bigint NOT NULL,
    on_holiday boolean NOT NULL,
    is_banned boolean NOT NULL,
    is_present boolean DEFAULT true NOT NULL,
    export_version character(12) NOT NULL,
    imported_at timestamp(3) without time zone NOT NULL,
    CONSTRAINT players_building_score_check CHECK ((building_score >= 0)),
    CONSTRAINT players_field_check CHECK ((field >= 0)),
    CONSTRAINT players_master_player_id_check CHECK ((master_player_id >= 0)),
    CONSTRAINT players_player_id_check CHECK ((player_id >= 0)),
    CONSTRAINT players_technology_score_check CHECK ((technology_score >= 0)),
    CONSTRAINT players_trophy_score_check CHECK ((trophy_score >= 0))
);


ALTER TABLE public.players OWNER TO uptep;

--
-- Name: alliances alliances_pkey; Type: CONSTRAINT; Schema: public; Owner: uptep
--

ALTER TABLE ONLY public.alliances
    ADD CONSTRAINT alliances_pkey PRIMARY KEY (server, tag);


--
-- Name: export_sync export_sync_pkey; Type: CONSTRAINT; Schema: public; Owner: uptep
--

ALTER TABLE ONLY public.export_sync
    ADD CONSTRAINT export_sync_pkey PRIMARY KEY (server, export_type);


--
-- Name: historique_alliances historique_alliances_pkey; Type: CONSTRAINT; Schema: public; Owner: uptep
--

ALTER TABLE ONLY public.historique_alliances
    ADD CONSTRAINT historique_alliances_pkey PRIMARY KEY (export_id, tag);


--
-- Name: historique_exports historique_exports_pkey; Type: CONSTRAINT; Schema: public; Owner: uptep
--

ALTER TABLE ONLY public.historique_exports
    ADD CONSTRAINT historique_exports_pkey PRIMARY KEY (id);


--
-- Name: historique_players historique_players_pkey; Type: CONSTRAINT; Schema: public; Owner: uptep
--

ALTER TABLE ONLY public.historique_players
    ADD CONSTRAINT historique_players_pkey PRIMARY KEY (export_id, player_id);


--
-- Name: players players_pkey; Type: CONSTRAINT; Schema: public; Owner: uptep
--

ALTER TABLE ONLY public.players
    ADD CONSTRAINT players_pkey PRIMARY KEY (server, player_id);


--
-- Name: historique_alliances_idx_history_alliance; Type: INDEX; Schema: public; Owner: uptep
--

CREATE INDEX historique_alliances_idx_history_alliance ON public.historique_alliances USING btree (tag, export_id);


--
-- Name: historique_exports_idx_history_version; Type: INDEX; Schema: public; Owner: uptep
--

CREATE INDEX historique_exports_idx_history_version ON public.historique_exports USING btree (server, export_type, export_version, imported_at);


--
-- Name: historique_exports_uq_export_content; Type: INDEX; Schema: public; Owner: uptep
--

CREATE UNIQUE INDEX historique_exports_uq_export_content ON public.historique_exports USING btree (server, export_type, export_version, content_sha256);


--
-- Name: historique_players_idx_history_player; Type: INDEX; Schema: public; Owner: uptep
--

CREATE INDEX historique_players_idx_history_player ON public.historique_players USING btree (player_id, export_id);


--
-- Name: players_idx_players_alliance; Type: INDEX; Schema: public; Owner: uptep
--

CREATE INDEX players_idx_players_alliance ON public.players USING btree (server, alliance_tag);


--
-- Name: players_idx_players_pseudo; Type: INDEX; Schema: public; Owner: uptep
--

CREATE INDEX players_idx_players_pseudo ON public.players USING btree (server, pseudo);


--
-- Name: historique_alliances fk_history_alliances_export; Type: FK CONSTRAINT; Schema: public; Owner: uptep
--

ALTER TABLE ONLY public.historique_alliances
    ADD CONSTRAINT fk_history_alliances_export FOREIGN KEY (export_id) REFERENCES public.historique_exports(id);


--
-- Name: historique_players fk_history_players_export; Type: FK CONSTRAINT; Schema: public; Owner: uptep
--

ALTER TABLE ONLY public.historique_players
    ADD CONSTRAINT fk_history_players_export FOREIGN KEY (export_id) REFERENCES public.historique_exports(id);


--
-- PostgreSQL database dump complete
--

\unrestrict NUXc1fPBRo5qRcl2cRkhAQ5nNplagMkgA7iIV3AXyAIDaEDHAOjf1XpJmox3ylV
