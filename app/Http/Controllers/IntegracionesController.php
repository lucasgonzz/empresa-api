<?php

namespace App\Http\Controllers;

use App\Models\OnlineConfiguration;
use App\Models\Platform;
use App\Models\PlatformConnector;

/**
 * Estado de las integraciones del comercio, para pintar las tarjetas de ABM -> Integraciones.
 *
 * 🔴 ESTE ENDPOINT NO SERIALIZA NINGUN TOKEN, Y ESE ES EL MOTIVO DE FONDO DE TODA LA MISION.
 * Hasta ahora la SPA se enteraba del estado de Mercado Pago leyendo el `online_configuration`
 * entero, la misma fila que `tienda-api` publica sin autenticacion en `CommerceController@commerce`.
 * Acá se devuelve lo justo para dibujar una tarjeta —si está conectada, cuándo vence y con qué
 * cuenta— y nada más. Ni `access_token`, ni `refresh_token`, ni `client_secret`, ni `public_key`.
 *
 * Grupos (son las solapas de la pantalla):
 * - `sistema`: integraciones del ERP (Mercado Libre, Tienda Nube).
 * - `tienda_online`: integraciones del checkout (Mercado Pago, Zippin).
 *
 * De dónde sale el estado de cada una:
 * - Mercado Libre, Tienda Nube y Mercado Pago: de `platform_connectors`.
 * - Zippin: todavía de `online_configurations.zippin_*`. Esta misión mudó solo Mercado Pago;
 *   Zippin se muda después, por el mismo camino, y cuando eso pase lo único que cambia acá es
 *   de dónde se lee — la forma de la respuesta ya es la definitiva.
 */
class IntegracionesController extends Controller
{
    /** Solapa "Sistema": integraciones del ERP. */
    const GRUPO_SISTEMA = 'sistema';

    /** Solapa "Tienda online": integraciones del checkout. */
    const GRUPO_TIENDA_ONLINE = 'tienda_online';

    /**
     * Catálogo de las integraciones que la pantalla muestra, en el orden en que se muestran.
     * El `name` es el que ve el operador; el `slug` es el que la SPA usa para elegir la tarjeta.
     *
     * @return array<int, array<string, string>>
     */
    protected function catalogo()
    {
        return [
            [
                'slug'  => Platform::SLUG_MERCADO_LIBRE,
                'name'  => 'Mercado Libre',
                'grupo' => self::GRUPO_SISTEMA,
            ],
            [
                'slug'  => Platform::SLUG_TIENDA_NUBE,
                'name'  => 'Tienda Nube',
                'grupo' => self::GRUPO_SISTEMA,
            ],
            [
                'slug'  => Platform::SLUG_MERCADO_PAGO,
                'name'  => 'Mercado Pago',
                'grupo' => self::GRUPO_TIENDA_ONLINE,
            ],
            [
                'slug'  => 'zippin',
                'name'  => 'Zippin',
                'grupo' => self::GRUPO_TIENDA_ONLINE,
            ],
        ];
    }

    /**
     * Lista el estado de todas las integraciones del comercio autenticado.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $user_id = $this->userId();

        $conectores = $this->conectores_por_slug($user_id);
        $configuration = OnlineConfiguration::where('user_id', $user_id)
            ->orderBy('created_at', 'DESC')
            ->first();

        $integraciones = [];

        foreach ($this->catalogo() as $integracion) {
            $slug = $integracion['slug'];

            $estado = ($slug === 'zippin')
                ? $this->estado_de_zippin($configuration)
                : $this->estado_del_conector(isset($conectores[$slug]) ? $conectores[$slug] : null);

            $integraciones[] = [
                'slug'             => $slug,
                'name'             => $integracion['name'],
                'grupo'            => $integracion['grupo'],
                'connected'        => $estado['connected'],
                'expires_at'       => $estado['expires_at'],
                'platform_user_id' => $estado['platform_user_id'],
            ];
        }

        return response()->json(['integraciones' => $integraciones], 200);
    }

    /**
     * Conectores del comercio indexados por slug de plataforma, en una sola query.
     *
     * Si por lo que sea hubiera más de un conector para la misma plataforma, gana el de id más
     * alto (el más nuevo), mismo criterio que `PlatformConnector::find_for_user_and_slug()`.
     *
     * @param int $user_id Comercio (owner).
     * @return array<string, PlatformConnector>
     */
    protected function conectores_por_slug($user_id)
    {
        $conectores = [];

        $models = PlatformConnector::with('platform')
            ->where('user_id', $user_id)
            ->orderBy('id', 'ASC')
            ->get();

        foreach ($models as $model) {
            if (!$model->platform) {
                continue;
            }
            $conectores[$model->platform->slug] = $model;
        }

        return $conectores;
    }

    /**
     * Estado de una integración que vive en `platform_connectors`.
     *
     * `connected` usa `PlatformConnector::is_connected()`, que es el mismo criterio exacto que
     * traía `OnlineConfiguration::getMpConnectedAttribute()`: hay access_token guardado Y (no
     * hay vencimiento registrado, o todavía es futuro). Se lee el atributo crudo, sin
     * desencriptar el token.
     *
     * @param PlatformConnector|null $connector
     * @return array<string, mixed>
     */
    protected function estado_del_conector($connector)
    {
        if (!$connector) {
            return $this->estado_vacio();
        }

        return [
            'connected'        => $connector->is_connected(),
            'expires_at'       => is_null($connector->expires_at) ? null : $connector->expires_at->toJSON(),
            'platform_user_id' => $connector->platform_user_id,
        ];
    }

    /**
     * Estado de Zippin, que todavía vive en `online_configurations.zippin_*`.
     *
     * @param OnlineConfiguration|null $configuration
     * @return array<string, mixed>
     */
    protected function estado_de_zippin($configuration)
    {
        if (!$configuration) {
            return $this->estado_vacio();
        }

        return [
            'connected'        => (bool) $configuration->zippin_connected,
            'expires_at'       => is_null($configuration->zippin_token_expires_at)
                ? null
                : $configuration->zippin_token_expires_at->toJSON(),
            // El equivalente al `platform_user_id` de un conector: con qué cuenta de Zippin
            // quedó vinculado el comercio. No es secreto.
            'platform_user_id' => $configuration->zippin_account_id,
        ];
    }

    /**
     * Estado de una integración que el comercio nunca conectó.
     *
     * @return array<string, mixed>
     */
    protected function estado_vacio()
    {
        return [
            'connected'        => false,
            'expires_at'       => null,
            'platform_user_id' => null,
        ];
    }
}
