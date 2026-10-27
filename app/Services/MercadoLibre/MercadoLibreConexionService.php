<?php

namespace App\Services\MercadoLibre;

use App\Models\OauthState;
use App\Models\Platform;
use App\Models\PlatformConnector;
use App\Models\SyncToMeliArticle;
use Illuminate\Support\Facades\Log;

/**
 * Conexión de la cuenta de Mercado Libre del comercio y estado de la cuenta para la solapa
 * "Cuenta" del módulo (misión mercado-libre-integracion, 27/10/2026).
 *
 * El connect espeja el de Mercado Pago (`MercadoPagoOAuthService::build_authorization_url`): el
 * `state` es aleatorio, vence y se consume una vez (`OauthState`), y con la URL central del admin
 * activa viaja como `<aleatorio>_<host de esta API>` para que el admin sepa a qué backend
 * reenviar el callback. La `redirect_uri` es la del relay, idéntica en la autorización y en el
 * canje (lo hace `PlatformConnectorOAuthService::handle_mercado_libre_callback`).
 */
class MercadoLibreConexionService
{
    /** Mensaje del 422 cuando la instalación no tiene las credenciales de la app de ML. */
    const MENSAJE_SIN_CREDENCIALES = 'Mercado Libre no está configurado en este sistema (faltan las credenciales de la app). Avisale a ComercioCity.';

    /** Regla de hostname que valida el admin antes de reenviar (misma que Mercado Pago). */
    const REGEX_HOST = '/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/';

    /**
     * Arma la URL de autorización de Mercado Libre para el comercio.
     *
     * Crea la fila `mercado_libre` de `platforms` si la instalación no la tiene (las credenciales
     * pueden venir del .env) y el conector del comercio en `sin_conectar` si todavía no existe.
     *
     * @param int $user_id Comercio (owner) que conecta.
     * @param string|null $host Host de esta API (`$request->getHost()`), para el state del relay.
     * @return string
     * @throws MercadoLibreNoConfiguradoException Si faltan credenciales o redirect.
     */
    public function url_de_autorizacion($user_id, $host = null)
    {
        list($client_id, $client_secret) = MercadoLibreAppCredentials::credenciales();
        $redirect_uri = MercadoLibreAppCredentials::redirect_uri_para_conectar();

        if (empty($client_id) || empty($client_secret) || empty($redirect_uri)) {
            throw new MercadoLibreNoConfiguradoException(self::MENSAJE_SIN_CREDENCIALES);
        }

        Platform::firstOrCreate(
            ['slug' => Platform::SLUG_MERCADO_LIBRE],
            ['name' => 'Mercado Libre']
        );

        $connector = PlatformConnector::find_or_create_for_user_and_slug((int) $user_id, Platform::SLUG_MERCADO_LIBRE);

        if (!$connector) {
            // Solo pasa si la fila de platforms no se pudo crear: mismo mensaje, mismo arreglo.
            throw new MercadoLibreNoConfiguradoException(self::MENSAJE_SIN_CREDENCIALES);
        }

        $state = OauthState::create_for_connector($connector);

        $host = strtolower(trim((string) $host));

        if (MercadoLibreAppCredentials::usa_relay() && preg_match(self::REGEX_HOST, $host)) {
            $state = $state.'_'.$host;
        }

        $query = http_build_query([
            'response_type' => 'code',
            'client_id'     => $client_id,
            'redirect_uri'  => $redirect_uri,
            'state'         => $state,
        ]);

        return MercadoLibreAppCredentials::AUTHORIZATION_URL.'?'.$query;
    }

    /**
     * Desconecta la cuenta: borra los tokens, deja el conector en `sin_conectar` y limpia
     * `extra_config.meli` (los datos de la cuenta y el último poll de pedidos). Las publicaciones
     * y los pedidos ya importados no se tocan.
     *
     * No se revoca el permiso en Mercado Libre (`DELETE /users/{id}/applications/{app}`): sin
     * tokens la app ya no puede operar y el comercio puede quitarlo desde su cuenta de ML.
     *
     * @param int $user_id Comercio (owner).
     * @return void
     */
    public function desconectar($user_id)
    {
        $connector = PlatformConnector::find_for_user_and_slug((int) $user_id, Platform::SLUG_MERCADO_LIBRE);

        if (!$connector) {
            return;
        }

        $extra = is_array($connector->extra_config) ? $connector->extra_config : [];
        unset($extra['meli']);

        $connector->access_token = null;
        $connector->refresh_token = null;
        $connector->auth_code = null;
        $connector->expires_at = null;
        $connector->platform_user_id = null;
        $connector->error_message = null;
        $connector->status = PlatformConnector::STATUS_SIN_CONECTAR;
        $connector->extra_config = count($extra) > 0 ? $extra : null;
        $connector->save();
    }

    /**
     * Estado de la cuenta para la solapa "Cuenta" del módulo. Siempre responde la forma completa:
     * sin conexión, `conectado=false` y el resto en null / vacío (la cola igual se informa, es
     * dato local).
     *
     * @param int $user_id Comercio (owner).
     * @return array<string, mixed>
     */
    public function cuenta($user_id)
    {
        $user_id = (int) $user_id;

        $cuenta = [
            'conectado'           => false,
            'nickname'            => null,
            'meli_user_id'        => null,
            'reputacion'          => null,
            'puede_publicar'      => null,
            'motivos_no_publica'  => [],
            'user_product_seller' => false,
            'multiorigen'         => false,
            'cola'                => $this->cola($user_id),
            'ultimo_poll_pedidos' => null,
            'error_conexion'      => null,
        ];

        $connector = PlatformConnector::find_for_user_and_slug($user_id, Platform::SLUG_MERCADO_LIBRE);

        if (!$connector) {
            return $cuenta;
        }

        $meli = (is_array($connector->extra_config) && isset($connector->extra_config['meli']) && is_array($connector->extra_config['meli']))
            ? $connector->extra_config['meli']
            : [];

        $cuenta['ultimo_poll_pedidos'] = isset($meli['ultimo_poll_pedidos']) ? $meli['ultimo_poll_pedidos'] : null;

        if ($connector->status === PlatformConnector::STATUS_ERROR) {
            $cuenta['error_conexion'] = $connector->error_message ?: 'La conexión con Mercado Libre falló. Volvé a conectar la cuenta.';

            return $cuenta;
        }

        if (!PlatformConnector::find_connected_mercado_libre_for_user($user_id)) {
            return $cuenta;
        }

        $cuenta['conectado'] = true;
        $cuenta['meli_user_id'] = $connector->platform_user_id;
        $cuenta['nickname'] = isset($meli['nickname']) ? $meli['nickname'] : null;
        $cuenta['user_product_seller'] = !empty($meli['user_product_seller']);
        $cuenta['multiorigen'] = !empty($meli['multiorigen']);

        try {
            $service = new MercadoLibreService($user_id);
            $usuario = $service->datos_de_la_cuenta();
        } catch (\Throwable $e) {
            Log::warning('MercadoLibreConexionService: no se pudo consultar la cuenta de ML del comercio '.$user_id.': '.$e->getMessage());

            // El refresh pudo haber cortado la conexión justo ahora: se informa lo que quedó.
            $connector->refresh();
            if ($connector->status === PlatformConnector::STATUS_ERROR) {
                $cuenta['conectado'] = false;
                $cuenta['error_conexion'] = $connector->error_message;
            } else {
                $cuenta['error_conexion'] = 'No se pudo consultar la cuenta en Mercado Libre en este momento. Probá de nuevo en unos minutos.';
            }

            return $cuenta;
        }

        if (!is_array($usuario)) {
            $cuenta['error_conexion'] = 'Mercado Libre no devolvió los datos de la cuenta.';

            return $cuenta;
        }

        $extra = MercadoLibreService::extra_meli_desde_usuario($usuario);

        $cuenta['nickname'] = $extra['nickname'];
        $cuenta['meli_user_id'] = (string) $usuario['id'];
        $cuenta['user_product_seller'] = (bool) $extra['user_product_seller'];
        $cuenta['multiorigen'] = (bool) $extra['multiorigen'];
        $cuenta['reputacion'] = $this->reputacion($usuario);

        list($puede, $motivos) = $this->puede_publicar($usuario);
        $cuenta['puede_publicar'] = $puede;
        $cuenta['motivos_no_publica'] = $motivos;

        return $cuenta;
    }

    /**
     * Reputación del vendedor tal como la pinta el termómetro de la SPA.
     *
     * @param array<string, mixed> $usuario Respuesta de `users/me`.
     * @return array<string, mixed>
     */
    protected function reputacion(array $usuario)
    {
        $rep = isset($usuario['seller_reputation']) && is_array($usuario['seller_reputation']) ? $usuario['seller_reputation'] : [];
        $transacciones = isset($rep['transactions']) && is_array($rep['transactions']) ? $rep['transactions'] : [];
        $metricas = isset($rep['metrics']) && is_array($rep['metrics']) ? $rep['metrics'] : [];

        $rate = function ($clave) use ($metricas) {
            if (isset($metricas[$clave]) && is_array($metricas[$clave]) && isset($metricas[$clave]['rate'])) {
                return (float) $metricas[$clave]['rate'];
            }

            return null;
        };

        $periodo = null;
        if (isset($metricas['sales']['period'])) {
            $periodo = (string) $metricas['sales']['period'];
        } elseif (isset($transacciones['period'])) {
            $periodo = (string) $transacciones['period'];
        }

        return [
            'level_id'                  => isset($rep['level_id']) ? $rep['level_id'] : null,
            'power_seller_status'       => isset($rep['power_seller_status']) ? $rep['power_seller_status'] : null,
            'transacciones_completadas' => isset($transacciones['completed']) ? (int) $transacciones['completed'] : 0,
            'transacciones_canceladas'  => isset($transacciones['canceled']) ? (int) $transacciones['canceled'] : 0,
            'reclamos_rate'             => $rate('claims'),
            'demoras_rate'              => $rate('delayed_handling_time'),
            'cancelaciones_rate'        => $rate('cancellations'),
            'periodo'                   => $periodo,
        ];
    }

    /**
     * Si el vendedor puede publicar (`status.list.allow`) y por qué no (`status.list.codes`).
     *
     * @param array<string, mixed> $usuario Respuesta de `users/me`.
     * @return array{0: bool|null, 1: array<int, string>}
     */
    protected function puede_publicar(array $usuario)
    {
        $lista = isset($usuario['status']['list']) && is_array($usuario['status']['list']) ? $usuario['status']['list'] : null;

        if (is_null($lista) || !array_key_exists('allow', $lista)) {
            return [null, []];
        }

        $puede = (bool) $lista['allow'];
        $motivos = [];

        if (!$puede) {
            $codigos = isset($lista['codes']) && is_array($lista['codes']) ? $lista['codes'] : [];
            foreach ($codigos as $codigo) {
                $motivos[] = 'Mercado Libre no te deja publicar todavía (código: '.$codigo.'). Entrá a tu cuenta de Mercado Libre para ver qué dato falta completar.';
            }
            if (count($motivos) === 0) {
                $motivos[] = 'Mercado Libre no te deja publicar todavía. Entrá a tu cuenta de Mercado Libre para ver qué dato falta completar.';
            }
        }

        return [$puede, $motivos];
    }

    /**
     * Conteo de la cola de sincronización de artículos del comercio.
     *
     * @param int $user_id
     * @return array<string, int>
     */
    protected function cola($user_id)
    {
        return [
            'pendientes'    => SyncToMeliArticle::where('user_id', $user_id)->whereIn('status', ['pendiente', 'en_progreso'])->count(),
            'errores'       => $this->errores_vigentes($user_id),
            'exitosas_24h'  => SyncToMeliArticle::where('user_id', $user_id)->where('status', 'exitosa')->where('synced_at', '>=', now()->subDay())->count(),
        ];
    }

    /**
     * Artículos cuya ÚLTIMA sincronización terminó en error (un error viejo que después salió bien
     * no cuenta).
     *
     * @param int $user_id
     * @return int
     */
    protected function errores_vigentes($user_id)
    {
        $ultimos_ids = SyncToMeliArticle::where('user_id', $user_id)
            ->selectRaw('MAX(id) as id')
            ->groupBy('article_id')
            ->pluck('id')
            ->all();

        if (count($ultimos_ids) === 0) {
            return 0;
        }

        return SyncToMeliArticle::whereIn('id', $ultimos_ids)->where('status', 'error')->count();
    }
}
