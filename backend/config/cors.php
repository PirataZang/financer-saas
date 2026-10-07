<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Em dev o front (:5174) chama o backend (:8000) direto, então /api/* precisa
    | de CORS. Auth é por Bearer token (sem cookies), por isso origem '*' é segura:
    | outro site não tem acesso ao token. Na VPS front e API ficam na mesma origem.
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
