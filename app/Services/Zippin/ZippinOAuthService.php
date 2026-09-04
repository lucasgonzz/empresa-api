<?php

namespace App\Services\Zippin;

use App\Models\OnlineConfiguration;
use App\Models\ZippinOauthState;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Flujo OAuth Authorization Code de Zippin para que cada comercio conecte SU propia cuenta de
 * Zipnova y se gestionen sus envíos EN SU NOMBRE (grupo 171, prompt 599).
 *
 * Es el gemelo de `App\Services\MercadoPago\MercadoPagoOAuthService` (prompt 598): mismo
 * criterio de `state` anti-CSRF persistido en BD, mismo lugar de guardado de tokens
 * (`online_configuration`, columnas del prompt 597) y mismo criterio de refresh programado sin
 * cortar el loop ante un comercio que revocó el acceso.
 *
 * Importante: las credenciales que usa esta clase (`services.zippin.client_id/client_secret`)
 * son las de la APLICACIÓN oficial de ComercioCity dada de alta en el programa de integradores
 * de Zippin (fijas, vienen del .env), nunca las del comercio.
 *
 * Nota (verificar contra docs.zipnova.com vigente): las URLs de autorización/token/cuenta se
 * dejan configurables en `config/services.php` (con un valor por defecto razonable) en vez de
 * hardcodeadas como constantes, a diferencia de Mercado Pago, justamente porque el prompt pide
 * verificarlas contra la documentación oficial al momento de implementar y no hay forma de
 * confirmar el dominio/paths exactos desde este entorno sin acceso a internet. Si alguno difiere
 * de la doc real, ajustar solo el `.env` (ZIPPIN_AUTHORIZATION_URL / ZIPPIN_TOKEN_URL /
 * ZIPPIN_ACCOUNT_URL), sin tocar código.
 */
class ZippinOAuthService
{
    /**
     * Opciones Guzzle/cURL para las peticiones a Zippin (ver config/services.php). Mismo
     * criterio que MercadoPagoOAuthService::http_client_options().
     *
     * @return array
     */
    public static function http_client_options()
    {
        $ca_bundle = (string) config('services.zippin.guzzle_ca_bundle', '');
        if ($ca_bundle !== '') {
            return ['verify' => $ca_bundle];
        }

        return [
            'verify' => (bool) config('services.zippin.guzzle_verify', true),
        ];
    }

    /**
     * Cliente HTTP Laravel (Guzzle) con las opciones SSL del entorno.
     *
     * @return \Illuminate\Http\Client\PendingRequest
     */
    protected function zippin_http()
    {
        return Http::withOptions(self::http_client_options());
    }

    /**
     * Arma la URL de autorización de Zippin para que el comercio autenticado la abra desde el
     * SPA, generando y persistiendo antes un `state` aleatorio atado a su user_id (protección
     * anti-CSRF, prompt 599).
     *
     * @param int $user_id Comercio (owner) autenticado que pide conectar su cuenta.
     * @return string URL completa de autorización de Zippin.
     */
    public function build_authorization_url($user_id)
    {
        $authorization_url = config('services.zippin.authorization_url');
        $redirect_uri = config('services.zippin.oauth_redirect_uri');
        $client_id = config('services.zippin.client_id');
        $scopes = config('services.zippin.oauth_scopes');

        if (empty($client_id) || empty($redirect_uri) || empty($authorization_url)) {
            throw new \RuntimeException('Faltan ZIPPIN_CLIENT_ID / ZIPPIN_OAUTH_REDIRECT_URI / ZIPPIN_AUTHORIZATION_URL en la configuración de Zippin.');
        }

        // El state se persiste en BD (mismo motivo que OauthState: no depender del
        // driver de cache configurado en cada entorno) con vencimiento corto, para que el
        // callback pueda validarlo y saber a qué comercio corresponde el `code` que Zippin
        // le devuelve.
        $state = ZippinOauthState::create_for_user((int) $user_id);

        $query = http_build_query([
            'client_id'     => $client_id,
            'response_type' => 'code',
            'redirect_uri'  => $redirect_uri,
            // Permisos/scopes de Zippin separados por espacio (ver prompt 599).
            'scope'         => $scopes,
            'state'         => $state,
        ]);

        return $authorization_url . '?' . $query;
    }

    /**
     * Procesa el callback OAuth de Zippin: valida el `state`, canjea el `code` por tokens,
     * obtiene el `account_id` de la cuenta Zipnova conectada y los guarda en el
     * `online_configuration` del comercio correcto. Siempre termina redirigiendo al SPA de
     * empresa (nunca deja tokens/secret en la URL de retorno).
     *
     * @param Request $request Request HTTP del callback (`code`, `state` por query).
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\Response
     */
    public function handle_callback(Request $request)
    {
        $code = $request->query('code');
        $state = $request->query('state');

        if (empty($code) || empty($state)) {
            Log::warning('ZippinOAuthService: callback sin code o state.');

            return $this->redirect_to_spa(false);
        }

        // Consumir el state: valida que exista, no haya vencido y no se haya usado antes, y
        // devuelve el user_id del comercio que inició el connect. Se consume una sola vez.
        $user_id = ZippinOauthState::consume((string) $state);

        if (is_null($user_id)) {
            Log::warning('ZippinOAuthService: state inválido, vencido o ya usado.');

            return $this->redirect_to_spa(false);
        }

        $configuration = OnlineConfiguration::where('user_id', $user_id)
            ->orderBy('created_at', 'DESC')
            ->first();

        if (!$configuration) {
            Log::warning("ZippinOAuthService: no existe online_configuration para user_id {$user_id}.");

            return $this->redirect_to_spa(false);
        }

        $client_id = config('services.zippin.client_id');
        $client_secret = config('services.zippin.client_secret');
        $redirect_uri = config('services.zippin.oauth_redirect_uri');
        $token_url = config('services.zippin.token_url');

        if (empty($client_id) || empty($client_secret) || empty($redirect_uri) || empty($token_url)) {
            Log::error('ZippinOAuthService: faltan credenciales/URLs de la app de Zippin en configuración.');

            return $this->redirect_to_spa(false);
        }

        try {
            // Nunca se loguea $code ni la respuesta cruda (contiene access_token/refresh_token).
            $response = $this->zippin_http()->asForm()->post($token_url, [
                'grant_type'    => 'authorization_code',
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'code'          => $code,
                'redirect_uri'  => $redirect_uri,
            ]);

            if (!$response->successful()) {
                Log::error('ZippinOAuthService: Zippin rechazó el intercambio de token. Status: ' . $response->status());

                return $this->redirect_to_spa(false);
            }

            $data = $response->json();

            $configuration->zippin_access_token = $data['access_token'];
            $configuration->zippin_refresh_token = isset($data['refresh_token']) ? $data['refresh_token'] : null;
            $configuration->zippin_token_expires_at = isset($data['expires_in'])
                ? Carbon::now()->addSeconds((int) $data['expires_in'])
                : null;

            // Con el access_token recién obtenido, se resuelve el account_id de la cuenta
            // Zipnova conectada (hace falta para operar envíos después, ver prompt 599).
            $configuration->zippin_account_id = $this->fetch_account_id($configuration->zippin_access_token);
            $configuration->zippin_enabled = true;
            $configuration->save();
        } catch (\Throwable $e) {
            // Se loguea solo el mensaje de la excepción, nunca variables que puedan contener tokens.
            Log::error('ZippinOAuthService: excepción al canjear el code de Zippin: ' . $e->getMessage());

            return $this->redirect_to_spa(false);
        }

        return $this->redirect_to_spa(true);
    }

    /**
     * Resuelve el `account_id` (identificador de cuenta Zipnova) de la cuenta recién conectada,
     * usando el access_token obtenido. Si la petición falla no corta el flujo de conexión: el
     * comercio queda igual conectado (los tokens ya se guardaron), solo sin account_id, y podrá
     * volver a intentarse más adelante.
     *
     * @param string $access_token Access token recién obtenido para la cuenta del comercio.
     * @return string|null
     */
    protected function fetch_account_id($access_token)
    {
        $account_url = config('services.zippin.account_url');

        if (empty($account_url)) {
            return null;
        }

        try {
            $response = $this->zippin_http()
                ->withToken($access_token)
                ->get($account_url);

            if (!$response->successful()) {
                Log::warning('ZippinOAuthService: no se pudo obtener el account_id de Zippin. Status: ' . $response->status());

                return null;
            }

            $data = $response->json();

            // La doc de Zippin puede exponer el id de cuenta como `id` o `account_id` según el
            // endpoint; se contemplan ambas claves por robustez.
            if (isset($data['account_id'])) {
                return (string) $data['account_id'];
            }
            if (isset($data['id'])) {
                return (string) $data['id'];
            }

            return null;
        } catch (\Throwable $e) {
            Log::warning('ZippinOAuthService: excepción al obtener el account_id de Zippin: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Desconecta la cuenta de Zippin del comercio: limpia tokens y apaga el master switch.
     *
     * @param int $user_id Comercio (owner) autenticado.
     * @return OnlineConfiguration|null El modelo actualizado, o null si no tiene configuración.
     */
    public function disconnect($user_id)
    {
        $configuration = OnlineConfiguration::where('user_id', $user_id)
            ->orderBy('created_at', 'DESC')
            ->first();

        if (!$configuration) {
            return null;
        }

        $configuration->zippin_access_token = null;
        $configuration->zippin_refresh_token = null;
        $configuration->zippin_account_id = null;
        $configuration->zippin_token_expires_at = null;
        $configuration->zippin_enabled = false;
        $configuration->save();

        return $configuration;
    }

    /**
     * Renueva el access_token de un comercio conectado usando su refresh_token. Si Zippin
     * responde que el refresh es inválido (comercio revocó el acceso desde su cuenta Zipnova), se
     * marca el comercio como desconectado en lugar de propagar la excepción, para que el
     * command que recorre varios comercios no corte el loop por uno solo que falló.
     *
     * @param OnlineConfiguration $configuration Configuración del comercio a renovar.
     * @return bool true si se renovó correctamente, false si se marcó desconectado por fallo.
     */
    public function refresh_configuration(OnlineConfiguration $configuration)
    {
        $client_id = config('services.zippin.client_id');
        $client_secret = config('services.zippin.client_secret');
        $token_url = config('services.zippin.token_url');

        if (empty($client_id) || empty($client_secret) || empty($token_url) || empty($configuration->zippin_refresh_token)) {
            Log::warning("ZippinOAuthService: online_configuration {$configuration->id} sin refresh_token o credenciales de app, se marca desconectado.");
            $this->mark_disconnected($configuration);

            return false;
        }

        try {
            $response = $this->zippin_http()->asForm()->post($token_url, [
                'grant_type'    => 'refresh_token',
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'refresh_token' => $configuration->zippin_refresh_token,
            ]);

            if (!$response->successful()) {
                Log::warning("ZippinOAuthService: refresh de token rechazado para online_configuration {$configuration->id}. Status: " . $response->status());
                $this->mark_disconnected($configuration);

                return false;
            }

            $data = $response->json();

            $configuration->zippin_access_token = $data['access_token'];
            if (!empty($data['refresh_token'])) {
                $configuration->zippin_refresh_token = $data['refresh_token'];
            }
            if (isset($data['expires_in'])) {
                $configuration->zippin_token_expires_at = Carbon::now()->addSeconds((int) $data['expires_in']);
            }
            $configuration->save();

            return true;
        } catch (\Throwable $e) {
            Log::error("ZippinOAuthService: excepción al refrescar token de online_configuration {$configuration->id}: " . $e->getMessage());
            $this->mark_disconnected($configuration);

            return false;
        }
    }

    /**
     * Limpia los tokens de una configuración y apaga el master switch de Zippin, sin lanzar
     * excepción (falla controlada usada tanto en refresh como en callback).
     *
     * @param OnlineConfiguration $configuration
     * @return void
     */
    protected function mark_disconnected(OnlineConfiguration $configuration)
    {
        $configuration->zippin_access_token = null;
        $configuration->zippin_refresh_token = null;
        $configuration->zippin_token_expires_at = null;
        $configuration->zippin_enabled = false;
        $configuration->save();
    }

    /**
     * Redirige al SPA de empresa (pantalla de Integraciones) con el resultado del OAuth. Nunca
     * incluye tokens ni el secret en la URL.
     *
     * @param bool $success
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\Response
     */
    protected function redirect_to_spa($success)
    {
        $base_url = config('services.zippin.spa_redirect_url');

        if (empty($base_url)) {
            // Sin URL de SPA configurada, se responde un HTML simple (mismo fallback que
            // MercadoPagoOAuthService) en vez de redirigir a una URL vacía.
            $message = $success
                ? 'Conexión con Zippin exitosa. Podés cerrar esta ventana y volver a Comercio City.'
                : 'No se pudo completar la conexión con Zippin. Volvé a intentarlo desde Comercio City.';

            return response($message, $success ? 200 : 422);
        }

        $separator = (strpos($base_url, '?') === false) ? '?' : '&';

        return redirect($base_url . $separator . 'zippin=' . ($success ? 'ok' : 'error'));
    }
}
