<?php

namespace App\Services\MercadoLibre;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\PlatformConnector;
use App\Services\MercadoLibre\ErrorHandler;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente base hacia api.mercadolibre.com usando OAuth del `PlatformConnector` del tenant.
 *
 * Misión mercado-libre-integracion (27/10/2026):
 * - El token se refresca ANTES de vencer (5 minutos de margen) y bajo un candado por conector: el
 *   refresh_token de ML es de un solo uso, y dos procesos refrescando a la vez dejaban al vendedor
 *   desconectado.
 * - Un refresh rechazado con `invalid_grant` marca el conector en `error` con un mensaje que el
 *   comercio entiende y avisa UNA vez.
 * - Las llamadas llevan una pausa mínima entre sí, reintentan 429/5xx con backoff (respetando
 *   `Retry-After`) y un 409 una vez; agotados, `MercadoLibreLimiteException`.
 * - Se sacaron los `Log::info` de URL y parámetros de cada llamada (queda uno en `debug`).
 */
class MercadoLibreService
{
    /** Mensaje fijo cuando ML corta la conexión (refresh con `invalid_grant`). */
    const MENSAJE_CONEXION_CORTADA = 'Mercado Libre cortó la conexión (cambio de contraseña, permiso quitado o mucho tiempo sin uso). Volvé a conectar la cuenta.';

    /** Minutos antes del vencimiento en los que ya se refresca el access_token. */
    const MINUTOS_MARGEN_REFRESH = 5;

    /** @var PlatformConnector Conector ML conectado del usuario */
    protected $platform_connector;

    /** @var int Usuario interno dueño del conector (para notificaciones) */
    protected $user_id;

    /**
     * Si es false, los errores HTTP de make_request no disparan GlobalNotification
     * (el llamador notifica una sola vez al marcar fallida la sync del artículo).
     *
     * @var bool
     */
    protected $notify_on_api_error = true;

    /** @var string URL base de la API pública de Mercado Libre */
    protected string $base_url = 'https://api.mercadolibre.com/';

    /**
     * Momento (microtime en segundos) de la última llamada a ML desde este proceso, para la pausa
     * mínima entre llamadas. Estático a propósito: el comando crea un servicio por corrida, pero
     * el webhook y el listado crean otros en el mismo proceso.
     *
     * @var float
     */
    protected static $ultima_llamada_en = 0.0;

    /**
     * Resuelve el conector ML del usuario y refresca el token si vence pronto.
     *
     * @param int|null $user_id Usuario interno; por defecto el autenticado o config USER_ID.
     * @return void
     */
    public function __construct($user_id = null)
    {
        if (is_null($user_id)) {
            $user_id = UserHelper::userId();
        }

        $this->user_id = (int) $user_id;

        $this->platform_connector = PlatformConnector::find_connected_mercado_libre_for_user($this->user_id);

        if (!$this->platform_connector) {
            ErrorHandler::notify_plain_message(
                $this->user_id,
                'Mercado Libre no conectado',
                'Conectá tu cuenta en Integraciones → Conector de plataforma antes de usar esta función.'
            );
            throw new \Exception("No existe conector de Mercado Libre conectado para el usuario {$this->user_id}");
        }

        if ($this->necesita_refresh()) {
            $this->refresh_token();
        }
    }

    /**
     * Opciones Guzzle/cURL para peticiones a Mercado Libre (ver config/services.php).
     *
     * @return array<string, mixed>
     */
    public static function http_client_options(): array
    {
        $ca_bundle = (string) config('services.mercadolibre.guzzle_ca_bundle', '');
        if ($ca_bundle !== '') {
            return ['verify' => $ca_bundle];
        }

        return [
            'verify' => (bool) config('services.mercadolibre.guzzle_verify', true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function meli_http_options(): array
    {
        return self::http_client_options();
    }

    /**
     * Cliente HTTP Laravel con opciones SSL del entorno (WAMP/local vs producción).
     *
     * @return \Illuminate\Http\Client\PendingRequest
     */
    protected function meli_http()
    {
        return Http::withOptions($this->meli_http_options());
    }

    /**
     * Conector de Mercado Libre que usa este servicio.
     *
     * @return PlatformConnector
     */
    public function conector()
    {
        return $this->platform_connector;
    }

    /**
     * Ejecuta una petición autenticada y devuelve la respuesta HTTP cruda (permite leer headers
     * como x-version).
     *
     * Reintentos (misión mercado-libre-integracion):
     * - 401: refresca el token (bajo candado) y reintenta una vez.
     * - 429 y 5xx: hasta 3 reintentos con backoff 1 s / 2 s / 4 s más jitter, respetando
     *   `Retry-After` si viene. Con `$reintentar_5xx = false` un 5xx se devuelve tal cual (lo usa
     *   el alta de una publicación: un 5xx en un POST puede haber creado el ítem igual, y
     *   reintentar a ciegas duplicaría la publicación).
     * - 409 ("optimistic locking"): espera y reintenta una vez.
     * Agotados, tira `MercadoLibreLimiteException`.
     *
     * @param string $method Verbo HTTP en minúsculas (get, post, put, ...).
     * @param string $endpoint Path relativo al host de ML.
     * @param array<string, mixed> $data Query o body según el verbo.
     * @param array<string, string> $request_headers Headers adicionales (p. ej. x-version).
     * @param bool $reintentar_5xx Si un 5xx se reintenta (default) o se devuelve.
     * @return \Illuminate\Http\Client\Response
     */
    protected function meli_authenticated_request(string $method, string $endpoint, array $data = [], array $request_headers = [], $reintentar_5xx = true)
    {
        $method = strtolower($method);
        $url = $this->base_url.ltrim($endpoint, '/');

        // Solo el verbo y la URL: los parámetros pueden traer datos del comercio y no hacen falta
        // para diagnosticar. Nunca el token.
        Log::debug('meli_authenticated_request: '.$method.' '.$url);

        $reintentos_por_limite = 0;
        $reintento_409_hecho = false;
        $refresh_hecho = false;

        while (true) {
            $this->esperar_turno();

            $token_usado = $this->credencial_del_conector('access_token');

            $http = $this->meli_http()->withToken($token_usado);
            if (count($request_headers) > 0) {
                $http = $http->withHeaders($request_headers);
            }

            try {
                $response = $http->{$method}($url, $data);
            } catch (ConnectionException $e) {
                // Timeout o red caída. Si el llamador admite reintentar (todo menos el alta), es
                // como un 5xx: la tarea vuelve a la cola y se reintenta sola. El alta lo maneja
                // aparte (un POST cortado pudo haber creado el ítem igual).
                if ($reintentar_5xx) {
                    throw new MercadoLibreLimiteException('No se pudo conectar con Mercado Libre ('.$e->getMessage().'); se reintenta solo.');
                }

                throw $e;
            }

            $status = $response->status();

            if ($status === 401 && !$refresh_hecho) {
                $refresh_hecho = true;
                $this->refresh_token($token_usado);

                continue;
            }

            $es_limite = $status === 429 || ($status >= 500 && $reintentar_5xx);

            if ($es_limite) {
                if ($reintentos_por_limite >= 3) {
                    throw new MercadoLibreLimiteException(
                        'Mercado Libre pidió esperar (HTTP '.$status.') y se agotaron los reintentos.'
                    );
                }

                $this->dormir_ms($this->ms_de_backoff($reintentos_por_limite, $response->header('Retry-After')));
                $reintentos_por_limite++;

                continue;
            }

            if ($status === 409) {
                if ($reintento_409_hecho) {
                    throw new MercadoLibreLimiteException(
                        'Mercado Libre respondió 409 (otro cambio en curso sobre el mismo recurso) dos veces seguidas.'
                    );
                }

                $reintento_409_hecho = true;
                $this->dormir_ms((int) config('services.mercadolibre.espera_409_ms', 2000));

                continue;
            }

            return $response;
        }
    }

    /**
     * Milisegundos a esperar antes del reintento número `$intento` (0, 1, 2) por un 429 / 5xx.
     *
     * Si ML manda `Retry-After` (segundos), se respeta con un tope de 30 s para no colgar la
     * corrida del scheduler. Si no, backoff exponencial (base 1 s) más un jitter de hasta 250 ms.
     *
     * @param int $intento Reintento que se va a hacer (empezando en 0).
     * @param string|null $retry_after Header `Retry-After` de la respuesta.
     * @return int
     */
    protected function ms_de_backoff($intento, $retry_after = null)
    {
        $base = (int) config('services.mercadolibre.backoff_ms', 1000);

        if ($base <= 0) {
            return 0;
        }

        if (!is_null($retry_after) && $retry_after !== '' && ctype_digit((string) $retry_after)) {
            return min(((int) $retry_after) * 1000, 30000);
        }

        return ($base * (int) pow(2, (int) $intento)) + mt_rand(0, 250);
    }

    /**
     * Pausa mínima entre dos llamadas a ML desde este proceso (`ms_entre_llamadas`).
     *
     * @return void
     */
    protected function esperar_turno()
    {
        $minimo_ms = (int) config('services.mercadolibre.ms_entre_llamadas', 250);

        if ($minimo_ms > 0 && self::$ultima_llamada_en > 0) {
            $transcurrido_ms = (microtime(true) - self::$ultima_llamada_en) * 1000;
            if ($transcurrido_ms < $minimo_ms) {
                $this->dormir_ms((int) ($minimo_ms - $transcurrido_ms));
            }
        }

        self::$ultima_llamada_en = microtime(true);
    }

    /**
     * Duerme los milisegundos indicados (punto único, para poder neutralizarlo en tests vía config).
     *
     * @param int $ms
     * @return void
     */
    protected function dormir_ms($ms)
    {
        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }

    /**
     * Ejecuta una petición autenticada contra la API de Mercado Libre.
     *
     * @param string $method Verbo HTTP en minúsculas (get, post, put, ...).
     * @param string $endpoint Path relativo al host de ML.
     * @param array<string, mixed> $data Query o body según el verbo.
     * @param array<string, string> $request_headers Headers adicionales (p. ej. x-version).
     * @return array<string, mixed>|null JSON decodificado; null en 404.
     */
    protected function make_request(string $method, string $endpoint, array $data = [], array $request_headers = [])
    {
        $url = $this->base_url.ltrim($endpoint, '/');
        $response = $this->meli_authenticated_request($method, $endpoint, $data, $request_headers);

        if ($response->status() === 404) {
            Log::warning("Mercado Libre devolvió 404 para {$url}");

            return null;
        }

        if (!$response->successful()) {
            if ($this->notify_on_api_error) {
                ErrorHandler::send_notification($response, $this->user_id);
            }
            throw new \Exception('Mercado Libre API error: '.$response->body());
        }

        return $response->json();
    }

    /**
     * True si el access_token ya venció o vence en menos de `MINUTOS_MARGEN_REFRESH`, o si no hay
     * vencimiento registrado pero sí un refresh_token (conector cargado a mano).
     *
     * Se mira el atributo crudo del refresh_token para no descifrarlo solo para saber si existe.
     *
     * @return bool
     */
    protected function necesita_refresh()
    {
        $tiene_refresh = !empty($this->platform_connector->getAttributes()['refresh_token']);

        if (!$tiene_refresh) {
            return false;
        }

        $expires_at = $this->platform_connector->expires_at;

        if (empty($expires_at)) {
            return true;
        }

        return $expires_at->lte(Carbon::now()->addMinutes(self::MINUTOS_MARGEN_REFRESH));
    }

    /**
     * Renueva el access_token usando refresh_token y persiste en `platform_connectors`.
     *
     * Bajo `Cache::lock('meli_refresh_<id>')`: adentro se relee la fila y, si otro proceso ya
     * refrescó mientras se esperaba el candado, se usa su token y no se refresca de nuevo (el
     * refresh_token de ML es de un solo uso: usar uno ya rotado da `invalid_grant` y desconecta).
     *
     * @param string|null $token_rechazado Token que ML acaba de rechazar con 401 (null = refresh por
     *        vencimiento). Si al releer la fila el token ya es otro, alguien refrescó y alcanza.
     * @return void
     */
    protected function refresh_token($token_rechazado = null)
    {
        $lock = Cache::lock('meli_refresh_'.$this->platform_connector->id, 30);

        try {
            $lock->block(20);
        } catch (LockTimeoutException $e) {
            throw new MercadoLibreLimiteException('Otro proceso está renovando la sesión de Mercado Libre; se reintenta solo.');
        }

        try {
            $this->platform_connector->refresh();

            if (!is_null($token_rechazado)) {
                if ($this->credencial_del_conector('access_token') !== $token_rechazado) {
                    return;
                }
            } elseif (!$this->necesita_refresh()) {
                return;
            }

            $this->pedir_refresh_a_mercado_libre();
        } finally {
            $lock->release();
        }
    }

    /**
     * El refresh propiamente dicho, ya con el candado tomado.
     *
     * @return void
     */
    protected function pedir_refresh_a_mercado_libre()
    {
        list($client_id, $client_secret) = $this->resolve_meli_oauth_app_credentials();

        // El refresh_token con el que se pide (para saber, si ML lo rechaza, si otro proceso ya lo
        // usó y guardó uno nuevo).
        $refresh_usado = $this->credencial_del_conector('refresh_token');

        $response = $this->meli_http()->asForm()->post(MercadoLibreAppCredentials::TOKEN_URL, [
            'grant_type'    => 'refresh_token',
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
            'refresh_token' => $refresh_usado,
        ]);

        if (!$response->successful()) {
            $body = $response->json();
            $error = is_array($body) && isset($body['error']) ? (string) $body['error'] : '';

            if ($error === 'invalid_grant') {
                /*
                 * 🔴 Revisión de merge (27/10/2026): con `CACHE_DRIVER=array` (clientes viejos) el
                 * candado de arriba no cubre dos procesos, y el refresh_token de ML es de UN uso: el
                 * proceso que pierde la carrera recibe invalid_grant aunque la conexión esté bien.
                 * Antes de cortarla se relee la fila: si el refresh_token cambió, otro proceso ya
                 * renovó; se usa su token y se sigue.
                 */
                if ($this->otro_proceso_ya_renovo($refresh_usado)) {
                    Log::info('MercadoLibreService: invalid_grant en el conector '.$this->platform_connector->id.' porque otro proceso ya había renovado; se usa su token.');

                    return;
                }

                $this->marcar_conexion_cortada();

                throw new \RuntimeException(self::MENSAJE_CONEXION_CORTADA);
            }

            if ($response->status() === 429 || $response->status() >= 500) {
                throw new MercadoLibreLimiteException('Mercado Libre no pudo renovar la sesión ahora (HTTP '.$response->status().'); se reintenta solo.');
            }

            // Solo el status: el cuerpo de un error de OAuth no trae tokens, pero no hace falta.
            Log::warning('MercadoLibreService: refresh rechazado para platform_connector '.$this->platform_connector->id.'. Status: '.$response->status().' error='.$error);
            ErrorHandler::send_notification(
                $response,
                $this->user_id,
                'No se pudo renovar la sesión de Mercado Libre'
            );
            throw new \RuntimeException('No se pudo renovar la sesión de Mercado Libre (HTTP '.$response->status().').');
        }

        $data = $response->json();

        // 🔴 NO SE LOGUEA `$data`. Hasta la mision de ABM -> Integraciones acá habia un
        // `Log::info($data)` con el JSON completo del OAuth, o sea el `access_token` y el
        // `refresh_token` en texto plano dentro de `storage/logs/laravel.log`, en CADA refresh.
        // Cifrar la columna y despues dejar el token legible en el log no sirve de nada. Se deja
        // solo lo que sirve para diagnosticar y no es secreto.
        Log::info('refresh_token ok: expires_in='.(isset($data['expires_in']) ? $data['expires_in'] : 's/d').
                  ', refresh rotado='.(!empty($data['refresh_token']) ? 'si' : 'no'));

        $this->platform_connector->access_token = $data['access_token'];
        if (!empty($data['refresh_token'])) {
            $this->platform_connector->refresh_token = $data['refresh_token'];
        }
        if (isset($data['expires_in'])) {
            $this->platform_connector->expires_at = Carbon::now()->addSeconds((int) $data['expires_in']);
        }
        $this->platform_connector->status = PlatformConnector::STATUS_CONECTADO;
        $this->platform_connector->error_message = null;
        $this->platform_connector->save();
    }

    /**
     * Relee la fila del conector y dice si otro proceso ya renovó el token: el refresh_token
     * guardado es otro que el que se usó y la conexión sigue `conectado`. Si es así, el conector en
     * memoria ya quedó con el token nuevo.
     *
     * No se toma la fila con `lockForUpdate` alrededor del refresh: eso dejaría una fila bloqueada
     * mientras dura una llamada HTTP a ML (sin tope de tiempo), y cualquier otro proceso del
     * comercio esperaría o caería por timeout de bloqueo. La relectura cubre el caso que importa
     * (el que pierde la carrera no corta la conexión).
     *
     * @param string|null $refresh_usado
     * @return bool
     */
    protected function otro_proceso_ya_renovo($refresh_usado)
    {
        $this->platform_connector->refresh();

        if ($this->platform_connector->status !== PlatformConnector::STATUS_CONECTADO) {
            return false;
        }

        try {
            $refresh_actual = $this->platform_connector->refresh_token;
        } catch (\Throwable $e) {
            return false;
        }

        return !empty($refresh_actual) && $refresh_actual !== $refresh_usado;
    }

    /**
     * Deja el conector en `error` con el mensaje fijo de conexión cortada y avisa al comercio, una
     * sola vez: si ya estaba en `error` con ese mensaje, no se vuelve a notificar.
     *
     * Los tokens no se borran (no sirven, pero tampoco molestan, y `status=error` ya saca al
     * conector de todos los `mercadoLibreConnected()`).
     *
     * @return void
     */
    protected function marcar_conexion_cortada()
    {
        $ya_avisado = $this->platform_connector->status === PlatformConnector::STATUS_ERROR
            && $this->platform_connector->error_message === self::MENSAJE_CONEXION_CORTADA;

        $this->platform_connector->status = PlatformConnector::STATUS_ERROR;
        $this->platform_connector->error_message = self::MENSAJE_CONEXION_CORTADA;
        $this->platform_connector->save();

        Log::warning('MercadoLibreService: refresh con invalid_grant, conector '.$this->platform_connector->id.' marcado en error.');

        if (!$ya_avisado) {
            ErrorHandler::notify_plain_message(
                $this->user_id,
                'Mercado Libre se desconectó',
                self::MENSAJE_CONEXION_CORTADA
            );
        }
    }

    /**
     * Obtiene client_id y client_secret de la app (ver `MercadoLibreAppCredentials`).
     *
     * @return array{0: string|null, 1: string|null}
     */
    protected function resolve_meli_oauth_app_credentials(): array
    {
        return MercadoLibreAppCredentials::credenciales();
    }

    /**
     * Lee un atributo cifrado del conector traduciendo un fallo de descifrado a un error que se
     * entienda.
     *
     * Desde la mision de ABM -> Integraciones, `access_token` y `refresh_token` tienen cast
     * `encrypted`, asi que leerlos puede tirar `DecryptException` si la fila quedo en texto
     * plano (la migracion `encrypt_platform_connector_tokens` no corrio) o si esta cifrada con
     * otra APP_KEY. Sin esto, el sintoma era un 500 pelado en cualquier llamada a Mercado Libre,
     * sin ninguna pista de que el problema era el descifrado.
     *
     * @param string $atributo Nombre del atributo cifrado (`access_token` / `refresh_token`).
     * @return string|null
     */
    protected function credencial_del_conector(string $atributo)
    {
        try {
            return $this->platform_connector->{$atributo};
        } catch (\Throwable $e) {
            Log::error(
                'MercadoLibreService: no se pudo descifrar '.$atributo.' del platform_connector '.
                $this->platform_connector->id.': '.$e->getMessage()
            );

            throw new \RuntimeException(
                'No se pudo leer la credencial de Mercado Libre del conector '.$this->platform_connector->id.'. '.
                'Puede estar guardada sin cifrar (falta correr la migracion '.
                'encrypt_platform_connector_tokens) o cifrada con otra APP_KEY. Reconectar la '.
                'cuenta desde ABM -> Integraciones la vuelve a dejar bien.'
            );
        }
    }

    /**
     * Id del vendedor en Mercado Libre (`platform_user_id` del conector).
     *
     * @return string
     */
    protected function meli_seller_id(): string
    {
        return (string) $this->platform_connector->platform_user_id;
    }

    /**
     * `GET users/me` del vendedor conectado, cacheado 10 minutos por conector.
     *
     * De paso refresca en `extra_config.meli` lo que el resto del módulo consulta sin llamar a ML
     * (nickname, modelo User Products, multiorigen, sitio).
     *
     * @param bool $forzar true para ignorar la caché.
     * @return array<string, mixed>|null null si ML no respondió un usuario.
     */
    public function datos_de_la_cuenta($forzar = false)
    {
        $clave = 'meli_users_me_'.$this->platform_connector->id;

        if (!$forzar) {
            try {
                $cacheado = Cache::get($clave);
                if (is_array($cacheado)) {
                    return $cacheado;
                }
            } catch (\Throwable $e) {
                // Sin caché se consulta igual.
            }
        }

        $usuario = $this->make_request('get', 'users/me');

        if (!is_array($usuario) || empty($usuario['id'])) {
            return null;
        }

        try {
            Cache::put($clave, $usuario, 600);
        } catch (\Throwable $e) {
            // Nada.
        }

        $this->guardar_extra_meli(self::extra_meli_desde_usuario($usuario));

        return $usuario;
    }

    /**
     * Lo que se guarda en `extra_config.meli` a partir de un `users/me`.
     *
     * @param array<string, mixed> $usuario Respuesta de `GET users/me`.
     * @return array<string, mixed>
     */
    public static function extra_meli_desde_usuario(array $usuario)
    {
        $tags = isset($usuario['tags']) && is_array($usuario['tags']) ? $usuario['tags'] : [];

        return [
            'nickname'            => isset($usuario['nickname']) ? (string) $usuario['nickname'] : null,
            'user_product_seller' => in_array('user_product_seller', $tags, true),
            'multiorigen'         => in_array('warehouse_management', $tags, true),
            'site_id'             => isset($usuario['site_id']) ? (string) $usuario['site_id'] : null,
        ];
    }

    /**
     * Mezcla claves en `extra_config.meli` del conector y guarda (sin pisar las otras claves de
     * `extra_config` ni las de `meli` que no vienen).
     *
     * @param array<string, mixed> $valores
     * @return void
     */
    protected function guardar_extra_meli(array $valores)
    {
        // Se relee de la base (revisión de la misión, 27/10/2026): el conector en memoria puede
        // tener horas (un comando largo) y otro proceso pudo haber escrito `extra_config` mientras
        // tanto (el callback de una reconexión, la solapa Cuenta, otra consulta de pedidos).
        // Partir de la copia vieja le pisaba esas claves.
        $crudo = DB::table('platform_connectors')->where('id', $this->platform_connector->id)->value('extra_config');
        $extra = is_string($crudo) ? json_decode($crudo, true) : null;

        if (!is_array($extra)) {
            $extra = is_array($this->platform_connector->extra_config) ? $this->platform_connector->extra_config : [];
        }

        $meli = (isset($extra['meli']) && is_array($extra['meli'])) ? $extra['meli'] : [];
        $extra['meli'] = array_merge($meli, $valores);

        // Solo esta columna: un save() del modelo podría escribir otros atributos viejos.
        DB::table('platform_connectors')->where('id', $this->platform_connector->id)->update([
            'extra_config' => json_encode($extra),
            'updated_at'   => now(),
        ]);

        $this->platform_connector->extra_config = $extra;
        $this->platform_connector->syncOriginalAttributes(['extra_config']);
    }

    /**
     * Un valor de `extra_config.meli` del conector, o el default.
     *
     * @param string $clave
     * @param mixed $default
     * @return mixed
     */
    protected function extra_meli($clave, $default = null)
    {
        $extra = is_array($this->platform_connector->extra_config) ? $this->platform_connector->extra_config : [];

        if (isset($extra['meli']) && is_array($extra['meli']) && array_key_exists($clave, $extra['meli'])) {
            return $extra['meli'][$clave];
        }

        return $default;
    }

    /**
     * True si la cuenta publica con el modelo User Products (tag `user_product_seller`).
     *
     * Se lee de `extra_config.meli` (lo guarda el callback del OAuth); si falta, se consulta
     * `users/me` UNA vez y queda guardado.
     *
     * @return bool
     */
    public function es_vendedor_user_products()
    {
        $valor = $this->extra_meli('user_product_seller');

        if (is_null($valor)) {
            $usuario = $this->datos_de_la_cuenta();

            return is_array($usuario) ? (bool) $this->extra_meli('user_product_seller', false) : false;
        }

        return (bool) $valor;
    }

    /**
     * True si la cuenta usa multiorigen (tag `warehouse_management`): el stock va por depósito y
     * no por `available_quantity`.
     *
     * @return bool
     */
    public function es_multiorigen()
    {
        $valor = $this->extra_meli('multiorigen');

        if (is_null($valor)) {
            $usuario = $this->datos_de_la_cuenta();

            return is_array($usuario) ? (bool) $this->extra_meli('multiorigen', false) : false;
        }

        return (bool) $valor;
    }

    /**
     * Notifica al usuario un error de excepción (salvo duplicados de API ML).
     *
     * @param \Throwable $exception Excepción capturada.
     * @param string $message_text Título del modal.
     * @return void
     */
    protected function notify_meli_exception(\Throwable $exception, string $message_text = 'Error en Mercado Libre'): void
    {
        ErrorHandler::notify_exception($this->user_id, $exception, $message_text, true);
    }
}
