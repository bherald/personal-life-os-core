<?php

namespace App\Services;

use App\Console\Commands\CodexOfflineEvalCommand;
use App\Services\Ops\AgentActionSeverityService;
use App\Services\Ops\AgentReliabilityProfileService;
use App\Services\Ops\GuardrailMonitorService;
use App\Services\Ops\OcrCandidateEvalService;
use App\Services\Ops\OtlpTraceJsonValidatorService;
use App\Services\Ops\SttCandidateEvalService;
use App\Services\Ops\TtsCandidateEvalService;
use App\Services\Ops\UpstreamDocumentationConsistencyService;
use App\Support\PromptIntegrityPolicy;
use App\Support\SensitiveTextTelemetry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class OfflineAiPipelineInventoryService
{
    /**
     * @return array<string, mixed>
     */
    public function payload(?string $task = null, bool $compact = false): array
    {
        $task = $task !== null ? trim($task) : null;
        $agentDelegation = $this->agentDelegation();
        $whisperTimeoutPolicy = app(WhisperRuntimeService::class)->capability()['timeout_policy'];
        $documentationContractProjects = $this->documentationContractProjects();
        $colibriResearchPolicy = app(ColibriResearchPolicyService::class)->runtimePolicy();
        $colibriLocalResearchPolicy = app(ColibriResearchPolicyService::class)
            ->runtimePolicy(ColibriResearchPolicyService::LOCAL_OFFLINE_PACK);

        return [
            'schema' => 'plos_offline_ai_pipeline_inventory.v1',
            'mode' => 'observe',
            'read_only' => true,
            'generated_at' => now()->toIso8601String(),
            'answer_contract' => [
                'purpose' => 'Give offline/local models a deterministic PLOS AI pipeline inventory instead of letting them infer or hallucinate generic PLOS article tooling.',
                'operator_shortcut' => 'For questions like "list in order executed the functions/tools/options in the AI pipeline for PLOS", call this command/tool first.',
                'entrypoints' => [
                    './offline_dev.sh --pipeline-tools',
                    'php artisan codex:offline-pipeline-tools --json',
                    'PLOS MCP tool plos_offline_pipeline_tools',
                ],
            ],
            'prompt_integrity' => PromptIntegrityPolicy::inventory(),
            'local_os_tool_discovery' => [
                'surface_kind' => 'static_contract_call_commands_for_current_environment_observations',
                'commands' => ['os-tools:inventory --json', 'os-tools:discover --limit=100 --json', 'os-tools:review <tool> --json'],
                'registered_tools' => ['os_tools_inventory', 'os_tools_discover', 'os_tools_review'],
                'mcp_surface' => 'existing artisan_execute bridge; commands execute in configured PROJECT_ROOT environment',
                'definition_source' => 'system_configs os_tools.definitions; explicit resources/os-tools/definitions.json fallback',
                'discovery_sources' => ['current process PATH', 'installed dpkg metadata on supported Linux hosts'],
                'documentation_sources' => ['bounded local man1/man8', 'selected package docs and copyright', 'review-only official reference URLs'],
                'discovered_programs_executed' => false,
                'complete_machine_inventory' => false,
                'new_execution_permissions_granted' => false,
                'external_references_fetched_automatically' => false,
                'approval_handoff' => 'Documentation-backed preparation review, then existing ToolProposalService for a concrete typed adapter.',
            ],
            'ollama_progress_monitor' => [
                'schema' => 'plos.ollama_progress_monitor.v1',
                'profile' => 'resilient_local_first',
                'transport' => 'ollama_generate_ndjson',
                'live_command' => 'php artisan ops:llm-active --json --compact',
                'live_api' => 'GET /api/ai/status active_generations',
                'log_event_prefix' => 'Ollama monitored generation',
                'states' => ['starting', 'waiting_for_first_chunk', 'receiving', 'waiting_for_next_chunk'],
                'timeout_semantics' => 'dynamic_inactivity_not_wall_clock',
                'model_state_expands_inactivity_budget' => true,
                'transport_wall_clock_disabled_while_progressing' => true,
                'outer_safety' => ['bounded_max_tokens', 'owning_worker_timeout'],
                'recovery' => ['bounded_retry', 'circuit_failover', 'failure_domain_fallback', 'scheduled_health_reconcile'],
                'exact_response_reconstructed' => true,
                'raw_prompt_or_response_in_telemetry' => false,
                'compression' => false,
                'precompaction' => false,
            ],
            'searxng_search_privacy' => [
                ...SensitiveTextTelemetry::inventory(),
                'surface' => 'SearXNG service and MCP adapter local logs',
                'caller_response_preserves_query' => true,
                'per_result_engine_provenance' => true,
                'per_result_engine_agreement_count' => true,
                'aggregate_unique_engine_summary' => true,
                'unresponsive_engine_reasons' => 'bounded_reason_codes_only',
                'unresponsive_engine_identity' => 'unique_engine_with_reason_code_set_and_separate_event_count',
                'empty_results_with_unresponsive_engines' => 'failure',
                'partial_results_with_unresponsive_engines' => 'success_degraded',
                'exception_message_logged' => false,
                'result_url_logged_on_policy_drop' => false,
                'changes_search_ranking_or_provider_route' => false,
            ],
            'colibri_research_sidecar' => [
                'schema' => ColibriResearchPolicyService::SCHEMA,
                'status' => $colibriResearchPolicy['status'],
                'execution_enabled' => $colibriResearchPolicy['execution_enabled'],
                'surface' => 'ai:colibri-research --execute --public-web',
                'local_offline_surface' => 'ai:colibri-research --execute --local-offline',
                'mcp_tool' => 'plos_colibri_research',
                'mcp_default_target' => 'local_checkout',
                'provider' => $colibriResearchPolicy['instance_id'],
                'model_role' => $colibriResearchPolicy['model_role'],
                'capability_pack' => $colibriResearchPolicy['capability_pack'],
                'requires_internet' => $colibriResearchPolicy['requires_internet'],
                'requires_searxng_endpoint' => $colibriResearchPolicy['requires_searxng_endpoint'],
                'local_offline_supported' => $colibriResearchPolicy['local_offline_supported'],
                'policy_profile' => $colibriResearchPolicy['offline_policy_profile'],
                'policy_source' => 'system_configs.'.ColibriResearchPolicyService::POLICY_KEY,
                'allowed_tools' => $colibriResearchPolicy['allowed_tools'],
                'writes_allowed' => false,
                'fallback_allowed' => false,
                'public_web_confirmation_required' => $colibriResearchPolicy['internet_requires_public_input'],
                'max_sidecar_depth' => $colibriResearchPolicy['max_sidecar_depth'],
                'model_tool_contract_eval' => 'php artisan ai:colibri-research-canary-eval --json --compact --fail-on-policy-gaps',
                'live_model_tool_contract_eval' => 'php artisan ai:colibri-tool-contract-eval --pack=<capability-pack> --max-cases=20 --execute --json --compact --fail-on-gaps',
                'general_agent_tool_status' => 'staged_disabled_pending_measured_promotion',
                'capability_packs' => [
                    ColibriResearchPolicyService::PUBLIC_WEB_PACK => [
                        'status' => $colibriResearchPolicy['status'],
                        'execution_enabled' => $colibriResearchPolicy['execution_enabled'],
                        'requires_internet' => $colibriResearchPolicy['requires_internet'],
                        'requires_searxng_endpoint' => $colibriResearchPolicy['requires_searxng_endpoint'],
                        'offline_policy_profile' => $colibriResearchPolicy['offline_policy_profile'],
                        'allowed_tools' => $colibriResearchPolicy['allowed_tools'],
                        'public_web_confirmation_required' => true,
                        'writes_allowed' => false,
                        'fallback_allowed' => false,
                    ],
                    ColibriResearchPolicyService::LOCAL_OFFLINE_PACK => [
                        'status' => $colibriLocalResearchPolicy['status'],
                        'execution_enabled' => $colibriLocalResearchPolicy['execution_enabled'],
                        'requires_internet' => $colibriLocalResearchPolicy['requires_internet'],
                        'requires_searxng_endpoint' => $colibriLocalResearchPolicy['requires_searxng_endpoint'],
                        'offline_policy_profile' => $colibriLocalResearchPolicy['offline_policy_profile'],
                        'allowed_tools' => $colibriLocalResearchPolicy['allowed_tools'],
                        'public_web_confirmation_required' => false,
                        'writes_allowed' => false,
                        'fallback_allowed' => false,
                    ],
                ],
                'availability_contract' => [
                    'colibri_requires_87_running' => true,
                    'home_lan_required' => false,
                    'host_only_route_supported' => true,
                    'fresh_public_web_requires_internet' => true,
                    'fresh_public_web_requires_reachable_searxng' => true,
                    'unavailable_behavior' => 'fail_closed_no_fallback',
                    'local_offline_supported' => $colibriLocalResearchPolicy['execution_enabled'],
                    'local_offline_requires_published_rag_rows' => true,
                ],
                'raw_question_or_answer_logged' => false,
                'compression' => false,
                'precompaction' => false,
            ],
            'sensitive_log_boundary' => [
                ...SensitiveTextTelemetry::inventory(),
                'schema' => 'plos.sensitive_log_boundary.v3',
                'processor' => 'App\\Logging\\SensitiveLogContextProcessor',
                'audit_command' => 'ops:log-secret-redaction-audit --json --compact --fail-on-violations',
                'sink_coverage' => 'every_configured_non_stack_non_emergency_log_channel',
                'framework_emergency_fallback_covered' => false,
                'structured_private_text_keys' => 'exact_or_suffix_query_prompt_claim_singular_or_plural_with_text_prefix_preview',
                'structured_diagnostic_text_keys' => 'message_and_exact_or_suffix_error_exception_throwable_private_body_forms',
                'private_text_replacement' => 'explicit_private_text_redaction_sentinel',
                'safe_metric_examples' => ['query_count', 'query_chars', 'prompt_tokens', 'query_fingerprint'],
                'safe_failure_examples' => ['error_code', 'error_count', 'exception_type', 'failure_reason'],
                'content_free_receipt' => 'purpose_scoped_hmac_sha256_length_and_kind',
                'receipt_surfaces' => ['research', 'rag', 'chat', 'genealogy'],
                'synthetic_audit_covers_private_text_keys' => true,
                'throwable_normalization' => 'type_code_previous_types_only_no_message_trace_or_file',
                'message_source_audit_engine' => 'nikic_php_parser_ast_v5',
                'message_source_audit_surfaces' => ['log_facade', 'facade_channel', 'logger_helper', 'obvious_logger_instance'],
                'message_expression_classification' => ['literal', 'placeholder_template', 'bounded_literal_choice', 'dynamic', 'direct_exception_detail'],
                'message_source_parse_fail_closed' => true,
                'new_dynamic_message_sites_blocked' => true,
                'new_placeholder_template_sites_blocked' => true,
                'direct_exception_message_interpolation_source_audit' => true,
                'free_form_message_expression_classification_covered' => true,
                'free_form_message_body_covered' => false,
                'exception_object_body_covered' => true,
                'changes_response_storage_or_ai_prompt_content' => false,
            ],
            'offline_workspace_context' => app(OfflineWorkspaceContextService::class)->inventory(),
            'repository_context_map' => [
                'schema' => 'plos.repo_context_map_runtime.v1',
                'tool' => 'repo-dev.context_map',
                'policy_source' => 'config/dev_agent.php repo_context_map',
                'runtime_budget_ms' => (int) config('dev_agent.repo_context_map.max_runtime_ms', 60000),
                'budget_scope' => [
                    'inventory',
                    'path_scoring',
                    'content_search',
                    'native_fallback',
                    'content_scan',
                    'declaration_extraction',
                    'result_ranking',
                ],
                'deadline_clock' => 'monotonic',
                'deadline_behavior' => 'fail_closed_no_partial_context',
                'content_search_order' => [
                    'ripgrep',
                    'exact_inventory_gnu_grep',
                    'bounded_native_path_candidates',
                ],
                'native_grep_scope' => 'exact_inventory_paths_no_recursive_release_walk',
                'candidate_cap_behavior' => 'global_path_content_prescore_bounded_declaration_enrichment_path_only_incomplete',
                'native_declaration_candidate_limit' => (int) config(
                    'dev_agent.repo_context_map.max_native_declaration_candidates',
                    750
                ),
                'success_evidence' => ['runtime_budget_ms', 'duration_ms', 'phase_duration_ms'],
                'raw_task_included' => false,
                'source_bodies_included' => false,
                'changes_write_or_approval_authority' => false,
                'compression' => false,
                'precompaction' => false,
            ],
            'configuration_secret_boundary' => [
                'schema' => 'plos.configuration_secret_boundary.v1',
                'api_authentication' => 'auth:web_required_for_reads_and_writes',
                'raw_secret_values_returned' => false,
                'whole_value_projection' => 'configured_state_only',
                'nested_json_projection' => 'recursive_redaction_sentinel',
                'redacted_round_trip' => 'preserve_existing_secret',
                'secret_clear' => 'explicit_clear_secret_flag_only',
                'runtime_cache_invalidated_after_write' => true,
                'audit_content' => 'operator_action_section_key_and_count_metadata_no_values',
                'changes_operator_authority' => false,
                'compression' => false,
                'precompaction' => false,
            ],
            'upstream_documentation_consistency' => [
                'schema' => UpstreamDocumentationConsistencyService::SCHEMA,
                'audit_command' => 'php artisan whiteroom:provenance-audit --json --compact --fail-on-drift',
                'policy_source' => 'config/whiteroom_projects.php documentation_contract',
                'contract_projects' => $documentationContractProjects,
                'canonical_revision_field' => 'source_version',
                'git_commit_identifier_match_required' => true,
                'documents' => ['docs/research-provenance.md', 'docs/tech-repo-monitor-watchlist.md'],
                'failure_conditions' => ['invalid contract', 'registry revision identity mismatch', 'unsafe or missing document', 'missing or ambiguous row', 'stale revision'],
                'raw_document_content_returned' => false,
                'changes_source_pins' => false,
                'changes_adoption_or_runtime_authority' => false,
                'runs_llm_network_or_database' => false,
                'compression' => false,
                'precompaction' => false,
            ],
            'upstream_source_drift_coverage' => [
                'schema' => 'plos_upstream_source_drift_coverage.v2',
                'audit_command' => 'php artisan whiteroom:source-drift --format=json --compact',
                'strict_command' => 'php artisan whiteroom:source-drift --format=json --compact --fail-on-drift --require-complete',
                'runtime_policy_source' => UpstreamSourceDriftRuntimePolicyService::CONFIG_KEY,
                'selection_fields' => ['eligible_total', 'selected_total', 'checked_total', 'project_request'],
                'completeness_fields' => ['complete', 'status', 'omitted_by_limit', 'missing_results', 'inconclusive_results'],
                'incomplete_reason_codes' => [
                    'source_limit_reached',
                    'source_results_missing',
                    'source_unavailable',
                    'source_rate_limited',
                    'source_state_unclassified',
                ],
                'partial_results_preserved' => true,
                'raw_source_content_returned' => false,
                'changes_source_pins' => false,
                'changes_adoption_or_runtime_authority' => false,
                'compression' => false,
                'precompaction' => false,
            ],
            'task_custody_recovery_integrity' => [
                'schema' => 'plos.task_custody_recovery_integrity.v2',
                'service' => 'App\\Services\\Custody\\TaskCustodyService',
                'surfaces' => ['scheduled_job', 'agent_session', 'horizon_job'],
                'acquire_conflict' => 'one_unreleased_record_per_surface_ref',
                'same_owner_reacquire' => 'idempotent',
                'lease_extension' => 'monotonic',
                'scheduled_job_owner_identity' => [
                    'new_token_fields' => ['pid', 'process_start_clock_ticks', 'run_id'],
                    'pid_reuse' => 'confirmed_dead_owner',
                    'unreadable_process_start' => 'fail_closed_preserve_holder',
                    'legacy_pid_only_token' => 'supported_conservative_liveness',
                ],
                'expired_snapshot_release_predicates' => [
                    'custody_id_matches',
                    'owner_token_matches_observed_holder',
                    'released_at_is_null',
                    'expires_at_still_expired_at_update',
                ],
                'renewed_after_snapshot' => 'fail_closed_without_release_or_preemption',
                'recovery_changes_scheduler_authority' => false,
                'raw_progress_or_result_content_included' => false,
                'compression' => false,
                'precompaction' => false,
            ],
            'review_queue_observability' => [
                'schema' => 'plos.review_queue_observability.v1',
                'queue_source' => 'agent_review_queue',
                'focus_filters' => [
                    'typed-remediation',
                    'materializable-remediation',
                    'source-backed-packet',
                    'face-photo-repair-packet',
                    'aged-review',
                ],
                'every_focus_reports_age_pressure' => true,
                'age_pressure_fields' => [
                    'candidate_rows',
                    'stale_rows',
                    'high_priority_rows',
                    'age_bucket_counts',
                    'classification_counts',
                ],
                'operator_ui_route' => '/knowledge?source=review',
                'aggregate_projection_includes_private_details' => false,
                'face_photo_identity_operator_owned' => true,
                'changes_review_or_write_authority' => false,
                'compression' => false,
                'precompaction' => false,
            ],
            'guardrail_monitor' => [
                'schema' => GuardrailMonitorService::SCHEMA,
                'operator_surface' => '/ai-observability',
                'rule_sources' => ['database', 'config', 'offline_policy'],
                'database_rules_are_authoritative' => true,
                'config_rules_visible_without_events' => true,
                'active_offline_policy_profile_visible' => true,
                'raw_conditions_reasons_or_context_included' => false,
                'changes_enforcement_or_operator_authority' => false,
                'compression' => false,
                'precompaction' => false,
            ],
            'runtime_policy_debt_visibility' => [
                'schema' => 'plos.runtime_policy_debt_visibility.v1',
                'audit_command' => 'ops:runtime-policy-leak-audit --json --compact',
                'scheduled_job' => 'runtime_policy_leak_audit',
                'evidence_source' => 'latest scheduled_job_runs aggregate output',
                'operator_surface' => '/operator-evidence',
                'healthy_contract' => 'enabled, successful, valid, fresh, and zero runtime_policy_candidates',
                'review_contract' => 'fresh valid review_required output with one or more runtime_policy_candidates',
                'definition_context_policy_source' => 'system_configs.ops.runtime_policy_leak_audit.classifier.line_policy_definition_regexes',
                'candidate_contract' => 'table-configured literal policy definition contexts only',
                'non_definition_matches_remain_visible' => true,
                'blanket_suppression_allowed' => false,
                'exact_reasoned_fingerprint_override_required' => true,
                'stale_after_hours' => 36,
                'automatic_clear' => 'next fresh valid zero-candidate run',
                'content_scope' => 'aggregate counts and classification or pattern names only',
                'raw_output_findings_snippets_or_paths_included' => false,
                'creates_review_rows' => false,
                'changes_runtime_policy_or_scheduler_authority' => false,
                'compression' => false,
                'precompaction' => false,
            ],
            'agent_confidence_calibration' => [
                'schema' => 'plos.agent_confidence_calibration.v1',
                'audit_command' => 'ops:agent-confidence-calibration --json --compact',
                'scheduled_job' => 'agent_confidence_calibration',
                'policy_source' => 'system_configs.ops.agent_confidence_calibration',
                'cohort' => 'manual genealogy_finding approvals and rejections; automatic decisions excluded',
                'metrics' => ['expected_calibration_error', 'brier_score', 'auroc', 'threshold_precision', 'monotonicity_violations'],
                'outcome_semantics' => 'operator decision proxy, not global factual correctness',
                'operator_surface' => '/operator-evidence',
                'raw_review_content_included' => false,
                'changes_confidence_thresholds' => false,
                'changes_review_routing' => false,
                'changes_approval_authority' => false,
                'runs_llm_or_network' => false,
                'compression' => false,
                'precompaction' => false,
            ],
            'agent_session_history' => [
                'schema' => 'plos.agent_session_history.v1',
                'storage' => 'append_only_relational_transcript',
                'retrieval' => 'signed_backward_cursor_pages',
                'page_order' => 'newest_first',
                'stable_head_excludes_later_live_messages' => true,
                'model_context_window_messages' => 100,
                'model_context_window_is_not_durable_retention' => true,
                'concurrent_append_serialization' => 'agent_sessions_row_lock',
                'search_prefers_durable_history' => true,
                'content_transformation' => false,
                'compression' => false,
                'precompaction' => false,
            ],
            'agent_memory_maintenance' => [
                'schema' => 'plos.agent_memory_maintenance.v1',
                'policy_source' => 'scheduled_jobs.notes.runtime',
                'observability_surface' => 'ops:agent-doctor',
                'selection_order' => 'oldest_undistilled_first',
                'raw_episode_retention' => 'preserved',
                'capacity_horizon_inputs' => [
                    'scheduled_jobs.cron_expression',
                    'runtime.session_limit',
                    'runtime.daily_capacity',
                    'runtime.freshness_slo_hours',
                ],
                'projection_scope' => 'current_backlog_only',
                'healthy_multi_run_status' => 'catching_up',
                'failed_disabled_invalid_or_stale_status' => 'degraded',
                'changes_schedule_or_work_limit' => false,
                'automatic_execution_authority' => false,
                'compression' => false,
                'precompaction' => false,
            ],
            'llm_portfolio_review' => $this->llmPortfolioReviewInventory(),
            'artifact_export_safety' => [
                'schema' => 'plos.artifact_export_safety.v1',
                'generated_csv_scope' => 'research-history downloads and genealogy source-audit packages',
                'spreadsheet_formula_neutralization' => 'source_enforced_no_disable_override',
                'trigger_coverage' => 'ASCII and full-width formula initiators after whitespace, zero-width characters, or BOM; leading tab, CR, and LF',
                'csv_quoting' => 'php_fputcsv_with_escape_disabled',
                'field_detail_preserved' => true,
                'research_log_download_headers' => 'attachment_private_no_store_nosniff',
                'stored_data_changed' => false,
            ],
            'ocr_candidate_evaluation' => [
                'artifact_schema' => OcrCandidateEvalService::ARTIFACT_SCHEMA,
                'result_schema' => OcrCandidateEvalService::RESULT_SCHEMA,
                'command' => 'php artisan content:ocr-candidate-eval --artifact=ocr/candidate.json --json --compact --fail-on-ineligible',
                'required_capabilities' => array_values((array) config('ocr_candidate_eval.required_capabilities', [])),
                'promotion_evidence' => 'global quality plus absolute per-case floors plus zero per-case regression',
                'compact_output_content' => 'identities, hashes, aggregate metrics, capability labels, and safe failure codes only',
                'runs_ocr_or_model' => false,
                'changes_production_route' => false,
                'operator_review_required' => true,
            ],
            'stt_candidate_evaluation' => [
                'artifact_schema' => SttCandidateEvalService::ARTIFACT_SCHEMA,
                'result_schema' => SttCandidateEvalService::RESULT_SCHEMA,
                'command' => 'php artisan content:stt-candidate-eval --artifact=stt/candidate.json --json --compact --fail-on-ineligible',
                'required_capabilities' => array_values((array) config('stt_candidate_eval.required_capabilities', [])),
                'promotion_evidence' => 'heterogeneous WER and CER plus normalized critical-phrase recall, language, silence, real-time factor, absolute per-case floors, and zero per-case quality regression',
                'compact_output_content' => 'identities, hashes, aggregate metrics, capability labels, and safe failure codes only',
                'built_in_example' => 'synthetic contract validation only; never runtime or model promotion evidence',
                'runs_stt_or_model' => false,
                'changes_production_route' => false,
                'installs_model_or_weights' => false,
                'operator_review_required' => true,
                'compression' => false,
                'precompaction' => false,
            ],
            'whisper_runtime_timeout' => [
                'schema' => 'plos.whisper_runtime_timeout.v1',
                'mode' => $whisperTimeoutPolicy['mode'],
                'policy_source' => 'config/services.php services.whisper',
                'model_factors' => $whisperTimeoutPolicy['model_factors'],
                'unknown_model_factor' => $whisperTimeoutPolicy['unknown_model_factor'],
                'baseline_timeout_never_shortened' => $whisperTimeoutPolicy['baseline_timeout_never_shortened'],
                'shared_surfaces' => [
                    'chat attachment transcription',
                    'general content extraction',
                    'YouTube transcription fallback',
                ],
                'timeout_failure_reason' => 'transcription_timeout',
                'changes_model_route' => false,
                'compression' => false,
                'precompaction' => false,
            ],
            'tts_candidate_evaluation' => [
                'artifact_schema' => TtsCandidateEvalService::ARTIFACT_SCHEMA,
                'result_schema' => TtsCandidateEvalService::RESULT_SCHEMA,
                'command' => 'php artisan audio:tts-candidate-eval --artifact=tts/candidate.json --json --compact --fail-on-ineligible',
                'required_capabilities' => array_values((array) config('tts_candidate_eval.required_capabilities', [])),
                'promotion_evidence' => 'same-fixture intelligibility, critical phrases, language, blind naturalness, clipping, loudness, silence, format, real-time factor, absolute per-case floors, and zero per-case quality regression',
                'compact_output_content' => 'runtime, model, voice, assessor identities, hashes, aggregate metrics, capability labels, and safe failure codes only',
                'built_in_example' => 'synthetic contract validation only; never runtime, voice, model, or route promotion evidence',
                'runs_tts_asr_quality_estimator_or_model' => false,
                'changes_production_route' => false,
                'installs_model_voice_or_weights' => false,
                'operator_review_required' => true,
                'compression' => false,
                'precompaction' => false,
            ],
            'chat_stream_transport' => [
                'schema' => 'plos.chat_sse_transport.v1',
                'framing' => 'incremental_event_stream',
                'server_frame_termination' => 'blank_line',
                'line_endings' => ['CR', 'LF', 'CRLF'],
                'unicode_content_separators_preserved' => ['U+0085', 'U+2028', 'U+2029'],
                'multiline_data_joined' => true,
                'byte_boundary_safe' => true,
                'final_decoder_and_frame_flushed' => true,
                'content_transformation' => false,
                'multi_step_text' => [
                    'boundary' => 'blank_line_between_meaningful_tool_iterations',
                    'same_step_deltas_joined_exactly' => true,
                    'whitespace_only_steps_emitted' => false,
                    'non_whitespace_delta_content_preserved' => true,
                    'live_and_persisted_message_consistent' => true,
                    'changes_routing_tools_or_provider_fallback' => false,
                    'compression' => false,
                    'precompaction' => false,
                ],
                'activity_status' => [
                    'event_driven' => true,
                    'content_free' => true,
                    'provider_and_model_visible_when_emitted' => true,
                    'silence_notice_seconds' => 30,
                    'distinguishes_waiting_from_paused_content' => true,
                    'changes_routing_or_retry_behavior' => false,
                ],
                'conversation_selection' => [
                    'policy' => 'latest_idle_selection_wins',
                    'previous_transcript_cleared_before_fetch' => true,
                    'stale_success_or_failure_may_mutate_active_thread' => false,
                    'active_stream_thread_switch_allowed' => false,
                    'active_stream_new_clear_or_delete_allowed' => false,
                    'server_history_or_message_content_changed' => false,
                    'compression' => false,
                    'precompaction' => false,
                ],
                'run_failure' => [
                    'schema' => ChatRunFailureService::SCHEMA,
                    'event_type' => 'run_error',
                    'correlation_identity' => 'opaque_request_uuid',
                    'safe_fixed_error_code_and_message' => true,
                    'raw_exception_or_provider_body_sent_to_browser' => false,
                    'live_ui_visible' => true,
                    'persisted_history_visible' => false,
                    'automatic_retry_authority' => false,
                    'changes_routing_tool_or_operator_authority' => false,
                    'compression' => false,
                    'precompaction' => false,
                ],
                'tool_lifecycle' => [
                    'schema' => 'plos.chat_tool_lifecycle.v1',
                    'correlation_identity' => 'request_local_iteration_and_position',
                    'events' => ['tool_call', 'tool_result', 'tool_error'],
                    'terminal_statuses' => ['succeeded', 'failed'],
                    'identity_assignment' => 'AIRouter assigns request-local identity before tool dispatch',
                    'missing_or_invalid_identity' => 'fail_closed_content_free_run_error',
                    'orphan_terminal_event' => 'fail_closed_content_free_run_error',
                    'duplicate_call_identity' => 'fail_closed_content_free_run_error',
                    'first_terminal_state_wins' => true,
                    'open_calls_closed_before_stream_interruption' => true,
                    'interruption_error_code' => 'tool_stream_interrupted',
                    'interruption_failure_content_free' => true,
                    'post_dispatch_identity_fabrication_allowed' => false,
                    'multiple_iterations_accumulated' => true,
                    'live_and_persisted_ui_visible' => true,
                    'arguments_preserved_in_chat_record' => true,
                    'arguments_rendered_in_activity_ledger' => false,
                    'result_or_error_content_in_activity_ledger' => false,
                    'raw_tool_errors_sent_to_browser' => false,
                    'changes_tool_execution_or_authority' => false,
                ],
            ],
            'research_topic_refinement' => [
                'schema' => 'plos.research_topic_refinement.v1',
                'surface' => 'AI Hub research topic create and edit dialog',
                'mode' => 'dependency_aware_frontier_batch',
                'max_questions_per_round' => 3,
                'max_clarification_rounds' => 2,
                'asks_only_unresolved_operator_decisions' => true,
                'asks_known_context_facts' => false,
                'dependent_questions_deferred_until_unblocked' => true,
                'operator_can_finalize_anytime' => true,
                'proposal_remains_operator_editable' => true,
                'changes_model_route_or_tool_authority' => false,
                'compression' => false,
                'precompaction' => false,
            ],
            'workflow_webhook_idempotency' => [
                'schema' => 'plos.workflow_webhook_delivery.v1',
                'headerless_mode' => 'canonical_payload_with_body_hmac_v1',
                'delivery_headers' => [
                    'Idempotency-Key',
                    'X-Webhook-Delivery',
                    'X-GitHub-Delivery',
                ],
                'delivery_signature_header' => 'X-Webhook-Signature-V2',
                'delivery_signature_message' => 'delivery_id + LF + exact_raw_body',
                'delivery_identifier_contract' => 'one consistent visible-ASCII value, 1-255 bytes',
                'internal_key' => 'SHA-256(webhook:trigger_id:delivery_id)',
                'queue_uniqueness' => true,
                'durable_completed_run_idempotency' => true,
                'empty_payload_explicit_key_checked' => true,
                'raw_delivery_id_retained' => false,
                'workflow_input_changed' => false,
            ],
            'agent_command_guardrail' => [
                'schema' => 'plos.agent_command_guardrail.v1',
                'recursive_delete_policy' => 'fail_closed_except_one_standalone_generated_artifact_delete',
                'recursive_flag_forms' => ['-r', 'combined_short_flags', '--recursive'],
                'compound_commands_allowed' => false,
                'absolute_home_parent_variable_quoted_or_unknown_targets_allowed' => false,
                'safe_target_basenames' => [
                    '.cache',
                    '.next',
                    '.turbo',
                    '__pycache__',
                    'build',
                    'coverage',
                    'dist',
                    'node_modules',
                ],
                'changes_execution_or_approval_authority' => false,
            ],
            'agent_runtime_policy' => $this->agentRuntimePolicy($agentDelegation),
            'agent_delegation' => $agentDelegation,
            'agent_handoff_lifecycle' => [
                'schema' => 'plos.agent_handoff_task_lifecycle.v1',
                'external_contract' => 'A2A_v1.0.1_task_states',
                'correlation' => 'handoff_id_plus_distributed_task_id',
                'states' => ['initiated', 'submitted', 'working', 'completed', 'failed', 'timeout', 'cancelled'],
                'active_states' => ['initiated', 'submitted', 'working'],
                'terminal_states' => ['completed', 'failed', 'timeout', 'cancelled'],
                'default_priority' => 'normal',
                'source_identity' => 'trusted_runtime_context_only',
                'source_identity_context_alias' => 'agent_id',
                'model_visible_source_agent_id' => false,
                'completion_requires_child_terminal_success' => true,
                'retries_return_to_submitted' => true,
                'terminal_state_first_result_wins' => true,
                'historical_failures_rewritten' => false,
                'changes_depth_permission_or_operator_authority' => false,
            ],
            'agent_sensitive_action_evidence_lineage' => [
                'schema' => 'plos.agent_sensitive_action_evidence_lineage.v1',
                'enforcement_point' => 'AgentLoopService_before_high_risk_tool_dispatch',
                'lineage_classes' => ['current_observation', 'advisory_memory', 'state_change', 'failed'],
                'memory_may_guide_research' => true,
                'memory_may_independently_authorize_sensitive_action' => false,
                'successful_current_run_read_observation_required' => true,
                'semantic_evidence_and_uncertainty_checks_remain_required' => true,
                'content_free' => true,
                'changes_tool_permission_confirmation_or_operator_authority' => false,
                'compression' => false,
                'precompaction' => false,
            ],
            'compute_router' => [
                'schema' => 'plos.compute_router_runtime.v2',
                'policy_source' => 'system_configs.'.ComputeRouterRuntimePolicyService::CONFIG_KEY,
                'worker_source' => 'compute_instances',
                'routing_eligibility' => 'active_capable_fresh_healthy_circuit_admitted_slot_available',
                'capability_matching' => 'exact',
                'capability_status_method' => 'ComputeRouterService::getCapabilityStatus',
                'capability_recovery_method' => 'ComputeRouterService::recoverCapability',
                'fresh_health_required' => true,
                'nightly_all_worker_health_check' => true,
                'scheduled_health_reconcile' => 'ops:compute-health-reconcile --json --compact',
                'scheduled_health_reconcile_cadence' => 'every_15_minutes',
                'consumer_preflight_recovery_supported' => true,
                'unavailable_backlog_outcome' => 'deferred_and_retried_on_owned_cadence',
                'operator_attention' => [
                    'unregistered' => 'fix',
                    'registered_unhealthy_stale_or_open' => 'watch_auto_recovery',
                    'capacity_busy' => 'transient_no_action',
                ],
                'failure_telemetry' => 'content_free_codes_identity_duration_exception_class',
                'raw_command_input_stderr_or_model_output_logged' => false,
                'routing_score_dimensions' => ['priority', 'health', 'observed_latency', 'available_slot_headroom'],
                'routing_weights_table_owned' => true,
                'remote_ssh_batch_mode' => true,
                'remote_ssh_password_authentication' => false,
                'remote_ssh_keyboard_interactive_authentication' => false,
                'remote_ssh_host_key_policy' => 'strict_known_hosts_required_no_disable_override',
                'external_serving_runtime_added' => false,
                'changes_model_provider_or_operator_authority' => false,
                'compression' => false,
                'precompaction' => false,
            ],
            'structured_output_routing' => [
                'schema' => 'plos.structured_output_routing.v1',
                'registered_contracts' => 'config/ollama_output_schemas.php',
                'ollama_admission' => 'exact ollama_models capability alias required for the selected role model',
                'external_admission' => 'exact llm_instances.config.structured_output.models entry required',
                'explicit_false_precedence' => true,
                'allowed_external_transport' => 'openai_json_schema',
                'local_response_validation' => true,
                'eval_suite' => 'plos.genea_local_model.v2',
                'eval_schema_transport' => 'same registered schema through every admitted provider transport',
                'eval_schema_revalidation' => 'independent evaluator-side Opis validation',
                'eval_schema_promotion_gate' => 'all active structured cases compliant',
                'semantic_cache_enabled' => false,
                'unadmitted_fallback' => 'blocked',
            ],
            'mcp_tool_integrity' => [
                'schema' => 'plos.mcp_tool_contract_audit.v1',
                'command' => 'php artisan ops:mcp-tool-contract-audit --json --compact --fail-on-violations',
                'scheduled_job' => 'mcp_tool_contract_audit',
                'protocol_version' => '2025-11-25',
                'scope' => 'live trusted PLOS tools/list metadata, schemas, annotations, names, and reviewed content digest',
                'posture' => 'content-free; no arbitrary MCP config execution; no network or metadata upload',
                'contract_probe_transport' => 'official_sdk_v1_client_owned_stdio',
                'contract_probe_sdk' => '@modelcontextprotocol/sdk@1.29.0',
                'contract_probe_timeout_seconds' => 8,
                'contract_probe_max_pages' => 64,
                'contract_probe_attempts' => 1,
                'contract_probe_automatic_retry' => false,
                'contract_probe_failure_receipt' => 'typed_content_free_code_plus_sha256',
                'contract_probe_failure_codes' => ['connection_closed', 'request_timeout', 'child_process_error', 'protocol_error', 'unknown'],
                'unclassified_or_unparseable_probe_failure' => 'blocked',
                'dynamic_discovery_pagination' => 'bounded_complete_catalog_or_static_fallback',
                'dynamic_discovery_max_pages' => 64,
                'dynamic_discovery_max_tools' => 4096,
                'invalid_repeated_or_ambiguous_pages' => 'blocked',
                'partial_dynamic_catalog_allowed' => false,
                'request_scoped_progress' => 'token_gated_content_free_elapsed_seconds',
                'progress_heartbeat_seconds' => 15,
                'progress_total_advertised' => false,
                'progress_messages' => ['started', 'running', 'completed', 'failed'],
                'progress_payload_content_included' => false,
                'late_progress_after_cancellation_allowed' => false,
                'progress_changes_tool_output_or_authority' => false,
                'invocation_argument_validation' => 'enforced_before_internal_or_external_dispatch',
                'unknown_root_arguments_rejected' => true,
                'argument_values_logged' => false,
                'external_result_validation' => 'advertised_output_schema_enforced_before_agent_or_llm',
                'result_values_logged' => false,
                'dynamic_catalog_cache_identity' => 'server_config_plus_resolved_runtime_file_content_sha256',
                'same_path_runtime_replacement_invalidates_cache' => true,
                'indirect_launchers_cached' => false,
                'external_runtime_admission' => 'fail_closed_before_catalog_or_invocation',
                'external_client_transports' => ['stdio'],
                'remote_http_or_sse_client_admitted' => false,
                'remote_transport_options_rejected' => ['url', 'headers', 'request_init', 'requestInit'],
                'http_auth_header_forwarding' => 'not_applicable_no_http_or_sse_client',
                'silent_transport_option_drop_allowed' => false,
                'indirect_launchers_admitted' => false,
                'indirect_launcher_examples' => ['npx', 'uvx', 'shell', 'mutable_container'],
                'npm_runtime_pin_evidence' => 'exact_manifest_version_plus_lock_version_integrity_plus_installed_version_plus_binary_ownership',
                'runtime_network_fetch_allowed' => false,
                'unverified_static_catalog_advertised' => false,
                'content_identity_runtime_supported' => true,
                'everything_reference_server' => 'exact_locked_disabled_by_default_operator_opt_in',
                'everything_offline_or_hybrid_profile_admission' => false,
                'everything_environment_inspection_exposed_when_enabled' => true,
                'static_reviewed_catalog_changed' => false,
                'compression' => false,
                'precompaction' => false,
                'policy_evaluation_failure' => 'blocked_before_internal_or_external_dispatch',
                'runtime_policy' => 'system_configs.mcp.router_runtime_policy',
            ],
            'rag_ingestion_provenance' => [
                'schema' => 'plos.rag_ingestion_provenance.v1',
                'embedding_contract' => 'provider_instance_model_dimension_pooling_and_input_fingerprint',
                'semantic_chunking_contract' => 'normalized_effective_options_plus_exact_output_set_fingerprint',
                'chunk_content_or_individual_hashes_retained' => false,
                'historical_rows_inferred_or_rewritten' => false,
                'audit_command' => 'php artisan rag:chunking-provenance-audit --json --compact',
                'changes_chunking_embedding_routing_or_retrieval' => false,
            ],
            'registered_cpu_reranking' => [
                'command' => 'php artisan rag:rerank --cross-encoder --query-b64=... --documents-json-b64=... --json',
                'selection_source' => 'system_configs.rag.reranker_controller_policy',
                'service' => 'App\\Services\\RerankerSidecarService::rerankControlled',
                'registration' => 'database/migrations/2026_10_14_210000_register_guarded_reranker_selection.php',
                'engine' => 'Exact table-registered transformers CPU backend and authenticated_reranker native Windows controller adapter.',
                'admission' => 'Staged catalog is disabled until exact artifact/runtime/task/source identities and resource profile are qualified.',
                'input' => 'Complete query/document pairs; byte and tokenizer budgets refuse before scoring; no truncation.',
                'outcome' => 'Exact request/model/revision/runtime receipt and complete finite yes/no-softmax scores; UNKNOWN holds without replay.',
                'embedding_boundary' => 'Existing indexes, default embedding role and model-space fingerprints are unchanged.',
            ],
            'rag_embedding_space_evaluation' => [
                'schema' => 'plos.rag_rerank_eval.v3',
                'command' => 'php artisan rag:rerank-eval --use-embeddings --json --compact --require-promotion-ready',
                'identity_contract' => 'provider_model_revision_dimension_sha256',
                'ollama_revision_source' => 'exact_api_tags_model_digest',
                'same_model_digest_across_instances_allowed' => true,
                'mixed_embedding_spaces_allowed' => false,
                'missing_identity_behavior' => 'fail_closed_to_lexical_not_promotion_evidence',
                'dimension_only_compatibility_allowed' => false,
                'production_vectors_changed' => false,
                'changes_embedding_routing_or_model_promotion' => false,
                'compression' => false,
                'precompaction' => false,
            ],
            'hierarchical_rag_retrieval' => [
                'schema' => 'plos.hierarchical_rag_retrieval.v1',
                'tools' => ['rag_search', 'rag_similar', 'rag_read'],
                'search_identity' => 'stable_document_id_and_RAG-DOC-reference',
                'read_addressing' => 'zero_based_UTF-8_character_offset',
                'default_window_chars' => 4000,
                'maximum_window_chars' => 20000,
                'continuation' => 'follow_next_offset_until_has_more_is_false',
                'content_transformation' => false,
                'compression' => false,
                'precompaction' => false,
                'changes_indexing_ranking_or_write_authority' => false,
            ],
            'gen_ai_telemetry' => [
                'schema' => 'plos_gen_ai_telemetry_export.v1',
                'scope_version' => '1.6.0',
                'otlp_json_validation_schema' => OtlpTraceJsonValidatorService::SCHEMA,
                'protobuf_byte_id_encoding' => 'canonical_base64',
                'protobuf_uint64_encoding' => 'decimal_string',
                'invalid_otlp_export_allowed' => false,
                'collector_invoked_automatically' => false,
                'tool_call_identity_source' => 'authoritative_mcp_tool_calls_row',
                'tool_call_identity_attribute' => 'gen_ai.tool.call.id',
                'tool_call_identity_format' => 'plos_call_<positive_database_id>',
                'tool_arguments_or_results_exported' => false,
                'raw_agent_audit_context_exported' => false,
                'historical_tool_call_identity_inferred' => false,
                'content_attribute_gate' => 'fail_closed',
                'forbidden_content_attribute_families' => count(OtlpTraceJsonValidatorService::FORBIDDEN_CONTENT_ATTRIBUTE_FAMILIES),
                'reasoning_text_or_payload_exported' => false,
                'reasoning_token_counts_allowed' => true,
            ],
            'workflow_execution_intent' => [
                'schema' => 'plos.workflow_execution_intent.v1',
                'resume_tool' => 'workflow_resume_execution',
                'required_intent' => 'resume_live',
                'implicit_live_default_allowed' => false,
                'operator_confirmation_still_required' => true,
                'manual_or_test_resume_mode_supported' => false,
            ],
            'skill_discovery_integrity' => [
                'schema' => 'plos.skill_discovery_integrity.v1',
                'canonical_name_pattern' => '^[a-z0-9]+(?:-[a-z0-9]+)*$',
                'maximum_name_bytes' => 64,
                'frontmatter_directory_name_match_required' => true,
                'noncanonical_backup_or_copy_directories_activated' => false,
                'symlinked_skill_directories_activated' => false,
                'path_like_direct_identifiers_allowed' => false,
                'explicit_operator_audit_of_inactive_directory_allowed' => true,
            ],
            'agent_tool_contract' => [
                'schema' => 'plos.agent_tool_contract.v1',
                'declaration_precedence' => 'top_level_tools_else_ordered_tool_phases_union',
                'undeclared_permission_compatible_tools_advertised' => false,
                'required_permissions_mode' => 'all_required_fail_closed',
                'empty_agent_permissions_bypass_required_permissions' => false,
                'unresolved_declared_tools' => 'blocked_before_session_cache_tool_or_model_access',
                'runtime_invocation_limited_to_resolved_contract' => true,
                'changes_operator_confirmation_or_offline_policy' => false,
            ],
            'offline_coding_evaluation' => $this->offlineCodingEvaluation(),
            'runtime_text_pipeline_order' => $this->runtimeTextPipelineOrder($compact),
            'runtime_tool_calling_order' => $this->runtimeToolCallingOrder($compact),
            'runtime_vision_pipeline_order' => $compact ? [] : $this->runtimeVisionPipelineOrder(),
            'configured_ollama_pipeline_tasks' => $this->configuredOllamaPipelineTasks($task, $compact),
            'typesafe_jev_judgment_route' => [
                'schema' => 'plos.typesafe_jev_policy.v1',
                'route_kind' => 'agent_tool_typed_judgment',
                'tool' => 'typesafe_jev_decide',
                'service' => 'App\\Services\\TypeSafeJevDecisionService::evaluate',
                'registration' => 'database/migrations/2026_09_21_160000_register_typesafe_jev_decision_tool.php',
                'private_task_registration' => 'database/migrations/2026_09_25_170000_register_typesafe_private_task_policy.php',
                'policy_source' => 'system_configs.ai.typesafe_jev_policy',
                'task_profiles_contract' => 'Seeded capability profiles below; current task enablement and classifications require live policy discovery with php artisan typesafe:task-bridge --describe.',
                'mcp_bridge' => 'scripts/typesafe-jev-mcp.mjs routes typesafe_evaluate through typesafe:task-bridge and the same registered service; no separate API transport.',
                'enabled_task_profiles' => [
                    'cli_task_skill_selection',
                    'todo_triage',
                    'social_state_classification',
                    'evidence_relevance',
                    'claim_support',
                    'genealogy_queue_triage',
                    'pipeline_issue_triage',
                ],
                'question_types' => ['noul', 'choice', 'score'],
                'request_contract' => 'POST https://api.typesafe.ai/v1/systemone with bearer auth and {state, model, questions}; max 8 typed questions and bounded JSON body.',
                'response_contract' => 'Exact answer IDs/types; typed values, probability distributions and confidence validated before use.',
                'eligibility' => 'Requires enabled tool and task policy, a permitted public, sanitized_internal or private_internal classification, online mode, internet access and allowed remote-domain policy. Private inputs are limited to registered tasks; exclude authentication material, credentials and security/access tokens.',
                'unavailable_behavior' => 'Returns status=skipped and continue_existing_route; bounded retry and shared circuit breaker protect provider calls.',
                'automatic_reranking' => ['caller' => 'App\\Services\\RerankerService::rerank(mode=ai)',
                    'policy' => 'ai.typesafe_jev_policy.tasks.evidence_relevance.reranking',
                    'fallback' => 'complete original candidate set through existing general scorer, then deterministic ranking',
                    'cache' => 'complete scores only; exact evidence, query and current policy; current tool/task permissions checked before reuse'],
                'telemetry' => 'Content-free route trace, typed_judgment/typed_judgment_skip metrics, latency and token usage; PLOS agent tool/audit log also records invocation.',
                'raw_state_or_answers_logged' => false,
                'automatic_actions_allowed' => false,
                'chat_completion_provider' => false,
                'general_inference_provider_route' => 'not_implemented; Jev remains a separate typed-judgment agent tool.',
            ],
            'plos_mcp_tool_order' => $this->plosMcpToolOrder($compact),
            'artisan_pipeline_commands' => $this->artisanPipelineCommands($compact),
            'route_and_provider_options' => $this->routeAndProviderOptions($compact),
            'live_db_inventory' => $this->liveDbInventory($compact),
            'operator_gates' => [
                'surface_kind' => 'static_policy_contract_not_runtime_status',
                'offline_mode' => 'Explicit /cloud off sets routing.offline_mode and blocks cloud providers. The local ./offline launcher does not force this setting; cloud is eligible when configured on and the network/provider policy allows it.',
                'writes' => 'Offline agent edits require explicit repo-write approval; this inventory is read-only.',
                'face_identity' => 'Face/photo identity decisions stay operator-owned; offline models may stage evidence and run repair/status commands only within existing gates.',
                'compression' => 'Lossy prompt compression, tool-result precompaction, contextual RAG compression, and automatic RLM decomposition are source-enforced off with no environment override.',
                'mcp_tool_contract' => 'Live PLOS tool metadata and schemas must match the reviewed baseline; every admitted argument object must pass its advertised schema before dispatch, and successful external structured results must pass an advertised output schema before agent or LLM use; refresh requires explicit review after security checks pass.',
                'policy_error_handling' => 'Policy rule only, not a current error: agent and MCP execution is denied if offline-policy resolution or evaluation fails; policy errors never become implicit permission.',
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function agentDelegation(): array
    {
        $defaults = [
            'max_depth' => (int) config('agents.delegation_governance.max_depth', 1),
            'max_sidecar_depth' => (int) config('agents.delegation_governance.max_sidecar_depth', 0),
        ];
        $policy = app(SystemConfigService::class)->get(AgentDSGovernanceService::RUNTIME_POLICY_KEY, $defaults);
        if (! is_array($policy)) {
            $policy = $defaults;
        }

        return [
            'schema' => AgentDSGovernanceService::SCHEMA,
            'policy_source' => 'system_configs.'.AgentDSGovernanceService::RUNTIME_POLICY_KEY,
            'max_depth' => max(0, min(5, (int) ($policy['max_depth'] ?? $defaults['max_depth']))),
            'max_sidecar_depth' => max(0, min(2, (int) ($policy['max_sidecar_depth'] ?? $defaults['max_sidecar_depth']))),
            'default_behavior' => 'root_agent_may_create_one_child_child_cannot_delegate',
            'trusted_context_fields' => ['agent_id', 'session_id', 'delegation_depth', 'ds_profile', 'sidecar'],
            'model_can_supply_or_override_trusted_context' => false,
            'queued_and_synchronous_handoffs_preserve_parent_depth' => true,
            'depth_checked_before_handoff_record_or_dispatch' => true,
            'routing_shadow' => app(AgentRoutingShadowService::class)->inventory(),
        ];
    }

    /** @param array<string,mixed> $delegation @return array<string,mixed> */
    private function agentRuntimePolicy(array $delegation): array
    {
        $systemConfig = app(SystemConfigService::class);

        return [
            'schema' => 'plos.agent_runtime_policy.v1',
            'namespace' => 'agents',
            'resolved_values' => [
                'ai_timeout_seconds' => max(5, min(3600, $systemConfig->getInt(
                    'agents.ai_timeout_seconds',
                    (int) config('agents.ai_timeout_seconds', 45)
                ))),
                'hybrid_default_timeout_minutes' => max(5, min(150, $systemConfig->getInt(
                    'agents.hybrid_default_timeout_minutes',
                    (int) config('agents.hybrid_default_timeout_minutes', 90)
                ))),
                'phase_max_retries' => max(0, min(5, $systemConfig->getInt(
                    'agents.phase_max_retries',
                    (int) config('agents.phase_max_retries', 2)
                ))),
                'max_delegation_depth' => (int) ($delegation['max_depth'] ?? 1),
                'max_sidecar_depth' => (int) ($delegation['max_sidecar_depth'] ?? 0),
            ],
            'policy_sources' => [
                'ai_timeout_seconds' => 'system_configs.agents.ai_timeout_seconds',
                'hybrid_default_timeout_minutes' => 'system_configs.agents.hybrid_default_timeout_minutes',
                'phase_max_retries' => 'system_configs.agents.phase_max_retries',
                'delegation' => 'system_configs.'.AgentDSGovernanceService::RUNTIME_POLICY_KEY,
            ],
            'fallback_source' => 'config/agents.php',
            'precedence' => [
                'ai_timeout_seconds' => ['explicit_run', 'skill', 'system_config', 'config_fallback'],
                'hybrid_default_timeout_minutes' => ['explicit_run_or_scheduled_job', 'system_config', 'config_fallback'],
                'phase_max_retries' => ['system_config', 'config_fallback'],
            ],
            'concurrency_semantics' => 'queue_and_delegation_specific_no_shared_agent_thread_limit',
            'subagent_model_semantics' => 'table_routed_by_role_and_capability_no_global_subagent_model_default',
            'reserved_or_ignored_settings_published' => false,
            'raw_config_values_included' => false,
            'changes_runtime_policy' => false,
            'changes_model_or_tool_authority' => false,
            'compression' => false,
            'precompaction' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function offlineCodingEvaluation(): array
    {
        return [
            'command' => 'php artisan codex:offline-eval --case=scratch_php_bugfix_hidden --case=scratch_php_multifile_migration_hidden --case=scratch_php_feature_extension_hidden --repeat=3 --execute --json',
            'robustness_command' => 'php artisan codex:offline-eval --case=scratch_php_bugfix_hidden --repeat=3 --reliability-condition=prompt_perturbation --reliability-condition=environment_perturbation --reliability-condition=fault_injection --execute --json',
            'case' => 'scratch_php_bugfix_hidden',
            'protected_cases' => [
                'scratch_php_bugfix_hidden',
                'scratch_php_multifile_migration_hidden',
                'scratch_php_feature_extension_hidden',
            ],
            'task_shapes' => [
                'existing_repository_issue_to_patch',
                'coordinated_php_and_sql_schema_change',
                'new_production_component_plus_existing_code_integration',
            ],
            'model_role' => 'coding',
            'runtime_route' => app(OfflineCodingRouteService::class)->resolve(),
            'agent_environment' => 'scratch_workspace_write',
            'verification' => [
                'public_regression_test' => true,
                'evaluator_owned_hidden_tests' => true,
                'protected_test_hashes' => true,
                'changed_path_allowlist' => true,
                'networkless_read_only_verifier' => 'bubblewrap_or_table_configured_local_docker_image',
                'docker_verifier_image_identity' => 'exact_table_configured_local_sha256_only',
                'docker_mutable_tags_allowed' => false,
                'product_configuration_identity' => true,
                'receipt_binds_verifier_image_identity' => true,
                'runtime_model_attestation' => 'dispatch_identity_only_backend_substitution_not_claimed',
                'calibration_contract' => 'null_fails_golden_passes_adversarial_fails',
                'receipt_schema' => OfflineCodingEvalReceiptService::SCHEMA,
                'harness_identity_schema' => OfflineCodingEvalReceiptService::HARNESS_IDENTITY_SCHEMA,
                'harness_fingerprint_scope' => OfflineCodingEvalReceiptService::HARNESS_FINGERPRINT_SCOPE,
                'legacy_receipts' => 'v2_same_schema_gate_eligible_v1_not_gate_eligible_cross_schema_not_comparable',
            ],
            'trajectory_assessment' => [
                'schema' => 'plos.agent_trajectory_assessment.v3',
                'diagnostics_schema' => 'plos.agent_trajectory_diagnostics.v1',
                'recovery_guidance_schema' => 'plos.agent_recovery_guidance.v1',
                'recovery_guidance_gate' => 'failure_anchor_plus_bounded_agent_behavior_action',
                'recovery_guidance_authority' => 'advisory_no_retry_policy_tool_budget_or_promotion_authority',
                'content_free' => true,
                'codex_cli_event_normalization' => 'completed_command_events_only',
                'codex_cli_sequence_limit' => 500,
                'codex_cli_sequence_fields' => ['canonical_action', 'success', 'action_fingerprint', 'pair_fingerprint'],
                'codex_cli_action_fingerprint' => [
                    'algorithm' => 'purpose_scoped_hmac_sha256',
                    'key_source' => 'derived_from_app_key_not_logged',
                    'raw_command_retained' => false,
                ],
                'transient_fault_recovery' => [
                    'scope' => 'protected_evaluator_owned_fault_injection',
                    'failed_and_successful_action_identity_must_match' => true,
                    'max_recovery_calls' => CodexOfflineEvalCommand::TRANSIENT_FAULT_RECOVERY_CALL_LIMIT,
                    'automatic_runtime_retry_added' => false,
                    'changes_model_route_or_tool_authority' => false,
                    'content_free' => true,
                ],
                'diagnostic_output_sequence' => 'bounded_canonical_actions_only',
                'raw_commands_or_outputs_retained' => false,
                'formal_verification_required_for_promotion' => true,
                'minimum_consistent_protected_trials_for_promotion' => 3,
                'single_protected_trial' => 'correctness_evidence_but_not_promotion_eligible',
                'unverified_completion_is_observational' => true,
                'procedure_milestone_dependencies' => 'stable topological DAG independent of definition order',
                'unknown_or_cyclic_milestone_dependencies' => 'invalid definition and fail closed',
                'structural_diagnostics' => 'advisory search-loop, redundant-search, re-read-churn, tail-validation, plan-presence, write-checkpoint validation, and failed-work evidence over available tool sequences',
                'write_checkpoint_validation' => [
                    'scope' => 'successful write actions followed by successful validation before the next successful write or terminal state',
                    'metrics' => [
                        'write_checkpoint_count',
                        'validated_write_checkpoint_count',
                        'unvalidated_write_checkpoint_count',
                        'write_checkpoint_validation_coverage',
                    ],
                    'anti_pattern' => 'unvalidated_write_checkpoint',
                    'content_free' => true,
                    'diagnostic_only' => true,
                    'build_health_claimed' => false,
                    'test_suite_stability_claimed' => false,
                ],
                'diagnostics_promotion_authority' => false,
            ],
            'reliability_profile' => [
                'schema' => AgentReliabilityProfileService::SCHEMA,
                'consistency_metrics' => ['outcome', 'trajectory_distribution', 'trajectory_sequence', 'resource'],
                'robustness_conditions' => ['prompt_perturbation', 'environment_perturbation', 'fault_injection'],
                'predictability_metrics' => ['calibration', 'discrimination', 'brier_score'],
                'safety_metrics' => ['compliance_rate', 'mean_harm_severity', 'mean_harm_level', 'max_harm_level', 'hard_constraint_passed'],
                'safety_severity_schema' => AgentActionSeverityService::SCHEMA,
                'safety_severity_scale' => 'L0_L6_action_effect',
                'safety_measured_scope' => 'protected_scratch_workspace_constraints',
                'safety_observed_constraints' => ['protected_file_integrity', 'change_scope'],
                'safety_unobserved_levels' => 'not_inferred_from_model_text_correctness_or_unobserved_effects',
                'missing_dimensions' => 'reported_not_measured_never_inferred',
                'safety_in_overall_score' => false,
                'promotion_authority' => false,
                'content_free' => true,
                'receipt_persistence' => 'not_in_v3_formal_receipt',
                'nominal_trials_control_promotion' => true,
                'condition_trials_control_promotion' => false,
                'prompt_perturbation' => 'three_evaluator_authored_semantically_equivalent_variants_per_protected_case',
                'environment_perturbation' => 'fixed_irrelevant_editorconfig_and_release_history_files_with_same_end_state_verifiers',
                'fault_injection' => 'protected_public_test_fails_once_with_exit_75_and_requires_later_independent_validation_success',
                'condition_result_content' => 'counts_rates_and_fixed_condition_names_only',
                'raw_condition_prompts_or_responses_retained' => false,
                'safety_llm_judge_used' => false,
            ],
            'promotion_rule' => 'A model is not coding-capable from exact replies, self-authored tests, or one successful protected run; it must pass at least three consistent independent trials with allowed-path, protected public, hidden verification, and dispatch-identity checks.',
        ];
    }

    /** @return array<string,mixed> */
    private function llmPortfolioReviewInventory(): array
    {
        $policy = [];
        $job = null;

        try {
            $row = DB::selectOne(
                'SELECT config_value FROM system_configs WHERE section = ? AND config_key = ? LIMIT 1',
                ['ops', 'llm_portfolio_review_policy']
            );
            $decoded = json_decode((string) ($row->config_value ?? ''), true);
            $policy = is_array($decoded) ? $decoded : [];
            $job = DB::selectOne(
                'SELECT name, command, cron_expression, enabled FROM scheduled_jobs WHERE name = ? LIMIT 1',
                ['llm_portfolio_review']
            );
        } catch (\Throwable) {
            // A missing pre-migration schema remains an explicit not-configured state.
        }

        return [
            'schema' => 'plos.llm_portfolio_review.v1',
            'status' => $policy !== [] && $job !== null && (bool) ($job->enabled ?? false)
                ? 'scheduled'
                : 'not_configured',
            'command' => 'php artisan ops:llm-portfolio-review --json --compact',
            'scheduled_job' => $job?->name,
            'cron_expression' => $job?->cron_expression,
            'minimum_days_between_reviews' => (int) ($policy['minimum_days_between_reviews'] ?? 0),
            'required_capability_lanes' => array_values(array_map('strval', (array) ($policy['required_capability_lanes'] ?? []))),
            'candidate_source_classes' => array_values(array_map('strval', (array) data_get($policy, 'codex.candidate_source_classes', []))),
            'fit_inputs' => [
                'llm_instances',
                'ollama_models',
                'system_configs.llm.model_hardware_evidence_policy',
                'codex:offline-capabilities modality_surface',
                'llm_model_request_logs content-free telemetry',
                'official external evidence',
            ],
            'operator_surfaces' => ['5am PLOS report', 'Daily Operations workboard', 'Review Hub', 'Operator Evidence'],
            'automatic_execution' => false,
            'operator_approval_required' => true,
            'compression' => false,
            'precompaction' => false,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function runtimeTextPipelineOrder(bool $compact): array
    {
        $steps = [
            ['AIService::process', 'normalizePrivacyRoutingConfig', 'Reads sensitive_context_terms from system_configs ai.service_runtime_policy, preserves explicit sensitivity choices, and initializes a content-free route trace before any provider call.'],
            ['AIService::process', 'model_role / auto_select_model', 'Resolves role such as fast, standard, quality, coding, vision, embedding, or uncensored.'],
            ['AIService::process', 'applyPreferredOllamaInstanceOrder', 'Applies caller/table preference for local Ollama host ordering.'],
            ['AIService::applyFactualMode', 'factual_mode option', 'Forces lower temperature and stricter factual prompt; disables cache when configured.'],
            ['SemanticCache::get', 'use_cache option', 'Returns a cached semantic match when enabled.'],
            ['AIService::waitForInflightResult', 'dedup option', 'Avoids duplicate concurrent LLM calls for the same prompt/role.'],
            ['AIService::tryCodexExec', 'prefer_codex / ai_mode=codex_exec', 'Optional explicit Codex Exec path; blocked in offline mode.'],
            ['AIService::getOrderedTextOllamaInstances', 'local Ollama first', 'Orders local text-capable instances by table policy, health, and role fit.'],
            ['AIService::applyOllamaInstanceRuntimeConfig', 'llm_instances.config.role_runtime', 'Applies table-owned think/options/num_ctx/temperature/max_tokens/timeout defaults.'],
            ['AIService::applyPatientOllamaProgressPolicy', 'resilient_local_first progress policy', 'Converts model-state timeouts into a dynamic inactivity budget and disables the transport wall clock while progress continues.'],
            ['AIService::tryOllamaWithRetry', 'Ollama request/retry/backoff', 'Calls the selected local model through AIRouter with skip_if_busy defaults.'],
            ['OllamaProgressClient::generate', 'internal NDJSON progress monitor', 'Reconstructs the exact response, publishes content-free live heartbeats, and fails closed on silent or incomplete streams.'],
            ['AIService::evaluateCascade', 'cascade quality gate', 'May reject a low-quality local response and continue to fallback providers.'],
            ['AIService::buildFallbackChain', 'llm_instances fallback providers', 'Builds table-approved external, local_llm, and codex_exec fallback chain under privacy/profile policy.'],
            ['AIService::tryExternalProvider / tryCodexExec', 'fallback execution', 'Attempts ordered fallback providers and records telemetry/circuit state.'],
            ['AIService::sendFailureAlert', 'suppressAlert option', 'Reports all-provider failure unless explicitly suppressed by a caller with its own fallback.'],
        ];

        return $this->indexedSteps($steps, $compact);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function runtimeToolCallingOrder(bool $compact): array
    {
        $steps = [
            ['AIService::processWithTools', 'normalizePrivacyRoutingConfig', 'Applies active privacy/offline routing profile before exposing tools.'],
            ['AIService::selectOllamaInstanceForRole', 'model_role=standard default', 'Chooses a local role model only when ollama_models records a native tool capability; host telemetry then orders eligible instances. Explicit coding-tool requests remain coding-scoped.'],
            ['AIService::resolveModelForProvider', 'per-provider model map', 'Resolves the exact model for the selected local instance and role.'],
            ['AIRouter::processWithTools', 'detectRelevantToolServers', 'Narrows the tool catalog by prompt unless a caller supplies a tool_filter.'],
            ['AIRouter::resolveAvailableTools', 'available_tools / MCPRouter catalog', 'Uses a profile-filtered tool catalog when supplied by the unified offline CLI.'],
            ['AIRouter::processWithTools', 'Ollama /api/chat tools payload', 'Converts MCP tools to Ollama function-tool schema and starts the chat loop.'],
            ['AIRouter::processWithTools', 'maxIterations loop', 'Allows bounded tool calls, appends tool results, and asks the model to continue.'],
            ['MCPRouter::callTool', 'advertised JSON Schema invocation gate', 'Validates required fields, types, constraints, and root argument scope before internal or external dispatch.'],
            ['MCPRouter::callTool', 'server_tool function name', 'Executes the admitted PLOS/repo/genealogy/time tool through policy gates.'],
            ['MCPRouter::callTool', 'advertised outputSchema result gate', 'Validates successful external structuredContent before it reaches an agent or LLM; tool execution errors remain available for correction.'],
            ['AIRouter::formatMcpToolResultForPrompt', 'trust-boundary wrapper', 'Wraps tool results as data before returning them to the model.'],
        ];

        return $this->indexedSteps($steps, $compact);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function runtimeVisionPipelineOrder(): array
    {
        return $this->indexedSteps([
            ['AIService::processImage', 'normalizePrivacyRoutingConfig', 'Vision payloads default to sensitive_data=true and initialize a content-free route trace.'],
            ['AIService::orderOllamaInstancesForRole', 'vision role', 'Tries the best local vision model first with skip_if_busy=true.'],
            ['AIService::tryOllamaVisionWithRetry', 'local vision request', 'Attempts local Ollama vision and can cascade on low quality.'],
            ['AIService::getVisionCapableProviders', 'external vision fallback', 'Uses only providers allowed by privacy/profile policy.'],
            ['AIService::tryExternalVisionProvider', 'external vision request', 'Calls allowed external vision providers when not disabled.'],
        ], false);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function configuredOllamaPipelineTasks(?string $taskFilter, bool $compact): array
    {
        $tasks = app(OllamaPipelineProfileService::class)->getAllTaskProfiles(true);

        $rows = [];
        foreach ($tasks as $name => $definition) {
            if (! is_array($definition)) {
                continue;
            }
            if ($taskFilter !== null && $taskFilter !== '' && ! str_contains(strtolower((string) $name), strtolower($taskFilter))) {
                continue;
            }

            $stages = array_values(array_map('strval', (array) ($definition['stages'] ?? [])));
            $row = [
                'task' => (string) $name,
                'enabled' => (bool) ($definition['enabled'] ?? true),
                'route' => (string) ($definition['route'] ?? 'local_first'),
                'model_role' => (string) ($definition['model_role'] ?? 'standard'),
                'input_kind' => (string) ($definition['input_kind'] ?? ''),
                'output_schema' => (string) ($definition['output_schema'] ?? ''),
                'structured_output_enforced' => (bool) ($definition['structured_output_enforced'] ?? false),
                'structured_output_transport' => ($definition['structured_output_enforced'] ?? false)
                    ? 'ollama_format_json_schema'
                    : null,
                'stages_in_execution_order' => array_map(
                    static fn (string $stage, int $index): array => ['order' => $index + 1, 'stage' => $stage],
                    $stages,
                    array_keys($stages)
                ),
            ];

            if (! $compact) {
                $row['purpose'] = (string) ($definition['purpose'] ?? '');
                $row['compression_enabled'] = false;
                $row['disabled_reason'] = $definition['disabled_reason'] ?? null;
                $row['options'] = [
                    'chunk_chars' => (int) ($definition['chunk_chars'] ?? config('ollama_pipeline.defaults.chunk_chars')),
                    'max_pages_per_chunk' => (int) ($definition['max_pages_per_chunk'] ?? config('ollama_pipeline.defaults.max_pages_per_chunk')),
                    'target_output_chars' => (int) ($definition['target_output_chars'] ?? config('ollama_pipeline.defaults.target_output_chars')),
                    'requires_human_review' => (bool) ($definition['requires_human_review'] ?? config('ollama_pipeline.defaults.requires_human_review')),
                    'deterministic_validation' => (bool) ($definition['deterministic_validation'] ?? config('ollama_pipeline.defaults.deterministic_validation')),
                ];
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function plosMcpToolOrder(bool $compact): array
    {
        $tools = [
            ['plos_schema', 'Read table/schema metadata before SQL.'],
            ['plos_query', 'Run guarded read-only SQL queries.'],
            ['plos_health', 'Read system health snapshot.'],
            ['plos_job_status', 'Inspect scheduled jobs.'],
            ['plos_agent_status', 'Inspect agent sessions.'],
            ['plos_log_search', 'Search Laravel logs.'],
            ['plos_config', 'Read/write system config when policy allows.'],
            ['plos_artisan', 'Run exact allowlisted artisan commands.'],
            ['plos_offline_capabilities', 'Return offline modality/capability manifest.'],
            ['plos_offline_pipeline_tools', 'Return this ordered AI pipeline/tool inventory.'],
            ['plos_offline_sidecar', 'Run read-only multi-model sidecar coordinator.'],
            ['plos_offline_improvement', 'Create review-gated improvement proposals.'],
            ['plos_audio_tts', 'Probe/run local TTS.'],
            ['plos_rerank', 'Run offline reranking.'],
            ['plos_media_analyze', 'Validate or analyze a local image with an installed Ollama vision model.'],
            ['plos_image_generate', 'Validate or generate an image through the authenticated local sidecar.'],
            ['genealogy_search', 'Discover tree-scoped genealogy entity IDs.'],
            ['genealogy_context', 'Fetch compact genealogy context for selected IDs.'],
            ['genealogy_batch_apply', 'Apply dry-run/default guarded genealogy batches.'],
            ['ollama_*', 'Local Ollama summarize/classify/explain/draft helpers.'],
        ];

        return array_map(
            static fn (array $row, int $index): array => $compact
                ? ['order' => $index + 1, 'tool' => $row[0]]
                : ['order' => $index + 1, 'tool' => $row[0], 'purpose' => $row[1]],
            $tools,
            array_keys($tools)
        );
    }

    /**
     * @return list<string>
     */
    private function artisanPipelineCommands(bool $compact): array
    {
        $needles = [
            'codex:offline',
            'offline:',
            'llm:',
            'ollama:',
            'model-host:',
            'rag:',
            'files:',
            'genealogy:',
            'ops:face',
            'audio:',
            'media:',
        ];

        try {
            $commands = array_keys(Artisan::all());
        } catch (\Throwable) {
            return [
                'codex:offline-capabilities',
                'codex:offline-pipeline-tools',
                'codex:offline-sidecar',
                'media:offline-analyze',
                'media:image-generate',
                'content:ocr-candidate-eval',
                'content:stt-candidate-eval',
                'audio:tts-candidate-eval',
                'content:extraction-provenance-audit',
                'ops:repo-context-map-golden-eval',
                'ops:agent-routing-golden-eval',
            ];
        }

        $matches = [];
        foreach ($commands as $command) {
            $lower = strtolower($command);
            if (in_array($lower, [
                'agent:benchmark',
                'agent:trajectory',
                'agent:trajectory-eval',
                'content:ocr-candidate-eval',
                'content:stt-candidate-eval',
                'audio:tts-candidate-eval',
                'content:extraction-provenance-audit',
                'ops:mcp-tool-contract-audit',
                'ops:repo-context-map-golden-eval',
                'ops:agent-routing-golden-eval',
                'whiteroom:provenance-audit',
            ], true)) {
                $matches[] = $command;

                continue;
            }
            foreach ($needles as $needle) {
                if (str_contains($lower, strtolower($needle))) {
                    $matches[] = $command;
                    break;
                }
            }
        }

        sort($matches);

        $matches = array_values(array_unique(array_merge(
            ['agent:trajectory', 'agent:trajectory-eval', 'ops:agent-routing-golden-eval', 'ops:repo-context-map-golden-eval', 'media:offline-analyze', 'media:image-generate', 'media:video-render-spec', 'whiteroom:provenance-audit'],
            $matches
        )));

        return array_slice($matches, 0, $compact ? 40 : 120);
    }

    /**
     * @return array<string, mixed>
     */
    private function routeAndProviderOptions(bool $compact): array
    {
        return [
            'request_options' => [
                'model',
                'model_role',
                'route_trace_id',
                'task_type',
                'auto_select_model',
                'sensitive_data',
                'local_only',
                'prefer_codex',
                'codex_required',
                'output_schema',
                'ollama_output_schema',
                'prefer_external',
                'factual_mode',
                'use_cache',
                'dedup',
                'embedding',
                'expect_json',
                'tool_filter',
                'available_tools',
                'max_tokens',
                'temperature',
                'ollama_options',
                'ollama_think',
                'skip_if_busy',
                'suppressAlert',
            ],
            'table_owned_controls' => [
                'llm_instances.instance_type/is_active/is_healthy/routability/quarantine_status',
                'llm_instances.supported_models',
                'llm_instances.capabilities',
                'ollama_models.capabilities (exact role-model protocol admission)',
                'llm_instances.config.models',
                'llm_instances.config.role_runtime',
                'llm_instances.config.route_policy',
                'llm_instances.config.structured_output',
                'system_configs.routing.offline_mode',
                'system_configs.profile.*',
                'system_configs.ai.router_runtime_policy',
                'system_configs.ai.service_runtime_policy',
                'system_configs.ai.pool_execution_custody (disabled until independent old-writer qualification and exact table catalog binding)',
                'llm_instances.config.slot_policy + llm_instances.max_concurrent (concurrency groups only)',
                'task_custody_records.llm_pool_execution (all unreleased holds; wire-terminal owner CAS release)',
                'system_configs.mcp.router_runtime_policy',
                'system_configs.ai.offline_coding_eval_verifier_docker_image',
                'system_configs.ai.offline_coding_runtime_policy',
                'agent_tool_registry tool policy/freshness rows',
            ],
            'config_owned_controls' => $compact ? [] : [
                'config/ollama_pipeline.php tasks/stages/options',
                'config/ollama_output_schemas.php registered JSON Schema contracts',
                'config/provider_model_capabilities.php exact-model capability and transport policy',
                'config/offline_policy.php profiles',
                'config/dev_agent.php required offline tools',
                'config/mcp.php server policy',
                'config/ai.php default token/cache limits',
                'config/ocr_candidate_eval.php artifact limits and evaluation thresholds',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function liveDbInventory(bool $compact): array
    {
        try {
            $providers = DB::select('
                SELECT instance_id, instance_type, is_active, is_healthy, routability, priority
                FROM llm_instances
                ORDER BY priority ASC, instance_id ASC
                LIMIT ?
            ', [$compact ? 20 : 80]);

            $toolCounts = DB::selectOne('
                SELECT COUNT(*) AS total,
                       SUM(CASE WHEN enabled = 1 THEN 1 ELSE 0 END) AS enabled_count
                FROM agent_tool_registry
            ');

            return [
                'status' => 'available',
                'providers' => array_map(static fn (object $row): array => [
                    'instance_id' => (string) $row->instance_id,
                    'instance_type' => (string) $row->instance_type,
                    'is_active' => (bool) $row->is_active,
                    'is_healthy' => (bool) $row->is_healthy,
                    'routability' => (string) $row->routability,
                    'priority' => (int) $row->priority,
                ], $providers),
                'agent_tool_registry' => [
                    'total' => (int) ($toolCounts->total ?? 0),
                    'enabled' => (int) ($toolCounts->enabled_count ?? 0),
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'unavailable',
                'reason' => 'DB inventory is optional for offline use; static code/config inventory above remains valid.',
                'error' => $compact ? null : mb_substr($e->getMessage(), 0, 500),
            ];
        }
    }

    /**
     * @param  list<array{0:string,1:string,2:string}>  $steps
     * @return list<array<string, mixed>>
     */
    private function indexedSteps(array $steps, bool $compact): array
    {
        return array_map(
            static fn (array $step, int $index): array => $compact
                ? ['order' => $index + 1, 'function' => $step[0], 'tool_or_option' => $step[1]]
                : ['order' => $index + 1, 'function' => $step[0], 'tool_or_option' => $step[1], 'notes' => $step[2]],
            $steps,
            array_keys($steps)
        );
    }

    /** @return list<string> */
    private function documentationContractProjects(): array
    {
        $keys = [];

        foreach (app(WhiteroomProjectRegistryService::class)->all() as $project) {
            if (! is_array($project['documentation_contract'] ?? null)) {
                continue;
            }

            $key = trim((string) ($project['key'] ?? ''));
            if ($key !== '') {
                $keys[] = $key;
            }
        }

        $keys = array_values(array_unique($keys));
        sort($keys, SORT_STRING);

        return $keys;
    }
}
