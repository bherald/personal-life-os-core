<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SOURCE_SESSION = 'genealogy-expert-seed-2026-05-12';

    public function up(): void
    {
        $procedures = [
            [
                'agent_id' => 'genealogy-researcher',
                'name' => 'GPS identity resolution before fact proposals',
                'trigger_pattern' => 'genealogy identity matching nickname married name face label media person link duplicate spouse parent child uncertain subject match',
                'strategy_insight' => 'Resolve the subject identity before accepting any fact. Require name plus date, place, relationship, FAN, occupation, media context, or source-chain anchors; never link from name alone.',
                'tools' => [
                    'recall_procedures',
                    'recall_episodes',
                    'get_person_full',
                    'surname_phonetic_matches',
                    'get_siblings',
                    'fan_get_cooccurrences',
                    'evidence_build_chain',
                    'detect_duplicates',
                    'submit_for_review',
                ],
            ],
            [
                'agent_id' => 'genealogy-researcher',
                'name' => 'Source-backed fact update workflow',
                'trigger_pattern' => 'genealogy birth death burial place spouse parent child fact update found record source citation evidence confidence',
                'strategy_insight' => 'A fact update needs a real source, source quality, information quality, evidence type, temporal fit, conflict check, and provenance. Weak but useful evidence becomes a proposal or research task.',
                'tools' => [
                    'get_repositories_for_person',
                    'source_search_all',
                    'get_person_sources',
                    'evidence_build_chain',
                    'assess_gps_compliance',
                    'detect_source_conflicts',
                    'propose_change',
                    'submit_for_review',
                    'save_procedure',
                ],
            ],
            [
                'agent_id' => 'genealogy-researcher',
                'name' => 'Negative search coverage workflow',
                'trigger_pattern' => 'genealogy no records found empty search negative evidence exhausted repository coverage repeat search avoid duplicate research',
                'strategy_insight' => 'No-result searches are useful GPS evidence when logged with repository, query, date range, geography, and rationale. Do not submit negative results as fact proposals.',
                'tools' => [
                    'get_recent_searches',
                    'get_search_coverage',
                    'get_repositories_for_person',
                    'source_search_all',
                    'log_research_search',
                    'update_search_coverage',
                    'update_hint_status',
                    'create_research_task',
                ],
            ],
            [
                'agent_id' => 'genealogy-records',
                'name' => 'Primary-record jurisdiction sweep',
                'trigger_pattern' => 'genealogy records census vital military immigration naturalization land pension jurisdiction original record image download attach source',
                'strategy_insight' => 'Route by era and jurisdiction first, search the highest-yield repository, prefer source images over indexes, and capture downloadable originals into the FT folder before relying on them.',
                'tools' => [
                    'recall_procedures',
                    'get_repositories_for_person',
                    'source_search_all',
                    'nara_search',
                    'nara_get_objects',
                    'nara_download_best',
                    'nara_copy_to_tree',
                    'log_research_search',
                    'update_search_coverage',
                ],
            ],
            [
                'agent_id' => 'genealogy-newspapers',
                'name' => 'Newspaper identity anchor workflow',
                'trigger_pattern' => 'genealogy newspaper obituary article marriage birth legal notice ocr publication date named relatives identity anchor',
                'strategy_insight' => 'Use publication date, place, named relatives, and article context as identity anchors. Treat OCR snippets as leads until corroborated by page image, publication metadata, and tree context.',
                'tools' => [
                    'recall_procedures',
                    'get_person_full',
                    'surname_phonetic_matches',
                    'newspaper_search',
                    'newspaper_search_obituaries',
                    'internet_archive_search',
                    'log_research_search',
                    'update_search_coverage',
                    'submit_for_review',
                    'save_procedure',
                ],
            ],
            [
                'agent_id' => 'genealogy-web',
                'name' => 'Community profile lead extraction workflow',
                'trigger_pattern' => 'genealogy wikitree web search community profile fan graph rag source citations lead extraction identity match',
                'strategy_insight' => 'Treat community profiles and web pages as leads. Extract their cited sources, verify identity with lifetime/place/relationship anchors, and route uncertain web claims to review.',
                'tools' => [
                    'recall_procedures',
                    'get_person_full',
                    'wikitree_search',
                    'wikitree_get_person',
                    'mcp_searxng_search',
                    'rag_search',
                    'fan_analyze_cluster',
                    'submit_for_review',
                    'save_procedure',
                ],
            ],
            [
                'agent_id' => 'genealogy-analyst',
                'name' => 'Conflict-first GPS proof analysis',
                'trigger_pattern' => 'genealogy evidence conflict proof conclusion gps source disagreement birth death parent spouse place duplicate merge',
                'strategy_insight' => 'Analyze the claim, source classes, subject identity, conflicts, and weakest required link before writing a proof or proposing a resolution.',
                'tools' => [
                    'recall_procedures',
                    'get_person_full',
                    'get_person_sources',
                    'evidence_build_chain',
                    'detect_source_conflicts',
                    'get_source_conflicts',
                    'generate_gps_proof',
                    'submit_for_review',
                    'save_procedure',
                ],
            ],
            [
                'agent_id' => 'genealogy-assessor',
                'name' => 'Research queue triage by genealogical value',
                'trigger_pattern' => 'genealogy research queue priority missing data direct ancestor relationship blocker source conflict export readiness media unlinked',
                'strategy_insight' => 'Prioritize by genealogical value: direct ancestors, relationship blockers, conflicts, export readiness, and media that can prove facts. Avoid duplicate tasks by checking coverage and open work first.',
                'tools' => [
                    'recall_procedures',
                    'get_research_landscape',
                    'get_recent_searches',
                    'get_missing_data_report',
                    'get_open_research_tasks',
                    'get_priority_persons',
                    'get_search_coverage',
                    'create_research_task',
                    'save_procedure',
                ],
            ],
        ];

        foreach ($procedures as $procedure) {
            $exists = DB::table('agent_procedures')
                ->where('agent_id', $procedure['agent_id'])
                ->where('name', $procedure['name'])
                ->exists();

            if ($exists) {
                continue;
            }

            $actions = array_map(
                static fn (string $tool): array => [
                    'tool' => $tool,
                    'params' => [],
                    'params_source' => 'active_person_or_task_context',
                ],
                $procedure['tools']
            );

            DB::table('agent_procedures')->insert([
                'agent_id' => $procedure['agent_id'],
                'name' => $procedure['name'],
                'trigger_pattern' => $procedure['trigger_pattern'],
                'action_sequence' => json_encode($actions, JSON_THROW_ON_ERROR),
                'strategy_insight' => $procedure['strategy_insight'],
                'procedure_type' => 'success',
                'source_session_id' => self::SOURCE_SESSION,
                'is_canonical' => 1,
                'is_shared' => 1,
                'is_retired' => 0,
                'success_rate' => 0.8000,
                'times_used' => 5,
                'times_succeeded' => 4,
                'last_used_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('agent_procedures')
            ->where('source_session_id', self::SOURCE_SESSION)
            ->delete();
    }
};
