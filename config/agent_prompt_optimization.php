<?php

return [
    'schema' => 'plos.prompt_candidate_eval_policy.v1',
    'min_training_cases' => 2,
    'min_holdout_cases' => 3,
    'min_holdout_mean_improvement' => 0.02,
    'max_case_regression' => 0.0,
    'min_improved_holdout_cases' => 1,
    'max_metrics' => 8,
    'operator_review_required' => true,
    'autonomous_promotion_allowed' => false,
];
