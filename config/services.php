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

    /**
     * Certificados y clave privada de AFIP (grupo 220, prompt 01): antes vivían bajo public/afip/
     * (document root de Laravel), lo que los hacía potencialmente descargables por HTTP. Ahora
     * cuelgan de storage/app/afip, fuera del docroot, y estas rutas son configurables por .env para
     * que cada servidor pueda apuntar al nombre real del archivo que copió a mano al provisionar.
     * Ver docs/afip.md para el procedimiento de instalación.
     */
    'afip' => [
        // Certificado de producción (.crt) — se copia a mano en cada servidor, nunca en el repo.
        'cert_path'         => env('AFIP_CERT_PATH', storage_path('app/afip/production/cert.crt')),
        // Clave privada de producción (.key) — nunca se commitea.
        'key_path'          => env('AFIP_KEY_PATH', storage_path('app/afip/production/privada.key')),
        // Certificado de testing/homologación.
        'cert_path_testing' => env('AFIP_CERT_PATH_TESTING', storage_path('app/afip/testing/afip_cert.pem')),
        // Clave privada de testing/homologación.
        'key_path_testing'  => env('AFIP_KEY_PATH_TESTING', storage_path('app/afip/testing/afip_private.key')),
        // Directorio de trabajo de WSAA (TRA/TA/CMS por ws_name): se crea solo al vuelo, no requiere
        // provisión manual. TA.xml es una credencial viva (token+sign), por eso no puede vivir en public/.
        'wsaa_path'         => env('AFIP_WSAA_PATH', storage_path('app/afip/wsaa')),
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
        /*
         * Base de la API. Vive acá y no en cada service (misión proveedores-ia-deepseek, 22/9/2026):
         * desde que hay dos proveedores, la URL de `/v1/messages` la arma ProveedorIaHelper a partir
         * de la base del proveedor elegido, y esta es la de Claude.
         */
        'base_url'   => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'model'      => env('ANTHROPIC_MODEL', 'claude-sonnet-4-20250514'),
        /*
         * Los tres modelos que elige la preferencia "cómo piensa" del agente (misión
         * foto-sucursal-y-asistente-configurable del 17/9/2026, corrida a tres niveles el
         * 21/9/2026). `model_agil` es el económico y rápido (Haiku) — el default del sistema, un
         * negocio que nunca tocó la config cae acá. `model_equilibrado` hereda el rol que tenía
         * `model_agil` hasta el 21/9 (cae por defecto al `ANTHROPIC_MODEL` de siempre). `model_profundo`
         * es el modelo caro, para el que elige "piensa a fondo". El service elige entre los tres por
         * `users.agente_pensamiento`; los ids NO se hardcodean en el service, salen de acá y se pueden
         * mover por .env.
         */
        'model_agil'        => env('ANTHROPIC_MODEL_AGIL', 'claude-haiku-4-5-20251001'),
        'model_equilibrado' => env('ANTHROPIC_MODEL_EQUILIBRADO', env('ANTHROPIC_MODEL', 'claude-sonnet-5')),
        'model_profundo'    => env('ANTHROPIC_MODEL_PROFUNDO', 'claude-opus-5'),
        'ca_bundle'  => env('ANTHROPIC_CAINFO'),
        'verify_ssl' => filter_var(env('ANTHROPIC_VERIFY_SSL', true), FILTER_VALIDATE_BOOLEAN),
    ],

    /*
     * API de DeepSeek — el segundo proveedor del asistente (misión proveedores-ia-deepseek,
     * 22/9/2026). El DUEÑO del negocio elige entre Claude y DeepSeek (`users.agente_proveedor`) y los
     * tres caminos del asistente —el chat del dueño, el bot de WhatsApp y el título de conversación—
     * siguen esa elección. El resto de los puntos de IA del repo (escaneo de facturas, importación
     * Excel, validación de imágenes, descripciones, paleta, resúmenes) siguen en Anthropic.
     *
     * Se le pega por su endpoint COMPATIBLE CON ANTHROPIC (`base_url` termina en `/anthropic`):
     * acepta el mismo payload de `/v1/messages` —system, tools con input_schema, bloques tool_use /
     * tool_result, imágenes base64— y contesta con la misma forma (content[], stop_reason, usage).
     * Por eso no hay un traductor de formatos: lo único que cambia por proveedor es la URL, la
     * clave, el id del modelo y el parámetro `thinking`. Todo eso lo resuelve ProveedorIaHelper.
     *
     * Los ids de los modelos salen de api-docs.deepseek.com (22/9/2026): `deepseek-flash` es el
     * rápido y barato, con visión; `deepseek-v4-pro` razona a fondo y no ve imágenes. DeepSeek no
     * tiene "equilibrado": el dueño elige entre Ágil (Flash) y Profundo (Pro). `model` es el
     * "general", el que usan el bot de WhatsApp y el título (el análogo de ANTHROPIC_MODEL).
     *
     * `model_vision` es el modelo CON visión: cuando el dueño manda una foto y está en Profundo, esa
     * llamada va con este modelo (con el thinking de Profundo igual prendido: Flash también razona).
     * Sin esto la foto viajaría a Pro, que no la ve — y DeepSeek no devuelve un error: mapea el
     * pedido y contesta sin haber mirado la imagen. Por eso el modelo se elige explícito.
     *
     * El thinking de DeepSeek viene PRENDIDO por defecto, así que Ágil lo manda `disabled` (es el
     * análogo de Haiku: contestar rápido) y Profundo lo manda `enabled`. Para Anthropic no se manda
     * ninguna clave `thinking`, exactamente como hasta hoy.
     *
     * `max_tokens_profundo` es el techo de salida cuando el thinking va `enabled` (Profundo): no está
     * documentado si el endpoint compatible con Anthropic cuenta los tokens de razonamiento contra
     * `max_tokens` (en el formato OpenAI no los cuenta; en el de Anthropic sí), y con los 1500 del
     * asistente un razonamiento largo podría cortar antes de escribir una sola palabra de respuesta.
     * ProveedorIaHelper::agregar_thinking() sube el techo del payload a este valor cuando el que trae
     * es menor (nunca lo baja). Es inocuo para el costo: la salida se paga por token generado, no
     * por el techo declarado.
     *
     * Sin `DEEPSEEK_API_KEY` en el .env de la instalación, DeepSeek no se puede elegir (el modal lo
     * muestra como no disponible y el PUT contesta 422). El bloque TLS es el mismo de Anthropic y
     * cae a él por defecto: es la misma máquina con el mismo cacert.pem.
     */
    'deepseek' => [
        'api_key'             => env('DEEPSEEK_API_KEY'),
        'base_url'            => env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com/anthropic'),
        'model'               => env('DEEPSEEK_MODEL', 'deepseek-flash'),
        'model_agil'          => env('DEEPSEEK_MODEL_AGIL', 'deepseek-flash'),
        'model_profundo'      => env('DEEPSEEK_MODEL_PROFUNDO', 'deepseek-v4-pro'),
        'model_vision'        => env('DEEPSEEK_MODEL_VISION', 'deepseek-flash'),
        'thinking_agil'       => env('DEEPSEEK_THINKING_AGIL', 'disabled'),
        'thinking_profundo'   => env('DEEPSEEK_THINKING_PROFUNDO', 'enabled'),
        'max_tokens_profundo' => (int) env('DEEPSEEK_MAX_TOKENS_PROFUNDO', 8000),
        /*
         * `budget_tokens` que acompaña al thinking `enabled`. DeepSeek lo ignora (lo dice su doc),
         * pero el esquema de Anthropic que ese endpoint imita lo exige con `enabled`: se manda para
         * que un Profundo no rebote con 400 si la validación copia el esquema. Tiene que quedar por
         * debajo de `max_tokens_profundo` (el helper lo recorta si no).
         */
        'thinking_budget_tokens' => (int) env('DEEPSEEK_THINKING_BUDGET_TOKENS', 4000),
        'ca_bundle'           => env('DEEPSEEK_CAINFO', env('ANTHROPIC_CAINFO')),
        'verify_ssl'          => filter_var(env('DEEPSEEK_VERIFY_SSL', env('ANTHROPIC_VERIFY_SSL', true)), FILTER_VALIDATE_BOOLEAN),
    ],

    /**
     * Validación de imagen por visión IA (grupo 201, prompt 02): antes de asignar una imagen
     * encontrada por Google a un artículo, ArticleImageValidationService le pide a Claude que
     * confirme si la imagen realmente muestra el producto (y no una lista de precios, un
     * catálogo en PDF, un logo, etc). Reutiliza la ANTHROPIC_API_KEY / ca_bundle / verify_ssl
     * del bloque 'anthropic' de arriba; estas claves son propias de este servicio puntual.
     */
    'article_image_validation' => [
        // Permite apagar la validación por completo (fail-open): en false, el servicio
        // devuelve 'evaluated' => false y 'accepted' => true sin llamar a la API.
        'enabled'          => filter_var(env('ARTICLE_IMAGE_VALIDATION_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        // Modelo de Claude usado para la validación (uno económico, alcanza para esta tarea).
        'model'            => env('ARTICLE_IMAGE_VALIDATION_MODEL', 'claude-haiku-4-5-20251001'),
        // Timeout en segundos de la request HTTP a Anthropic.
        'timeout'          => (int) env('ARTICLE_IMAGE_VALIDATION_TIMEOUT', 25),
        // Lado mayor máximo (px) al que se redimensiona la imagen antes de mandarla a la IA,
        // para bajar el costo en tokens sin perder precisión de la validación.
        'max_side'         => (int) env('ARTICLE_IMAGE_VALIDATION_MAX_SIDE', 512),
        // Techo de llamadas reales a Anthropic por corrida de batch (protección de costo).
        'max_calls_batch'  => (int) env('ARTICLE_IMAGE_VALIDATION_MAX_CALLS_BATCH', 300),
    ],

    /**
     * Escaneo de facturas de compra con IA (misión escaneo-factura-compra): el usuario saca
     * una foto de la factura del proveedor y el sistema extrae la tabla de artículos y los
     * datos del comprobante. Reutiliza ANTHROPIC_API_KEY / ca_bundle / verify_ssl del bloque
     * 'anthropic' de arriba; estas claves son propias de este flujo.
     */
    'escaneo_factura_compra' => [
        /*
         * Modelo de Claude. Acá NO se usa uno económico: leer una tabla impresa chica y sacar
         * códigos exactos es la parte cara de la funcionalidad.
         *
         * ⚠️ El id va SIN sufijo de fecha, y no es un descuido: los ids vigentes de Anthropic
         * son completos así como están. Los otros dos bloques de este archivo
         * (article_image_validation, anthropic) llevan fecha porque se escribieron cuando esa
         * era la convención, no porque haga falta. No le agregues una.
         *
         * Se puede cambiar por .env sin tocar código (ESCANEO_FACTURA_MODEL), que es la salida
         * si algún cliente necesita otro modelo o si aparece uno mejor para leer facturas.
         */
        'model'         => env('ESCANEO_FACTURA_MODEL', 'claude-sonnet-5'),
        // Timeout en segundos de la request a Anthropic. Varias páginas tardan.
        'timeout'       => (int) env('ESCANEO_FACTURA_TIMEOUT', 180),
        // Lado mayor (px) al que se redimensiona cada foto. 1568 es el máximo que Anthropic
        // procesa sin downsamplear; con 512 (el de la validación de imágenes de producto) los
        // códigos de artículo impresos chicos quedan ilegibles.
        'max_side'      => (int) env('ESCANEO_FACTURA_MAX_SIDE', 1568),
        // Páginas por escaneo. Más de 6 imágenes en una sola llamada empieza a costar caro y
        // a acercarse al límite de contexto.
        'max_imagenes'  => (int) env('ESCANEO_FACTURA_MAX_IMAGENES', 6),
        // Tamaño máximo por archivo subido, en MB, ANTES de redimensionar.
        'max_mb'        => (int) env('ESCANEO_FACTURA_MAX_MB', 12),
        // Tokens máximos de la respuesta. Con 8000 una factura de muchos renglones y nombres
        // largos (medido en demo el 5/9/2026: cañas de pescar con descripciones detalladas) corta
        // el JSON a mitad de camino y el escaneo falla con "JSON inválido".
        'max_tokens'    => (int) env('ESCANEO_FACTURA_MAX_TOKENS', 16000),
    ],

    /*
     * API OpenAI — embeddings vectoriales del catálogo de artículos (text-embedding-3-small).
     * El token se configura en .env como OPENAI_API_KEY.
     * Reutiliza la configuración TLS de anthropic (verify_ssl / ca_bundle) para
     * mantener coherencia entre entornos Windows/WAMP y producción Linux.
     */
    'openai' => [
        'api_key' => env('OPENAI_API_KEY', ''),

        /*
         * Pausa global de la generación de embeddings (misión busqueda-lenta-y-pausa-embeddings,
         * 14/9/2026), independiente de la extensión whatsapp_ia por cliente — ver
         * EMBEDDINGS_GENERACION_PAUSADA en .env.example. Va acá y no se lee con env() directo en
         * el código de aplicación: con config:cache activo (lo normal en producción) env() fuera
         * de config/ devuelve el default, y esta misma clase de bug ya rompió DURACION_REPORTES
         * en producción (ver CompanyPerformanceController::check_tiempo_ultima_creada()).
         */
        'embeddings_generacion_pausada' => filter_var(env('EMBEDDINGS_GENERACION_PAUSADA', false), FILTER_VALIDATE_BOOLEAN),
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

        /**
         * Fallback del header `Referer` que se manda en las llamadas server-to-server a la API de
         * Google (la key tiene restriccion por referrer HTTP: sin el header, Google responde
         * "Requests from referer <empty> are blocked").
         *
         * En el caso normal NO hace falta cargarla: el valor se deriva del host de `APP_URL`, que
         * es el mismo que el navegador manda cuando la busqueda se hace desde el front. Solo se
         * carga en instalaciones cuyo `APP_URL` no es `comerciocity.com` ni un subdominio suyo
         * (cliente con dominio propio), con una URL que matchee el patron cargado en Google Cloud
         * Console. Ver GoogleSearchHelpers::google_api_referer().
         */
        'referer' => env('GOOGLE_SEARCH_REFERER', ''),
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
        //
        // Desde la misión de ABM -> Integraciones apunta a `/abm/integraciones/tienda-online`,
        // no más al viejo `/integraciones` del bloque de Configuración online. El segundo
        // segmento es obligatorio: el SPA rutea `/abm/:view?/:sub_view?` y sin él abre la solapa
        // "Sistema", donde el aviso de retorno del OAuth nunca corre. Ojo al desplegar: este
        // valor vive en el `.env` de cada cliente, así que actualizarlo es parte del deploy — si
        // queda el viejo, el comercio conecta bien y no se entera.
        'spa_redirect_url'   => env('MP_OAUTH_SPA_REDIRECT_URL'),
        // Mismo problema de cURL error 60 en WAMP/Windows que mercadolibre/google_custom_search:
        // sin CA bundle, verify=false salvo en producción.
        'guzzle_verify' => filter_var(
            env('MP_GUZZLE_VERIFY_SSL', env('APP_ENV') === 'production'),
            FILTER_VALIDATE_BOOLEAN
        ),
        'guzzle_ca_bundle' => env('MP_GUZZLE_CA_BUNDLE', ''),

        /*
         * Credenciales de Mercado Pago para conectar SOLAS una demo que no tiene ninguna cuenta
         * conectada todavía (misión mp-precio-servidor-y-credenciales-env, 16/9/2026). Van acá y
         * no se leen con env() directo en el código de aplicación: con config:cache activo (lo
         * normal en producción) env() fuera de config/ devuelve el default, y esta misma clase de
         * bug ya rompió DURACION_REPORTES en producción — ver el comentario de
         * 'embeddings_generacion_pausada' más arriba en este archivo, o
         * CompanyPerformanceController::check_tiempo_ultima_creada().
         */
        'demo_access_token' => env('MERCADOPAGO_DEMO_ACCESS_TOKEN'),
        'demo_public_key'   => env('MERCADOPAGO_DEMO_PUBLIC_KEY'),
    ],

    /**
     * OAuth de Zippin (grupo 171, prompt 599): credenciales de la APLICACIÓN de ComercioCity
     * dada de alta en el programa de integradores de Zippin (NO son credenciales del comercio;
     * las de cada comercio se guardan en su online_configuration tras el OAuth). Con este
     * client_id/client_secret la app pide autorización a la cuenta Zipnova de cada comercio y
     * gestiona sus envíos EN SU NOMBRE.
     *
     * Las URLs de autorización/token/cuenta se dejan configurables (con valor por defecto) en
     * vez de hardcodeadas, porque el prompt pide verificarlas contra la doc oficial vigente de
     * Zippin (docs.zipnova.com) al implementar. Ajustar vía .env si difieren de la doc real.
     */
    'zippin' => [
        // client_id de la app de ComercioCity en Zippin.
        'client_id'          => env('ZIPPIN_CLIENT_ID'),
        // client_secret de la app de ComercioCity. Nunca debe loguearse ni exponerse en un response.
        'client_secret'      => env('ZIPPIN_CLIENT_SECRET'),
        // URL de este backend a la que Zippin redirige tras autorizar (debe coincidir
        // exactamente con la registrada en la app de Zippin).
        'oauth_redirect_uri' => env('ZIPPIN_OAUTH_REDIRECT_URI'),
        // Pantalla de autorización de Zippin que el comercio abre para conectar su cuenta.
        // Verificado contra docs.zipnova.com/envios/principios/autorizacion-con-oauth (22/7/2026):
        // el dominio real es zipnova.com.ar, no zippin.com.ar.
        'authorization_url'  => env('ZIPPIN_AUTHORIZATION_URL', 'https://api.zipnova.com.ar/oauth/authorize'),
        // Endpoint de canje/refresh de tokens de Zippin (sin el prefijo v2, ver prompt 599).
        'token_url'          => env('ZIPPIN_TOKEN_URL', 'https://api.zipnova.com.ar/oauth/token'),
        // Endpoint para obtener el account_id de la cuenta Zipnova recién conectada (no confirmado
        // en la doc oficial; fetch_account_id() del service prueba tanto account_id como id).
        'account_url'        => env('ZIPPIN_ACCOUNT_URL', 'https://api.zipnova.com.ar/v2/account'),
        // Permisos/scopes de Zippin separados por espacio, formato con punto según doc oficial.
        'oauth_scopes'       => env('ZIPPIN_OAUTH_SCOPES', 'shipments.create shipments.quote accounts.show'),
        // Pantalla de Integraciones del SPA de empresa a la que se vuelve luego del callback,
        // con ?zippin=ok o ?zippin=error agregado.
        'spa_redirect_url'   => env('ZIPPIN_OAUTH_SPA_REDIRECT_URL'),
        // Mismo problema de cURL error 60 en WAMP/Windows que mercadopago/mercadolibre: sin CA
        // bundle, verify=false salvo en producción.
        'guzzle_verify' => filter_var(
            env('ZIPPIN_GUZZLE_VERIFY_SSL', env('APP_ENV') === 'production'),
            FILTER_VALIDATE_BOOLEAN
        ),
        'guzzle_ca_bundle' => env('ZIPPIN_GUZZLE_CA_BUNDLE', ''),
    ],

    /**
     * Zipnova (ex Zippin), misión zipnova-envios (14/9/2026): el agregador de correos con el que
     * la tienda cotiza y despacha envíos.
     *
     * Acá NO hay credenciales: a diferencia del bloque `zippin` de arriba (OAuth con una app de
     * ComercioCity que nunca llegó a existir), cada comercio se conecta con SU API Token + API
     * Secret, que viven cifrados en `platform_connectors.access_token`. Lo único que se configura
     * a nivel instancia es cómo llegar a la API: el host (por si Zipnova lo cambia o para apuntar
     * a un stub en desarrollo), el timeout y la verificación SSL. `ZipnovaClient::base_url()` y
     * `ZipnovaClient::http_client_options()` son los únicos lectores.
     */
    'zipnova' => [
        // Host de la API v2. Los dominios `zippin` vencen el 1/4/2026; el vigente es zipnova.com.ar.
        'base_url'         => env('ZIPNOVA_BASE_URL', 'https://api.zipnova.com.ar/v2'),
        // Segundos máximos de espera por respuesta. Zipnova cotiza en ~1-3 s; 20 cubre un pico.
        'timeout'          => (int) env('ZIPNOVA_TIMEOUT', 20),
        // Reintento único ante 429 respetando Retry-After (máx. 5 s). En el ERP queda prendido:
        // opera de a un envío. En la tienda está apagado (endpoint público, IP compartida).
        'reintento_429'    => filter_var(env('ZIPNOVA_REINTENTO_429', true), FILTER_VALIDATE_BOOLEAN),
        // Verificación del certificado. Por defecto true (en el header viaja el token del
        // comercio: desactivarla no es opción en producción). En wamp el PHP no encuentra el
        // bundle solo: se le pasa por `guzzle_ca_bundle`, o cae al de `zippin` que ya está cargado.
        'guzzle_verify'    => filter_var(env('ZIPNOVA_GUZZLE_VERIFY_SSL', true), FILTER_VALIDATE_BOOLEAN),
        'guzzle_ca_bundle' => env('ZIPNOVA_GUZZLE_CA_BUNDLE', ''),

        /*
         * API Token + API Secret para conectar SOLA una demo (o una base local) que no tiene
         * ninguna cuenta de Zipnova conectada todavía (misión mp-zipnova-seed-local, 17/9/2026,
         * mismo patrón que 'demo_access_token'/'demo_public_key' del bloque 'mercadopago' de
         * arriba). Van acá y no se leen con env() directo en el código de aplicación: con
         * config:cache activo (lo normal en producción) env() fuera de config/ devuelve el
         * default — ver el comentario de 'demo_access_token' en el bloque 'mercadopago'.
         *
         * demo_account_id es opcional: sin él, el conector queda sin platform_user_id (mismo
         * criterio que un conector recién creado por OAuth antes de resolver la cuenta).
         */
        'demo_api_token'   => env('ZIPNOVA_DEMO_API_TOKEN'),
        'demo_api_secret'  => env('ZIPNOVA_DEMO_API_SECRET'),
        'demo_account_id'  => env('ZIPNOVA_DEMO_ACCOUNT_ID'),
    ],

    /**
     * Credenciales de AFIP SDK (app.afipsdk.com), usadas por AfipSdk::__construct() para pedir
     * el token de autorizacion (TA) via WebService. Antes estaban escritas literalmente en
     * AfipSdk.php (grupo 220, prompt 02); el repositorio es publico, asi que ahora se leen del
     * .env del servidor. Prohibido volver a escribir el valor real como default aca.
     */
    'afip_sdk' => [
        // Access token de la cuenta de app.afipsdk.com. Obtenido de https://app.afipsdk.com
        'access_token' => env('AFIP_SDK_ACCESS_TOKEN', ''),
        // CUIT asociado a esa cuenta de AFIP SDK.
        'cuit'         => env('AFIP_SDK_CUIT', ''),
    ],

    /**
     * Tokens de Mercado Libre usados solo por los seeders de desarrollo
     * (MeliPlatformConnectorSeeder / MercadoLibreTokenSeeder) para crear un PlatformConnector de
     * ejemplo ya conectado. Son tokens de una cuenta real de Mercado Libre: nunca deben quedar
     * hardcodeados en el repositorio publico (grupo 220, prompt 02).
     */
    'mercado_libre' => [
        // Access token de la cuenta de Mercado Libre usada para sembrar el conector de ejemplo.
        'access_token'  => env('MERCADO_LIBRE_SEED_ACCESS_TOKEN', ''),
        // Refresh token de esa misma cuenta.
        'refresh_token' => env('MERCADO_LIBRE_SEED_REFRESH_TOKEN', ''),
    ],

    /**
     * Access token de una cuenta de prueba de Mercado Pago, usado solo por PaymentMethodSeeder
     * para sembrar el medio de pago de ejemplo. Credencial de entorno, no configuracion de
     * negocio (grupo 220, prompt 02).
     */
    'mercado_pago' => [
        'token' => env('MERCADO_PAGO_SEED_ACCESS_TOKEN', ''),
    ],

    /**
     * API key de fallback global de Google Custom Search: se usa cuando un User no tiene su
     * propia google_custom_search_api_key configurada (GoogleController,
     * ArticleDescriptionAiController, UserSeeder, set_datos_for_demo). Antes estaba hardcodeada
     * en varios archivos; el repositorio es publico, asi que ahora se lee del .env del servidor
     * (grupo 220, prompt 02).
     */
    'google_search' => [
        'api_key' => env('GOOGLE_SEARCH_API_KEY', ''),
    ],

    /**
     * Token de GitHub usado por GitHubErrorReporterService para subir reportes automáticos de
     * error al repo claude-comerciocity/errores/ (grupo 253, prompt 01). Antes se leía con
     * env() directo dentro del servicio: con config:cache corrido en producción eso devuelve
     * null y el reporter se apaga entero, sin avisar. Ahora se lee siempre de config().
     */
    'github_error_reporter' => [
        'token' => env('GITHUB_ERROR_REPORTER_TOKEN'),
    ],

    /**
     * Cotizaciones del dólar (misión cotizacion-dolar). Endpoint público de dolarapi.com, sin
     * credenciales: devuelve las siete casas del día en un array de objetos.
     * CotizacionDolarService se queda con tres — oficial, blue y bolsa (que es el MEP; la casa
     * 'mep' NO existe en esa API).
     *
     * Va en config y no leído con env() adentro del servicio por el mismo motivo que
     * github_error_reporter de acá arriba: con `config:cache` corrido en producción, un env()
     * dentro del código devuelve null y el servicio se apaga sin avisar.
     */
    'dolar_api' => [
        'url' => env('DOLAR_API_URL', 'https://dolarapi.com/v1/dolares'),
    ],

    /**
     * El mostrador del módulo IA visto desde afuera (misión asistente-por-whatsapp, 16/9/2026).
     *
     * `spa_url` es la URL del SISTEMA de este cliente —lo que el dueño abre en el navegador,
     * https://<cliente>.comerciocity.com—, y se usa para armar el link con el que el informe de la
     * mañana se abre desde el teléfono (MostradorAccesoHelper).
     *
     * 🔴 NO tiene repliegue a `app.url` a propósito. `app.url` es la URL de la API
     * (api-<cliente>.comerciocity.com): un link armado con eso da 404 en el teléfono del dueño, y
     * el error aparece recién cuando 40 dueños tocan el link a las 8:30 de la mañana. Es la clase
     * "la URL que un sistema le entrega a otro, armada con APP_URL" de APRENDER_NO_PARCHEAR.md
     * (9/9/2026). Sin esta variable cargada, el informe se manda SIN link y queda registrado en el
     * log: fallar visible antes que mandar un link roto.
     *
     * Va en config y no leído con env() adentro del código por el mismo motivo que
     * github_error_reporter: con `config:cache` corrido en producción, un env() devuelve null.
     */
    'mostrador' => [
        'spa_url' => env('SPA_URL'),
    ],

];
