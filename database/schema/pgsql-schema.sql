--
-- PostgreSQL database dump
--

\restrict 5bWWgawRlbvxxsw9hOeRbUfjqphaVnkkgXdDT4mMPSzKyZL71Wo9shsgr3EIdYY

-- Dumped from database version 16.13 (Ubuntu 16.13-0ubuntu0.24.04.1)
-- Dumped by pg_dump version 16.13 (Ubuntu 16.13-0ubuntu0.24.04.1)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: fuzzystrmatch; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS fuzzystrmatch WITH SCHEMA public;


--
-- Name: EXTENSION fuzzystrmatch; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION fuzzystrmatch IS 'determine similarities and distance between strings';


--
-- Name: vector; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS vector WITH SCHEMA public;


--
-- Name: EXTENSION vector; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION vector IS 'vector data type and ivfflat and hnsw access methods';


--
-- Name: calculate_factuality_score(integer, integer); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.calculate_factuality_score(supporting integer, contradicting integer) RETURNS numeric
    LANGUAGE plpgsql IMMUTABLE
    AS $$
            BEGIN
                IF supporting + contradicting = 0 THEN
                    RETURN NULL;
                END IF;
                RETURN ROUND(supporting::DECIMAL / (supporting + contradicting), 3);
            END;
            $$;


--
-- Name: cleanup_expired_research_cache(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.cleanup_expired_research_cache() RETURNS integer
    LANGUAGE plpgsql
    AS $$
            DECLARE
                deleted_count INTEGER;
            BEGIN
                DELETE FROM research_cache
                WHERE expires_at IS NOT NULL AND expires_at < NOW();
                GET DIAGNOSTICS deleted_count = ROW_COUNT;
                RETURN deleted_count;
            END;
            $$;


--
-- Name: cleanup_old_documents(character varying, integer); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.cleanup_old_documents(doc_type character varying, days_old integer DEFAULT 365) RETURNS integer
    LANGUAGE plpgsql
    AS $$
            DECLARE
                deleted_count int;
            BEGIN
                DELETE FROM rag_documents
                WHERE document_type = doc_type
                AND created_at < NOW() - INTERVAL '1 day' * days_old;

                GET DIAGNOSTICS deleted_count = ROW_COUNT;
                RETURN deleted_count;
            END;
            $$;


--
-- Name: find_similar_documents(bigint, integer, double precision); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.find_similar_documents(source_doc_id bigint, max_results integer DEFAULT 5, similarity_threshold double precision DEFAULT 0.75) RETURNS TABLE(id bigint, title character varying, document_type character varying, similarity double precision, created_at timestamp without time zone)
    LANGUAGE plpgsql STABLE PARALLEL SAFE
    AS $$
            BEGIN
                RETURN QUERY
                SELECT
                    rd.id,
                    rd.title,
                    rd.document_type,
                    1 - (rd.embedding <=> source.embedding) as similarity,
                    rd.created_at
                FROM rag_documents rd
                CROSS JOIN (SELECT embedding FROM rag_documents WHERE id = source_doc_id) source
                WHERE
                    rd.id != source_doc_id
                    AND (1 - (rd.embedding <=> source.embedding)) >= similarity_threshold
                ORDER BY rd.embedding <=> source.embedding
                LIMIT max_results;
            END;
            $$;


--
-- Name: hybrid_search_documents(text, public.vector, integer, double precision, double precision); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.hybrid_search_documents(search_text text, query_embedding public.vector, max_results integer DEFAULT 10, fts_weight double precision DEFAULT 0.3, vector_weight double precision DEFAULT 0.7) RETURNS TABLE(id bigint, title character varying, content text, document_type character varying, combined_score double precision, metadata jsonb)
    LANGUAGE plpgsql STABLE PARALLEL SAFE
    AS $$
            BEGIN
                RETURN QUERY
                SELECT
                    rd.id,
                    rd.title,
                    rd.content,
                    rd.document_type,
                    (
                        (ts_rank(to_tsvector('english', rd.content), plainto_tsquery('english', search_text)) * fts_weight) +
                        ((1 - (rd.embedding <=> query_embedding)) * vector_weight)
                    ) as combined_score,
                    rd.metadata
                FROM rag_documents rd
                WHERE
                    to_tsvector('english', rd.content) @@ plainto_tsquery('english', search_text)
                    OR (1 - (rd.embedding <=> query_embedding)) >= 0.6
                ORDER BY combined_score DESC
                LIMIT max_results;
            END;
            $$;


--
-- Name: hybrid_search_genealogy_persons(text, public.vector, integer, integer, double precision, double precision); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.hybrid_search_genealogy_persons(search_text text, query_embedding public.vector, target_tree_id integer DEFAULT NULL::integer, max_results integer DEFAULT 20, fts_weight double precision DEFAULT 0.3, vector_weight double precision DEFAULT 0.7) RETURNS TABLE(person_id integer, tree_id integer, full_name character varying, birth_year character varying, death_year character varying, combined_score double precision)
    LANGUAGE plpgsql
    AS $$
            BEGIN
                RETURN QUERY
                SELECT
                    gpe.person_id,
                    gpe.tree_id,
                    gpe.full_name,
                    gpe.birth_year,
                    gpe.death_year,
                    (
                        fts_weight * COALESCE(ts_rank(
                            to_tsvector('english', COALESCE(gpe.search_text, '')),
                            plainto_tsquery('english', search_text)
                        ), 0)
                        + vector_weight * (1 - (gpe.embedding <=> query_embedding))
                    )::FLOAT AS combined_score
                FROM genealogy_person_embeddings gpe
                WHERE
                    (target_tree_id IS NULL OR gpe.tree_id = target_tree_id)
                ORDER BY combined_score DESC
                LIMIT max_results;
            END;
            $$;


--
-- Name: refresh_document_statistics(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.refresh_document_statistics() RETURNS void
    LANGUAGE plpgsql
    AS $$
            BEGIN
                REFRESH MATERIALIZED VIEW CONCURRENTLY document_statistics;
            END;
            $$;


--
-- Name: search_documents_optimized(public.vector, character varying, integer, double precision); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.search_documents_optimized(query_embedding public.vector, doc_type character varying DEFAULT NULL::character varying, max_results integer DEFAULT 10, similarity_threshold double precision DEFAULT 0.7) RETURNS TABLE(id bigint, title character varying, content text, document_type character varying, similarity double precision, metadata jsonb, created_at timestamp without time zone)
    LANGUAGE plpgsql STABLE PARALLEL SAFE
    AS $$
            BEGIN
                RETURN QUERY
                SELECT
                    rd.id,
                    rd.title,
                    rd.content,
                    rd.document_type,
                    1 - (rd.embedding <=> query_embedding) as similarity,
                    rd.metadata,
                    rd.created_at
                FROM rag_documents rd
                WHERE
                    (doc_type IS NULL OR rd.document_type = doc_type)
                    AND (1 - (rd.embedding <=> query_embedding)) >= similarity_threshold
                ORDER BY rd.embedding <=> query_embedding
                LIMIT max_results;
            END;
            $$;


--
-- Name: search_genealogy_persons(public.vector, integer, integer, double precision); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.search_genealogy_persons(query_embedding public.vector, target_tree_id integer DEFAULT NULL::integer, max_results integer DEFAULT 20, similarity_threshold double precision DEFAULT 0.6) RETURNS TABLE(person_id integer, tree_id integer, full_name character varying, birth_year character varying, death_year character varying, birth_place character varying, death_place character varying, biography text, similarity double precision)
    LANGUAGE plpgsql
    AS $$
            BEGIN
                RETURN QUERY
                SELECT
                    gpe.person_id,
                    gpe.tree_id,
                    gpe.full_name,
                    gpe.birth_year,
                    gpe.death_year,
                    gpe.birth_place,
                    gpe.death_place,
                    gpe.biography,
                    (1 - (gpe.embedding <=> query_embedding))::FLOAT AS similarity
                FROM genealogy_person_embeddings gpe
                WHERE
                    (target_tree_id IS NULL OR gpe.tree_id = target_tree_id)
                    AND (1 - (gpe.embedding <=> query_embedding)) >= similarity_threshold
                ORDER BY gpe.embedding <=> query_embedding
                LIMIT max_results;
            END;
            $$;


--
-- Name: update_updated_at_column(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.update_updated_at_column() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
            BEGIN
                NEW.updated_at = NOW();
                RETURN NEW;
            END;
            $$;


--
-- Name: update_verdict_factuality(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.update_verdict_factuality() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
            BEGIN
                NEW.factuality_score := calculate_factuality_score(NEW.supporting_count, NEW.contradicting_count);
                NEW.updated_at := CURRENT_TIMESTAMP;
                RETURN NEW;
            END;
            $$;


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: agent_episode_embeddings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.agent_episode_embeddings (
    id integer NOT NULL,
    summary_id bigint NOT NULL,
    agent_id character varying(100) NOT NULL,
    embedding public.vector(768) NOT NULL,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now()
);


--
-- Name: agent_episode_embeddings_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.agent_episode_embeddings_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: agent_episode_embeddings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.agent_episode_embeddings_id_seq OWNED BY public.agent_episode_embeddings.id;


--
-- Name: agent_procedure_embeddings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.agent_procedure_embeddings (
    id integer NOT NULL,
    procedure_id bigint NOT NULL,
    agent_id character varying(100) NOT NULL,
    embedding public.vector(768) NOT NULL,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now()
);


--
-- Name: agent_procedure_embeddings_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.agent_procedure_embeddings_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: agent_procedure_embeddings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.agent_procedure_embeddings_id_seq OWNED BY public.agent_procedure_embeddings.id;


--
-- Name: ai_semantic_cache; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ai_semantic_cache (
    id bigint NOT NULL,
    prompt_hash character varying(64) NOT NULL,
    context_hash character varying(32) NOT NULL,
    embedding public.vector(768) NOT NULL,
    response jsonb NOT NULL,
    prompt_preview text,
    hit_count integer DEFAULT 0,
    last_accessed_at timestamp without time zone DEFAULT now(),
    created_at timestamp without time zone DEFAULT now()
);


--
-- Name: ai_semantic_cache_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.ai_semantic_cache_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: ai_semantic_cache_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.ai_semantic_cache_id_seq OWNED BY public.ai_semantic_cache.id;


--
-- Name: cache; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache (
    key character varying(255) NOT NULL,
    value text NOT NULL,
    expiration integer NOT NULL
);


--
-- Name: cache_locks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache_locks (
    key character varying(255) NOT NULL,
    owner character varying(255) NOT NULL,
    expiration integer NOT NULL
);


--
-- Name: claims; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.claims (
    id bigint NOT NULL,
    source_text text NOT NULL,
    normalized_claim text NOT NULL,
    checkworthiness_score numeric(4,3) DEFAULT 0.0,
    entities jsonb DEFAULT '[]'::jsonb,
    source_document_id bigint,
    decomposition_context jsonb DEFAULT '{}'::jsonb,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_checkworthiness CHECK (((checkworthiness_score >= (0)::numeric) AND (checkworthiness_score <= (1)::numeric)))
);


--
-- Name: TABLE claims; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.claims IS 'Atomic claims extracted from source text via ClaimDecompositionService';


--
-- Name: COLUMN claims.checkworthiness_score; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.claims.checkworthiness_score IS 'Score 0-1 indicating if claim is worth verifying (threshold: 0.5)';


--
-- Name: COLUMN claims.entities; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.claims.entities IS 'Extracted named entities: [{"text": "...", "type": "PERSON|ORG|DATE|LOC"}]';


--
-- Name: COLUMN claims.decomposition_context; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.claims.decomposition_context IS 'Pipeline metadata: stage timings, original sentence, disambiguation steps';


--
-- Name: claims_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.claims_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: claims_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.claims_id_seq OWNED BY public.claims.id;


--
-- Name: cluster_merge_history; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cluster_merge_history (
    id integer NOT NULL,
    source_cluster_id integer NOT NULL,
    target_cluster_id integer NOT NULL,
    faces_moved integer DEFAULT 0,
    merged_by character varying(100),
    merged_at timestamp without time zone DEFAULT now()
);


--
-- Name: cluster_merge_history_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.cluster_merge_history_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: cluster_merge_history_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.cluster_merge_history_id_seq OWNED BY public.cluster_merge_history.id;


--
-- Name: consensus_verdicts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.consensus_verdicts (
    id bigint NOT NULL,
    claim_id bigint NOT NULL,
    provider_count integer DEFAULT 0 NOT NULL,
    agreement_ratio numeric(4,3),
    consensus_verdict character varying(20),
    consensus_confidence numeric(5,3),
    devil_advocate_verdict character varying(20),
    devil_advocate_confidence numeric(5,3),
    provider_details jsonb DEFAULT '[]'::jsonb NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: consensus_verdicts_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.consensus_verdicts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: consensus_verdicts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.consensus_verdicts_id_seq OWNED BY public.consensus_verdicts.id;


--
-- Name: contradictions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.contradictions (
    id bigint NOT NULL,
    claim_id bigint,
    evidence_id bigint,
    text1 text NOT NULL,
    text2 text NOT NULL,
    contradiction_types jsonb DEFAULT '[]'::jsonb,
    severity numeric(4,3) DEFAULT 0.0 NOT NULL,
    severity_label character varying(20) DEFAULT 'none'::character varying NOT NULL,
    detection_details jsonb DEFAULT '[]'::jsonb,
    human_reviewed boolean DEFAULT false,
    is_valid boolean,
    reviewed_by character varying(100),
    review_notes text,
    reviewed_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_severity CHECK (((severity >= (0)::numeric) AND (severity <= (1)::numeric))),
    CONSTRAINT chk_severity_label CHECK (((severity_label)::text = ANY ((ARRAY['none'::character varying, 'minor'::character varying, 'moderate'::character varying, 'major'::character varying])::text[])))
);


--
-- Name: TABLE contradictions; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.contradictions IS 'Detected contradictions between claims and evidence via ContradictionDetectorService';


--
-- Name: COLUMN contradictions.contradiction_types; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.contradictions.contradiction_types IS 'Array of types: ["negation", "antonym", "numeric", "temporal", "semantic"]';


--
-- Name: COLUMN contradictions.severity; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.contradictions.severity IS 'Weighted severity score 0-1 based on contradiction types and confidence';


--
-- Name: COLUMN contradictions.severity_label; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.contradictions.severity_label IS 'Human-readable severity: none, minor, moderate, major';


--
-- Name: COLUMN contradictions.detection_details; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.contradictions.detection_details IS 'Full detection results including evidence for each type';


--
-- Name: COLUMN contradictions.is_valid; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.contradictions.is_valid IS 'Human determination: TRUE if contradiction is real, FALSE if false positive';


--
-- Name: contradictions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.contradictions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: contradictions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.contradictions_id_seq OWNED BY public.contradictions.id;


--
-- Name: discovered_sources; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.discovered_sources (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    domain character varying(255) NOT NULL,
    full_url text,
    display_name character varying(255),
    source_type character varying(50) DEFAULT 'webpage'::character varying,
    domain_category character varying(100) DEFAULT 'unknown'::character varying,
    content_types jsonb DEFAULT '[]'::jsonb,
    specializations jsonb DEFAULT '[]'::jsonb,
    safety_score numeric(4,3) DEFAULT 0.500,
    trust_score numeric(4,3) DEFAULT 0.500,
    safety_evaluation jsonb,
    is_whitelisted boolean DEFAULT false,
    is_blacklisted boolean DEFAULT false,
    blacklist_reason text,
    requires_sandbox boolean DEFAULT true,
    access_method character varying(30) DEFAULT 'scrape'::character varying,
    api_endpoint text,
    api_auth_type character varying(30),
    api_key_env_var character varying(100),
    rate_limit_rpm integer DEFAULT 30,
    scrape_selectors jsonb,
    robots_txt_checked boolean DEFAULT false,
    robots_txt_allows boolean DEFAULT true,
    success_count integer DEFAULT 0,
    failure_count integer DEFAULT 0,
    consecutive_failures integer DEFAULT 0,
    last_success_at timestamp without time zone,
    last_failure_at timestamp without time zone,
    last_error_message text,
    avg_response_ms integer,
    is_active boolean DEFAULT true,
    discovered_by character varying(50) DEFAULT 'ai'::character varying,
    discovered_from_mission uuid,
    discovery_context text,
    discovery_query text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: discovery_rules; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.discovery_rules (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    rule_name character varying(255) NOT NULL,
    rule_type character varying(50) NOT NULL,
    match_pattern text NOT NULL,
    pattern_type character varying(20) DEFAULT 'exact'::character varying,
    trust_score_value numeric(5,3),
    trust_score_multiplier numeric(5,3) DEFAULT 1.0,
    safety_score_adjustment numeric(5,3) DEFAULT 0.0,
    domain_category character varying(100),
    suggested_specializations jsonb DEFAULT '[]'::jsonb,
    suggested_content_types jsonb DEFAULT '[]'::jsonb,
    priority integer DEFAULT 100,
    applies_to_new_sources boolean DEFAULT true,
    applies_to_existing_sources boolean DEFAULT false,
    applies_to_ai_evaluation boolean DEFAULT true,
    auto_whitelist boolean DEFAULT false,
    auto_blacklist boolean DEFAULT false,
    requires_verification boolean DEFAULT true,
    times_applied integer DEFAULT 0,
    last_applied_at timestamp without time zone,
    sources_matched integer DEFAULT 0,
    sources_succeeded integer DEFAULT 0,
    success_rate_pct numeric(5,2),
    notes text,
    created_by character varying(100) DEFAULT 'system'::character varying,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    is_active boolean DEFAULT true,
    CONSTRAINT valid_pattern_type CHECK (((pattern_type)::text = ANY ((ARRAY['exact'::character varying, 'regex'::character varying, 'suffix'::character varying, 'prefix'::character varying, 'contains'::character varying])::text[]))),
    CONSTRAINT valid_rule_type CHECK (((rule_type)::text = ANY ((ARRAY['tld_trust'::character varying, 'whitelist_pattern'::character varying, 'blacklist_pattern'::character varying, 'category_domain'::character varying, 'category_pattern'::character varying, 'safety_modifier'::character varying])::text[]))),
    CONSTRAINT valid_trust_value CHECK (((trust_score_value IS NULL) OR ((trust_score_value >= 0.0) AND (trust_score_value <= 1.0))))
);


--
-- Name: rag_documents; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.rag_documents (
    id bigint NOT NULL,
    document_type character varying(50) NOT NULL,
    title character varying(500),
    content text NOT NULL,
    metadata jsonb,
    source_id character varying(255),
    source_type character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    embedding public.vector(768) NOT NULL,
    designation character varying(100),
    parent_id bigint,
    content_hash character varying(64),
    last_synced_at timestamp without time zone,
    media_url character varying(500),
    context_prefix text,
    contextualized_at timestamp without time zone,
    image_embedding public.vector(768),
    image_description text,
    has_visual_content boolean DEFAULT false,
    visual_analyzed_at timestamp without time zone,
    sentence_positions jsonb,
    embedding_mode character varying(20) DEFAULT 'chunk'::character varying,
    raptor_indexed_at timestamp without time zone,
    kg_extracted_at timestamp without time zone,
    sentence_indexed_at timestamp without time zone,
    near_duplicate_of bigint,
    similarity_score numeric(5,4),
    compressed_content text,
    compression_ratio numeric(5,3),
    dedup_status character varying(20) DEFAULT 'unique'::character varying,
    dedup_checked_at timestamp without time zone,
    dedup_matched_id bigint,
    dedup_similarity numeric(6,5),
    raptor_error_count integer DEFAULT 0 NOT NULL,
    raptor_eligible smallint,
    se_eligible smallint,
    sparse_embedding public.sparsevec(30522),
    splade_indexed_at timestamp without time zone,
    hype_eligible smallint,
    hype_indexed_at timestamp without time zone,
    hype_error_count integer DEFAULT 0,
    kg_content_hash character varying(64)
)
WITH (autovacuum_vacuum_scale_factor='0.05', autovacuum_analyze_scale_factor='0.02');


--
-- Name: COLUMN rag_documents.media_url; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.rag_documents.media_url IS 'Nextcloud WebDAV URL for source media';


--
-- Name: document_statistics; Type: MATERIALIZED VIEW; Schema: public; Owner: -
--

CREATE MATERIALIZED VIEW public.document_statistics AS
 SELECT document_type,
    count(*) AS document_count,
    avg(length(content)) AS avg_content_length,
    max(created_at) AS latest_document,
    min(created_at) AS oldest_document,
    count(DISTINCT designation) AS unique_designations
   FROM public.rag_documents
  GROUP BY document_type
  WITH NO DATA;


--
-- Name: entity_resolution_runs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.entity_resolution_runs (
    id bigint NOT NULL,
    phase character varying(50) NOT NULL,
    entities_processed integer DEFAULT 0,
    candidates_found integer DEFAULT 0,
    auto_merged integer DEFAULT 0,
    llm_compared integer DEFAULT 0,
    llm_merged integer DEFAULT 0,
    submitted_for_review integer DEFAULT 0,
    errors integer DEFAULT 0,
    duration_ms integer DEFAULT 0,
    metadata jsonb DEFAULT '{}'::jsonb,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: entity_resolution_runs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.entity_resolution_runs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: entity_resolution_runs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.entity_resolution_runs_id_seq OWNED BY public.entity_resolution_runs.id;


--
-- Name: evidence; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.evidence (
    id bigint NOT NULL,
    claim_id bigint NOT NULL,
    snippet text NOT NULL,
    source_url text NOT NULL,
    source_title text,
    source_domain text,
    nli_label character varying(20) DEFAULT 'neutral'::character varying NOT NULL,
    nli_score numeric(4,3) DEFAULT 0.0,
    credibility_score numeric(4,3) DEFAULT 0.5,
    retrieval_query text,
    retrieval_rank integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    retrieval_intent character varying(20) DEFAULT 'general'::character varying,
    CONSTRAINT chk_credibility CHECK (((credibility_score >= (0)::numeric) AND (credibility_score <= (1)::numeric))),
    CONSTRAINT chk_nli_label CHECK (((nli_label)::text = ANY ((ARRAY['supported'::character varying, 'contradicted'::character varying, 'neutral'::character varying])::text[]))),
    CONSTRAINT chk_nli_score CHECK (((nli_score >= (0)::numeric) AND (nli_score <= (1)::numeric)))
);


--
-- Name: TABLE evidence; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.evidence IS 'Evidence snippets retrieved for claim verification via SearXNG';


--
-- Name: COLUMN evidence.nli_label; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.evidence.nli_label IS 'Natural Language Inference label: supported, contradicted, or neutral';


--
-- Name: COLUMN evidence.nli_score; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.evidence.nli_score IS 'Confidence score for NLI classification (0-1)';


--
-- Name: COLUMN evidence.credibility_score; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.evidence.credibility_score IS 'Source credibility score based on domain reputation (0-1)';


--
-- Name: evidence_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.evidence_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: evidence_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.evidence_id_seq OWNED BY public.evidence.id;


--
-- Name: face_embeddings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.face_embeddings (
    id integer NOT NULL,
    file_registry_id integer NOT NULL,
    person_cluster_id integer,
    embedding public.vector(128) NOT NULL,
    region_x real NOT NULL,
    region_y real NOT NULL,
    region_w real NOT NULL,
    region_h real NOT NULL,
    crop_path character varying(500),
    matched_face_id integer,
    match_confidence real,
    embedding_model character varying(100) DEFAULT 'dlib_face_recognition_resnet_model_v1'::character varying,
    quality_score real,
    is_representative boolean DEFAULT false,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now(),
    file_registry_face_id bigint
);


--
-- Name: TABLE face_embeddings; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.face_embeddings IS '128-dim face embeddings from dlib/face_recognition for similarity search';


--
-- Name: face_embeddings_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.face_embeddings_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: face_embeddings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.face_embeddings_id_seq OWNED BY public.face_embeddings.id;


--
-- Name: face_match_candidates; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.face_match_candidates (
    id integer NOT NULL,
    face_embedding_id integer,
    candidate_cluster_id integer,
    candidate_face_id integer,
    confidence real NOT NULL,
    status character varying(50) DEFAULT 'pending'::character varying,
    reviewed_by character varying(100),
    reviewed_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT now()
);


--
-- Name: face_match_candidates_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.face_match_candidates_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: face_match_candidates_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.face_match_candidates_id_seq OWNED BY public.face_match_candidates.id;


--
-- Name: fact_check_benchmark_claims; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.fact_check_benchmark_claims (
    id bigint NOT NULL,
    run_id character varying(64) NOT NULL,
    claim_index integer NOT NULL,
    claim_text text NOT NULL,
    gold_label character varying(30) NOT NULL,
    predicted_label character varying(30),
    confidence numeric(5,4),
    evidence_count integer,
    duration_ms integer,
    correct boolean,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: fact_check_benchmark_claims_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.fact_check_benchmark_claims_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: fact_check_benchmark_claims_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.fact_check_benchmark_claims_id_seq OWNED BY public.fact_check_benchmark_claims.id;


--
-- Name: fact_check_benchmark_runs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.fact_check_benchmark_runs (
    id bigint NOT NULL,
    run_id character varying(64) NOT NULL,
    dataset character varying(50) DEFAULT 'averitec'::character varying NOT NULL,
    split character varying(20) DEFAULT 'dev'::character varying NOT NULL,
    claims_evaluated integer DEFAULT 0 NOT NULL,
    accuracy numeric(5,4),
    macro_f1 numeric(5,4),
    weighted_f1 numeric(5,4),
    confusion_matrix jsonb,
    per_class_metrics jsonb,
    config jsonb,
    avg_confidence_correct numeric(5,4),
    avg_confidence_incorrect numeric(5,4),
    avg_duration_ms integer,
    total_duration_ms integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: fact_check_benchmark_runs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.fact_check_benchmark_runs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: fact_check_benchmark_runs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.fact_check_benchmark_runs_id_seq OWNED BY public.fact_check_benchmark_runs.id;


--
-- Name: fact_check_pipeline_runs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.fact_check_pipeline_runs (
    id integer NOT NULL,
    pipeline_id character varying(64) NOT NULL,
    source_url text,
    source_title character varying(500),
    status character varying(50) DEFAULT 'running'::character varying NOT NULL,
    claim_count integer DEFAULT 0,
    supported_count integer DEFAULT 0,
    refuted_count integer DEFAULT 0,
    inconclusive_count integer DEFAULT 0,
    overall_factuality_score numeric(5,4),
    duration_ms integer,
    error_message text,
    metadata jsonb,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    completed_at timestamp without time zone
);


--
-- Name: fact_check_pipeline_runs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.fact_check_pipeline_runs_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: fact_check_pipeline_runs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.fact_check_pipeline_runs_id_seq OWNED BY public.fact_check_pipeline_runs.id;


--
-- Name: failed_jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.failed_jobs (
    id bigint NOT NULL,
    uuid character varying(255) NOT NULL,
    connection text NOT NULL,
    queue text NOT NULL,
    payload text NOT NULL,
    exception text NOT NULL,
    failed_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.failed_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.failed_jobs_id_seq OWNED BY public.failed_jobs.id;


--
-- Name: file_semantic_embeddings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.file_semantic_embeddings (
    id integer NOT NULL,
    file_id bigint NOT NULL,
    chunk_index integer DEFAULT 0 NOT NULL,
    chunk_text text NOT NULL,
    embedding public.vector(768),
    metadata jsonb,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: file_semantic_embeddings_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.file_semantic_embeddings_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: file_semantic_embeddings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.file_semantic_embeddings_id_seq OWNED BY public.file_semantic_embeddings.id;


--
-- Name: genealogy_person_embeddings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.genealogy_person_embeddings (
    id bigint NOT NULL,
    person_id integer NOT NULL,
    tree_id integer NOT NULL,
    full_name character varying(500),
    surname character varying(255),
    given_name character varying(255),
    birth_year character varying(10),
    death_year character varying(10),
    birth_place character varying(500),
    death_place character varying(500),
    biography text,
    search_text text,
    embedding public.vector(768),
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: genealogy_person_embeddings_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.genealogy_person_embeddings_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: genealogy_person_embeddings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.genealogy_person_embeddings_id_seq OWNED BY public.genealogy_person_embeddings.id;


--
-- Name: genealogy_research_fact_links; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.genealogy_research_fact_links (
    id bigint NOT NULL,
    research_fact_id uuid NOT NULL,
    genealogy_person_id bigint NOT NULL,
    fact_type character varying(50) NOT NULL,
    applied_value text,
    applied_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    notes text
);


--
-- Name: genealogy_research_fact_links_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.genealogy_research_fact_links_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: genealogy_research_fact_links_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.genealogy_research_fact_links_id_seq OWNED BY public.genealogy_research_fact_links.id;


--
-- Name: job_batches; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.job_batches (
    id character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    total_jobs integer NOT NULL,
    pending_jobs integer NOT NULL,
    failed_jobs integer NOT NULL,
    failed_job_ids text NOT NULL,
    options text,
    cancelled_at integer,
    created_at integer NOT NULL,
    finished_at integer
);


--
-- Name: jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.jobs (
    id bigint NOT NULL,
    queue character varying(255) NOT NULL,
    payload text NOT NULL,
    attempts smallint NOT NULL,
    reserved_at integer,
    available_at integer NOT NULL,
    created_at integer NOT NULL
);


--
-- Name: jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.jobs_id_seq OWNED BY public.jobs.id;


--
-- Name: kg_quality_runs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.kg_quality_runs (
    id bigint NOT NULL,
    accuracy_score numeric(5,4) DEFAULT 0,
    freshness_score numeric(5,4) DEFAULT 0,
    coverage_score numeric(5,4) DEFAULT 0,
    composite_score numeric(5,4) DEFAULT 0,
    sample_size integer DEFAULT 50,
    sample_details jsonb DEFAULT '{}'::jsonb,
    stale_triple_count integer DEFAULT 0,
    orphan_entity_count integer DEFAULT 0,
    total_triples integer DEFAULT 0,
    total_entities integer DEFAULT 0,
    eligible_documents integer DEFAULT 0,
    extracted_documents integer DEFAULT 0,
    duration_ms integer DEFAULT 0,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    temporal_coverage numeric(5,4) DEFAULT NULL::numeric,
    stale_valid_time_count integer,
    invalidation_rate numeric(5,4) DEFAULT NULL::numeric,
    active_triple_count integer,
    expired_triple_count integer
);


--
-- Name: TABLE kg_quality_runs; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.kg_quality_runs IS 'Quality metric snapshots for knowledge graph accuracy, freshness, and coverage';


--
-- Name: kg_quality_runs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.kg_quality_runs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: kg_quality_runs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.kg_quality_runs_id_seq OWNED BY public.kg_quality_runs.id;


--
-- Name: knowledge_graph; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.knowledge_graph (
    id bigint NOT NULL,
    source_document_id bigint,
    subject text NOT NULL,
    subject_type character varying(50) NOT NULL,
    subject_entity_id bigint,
    predicate character varying(100) NOT NULL,
    object text NOT NULL,
    object_type character varying(50) NOT NULL,
    object_entity_id bigint,
    confidence numeric(4,3) DEFAULT 1.0,
    extracted_from text,
    metadata jsonb DEFAULT '{}'::jsonb,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    t_expired timestamp without time zone,
    valid_from timestamp without time zone,
    valid_until timestamp without time zone,
    temporal_type character varying(20) DEFAULT 'unknown'::character varying,
    temporal_confidence numeric(4,3) DEFAULT NULL::numeric,
    superseded_by bigint,
    CONSTRAINT chk_confidence CHECK (((confidence >= (0)::numeric) AND (confidence <= (1)::numeric))),
    CONSTRAINT chk_kg_temporal_type CHECK (((temporal_type)::text = ANY ((ARRAY['ongoing'::character varying, 'point_in_time'::character varying, 'period'::character varying, 'unknown'::character varying])::text[]))),
    CONSTRAINT chk_object_type CHECK (((object_type)::text = ANY ((ARRAY['person'::character varying, 'organization'::character varying, 'location'::character varying, 'concept'::character varying, 'event'::character varying, 'document'::character varying, 'date'::character varying, 'product'::character varying, 'technology'::character varying, 'other'::character varying, 'file'::character varying, 'genealogy_person'::character varying, 'face_cluster'::character varying])::text[]))),
    CONSTRAINT chk_subject_type CHECK (((subject_type)::text = ANY ((ARRAY['person'::character varying, 'organization'::character varying, 'location'::character varying, 'concept'::character varying, 'event'::character varying, 'document'::character varying, 'date'::character varying, 'product'::character varying, 'technology'::character varying, 'other'::character varying, 'file'::character varying, 'genealogy_person'::character varying, 'face_cluster'::character varying])::text[]))),
    CONSTRAINT knowledge_graph_object_type_check CHECK (((object_type)::text = ANY ((ARRAY['person'::character varying, 'organization'::character varying, 'location'::character varying, 'concept'::character varying, 'event'::character varying, 'document'::character varying, 'date'::character varying, 'product'::character varying, 'technology'::character varying, 'other'::character varying, 'file'::character varying, 'genealogy_person'::character varying, 'face_cluster'::character varying])::text[]))),
    CONSTRAINT knowledge_graph_subject_type_check CHECK (((subject_type)::text = ANY ((ARRAY['person'::character varying, 'organization'::character varying, 'location'::character varying, 'concept'::character varying, 'event'::character varying, 'document'::character varying, 'date'::character varying, 'product'::character varying, 'technology'::character varying, 'other'::character varying, 'file'::character varying, 'genealogy_person'::character varying, 'face_cluster'::character varying])::text[])))
);


--
-- Name: TABLE knowledge_graph; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.knowledge_graph IS 'Subject-predicate-object triples extracted from documents';


--
-- Name: COLUMN knowledge_graph.predicate; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.knowledge_graph.predicate IS 'Relationship type: works_at, located_in, related_to, founded_by, etc.';


--
-- Name: COLUMN knowledge_graph.confidence; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.knowledge_graph.confidence IS 'AI extraction confidence score 0-1';


--
-- Name: COLUMN knowledge_graph.extracted_from; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.knowledge_graph.extracted_from IS 'Source text snippet the triple was extracted from';


--
-- Name: COLUMN knowledge_graph.t_expired; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.knowledge_graph.t_expired IS 'Transaction time: when edge was invalidated (NULL = active)';


--
-- Name: COLUMN knowledge_graph.valid_from; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.knowledge_graph.valid_from IS 'Valid time start: when fact became true in real world (NULL = unknown)';


--
-- Name: COLUMN knowledge_graph.valid_until; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.knowledge_graph.valid_until IS 'Valid time end: when fact stopped being true (NULL = ongoing/unknown)';


--
-- Name: COLUMN knowledge_graph.temporal_type; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.knowledge_graph.temporal_type IS 'Temporal classification: ongoing, point_in_time, period, unknown';


--
-- Name: COLUMN knowledge_graph.temporal_confidence; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.knowledge_graph.temporal_confidence IS 'AI confidence 0-1 in extracted temporal data';


--
-- Name: COLUMN knowledge_graph.superseded_by; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.knowledge_graph.superseded_by IS 'ID of newer triple that replaced this one';


--
-- Name: knowledge_graph_communities; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.knowledge_graph_communities (
    id bigint NOT NULL,
    community_id integer NOT NULL,
    level integer DEFAULT 0 NOT NULL,
    parent_community_id bigint,
    entity_ids jsonb DEFAULT '[]'::jsonb NOT NULL,
    edge_count integer DEFAULT 0 NOT NULL,
    entity_count integer DEFAULT 0 NOT NULL,
    modularity_score double precision,
    detection_run_id uuid,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now()
);


--
-- Name: knowledge_graph_communities_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.knowledge_graph_communities_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: knowledge_graph_communities_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.knowledge_graph_communities_id_seq OWNED BY public.knowledge_graph_communities.id;


--
-- Name: knowledge_graph_community_reports; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.knowledge_graph_community_reports (
    id bigint NOT NULL,
    community_id bigint NOT NULL,
    level integer NOT NULL,
    title text,
    summary text NOT NULL,
    key_entities jsonb DEFAULT '[]'::jsonb,
    key_relationships jsonb DEFAULT '[]'::jsonb,
    themes jsonb DEFAULT '[]'::jsonb,
    rating double precision,
    embedding public.vector(768),
    token_count integer,
    detection_run_id uuid,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now()
);


--
-- Name: knowledge_graph_community_reports_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.knowledge_graph_community_reports_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: knowledge_graph_community_reports_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.knowledge_graph_community_reports_id_seq OWNED BY public.knowledge_graph_community_reports.id;


--
-- Name: knowledge_graph_detection_runs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.knowledge_graph_detection_runs (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    entity_count integer NOT NULL,
    triple_count integer NOT NULL,
    communities_detected integer DEFAULT 0 NOT NULL,
    levels integer DEFAULT 0 NOT NULL,
    resolution_params jsonb DEFAULT '[]'::jsonb,
    modularity_scores jsonb DEFAULT '{}'::jsonb,
    duration_ms integer,
    reports_generated integer DEFAULT 0,
    created_at timestamp without time zone DEFAULT now()
);


--
-- Name: knowledge_graph_edge_history; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.knowledge_graph_edge_history (
    id bigint NOT NULL,
    triple_id bigint NOT NULL,
    action character varying(20) NOT NULL,
    old_values jsonb,
    reason text,
    caused_by_triple_id bigint,
    actor character varying(50) DEFAULT 'system'::character varying,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_edge_history_action CHECK (((action)::text = ANY ((ARRAY['created'::character varying, 'invalidated'::character varying, 'superseded'::character varying, 'temporal_updated'::character varying, 'restored'::character varying])::text[])))
);


--
-- Name: TABLE knowledge_graph_edge_history; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.knowledge_graph_edge_history IS 'Audit trail for knowledge graph edge lifecycle changes (GR-1 bi-temporal)';


--
-- Name: knowledge_graph_edge_history_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.knowledge_graph_edge_history_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: knowledge_graph_edge_history_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.knowledge_graph_edge_history_id_seq OWNED BY public.knowledge_graph_edge_history.id;


--
-- Name: knowledge_graph_entities; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.knowledge_graph_entities (
    id bigint NOT NULL,
    canonical_name text NOT NULL,
    entity_type character varying(50) NOT NULL,
    aliases jsonb DEFAULT '[]'::jsonb,
    properties jsonb DEFAULT '{}'::jsonb,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    primary_community_id bigint,
    degree integer DEFAULT 0,
    pagerank double precision DEFAULT 0.0,
    last_community_run uuid,
    CONSTRAINT chk_entity_type CHECK (((entity_type)::text = ANY ((ARRAY['person'::character varying, 'organization'::character varying, 'location'::character varying, 'concept'::character varying, 'event'::character varying, 'document'::character varying, 'date'::character varying, 'product'::character varying, 'technology'::character varying, 'other'::character varying, 'file'::character varying, 'genealogy_person'::character varying, 'face_cluster'::character varying])::text[])))
);


--
-- Name: TABLE knowledge_graph_entities; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.knowledge_graph_entities IS 'Canonical entity definitions with aliases for knowledge graph deduplication';


--
-- Name: COLUMN knowledge_graph_entities.aliases; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.knowledge_graph_entities.aliases IS 'Array of alternative names/spellings for this entity';


--
-- Name: COLUMN knowledge_graph_entities.properties; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.knowledge_graph_entities.properties IS 'Additional attributes like birth_date, headquarters, etc.';


--
-- Name: knowledge_graph_entities_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.knowledge_graph_entities_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: knowledge_graph_entities_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.knowledge_graph_entities_id_seq OWNED BY public.knowledge_graph_entities.id;


--
-- Name: knowledge_graph_entity_communities; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.knowledge_graph_entity_communities (
    id bigint NOT NULL,
    entity_id bigint NOT NULL,
    community_id bigint NOT NULL,
    membership_score double precision DEFAULT 1.0,
    is_bridge boolean DEFAULT false,
    created_at timestamp without time zone DEFAULT now()
);


--
-- Name: knowledge_graph_entity_communities_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.knowledge_graph_entity_communities_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: knowledge_graph_entity_communities_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.knowledge_graph_entity_communities_id_seq OWNED BY public.knowledge_graph_entity_communities.id;


--
-- Name: knowledge_graph_entity_embeddings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.knowledge_graph_entity_embeddings (
    id bigint NOT NULL,
    entity_id bigint NOT NULL,
    entity_type character varying(50) NOT NULL,
    embedding_text text NOT NULL,
    embedding public.vector(768) NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: knowledge_graph_entity_embeddings_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.knowledge_graph_entity_embeddings_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: knowledge_graph_entity_embeddings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.knowledge_graph_entity_embeddings_id_seq OWNED BY public.knowledge_graph_entity_embeddings.id;


--
-- Name: knowledge_graph_hyperedges; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.knowledge_graph_hyperedges (
    id bigint NOT NULL,
    source_document_id bigint NOT NULL,
    predicate character varying(100) NOT NULL,
    participants jsonb NOT NULL,
    raw_text text,
    confidence numeric(4,3) DEFAULT 1.000,
    metadata jsonb,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now()
);


--
-- Name: knowledge_graph_hyperedges_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.knowledge_graph_hyperedges_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: knowledge_graph_hyperedges_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.knowledge_graph_hyperedges_id_seq OWNED BY public.knowledge_graph_hyperedges.id;


--
-- Name: knowledge_graph_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.knowledge_graph_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: knowledge_graph_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.knowledge_graph_id_seq OWNED BY public.knowledge_graph.id;


--
-- Name: migrations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.migrations (
    id integer NOT NULL,
    migration character varying(255) NOT NULL,
    batch integer NOT NULL
);


--
-- Name: migrations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.migrations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.migrations_id_seq OWNED BY public.migrations.id;


--
-- Name: password_reset_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.password_reset_tokens (
    email character varying(255) NOT NULL,
    token character varying(255) NOT NULL,
    created_at timestamp(0) without time zone
);


--
-- Name: person_clusters; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.person_clusters (
    id integer NOT NULL,
    name character varying(255),
    status character varying(50) DEFAULT 'unreviewed'::character varying,
    face_count integer DEFAULT 0,
    genealogy_person_id integer,
    merged_into_id integer,
    representative_face_id integer,
    notes text,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now(),
    centroid public.vector(128),
    centroid_radius real,
    last_optimized_at timestamp without time zone,
    merge_retry integer DEFAULT 0,
    merge_notes text
);


--
-- Name: TABLE person_clusters; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.person_clusters IS 'AI-detected face clusters representing unique persons';


--
-- Name: person_clusters_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.person_clusters_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: person_clusters_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.person_clusters_id_seq OWNED BY public.person_clusters.id;


--
-- Name: rag_chunk_hypotheticals; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.rag_chunk_hypotheticals (
    id bigint NOT NULL,
    document_id bigint NOT NULL,
    question_text text NOT NULL,
    embedding public.vector(768) NOT NULL,
    question_index smallint DEFAULT 0 NOT NULL,
    created_at timestamp without time zone DEFAULT now() NOT NULL
);


--
-- Name: rag_chunk_hypotheticals_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.rag_chunk_hypotheticals_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: rag_chunk_hypotheticals_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.rag_chunk_hypotheticals_id_seq OWNED BY public.rag_chunk_hypotheticals.id;


--
-- Name: rag_dedup_log; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.rag_dedup_log (
    id bigint NOT NULL,
    incoming_title character varying(500),
    incoming_source_type character varying(100),
    incoming_content_hash character varying(64) NOT NULL,
    matched_document_id bigint,
    similarity_score numeric(6,5),
    strategy character varying(30) NOT NULL,
    action_taken character varying(30) NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: rag_dedup_log_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.rag_dedup_log_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: rag_dedup_log_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.rag_dedup_log_id_seq OWNED BY public.rag_dedup_log.id;


--
-- Name: rag_documents_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.rag_documents_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: rag_documents_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.rag_documents_id_seq OWNED BY public.rag_documents.id;


--
-- Name: rag_evaluations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.rag_evaluations (
    id bigint NOT NULL,
    query text NOT NULL,
    answer text NOT NULL,
    metrics jsonb DEFAULT '{}'::jsonb NOT NULL,
    overall_score numeric(5,4) DEFAULT 0 NOT NULL,
    evaluated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: rag_evaluations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.rag_evaluations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: rag_evaluations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.rag_evaluations_id_seq OWNED BY public.rag_evaluations.id;


--
-- Name: rag_propositions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.rag_propositions (
    id bigint NOT NULL,
    document_id integer NOT NULL,
    chunk_index integer DEFAULT 0 NOT NULL,
    proposition_text text NOT NULL,
    subject character varying(255),
    predicate character varying(255),
    object_value character varying(500),
    confidence numeric(5,4) DEFAULT 0.5,
    extraction_method character varying(20) DEFAULT 'heuristic'::character varying,
    embedding public.vector(768),
    created_at timestamp without time zone DEFAULT now()
);


--
-- Name: rag_propositions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.rag_propositions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: rag_propositions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.rag_propositions_id_seq OWNED BY public.rag_propositions.id;


--
-- Name: rag_query_traces; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.rag_query_traces (
    id bigint NOT NULL,
    query_text text NOT NULL,
    strategy_used character varying(50),
    retrieval_time_ms integer,
    rerank_time_ms integer,
    total_time_ms integer,
    result_count integer DEFAULT 0,
    top_similarity numeric(6,4),
    hyde_used boolean DEFAULT false,
    raptor_used boolean DEFAULT false,
    filters_applied jsonb,
    metadata jsonb,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: rag_query_traces_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.rag_query_traces_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: rag_query_traces_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.rag_query_traces_id_seq OWNED BY public.rag_query_traces.id;


--
-- Name: rag_sentence_embeddings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.rag_sentence_embeddings (
    id bigint NOT NULL,
    document_id bigint NOT NULL,
    sentence_index integer NOT NULL,
    sentence_text text NOT NULL,
    char_start integer NOT NULL,
    char_end integer NOT NULL,
    embedding public.vector(768) NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: rag_sentence_embeddings_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.rag_sentence_embeddings_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: rag_sentence_embeddings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.rag_sentence_embeddings_id_seq OWNED BY public.rag_sentence_embeddings.id;


--
-- Name: raptor_summaries; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.raptor_summaries (
    id bigint NOT NULL,
    document_id bigint NOT NULL,
    parent_summary_id bigint,
    level integer DEFAULT 0 NOT NULL,
    level_name character varying(50) DEFAULT 'sentence'::character varying NOT NULL,
    summary_text text NOT NULL,
    source_chunk_ids jsonb,
    token_count integer,
    embedding public.vector(768),
    metadata jsonb,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    kg_community_id bigint
);


--
-- Name: COLUMN raptor_summaries.level; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.raptor_summaries.level IS '0=sentence, 1=paragraph, 2=section, 3=document';


--
-- Name: COLUMN raptor_summaries.source_chunk_ids; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.raptor_summaries.source_chunk_ids IS 'Array of rag_document IDs or raptor_summary IDs that were summarized';


--
-- Name: COLUMN raptor_summaries.metadata; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.raptor_summaries.metadata IS 'Additional context (position, title hints, etc.)';


--
-- Name: raptor_summaries_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.raptor_summaries_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: raptor_summaries_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.raptor_summaries_id_seq OWNED BY public.raptor_summaries.id;


--
-- Name: research_cache; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.research_cache (
    id integer NOT NULL,
    source_id integer NOT NULL,
    query_hash character varying(64) NOT NULL,
    query_params jsonb NOT NULL,
    result_count integer DEFAULT 0,
    results jsonb,
    person_id integer,
    tree_id integer,
    cached_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    expires_at timestamp without time zone,
    access_count integer DEFAULT 1,
    last_accessed_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: research_cache_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.research_cache_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: research_cache_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.research_cache_id_seq OWNED BY public.research_cache.id;


--
-- Name: research_facts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.research_facts (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    mission_id uuid,
    fact_statement text NOT NULL,
    fact_hash character varying(64) NOT NULL,
    fact_type character varying(50),
    domain_category character varying(100),
    context_snippet text,
    verification_status character varying(30) DEFAULT 'unverified'::character varying,
    confidence_score numeric(5,4) DEFAULT 0,
    llm_stated boolean DEFAULT false,
    llm_confidence numeric(5,4),
    llm_model character varying(100),
    external_sources_checked integer DEFAULT 0,
    external_sources_confirmed integer DEFAULT 0,
    external_sources_denied integer DEFAULT 0,
    rag_cross_referenced boolean DEFAULT false,
    rag_match_score numeric(5,4),
    rag_match_document_ids jsonb DEFAULT '[]'::jsonb,
    primary_source_id uuid,
    source_urls jsonb DEFAULT '[]'::jsonb,
    source_citations jsonb DEFAULT '[]'::jsonb,
    indexed_to_rag boolean DEFAULT false,
    rag_document_id bigint,
    related_entities jsonb DEFAULT '[]'::jsonb,
    tags jsonb DEFAULT '[]'::jsonb,
    notes text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    verified_at timestamp without time zone,
    indexed_at timestamp without time zone,
    needs_human_review boolean DEFAULT false,
    human_review_action character varying(50),
    human_reviewed_at timestamp without time zone,
    review_status character varying(20) DEFAULT 'pending'::character varying,
    reviewed_at timestamp without time zone,
    reviewed_by character varying(100),
    skip_reason text,
    source_count integer DEFAULT 0,
    verification_summary jsonb
);


--
-- Name: COLUMN research_facts.review_status; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.research_facts.review_status IS 'pending, approved, rejected, auto_skipped';


--
-- Name: research_missions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.research_missions (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    title character varying(255) NOT NULL,
    description text,
    mission_type character varying(50) DEFAULT 'knowledge_capture'::character varying NOT NULL,
    domain_category character varying(100) DEFAULT 'general'::character varying,
    query_template text,
    constraints jsonb DEFAULT '{}'::jsonb,
    status character varying(30) DEFAULT 'pending'::character varying,
    progress_pct numeric(5,2) DEFAULT 0,
    current_phase character varying(50),
    phase_details jsonb DEFAULT '{}'::jsonb,
    depth_level integer DEFAULT 3,
    verification_level character varying(30) DEFAULT 'strict'::character varying,
    max_sources integer DEFAULT 20,
    time_limit_minutes integer DEFAULT 30,
    facts_discovered integer DEFAULT 0,
    facts_verified integer DEFAULT 0,
    facts_rejected integer DEFAULT 0,
    sources_discovered integer DEFAULT 0,
    sources_used integer DEFAULT 0,
    last_error text,
    error_count integer DEFAULT 0,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    started_at timestamp without time zone,
    completed_at timestamp without time zone,
    created_by character varying(50) DEFAULT 'system'::character varying,
    workflow_run_id integer,
    frequency character varying(20) DEFAULT 'once'::character varying,
    rag_category character varying(100),
    last_ran_at timestamp without time zone,
    next_run_at timestamp without time zone,
    is_active boolean DEFAULT true,
    require_human_approval boolean DEFAULT true,
    migrated_from_topic_id integer,
    auto_index_to_rag boolean DEFAULT false,
    report text,
    CONSTRAINT research_missions_depth_level_check CHECK (((depth_level >= 1) AND (depth_level <= 10)))
);


--
-- Name: COLUMN research_missions.frequency; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.research_missions.frequency IS 'once, daily, weekly, monthly, quarterly, biannually';


--
-- Name: research_rejected_facts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.research_rejected_facts (
    fact_hash character varying(64) NOT NULL,
    original_fact_statement text NOT NULL,
    rejected_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    rejected_by character varying(100),
    rejection_reason text,
    mission_id uuid,
    rejection_count integer DEFAULT 1,
    last_rejected_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: research_rejections; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.research_rejections (
    id bigint NOT NULL,
    research_topic_id bigint NOT NULL,
    content_hash character varying(64) NOT NULL,
    fact_hashes jsonb DEFAULT '[]'::jsonb,
    rejection_reason character varying(255),
    rejected_by character varying(50) DEFAULT 'human'::character varying,
    original_result_id bigint,
    created_at timestamp without time zone DEFAULT now()
);


--
-- Name: research_rejections_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.research_rejections_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: research_rejections_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.research_rejections_id_seq OWNED BY public.research_rejections.id;


--
-- Name: research_results; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.research_results (
    id bigint NOT NULL,
    research_topic_id bigint NOT NULL,
    ai_output text NOT NULL,
    status character varying(20) DEFAULT 'pending'::character varying NOT NULL,
    reviewed_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    quality_score numeric(3,2) DEFAULT NULL::numeric,
    ai_quality_score numeric(3,2) DEFAULT NULL::numeric,
    ai_has_findings boolean,
    ai_recommendation character varying(20) DEFAULT NULL::character varying,
    content_hash character varying(64),
    normalized_content text,
    extracted_facts jsonb DEFAULT '[]'::jsonb,
    dedup_status character varying(20) DEFAULT NULL::character varying,
    dedup_matched_id bigint,
    source_references jsonb,
    rag_indexed_at timestamp(0) without time zone,
    fact_checked_at timestamp without time zone
);


--
-- Name: COLUMN research_results.ai_output; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.research_results.ai_output IS 'AI-generated research content';


--
-- Name: COLUMN research_results.status; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.research_results.status IS 'pending, approved, skipped';


--
-- Name: COLUMN research_results.reviewed_at; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.research_results.reviewed_at IS 'When human reviewed this result';


--
-- Name: COLUMN research_results.quality_score; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.research_results.quality_score IS 'AI-assessed quality score 0.0-1.0, NULL if not assessed';


--
-- Name: COLUMN research_results.ai_quality_score; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.research_results.ai_quality_score IS 'AI-assigned quality score 0.0-1.0';


--
-- Name: COLUMN research_results.ai_has_findings; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.research_results.ai_has_findings IS 'Whether AI found actionable information';


--
-- Name: COLUMN research_results.ai_recommendation; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.research_results.ai_recommendation IS 'index, reject, review, or needs_research';


--
-- Name: research_results_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.research_results_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: research_results_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.research_results_id_seq OWNED BY public.research_results.id;


--
-- Name: research_source_results; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.research_source_results (
    id integer NOT NULL,
    research_topic_id integer NOT NULL,
    source_id integer,
    url character varying(1000) NOT NULL,
    title character varying(500),
    snippet text,
    full_content text,
    content_hash character varying(64),
    published_date date,
    scraped_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    relevance_score numeric(5,4),
    ai_vetted boolean DEFAULT false,
    ai_vetting_notes text,
    is_duplicate boolean DEFAULT false,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: research_source_results_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.research_source_results_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: research_source_results_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.research_source_results_id_seq OWNED BY public.research_source_results.id;


--
-- Name: research_sources; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.research_sources (
    id integer NOT NULL,
    name character varying(255) NOT NULL,
    base_url character varying(500) NOT NULL,
    url_pattern character varying(500),
    source_type character varying(50) DEFAULT 'website'::character varying NOT NULL,
    categories jsonb DEFAULT '[]'::jsonb,
    trust_score smallint DEFAULT 5,
    domain_type character varying(50),
    requires_scraping boolean DEFAULT true,
    rate_limit_per_hour integer DEFAULT 60,
    last_success_at timestamp without time zone,
    last_failure_at timestamp without time zone,
    failure_count integer DEFAULT 0,
    success_count integer DEFAULT 0,
    avg_response_ms integer,
    is_active boolean DEFAULT true,
    is_search_engine boolean DEFAULT false,
    search_url_template character varying(500),
    result_selector character varying(255),
    notes text,
    discovered_by character varying(50) DEFAULT 'manual'::character varying,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    research_category character varying(30) DEFAULT 'general'::character varying,
    auth_type character varying(20) DEFAULT 'none'::character varying,
    auth_config jsonb,
    api_endpoints jsonb,
    geographic_coverage jsonb,
    temporal_coverage jsonb,
    record_types jsonb,
    estimated_records bigint,
    ocr_quality numeric(3,2),
    is_free boolean DEFAULT true,
    documentation_url character varying(500),
    ai_success_count integer DEFAULT 0,
    ai_failure_count integer DEFAULT 0,
    ai_last_used timestamp without time zone,
    ai_notes jsonb,
    CONSTRAINT research_sources_trust_score_check CHECK (((trust_score >= 1) AND (trust_score <= 10)))
);


--
-- Name: research_sources_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.research_sources_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: research_sources_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.research_sources_id_seq OWNED BY public.research_sources.id;


--
-- Name: research_topics; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.research_topics (
    id bigint NOT NULL,
    description character varying(255) NOT NULL,
    topic_content text NOT NULL,
    frequency character varying(20) DEFAULT 'monthly'::character varying NOT NULL,
    last_ran_at timestamp(0) without time zone,
    is_active boolean DEFAULT true NOT NULL,
    rag_category character varying(100),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    search_depth integer DEFAULT 3,
    max_sources integer DEFAULT 10,
    max_results_per_source integer DEFAULT 5,
    date_filter_days integer DEFAULT 30,
    preferred_categories jsonb DEFAULT '[]'::jsonb,
    excluded_domains jsonb DEFAULT '[]'::jsonb,
    require_recent_only boolean DEFAULT true,
    source character varying(20) DEFAULT 'auto'::character varying,
    migrated_to_mission_id uuid,
    mode character varying(20) DEFAULT NULL::character varying
);


--
-- Name: COLUMN research_topics.description; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.research_topics.description IS 'Short description for UI display';


--
-- Name: COLUMN research_topics.topic_content; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.research_topics.topic_content IS 'Full topic paragraph/keywords for AI research';


--
-- Name: COLUMN research_topics.frequency; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.research_topics.frequency IS 'daily, weekly, monthly, quarterly, biannually';


--
-- Name: COLUMN research_topics.last_ran_at; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.research_topics.last_ran_at IS 'Last time research was performed';


--
-- Name: COLUMN research_topics.is_active; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.research_topics.is_active IS 'Whether this topic is actively scheduled';


--
-- Name: COLUMN research_topics.rag_category; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.research_topics.rag_category IS 'Category name for RAG storage when approved';


--
-- Name: COLUMN research_topics.source; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.research_topics.source IS 'Topic origin: auto (system-generated), human (manual), workflow (from workflow node)';


--
-- Name: research_topics_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.research_topics_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: research_topics_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.research_topics_id_seq OWNED BY public.research_topics.id;


--
-- Name: sessions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sessions (
    id character varying(255) NOT NULL,
    user_id bigint,
    ip_address character varying(45),
    user_agent text,
    payload text NOT NULL,
    last_activity integer NOT NULL
);


--
-- Name: source_credibility; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.source_credibility (
    id integer NOT NULL,
    domain character varying(255) NOT NULL,
    url text,
    composite_score numeric(5,4),
    dimension_scores jsonb,
    tier character varying(50),
    confidence numeric(4,3),
    custom_score numeric(5,4),
    verification_result character varying(30),
    accuracy_score numeric(5,4),
    verification_count integer DEFAULT 0,
    last_verified_at timestamp without time zone,
    citation_count integer DEFAULT 0,
    cited_by_count integer DEFAULT 0,
    last_citation_at timestamp without time zone,
    notes text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    bayesian_alpha numeric(8,3) DEFAULT 2.0,
    bayesian_beta numeric(8,3) DEFAULT 2.0,
    last_bayesian_update timestamp without time zone
);


--
-- Name: source_credibility_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.source_credibility_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: source_credibility_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.source_credibility_id_seq OWNED BY public.source_credibility.id;


--
-- Name: source_discovery_patterns; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.source_discovery_patterns (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    pattern_name character varying(255),
    pattern_hash character varying(64) NOT NULL,
    domain_category character varying(100) NOT NULL,
    pattern_used text NOT NULL,
    discovery_method character varying(50) DEFAULT 'ai_suggestion'::character varying,
    pattern_keywords jsonb DEFAULT '[]'::jsonb,
    pattern_exclusions jsonb DEFAULT '[]'::jsonb,
    pattern_modifiers jsonb DEFAULT '{}'::jsonb,
    sources_discovered integer DEFAULT 0,
    sources_whitelisted integer DEFAULT 0,
    sources_blacklisted integer DEFAULT 0,
    sources_active integer DEFAULT 0,
    sources_inactive integer DEFAULT 0,
    total_success_count integer DEFAULT 0,
    total_failure_count integer DEFAULT 0,
    success_rate_pct numeric(5,2),
    avg_trust_score numeric(4,3),
    avg_safety_score numeric(4,3),
    avg_accuracy_rating numeric(3,2),
    avg_relevance_rating numeric(3,2),
    facts_generated integer DEFAULT 0,
    facts_verified integer DEFAULT 0,
    times_used integer DEFAULT 1,
    first_used_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    last_used_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    last_success_at timestamp without time zone,
    derived_from_pattern_id uuid,
    evolved_count integer DEFAULT 0,
    confidence_score numeric(4,3) DEFAULT 0.5,
    is_active boolean DEFAULT true,
    is_verified boolean DEFAULT false,
    is_manual boolean DEFAULT false,
    notes text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT valid_discovery_method CHECK (((discovery_method)::text = ANY ((ARRAY['ai_suggestion'::character varying, 'search_engine'::character varying, 'manual'::character varying, 'scrape_extraction'::character varying, 'reference_following'::character varying, 'hybrid'::character varying])::text[])))
);


--
-- Name: source_performance_feedback; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.source_performance_feedback (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    source_id uuid,
    source_domain character varying(500) NOT NULL,
    mission_id uuid,
    research_topic text,
    research_category character varying(100),
    accuracy_rating smallint,
    relevance_rating smallint,
    reliability_rating smallint,
    timeliness_rating smallint,
    overall_score numeric(3,2),
    feedback_type character varying(30) DEFAULT 'neutral'::character varying NOT NULL,
    notes text,
    error_message text,
    response_time_ms integer,
    content_length integer,
    facts_extracted integer DEFAULT 0,
    facts_verified integer DEFAULT 0,
    facts_rejected integer DEFAULT 0,
    trust_score_before numeric(4,3),
    trust_score_after numeric(4,3),
    safety_score_before numeric(4,3),
    safety_score_after numeric(4,3),
    rated_by character varying(100) DEFAULT 'system'::character varying,
    rated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT source_performance_feedback_accuracy_rating_check CHECK (((accuracy_rating IS NULL) OR ((accuracy_rating >= 1) AND (accuracy_rating <= 5)))),
    CONSTRAINT source_performance_feedback_relevance_rating_check CHECK (((relevance_rating IS NULL) OR ((relevance_rating >= 1) AND (relevance_rating <= 5)))),
    CONSTRAINT source_performance_feedback_reliability_rating_check CHECK (((reliability_rating IS NULL) OR ((reliability_rating >= 1) AND (reliability_rating <= 5)))),
    CONSTRAINT source_performance_feedback_timeliness_rating_check CHECK (((timeliness_rating IS NULL) OR ((timeliness_rating >= 1) AND (timeliness_rating <= 5)))),
    CONSTRAINT valid_feedback_type CHECK (((feedback_type)::text = ANY ((ARRAY['excellent'::character varying, 'good'::character varying, 'neutral'::character varying, 'poor'::character varying, 'unusable'::character varying, 'false_positive'::character varying, 'irrelevant'::character varying, 'outdated'::character varying, 'blocked'::character varying, 'error'::character varying])::text[])))
);


--
-- Name: users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.users (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    email character varying(255) NOT NULL,
    email_verified_at timestamp(0) without time zone,
    password character varying(255) NOT NULL,
    remember_token character varying(100),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: users_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.users_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: users_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.users_id_seq OWNED BY public.users.id;


--
-- Name: verdicts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.verdicts (
    id bigint NOT NULL,
    claim_id bigint NOT NULL,
    verdict character varying(20) DEFAULT 'inconclusive'::character varying NOT NULL,
    confidence numeric(4,3) DEFAULT 0.0,
    factuality_score numeric(4,3),
    evidence_summary text,
    supporting_count integer DEFAULT 0,
    contradicting_count integer DEFAULT 0,
    neutral_count integer DEFAULT 0,
    human_reviewed boolean DEFAULT false,
    reviewed_by character varying(100),
    review_notes text,
    reviewed_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_confidence CHECK (((confidence >= (0)::numeric) AND (confidence <= (1)::numeric))),
    CONSTRAINT chk_factuality CHECK (((factuality_score IS NULL) OR ((factuality_score >= (0)::numeric) AND (factuality_score <= (1)::numeric)))),
    CONSTRAINT chk_verdict CHECK (((verdict)::text = ANY ((ARRAY['true'::character varying, 'mostly_true'::character varying, 'half_true'::character varying, 'mostly_false'::character varying, 'false'::character varying, 'inconclusive'::character varying, 'supported'::character varying, 'refuted'::character varying])::text[])))
);


--
-- Name: TABLE verdicts; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.verdicts IS 'Final verification verdicts for claims with human review support';


--
-- Name: COLUMN verdicts.verdict; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.verdicts.verdict IS 'Final verdict: supported (confirmed), refuted (debunked), inconclusive';


--
-- Name: COLUMN verdicts.factuality_score; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.verdicts.factuality_score IS 'Computed as: supporting / (supporting + contradicting), NULL if no evidence';


--
-- Name: COLUMN verdicts.evidence_summary; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.verdicts.evidence_summary IS 'AI-generated summary of evidence for/against the claim';


--
-- Name: verdicts_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.verdicts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: verdicts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.verdicts_id_seq OWNED BY public.verdicts.id;


--
-- Name: verification_attempts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.verification_attempts (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    fact_id uuid NOT NULL,
    method character varying(30) NOT NULL,
    source_id uuid,
    source_url text,
    search_query text,
    result character varying(20) NOT NULL,
    confidence numeric(5,4),
    evidence_snippet text,
    evidence_url text,
    executed_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    response_time_ms integer,
    error_message text,
    raw_response jsonb
);


--
-- Name: agent_episode_embeddings id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agent_episode_embeddings ALTER COLUMN id SET DEFAULT nextval('public.agent_episode_embeddings_id_seq'::regclass);


--
-- Name: agent_procedure_embeddings id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agent_procedure_embeddings ALTER COLUMN id SET DEFAULT nextval('public.agent_procedure_embeddings_id_seq'::regclass);


--
-- Name: ai_semantic_cache id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_semantic_cache ALTER COLUMN id SET DEFAULT nextval('public.ai_semantic_cache_id_seq'::regclass);


--
-- Name: claims id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.claims ALTER COLUMN id SET DEFAULT nextval('public.claims_id_seq'::regclass);


--
-- Name: cluster_merge_history id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cluster_merge_history ALTER COLUMN id SET DEFAULT nextval('public.cluster_merge_history_id_seq'::regclass);


--
-- Name: consensus_verdicts id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consensus_verdicts ALTER COLUMN id SET DEFAULT nextval('public.consensus_verdicts_id_seq'::regclass);


--
-- Name: contradictions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.contradictions ALTER COLUMN id SET DEFAULT nextval('public.contradictions_id_seq'::regclass);


--
-- Name: entity_resolution_runs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entity_resolution_runs ALTER COLUMN id SET DEFAULT nextval('public.entity_resolution_runs_id_seq'::regclass);


--
-- Name: evidence id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.evidence ALTER COLUMN id SET DEFAULT nextval('public.evidence_id_seq'::regclass);


--
-- Name: face_embeddings id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.face_embeddings ALTER COLUMN id SET DEFAULT nextval('public.face_embeddings_id_seq'::regclass);


--
-- Name: face_match_candidates id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.face_match_candidates ALTER COLUMN id SET DEFAULT nextval('public.face_match_candidates_id_seq'::regclass);


--
-- Name: fact_check_benchmark_claims id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fact_check_benchmark_claims ALTER COLUMN id SET DEFAULT nextval('public.fact_check_benchmark_claims_id_seq'::regclass);


--
-- Name: fact_check_benchmark_runs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fact_check_benchmark_runs ALTER COLUMN id SET DEFAULT nextval('public.fact_check_benchmark_runs_id_seq'::regclass);


--
-- Name: fact_check_pipeline_runs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fact_check_pipeline_runs ALTER COLUMN id SET DEFAULT nextval('public.fact_check_pipeline_runs_id_seq'::regclass);


--
-- Name: failed_jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs ALTER COLUMN id SET DEFAULT nextval('public.failed_jobs_id_seq'::regclass);


--
-- Name: file_semantic_embeddings id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.file_semantic_embeddings ALTER COLUMN id SET DEFAULT nextval('public.file_semantic_embeddings_id_seq'::regclass);


--
-- Name: genealogy_person_embeddings id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.genealogy_person_embeddings ALTER COLUMN id SET DEFAULT nextval('public.genealogy_person_embeddings_id_seq'::regclass);


--
-- Name: genealogy_research_fact_links id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.genealogy_research_fact_links ALTER COLUMN id SET DEFAULT nextval('public.genealogy_research_fact_links_id_seq'::regclass);


--
-- Name: jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs ALTER COLUMN id SET DEFAULT nextval('public.jobs_id_seq'::regclass);


--
-- Name: kg_quality_runs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.kg_quality_runs ALTER COLUMN id SET DEFAULT nextval('public.kg_quality_runs_id_seq'::regclass);


--
-- Name: knowledge_graph id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.knowledge_graph ALTER COLUMN id SET DEFAULT nextval('public.knowledge_graph_id_seq'::regclass);


--
-- Name: knowledge_graph_communities id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.knowledge_graph_communities ALTER COLUMN id SET DEFAULT nextval('public.knowledge_graph_communities_id_seq'::regclass);


--
-- Name: knowledge_graph_community_reports id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.knowledge_graph_community_reports ALTER COLUMN id SET DEFAULT nextval('public.knowledge_graph_community_reports_id_seq'::regclass);


--
-- Name: knowledge_graph_edge_history id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.knowledge_graph_edge_history ALTER COLUMN id SET DEFAULT nextval('public.knowledge_graph_edge_history_id_seq'::regclass);


--
-- Name: knowledge_graph_entities id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.knowledge_graph_entities ALTER COLUMN id SET DEFAULT nextval('public.knowledge_graph_entities_id_seq'::regclass);


--
-- Name: knowledge_graph_entity_communities id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.knowledge_graph_entity_communities ALTER COLUMN id SET DEFAULT nextval('public.knowledge_graph_entity_communities_id_seq'::regclass);


--
-- Name: knowledge_graph_entity_embeddings id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.knowledge_graph_entity_embeddings ALTER COLUMN id SET DEFAULT nextval('public.knowledge_graph_entity_embeddings_id_seq'::regclass);


--
-- Name: knowledge_graph_hyperedges id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.knowledge_graph_hyperedges ALTER COLUMN id SET DEFAULT nextval('public.knowledge_graph_hyperedges_id_seq'::regclass);


--
-- Name: migrations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations ALTER COLUMN id SET DEFAULT nextval('public.migrations_id_seq'::regclass);


--
-- Name: person_clusters id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.person_clusters ALTER COLUMN id SET DEFAULT nextval('public.person_clusters_id_seq'::regclass);


--
-- Name: rag_chunk_hypotheticals id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rag_chunk_hypotheticals ALTER COLUMN id SET DEFAULT nextval('public.rag_chunk_hypotheticals_id_seq'::regclass);


--
-- Name: rag_dedup_log id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rag_dedup_log ALTER COLUMN id SET DEFAULT nextval('public.rag_dedup_log_id_seq'::regclass);


--
-- Name: rag_documents id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rag_documents ALTER COLUMN id SET DEFAULT nextval('public.rag_documents_id_seq'::regclass);


--
-- Name: rag_evaluations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rag_evaluations ALTER COLUMN id SET DEFAULT nextval('public.rag_evaluations_id_seq'::regclass);


--
-- Name: rag_propositions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rag_propositions ALTER COLUMN id SET DEFAULT nextval('public.rag_propositions_id_seq'::regclass);


--
-- Name: rag_query_traces id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rag_query_traces ALTER COLUMN id SET DEFAULT nextval('public.rag_query_traces_id_seq'::regclass);


--
-- Name: rag_sentence_embeddings id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rag_sentence_embeddings ALTER COLUMN id SET DEFAULT nextval('public.rag_sentence_embeddings_id_seq'::regclass);


--
-- Name: raptor_summaries id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.raptor_summaries ALTER COLUMN id SET DEFAULT nextval('public.raptor_summaries_id_seq'::regclass);


--
-- Name: research_cache id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.research_cache ALTER COLUMN id SET DEFAULT nextval('public.research_cache_id_seq'::regclass);


--
-- Name: research_rejections id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.research_rejections ALTER COLUMN id SET DEFAULT nextval('public.research_rejections_id_seq'::regclass);


--
-- Name: research_results id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.research_results ALTER COLUMN id SET DEFAULT nextval('public.research_results_id_seq'::regclass);


--
-- Name: research_source_results id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.research_source_results ALTER COLUMN id SET DEFAULT nextval('public.research_source_results_id_seq'::regclass);


--
-- Name: research_sources id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.research_sources ALTER COLUMN id SET DEFAULT nextval('public.research_sources_id_seq'::regclass);


--
-- Name: research_topics id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.research_topics ALTER COLUMN id SET DEFAULT nextval('public.research_topics_id_seq'::regclass);


--
-- Name: source_credibility id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.source_credibility ALTER COLUMN id SET DEFAULT nextval('public.source_credibility_id_seq'::regclass);


--
-- Name: users id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users ALTER COLUMN id SET DEFAULT nextval('public.users_id_seq'::regclass);


--
-- Name: verdicts id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.verdicts ALTER COLUMN id SET DEFAULT nextval('public.verdicts_id_seq'::regclass);


--
-- Name: agent_episode_embeddings agent_episode_embeddings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agent_episode_embeddings
    ADD CONSTRAINT agent_episode_embeddings_pkey PRIMARY KEY (id);


--
-- Name: agent_procedure_embeddings agent_procedure_embeddings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.agent_procedure_embeddings
    ADD CONSTRAINT agent_procedure_embeddings_pkey PRIMARY KEY (id);


--
-- Name: ai_semantic_cache ai_semantic_cache_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_semantic_cache
    ADD CONSTRAINT ai_semantic_cache_pkey PRIMARY KEY (id);


--
-- Name: cache_locks cache_locks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache_locks
    ADD CONSTRAINT cache_locks_pkey PRIMARY KEY (key);


--
-- Name: cache cache_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache
    ADD CONSTRAINT cache_pkey PRIMARY KEY (key);


--
-- Name: claims claims_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.claims
    ADD CONSTRAINT claims_pkey PRIMARY KEY (id);


--
-- Name: cluster_merge_history cluster_merge_history_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cluster_merge_history
    ADD CONSTRAINT cluster_merge_history_pkey PRIMARY KEY (id);


--
-- Name: consensus_verdicts consensus_verdicts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consensus_verdicts
    ADD CONSTRAINT consensus_verdicts_pkey PRIMARY KEY (id);


--
-- Name: contradictions contradictions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.contradictions
    ADD CONSTRAINT contradictions_pkey PRIMARY KEY (id);


--
-- Name: discovered_sources discovered_sources_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.discovered_sources
    ADD CONSTRAINT discovered_sources_pkey PRIMARY KEY (id);


--
-- Name: discovery_rules discovery_rules_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.discovery_rules
    ADD CONSTRAINT discovery_rules_pkey PRIMARY KEY (id);


--
-- Name: entity_resolution_runs entity_resolution_runs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entity_resolution_runs
    ADD CONSTRAINT entity_resolution_runs_pkey PRIMARY KEY (id);


--
-- Name: evidence evidence_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.evidence
    ADD CONSTRAINT evidence_pkey PRIMARY KEY (id);


--
-- Name: face_embeddings face_embeddings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.face_embeddings
    ADD CONSTRAINT face_embeddings_pkey PRIMARY KEY (id);


--
-- Name: face_match_candidates face_match_candidates_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.face_match_candidates
    ADD CONSTRAINT face_match_candidates_pkey PRIMARY KEY (id);


--
-- Name: fact_check_benchmark_claims fact_check_benchmark_claims_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fact_check_benchmark_claims
    ADD CONSTRAINT fact_check_benchmark_claims_pkey PRIMARY KEY (id);


--
-- Name: fact_check_benchmark_runs fact_check_benchmark_runs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fact_check_benchmark_runs
    ADD CONSTRAINT fact_check_benchmark_runs_pkey PRIMARY KEY (id);


--
-- Name: fact_check_benchmark_runs fact_check_benchmark_runs_run_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fact_check_benchmark_runs
    ADD CONSTRAINT fact_check_benchmark_runs_run_id_key UNIQUE (run_id);


--
-- Name: fact_check_pipeline_runs fact_check_pipeline_runs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fact_check_pipeline_runs
    ADD CONSTRAINT fact_check_pipeline_runs_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_uuid_unique UNIQUE (uuid);


--
-- Name: file_semantic_embeddings file_semantic_embeddings_file_id_chunk_index_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.file_semantic_embeddings
    ADD CONSTRAINT file_semantic_embeddings_file_id_chunk_index_key UNIQUE (file_id, chunk_index);


--
-- Name: file_semantic_embeddings file_semantic_embeddings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.file_semantic_embeddings
    ADD CONSTRAINT file_semantic_embeddings_pkey PRIMARY KEY (id);


--
-- Name: genealogy_person_embeddings genealogy_person_embeddings_person_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.genealogy_person_embeddings
    ADD CONSTRAINT genealogy_person_embeddings_person_id_key UNIQUE (person_id);


--
-- Name: genealogy_person_embeddings genealogy_person_embeddings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.genealogy_person_embeddings
    ADD CONSTRAINT genealogy_person_embeddings_pkey PRIMARY KEY (id);


--
-- Name: genealogy_research_fact_links genealogy_research_fact_links_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.genealogy_research_fact_links
    ADD CONSTRAINT genealogy_research_fact_links_pkey PRIMARY KEY (id);


--
-- Name: job_batches job_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.job_batches
    ADD CONSTRAINT job_batches_pkey PRIMARY KEY (id);


--
-- Name: jobs jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs
    ADD CONSTRAINT jobs_pkey PRIMARY KEY (id);


--
-- Name: kg_quality_runs kg_quality_runs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.kg_quality_runs
    ADD CONSTRAINT kg_quality_runs_pkey PRIMARY KEY (id);


--
-- Name: knowledge_graph_communities knowledge_graph_communities_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.knowledge_graph_communities
    ADD CONSTRAINT knowledge_graph_communities_pkey PRIMARY KEY (id);


--
-- Name: knowledge_graph_community_reports knowledge_graph_community_reports_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.knowledge_graph_community_reports
    ADD CONSTRAINT knowledge_graph_community_reports_pkey PRIMARY KEY (id);


--
-- Name: knowledge_graph_detection_runs knowledge_graph_detection_runs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.knowledge_graph_detection_runs
    ADD CONSTRAINT knowledge_graph_detection_runs_pkey PRIMARY KEY (id);


--
-- Name: knowledge_graph_edge_history knowledge_graph_edge_history_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.knowledge_graph_edge_history
    ADD CONSTRAINT knowledge_graph_edge_history_pkey PRIMARY KEY (id);


--
-- Name: knowledge_graph_entities knowledge_graph_entities_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.knowledge_graph_entities
    ADD CONSTRAINT knowledge_graph_entities_pkey PRIMARY KEY (id);


--
-- Name: knowledge_graph_entity_communities knowledge_graph_entity_communities_entity_id_community_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.knowledge_graph_entity_communities
    ADD CONSTRAINT knowledge_graph_entity_communities_entity_id_community_id_key UNIQUE (entity_id, community_id);


--
-- Name: knowledge_graph_entity_communities knowledge_graph_entity_communities_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.knowledge_graph_entity_communities
    ADD CONSTRAINT knowledge_graph_entity_communities_pkey PRIMARY KEY (id);


--
-- Name: knowledge_graph_entity_embeddings knowledge_graph_entity_embeddings_entity_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.knowledge_graph_entity_embeddings
    ADD CONSTRAINT knowledge_graph_entity_embeddings_entity_id_key UNIQUE (entity_id);


--
-- Name: knowledge_graph_entity_embeddings knowledge_graph_entity_embeddings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.knowledge_graph_entity_embeddings
    ADD CONSTRAINT knowledge_graph_entity_embeddings_pkey PRIMARY KEY (id);


--
-- Name: knowledge_graph_hyperedges knowledge_graph_hyperedges_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.knowledge_graph_hyperedges
    ADD CONSTRAINT knowledge_graph_hyperedges_pkey PRIMARY KEY (id);


--
-- Name: knowledge_graph knowledge_graph_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.knowledge_graph
    ADD CONSTRAINT knowledge_graph_pkey PRIMARY KEY (id);


--
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- Name: password_reset_tokens password_reset_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.password_reset_tokens
    ADD CONSTRAINT password_reset_tokens_pkey PRIMARY KEY (email);


--
-- Name: person_clusters person_clusters_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.person_clusters
    ADD CONSTRAINT person_clusters_pkey PRIMARY KEY (id);


--
-- Name: rag_chunk_hypotheticals rag_chunk_hypotheticals_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rag_chunk_hypotheticals
    ADD CONSTRAINT rag_chunk_hypotheticals_pkey PRIMARY KEY (id);


--
-- Name: rag_dedup_log rag_dedup_log_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rag_dedup_log
    ADD CONSTRAINT rag_dedup_log_pkey PRIMARY KEY (id);


--
-- Name: rag_documents rag_documents_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rag_documents
    ADD CONSTRAINT rag_documents_pkey PRIMARY KEY (id);


--
-- Name: rag_evaluations rag_evaluations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rag_evaluations
    ADD CONSTRAINT rag_evaluations_pkey PRIMARY KEY (id);


--
-- Name: rag_propositions rag_propositions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rag_propositions
    ADD CONSTRAINT rag_propositions_pkey PRIMARY KEY (id);


--
-- Name: rag_query_traces rag_query_traces_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rag_query_traces
    ADD CONSTRAINT rag_query_traces_pkey PRIMARY KEY (id);


--
-- Name: rag_sentence_embeddings rag_sentence_embeddings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rag_sentence_embeddings
    ADD CONSTRAINT rag_sentence_embeddings_pkey PRIMARY KEY (id);


--
-- Name: raptor_summaries raptor_summaries_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.raptor_summaries
    ADD CONSTRAINT raptor_summaries_pkey PRIMARY KEY (id);


--
-- Name: research_cache research_cache_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.research_cache
    ADD CONSTRAINT research_cache_pkey PRIMARY KEY (id);


--
-- Name: research_cache research_cache_source_id_query_hash_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.research_cache
    ADD CONSTRAINT research_cache_source_id_query_hash_key UNIQUE (source_id, query_hash);


--
-- Name: research_facts research_facts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.research_facts
    ADD CONSTRAINT research_facts_pkey PRIMARY KEY (id);


--
-- Name: research_missions research_missions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.research_missions
    ADD CONSTRAINT research_missions_pkey PRIMARY KEY (id);


--
-- Name: research_rejected_facts research_rejected_facts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.research_rejected_facts
    ADD CONSTRAINT research_rejected_facts_pkey PRIMARY KEY (fact_hash);


--
-- Name: research_rejections research_rejections_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.research_rejections
    ADD CONSTRAINT research_rejections_pkey PRIMARY KEY (id);


--
-- Name: research_results research_results_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.research_results
    ADD CONSTRAINT research_results_pkey PRIMARY KEY (id);


--
-- Name: research_source_results research_source_results_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.research_source_results
    ADD CONSTRAINT research_source_results_pkey PRIMARY KEY (id);


--
-- Name: research_sources research_sources_base_url_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.research_sources
    ADD CONSTRAINT research_sources_base_url_key UNIQUE (base_url);


--
-- Name: research_sources research_sources_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.research_sources
    ADD CONSTRAINT research_sources_pkey PRIMARY KEY (id);


--
-- Name: research_topics research_topics_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.research_topics
    ADD CONSTRAINT research_topics_pkey PRIMARY KEY (id);


--
-- Name: sessions sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (id);


--
-- Name: source_credibility source_credibility_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.source_credibility
    ADD CONSTRAINT source_credibility_pkey PRIMARY KEY (id);


--
-- Name: source_discovery_patterns source_discovery_patterns_pattern_hash_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.source_discovery_patterns
    ADD CONSTRAINT source_discovery_patterns_pattern_hash_key UNIQUE (pattern_hash);


--
-- Name: source_discovery_patterns source_discovery_patterns_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.source_discovery_patterns
    ADD CONSTRAINT source_discovery_patterns_pkey PRIMARY KEY (id);


--
-- Name: source_performance_feedback source_performance_feedback_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.source_performance_feedback
    ADD CONSTRAINT source_performance_feedback_pkey PRIMARY KEY (id);


--
-- Name: genealogy_research_fact_links uniq_grfl_fact_person_type; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.genealogy_research_fact_links
    ADD CONSTRAINT uniq_grfl_fact_person_type UNIQUE (research_fact_id, genealogy_person_id, fact_type);


--
-- Name: rag_sentence_embeddings unique_doc_sentence; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rag_sentence_embeddings
    ADD CONSTRAINT unique_doc_sentence UNIQUE (document_id, sentence_index);


--
-- Name: discovered_sources uq_discovered_sources_domain; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.discovered_sources
    ADD CONSTRAINT uq_discovered_sources_domain UNIQUE (domain);


--
-- Name: users users_email_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_email_unique UNIQUE (email);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: verdicts verdicts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.verdicts
    ADD CONSTRAINT verdicts_pkey PRIMARY KEY (id);


--
-- Name: verification_attempts verification_attempts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.verification_attempts
    ADD CONSTRAINT verification_attempts_pkey PRIMARY KEY (id);


--
-- Name: idx_aee_agent_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_aee_agent_id ON public.agent_episode_embeddings USING btree (agent_id);


--
-- Name: idx_aee_embedding_hnsw; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_aee_embedding_hnsw ON public.agent_episode_embeddings USING hnsw (embedding public.vector_cosine_ops) WITH (m='16', ef_construction='64');


--
-- Name: idx_aee_summary_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_aee_summary_id ON public.agent_episode_embeddings USING btree (summary_id);


--
-- Name: idx_ape_agent_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_ape_agent_id ON public.agent_procedure_embeddings USING btree (agent_id);


--
-- Name: idx_ape_embedding_hnsw; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_ape_embedding_hnsw ON public.agent_procedure_embeddings USING hnsw (embedding public.vector_cosine_ops) WITH (m='16', ef_construction='64');


--
-- Name: idx_ape_procedure_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_ape_procedure_id ON public.agent_procedure_embeddings USING btree (procedure_id);


--
-- Name: idx_benchmark_claims_correct; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_benchmark_claims_correct ON public.fact_check_benchmark_claims USING btree (correct);


--
-- Name: idx_benchmark_claims_run; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_benchmark_claims_run ON public.fact_check_benchmark_claims USING btree (run_id);


--
-- Name: idx_benchmark_runs_created; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_benchmark_runs_created ON public.fact_check_benchmark_runs USING btree (created_at);


--
-- Name: idx_claims_checkworthiness; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_claims_checkworthiness ON public.claims USING btree (checkworthiness_score DESC) WHERE (checkworthiness_score >= 0.5);


--
-- Name: idx_claims_created; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_claims_created ON public.claims USING btree (created_at DESC);


--
-- Name: idx_claims_entities; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_claims_entities ON public.claims USING gin (entities);


--
-- Name: idx_claims_source_doc; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_claims_source_doc ON public.claims USING btree (source_document_id);


--
-- Name: idx_consensus_verdicts_claim_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_consensus_verdicts_claim_id ON public.consensus_verdicts USING btree (claim_id);


--
-- Name: idx_consensus_verdicts_consensus_verdict; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_consensus_verdicts_consensus_verdict ON public.consensus_verdicts USING btree (consensus_verdict);


--
-- Name: idx_contradictions_claim; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_contradictions_claim ON public.contradictions USING btree (claim_id) WHERE (claim_id IS NOT NULL);


--
-- Name: idx_contradictions_doc_severity; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_contradictions_doc_severity ON public.contradictions USING btree (claim_id, severity);


--
-- Name: idx_contradictions_evidence; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_contradictions_evidence ON public.contradictions USING btree (evidence_id) WHERE (evidence_id IS NOT NULL);


--
-- Name: idx_contradictions_review_queue; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_contradictions_review_queue ON public.contradictions USING btree (severity DESC, created_at DESC) WHERE (human_reviewed = false);


--
-- Name: idx_contradictions_reviewed; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_contradictions_reviewed ON public.contradictions USING btree (reviewed_at DESC) WHERE (human_reviewed = true);


--
-- Name: idx_contradictions_types; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_contradictions_types ON public.contradictions USING gin (contradiction_types);


--
-- Name: idx_dedup_log_action; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_dedup_log_action ON public.rag_dedup_log USING btree (action_taken);


--
-- Name: idx_dedup_log_created; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_dedup_log_created ON public.rag_dedup_log USING btree (created_at);


--
-- Name: idx_dedup_log_hash; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_dedup_log_hash ON public.rag_dedup_log USING btree (incoming_content_hash);


--
-- Name: idx_dedup_log_strategy; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_dedup_log_strategy ON public.rag_dedup_log USING btree (strategy);


--
-- Name: idx_discovery_rules_category; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_discovery_rules_category ON public.discovery_rules USING btree (domain_category, is_active);


--
-- Name: idx_discovery_rules_priority; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_discovery_rules_priority ON public.discovery_rules USING btree (priority, rule_type);


--
-- Name: idx_discovery_rules_type_active; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_discovery_rules_type_active ON public.discovery_rules USING btree (rule_type, is_active);


--
-- Name: idx_doc_stats_type; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_doc_stats_type ON public.document_statistics USING btree (document_type);


--
-- Name: idx_edge_history_caused_by; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_edge_history_caused_by ON public.knowledge_graph_edge_history USING btree (caused_by_triple_id) WHERE (caused_by_triple_id IS NOT NULL);


--
-- Name: idx_edge_history_triple; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_edge_history_triple ON public.knowledge_graph_edge_history USING btree (triple_id, created_at);


--
-- Name: idx_evidence_claim; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_evidence_claim ON public.evidence USING btree (claim_id, nli_label);


--
-- Name: idx_evidence_credibility; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_evidence_credibility ON public.evidence USING btree (credibility_score DESC);


--
-- Name: idx_evidence_domain; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_evidence_domain ON public.evidence USING btree (source_domain);


--
-- Name: idx_evidence_nli; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_evidence_nli ON public.evidence USING btree (nli_label, nli_score DESC);


--
-- Name: idx_face_embeddings_cluster; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_face_embeddings_cluster ON public.face_embeddings USING btree (person_cluster_id);


--
-- Name: idx_face_embeddings_confidence; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_face_embeddings_confidence ON public.face_embeddings USING btree (match_confidence);


--
-- Name: idx_face_embeddings_file; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_face_embeddings_file ON public.face_embeddings USING btree (file_registry_id);


--
-- Name: idx_face_embeddings_frf_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_face_embeddings_frf_id ON public.face_embeddings USING btree (file_registry_face_id);


--
-- Name: idx_face_embeddings_frf_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_face_embeddings_frf_unique ON public.face_embeddings USING btree (file_registry_face_id) WHERE (file_registry_face_id IS NOT NULL);


--
-- Name: idx_face_embeddings_vector; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_face_embeddings_vector ON public.face_embeddings USING hnsw (embedding public.vector_cosine_ops) WITH (m='16', ef_construction='64');


--
-- Name: idx_face_match_candidates_confidence; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_face_match_candidates_confidence ON public.face_match_candidates USING btree (confidence DESC);


--
-- Name: idx_face_match_candidates_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_face_match_candidates_status ON public.face_match_candidates USING btree (status);


--
-- Name: idx_facts_rejected_hash; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_facts_rejected_hash ON public.research_facts USING btree (fact_hash) WHERE ((review_status)::text = 'rejected'::text);


--
-- Name: idx_facts_review_queue; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_facts_review_queue ON public.research_facts USING btree (review_status, confidence_score DESC, created_at DESC) WHERE ((review_status)::text = 'pending'::text);


--
-- Name: idx_fcpr_created; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_fcpr_created ON public.fact_check_pipeline_runs USING btree (created_at);


--
-- Name: idx_fcpr_pipeline; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_fcpr_pipeline ON public.fact_check_pipeline_runs USING btree (pipeline_id);


--
-- Name: idx_fcpr_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_fcpr_status ON public.fact_check_pipeline_runs USING btree (status);


--
-- Name: idx_fse_file; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_fse_file ON public.file_semantic_embeddings USING btree (file_id);


--
-- Name: idx_genealogy_person_embedding; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_genealogy_person_embedding ON public.genealogy_person_embeddings USING hnsw (embedding public.vector_cosine_ops) WITH (m='32', ef_construction='128');


--
-- Name: idx_genealogy_person_fts; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_genealogy_person_fts ON public.genealogy_person_embeddings USING gin (to_tsvector('english'::regconfig, COALESCE(search_text, ''::text)));


--
-- Name: idx_genealogy_person_surname; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_genealogy_person_surname ON public.genealogy_person_embeddings USING btree (surname);


--
-- Name: idx_genealogy_person_tree; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_genealogy_person_tree ON public.genealogy_person_embeddings USING btree (tree_id);


--
-- Name: idx_grfl_fact; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_grfl_fact ON public.genealogy_research_fact_links USING btree (research_fact_id);


--
-- Name: idx_grfl_person; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_grfl_person ON public.genealogy_research_fact_links USING btree (genealogy_person_id);


--
-- Name: idx_grfl_person_type; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_grfl_person_type ON public.genealogy_research_fact_links USING btree (genealogy_person_id, fact_type);


--
-- Name: idx_kg_active; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_kg_active ON public.knowledge_graph USING btree (subject_entity_id, object_entity_id) WHERE (t_expired IS NULL);


--
-- Name: idx_kg_confidence; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_kg_confidence ON public.knowledge_graph USING btree (confidence DESC) WHERE (confidence >= 0.7);


--
-- Name: idx_kg_entities_canonical; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_kg_entities_canonical ON public.knowledge_graph_entities USING btree (lower(canonical_name), entity_type);


--
-- Name: idx_kg_entities_type; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_kg_entities_type ON public.knowledge_graph_entities USING btree (entity_type);


--
-- Name: idx_kg_expired; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_kg_expired ON public.knowledge_graph USING btree (t_expired) WHERE (t_expired IS NOT NULL);


--
-- Name: idx_kg_hyperedges_document; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_kg_hyperedges_document ON public.knowledge_graph_hyperedges USING btree (source_document_id);


--
-- Name: idx_kg_hyperedges_participants; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_kg_hyperedges_participants ON public.knowledge_graph_hyperedges USING gin (participants);


--
-- Name: idx_kg_hyperedges_predicate; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_kg_hyperedges_predicate ON public.knowledge_graph_hyperedges USING btree (predicate);


--
-- Name: idx_kg_object; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_kg_object ON public.knowledge_graph USING btree (lower(object));


--
-- Name: idx_kg_object_entity; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_kg_object_entity ON public.knowledge_graph USING btree (object_entity_id) WHERE (object_entity_id IS NOT NULL);


--
-- Name: idx_kg_object_fts; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_kg_object_fts ON public.knowledge_graph USING gin (to_tsvector('english'::regconfig, object));


--
-- Name: idx_kg_predicate; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_kg_predicate ON public.knowledge_graph USING btree (predicate);


--
-- Name: idx_kg_quality_runs_created; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_kg_quality_runs_created ON public.kg_quality_runs USING btree (created_at DESC);


--
-- Name: idx_kg_source_doc; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_kg_source_doc ON public.knowledge_graph USING btree (source_document_id) WHERE (source_document_id IS NOT NULL);


--
-- Name: idx_kg_subject; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_kg_subject ON public.knowledge_graph USING btree (lower(subject));


--
-- Name: idx_kg_subject_entity; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_kg_subject_entity ON public.knowledge_graph USING btree (subject_entity_id) WHERE (subject_entity_id IS NOT NULL);


--
-- Name: idx_kg_subject_fts; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_kg_subject_fts ON public.knowledge_graph USING gin (to_tsvector('english'::regconfig, subject));


--
-- Name: idx_kg_triple_lookup; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_kg_triple_lookup ON public.knowledge_graph USING btree (lower(subject), predicate, lower(object));


--
-- Name: idx_kg_valid_range; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_kg_valid_range ON public.knowledge_graph USING btree (valid_from, valid_until) WHERE (valid_from IS NOT NULL);


--
-- Name: idx_kgc_community_level; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_kgc_community_level ON public.knowledge_graph_communities USING btree (community_id, level);


--
-- Name: idx_kgc_detection_run; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_kgc_detection_run ON public.knowledge_graph_communities USING btree (detection_run_id);


--
-- Name: idx_kgc_level; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_kgc_level ON public.knowledge_graph_communities USING btree (level);


--
-- Name: idx_kgc_parent; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_kgc_parent ON public.knowledge_graph_communities USING btree (parent_community_id);


--
-- Name: idx_kgcr_community; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_kgcr_community ON public.knowledge_graph_community_reports USING btree (community_id);


--
-- Name: idx_kgcr_embedding; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_kgcr_embedding ON public.knowledge_graph_community_reports USING hnsw (embedding public.vector_cosine_ops) WITH (m='16', ef_construction='64');


--
-- Name: idx_kgcr_level; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_kgcr_level ON public.knowledge_graph_community_reports USING btree (level);


--
-- Name: idx_kge_community; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_kge_community ON public.knowledge_graph_entities USING btree (primary_community_id);


--
-- Name: idx_kge_pagerank; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_kge_pagerank ON public.knowledge_graph_entities USING btree (pagerank DESC);


--
-- Name: idx_kgec_bridge; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_kgec_bridge ON public.knowledge_graph_entity_communities USING btree (is_bridge) WHERE (is_bridge = true);


--
-- Name: idx_kgec_community; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_kgec_community ON public.knowledge_graph_entity_communities USING btree (community_id);


--
-- Name: idx_kgec_entity; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_kgec_entity ON public.knowledge_graph_entity_communities USING btree (entity_id);


--
-- Name: idx_kgee_embedding_hnsw; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_kgee_embedding_hnsw ON public.knowledge_graph_entity_embeddings USING hnsw (embedding public.vector_cosine_ops) WITH (m='16', ef_construction='64');


--
-- Name: idx_kgee_entity_type; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_kgee_entity_type ON public.knowledge_graph_entity_embeddings USING btree (entity_type);


--
-- Name: idx_missions_recurring; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_missions_recurring ON public.research_missions USING btree (frequency, next_run_at, is_active) WHERE (((frequency)::text <> 'once'::text) AND (is_active = true));


--
-- Name: idx_patterns_category_active; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_patterns_category_active ON public.source_discovery_patterns USING btree (domain_category, is_active);


--
-- Name: idx_patterns_confidence; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_patterns_confidence ON public.source_discovery_patterns USING btree (confidence_score DESC);


--
-- Name: idx_patterns_last_used; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_patterns_last_used ON public.source_discovery_patterns USING btree (last_used_at DESC);


--
-- Name: idx_patterns_method; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_patterns_method ON public.source_discovery_patterns USING btree (discovery_method);


--
-- Name: idx_patterns_success_rate; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_patterns_success_rate ON public.source_discovery_patterns USING btree (success_rate_pct DESC NULLS LAST);


--
-- Name: idx_person_clusters_centroid; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_person_clusters_centroid ON public.person_clusters USING hnsw (centroid public.vector_cosine_ops) WITH (m='16', ef_construction='64');


--
-- Name: idx_person_clusters_face_count; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_person_clusters_face_count ON public.person_clusters USING btree (face_count DESC);


--
-- Name: idx_person_clusters_genealogy; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_person_clusters_genealogy ON public.person_clusters USING btree (genealogy_person_id);


--
-- Name: idx_person_clusters_last_optimized; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_person_clusters_last_optimized ON public.person_clusters USING btree (last_optimized_at) WHERE (last_optimized_at IS NOT NULL);


--
-- Name: idx_person_clusters_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_person_clusters_status ON public.person_clusters USING btree (status);


--
-- Name: idx_query_traces_created; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_query_traces_created ON public.rag_query_traces USING btree (created_at);


--
-- Name: idx_query_traces_strategy; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_query_traces_strategy ON public.rag_query_traces USING btree (strategy_used);


--
-- Name: idx_rag_content_fts; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_rag_content_fts ON public.rag_documents USING gin (to_tsvector('english'::regconfig, content));


--
-- Name: idx_rag_content_hash; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_rag_content_hash ON public.rag_documents USING btree (content_hash);


--
-- Name: idx_rag_created_desc; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_rag_created_desc ON public.rag_documents USING btree (created_at DESC);


--
-- Name: idx_rag_dedup_log_created; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_rag_dedup_log_created ON public.rag_dedup_log USING btree (created_at);


--
-- Name: idx_rag_dedup_log_hash; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_rag_dedup_log_hash ON public.rag_dedup_log USING btree (incoming_content_hash);


--
-- Name: idx_rag_dedup_log_strategy; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_rag_dedup_log_strategy ON public.rag_dedup_log USING btree (strategy);


--
-- Name: idx_rag_designation; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_rag_designation ON public.rag_documents USING btree (designation);


--
-- Name: idx_rag_document_type_created; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_rag_document_type_created ON public.rag_documents USING btree (document_type, created_at DESC);


--
-- Name: idx_rag_documents_content_hash; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_rag_documents_content_hash ON public.rag_documents USING btree (content_hash);


--
-- Name: idx_rag_documents_contextualized_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_rag_documents_contextualized_at ON public.rag_documents USING btree (contextualized_at) WHERE (contextualized_at IS NULL);


--
-- Name: idx_rag_documents_dedup_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_rag_documents_dedup_status ON public.rag_documents USING btree (dedup_status);


--
-- Name: idx_rag_documents_parent_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_rag_documents_parent_id ON public.rag_documents USING btree (parent_id) WHERE (parent_id IS NOT NULL);


--
-- Name: idx_rag_documents_raptor_indexed; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_rag_documents_raptor_indexed ON public.rag_documents USING btree (raptor_indexed_at) WHERE (raptor_indexed_at IS NULL);


--
-- Name: idx_rag_documents_sentence_positions; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_rag_documents_sentence_positions ON public.rag_documents USING gin (sentence_positions) WHERE (sentence_positions IS NOT NULL);


--
-- Name: idx_rag_embedding_hnsw_optimized; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_rag_embedding_hnsw_optimized ON public.rag_documents USING hnsw (embedding public.vector_cosine_ops) WITH (m='48', ef_construction='256');


--
-- Name: idx_rag_evaluations_date; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_rag_evaluations_date ON public.rag_evaluations USING btree (evaluated_at);


--
-- Name: idx_rag_evaluations_score; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_rag_evaluations_score ON public.rag_evaluations USING btree (overall_score);


--
-- Name: idx_rag_has_visual_content; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_rag_has_visual_content ON public.rag_documents USING btree (has_visual_content) WHERE (has_visual_content = true);


--
-- Name: idx_rag_image_embedding_hnsw; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_rag_image_embedding_hnsw ON public.rag_documents USING hnsw (image_embedding public.vector_cosine_ops) WITH (m='32', ef_construction='128');


--
-- Name: idx_rag_parent_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_rag_parent_id ON public.rag_documents USING btree (parent_id);


--
-- Name: idx_rag_propositions_doc; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_rag_propositions_doc ON public.rag_propositions USING btree (document_id);


--
-- Name: idx_rag_propositions_subject; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_rag_propositions_subject ON public.rag_propositions USING btree (subject);


--
-- Name: idx_rag_query_traces_created; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_rag_query_traces_created ON public.rag_query_traces USING btree (created_at);


--
-- Name: idx_rag_query_traces_strategy; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_rag_query_traces_strategy ON public.rag_query_traces USING btree (strategy_used);


--
-- Name: idx_rag_sentence_embeddings_document; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_rag_sentence_embeddings_document ON public.rag_sentence_embeddings USING btree (document_id);


--
-- Name: idx_rag_source; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_rag_source ON public.rag_documents USING btree (source_id, source_type);


--
-- Name: idx_raptor_document_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_raptor_document_id ON public.raptor_summaries USING btree (document_id);


--
-- Name: idx_raptor_embedding_hnsw; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_raptor_embedding_hnsw ON public.raptor_summaries USING hnsw (embedding public.vector_cosine_ops) WITH (m='16', ef_construction='64');


--
-- Name: idx_raptor_level; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_raptor_level ON public.raptor_summaries USING btree (document_id, level);


--
-- Name: idx_raptor_parent_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_raptor_parent_id ON public.raptor_summaries USING btree (parent_summary_id) WHERE (parent_summary_id IS NOT NULL);


--
-- Name: idx_rch_document_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_rch_document_id ON public.rag_chunk_hypotheticals USING btree (document_id);


--
-- Name: idx_rejected_facts_hash; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_rejected_facts_hash ON public.research_rejected_facts USING btree (fact_hash);


--
-- Name: idx_rejected_facts_mission; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_rejected_facts_mission ON public.research_rejected_facts USING btree (mission_id);


--
-- Name: idx_research_cache_expires; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_research_cache_expires ON public.research_cache USING btree (expires_at);


--
-- Name: idx_research_cache_hash; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_research_cache_hash ON public.research_cache USING btree (query_hash);


--
-- Name: idx_research_cache_person; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_research_cache_person ON public.research_cache USING btree (person_id) WHERE (person_id IS NOT NULL);


--
-- Name: idx_research_cache_source; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_research_cache_source ON public.research_cache USING btree (source_id);


--
-- Name: idx_research_cache_tree; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_research_cache_tree ON public.research_cache USING btree (tree_id) WHERE (tree_id IS NOT NULL);


--
-- Name: idx_research_category; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_research_category ON public.research_sources USING btree (research_category);


--
-- Name: idx_research_rejections_lookup; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_research_rejections_lookup ON public.research_rejections USING btree (content_hash);


--
-- Name: idx_research_rejections_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_research_rejections_unique ON public.research_rejections USING btree (research_topic_id, content_hash);


--
-- Name: idx_research_results_content_hash; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_research_results_content_hash ON public.research_results USING btree (content_hash) WHERE (content_hash IS NOT NULL);


--
-- Name: idx_research_results_topic_hash; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_research_results_topic_hash ON public.research_results USING btree (research_topic_id, content_hash) WHERE (content_hash IS NOT NULL);


--
-- Name: idx_research_results_topic_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_research_results_topic_status ON public.research_results USING btree (research_topic_id, status);


--
-- Name: idx_research_source_results_hash; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_research_source_results_hash ON public.research_source_results USING btree (content_hash);


--
-- Name: idx_research_source_results_topic; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_research_source_results_topic ON public.research_source_results USING btree (research_topic_id, scraped_at DESC);


--
-- Name: idx_research_sources_active; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_research_sources_active ON public.research_sources USING btree (is_active, trust_score DESC);


--
-- Name: idx_research_sources_categories; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_research_sources_categories ON public.research_sources USING gin (categories);


--
-- Name: idx_research_sources_search_engines; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_research_sources_search_engines ON public.research_sources USING btree (is_search_engine, is_active);


--
-- Name: idx_research_topics_scheduling; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_research_topics_scheduling ON public.research_topics USING btree (is_active, frequency, last_ran_at);


--
-- Name: idx_research_topics_source; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_research_topics_source ON public.research_topics USING btree (source);


--
-- Name: idx_semantic_cache_context; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_semantic_cache_context ON public.ai_semantic_cache USING btree (context_hash);


--
-- Name: idx_semantic_cache_created; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_semantic_cache_created ON public.ai_semantic_cache USING btree (created_at);


--
-- Name: idx_semantic_cache_embedding; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_semantic_cache_embedding ON public.ai_semantic_cache USING hnsw (embedding public.vector_cosine_ops);


--
-- Name: idx_semantic_cache_prompt_hash; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_semantic_cache_prompt_hash ON public.ai_semantic_cache USING btree (prompt_hash);


--
-- Name: idx_source_credibility_composite; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_source_credibility_composite ON public.source_credibility USING btree (composite_score DESC NULLS LAST);


--
-- Name: idx_source_credibility_domain; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_source_credibility_domain ON public.source_credibility USING btree (domain);


--
-- Name: idx_source_credibility_domain_only; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_source_credibility_domain_only ON public.source_credibility USING btree (domain) WHERE (url IS NULL);


--
-- Name: idx_source_credibility_domain_url; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_source_credibility_domain_url ON public.source_credibility USING btree (domain, COALESCE(url, ''::text));


--
-- Name: idx_source_credibility_tier; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_source_credibility_tier ON public.source_credibility USING btree (tier) WHERE (tier IS NOT NULL);


--
-- Name: idx_source_credibility_url; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_source_credibility_url ON public.source_credibility USING btree (url) WHERE (url IS NOT NULL);


--
-- Name: idx_source_credibility_verification; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_source_credibility_verification ON public.source_credibility USING btree (verification_result) WHERE (verification_result IS NOT NULL);


--
-- Name: idx_source_feedback_category; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_source_feedback_category ON public.source_performance_feedback USING btree (research_category);


--
-- Name: idx_source_feedback_domain; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_source_feedback_domain ON public.source_performance_feedback USING btree (source_domain);


--
-- Name: idx_source_feedback_mission; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_source_feedback_mission ON public.source_performance_feedback USING btree (mission_id);


--
-- Name: idx_source_feedback_rated_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_source_feedback_rated_at ON public.source_performance_feedback USING btree (rated_at DESC);


--
-- Name: idx_source_feedback_source; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_source_feedback_source ON public.source_performance_feedback USING btree (source_id);


--
-- Name: idx_source_feedback_type; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_source_feedback_type ON public.source_performance_feedback USING btree (feedback_type);


--
-- Name: idx_verdicts_by_verdict; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_verdicts_by_verdict ON public.verdicts USING btree (verdict, confidence DESC);


--
-- Name: idx_verdicts_claim_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_verdicts_claim_unique ON public.verdicts USING btree (claim_id);


--
-- Name: idx_verdicts_review_queue; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_verdicts_review_queue ON public.verdicts USING btree (human_reviewed, confidence DESC) WHERE (human_reviewed = false);


--
-- Name: idx_verdicts_reviewed; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_verdicts_reviewed ON public.verdicts USING btree (reviewed_at DESC) WHERE (human_reviewed = true);


--
-- Name: jobs_queue_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX jobs_queue_index ON public.jobs USING btree (queue);


--
-- Name: rag_documents_document_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX rag_documents_document_type_index ON public.rag_documents USING btree (document_type);


--
-- Name: rag_documents_source_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX rag_documents_source_type_index ON public.rag_documents USING btree (source_type);


--
-- Name: research_facts_fact_hash_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX research_facts_fact_hash_unique ON public.research_facts USING btree (fact_hash);


--
-- Name: research_results_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX research_results_created_at_index ON public.research_results USING btree (created_at);


--
-- Name: research_results_rag_indexed_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX research_results_rag_indexed_at_index ON public.research_results USING btree (rag_indexed_at);


--
-- Name: research_results_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX research_results_status_index ON public.research_results USING btree (status);


--
-- Name: research_topics_frequency_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX research_topics_frequency_index ON public.research_topics USING btree (frequency);


--
-- Name: research_topics_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX research_topics_is_active_index ON public.research_topics USING btree (is_active);


--
-- Name: research_topics_last_ran_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX research_topics_last_ran_at_index ON public.research_topics USING btree (last_ran_at);


--
-- Name: sessions_last_activity_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sessions_last_activity_index ON public.sessions USING btree (last_activity);


--
-- Name: sessions_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sessions_user_id_index ON public.sessions USING btree (user_id);


--
-- Name: verdicts trg_verdict_factuality; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_verdict_factuality BEFORE INSERT OR UPDATE OF supporting_count, contradicting_count ON public.verdicts FOR EACH ROW EXECUTE FUNCTION public.update_verdict_factuality();


--
-- Name: rag_documents update_rag_documents_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER update_rag_documents_updated_at BEFORE UPDATE ON public.rag_documents FOR EACH ROW EXECUTE FUNCTION public.update_updated_at_column();


--
-- Name: consensus_verdicts consensus_verdicts_claim_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.consensus_verdicts
    ADD CONSTRAINT consensus_verdicts_claim_id_fkey FOREIGN KEY (claim_id) REFERENCES public.claims(id) ON DELETE CASCADE;


--
-- Name: contradictions contradictions_claim_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.contradictions
    ADD CONSTRAINT contradictions_claim_id_fkey FOREIGN KEY (claim_id) REFERENCES public.claims(id) ON DELETE SET NULL;


--
-- Name: contradictions contradictions_evidence_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.contradictions
    ADD CONSTRAINT contradictions_evidence_id_fkey FOREIGN KEY (evidence_id) REFERENCES public.evidence(id) ON DELETE SET NULL;


--
-- Name: evidence evidence_claim_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.evidence
    ADD CONSTRAINT evidence_claim_id_fkey FOREIGN KEY (claim_id) REFERENCES public.claims(id) ON DELETE CASCADE;


--
-- Name: face_embeddings face_embeddings_matched_face_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.face_embeddings
    ADD CONSTRAINT face_embeddings_matched_face_id_fkey FOREIGN KEY (matched_face_id) REFERENCES public.face_embeddings(id);


--
-- Name: face_embeddings face_embeddings_person_cluster_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.face_embeddings
    ADD CONSTRAINT face_embeddings_person_cluster_id_fkey FOREIGN KEY (person_cluster_id) REFERENCES public.person_clusters(id);


--
-- Name: face_match_candidates face_match_candidates_candidate_cluster_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.face_match_candidates
    ADD CONSTRAINT face_match_candidates_candidate_cluster_id_fkey FOREIGN KEY (candidate_cluster_id) REFERENCES public.person_clusters(id) ON DELETE CASCADE;


--
-- Name: face_match_candidates face_match_candidates_candidate_face_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.face_match_candidates
    ADD CONSTRAINT face_match_candidates_candidate_face_id_fkey FOREIGN KEY (candidate_face_id) REFERENCES public.face_embeddings(id) ON DELETE SET NULL;


--
-- Name: face_match_candidates face_match_candidates_face_embedding_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.face_match_candidates
    ADD CONSTRAINT face_match_candidates_face_embedding_id_fkey FOREIGN KEY (face_embedding_id) REFERENCES public.face_embeddings(id) ON DELETE CASCADE;


--
-- Name: research_rejections fk_rejection_topic; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.research_rejections
    ADD CONSTRAINT fk_rejection_topic FOREIGN KEY (research_topic_id) REFERENCES public.research_topics(id) ON DELETE CASCADE;


--
-- Name: knowledge_graph_communities knowledge_graph_communities_detection_run_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.knowledge_graph_communities
    ADD CONSTRAINT knowledge_graph_communities_detection_run_id_fkey FOREIGN KEY (detection_run_id) REFERENCES public.knowledge_graph_detection_runs(id) ON DELETE CASCADE;


--
-- Name: knowledge_graph_communities knowledge_graph_communities_parent_community_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.knowledge_graph_communities
    ADD CONSTRAINT knowledge_graph_communities_parent_community_id_fkey FOREIGN KEY (parent_community_id) REFERENCES public.knowledge_graph_communities(id) ON DELETE SET NULL;


--
-- Name: knowledge_graph_community_reports knowledge_graph_community_reports_community_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.knowledge_graph_community_reports
    ADD CONSTRAINT knowledge_graph_community_reports_community_id_fkey FOREIGN KEY (community_id) REFERENCES public.knowledge_graph_communities(id) ON DELETE CASCADE;


--
-- Name: knowledge_graph_community_reports knowledge_graph_community_reports_detection_run_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.knowledge_graph_community_reports
    ADD CONSTRAINT knowledge_graph_community_reports_detection_run_id_fkey FOREIGN KEY (detection_run_id) REFERENCES public.knowledge_graph_detection_runs(id) ON DELETE CASCADE;


--
-- Name: knowledge_graph_edge_history knowledge_graph_edge_history_caused_by_triple_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.knowledge_graph_edge_history
    ADD CONSTRAINT knowledge_graph_edge_history_caused_by_triple_id_fkey FOREIGN KEY (caused_by_triple_id) REFERENCES public.knowledge_graph(id) ON DELETE SET NULL;


--
-- Name: knowledge_graph_edge_history knowledge_graph_edge_history_triple_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.knowledge_graph_edge_history
    ADD CONSTRAINT knowledge_graph_edge_history_triple_id_fkey FOREIGN KEY (triple_id) REFERENCES public.knowledge_graph(id) ON DELETE CASCADE;


--
-- Name: knowledge_graph_entities knowledge_graph_entities_primary_community_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.knowledge_graph_entities
    ADD CONSTRAINT knowledge_graph_entities_primary_community_id_fkey FOREIGN KEY (primary_community_id) REFERENCES public.knowledge_graph_communities(id) ON DELETE SET NULL;


--
-- Name: knowledge_graph_entity_communities knowledge_graph_entity_communities_community_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.knowledge_graph_entity_communities
    ADD CONSTRAINT knowledge_graph_entity_communities_community_id_fkey FOREIGN KEY (community_id) REFERENCES public.knowledge_graph_communities(id) ON DELETE CASCADE;


--
-- Name: knowledge_graph_entity_communities knowledge_graph_entity_communities_entity_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.knowledge_graph_entity_communities
    ADD CONSTRAINT knowledge_graph_entity_communities_entity_id_fkey FOREIGN KEY (entity_id) REFERENCES public.knowledge_graph_entities(id) ON DELETE CASCADE;


--
-- Name: knowledge_graph knowledge_graph_object_entity_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.knowledge_graph
    ADD CONSTRAINT knowledge_graph_object_entity_id_fkey FOREIGN KEY (object_entity_id) REFERENCES public.knowledge_graph_entities(id) ON DELETE SET NULL;


--
-- Name: knowledge_graph knowledge_graph_source_document_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.knowledge_graph
    ADD CONSTRAINT knowledge_graph_source_document_id_fkey FOREIGN KEY (source_document_id) REFERENCES public.rag_documents(id) ON DELETE SET NULL;


--
-- Name: knowledge_graph knowledge_graph_subject_entity_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.knowledge_graph
    ADD CONSTRAINT knowledge_graph_subject_entity_id_fkey FOREIGN KEY (subject_entity_id) REFERENCES public.knowledge_graph_entities(id) ON DELETE SET NULL;


--
-- Name: knowledge_graph knowledge_graph_superseded_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.knowledge_graph
    ADD CONSTRAINT knowledge_graph_superseded_by_fkey FOREIGN KEY (superseded_by) REFERENCES public.knowledge_graph(id) ON DELETE SET NULL;


--
-- Name: person_clusters person_clusters_merged_into_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.person_clusters
    ADD CONSTRAINT person_clusters_merged_into_id_fkey FOREIGN KEY (merged_into_id) REFERENCES public.person_clusters(id);


--
-- Name: rag_sentence_embeddings rag_sentence_embeddings_document_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rag_sentence_embeddings
    ADD CONSTRAINT rag_sentence_embeddings_document_id_fkey FOREIGN KEY (document_id) REFERENCES public.rag_documents(id) ON DELETE CASCADE;


--
-- Name: raptor_summaries raptor_summaries_document_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.raptor_summaries
    ADD CONSTRAINT raptor_summaries_document_id_fkey FOREIGN KEY (document_id) REFERENCES public.rag_documents(id) ON DELETE CASCADE;


--
-- Name: raptor_summaries raptor_summaries_parent_summary_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.raptor_summaries
    ADD CONSTRAINT raptor_summaries_parent_summary_id_fkey FOREIGN KEY (parent_summary_id) REFERENCES public.raptor_summaries(id) ON DELETE CASCADE;


--
-- Name: research_cache research_cache_source_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.research_cache
    ADD CONSTRAINT research_cache_source_id_fkey FOREIGN KEY (source_id) REFERENCES public.research_sources(id) ON DELETE CASCADE;


--
-- Name: research_facts research_facts_mission_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.research_facts
    ADD CONSTRAINT research_facts_mission_id_fkey FOREIGN KEY (mission_id) REFERENCES public.research_missions(id) ON DELETE SET NULL;


--
-- Name: research_facts research_facts_primary_source_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.research_facts
    ADD CONSTRAINT research_facts_primary_source_id_fkey FOREIGN KEY (primary_source_id) REFERENCES public.discovered_sources(id) ON DELETE SET NULL;


--
-- Name: research_results research_results_research_topic_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.research_results
    ADD CONSTRAINT research_results_research_topic_id_foreign FOREIGN KEY (research_topic_id) REFERENCES public.research_topics(id) ON DELETE CASCADE;


--
-- Name: research_source_results research_source_results_source_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.research_source_results
    ADD CONSTRAINT research_source_results_source_id_fkey FOREIGN KEY (source_id) REFERENCES public.research_sources(id) ON DELETE CASCADE;


--
-- Name: verdicts verdicts_claim_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.verdicts
    ADD CONSTRAINT verdicts_claim_id_fkey FOREIGN KEY (claim_id) REFERENCES public.claims(id) ON DELETE CASCADE;


--
-- Name: verification_attempts verification_attempts_fact_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.verification_attempts
    ADD CONSTRAINT verification_attempts_fact_id_fkey FOREIGN KEY (fact_id) REFERENCES public.research_facts(id) ON DELETE CASCADE;


--
-- Name: verification_attempts verification_attempts_source_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.verification_attempts
    ADD CONSTRAINT verification_attempts_source_id_fkey FOREIGN KEY (source_id) REFERENCES public.discovered_sources(id) ON DELETE SET NULL;


--
-- PostgreSQL database dump complete
--

\unrestrict 5bWWgawRlbvxxsw9hOeRbUfjqphaVnkkgXdDT4mMPSzKyZL71Wo9shsgr3EIdYY
