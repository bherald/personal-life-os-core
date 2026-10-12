<?php

return [
    'thresholds' => [
        'minimum_top_1_accuracy' => 1.0,
        'minimum_mrr' => 1.0,
        'maximum_hard_negative_ahead_count' => 0,
    ],

    'cases' => [
        'gpu_capacity_and_provider_health' => [
            'task' => 'diagnose gpu capacity and provider health',
            'expected_agent_id' => 'ai-ops',
            'hard_negative_agent_ids' => ['system-guardian'],
        ],
        'application_log_signature_analysis' => [
            'task' => 'cluster recurring error signatures across application logs',
            'expected_agent_id' => 'log-analyst',
            'hard_negative_agent_ids' => ['system-guardian'],
        ],
        'genealogy_record_research' => [
            'task' => 'search census and vital records for genealogy evidence',
            'expected_agent_id' => 'genealogy-researcher',
            'hard_negative_agent_ids' => ['research_agent'],
        ],
        'workflow_failure_operations' => [
            'task' => 'inspect failed workflows dead letter queue and schedule health',
            'expected_agent_id' => 'workflow-ops',
            'hard_negative_agent_ids' => ['system-guardian'],
        ],
        'knowledge_embedding_maintenance' => [
            'task' => 'maintain rag embeddings and knowledge quality',
            'expected_agent_id' => 'knowledge-curator',
            'hard_negative_agent_ids' => ['research-ops'],
        ],
        'claim_evidence_verification' => [
            'task' => 'verify claims using evidence quality and claim verification',
            'expected_agent_id' => 'factcheck-ops',
            'hard_negative_agent_ids' => ['research-analyst'],
        ],
    ],

    'challenger_cases' => [
        'character_ngram_related_word_form' => [
            'task' => 'presentation',
            'selector_method' => 'character_ngram_v1',
            'expected_agent_id' => 'presentations',
            'hard_negative_agent_ids' => ['spreadsheets'],
            'baseline_expected_empty' => true,
            'catalog' => [
                'presentations' => [
                    'name' => 'Presentations',
                    'capabilities' => [],
                    'description' => 'Create visual decks.',
                    'is_active' => true,
                ],
                'spreadsheets' => [
                    'name' => 'Spreadsheets',
                    'capabilities' => [],
                    'description' => 'Analyze tabular data.',
                    'is_active' => true,
                ],
            ],
        ],
        'character_ngram_typo' => [
            'task' => 'postgrez',
            'selector_method' => 'character_ngram_v1',
            'expected_agent_id' => 'postgresql',
            'hard_negative_agent_ids' => ['postscript'],
            'baseline_expected_empty' => true,
            'catalog' => [
                'postgresql' => [
                    'name' => 'PostgreSQL',
                    'capabilities' => [],
                    'description' => 'Manage a relational database.',
                    'is_active' => true,
                ],
                'postscript' => [
                    'name' => 'PostScript',
                    'capabilities' => [],
                    'description' => 'Render printable documents.',
                    'is_active' => true,
                ],
            ],
        ],
        'character_ngram_cjk_without_word_boundaries' => [
            'task' => '帮我制作演示文稿',
            'selector_method' => 'character_ngram_v1',
            'expected_agent_id' => 'slides',
            'hard_negative_agent_ids' => ['sheets'],
            'baseline_expected_empty' => true,
            'catalog' => [
                'slides' => [
                    'name' => '演示文稿',
                    'capabilities' => [],
                    'description' => '创建幻灯片。',
                    'is_active' => true,
                ],
                'sheets' => [
                    'name' => '电子表格',
                    'capabilities' => [],
                    'description' => '分析表格数据。',
                    'is_active' => true,
                ],
            ],
        ],
    ],
];
