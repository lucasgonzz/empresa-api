<?php

namespace App\Services\MercadoPago;

use App\Models\MercadoPagoOauthState;
use App\Models\OnlineConfiguration;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Flujo OAuth Authorization Code de Mercado Pago para que cada comercio conecte SU propia
 * cuenta y se procesen pagos EN SU NOMBRE (grupo 170, prompt 598).
 *
 * Sigue el mismo criterio que `App\Services\MercadoLibre\MercadoLibreService` y
 * `App\Services\PlatformConnector\PlatformConnectorOAuthService` (OAuth ya existente en el
 * repo), adaptado a que acá los tokens se guardan en `online_configuration` (columnas del
 * prompt 597) en vez de en un `PlatformConnector`.
 *
 * Importante: las credenciales que usa esta clase (`services.mercadopago.app_id/app_secret`)
 * son las de la APLICACIÓN de ComercioCity (fijas, vienen del .env), nunca las del comercio.
 * NO se usa `application_fee`/`marketplace_fee`: ComercioCity no cobra comisión por transacción.
 */
class MercadoPagoOAuthService
{
    /** URL de autorización de Mercado Pago (el comercio la abre en su navegador). */
    const AUTHORIZATION_URL = 'https://auth.mercadopago.com.ar/authorization';

    /** Endpoint de canje/refresh de tokens de Mercado Pago. */
    const TOKEN_URL = 'https://api.mercadopago.com/oauth/token';

    /**
     * Opciones Guzzle/cURL para las peticiones a Mercado Pago (ver config/services.php).
     * Mismo criterio que MercadoLibreService::http_client_options().
     *
     * @return array
     */
    public static function http_client_options()
    {
        $ca_bundle = (string) config('services.mercadopago.guzzle_ca_bundle', '');
        if ($ca_bundle !== '') {
            return ['verify' => $ca_bundle];
        }

        return [
            'verify' => (bool) config('services.mercadopago.guzzle_verify', true),
        ];
    }

    /**
     * Cliente HTTP Laravel (Guzzle) con las opciones SSL del entorno.
     *
     * @return \Illuminate\Http\Client\PendingRequest
     */
    protected function mp_http()
    {
        return Http::withOptions(self::http_client_options());
    }

    /**
     * Arma la URL de autorización de Mercado Pago para que el comercio autenticado la abra
     * desde el SPA, generando y persistiendo antes un `state` aleatorio atado a su user_id
     * (protección anti-CSRF, prompt 598).
     *
     * @param int $user_id Comercio (owner) autenticado que pide conectar su cuenta.
     * @return string URL completa de autorización de Mercado Pago.
     */
    public function build_authorization_url($user_id)
    {
        $redirect_uri = config('services.mercadopago.oauth_redirect_uri');
        $client_id = config('services.mercadopago.app_id');

        if (empty($client_id) || empty($redirect_uri)) {
            throw new \RuntimeException('Faltan MP_APP_ID / MP_OAUTH_REDIRECT_URI en la configuración de Mercado Pago.');
        }

        // El state se persiste en BD (no en Cache: ver comentario de la migración) con
        // vencimiento corto, para que el callback pueda validarlo y saber a qué comercio
        // corresponde el `code` que Mercado Pago le devuelva.
        $state = MercadoPagoOauthState::create_for_user((int) $user_id);

        $query = http_build_query([
            'client_id'     => $client_id,
            'response_type' => 'code',
            'platform_id'   => 'mp',
            'state'         => $state,
            'redirect_uri'  => $redirect_uri,
        ]);

        return self::AUTHORIZATION_URL . '?' . $query;
    }

    /**
     * Procesa el callback OAuth de Mercado Pago: valida el `state`, canjea el `code` por
     * tokens y los guarda en el `online_configuration` del comercio correcto. Siempre termina
     * redirigiendo al SPA de empresa (nunca deja tokens/secret en la URL de retorno).
     *
     * @param Request $request Request HTTP del callback (`code`, `state` por query).
     * @return \Illuminate\Http\RedirectResponse
     */
    public function handle_callback(Request $request)
    {
        $code = $request->query('code');
        $state = $request->query('state');

        if (empty($code) || empty($state)) {
            Log::warning('MercadoPagoOAuthService: callback sin code o state.');

            return $this->redirect_to_spa(false);
        }

        // Consumir el state: valida que exista, no haya vencido y no se haya usado antes, y
        // devuelve el user_id del comercio que inició el connect. Se consume una sola vez.
        $user_id = MercadoPagoOauthState::consume((string) $state);

        if (is_null($user_id)) {
            Log::warning('MercadoPagoOAuthService: state inválido, vencido o ya usado.');

            return $this->redirect_to_spa(false);
        }

        $configuration = OnlineConfiguration::where('user_id', $user_id)
            ->orderBy('created_at', 'DESC')
            ->first();

        if (!$configuration) {
            Log::warning("MercadoPagoOAuthService: no existe online_configuration para user_id {$user_id}.");

            return $this->redirect_to_spa(false);
        }

        $client_id = config('services.mercadopago.app_id');
        $client_secret = config('services.mercadopago.app_secret');
        $redirect_uri = config('services.mercadopago.oauth_redirect_uri');

        if (empty($client_id) || empty($client_secret) || empty($redirect_uri)) {
            Log::error('MercadoPagoOAuthService: faltan credenciales de la app de Mercado Pago en configuración.');

            return $this->redirect_to_spa(false);
        }

        try {
            // Nunca se loguea $code ni la respuesta cruda (contiene access_token/refresh_token).
            $response = $this->mp_http()->asForm()->post(self::TOKEN_URL, [
                'grant_type'    => 'authorization_code',
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'code'          => $code,
                'redirect_uri'  => $redirect_uri,
            ]);

            if (!$response->successful()) {
                Log::error('MercadoPagoOAuthService: Mercado Pago rechazó el intercambio de token. Status: ' . $response->status());

                return $this->redirect_to_spa(false);
            }

            $data = $response->json();

            $configuration->mp_access_token = $data['access_token'];
            $configuration->mp_refresh_token = isset($data['refresh_token']) ? $data['refresh_token'] : null;
            $configuration->mp_user_id = isset($data['user_id']) ? (string) $data['user_id'] : null;
            $configuration->mp_public_key = isset($data['public_key']) ? $data['public_key'] : null;
            $configuration->mp_token_expires_at = isset($data['expires_in'])
                ? Carbon::now()->addSeconds((int) $data['expires_in'])
                : null;
            $configuration->mp_enabled = true;
            $configuration->save();
        } catch (\Throwable $e) {
            // Se loguea solo el mensaje de la excepción, nunca variables que puedan contener tokens.
            Log::error('MercadoPagoOAuthService: excepción al canjear el code de Mercado Pago: ' . $e->getMessage());

            return $this->redirect_to_spa(false);
        }

        return $this->redirect_to_spa(true);
    }

    /**
     * Desconecta la cuenta de Mercado Pago del comercio: limpia tokens y apaga el master switch.
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

        $configuration->mp_access_token = null;
        $configuration->mp_refresh_token = null;
        $configuration->mp_user_id = null;
        $configuration->mp_public_key = null;
        $configuration->mp_token_expires_at = null;
        $configuration->mp_enabled = false;
        $configuration->save();

        return $configuration;
    }

    /**
     * Renueva el access_token de un comercio conectado usando su refresh_token. Si Mercado Pago
     * responde que el refresh es inválido (comercio revocó el acceso desde su cuenta de MP), se
     * marca el comercio como desconectado en lugar de propagar la excepción, para que el
     * command que recorre varios comercios no corte el loop por uno solo que falló.
     *
     * @param OnlineConfiguration $configuration Configuración del comercio a renovar.
     * @return bool true si se renovó correctamente, false si se marcó desconectado por fallo.
     */
    public function refresh_configuration(OnlineConfiguration $configuration)
    {
        $client_id = config('services.mercadopago.app_id');
        $client_secret = config('services.mercadopago.app_secret');

        if (empty($client_id) || empty($client_secret) || empty($configuration->mp_refresh_token)) {
            Log::warning("MercadoPagoOAuthService: online_configuration {$configuration->id} sin refresh_token o credenciales de app, se marca desconectado.");
            $this->mark_disconnected($configuration);

            return false;
        }

        try {
            $response = $this->mp_http()->asForm()->post(self::TOKEN_URL, [
                'grant_type'    => 'refresh_token',
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'refresh_token' => $configuration->mp_refresh_token,
            ]);

            if (!$response->successful()) {
                Log::warning("MercadoPagoOAuthService: refresh de token rechazado para online_configuration {$configuration->id}. Status: " . $response->status());
                $this->mark_disconnected($configuration);

                return false;
            }

            $data = $response->json();

            $configuration->mp_access_token = $data['access_token'];
            if (!empty($data['refresh_token'])) {
                $configuration->mp_refresh_token = $data['refresh_token'];
            }
            if (isset($data['expires_in'])) {
                $configuration->mp_token_expires_at = Carbon::now()->addSeconds((int) $data['expires_in']);
            }
            $configuration->save();

            return true;
        } catch (\Throwable $e) {
            Log::error("MercadoPagoOAuthService: excepción al refrescar token de online_configuration {$configuration->id}: " . $e->getMessage());
            $this->mark_disconnected($configuration);

            return false;
        }
    }

    /**
     * Limpia los tokens de una configuración y apaga el master switch de Mercado Pago, sin
     * lanzar excepción (falla controlada usada tanto en refresh como en callback).
     *
     * @param OnlineConfiguration $configuration
     * @return void
     */
    protected function mark_disconnected(OnlineConfiguration $configuration)
    {
        $configuration->mp_access_token = null;
        $configuration->mp_refresh_token = null;
        $configuration->mp_token_expires_at = null;
        $configuration->mp_enabled = false;
        $configuration->save();
    }

    /**
     * Redirige al SPA de empresa (pantalla de Integraciones) con el resultado del OAuth.
     * Nunca incluye tokens ni el secret en la URL.
     *
     * @param bool $success
     * @return \Illuminate\Http\RedirectResponse
     */
    protected function redirect_to_spa($success)
    {
        $base_url = config('services.mercadopago.spa_redirect_url');

        if (empty($base_url)) {
            // Sin URL de SPA configurada, se responde un HTML simple (igual que el fallback de
            // PlatformConnectorOAuthService) en vez de redirigir a una URL vacía.
            $message = $success
                ? 'Conexión con Mercado Pago exitosa. Podés cerrar esta ventana y volver a Comercio City.'
                : 'No se pudo completar la conexión con Mercado Pago. Volvé a intentarlo desde Comercio City.';

            return response($message, $success ? 200 : 422);
        }

        $separator = (strpos($base_url, '?') === false) ? '?' : '&';

        return redirect($base_url . $separator . 'mp=' . ($success ? 'ok' : 'error'));
    }
}
