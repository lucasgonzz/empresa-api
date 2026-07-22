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

    /**
     * UPCitemdb (plan FREE, sin API key): lookup de producto por GTIN para el flujo de
     * descripciones inteligentes. Limite real: 100 requests/dia POR IP, compartidos por
     * TODOS los clientes de la plataforma. daily_cap deja margen antes de ese techo.
     */
    'upcitemdb' => [
        'enabled'   => filter_var(env('UPCITEMDB_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'daily_cap' => (int) env('UPCITEMDB_DAILY_CAP', 90),
        'timeout'   => (int) env('UPCITEMDB_TIMEOUT', 8),
    ],

    /**
     * OAuth de Mercado Pago (grupo 170, prompt 598): credenciales de la APLICACIÓN de
     * ComercioCity dada de alta en Mercado Pago Developers (NO son credenciales del comercio;
     * las de cada comercio se guardan en su online_configuration tras el OAuth). Con este
     * client_id/client_secret la app pide autorización a la cuenta de Mercado Pago de cada
     * comercio y procesa pagos EN SU NOMBRE (no se usa application_fee/marketplace_fee).
     */
    'mercadopago' => [
        // client_id de la app de ComercioCity en Mercado Pago Developers.
        'app_id'             => env('MP_APP_ID'),
        // client_secret de la app de ComercioCity. Nunca debe loguearse ni exponerse en un response.
        'app_secret'         => env('MP_APP_SECRET'),
        // URL de este backend a la que Mercado Pago redirige tras autorizar (debe coincidir
        // exactamente con la registrada en la app de Mercado Pago Developers).
        'oauth_redirect_uri' => env('MP_OAUTH_REDIRECT_URI'),
        // Pantalla de Integraciones del SPA de empresa a la que se vuelve luego del callback,
        // con ?mp=ok o ?mp=error agregado. Configurable porque empresa-api y empresa-spa pueden
        // vivir en dominios distintos según el cliente.
        'spa_redirect_url'   => env('MP_OAUTH_SPA_REDIRECT_URL'),
        // Mismo problema de cURL error 60 en WAMP/Windows que mercadolibre/google_custom_search:
        // sin CA bundle, verify=false salvo en producción.
        'guzzle_verify' => filter_var(
            env('MP_GUZZLE_VERIFY_SSL', env('APP_ENV') === 'production'),
            FILTER_VALIDATE_BOOLEAN
        ),
        'guzzle_ca_bundle' => env('MP_GUZZLE_CA_BUNDLE', ''),
    ],

];
