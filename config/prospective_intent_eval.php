<?php

return [
    'schema' => 'plos.prospective_intent_eval.v1',

    // Synthetic PLOS-authored contracts. They contain no upstream benchmark data or prompts.
    'scenarios' => [
        'time_and_event_intents_execute_as_exact_due_set' => [
            'intents' => [
                ['id' => 'scheduled_digest', 'due_step' => 2],
                ['id' => 'arrival_followup', 'due_step' => 9, 'cue' => 'arrival_notice', 'proactive_required' => true],
            ],
            'steps' => [
                ['step' => 1, 'selected' => [], 'monitoring_queries' => 1],
                ['step' => 2, 'observed_cues' => ['arrival_notice'], 'selected' => ['scheduled_digest', 'arrival_followup'], 'monitoring_queries' => 2],
            ],
            'expect' => [
                'true_positives' => 2, 'false_positives' => 0, 'false_negatives' => 0,
                'on_time' => 2, 'misses' => 0, 'exact_set_matches' => 2,
                'proactive_required_hits' => 1, 'non_clock_proactive_hits' => 1,
                'monitoring_queries' => 3, 'set_f1' => 1.0, 'exact_set_rate' => 1.0,
            ],
        ],
        'miss_then_late_execution_is_not_an_on_time_hit' => [
            'intents' => [['id' => 'due_report', 'due_step' => 2]],
            'steps' => [
                ['step' => 2, 'selected' => []],
                ['step' => 3, 'selected' => ['due_report']],
            ],
            'expect' => [
                'true_positives' => 0, 'false_positives' => 1, 'false_negatives' => 1,
                'on_time' => 0, 'late' => 1, 'misses' => 1, 'false_alarms' => 0,
                'set_f1' => 0.0, 'exact_set_rate' => 0.0,
            ],
        ],
        'cancelled_intent_selection_is_stale_update_violation' => [
            'intents' => [['id' => 'cancelled_export', 'due_step' => 3]],
            'updates' => [['at_step' => 2, 'intent_id' => 'cancelled_export', 'type' => 'cancel']],
            'steps' => [
                ['step' => 2, 'selected' => []],
                ['step' => 3, 'selected' => [['id' => 'cancelled_export', 'revision' => 1]]],
            ],
            'expect' => [
                'true_positives' => 0, 'false_positives' => 1, 'false_negatives' => 0,
                'update_violations' => 1, 'false_alarms' => 0, 'exact_set_matches' => 1,
                'precision' => 0.0, 'recall' => 1.0,
            ],
        ],
        'reschedule_rejects_old_revision_and_accepts_new_due_state' => [
            'intents' => [['id' => 'rescheduled_sync', 'due_step' => 2]],
            'updates' => [[
                'at_step' => 2, 'intent_id' => 'rescheduled_sync', 'type' => 'reschedule',
                'due_step' => 4, 'revision' => 2,
            ]],
            'steps' => [
                ['step' => 2, 'selected' => [['id' => 'rescheduled_sync', 'revision' => 1]]],
                ['step' => 4, 'selected' => [['id' => 'rescheduled_sync', 'revision' => 2]]],
            ],
            'expect' => [
                'true_positives' => 1, 'false_positives' => 1, 'false_negatives' => 0,
                'update_violations' => 1, 'on_time' => 1, 'exact_set_matches' => 1,
                'precision' => 0.5, 'recall' => 1.0, 'set_f1' => 0.6667,
            ],
        ],
        'dependency_blocked_selection_is_violation_until_prerequisite_completes' => [
            'intents' => [
                ['id' => 'collect_input', 'due_step' => 1],
                ['id' => 'publish_result', 'due_step' => 2, 'depends_on' => ['collect_input']],
            ],
            'steps' => [
                ['step' => 1, 'selected' => ['collect_input', 'publish_result']],
                ['step' => 2, 'selected' => ['publish_result']],
            ],
            'expect' => [
                'true_positives' => 2, 'false_positives' => 1, 'false_negatives' => 0,
                'dependency_violations' => 1, 'on_time' => 2, 'exact_set_matches' => 1,
                'precision' => 0.6667, 'recall' => 1.0, 'set_f1' => 0.8,
            ],
        ],
        'partial_due_set_with_lure_preserves_precision_and_recall_penalties' => [
            'intents' => [
                ['id' => 'first_due', 'due_step' => 2],
                ['id' => 'second_due', 'due_step' => 2],
                ['id' => 'future_lure', 'due_step' => 4],
            ],
            'steps' => [['step' => 2, 'selected' => ['first_due', 'future_lure']]],
            'expect' => [
                'true_positives' => 1, 'false_positives' => 1, 'false_negatives' => 1,
                'misses' => 1, 'false_alarms' => 1, 'exact_set_matches' => 0,
                'precision' => 0.5, 'recall' => 0.5, 'set_f1' => 0.5,
            ],
        ],
        'correct_intent_with_wrong_content_is_not_a_hit' => [
            'intents' => [[
                'id' => 'content_sensitive_notice',
                'due_step' => 1,
                'content_key' => 'current_notice',
            ]],
            'steps' => [[
                'step' => 1,
                'selected' => [[
                    'id' => 'content_sensitive_notice',
                    'content_key' => 'stale_notice',
                ]],
            ]],
            'expect' => [
                'true_positives' => 0, 'false_positives' => 1, 'false_negatives' => 1,
                'wrong_content' => 1, 'misses' => 1, 'on_time' => 0,
                'exact_set_matches' => 0, 'set_f1' => 0.0, 'exact_set_rate' => 0.0,
            ],
        ],
    ],
];
