<?php

return [
    'schema' => 'plos.stt_candidate_eval.v1',
    'required_capabilities' => [
        'clean_speech',
        'noisy_speech',
        'accented_speech',
        'names_dates',
        'long_form',
        'multilingual',
        'silence',
    ],
    'limits' => [
        'max_artifact_bytes' => 1024 * 1024,
        'max_cases' => 24,
        'max_text_chars' => 2000,
        'max_critical_phrases_per_case' => 25,
        'max_critical_phrase_chars' => 240,
        'max_capabilities_per_case' => 4,
        'max_audio_duration_seconds' => 21_600,
    ],
    'thresholds' => [
        'min_cases' => 7,
        'min_categories' => 6,
        'max_word_error_rate' => 0.20,
        'max_character_error_rate' => 0.15,
        'min_critical_phrase_recall' => 1.0,
        'min_language_accuracy' => 1.0,
        'min_silence_pass_rate' => 1.0,
        'max_real_time_factor' => 1.0,
        'min_word_error_rate_improvement' => 0.01,
        'min_real_time_factor_improvement' => 0.05,
        'max_case_word_error_rate' => 0.40,
        'max_case_character_error_rate' => 0.30,
        'max_case_quality_regression' => 0.0,
        'max_aggregate_real_time_factor_regression' => 0.10,
    ],
];
