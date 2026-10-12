<?php

namespace App\Console\Commands;

use App\Services\ImageGenerationService;
use App\Services\OfflineAiPipelineInventoryService;
use App\Services\OfflineTtsService;
use App\Services\WhisperRuntimeService;
use App\Support\SpreadsheetSafeCsv;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class CodexOfflineCapabilitiesCommand extends Command
{
    protected $signature = 'codex:offline-capabilities
        {--json : Emit machine-readable JSON}
        {--probe-sidecars : Probe configured local/LAN sidecars instead of reporting planned capability only}
        {--catalog= : Codex model catalog path override}';

    protected $description = 'Report local/offline Codex, PLOS automation, and media capabilities for smaller local models';

    public function handle(): int
    {
        $payload = $this->payload((bool) $this->option('probe-sidecars'));

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Offline Codex/PLOS capabilities');
        $this->line('  Profile: '.$payload['codex']['profile']);
        $this->line('  Launcher: '.$payload['codex']['launcher']);
        $this->line('  SDK posture: '.$payload['laravel_ai_sdk']['decision']);
        $this->line('  Image sidecar: '.$payload['media']['image_generation']['status']);
        $this->line('  Model catalog: '.$payload['models']['status'].' ('.$payload['models']['model_count'].' models)');
        $this->newLine();

        foreach ($payload['automation']['command_categories'] as $category => $commands) {
            $this->line(sprintf('  %s: %d commands', $category, count($commands)));
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(bool $probeSidecars): array
    {
        $artisanCommands = $this->artisanCommandNames();
        $commandCategories = $this->commandCategories($artisanCommands);
        $imageGeneration = $this->imageSidecar($probeSidecars);
        $transcription = $this->transcriptionCapability();
        $tts = app(OfflineTtsService::class)->capability();
        $embeddings = $this->embeddingCapability();
        $models = $this->modelCatalog();
        $pipelineInventory = app(OfflineAiPipelineInventoryService::class)->payload(compact: true);

        return [
            'schema' => 'plos_codex_offline_capabilities.v1',
            'mode' => 'observe',
            'read_only' => true,
            'generated_at' => now()->toIso8601String(),
            'laravel_ai_sdk' => $this->laravelAiSdkPosture(),
            'hermes_agent' => $this->hermesAgentPosture(),
            'modality_surface' => $this->modalitySurface(
                $artisanCommands,
                $imageGeneration,
                $transcription,
                $tts,
                $embeddings,
                $models,
            ),
            'codex' => [
                'profile' => 'plos-offline-coding',
                'launcher' => './offline',
                'default_chat_mode' => 'Unified Codex CLI through the PLOS AI pipeline',
                'optional_codex_flag' => null,
                'approval_posture' => 'PLOS table rules and explicit session approvals; operator overrides may be saved',
                'discovery_hint' => 'Run php artisan codex:offline-capabilities --json before non-code media or automation tasks.',
                'media_posture' => [
                    'status' => 'text_tool_first',
                    'image_input' => 'Direct Codex CLI image attachment support is not guaranteed for local profiles; use PLOS chat/media/vision routes when a selected model advertises image input.',
                    'image_output' => 'Generated images are returned through the PLOS media_output_image/image-sidecar chat path when configured, not as native Codex CLI output.',
                ],
            ],
            'automation' => [
                'command_categories' => $commandCategories,
                'mcp_posture' => 'Use configured Codex MCP servers when available; fall back to local artisan/shell commands when offline or disconnected.',
                'mcp_tools' => [
                    'status' => 'available_when_plos_mcp_configured',
                    'server' => 'plos',
                    'tools' => [
                        'plos_offline_capabilities',
                        'plos_offline_pipeline_tools',
                        'plos_offline_sidecar',
                        'plos_colibri_research',
                        'plos_colibri_local_research',
                        'plos_audio_tts',
                        'plos_rerank',
                        'plos_media_analyze',
                        'plos_image_generate',
                        'plos_offline_improvement',
                        'plos_artisan exact allowlist entries for codex:offline-capabilities, codex:offline-pipeline-tools, and codex:offline-sidecar',
                    ],
                ],
                'sidecar_coordination' => [
                    'status' => 'primary_only_until_sidecar_admitted',
                    'command' => 'php artisan codex:offline-sidecar --json',
                    'strategy' => 'read-only primary planner by default; an independently admitted sidecar may be supplied explicitly.',
                    'primary_profile' => 'plos-offline-coding',
                    'sidecar_profile' => null,
                    'jetson_status' => 'retired_30b_fallback_waiting_for_candidate_admission',
                    'colibri_research' => [
                        'status' => 'operator_selected_read_only_canary',
                        'mcp_tool' => 'plos_colibri_research',
                        'default_target' => 'local_checkout',
                        'capability_pack' => 'public_web_read_only',
                        'capability_packs' => [
                            'public_web_read_only',
                            'local_offline_read_only',
                        ],
                        'home_lan_required' => false,
                        'requires_colibri_host' => true,
                        'fresh_web_requires_internet' => true,
                        'fresh_web_requires_reachable_searxng' => true,
                        'local_offline_requires_published_rag_rows' => true,
                        'writes_allowed' => false,
                        'fallback_allowed' => false,
                    ],
                ],
                'skill_trust' => [
                    'status' => $this->commandExists($artisanCommands, 'agents:skill-audit')
                        ? 'available'
                        : 'not_detected',
                    'command' => 'php artisan agents:skill-audit --format=json --compact',
                    'scheduled_drift_gate' => 'agent_skill_drift_audit',
                    'integrity_schema' => 'plos.agent_skill_integrity.v1',
                    'import_pin_option' => '--expected-content-sha256=<64-hex>',
                    'scope' => 'complete regular-file bundle manifest; content excluded; symlinks blocked',
                    'signature_boundary' => 'signature presence is unverified until checked with the reviewed trust anchor',
                ],
                'mcp_tool_integrity' => [
                    'status' => $this->commandExists($artisanCommands, 'ops:mcp-tool-contract-audit')
                        ? 'available'
                        : 'not_detected',
                    'command' => 'php artisan ops:mcp-tool-contract-audit --json --compact --fail-on-violations',
                    'scheduled_drift_gate' => 'mcp_tool_contract_audit',
                    'integrity_schema' => 'plos.mcp_tool_contract_audit.v1',
                    'scope' => 'live trusted PLOS tools/list metadata, schemas, annotations, names, and content digest',
                    'external_scanner_boundary' => 'no arbitrary MCP config execution, network request, or metadata upload',
                    'protocol_version' => '2025-11-25',
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
                    'external_client_transports' => ['stdio'],
                    'remote_http_or_sse_client_admitted' => false,
                    'remote_transport_options_rejected' => ['url', 'headers', 'request_init', 'requestInit'],
                    'http_auth_header_forwarding' => 'not_applicable_no_http_or_sse_client',
                    'silent_transport_option_drop_allowed' => false,
                ],
                'rag_ingestion_provenance' => [
                    'status' => $this->commandExists($artisanCommands, 'rag:chunking-provenance-audit')
                        ? 'available'
                        : 'not_detected',
                    'schema' => 'plos.rag_ingestion_provenance.v1',
                    'embedding_contract' => 'provider_instance_model_dimension_pooling_and_input_fingerprint',
                    'semantic_chunking_contract' => 'normalized_effective_options_plus_exact_output_set_fingerprint',
                    'chunk_content_or_individual_hashes_retained' => false,
                    'historical_rows_inferred_or_rewritten' => false,
                    'audit_command' => 'php artisan rag:chunking-provenance-audit --json --compact',
                    'changes_chunking_embedding_routing_or_retrieval' => false,
                ],
                'artifact_export_safety' => [
                    'status' => class_exists(SpreadsheetSafeCsv::class)
                        ? 'available'
                        : 'not_detected',
                    'schema' => 'plos.artifact_export_safety.v1',
                    'generated_csv_scope' => 'research-history downloads and genealogy source-audit packages',
                    'spreadsheet_formula_neutralization' => 'source_enforced_no_disable_override',
                    'trigger_coverage' => 'ASCII and full-width formula initiators after whitespace, zero-width characters, or BOM; leading tab, CR, and LF',
                    'csv_quoting' => 'php_fputcsv_with_escape_disabled',
                    'field_detail_preserved' => true,
                    'stored_data_changed' => false,
                ],
                'pipeline_scope' => [
                    'developer maintenance',
                    'Laravel/artisan diagnostics',
                    'PLOS ops and scheduled-output audits',
                    'RAG/file/document workflows',
                    'genealogy/media intake under existing operator gates',
                    'sidecar readiness checks',
                    'review-gated offline skill/memory improvement proposals',
                ],
                'ai_pipeline_inventory' => [
                    'status' => 'available',
                    'command' => 'php artisan codex:offline-pipeline-tools --json',
                    'mcp_tool' => 'plos_offline_pipeline_tools',
                    'offline_shell' => '/pipeline-tools',
                    'runtime_text_pipeline_order' => $pipelineInventory['runtime_text_pipeline_order'] ?? [],
                    'configured_task_count' => count($pipelineInventory['configured_ollama_pipeline_tasks'] ?? []),
                ],
                'genealogy_support' => [
                    'status' => 'available',
                    'discovery_commands' => [
                        'php artisan list genealogy --format=json',
                        'php artisan codex:offline-capabilities --json',
                    ],
                    'mcp_tools' => [
                        'genealogy_search',
                        'genealogy_context',
                        'genealogy_batch_apply',
                    ],
                    'write_gate' => 'tree-scoped, dry-run-first when supported, operator approval required for identity decisions and writeback',
                    'identity_boundary' => 'Face and photo identity decisions remain operator-owned; offline agents may stage evidence but must not auto-identify people.',
                    'evidence_asset_identity' => [
                        'schema' => 'plos.genealogy_evidence_asset_identity.v1',
                        'default' => 'exact locator identity',
                        'transport_normalization' => 'known signed-transport and tracking parameters only; semantic query parameters remain identity-bearing',
                        'source_generation' => 'explicit source generation, revision, version, revision id, object version, or source etag scopes identity when supplied',
                        'raw_identity_values_included' => false,
                        'changes_review_or_identity_authority' => false,
                    ],
                ],
            ],
            'media' => [
                'image_generation' => $imageGeneration,
                'transcription' => $transcription,
                'audio_output' => $tts,
                'embeddings' => $embeddings,
                'vision' => [
                    'status' => 'model-dependent',
                    'note' => 'Vision-capable local models may be present, but direct offline Codex media input remains conservative; use PLOS media pipelines or sidecars when possible.',
                ],
            ],
            'models' => $models,
            'operator_gates' => [
                'writes' => 'approval-required',
                'destructive_actions' => 'blocked unless explicitly approved outside this report',
                'face_identity' => 'operator-owned; offline agents may stage evidence but must not auto-identify people',
                'external_network' => 'not required for this command; optional sidecar probes only hit configured local/LAN URLs',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function laravelAiSdkPosture(): array
    {
        return [
            'source' => 'https://github.com/laravel/ai',
            'docs' => 'https://laravel.com/docs/ai-sdk',
            'license' => 'MIT',
            'decision' => 'watch_and_harvest_patterns',
            'dependency_installed' => class_exists('Laravel\\Ai\\AiServiceProvider'),
            'adoption_gate' => 'Do not add as a runtime dependency until provider fit, PHP/package constraints, local/offline adapters, and PLOS policy overlap are reviewed.',
            'harvested_patterns' => [
                'typed modality capabilities',
                'agent/tool/structured-output surface separation',
                'image/audio/embedding APIs as explicit capabilities',
                'fake/probe split for tests and readiness checks',
                'provider abstraction without replacing the PLOS control plane',
            ],
            'surface_map' => $this->laravelAiSdkSurfaceMap(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function hermesAgentPosture(): array
    {
        return [
            'source' => 'https://github.com/NousResearch/hermes-agent',
            'license' => 'MIT',
            'decision' => 'pattern_reference_not_runtime_dependency',
            'harvested_patterns' => [
                'review-gated skill and memory improvement loop',
                'slash-command style repeatable workflows',
                'cross-session recall as a local-first capability',
                'toolset/provider flexibility without replacing the PLOS control plane',
            ],
            'plos_surface' => [
                'command' => 'php artisan codex:offline-improvement --json',
                'prompt_candidate_eval' => 'php artisan codex:offline-prompt-candidate-eval --json --compact --artifact=<storage-app-relative-path> --fail-on-ineligible',
                'review_command' => 'php artisan codex:offline-improvement-review --json --compact',
                'scheduled_job' => 'offline_improvement_review',
                'mcp_tool' => 'plos_offline_improvement',
                'write_gate' => 'default observe-only; --write-report stores a review packet but does not mutate skills, memory, or production data',
                'prompt_gate' => 'hashes-only candidate artifacts require labeled training hypotheses, disjoint held-out cases, zero per-case metric regressions, measurable primary-metric gain, and operator review; no prompt is generated or activated',
                'attention_path' => 'daily scheduled job creates/reuses one pending skill_optimization review item when saved reports exist',
            ],
        ];
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function laravelAiSdkSurfaceMap(): array
    {
        return [
            'providers' => [
                'sdk' => 'Unified provider labs including OpenAI, Anthropic, Gemini, Ollama, OpenRouter, Bedrock, Cohere, DeepSeek, Groq, Jina, Mistral, Voyage, xAI, Azure OpenAI, and ElevenLabs.',
                'plos_offline' => 'Table-driven PLOS routing across local Ollama/Codex/BitLLM-style providers and configured external providers; SDK not installed as runtime control plane.',
            ],
            'agents_and_context' => [
                'sdk' => 'Agent contract, promptable agents, conversation context, streaming, queueing, tools, structured output, attachments, events, and testing fakes.',
                'plos_offline' => 'Existing PLOS agents, supervisors, managers, MCP tools, scheduled jobs, and Codex CLI sessions; sidecar coordination is read-only and writeback stays behind approval gates.',
            ],
            'text_and_structured_output' => [
                'sdk' => 'Chat/text generation plus structured output support through a common API.',
                'plos_offline' => 'AIService, Codex CLI, MCP tools, offline evals, and JSON artisan manifests; structured output is command/tool specific.',
            ],
            'tool_use' => [
                'sdk' => 'Agent tools and function/tool calling abstractions.',
                'plos_offline' => 'Codex command_execution, MCP tools, PLOS artisan allowlists, and existing agent tool registry.',
            ],
            'image_generation' => [
                'sdk' => 'Image generation API with testing fake support.',
                'plos_offline' => 'PLOS media_output_image chat route and authenticated image sidecar when configured; capability is reported but not a native Codex CLI output mode.',
            ],
            'image_input_vision' => [
                'sdk' => 'Attachment-capable agent flows and provider-specific vision support.',
                'plos_offline' => 'PLOS chat/media/vision routes when the selected model profile advertises image input; offline Codex CLI profiles stay conservative and text/tool-first.',
            ],
            'audio_tts' => [
                'sdk' => 'Audio/text-to-speech generation API.',
                'plos_offline' => 'audio:tts probes local piper/espeak/flite/say-style engines and can synthesize only with --execute; exposed to MCP as plos_audio_tts.',
            ],
            'transcription_stt' => [
                'sdk' => 'Audio transcription API.',
                'plos_offline' => 'Local Whisper-compatible command readiness is reported; genealogy/media transcription commands already exist under existing gates.',
            ],
            'embeddings' => [
                'sdk' => 'Embedding generation API.',
                'plos_offline' => 'Ollama embedding configuration and existing PLOS RAG/vector pipelines.',
            ],
            'reranking' => [
                'sdk' => 'Reranking API.',
                'plos_offline' => 'rag:rerank provides lexical and optional embedding-backed scoring through PLOS AIService; rag:rerank-eval adds heterogeneous qrels, hard negatives, ranking metrics, claim-level retrieval diagnostics, and an optional content-free generator grounding envelope; exposed to MCP as plos_rerank plus the exact allowlisted baseline eval.',
            ],
            'files' => [
                'sdk' => 'File upload/management API.',
                'plos_offline' => 'PLOS file registry, Joplin attachments, chat attachments, media intake, and lifecycle commands under existing storage/privacy rules.',
            ],
            'vector_stores' => [
                'sdk' => 'Vector store management API.',
                'plos_offline' => 'Existing PostgreSQL/pgvector RAG, sentence, graph, and media indexes; no SDK vector-store facade adopted.',
            ],
            'failover_testing_events' => [
                'sdk' => 'Provider failover, testing fakes, and events.',
                'plos_offline' => 'Existing route policy, drift checks, eval/smoke commands, probe-sidecar split, logs, scheduled-output audits, and operational events.',
            ],
        ];
    }

    /**
     * @param  list<string>  $commands
     * @param  array<string, mixed>  $imageGeneration
     * @param  array<string, mixed>  $transcription
     * @param  array<string, mixed>  $tts
     * @param  array<string, mixed>  $embeddings
     * @param  array<string, mixed>  $models
     * @return array<string, array<string, mixed>>
     */
    private function modalitySurface(
        array $commands,
        array $imageGeneration,
        array $transcription,
        array $tts,
        array $embeddings,
        array $models,
    ): array {
        $hasChatMedia = $this->commandExists($commands, 'codex:offline-capabilities')
            && is_file(base_path('offline'));
        $hasGenealogyMedia = $this->commandExists($commands, 'genealogy:media-intake-report')
            || $this->commandExists($commands, 'genealogy:transcribe-media');
        $hasVideoRenderBundle = $this->commandExists($commands, 'media:video-render-spec');
        $hasOfflineImageAnalysis = $this->commandExists($commands, 'media:offline-analyze');
        $hasOfflineImageGeneration = $this->commandExists($commands, 'media:image-generate');
        $hasRerank = $this->commandExists($commands, 'rag:rerank');
        $hasRerankEval = $this->commandExists($commands, 'rag:rerank-eval');
        $hasOcrCandidateEval = $this->commandExists($commands, 'content:ocr-candidate-eval');
        $hasSttCandidateEval = $this->commandExists($commands, 'content:stt-candidate-eval');
        $hasTtsCandidateEval = $this->commandExists($commands, 'audio:tts-candidate-eval');
        $hasExtractionProvenance = $this->commandExists($commands, 'content:extraction-provenance-audit');
        $hasRag = $hasRerank
            || $this->commandExists($commands, 'rag:scale-review')
            || $this->commandExists($commands, 'rag:backlog-report');
        $hasFiles = $this->commandExists($commands, 'files:registry')
            || $this->commandExists($commands, 'files:reconcile-lifecycle');

        return [
            'text_input' => [
                'status' => 'available',
                'surfaces' => ['./offline', 'PLOS AIService'],
            ],
            'text_output' => [
                'status' => 'available',
                'surfaces' => ['Codex CLI responses', 'artisan JSON reports', 'PLOS chat responses'],
            ],
            'structured_output' => [
                'status' => 'available',
                'surfaces' => ['JSON artisan commands', 'MCP tool payloads', 'offline eval harness'],
            ],
            'tool_use' => [
                'status' => 'available',
                'surfaces' => ['Codex command_execution', 'PLOS MCP tools', 'plos_artisan allowlist', 'agent_tool_registry'],
            ],
            'agents' => [
                'status' => 'available',
                'surfaces' => ['PLOS agents', 'supervisors/managers', 'scheduled jobs', 'read-only offline sidecar'],
                'write_gate' => 'approval-required; sidecar lanes are read-only',
            ],
            'file_input' => [
                'status' => $hasFiles ? 'available' : 'not_detected',
                'surfaces' => ['file registry', 'Joplin attachments', 'chat attachments', 'media intake'],
            ],
            'file_output' => [
                'status' => $hasFiles ? 'available' : 'not_detected',
                'surfaces' => ['assistant attachments', 'Joplin notes/attachments', 'generated reports'],
            ],
            'image_input' => [
                'status' => $hasOfflineImageAnalysis
                    ? 'available_with_installed_model'
                    : ($hasChatMedia || $hasGenealogyMedia ? 'model_dependent' : 'not_detected'),
                'surfaces' => array_values(array_filter([
                    'PLOS chat/media/vision routes',
                    $hasOfflineImageAnalysis ? 'media:offline-analyze and plos_media_analyze' : null,
                    'genealogy media review',
                    'face/photo evidence staging',
                ])),
                'codex_cli_direct' => 'not_guaranteed_for_local_profiles',
                'write_gate' => 'media:offline-analyze defaults to dry-run; --execute sends content only to the configured local Ollama endpoint',
            ],
            'ocr_text_extraction' => [
                'status' => $hasOcrCandidateEval ? 'available_with_candidate_gate' : 'pipeline_specific',
                'surfaces' => $hasOcrCandidateEval
                    ? [
                        'Tika and Tesseract content extraction',
                        'genealogy TrOCR and vision escalation',
                        'content:ocr-candidate-eval fidelity and promotion evidence',
                        ...($hasExtractionProvenance ? ['content:extraction-provenance-audit durable extractor, confidence, page, and review evidence'] : []),
                    ]
                    : ['Tika and Tesseract content extraction', 'genealogy TrOCR and vision escalation'],
                'quality_gate' => $hasOcrCandidateEval
                    ? 'seven required OCR capabilities, normalized edit fidelity, exact required/forbidden spans, bounded reading-order pairs, page provenance, blank-page hallucination, latency, VRAM, absolute per-case floors, and zero per-case quality regression'
                    : 'not_detected',
                'route_change' => 'operator-reviewed only; evaluation cannot change production OCR routing',
                'provenance' => $hasExtractionProvenance
                    ? 'future extractions persist a content-free versioned contract into a file-registry side table and RAG metadata; historical rows remain observation debt'
                    : 'not_detected',
            ],
            'image_output' => [
                'status' => (string) ($imageGeneration['status'] ?? 'not_configured'),
                'surfaces' => array_values(array_filter([
                    'PLOS media_output_image chat mode',
                    $hasOfflineImageGeneration ? 'media:image-generate and plos_image_generate' : null,
                    'authenticated image sidecar',
                ])),
                'url_configured' => (bool) ($imageGeneration['url_configured'] ?? false),
                'default_model' => $imageGeneration['default_model'] ?? null,
                'models' => $imageGeneration['models'] ?? [],
                'model_selection' => 'Use media:image-generate --model=<alias> or plos_image_generate model; image generators are sidecar models, not Ollama chat models.',
                'write_gate' => 'media:image-generate defaults to dry-run; --execute stores only under storage/app/offline-media',
            ],
            'audio_input' => [
                'status' => (string) ($transcription['status'] ?? 'not_detected'),
                'surfaces' => array_values(array_filter([
                    'local Whisper-compatible transcription',
                    'genealogy/media transcription commands',
                    $hasSttCandidateEval ? 'content:stt-candidate-eval heterogeneous candidate evidence' : null,
                ])),
                'candidate_gate' => $hasSttCandidateEval ? 'available' : 'not_detected',
                'quality_gate' => $hasSttCandidateEval
                    ? 'seven required speech capabilities, WER, CER, normalized critical-phrase recall, language correctness, silence hallucination, real-time factor, absolute per-case floors, and zero per-case quality regression'
                    : 'not_detected',
                'route_change' => 'operator-reviewed only; evaluation cannot change production transcription routing',
                'model_or_weight_install' => false,
            ],
            'audio_output' => [
                'status' => (string) ($tts['status'] ?? 'not_detected'),
                'surfaces' => array_values(array_filter([
                    'audio:tts local command adapter',
                    'plos_audio_tts MCP tool',
                    $hasTtsCandidateEval ? 'audio:tts-candidate-eval heterogeneous candidate evidence' : null,
                ])),
                'write_gate' => 'audio:tts requires --execute before creating files',
                'candidate_gate' => $hasTtsCandidateEval ? 'available' : 'not_detected',
                'quality_gate' => $hasTtsCandidateEval
                    ? 'eight required text capabilities, ASR-derived intelligibility, blind assessor provenance, naturalness, clipping, loudness, silence, format, real-time factor, absolute per-case floors, and zero per-case quality regression'
                    : 'not_detected',
                'route_change' => 'operator-reviewed only; evaluation cannot install a runtime or change production TTS routing',
                'model_voice_or_weight_install' => false,
            ],
            'video_output' => [
                'status' => $hasVideoRenderBundle ? 'available_bundle_contract' : 'not_detected',
                'surfaces' => $hasVideoRenderBundle
                    ? ['media:video-render-spec offline recap bundle', 'optional HyperFrames sidecar readiness doctor']
                    : [],
                'write_gate' => 'preview by default; --write plus a unique bundle key creates only local HTML and a manifest',
                'renderer_posture' => 'not installed or invoked; isolated operator-reviewed acceptance required',
            ],
            'embeddings' => [
                'status' => (string) ($embeddings['status'] ?? 'not_configured'),
                'surfaces' => ['Ollama embeddings', 'PLOS RAG', 'agent episodic memory', 'genealogy/person/media indexes'],
            ],
            'reranking' => [
                'status' => $hasRerank ? 'available' : ($hasRag ? 'pipeline_specific' : 'not_detected'),
                'surfaces' => array_values(array_filter([
                    'rag:rerank generic reranker',
                    $hasRerankEval ? 'rag:rerank-eval versioned regression gate' : null,
                    $this->commandExists($commands, 'rag:reranker-sidecar') ? 'rag:reranker-sidecar authenticated challenger runtime' : null,
                    'plos_rerank MCP tool',
                    'RAG scale/review pipelines',
                ])),
                'embedding_backed' => $hasRerank ? 'optional via --use-embeddings through PLOS AIService' : 'not_available',
                'quality_gate' => $hasRerankEval ? 'graded qrels, hard negatives, ranking metrics, claim recall, context precision, optional claim/citation faithfulness, hallucination and unsupported-citation checks, and separate regression/promotion thresholds' : 'not_detected',
            ],
            'vector_stores' => [
                'status' => $hasRag ? 'available' : 'not_detected',
                'surfaces' => ['PostgreSQL pgvector', 'RAG sentence embeddings', 'knowledge graph', 'face/person embeddings'],
            ],
            'failover' => [
                'status' => 'primary_only_until_sidecar_admitted',
                'surfaces' => ['offline launcher primary route', 'explicit admitted sidecar/fallback only', 'PLOS route policy', 'drift checks', 'provider sync'],
            ],
            'testing_fakes_probes' => [
                'status' => 'available',
                'surfaces' => ['offline eval harness', 'Codex command_execution smoke', 'probe-sidecars', 'MCP allowlist tests'],
            ],
            'events_observability' => [
                'status' => 'available',
                'surfaces' => ['scheduled output audits', 'runtime diagnostics', 'agent traces', 'Laravel logs'],
            ],
            'model_catalog' => [
                'status' => (string) ($models['status'] ?? 'not_found'),
                'model_count' => (int) ($models['model_count'] ?? 0),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function artisanCommandNames(): array
    {
        $commands = array_keys(Artisan::all());
        sort($commands);

        return $commands;
    }

    private function commandExists(array $commands, string $command): bool
    {
        return in_array($command, $commands, true);
    }

    /**
     * @param  list<string>  $commands
     * @return array<string, list<string>>
     */
    private function commandCategories(array $commands): array
    {
        return [
            'offline_dev' => $this->matchingCommands($commands, ['codex:offline']),
            'agent_eval' => $this->matchingCommands($commands, ['agent:trajectory', 'agent:benchmark', 'ops:agent-behavior-golden-eval', 'ops:prospective-intent-eval', 'ops:trace-golden-eval']),
            'ops' => $this->matchingCommands($commands, ['ops:', 'scheduler:', 'routing:profile', 'llm:']),
            'media' => array_values(array_unique(array_merge(
                $this->commandExists($commands, 'media:offline-analyze') ? ['media:offline-analyze'] : [],
                $this->commandExists($commands, 'media:image-generate') ? ['media:image-generate'] : [],
                $this->commandExists($commands, 'media:video-render-spec') ? ['media:video-render-spec'] : [],
                $this->commandExists($commands, 'content:stt-candidate-eval') ? ['content:stt-candidate-eval'] : [],
                $this->matchingCommands($commands, ['media', 'audio:', 'genealogy:media', 'genealogy:transcribe', 'genealogy:enrich', 'files:', 'rag:', 'joplin'])
            ))),
            'research_genealogy' => $this->matchingCommands($commands, ['genealogy:', 'research:', 'source']),
            'sidecars' => $this->matchingCommands($commands, ['whiteroom:', 'agents:skill-audit']),
        ];
    }

    /**
     * @param  list<string>  $commands
     * @param  list<string>  $needles
     * @return list<string>
     */
    private function matchingCommands(array $commands, array $needles): array
    {
        $matches = [];
        foreach ($commands as $command) {
            $lower = strtolower($command);
            foreach ($needles as $needle) {
                if (str_contains($lower, strtolower($needle))) {
                    $matches[] = $command;
                    break;
                }
            }
        }

        return array_slice(array_unique($matches), 0, 24);
    }

    /**
     * @return array<string, mixed>
     */
    private function imageSidecar(bool $probe): array
    {
        return app(ImageGenerationService::class)->capability($probe);
    }

    /**
     * @return array<string, mixed>
     */
    private function transcriptionCapability(): array
    {
        return app(WhisperRuntimeService::class)->capability();
    }

    /**
     * @return array<string, mixed>
     */
    private function embeddingCapability(): array
    {
        $model = trim((string) config('services.ollama.embedding_model', ''));
        $url = trim((string) config('services.ollama.api_url', ''));

        return [
            'status' => $model !== '' ? 'configured' : 'not_configured',
            'provider' => 'Ollama embeddings through existing PLOS routing',
            'model' => $model !== '' ? $model : null,
            'url_configured' => $url !== '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function modelCatalog(): array
    {
        $path = trim((string) ($this->option('catalog') ?: ''));
        if ($path === '') {
            $home = (string) (getenv('HOME') ?: '');
            $path = (string) (getenv('CODEX_MODEL_CATALOG') ?: ($home !== '' ? $home.'/.codex/model-catalogs/plos-offline-models.json' : ''));
        }

        if ($path === '' || ! is_file($path)) {
            return [
                'status' => 'not_found',
                'path' => $path !== '' ? $path : null,
                'model_count' => 0,
                'models' => [],
            ];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        $models = is_array($decoded['models'] ?? null) ? $decoded['models'] : [];
        $summaries = [];

        foreach ($models as $model) {
            if (! is_array($model)) {
                continue;
            }

            $plos = is_array($model['plos_capabilities'] ?? null) ? $model['plos_capabilities'] : [];
            $summaries[] = [
                'slug' => (string) ($model['slug'] ?? ''),
                'category' => (string) ($plos['category'] ?? 'Uncategorized'),
                'priority' => (int) ($model['priority'] ?? 0),
                'context_window' => (int) ($model['context_window'] ?? 0),
                'modalities' => (array) ($plos['modalities'] ?? $model['input_modalities'] ?? ['text']),
                'tool_use' => $plos['tool_use'] ?? 'unknown',
                'vision_candidate' => (bool) ($plos['vision_candidate'] ?? false),
            ];
        }

        return [
            'status' => 'loaded',
            'path' => $path,
            'generated_at' => $decoded['generated_at'] ?? null,
            'model_count' => count($summaries),
            'models' => $summaries,
        ];
    }
}
