<?php

namespace App\Services\MercadoPago;

use App\Models\OauthState;
use App\Models\OnlineConfiguration;
use App\Models\Platform;
use App\Models\PlatformConnector;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Flujo OAuth Authorization Code de Mercado Pago para que cada comercio conecte SU propia
 * cuenta y se procesen pagos EN SU NOMBRE (grupo 170, prompt 598).
 *
 * DONDE SE GUARDA (cambio de esta mision): los tokens del comercio ahora viven en
 * `platform_connectors`, junto a los de Mercado Libre y Tienda Nube, y NO mas en las columnas
 * `online_configurations.mp_*`. El motivo es concreto: `online_configuration` viaja entera en
 * una respuesta publica de `tienda-api` (`CommerceController@commerce`, sin autenticacion), asi
 * que las credenciales de cobro no tienen por que estar ahi ni un dia mas. `platform_connectors`
 * no se serializa en ninguna respuesta publica y, desde esta misma mision, cifra sus tokens.
 *
 * NADA SE BORRA: las columnas `mp_*` de `online_configurations` quedan como estaban y la
 * migracion `copiar_credenciales_mp_a_conectores` COPIA lo que haya ahi (o en `payment_methods`)
 * hacia el conector. La limpieza es una mision posterior, cuando la tienda tambien lea del
 * conector.
 *
 * Sigue el mismo criterio que `App\Services\MercadoLibre\MercadoLibreService` y
 * `App\Services\PlatformConnector\PlatformConnectorOAuthService`, con una diferencia a favor:
 * el `state` es aleatorio, vence y se consume una sola vez (`OauthState`), en vez de ser el id
 * del conector.
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
     * Credenciales de la APLICACION de ComercioCity en Mercado Pago.
     *
     * Se leen primero del `.env` (`config/services.php`), que es de donde salian desde el prompt
     * 598 y sigue siendo la fuente de verdad. Si no estan ahi, se cae a la fila `mercado_pago`
     * de `platforms` (que siembra `PlatformSeeder`), que es el lugar donde el OAuth generico de
     * ML/TN guarda las suyas. Asi una instancia que ya tenga el .env configurado no cambia de
     * comportamiento, y una nueva puede configurarlas por catalogo.
     *
     * @return array{client_id: string|null, client_secret: string|null}
     */
    protected function app_credentials()
    {
        $client_id     = config('services.mercadopago.app_id');
        $client_secret = config('services.mercadopago.app_secret');

        if (!empty($client_id) && !empty($client_secret)) {
            return ['client_id' => $client_id, 'client_secret' => $client_secret];
        }

        $platform = Platform::where('slug', Platform::SLUG_MERCADO_PAGO)->first();

        if ($platform) {
            $client_id     = empty($client_id) ? $platform->client_id : $client_id;
            $client_secret = empty($client_secret) ? $platform->client_secret : $client_secret;
        }

        return ['client_id' => $client_id, 'client_secret' => $client_secret];
    }

    /**
     * Conector de Mercado Pago del comercio, creandolo en `sin_conectar` si todavia no existe.
     *
     * @param int $user_id Comercio (owner).
     * @return PlatformConnector|null null solo si falta la fila `mercado_pago` en `platforms`.
     */
    public function connector_for_user($user_id)
    {
        return PlatformConnector::find_or_create_for_user_and_slug(
            (int) $user_id,
            Platform::SLUG_MERCADO_PAGO
        );
    }

    /**
     * Arma la URL de autorización de Mercado Pago para que el comercio autenticado la abra
     * desde el SPA, generando y persistiendo antes un `state` aleatorio atado a su conector
     * (protección anti-CSRF, prompt 598).
     *
     * @param int $user_id Comercio (owner) autenticado que pide conectar su cuenta.
     * @return string URL completa de autorización de Mercado Pago.
     */
    public function build_authorization_url($user_id)
    {
        $redirect_uri = config('services.mercadopago.oauth_redirect_uri');
        $credentials = $this->app_credentials();

        if (empty($credentials['client_id']) || empty($redirect_uri)) {
            throw new \RuntimeException('Faltan MP_APP_ID / MP_OAUTH_REDIRECT_URI en la configuración de Mercado Pago.');
        }

        $connector = $this->connector_for_user($user_id);

        if (!$connector) {
            throw new \RuntimeException('Falta la plataforma "mercado_pago" en el catálogo. Corré el PlatformSeeder.');
        }

        // El state se persiste en BD (no en Cache: ver comentario de la migración) con
        // vencimiento corto, para que el callback pueda validarlo y saber a qué conector
        // corresponde el `code` que Mercado Pago le devuelva.
        $state = OauthState::create_for_connector($connector);

        $query = http_build_query([
            'client_id'     => $credentials['client_id'],
            'response_type' => 'code',
            'platform_id'   => 'mp',
            'state'         => $state,
            'redirect_uri'  => $redirect_uri,
        ]);

        return self::AUTHORIZATION_URL . '?' . $query;
    }

    /**
     * Procesa el callback OAuth de Mercado Pago: valida el `state`, canjea el `code` por
     * tokens y los guarda en el `platform_connector` del comercio correcto. Siempre termina
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
        // devuelve la fila con el comercio y (si lo tiene) el conector que inició el connect.
        // Se consume una sola vez.
        $oauth_state = OauthState::consume((string) $state);

        if (is_null($oauth_state)) {
            Log::warning('MercadoPagoOAuthService: state inválido, vencido o ya usado.');

            return $this->redirect_to_spa(false);
        }

        $connector = null;

        if (!empty($oauth_state->platform_connector_id)) {
            $connector = PlatformConnector::with('platform')->find((int) $oauth_state->platform_connector_id);
        }

        if (!$connector) {
            // State del flujo viejo (`create_for_user`), sin conector atado: una ventana de
            // autorización que el comercio abrió ANTES de que se desplegara esta misión y
            // completó después. Se resuelve por comercio y se sigue igual.
            $connector = $this->connector_for_user((int) $oauth_state->user_id);
        }

        if (!$connector) {
            Log::warning("MercadoPagoOAuthService: no se pudo resolver el conector de Mercado Pago para user_id {$oauth_state->user_id}.");

            return $this->redirect_to_spa(false);
        }

        $credentials = $this->app_credentials();
        $redirect_uri = config('services.mercadopago.oauth_redirect_uri');

        if (empty($credentials['client_id']) || empty($credentials['client_secret']) || empty($redirect_uri)) {
            Log::error('MercadoPagoOAuthService: faltan credenciales de la app de Mercado Pago en configuración.');

            return $this->redirect_to_spa(false);
        }

        try {
            // Nunca se loguea $code ni la respuesta cruda (contiene access_token/refresh_token).
            $response = $this->mp_http()->asForm()->post(self::TOKEN_URL, [
                'grant_type'    => 'authorization_code',
                'client_id'     => $credentials['client_id'],
                'client_secret' => $credentials['client_secret'],
                'code'          => $code,
                'redirect_uri'  => $redirect_uri,
            ]);

            if (!$response->successful()) {
                Log::error('MercadoPagoOAuthService: Mercado Pago rechazó el intercambio de token. Status: ' . $response->status());
                $this->marcar_error($connector, 'Mercado Pago rechazó el intercambio de token.');

                return $this->redirect_to_spa(false);
            }

            $data = $response->json();

            $connector->auth_code = $code;
            $connector->access_token = $data['access_token'];
            $connector->refresh_token = isset($data['refresh_token']) ? $data['refresh_token'] : null;
            $connector->platform_user_id = isset($data['user_id']) ? (string) $data['user_id'] : null;
            $connector->public_key = isset($data['public_key']) ? $data['public_key'] : null;
            $connector->expires_at = isset($data['expires_in'])
                ? Carbon::now()->addSeconds((int) $data['expires_in'])
                : null;
            $connector->status = PlatformConnector::STATUS_CONECTADO;
            $connector->error_message = null;
            $connector->save();
        } catch (\Throwable $e) {
            // Se loguea solo el mensaje de la excepción, nunca variables que puedan contener tokens.
            Log::error('MercadoPagoOAuthService: excepción al canjear el code de Mercado Pago: ' . $e->getMessage());
            $this->marcar_error($connector, $e->getMessage());

            return $this->redirect_to_spa(false);
        }

        return $this->redirect_to_spa(true);
    }

    /**
     * Desconecta la cuenta de Mercado Pago del comercio: limpia los tokens del conector y, si
     * todavía tiene credenciales viejas en `online_configuration`, también las limpia y apaga
     * ahí el master switch.
     *
     * Lo segundo NO contradice el "esta misión no borra nada" (que es sobre el esquema y sobre
     * la migración de datos, que copia y no mueve): acá el comercio pidió explícitamente
     * desconectarse, y dejarle los tokens viejos guardados en la otra tabla sería exactamente
     * el problema que esta misión viene a resolver.
     *
     * `payment_methods.access_token` NO se toca: es lo que hoy usa la tienda para cobrar y
     * borrarlo dejaría al comercio sin poder cobrar.
     *
     * @param int $user_id Comercio (owner) autenticado.
     * @return OnlineConfiguration|null La configuración online del comercio, si tiene una.
     */
    public function disconnect($user_id)
    {
        $connector = PlatformConnector::find_for_user_and_slug((int) $user_id, Platform::SLUG_MERCADO_PAGO);

        if ($connector) {
            $this->mark_disconnected($connector);
        }

        $configuration = OnlineConfiguration::where('user_id', $user_id)
            ->orderBy('created_at', 'DESC')
            ->first();

        if ($configuration) {
            $configuration->mp_access_token = null;
            $configuration->mp_refresh_token = null;
            $configuration->mp_user_id = null;
            $configuration->mp_public_key = null;
            $configuration->mp_token_expires_at = null;
            $configuration->mp_enabled = false;
            $configuration->save();
        }

        return $configuration;
    }

    /**
     * Renueva el access_token de un comercio conectado usando su refresh_token. Si Mercado Pago
     * responde que el refresh es inválido (comercio revocó el acceso desde su cuenta de MP), se
     * marca el conector como desconectado en lugar de propagar la excepción, para que el
     * command que recorre varios comercios no corte el loop por uno solo que falló.
     *
     * @param PlatformConnector $connector Conector de Mercado Pago a renovar.
     * @return bool true si se renovó correctamente, false si se marcó desconectado por fallo.
     */
    public function refresh_connector(PlatformConnector $connector)
    {
        $credentials = $this->app_credentials();

        if (empty($credentials['client_id']) || empty($credentials['client_secret']) || empty($connector->refresh_token)) {
            Log::warning("MercadoPagoOAuthService: platform_connector {$connector->id} sin refresh_token o credenciales de app, se marca desconectado.");
            $this->mark_disconnected($connector);

            return false;
        }

        try {
            $response = $this->mp_http()->asForm()->post(self::TOKEN_URL, [
                'grant_type'    => 'refresh_token',
                'client_id'     => $credentials['client_id'],
                'client_secret' => $credentials['client_secret'],
                'refresh_token' => $connector->refresh_token,
            ]);

            if (!$response->successful()) {
                Log::warning("MercadoPagoOAuthService: refresh de token rechazado para platform_connector {$connector->id}. Status: " . $response->status());
                $this->mark_disconnected($connector);

                return false;
            }

            $data = $response->json();

            $connector->access_token = $data['access_token'];
            if (!empty($data['refresh_token'])) {
                $connector->refresh_token = $data['refresh_token'];
            }
            if (!empty($data['public_key'])) {
                $connector->public_key = $data['public_key'];
            }
            if (isset($data['expires_in'])) {
                $connector->expires_at = Carbon::now()->addSeconds((int) $data['expires_in']);
            }
            $connector->status = PlatformConnector::STATUS_CONECTADO;
            $connector->error_message = null;
            $connector->save();

            return true;
        } catch (\Throwable $e) {
            Log::error("MercadoPagoOAuthService: excepción al refrescar token de platform_connector {$connector->id}: " . $e->getMessage());
            $this->mark_disconnected($connector);

            return false;
        }
    }

    /**
     * Limpia los tokens de un conector y lo deja en `sin_conectar`, sin lanzar excepción (falla
     * controlada usada tanto en refresh como en disconnect).
     *
     * @param PlatformConnector $connector
     * @return void
     */
    protected function mark_disconnected(PlatformConnector $connector)
    {
        $connector->access_token = null;
        $connector->refresh_token = null;
        $connector->expires_at = null;
        $connector->public_key = null;
        $connector->platform_user_id = null;
        $connector->auth_code = null;
        $connector->status = PlatformConnector::STATUS_SIN_CONECTAR;
        $connector->save();
    }

    /**
     * Deja el conector en estado `error` con el motivo, sin tocar los tokens que ya tuviera:
     * un canje fallido no tiene por qué desconectar una cuenta que venía andando.
     *
     * @param PlatformConnector $connector
     * @param string $mensaje
     * @return void
     */
    protected function marcar_error(PlatformConnector $connector, $mensaje)
    {
        $connector->status = PlatformConnector::STATUS_ERROR;
        $connector->error_message = $mensaje;
        $connector->save();
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
