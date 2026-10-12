<?php

return [
    // This exact target still requires the existing reviewed, enabled table
    // profile and explicit LAN workflow scope. Empty disables public defaults.
    'target_host' => env('PLOS_CYBER_OBSERVATION_TARGET_HOST', ''),
];
