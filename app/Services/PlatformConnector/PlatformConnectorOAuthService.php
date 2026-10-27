<?php

namespace App\Services\PlatformConnector;

use App\Models\OauthState;
use App\Models\Platform;
use App\Models\PlatformConnector;
use App\Services\MercadoLibre\MercadoLibreAppCredentials;
use App\Services\MercadoLibre\MercadoLibreService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Completa el flujo OAuth para un `PlatformConnector` tras el callback HTTP.
 *
 * Responsabilidad:
 * - Intercambiar `code` por tokens según la plataforma.
 * - Actualizar el conector (`status`, tokens, `platform_user_id`).
 */
class PlatformConnectorOAuthService
{
    /**
     * True si el `state` del callback en curso salió de `oauth_states` (el formato nuevo, aleatorio
     * y de un solo uso) y false si fue el id numérico del conector (el formato viejo). Lo setea
     * `resolver_conector_del_state()` y lo lee el canje de Mercado Libre para elegir la
     * `redirect_uri`: tiene que ser la MISMA con la que se armó la URL de autorización.
     *
     * @var bool
     */
    protected $state_de_oauth_states = false;

    /**
     * Procesa el callback de Mercado Libre (query `code`, `state`).
     *
     * Misión mercado-libre-integracion (27/10/2026):
     * - Las credenciales de la app salen de `MercadoLibreAppCredentials` (fila `platforms` o
     *   `.env`), ya no se exige que estén en `platforms`.
     * - `redirect_uri` del canje: si el state vino de `oauth_states`, la de conectar (el relay del
     *   admin si está configurado; si no, la propia); si es el id numérico viejo, la propia
     *   (`MERCADO_LIBRE_REDIRECT_URI`), que es con la que se armó ese link.
     * - Después del canje se consulta `users/me` y se guarda en `extra_config.meli` lo que el
     *   módulo necesita sin volver a llamar (nickname, modelo User Products, multiorigen, sitio).
     *
     * @param Request $request Request HTTP entrante.
     * @return \Illuminate\Http\Response
     */
    public function handle_mercado_libre_callback(Request $request)
    {
        // El comerciante canceló en la pantalla de Mercado Libre (ML vuelve con `error`, y el relay
        // del admin lo reenvía): página amable, sin canjear nada ni tocar el conector (si ya estaba
        // conectado, sigue conectado). El state NO se consume: no hace falta.
        if (!empty($request->query('error'))) {
            Log::info('PlatformConnector OAuth ML: el comerciante canceló la autorización ('.(string) $request->query('error').').');

            return $this->pagina(
                'Conexión cancelada',
                'Cancelaste la conexión con Mercado Libre. Podés cerrar esta ventana y volver a ComercioCity.',
                200
            );
        }

        return $this->handle_callback_internal(
            $request,
            Platform::SLUG_MERCADO_LIBRE,
            function (PlatformConnector $connector, string $code) {
                $redirect_uri = $this->state_de_oauth_states
                    ? MercadoLibreAppCredentials::redirect_uri_para_conectar()
                    : MercadoLibreAppCredentials::redirect_uri_legacy();

                if (empty($redirect_uri)) {
                    throw new \RuntimeException('Falta la redirect_uri de Mercado Libre en la configuración (MERCADO_LIBRE_OAUTH_RELAY_URL o MERCADO_LIBRE_REDIRECT_URI).');
                }

                list($client_id, $client_secret) = MercadoLibreAppCredentials::credenciales();

                // Nunca se loguea el code ni la respuesta cruda (trae access_token y refresh_token).
                $response = Http::withOptions(MercadoLibreService::http_client_options())
                    ->asForm()
                    ->post(MercadoLibreAppCredentials::TOKEN_URL, [
                    'grant_type'    => 'authorization_code',
                    'client_id'     => $client_id,
                    'client_secret' => $client_secret,
                    'code'          => $code,
                    'redirect_uri'  => $redirect_uri,
                ]);
                if (!$response->successful()) {
                    $body = $response->json();
                    $error = is_array($body) && isset($body['error']) ? (string) $body['error'] : '';
                    Log::warning('PlatformConnector OAuth ML: canje rechazado. Status: '.$response->status().' error='.$error);

                    throw new \RuntimeException(
                        'Mercado Libre rechazó la conexión ('.($error !== '' ? $error : 'HTTP '.$response->status()).'). '.
                        'Volvé a intentarlo desde ComercioCity con la cuenta TITULAR de Mercado Libre (no la de un colaborador).'
                    );
                }
                $data = $response->json();
                $connector->auth_code = $code;
                $connector->access_token = $data['access_token'];
                $connector->refresh_token = $data['refresh_token'] ?? null;
                $connector->expires_at = Carbon::now()->addSeconds((int) ($data['expires_in'] ?? 0));
                $connector->platform_user_id = isset($data['user_id']) ? (string) $data['user_id'] : null;
                $connector->status = PlatformConnector::STATUS_CONECTADO;
                $connector->error_message = null;

                $connector->extra_config = $this->extra_config_con_cuenta_de_meli($connector, (string) $data['access_token']);

                $connector->save();
            }
        );
    }

    /**
     * `extra_config` del conector con la clave `meli` armada desde `users/me` recién conectado.
     *
     * Si `users/me` falla la conexión igual queda hecha: se guarda solo `conectado_en` y el resto
     * se completa en la primera consulta (`MercadoLibreService::datos_de_la_cuenta`).
     *
     * @param PlatformConnector $connector Conector que se está conectando.
     * @param string $access_token Token recién canjeado.
     * @return array<string, mixed>
     */
    protected function extra_config_con_cuenta_de_meli(PlatformConnector $connector, $access_token)
    {
        $extra = is_array($connector->extra_config) ? $connector->extra_config : [];

        // Una reconexión arranca de cero: el último poll de pedidos de una cuenta anterior no
        // tiene por qué valer para esta.
        $meli = [
            'conectado_en' => Carbon::now()->toIso8601String(),
        ];

        try {
            $response = Http::withOptions(MercadoLibreService::http_client_options())
                ->withToken($access_token)
                ->get('https://api.mercadolibre.com/users/me');

            $usuario = $response->successful() ? $response->json() : null;

            if (is_array($usuario) && !empty($usuario['id'])) {
                $meli = array_merge($meli, MercadoLibreService::extra_meli_desde_usuario($usuario));
            } else {
                Log::warning('PlatformConnector OAuth ML: users/me no respondió un usuario. Status: '.$response->status());
            }
        } catch (\Throwable $e) {
            Log::warning('PlatformConnector OAuth ML: no se pudo consultar users/me: '.$e->getMessage());
        }

        $extra['meli'] = $meli;

        return $extra;
    }

    /**
     * Procesa el callback de Tienda Nube (query `code`, `state`).
     *
     * @param Request $request Request HTTP entrante.
     * @return \Illuminate\Http\Response
     */
    public function handle_tienda_nube_callback(Request $request)
    {
        return $this->handle_callback_internal(
            $request,
            Platform::SLUG_TIENDA_NUBE,
            function (PlatformConnector $connector, string $code) {
                $platform = $connector->platform;
                if (!$platform || empty($platform->client_id) || empty($platform->client_secret)) {
                    throw new \RuntimeException('La plataforma Tienda Nube no tiene client_id o client_secret en catálogo.');
                }
                $response = Http::withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ])->post('https://www.tiendanube.com/apps/authorize/token', [
                    'client_id'     => $platform->client_id,
                    'client_secret' => $platform->client_secret,
                    'grant_type'    => 'authorization_code',
                    'code'          => $code,
                ]);
                if (!$response->successful()) {
                    throw new \RuntimeException('Tienda Nube rechazó el intercambio de token: ' . $response->body());
                }
                $data = $response->json();
                $connector->auth_code = $code;
                $connector->access_token = $data['access_token'] ?? null;
                $connector->refresh_token = null;
                $connector->expires_at = null;
                $connector->platform_user_id = isset($data['user_id']) ? (string) $data['user_id'] : null;
                $connector->status = PlatformConnector::STATUS_CONECTADO;
                $connector->error_message = null;
                $connector->save();
            }
        );
    }

    /**
     * Lógica común de callback: valida parámetros, localiza conector y ejecuta intercambio.
     *
     * @param Request $request Request HTTP.
     * @param string $expected_platform_slug Slug esperado en `platforms.slug`.
     * @param callable $exchange Callable(PlatformConnector $connector, string $code): void
     * @return \Illuminate\Http\Response
     */
    protected function handle_callback_internal(Request $request, string $expected_platform_slug, callable $exchange)
    {
        $code = $request->query('code');
        $state = $request->query('state');
        if (!$code || !$state) {
            return response('Faltan parámetros code o state en la URL de retorno.', 400);
        }
        $connector = $this->resolver_conector_del_state((string) $state);
        if (!$connector) {
            return response('Conector no encontrado.', 404);
        }
        if (!$connector->platform || $connector->platform->slug !== $expected_platform_slug) {
            return response('La plataforma del conector no coincide con esta URL de callback.', 400);
        }

        // Mercado Libre resuelve sus credenciales con `MercadoLibreAppCredentials` (fila de
        // `platforms` o `.env`, misión mercado-libre-integracion): no se le exige que estén en el
        // catálogo. Tienda Nube sigue igual que siempre.
        if ($expected_platform_slug === Platform::SLUG_MERCADO_LIBRE) {
            if (!MercadoLibreAppCredentials::configurada()) {
                return response('Mercado Libre no está configurado en este sistema (faltan las credenciales de la app). Avisale a ComercioCity.', 400);
            }
        } else {
            // `client_secret` tiene cast `encrypted` desde la mision de ABM -> Integraciones, asi que
            // LEERLO PUEDE TIRAR. Esta lectura vive FUERA del try de mas abajo, asi que sin esta
            // guarda una fila con el secreto en texto plano (APP_KEY rotada, o una instalacion donde
            // la migracion de cifrado no corrio) devolvia un 500 pelado en el callback de ML/TN, sin
            // ninguna pista de que el problema era el descifrado.
            try {
                $client_secret = $connector->platform->client_secret;
            } catch (\Throwable $e) {
                Log::error('PlatformConnector OAuth: no se pudo descifrar el client_secret de la plataforma '.$connector->platform->slug.': '.$e->getMessage());

                return response(
                    'No se pudo leer el client_secret de la plataforma. Puede estar guardado sin cifrar '.
                    '(falta correr la migracion encrypt_platform_connector_tokens) o cifrado con otra APP_KEY.',
                    500
                );
            }

            if (empty($connector->platform->client_id) || empty($client_secret)) {
                return response('La plataforma no tiene client_id o client_secret configurados en el catálogo.', 400);
            }
        }
        // ¿Andaba la conexión antes de este callback? Se pregunta ANTES del canje (el canje cambia
        // el conector en memoria). Se mira el token crudo, sin descifrarlo.
        $estaba_conectado = $connector->status === PlatformConnector::STATUS_CONECTADO
            && !empty($connector->getAttributes()['access_token']);

        try {
            $exchange($connector, $code);
        } catch (\Throwable $e) {
            Log::error('PlatformConnector OAuth error: ' . $e->getMessage());

            // `error_message` se serializa en `GET /api/platform-connector`: un mensaje de
            // QueryException trae el SQL con sus bindings (el code y los tokens), así que ese caso
            // va con un texto fijo. Las RuntimeException que tiran los canjes ya son textos
            // armados acá, sin el cuerpo de la respuesta.
            $mensaje = ($e instanceof \RuntimeException && !($e instanceof \PDOException))
                ? $e->getMessage()
                : 'No se pudo completar la conexión. El detalle quedó en el log del servidor.';

            // El callback es PÚBLICO (revisión de la misión, 27/10/2026): cualquiera puede pegarle
            // con un `code` basura y el state viejo (el id del conector, que es adivinable). Si la
            // conexión venía andando, un canje fallido no la corta: no se toca el conector.
            if ($estaba_conectado) {
                return $this->pagina(
                    'No se pudo completar la conexión',
                    $mensaje.' Tu cuenta sigue conectada como antes.',
                    400
                );
            }

            $connector->status = PlatformConnector::STATUS_ERROR;
            $connector->error_message = mb_substr($mensaje, 0, 500);
            $connector->save();

            return response('Error al completar la conexión: ' . $mensaje, 500);
        }

        return response(
            '<html><body><h1>Conexión exitosa</h1><p>Podés cerrar esta ventana y volver a Comercio City.</p></body></html>',
            200,
            ['Content-Type' => 'text/html; charset=UTF-8']
        );
    }

    /**
     * Página HTML simple para la ventana del callback (la abre el comerciante con `window.open`).
     * El texto se escapa: puede traer un código de error que vino de la URL.
     *
     * @param string $titulo
     * @param string $texto
     * @param int $status
     * @return \Illuminate\Http\Response
     */
    protected function pagina($titulo, $texto, $status)
    {
        $html = '<html><body><h1>'.e($titulo).'</h1><p>'.e($texto).'</p></body></html>';

        return response($html, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * Resuelve el conector a partir del `state` del callback aceptando LOS DOS FORMATOS.
     *
     * 1. State aleatorio de `oauth_states` (el bueno, el que usa Mercado Pago desde el prompt
     *    598 y al que van a migrar ML y TN): se busca la fila, se valida que no haya vencido ni
     *    se haya usado, y se consume. El conector sale de `platform_connector_id`, no de la URL.
     * 2. Id del conector como state (el formato viejo, el unico que ML y TN mandan HOY en
     *    produccion): `PlatformConnector::find((int) $state)`.
     *
     * Los dos tienen que seguir andando: cuando esta mision se despliegue va a haber ventanas de
     * autorizacion de ML/TN ya abiertas en el navegador de algun comercio, con el id del conector
     * pegado en la URL de Mercado Libre. Si el callback dejara de aceptarlo, esas conexiones
     * fallarian sin que nadie entienda por que.
     *
     * 🔴 LOS DOS FORMATOS SE DISTINGUEN CON `ctype_digit()`, NO CON EL CAST A ENTERO. Este
     * metodo tenia acá un comentario que afirmaba que `(int)` sobre un state aleatorio da
     * siempre 0 "asi que no hay ambiguedad". Es FALSO y esta medido: `Str::random()` devuelve
     * alfanumerico, asi que un state que empieza con digitos castea a ese numero — sobre 10.000
     * states generados con el binario 7.4, **1429 (14%) dan un entero distinto de cero**, y 3,
     * 4, 5 y 9 son ids reales de `platform_connectors`. Sin la guarda, un replay de un state
     * aleatorio ya consumido (que `consume()` rechaza, como corresponde) se caia igual en el
     * `find()` del formato viejo y conectaba el conector de OTRO comercio.
     *
     * @param string $state Valor recibido en el query `state`.
     * @return PlatformConnector|null
     */
    protected function resolver_conector_del_state(string $state)
    {
        $this->state_de_oauth_states = false;

        // Con la URL central del admin el state puede volver como `<aleatorio>_<host>` (el admin
        // normalmente recorta el host antes de reenviar, pero se acepta de las dos formas, igual
        // que Mercado Pago). El aleatorio es alfanumérico, así que el primer `_` marca el host.
        $guion = strpos($state, '_');
        if ($guion !== false) {
            $state = substr($state, 0, $guion);
        }

        $oauth_state = OauthState::consume($state);

        if ($oauth_state && !empty($oauth_state->platform_connector_id)) {
            $this->state_de_oauth_states = true;

            return PlatformConnector::with('platform')->find((int) $oauth_state->platform_connector_id);
        }

        // Formato viejo: el state ES el id del conector, o sea SOLO digitos. Cualquier otra cosa
        // -incluido un state aleatorio ya consumido- no se intenta resolver por id.
        if (!ctype_digit($state)) {
            return null;
        }

        return PlatformConnector::with('platform')->find((int) $state);
    }
}
