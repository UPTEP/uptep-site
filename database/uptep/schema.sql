--
-- PostgreSQL database dump
--

\restrict XOsOhMRKTEGlB59fqqvtmFGjPpIpLIbQkplvhgBpP5mxJLaYJu2ygZ5j8Z0Neon

-- Dumped from database version 17.11 (Debian 17.11-1.pgdg13+2)
-- Dumped by pg_dump version 17.11 (Debian 17.11-1.pgdg13+2)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'SQL_ASCII';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: bot_commandes; Type: TABLE; Schema: public; Owner: uptep
--

CREATE TABLE public.bot_commandes (
    commande_id character varying(100) NOT NULL,
    empreinte character(64) NOT NULL,
    discord_acteur bigint NOT NULL,
    action text NOT NULL,
    statut integer NOT NULL,
    reponse jsonb NOT NULL,
    date_creation timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.bot_commandes OWNER TO uptep;

--
-- Name: bot_flood_batches; Type: TABLE; Schema: public; Owner: uptep
--

CREATE TABLE public.bot_flood_batches (
    commande_id text NOT NULL,
    payload_hash text NOT NULL,
    response jsonb NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.bot_flood_batches OWNER TO uptep;

--
-- Name: chaine_members; Type: TABLE; Schema: public; Owner: uptep
--

CREATE TABLE public.chaine_members (
    server character varying(16) NOT NULL,
    player_id bigint NOT NULL,
    grade text,
    sort_order integer,
    version integer DEFAULT 1 NOT NULL,
    updated_by bigint,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    pseudo text,
    iddiscord bigint,
    CONSTRAINT chaine_members_grade_check CHECK ((grade = ANY (ARRAY['Grenier'::text, 'Passeur'::text, 'Chasseur'::text, 'A flooder'::text, 'Ne pas toucher'::text, 'Hors chaîne'::text]))),
    CONSTRAINT chaine_members_sort_order_check CHECK (((sort_order >= 1) AND (sort_order <= 999999)))
);


ALTER TABLE public.chaine_members OWNER TO uptep;

--
-- Name: convois_effectues; Type: TABLE; Schema: public; Owner: uptep
--

CREATE TABLE public.convois_effectues (
    id bigint NOT NULL,
    iddemande bigint NOT NULL,
    idenvoyeur bigint NOT NULL,
    pseudo_envoyeur text NOT NULL,
    discord_envoyeur bigint NOT NULL,
    nbpomme bigint NOT NULL,
    nbmateriaux bigint NOT NULL,
    date_convoi timestamp with time zone NOT NULL,
    date_enregistrement timestamp with time zone DEFAULT now() NOT NULL,
    date_arrivee timestamp with time zone,
    annule boolean DEFAULT false NOT NULL,
    date_annulation timestamp with time zone,
    discord_annulateur bigint,
    pseudo_annulateur text,
    CONSTRAINT convoi_annulation_audit CHECK (((annule AND (date_annulation IS NOT NULL) AND (discord_annulateur IS NOT NULL) AND (pseudo_annulateur IS NOT NULL)) OR ((NOT annule) AND (date_annulation IS NULL) AND (discord_annulateur IS NULL) AND (pseudo_annulateur IS NULL)))),
    CONSTRAINT convois_effectues_check CHECK (((nbpomme > 0) OR (nbmateriaux > 0))),
    CONSTRAINT convois_effectues_idenvoyeur_check CHECK ((idenvoyeur > 0)),
    CONSTRAINT convois_effectues_nbmateriaux_check CHECK ((nbmateriaux >= 0)),
    CONSTRAINT convois_effectues_nbpomme_check CHECK ((nbpomme >= 0))
);


ALTER TABLE public.convois_effectues OWNER TO uptep;

--
-- Name: convois_effectues_id_seq; Type: SEQUENCE; Schema: public; Owner: uptep
--

ALTER TABLE public.convois_effectues ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.convois_effectues_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: demandes_ajustements; Type: TABLE; Schema: public; Owner: uptep
--

CREATE TABLE public.demandes_ajustements (
    id bigint NOT NULL,
    iddemande bigint NOT NULL,
    discord_acteur bigint NOT NULL,
    pseudo_acteur text NOT NULL,
    pommes_avant bigint NOT NULL,
    pommes_apres bigint NOT NULL,
    materiaux_avant bigint NOT NULL,
    materiaux_apres bigint NOT NULL,
    date_ajustement timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT demandes_ajustements_materiaux_apres_check CHECK ((materiaux_apres >= 0)),
    CONSTRAINT demandes_ajustements_materiaux_avant_check CHECK ((materiaux_avant >= 0)),
    CONSTRAINT demandes_ajustements_pommes_apres_check CHECK ((pommes_apres >= 0)),
    CONSTRAINT demandes_ajustements_pommes_avant_check CHECK ((pommes_avant >= 0))
);


ALTER TABLE public.demandes_ajustements OWNER TO uptep;

--
-- Name: demandes_ajustements_id_seq; Type: SEQUENCE; Schema: public; Owner: uptep
--

ALTER TABLE public.demandes_ajustements ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.demandes_ajustements_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: demandes_convois; Type: TABLE; Schema: public; Owner: uptep
--

CREATE TABLE public.demandes_convois (
    id bigint NOT NULL,
    serveur character varying(16) DEFAULT 's5'::character varying NOT NULL,
    iddemandeur bigint NOT NULL,
    pseudo_demandeur text NOT NULL,
    discord_demandeur bigint NOT NULL,
    nbpomme_original bigint NOT NULL,
    nbpomme_restant bigint NOT NULL,
    nbmateriaux_original bigint NOT NULL,
    nbmateriaux_restant bigint NOT NULL,
    valide boolean DEFAULT false NOT NULL,
    idvalidateur bigint,
    pseudo_validateur text,
    discord_validateur bigint,
    date_demande timestamp with time zone DEFAULT now() NOT NULL,
    date_validation timestamp with time zone,
    fini boolean DEFAULT false NOT NULL,
    date_fin timestamp with time zone,
    objet text,
    niveau integer,
    date_souhaitee date,
    annulee boolean DEFAULT false NOT NULL,
    date_annulation timestamp with time zone,
    idannulateur bigint,
    pseudo_annulateur text,
    discord_annulateur bigint,
    fin_manuelle boolean DEFAULT false NOT NULL,
    discord_cloture bigint,
    pseudo_cloture text,
    CONSTRAINT demande_annulation CHECK (((annulee AND (NOT fini) AND (date_annulation IS NOT NULL) AND (idannulateur IS NOT NULL) AND (pseudo_annulateur IS NOT NULL) AND (discord_annulateur IS NOT NULL)) OR ((NOT annulee) AND (date_annulation IS NULL) AND (idannulateur IS NULL) AND (pseudo_annulateur IS NULL) AND (discord_annulateur IS NULL)))),
    CONSTRAINT demande_fin_audit CHECK (((fin_manuelle AND fini AND (discord_cloture IS NOT NULL) AND (pseudo_cloture IS NOT NULL)) OR ((NOT fin_manuelle) AND (discord_cloture IS NULL) AND (pseudo_cloture IS NULL)))),
    CONSTRAINT demande_solde_fin CHECK ((fini = (fin_manuelle OR ((nbpomme_restant = 0) AND (nbmateriaux_restant = 0))))),
    CONSTRAINT demandes_convois_check CHECK (((nbpomme_original > 0) OR (nbmateriaux_original > 0))),
    CONSTRAINT demandes_convois_check1 CHECK (((nbpomme_restant >= 0) AND (nbpomme_restant <= nbpomme_original))),
    CONSTRAINT demandes_convois_check2 CHECK (((nbmateriaux_restant >= 0) AND (nbmateriaux_restant <= nbmateriaux_original))),
    CONSTRAINT demandes_convois_check3 CHECK (((valide AND (idvalidateur IS NOT NULL) AND (pseudo_validateur IS NOT NULL) AND (discord_validateur IS NOT NULL) AND (date_validation IS NOT NULL)) OR ((NOT valide) AND (idvalidateur IS NULL) AND (pseudo_validateur IS NULL) AND (discord_validateur IS NULL) AND (date_validation IS NULL)))),
    CONSTRAINT demandes_convois_check5 CHECK (((fini AND valide AND (date_fin IS NOT NULL)) OR ((NOT fini) AND (date_fin IS NULL)))),
    CONSTRAINT demandes_convois_iddemandeur_check CHECK ((iddemandeur > 0)),
    CONSTRAINT demandes_convois_nbmateriaux_original_check CHECK ((nbmateriaux_original >= 0)),
    CONSTRAINT demandes_convois_nbpomme_original_check CHECK ((nbpomme_original >= 0)),
    CONSTRAINT demandes_convois_niveau_check CHECK (((niveau >= 1) AND (niveau <= 999))),
    CONSTRAINT demandes_convois_objet_check CHECK (((length(objet) >= 1) AND (length(objet) <= 160)))
);


ALTER TABLE public.demandes_convois OWNER TO uptep;

--
-- Name: demandes_convois_id_seq; Type: SEQUENCE; Schema: public; Owner: uptep
--

ALTER TABLE public.demandes_convois ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.demandes_convois_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: discord_login_tokens; Type: TABLE; Schema: public; Owner: uptep
--

CREATE TABLE public.discord_login_tokens (
    token_hash character(64) NOT NULL,
    user_id bigint,
    discord_id character varying(20) NOT NULL,
    session_version integer,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    expires_at timestamp with time zone DEFAULT (now() + '00:02:00'::interval) NOT NULL,
    player_id bigint NOT NULL,
    test_mode boolean DEFAULT false NOT NULL
);


ALTER TABLE public.discord_login_tokens OWNER TO uptep;

--
-- Name: floods; Type: TABLE; Schema: public; Owner: uptep
--

CREATE TABLE public.floods (
    id bigint NOT NULL,
    commande_id text NOT NULL,
    ordinal integer NOT NULL,
    sender_id bigint NOT NULL,
    sender_pseudo text NOT NULL,
    sender_discord bigint NOT NULL,
    target_id bigint,
    target_pseudo text NOT NULL,
    target_discord bigint,
    location text NOT NULL,
    troops text,
    arrival_at timestamp with time zone NOT NULL,
    captured_at timestamp with time zone NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT floods_ordinal_check CHECK ((ordinal >= 0))
);


ALTER TABLE public.floods OWNER TO uptep;

--
-- Name: floods_id_seq; Type: SEQUENCE; Schema: public; Owner: uptep
--

ALTER TABLE public.floods ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.floods_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: login_limits; Type: TABLE; Schema: public; Owner: uptep
--

CREATE TABLE public.login_limits (
    bucket character(64) NOT NULL,
    attempts integer NOT NULL,
    started_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.login_limits OWNER TO uptep;

--
-- Name: script_settings; Type: TABLE; Schema: public; Owner: uptep
--

CREATE TABLE public.script_settings (
    user_id bigint NOT NULL,
    preferences jsonb DEFAULT '{"army": true, "clock": true, "chaine": true, "economy": true, "research": true, "buildings": true, "laboratory": true, "statistics": true}'::jsonb NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    buildings_synced_at timestamp with time zone,
    research_synced_at timestamp with time zone,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT script_settings_version_check CHECK ((version > 0))
);


ALTER TABLE public.script_settings OWNER TO uptep;

--
-- Name: script_tokens; Type: TABLE; Schema: public; Owner: uptep
--

CREATE TABLE public.script_tokens (
    token_hash character(64) NOT NULL,
    user_id bigint NOT NULL,
    player_id bigint NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT script_tokens_player_id_check CHECK ((player_id > 0))
);


ALTER TABLE public.script_tokens OWNER TO uptep;

--
-- Name: terrain_discord_batches; Type: TABLE; Schema: public; Owner: uptep
--

CREATE TABLE public.terrain_discord_batches (
    id text NOT NULL,
    guild_id text NOT NULL,
    channel_id text NOT NULL,
    payload_hash text NOT NULL,
    response jsonb NOT NULL,
    warnings jsonb DEFAULT '[]'::jsonb NOT NULL,
    published_message_id text,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.terrain_discord_batches OWNER TO uptep;

--
-- Name: terrain_discord_channels; Type: TABLE; Schema: public; Owner: uptep
--

CREATE TABLE public.terrain_discord_channels (
    guild_id text NOT NULL,
    channel_id text NOT NULL,
    last_message_id numeric(20,0) DEFAULT 0 NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.terrain_discord_channels OWNER TO uptep;

--
-- Name: terrain_discord_movements; Type: TABLE; Schema: public; Owner: uptep
--

CREATE TABLE public.terrain_discord_movements (
    guild_id text NOT NULL,
    channel_id text NOT NULL,
    event_id text NOT NULL,
    message_id text NOT NULL,
    direction text NOT NULL,
    amount numeric(30,0) NOT NULL,
    enemy text NOT NULL,
    member text,
    occurred_at_text text,
    quote text NOT NULL,
    member_discord_id text,
    CONSTRAINT terrain_discord_movements_amount_check CHECK ((amount > (0)::numeric)),
    CONSTRAINT terrain_discord_movements_direction_check CHECK ((direction = ANY (ARRAY['gain'::text, 'loss'::text])))
);


ALTER TABLE public.terrain_discord_movements OWNER TO uptep;

--
-- Name: terrain_discord_reports; Type: TABLE; Schema: public; Owner: uptep
--

CREATE TABLE public.terrain_discord_reports (
    guild_id text NOT NULL,
    channel_id text NOT NULL,
    message_id text NOT NULL,
    author_id text,
    content text NOT NULL,
    fingerprint text,
    duplicate_of text,
    batch_id text NOT NULL
);


ALTER TABLE public.terrain_discord_reports OWNER TO uptep;

--
-- Name: user_stats; Type: TABLE; Schema: public; Owner: uptep
--

CREATE TABLE public.user_stats (
    user_id bigint NOT NULL,
    data jsonb DEFAULT '{}'::jsonb NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    buildings_synced_at timestamp with time zone,
    research_synced_at timestamp with time zone,
    army_synced_at timestamp with time zone,
    resources_synced_at timestamp with time zone,
    workers_synced_at timestamp with time zone,
    CONSTRAINT user_stats_data_check CHECK ((jsonb_typeof(data) = 'object'::text)),
    CONSTRAINT user_stats_version_check CHECK ((version > 0))
);


ALTER TABLE public.user_stats OWNER TO uptep;

--
-- Name: users; Type: TABLE; Schema: public; Owner: uptep
--

CREATE TABLE public.users (
    id bigint NOT NULL,
    username character varying(80) NOT NULL,
    display_name character varying(80) NOT NULL,
    password_hash text NOT NULL,
    role character varying(12) DEFAULT 'member'::character varying NOT NULL,
    status character varying(12) DEFAULT 'active'::character varying NOT NULL,
    must_change_password boolean DEFAULT true NOT NULL,
    session_version integer DEFAULT 1 NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    approved_at timestamp with time zone,
    approved_by bigint,
    last_login_at timestamp with time zone,
    is_chef boolean DEFAULT false NOT NULL,
    is_chaine boolean DEFAULT false NOT NULL,
    permissions_version integer DEFAULT 1 NOT NULL,
    game_player_id bigint,
    script_enabled boolean DEFAULT true NOT NULL,
    discord_login_id bigint,
    password_setup_required boolean DEFAULT false NOT NULL,
    CONSTRAINT users_game_player_id_check CHECK ((game_player_id > 0)),
    CONSTRAINT users_role_check CHECK (((role)::text = ANY (ARRAY[('admin'::character varying)::text, ('member'::character varying)::text]))),
    CONSTRAINT users_status_check CHECK (((status)::text = ANY (ARRAY[('pending'::character varying)::text, ('active'::character varying)::text, ('disabled'::character varying)::text]))),
    CONSTRAINT users_username_check CHECK ((((username)::text = btrim((username)::text)) AND ((char_length((username)::text) >= 1) AND (char_length((username)::text) <= 80)) AND ((username)::text !~ '[[:cntrl:]]'::text)))
);


ALTER TABLE public.users OWNER TO uptep;

--
-- Name: users_id_seq; Type: SEQUENCE; Schema: public; Owner: uptep
--

ALTER TABLE public.users ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.users_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: bot_commandes bot_commandes_pkey; Type: CONSTRAINT; Schema: public; Owner: uptep
--

ALTER TABLE ONLY public.bot_commandes
    ADD CONSTRAINT bot_commandes_pkey PRIMARY KEY (commande_id);


--
-- Name: bot_flood_batches bot_flood_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: uptep
--

ALTER TABLE ONLY public.bot_flood_batches
    ADD CONSTRAINT bot_flood_batches_pkey PRIMARY KEY (commande_id);


--
-- Name: chaine_members chaine_members_pkey; Type: CONSTRAINT; Schema: public; Owner: uptep
--

ALTER TABLE ONLY public.chaine_members
    ADD CONSTRAINT chaine_members_pkey PRIMARY KEY (server, player_id);


--
-- Name: convois_effectues convois_effectues_pkey; Type: CONSTRAINT; Schema: public; Owner: uptep
--

ALTER TABLE ONLY public.convois_effectues
    ADD CONSTRAINT convois_effectues_pkey PRIMARY KEY (id);


--
-- Name: demandes_ajustements demandes_ajustements_pkey; Type: CONSTRAINT; Schema: public; Owner: uptep
--

ALTER TABLE ONLY public.demandes_ajustements
    ADD CONSTRAINT demandes_ajustements_pkey PRIMARY KEY (id);


--
-- Name: demandes_convois demandes_convois_pkey; Type: CONSTRAINT; Schema: public; Owner: uptep
--

ALTER TABLE ONLY public.demandes_convois
    ADD CONSTRAINT demandes_convois_pkey PRIMARY KEY (id);


--
-- Name: discord_login_tokens discord_login_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: uptep
--

ALTER TABLE ONLY public.discord_login_tokens
    ADD CONSTRAINT discord_login_tokens_pkey PRIMARY KEY (token_hash);


--
-- Name: floods floods_commande_id_ordinal_key; Type: CONSTRAINT; Schema: public; Owner: uptep
--

ALTER TABLE ONLY public.floods
    ADD CONSTRAINT floods_commande_id_ordinal_key UNIQUE (commande_id, ordinal);


--
-- Name: floods floods_pkey; Type: CONSTRAINT; Schema: public; Owner: uptep
--

ALTER TABLE ONLY public.floods
    ADD CONSTRAINT floods_pkey PRIMARY KEY (id);


--
-- Name: login_limits login_limits_pkey; Type: CONSTRAINT; Schema: public; Owner: uptep
--

ALTER TABLE ONLY public.login_limits
    ADD CONSTRAINT login_limits_pkey PRIMARY KEY (bucket);


--
-- Name: script_settings script_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: uptep
--

ALTER TABLE ONLY public.script_settings
    ADD CONSTRAINT script_settings_pkey PRIMARY KEY (user_id);


--
-- Name: script_tokens script_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: uptep
--

ALTER TABLE ONLY public.script_tokens
    ADD CONSTRAINT script_tokens_pkey PRIMARY KEY (token_hash);


--
-- Name: terrain_discord_batches terrain_discord_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: uptep
--

ALTER TABLE ONLY public.terrain_discord_batches
    ADD CONSTRAINT terrain_discord_batches_pkey PRIMARY KEY (id);


--
-- Name: terrain_discord_channels terrain_discord_channels_pkey; Type: CONSTRAINT; Schema: public; Owner: uptep
--

ALTER TABLE ONLY public.terrain_discord_channels
    ADD CONSTRAINT terrain_discord_channels_pkey PRIMARY KEY (guild_id, channel_id);


--
-- Name: terrain_discord_movements terrain_discord_movements_pkey; Type: CONSTRAINT; Schema: public; Owner: uptep
--

ALTER TABLE ONLY public.terrain_discord_movements
    ADD CONSTRAINT terrain_discord_movements_pkey PRIMARY KEY (guild_id, channel_id, event_id);


--
-- Name: terrain_discord_reports terrain_discord_reports_pkey; Type: CONSTRAINT; Schema: public; Owner: uptep
--

ALTER TABLE ONLY public.terrain_discord_reports
    ADD CONSTRAINT terrain_discord_reports_pkey PRIMARY KEY (guild_id, channel_id, message_id);


--
-- Name: user_stats user_stats_pkey; Type: CONSTRAINT; Schema: public; Owner: uptep
--

ALTER TABLE ONLY public.user_stats
    ADD CONSTRAINT user_stats_pkey PRIMARY KEY (user_id);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: uptep
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: users users_username_key; Type: CONSTRAINT; Schema: public; Owner: uptep
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_username_key UNIQUE (username);


--
-- Name: convois_demand_arrival; Type: INDEX; Schema: public; Owner: uptep
--

CREATE INDEX convois_demand_arrival ON public.convois_effectues USING btree (iddemande, date_arrivee, id) WHERE ((NOT annule) AND (date_arrivee IS NOT NULL));


--
-- Name: convois_effectues_demande; Type: INDEX; Schema: public; Owner: uptep
--

CREATE INDEX convois_effectues_demande ON public.convois_effectues USING btree (iddemande, id);


--
-- Name: convois_sender_arrival; Type: INDEX; Schema: public; Owner: uptep
--

CREATE INDEX convois_sender_arrival ON public.convois_effectues USING btree (idenvoyeur, date_arrivee, id) WHERE ((NOT annule) AND (date_arrivee IS NOT NULL));


--
-- Name: demandes_ajustements_demande; Type: INDEX; Schema: public; Owner: uptep
--

CREATE INDEX demandes_ajustements_demande ON public.demandes_ajustements USING btree (iddemande, id);


--
-- Name: demandes_convois_etat; Type: INDEX; Schema: public; Owner: uptep
--

CREATE INDEX demandes_convois_etat ON public.demandes_convois USING btree (valide, id) WHERE (NOT fini);


--
-- Name: demandes_requester; Type: INDEX; Schema: public; Owner: uptep
--

CREATE INDEX demandes_requester ON public.demandes_convois USING btree (serveur, iddemandeur, id);


--
-- Name: discord_login_tokens_expiry; Type: INDEX; Schema: public; Owner: uptep
--

CREATE INDEX discord_login_tokens_expiry ON public.discord_login_tokens USING btree (expires_at);


--
-- Name: floods_arrival; Type: INDEX; Schema: public; Owner: uptep
--

CREATE INDEX floods_arrival ON public.floods USING btree (arrival_at, id);


--
-- Name: floods_sender_arrival; Type: INDEX; Schema: public; Owner: uptep
--

CREATE INDEX floods_sender_arrival ON public.floods USING btree (sender_id, arrival_at, id);


--
-- Name: floods_target_arrival; Type: INDEX; Schema: public; Owner: uptep
--

CREATE INDEX floods_target_arrival ON public.floods USING btree (target_id, arrival_at, id);


--
-- Name: login_limits_expiry; Type: INDEX; Schema: public; Owner: uptep
--

CREATE INDEX login_limits_expiry ON public.login_limits USING btree (started_at);


--
-- Name: script_tokens_user; Type: INDEX; Schema: public; Owner: uptep
--

CREATE INDEX script_tokens_user ON public.script_tokens USING btree (user_id);


--
-- Name: terrain_discord_report_fingerprint; Type: INDEX; Schema: public; Owner: uptep
--

CREATE UNIQUE INDEX terrain_discord_report_fingerprint ON public.terrain_discord_reports USING btree (guild_id, channel_id, fingerprint) WHERE ((fingerprint IS NOT NULL) AND (duplicate_of IS NULL));


--
-- Name: users_discord_login_id_unique; Type: INDEX; Schema: public; Owner: uptep
--

CREATE UNIQUE INDEX users_discord_login_id_unique ON public.users USING btree (discord_login_id) WHERE (discord_login_id IS NOT NULL);


--
-- Name: users_username_lower_unique; Type: INDEX; Schema: public; Owner: uptep
--

CREATE UNIQUE INDEX users_username_lower_unique ON public.users USING btree (lower((username)::text));


--
-- Name: chaine_members chaine_members_updated_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: uptep
--

ALTER TABLE ONLY public.chaine_members
    ADD CONSTRAINT chaine_members_updated_by_fkey FOREIGN KEY (updated_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: convois_effectues convois_effectues_iddemande_fkey; Type: FK CONSTRAINT; Schema: public; Owner: uptep
--

ALTER TABLE ONLY public.convois_effectues
    ADD CONSTRAINT convois_effectues_iddemande_fkey FOREIGN KEY (iddemande) REFERENCES public.demandes_convois(id) ON DELETE RESTRICT;


--
-- Name: demandes_ajustements demandes_ajustements_iddemande_fkey; Type: FK CONSTRAINT; Schema: public; Owner: uptep
--

ALTER TABLE ONLY public.demandes_ajustements
    ADD CONSTRAINT demandes_ajustements_iddemande_fkey FOREIGN KEY (iddemande) REFERENCES public.demandes_convois(id) ON DELETE RESTRICT;


--
-- Name: discord_login_tokens discord_login_tokens_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: uptep
--

ALTER TABLE ONLY public.discord_login_tokens
    ADD CONSTRAINT discord_login_tokens_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: floods floods_commande_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: uptep
--

ALTER TABLE ONLY public.floods
    ADD CONSTRAINT floods_commande_id_fkey FOREIGN KEY (commande_id) REFERENCES public.bot_flood_batches(commande_id);


--
-- Name: script_settings script_settings_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: uptep
--

ALTER TABLE ONLY public.script_settings
    ADD CONSTRAINT script_settings_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: script_tokens script_tokens_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: uptep
--

ALTER TABLE ONLY public.script_tokens
    ADD CONSTRAINT script_tokens_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: terrain_discord_batches terrain_discord_batches_guild_id_channel_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: uptep
--

ALTER TABLE ONLY public.terrain_discord_batches
    ADD CONSTRAINT terrain_discord_batches_guild_id_channel_id_fkey FOREIGN KEY (guild_id, channel_id) REFERENCES public.terrain_discord_channels(guild_id, channel_id);


--
-- Name: terrain_discord_movements terrain_discord_movements_guild_id_channel_id_message_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: uptep
--

ALTER TABLE ONLY public.terrain_discord_movements
    ADD CONSTRAINT terrain_discord_movements_guild_id_channel_id_message_id_fkey FOREIGN KEY (guild_id, channel_id, message_id) REFERENCES public.terrain_discord_reports(guild_id, channel_id, message_id);


--
-- Name: terrain_discord_reports terrain_discord_reports_batch_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: uptep
--

ALTER TABLE ONLY public.terrain_discord_reports
    ADD CONSTRAINT terrain_discord_reports_batch_id_fkey FOREIGN KEY (batch_id) REFERENCES public.terrain_discord_batches(id);


--
-- Name: user_stats user_stats_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: uptep
--

ALTER TABLE ONLY public.user_stats
    ADD CONSTRAINT user_stats_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: users users_approved_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: uptep
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_approved_by_fkey FOREIGN KEY (approved_by) REFERENCES public.users(id);


--
-- PostgreSQL database dump complete
--

\unrestrict XOsOhMRKTEGlB59fqqvtmFGjPpIpLIbQkplvhgBpP5mxJLaYJu2ygZ5j8Z0Neon
