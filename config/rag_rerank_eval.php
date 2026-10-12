<?php

return [
    'schema' => 'plos.rag_rerank_eval.v3',
    'embedding_query_instruction' => 'Retrieve the PLOS passage that directly answers the operational question, respecting negation, evidence status, and approval boundaries.',
    'cross_encoder_instruction' => 'Given a web search query, retrieve relevant passages that answer the query',
    'thresholds' => [
        'min_mrr' => 0.68,
        'min_hit_at_1' => 0.375,
        'min_ndcg_at_3' => 0.68,
        'min_recall_at_3' => 0.75,
        'min_precision_at_3' => 0.50,
        'min_map_at_3' => 0.50,
        'max_hard_negative_at_1_rate' => 0.625,
    ],
    'promotion_thresholds' => [
        'min_mrr' => 0.85,
        'min_hit_at_1' => 0.75,
        'min_ndcg_at_3' => 0.82,
        'min_recall_at_3' => 0.875,
        'min_precision_at_3' => 0.58,
        'min_map_at_3' => 0.75,
        'max_hard_negative_at_1_rate' => 0.125,
    ],
    'grounding' => [
        'retriever' => [
            'min_claim_recall_at_3' => 0.75,
            'min_context_precision_at_3' => 0.50,
        ],
        'generator' => [
            'min_claim_precision' => 1.0,
            'min_claim_recall' => 0.75,
            'min_faithfulness' => 1.0,
            'max_hallucination_rate' => 0.0,
            'max_unsupported_citation_rate' => 0.0,
            'min_context_utilization' => 0.75,
        ],
    ],
    'cases' => [
        'genealogy_death_evidence' => [
            'query' => 'Which records prove a person death date and place?',
            'relevance' => ['death_certificate' => 3, 'obituary' => 1],
            'hard_negative_ids' => ['birth_certificate'],
            'claims' => [
                'death_date_and_place' => ['death_certificate', 'obituary'],
                'registrar_provenance' => ['death_certificate'],
            ],
            'documents' => [
                ['id' => 'birth_certificate', 'text' => 'A birth certificate records a date and place of birth, not proof of the later death event.'],
                ['id' => 'death_certificate', 'text' => 'The certified death register identifies the decedent and records the death date and place with registrar provenance.'],
                ['id' => 'obituary', 'text' => 'A contemporary obituary independently reports when and where the person died and cites the newspaper issue.'],
                ['id' => 'family_tree_hint', 'text' => 'An unsourced family tree hint repeats a year without an image or citation.'],
                ['id' => 'weather_digest', 'text' => 'The daily weather digest lists forecast conditions and alerts.'],
            ],
        ],
        'face_identity_boundary' => [
            'query' => 'What evidence may identify a face without automatic person linkage?',
            'relevance' => ['operator_packet' => 3, 'trusted_provenance' => 2],
            'hard_negative_ids' => ['automatic_linker'],
            'claims' => [
                'operator_owns_identity_decision' => ['operator_packet'],
                'trusted_seed_remains_review_only' => ['trusted_provenance'],
            ],
            'documents' => [
                ['id' => 'automatic_linker', 'text' => 'Automatically link the face to a person when a similar name or embedding appears.'],
                ['id' => 'operator_packet', 'text' => 'Stage visual and source evidence in a review packet; only the operator decides the face and person identity.'],
                ['id' => 'trusted_provenance', 'text' => 'A provenance-trusted named seed may support a same-person candidate, but the result remains review-only.'],
                ['id' => 'cluster_metrics', 'text' => 'Cluster telemetry reports distance distributions without asserting identity.'],
                ['id' => 'media_caption', 'text' => 'A generated caption describes clothing and scenery in the photograph.'],
            ],
        ],
        'horizon_d_state_recovery' => [
            'query' => 'How should a stalled Horizon worker in uninterruptible D state be handled?',
            'relevance' => ['d_state_runbook' => 3, 'replacement_supervisor' => 2],
            'hard_negative_ids' => ['kill_loop'],
            'claims' => [
                'd_state_cannot_handle_signals' => ['d_state_runbook'],
                'replacement_workers_require_lease_check' => ['replacement_supervisor'],
            ],
            'documents' => [
                ['id' => 'kill_loop', 'text' => 'Repeatedly send stronger kill signals to the old Horizon PID until the D state disappears.'],
                ['id' => 'd_state_runbook', 'text' => 'An uninterruptible D-state process is blocked in the kernel and cannot handle signals; investigate the underlying I/O wait.'],
                ['id' => 'replacement_supervisor', 'text' => 'Keep the blocked PID isolated, verify it owns no active queue lease, and run healthy replacement workers under normal supervision.'],
                ['id' => 'queue_retry', 'text' => 'Retry a normal failed queue job after its backoff interval.'],
                ['id' => 'schedule_edit', 'text' => 'Change a scheduled job cadence through its table-owned runtime metadata.'],
            ],
        ],
        'knowledge_graph_provenance' => [
            'query' => 'How can missing knowledge graph source provenance be repaired safely?',
            'relevance' => ['exact_link_repair' => 3, 'source_audit' => 2],
            'hard_negative_ids' => ['invented_citation'],
            'claims' => [
                'repair_preserves_source_document' => ['exact_link_repair'],
                'unsupported_links_remain_pending' => ['source_audit'],
            ],
            'documents' => [
                ['id' => 'invented_citation', 'text' => 'Fill missing knowledge graph source provenance by generating a plausible citation from the entity name.'],
                ['id' => 'exact_link_repair', 'text' => 'Repair only exact canonical entity endpoints while preserving the source document and existing provenance fields.'],
                ['id' => 'source_audit', 'text' => 'Audit unresolved provenance separately and leave unsupported links pending rather than inferring a source.'],
                ['id' => 'vector_reindex', 'text' => 'Rebuild document embeddings after a model dimension change.'],
                ['id' => 'graph_layout', 'text' => 'Recompute graph coordinates for the visualization.'],
            ],
        ],
        'lossless_prompt_policy' => [
            'query' => 'Which policy keeps AI prompt details lossless and compression unavailable?',
            'relevance' => ['compression_disabled' => 3, 'lossless_transport' => 2],
            'hard_negative_ids' => ['enable_compression'],
            'claims' => [
                'prompt_compression_is_unavailable' => ['compression_disabled'],
                'original_prompt_is_preserved' => ['lossless_transport'],
            ],
            'documents' => [
                ['id' => 'enable_compression', 'text' => 'Enable AI prompt compression to reduce token cost even when details may be removed.'],
                ['id' => 'compression_disabled', 'text' => 'Prompt compression is disabled and unavailable because lossy rewriting can remove operational details.'],
                ['id' => 'lossless_transport', 'text' => 'The pipeline preserves the original prompt content through routing and does not transparently summarize it.'],
                ['id' => 'output_compaction', 'text' => 'Compact scheduled command output by removing repeated status fields.'],
                ['id' => 'context_window', 'text' => 'A model context window limits the number of accepted input tokens.'],
            ],
        ],
        'jetson_role_admission' => [
            'query' => 'Which local model role is supported on Jetson by production evidence?',
            'relevance' => ['embedding_route' => 3, 'health_route' => 1],
            'hard_negative_ids' => ['thirty_b_chat'],
            'claims' => [
                'jetson_embedding_role_is_supported' => ['embedding_route'],
                'jetson_health_role_is_bounded' => ['health_route'],
            ],
            'documents' => [
                ['id' => 'thirty_b_chat', 'text' => 'Use the 30B chat model for normal Jetson conversation despite its size, latency, and failure evidence.'],
                ['id' => 'embedding_route', 'text' => 'Jetson production telemetry supports the embedding role with low latency and a low failure rate.'],
                ['id' => 'health_route', 'text' => 'A tiny local model remains admitted only for bounded health checks.'],
                ['id' => 'candidate_chat', 'text' => 'A hardware-fit 4B chat model remains a candidate until it passes a focused role contract canary.'],
                ['id' => 'external_coding', 'text' => 'An external provider handles an explicitly selected coding request.'],
            ],
        ],
        'genealogy_same_name_gate' => [
            'query' => 'Can a same-name genealogy search result be written as a person fact?',
            'relevance' => ['lead_only' => 3, 'identity_bridge' => 2],
            'hard_negative_ids' => ['same_name_write'],
            'claims' => [
                'same_name_result_is_lead_only' => ['lead_only'],
                'identity_bridge_requires_corroboration' => ['identity_bridge'],
            ],
            'documents' => [
                ['id' => 'same_name_write', 'text' => 'Write the genealogy fact when a search result has the same person name.'],
                ['id' => 'lead_only', 'text' => 'A same-name source result is a lead only and cannot become a canonical person fact by itself.'],
                ['id' => 'identity_bridge', 'text' => 'Corroborating dates, places, relatives, and source provenance must establish the identity bridge before review.'],
                ['id' => 'query_training', 'text' => 'Store rejected search wording as feedback for future source queries.'],
                ['id' => 'surname_chart', 'text' => 'Render a surname frequency chart for the selected family tree.'],
            ],
        ],
        'joplin_sync_verification' => [
            'query' => 'How should a PLOS note be verified after Joplin Nextcloud sync?',
            'relevance' => ['webdav_body' => 3, 'note_lookup' => 2],
            'hard_negative_ids' => ['hash_only'],
            'claims' => [
                'webdav_body_verifies_content' => ['webdav_body'],
                'notebook_and_note_ids_are_confirmed' => ['note_lookup'],
            ],
            'documents' => [
                ['id' => 'hash_only', 'text' => 'Treat an application-level hash match as sufficient proof that Joplin Nextcloud sync preserved the note.'],
                ['id' => 'webdav_body', 'text' => 'Inspect the synced WebDAV note body because Joplin normalization can change application-level hashes.'],
                ['id' => 'note_lookup', 'text' => 'Confirm the notebook and note identifiers through the normal synchronized lookup surface.'],
                ['id' => 'queue_pending', 'text' => 'Check whether a note write remains queued before declaring synchronization complete.'],
                ['id' => 'calendar_event', 'text' => 'Create a Nextcloud calendar event with a recurrence rule.'],
            ],
        ],
    ],
];
