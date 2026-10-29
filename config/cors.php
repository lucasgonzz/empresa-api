<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => [
        'api/*',
        'login',
        'login-forzado',
        'logout',
        'version-session-token',
        'login-from-version-session-token',
        'sanctum/csrf-cookie',
        'set-image',
        'password-reset/*',
        'user',
        'home/clients',
        '/storage/*',
        'storage',
        'broadcasting/auth',
    ],
    

    'allowed_methods' => ['*'],

    /*
     * El origen del sistema de este cliente (SANCTUM_STATEFUL_CORS) MÁS el de la app «ComercioCity IA»
     * (misión app-ia-y-memoria, 29/10/2026): IA_APP_ORIGENES, separados por coma, con
     * https://ia.comerciocity.com por defecto. Sin duplicados ni vacíos (un null o un '' no habilitan a
     * nadie, pero ensucian la lista). La app entra con Bearer, sin cookie: `supports_credentials` no la
     * afecta.
     */
    'allowed_origins' => array_values(array_unique(array_filter(array_map('trim', array_merge(
        [(string) env('SANCTUM_STATEFUL_CORS', '')],
        explode(',', (string) env('IA_APP_ORIGENES', 'https://ia.comerciocity.com'))
    ))))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    // 'exposed_headers' => [],
    // Content-Disposition: la app de IA baja los archivos adjuntos con su nombre (misión app-ia-y-memoria).
    'exposed_headers' => ['XSRF-TOKEN', 'Content-Disposition'],

    'max_age' => 0,

    'supports_credentials' => true,

];
