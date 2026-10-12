<?php

return [
    'schema' => 'plos.repo_context_map_golden_eval.v1',

    'thresholds' => [
        'minimum_case_pass_rate' => 1.0,
        'minimum_mrr' => 0.5,
        'minimum_mean_recall_at_k' => 0.5,
        'minimum_mean_precision_at_k' => 0.25,
        'minimum_mean_context_efficiency' => 0.25,
        'minimum_search_space_complete_rate' => 1.0,
    ],

    'cases' => [
        'agent_behavior_gate_to_doctor' => [
            'task' => 'How does agent behavior golden evaluation reach production services and Agent Doctor?',
            'top_k' => 10,
            'primary_file' => 'app/Services/Ops/AgentBehaviorGoldenEvalService.php',
            'relevant_files' => [
                'app/Services/Ops/AgentBehaviorGoldenEvalService.php',
                'app/Console/Commands/OpsAgentBehaviorGoldenEvalCommand.php',
                'app/Services/Ops/AgentDoctorService.php',
                'app/Console/Commands/AgentDoctorCommand.php',
            ],
            'minimum_relevant' => 3,
            'hard_negative_files' => [
                'app/Services/AgentLoopService.php',
            ],
        ],
        'lossless_offline_prompt_pipeline' => [
            'task' => 'Which code enforces lossless prompts and disables compression in the offline AI pipeline?',
            'top_k' => 10,
            'primary_file' => 'app/Services/OfflineAiPipelineInventoryService.php',
            'relevant_files' => [
                'app/Services/OfflineAiPipelineInventoryService.php',
                'app/Console/Commands/CodexOfflinePipelineToolsCommand.php',
                'app/Services/AIService.php',
            ],
            'minimum_relevant' => 2,
            'hard_negative_files' => [
                'app/Console/Commands/MorningDigestCommand.php',
            ],
        ],
        'genealogy_picture_review_frontend' => [
            'task' => 'Where does the genealogy picture review UI approve and link a photo to a person?',
            'top_k' => 10,
            'primary_file' => 'resources/js/src/views/GenealogyView.vue',
            'relevant_files' => [
                'resources/js/src/views/GenealogyView.vue',
                'app/Http/Controllers/GenealogyController.php',
                'app/Http/Controllers/Api/MediaBrowserController.php',
            ],
            'minimum_relevant' => 3,
            'hard_negative_files' => [
                'database/schema/mysql-schema.sql',
            ],
        ],
        'mcp_structured_output_contract' => [
            'task' => 'How are MCP tool output schemas validated before structured results are returned?',
            'top_k' => 10,
            'primary_file' => 'app/Engine/MCPRouter.php',
            'relevant_files' => [
                'app/Engine/MCPRouter.php',
                'app/Engine/AIRouter.php',
                'app/Services/AgentToolRegistryService.php',
            ],
            'minimum_relevant' => 2,
            'hard_negative_files' => [
                'app/Console/Commands/TestMCPToolCalling.php',
            ],
        ],
        'table_owned_scheduler_runtime_policy' => [
            'task' => 'Where are scheduled job runtime limits and table-owned policy enforced and tested?',
            'top_k' => 10,
            'primary_file' => 'app/Services/ScheduledJobService.php',
            'relevant_files' => [
                'app/Services/ScheduledJobService.php',
                'app/Console/Commands/OpsRuntimePolicyLeakAuditCommand.php',
                'tests/Unit/Services/ScheduledJobServiceTest.php',
            ],
            'minimum_relevant' => 3,
            'hard_negative_files' => [
                'app/Console/Commands/MorningDigestCommand.php',
            ],
        ],
        'repo_edit_conflict_safe_recovery' => [
            'task' => 'How can a repo edit be restored without overwriting a later operator change?',
            'top_k' => 10,
            'primary_file' => 'app/Services/RepoEditRecoveryService.php',
            'relevant_files' => [
                'app/Services/RepoEditRecoveryService.php',
                'app/Services/RepoDevMCPService.php',
                'tests/Unit/Services/RepoDevMCPServiceTest.php',
            ],
            'minimum_relevant' => 3,
            'hard_negative_files' => [
                'app/Services/OperatorEvidenceService.php',
            ],
        ],
    ],
];
