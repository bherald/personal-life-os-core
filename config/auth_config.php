<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Master Password
    |--------------------------------------------------------------------------
    |
    | This password is used for simple authentication in the web UI.
    | It's a single master password for personal/family use.
    |
    */
    'master_password' => env('WEB_UI_MASTER_PASSWORD'),
    'admin_email' => env('PLOS_ADMIN_EMAIL', 'admin@plos.local'),
];
