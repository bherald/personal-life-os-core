<?php

return [
    'enabled' => (bool) env('LEMMA_MODEL_GATEWAY_ENABLED', false),
    'token' => env('LEMMA_MODEL_GATEWAY_TOKEN'),
    'instance_id' => env('LEMMA_MODEL_GATEWAY_INSTANCE_ID'),
    'role' => env('LEMMA_MODEL_GATEWAY_ROLE', 'standard'),
];
