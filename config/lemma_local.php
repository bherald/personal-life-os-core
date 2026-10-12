<?php

return [
    'tools' => [
        [
            'name' => 'lemma_status',
            'description' => 'Read the local Lemma version, API readiness, and rootless container states.',
            'inputSchema' => ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false],
        ],
        [
            'name' => 'lemma_start',
            'description' => 'Start only the pinned local Lemma Compose stack after exact operator approval.',
            'inputSchema' => ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false],
        ],
        [
            'name' => 'lemma_stop',
            'description' => 'Stop only the local Lemma Compose stack after exact operator approval; retain volumes.',
            'inputSchema' => ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false],
        ],
    ],
];
