<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Integración con admin-api central (sistema de releases/versiones).
    // - api_key: clave que admin-api envía hacia este cliente (debe coincidir con clients.api_key en admin-api).
    // - inbound_key: clave que este cliente envía hacia admin-api al reportar lecturas (debe coincidir con clients.inbound_api_key en admin-api).
    // - client_uuid: uuid propio de este cliente dentro del admin-api.
    // - require_api_key: si es false, el middleware admin.api.key no valida X-Admin-Api-Key (solo uso temporal;
    //   en producción debe ser true). Variable .env: ADMIN_SYNC_REQUIRE_API_KEY.
    'admin_api' => [
        'url'               => env('ADMIN_API_URL'),
        'api_key'           => env('ADMIN_API_INBOUND_KEY'),
        'inbound_key'       => env('ADMIN_API_OUTBOUND_KEY'),
        'client_uuid'       => env('ADMIN_API_CLIENT_UUID'),
        'require_api_key'   => env('ADMIN_SYNC_REQUIRE_API_KEY', false),
    ],

    /**
     * Cliente HTTP hacia api.mercadolibre.com (Guzzle vía Illuminate\Http\Client).
     * En Windows/WAMP sin CA bundle suele aparecer cURL error 60; ver .env.example.
     */
    'mercadolibre' => [
        'guzzle_verify' => filter_var(
            env('MERCADO_LIBRE_GUZZLE_VERIFY_SSL', env('APP_ENV') === 'production'),
            FILTER_VALIDATE_BOOLEAN
        ),
        'guzzle_ca_bundle' => env('MERCADO_LIBRE_GUZZLE_CA_BUNDLE', ''),
    ],

    /*
     * Kapso (proxy WhatsApp / Meta Cloud API).
     * verify_ssl=false en WAMP/Windows si no hay CA bundle disponible.
     */
    'kapso' => [
        'verify_ssl' => env('KAPSO_VERIFY_SSL', true),
        'ca_bundle'  => env('KAPSO_CA_BUNDLE', null),
    ],

    /*
     * API Anthropic (Claude) — importación Excel asistida por IA.
     * Misma configuración TLS que admin-api (WAMP/Windows suele requerir ANTHROPIC_CAINFO).
     */
    'anthropic' => [
        'api_key'    => env('ANTHROPIC_API_KEY'),
        'model'      => env('ANTHROPIC_MODEL', 'claude-sonnet-4-20250514'),
        'ca_bundle'  => env('ANTHROPIC_CAINFO'),
        'verify_ssl' => filter_var(env('ANTHROPIC_VERIFY_SSL', true), FILTER_VALIDATE_BOOLEAN),
    ],

    /*
     * API OpenAI — embeddings vectoriales del catálogo de artículos (text-embedding-3-small).
     * El token se configura en .env como OPENAI_API_KEY.
     * Reutiliza la configuración TLS de anthropic (verify_ssl / ca_bundle) para
     * mantener coherencia entre entornos Windows/WAMP y producción Linux.
     */
    'openai' => [
        'api_key' => env('OPENAI_API_KEY', ''),
    ],

    /**
     * Google Custom Search API (asignación batch de imágenes en ProcessArticleBatchImagesJob).
     * En Windows/WAMP sin CA bundle suele aparecer cURL error 60; ver .env.example.
     */
    'google_custom_search' => [
        'guzzle_verify' => filter_var(
            env('GOOGLE_CUSTOM_SEARCH_GUZZLE_VERIFY_SSL', env('APP_ENV') === 'production'),
            FILTER_VALIDATE_BOOLEAN
        ),
        'guzzle_ca_bundle' => env('GOOGLE_CUSTOM_SEARCH_GUZZLE_CA_BUNDLE', ''),
    ],

];
