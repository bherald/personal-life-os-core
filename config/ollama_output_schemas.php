<?php

$numericConfidence = [
    'type' => 'number',
    'minimum' => 0,
    'maximum' => 1,
];

$classificationConfidence = [
    'anyOf' => [
        $numericConfidence,
        ['type' => 'string'],
    ],
];

$classification = static fn (array $allowed): array => [
    'type' => 'object',
    'additionalProperties' => false,
    'properties' => [
        'decision' => ['type' => 'string', 'enum' => $allowed],
        'confidence' => $classificationConfidence,
        'rationale' => ['type' => 'string'],
    ],
    'required' => ['decision', 'confidence', 'rationale'],
];

return [
    'schema_version' => 'plos_ollama_output_schemas.v1',

    // Only identifiers in this registry may become an Ollama `format` payload.
    'schemas' => [
        'evidence_summary_json' => [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'summary' => ['type' => 'string'],
                'evidence_items' => ['type' => 'array', 'items' => ['type' => 'string']],
                'unresolved_questions' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['summary', 'evidence_items', 'unresolved_questions'],
        ],
        'genealogy_facts_json' => [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'confidence' => $numericConfidence,
                'document_type' => ['type' => 'string'],
                'document_year' => [
                    'type' => ['integer', 'null'],
                    'description' => 'Overall document or record year only when explicit in the input; otherwise null. Do not infer it from event dates or the current year.',
                ],
                'persons' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'given_name' => ['type' => 'string'],
                            'surname' => ['type' => 'string'],
                            'birth_year' => ['type' => ['integer', 'null']],
                            'role' => ['type' => 'string'],
                            'confidence' => $numericConfidence,
                            'facts' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'additionalProperties' => false,
                                    'properties' => [
                                        'field' => [
                                            'type' => 'string',
                                            'enum' => [
                                                'birth_date',
                                                'birth_year',
                                                'birth_place',
                                                'death_date',
                                                'death_year',
                                                'death_place',
                                                'burial_place',
                                                'marriage_date',
                                                'marriage_place',
                                                'occupation',
                                                'residence',
                                                'immigration_date',
                                                'immigration_place',
                                                'military_branch',
                                                'military_rank',
                                                'enlistment_date',
                                                'discharge_date',
                                                'nationality',
                                                'religion',
                                                'other',
                                            ],
                                        ],
                                        'value' => ['type' => 'string'],
                                        'confidence' => $numericConfidence,
                                    ],
                                    'required' => ['field', 'value', 'confidence'],
                                ],
                            ],
                            'relationships' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'additionalProperties' => false,
                                    'properties' => [
                                        'type' => [
                                            'type' => 'string',
                                            'enum' => ['parent', 'mother', 'father', 'spouse', 'child', 'sibling', 'other'],
                                        ],
                                        'name' => ['type' => 'string'],
                                        'birth_year' => [
                                            'type' => ['integer', 'null'],
                                            'description' => 'Birth year only when explicit for this named related person; otherwise null.',
                                        ],
                                    ],
                                    'required' => ['type', 'name'],
                                ],
                            ],
                        ],
                        'required' => ['name', 'role', 'confidence', 'facts', 'relationships'],
                    ],
                ],
                'notes_remainder' => ['type' => 'string'],
            ],
            'required' => ['confidence', 'document_type', 'document_year', 'persons', 'notes_remainder'],
        ],
        'source_triage_json' => [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'label' => [
                    'type' => 'string',
                    'enum' => ['same_person', 'collateral', 'wrong_person', 'noisy', 'uncertain'],
                ],
                'confidence' => $classificationConfidence,
                'rationale' => ['type' => 'string'],
            ],
            'required' => ['label', 'confidence', 'rationale'],
        ],
        'wrong_subject_json' => $classification([
            'reject',
            'uncertain',
            'accept',
            'attach',
            'needs_review',
        ]),
        'prompt_injection_resilience_json' => $classification([
            'ignore_payload_instruction',
            'needs_review',
        ]),
        'identity_reasoning_json' => $classification([
            'same_person',
            'different_person',
            'uncertain',
        ]),
        'relationship_suggestion_json' => [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'decision' => ['type' => 'string', 'enum' => ['suggest', 'no_match', 'uncertain']],
                'confidence' => $classificationConfidence,
                'rationale' => ['type' => 'string'],
                'relationships' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'type' => ['type' => 'string', 'enum' => ['parent', 'sibling', 'spouse', 'child']],
                            'name' => ['type' => 'string'],
                            'confidence' => $classificationConfidence,
                            'rationale' => ['type' => 'string'],
                        ],
                        'required' => ['type', 'name', 'confidence', 'rationale'],
                    ],
                ],
            ],
            'required' => ['decision', 'confidence', 'rationale', 'relationships'],
        ],
        'packet_summary_json' => [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'packet_summary' => ['type' => 'string'],
                'page_anchors' => ['type' => 'array', 'items' => ['type' => 'string']],
                'unresolved_questions' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['packet_summary', 'page_anchors', 'unresolved_questions'],
        ],
    ],
];
