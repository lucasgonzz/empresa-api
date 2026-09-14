<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\ZipnovaCredentialsHelper;
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
 * - `tienda_online`: integraciones del checkout (Mercado Pago, Zipnova).
 *
 * De dónde sale el estado: de `platform_connectors`, para las cuatro. Zipnova (ex Zippin) se
 * mudó ahí en la misión zipnova-envios (14/9/2026): el OAuth viejo de `online_configurations.zippin_*`
 * nunca cotizó ni despachó nada y queda como está, pero ya no aparece en este catálogo. Para
 * Zipnova el item suma la clave `config` (solo si está conectado): las preferencias no secretas
 * del conector que la tarjeta edita (depósito de origen, bulto por defecto, envío gratis) y el
 * nombre de la cuenta. Nunca el token: es un `Basic base64(token:secret)` y vive cifrado.
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
            self::entrada_zipnova(),
        ];
    }

    /**
     * Entrada de Zipnova en el catálogo. Está aparte porque `ZipnovaIntegracionController`
     * responde `{integracion}` con esta misma forma después de conectar, desconectar o guardar
     * la config, y así el nombre y el grupo salen de un solo lugar.
     *
     * @return array<string, string>
     */
    public static function entrada_zipnova()
    {
        return [
            'slug'  => Platform::SLUG_ZIPNOVA,
            'name'  => 'Zipnova',
            'grupo' => self::GRUPO_TIENDA_ONLINE,
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

        $integraciones = [];

        foreach ($this->catalogo() as $integracion) {
            $slug = $integracion['slug'];

            $integraciones[] = self::item($integracion, isset($conectores[$slug]) ? $conectores[$slug] : null);
        }

        return response()->json(['integraciones' => $integraciones], 200);
    }

    /**
     * El item de Zipnova del comercio, tal como lo devuelve `index()`. Lo usan los endpoints de
     * `ZipnovaIntegracionController` para responder con la tarjeta ya actualizada, sin que la
     * SPA tenga que volver a pedir el listado entero.
     *
     * @param int $user_id Comercio (owner).
     * @return array<string, mixed>
     */
    public static function integracion_zipnova($user_id)
    {
        $connector = PlatformConnector::find_for_user_and_slug((int) $user_id, Platform::SLUG_ZIPNOVA);

        return self::item(self::entrada_zipnova(), $connector);
    }

    /**
     * Un item del listado: la entrada del catálogo más el estado del conector. Para Zipnova
     * conectado suma `config`; para el resto son exactamente las seis claves que la SPA consume.
     *
     * @param array<string, string> $integracion Entrada del catálogo (`slug`, `name`, `grupo`).
     * @param PlatformConnector|null $connector Conector del comercio hacia esa plataforma.
     * @return array<string, mixed>
     */
    public static function item(array $integracion, $connector)
    {
        $estado = self::estado_del_conector($connector);

        $item = [
            'slug'             => $integracion['slug'],
            'name'             => $integracion['name'],
            'grupo'            => $integracion['grupo'],
            'connected'        => $estado['connected'],
            'expires_at'       => $estado['expires_at'],
            'platform_user_id' => $estado['platform_user_id'],
        ];

        if ($integracion['slug'] === Platform::SLUG_ZIPNOVA && $estado['connected']) {
            $item['config'] = self::config_publica_de_zipnova($connector);
        }

        return $item;
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
    protected static function estado_del_conector($connector)
    {
        if (!$connector) {
            return self::estado_vacio();
        }

        return [
            'connected'        => $connector->is_connected(),
            'expires_at'       => is_null($connector->expires_at) ? null : $connector->expires_at->toJSON(),
            'platform_user_id' => $connector->platform_user_id,
        ];
    }

    /**
     * La parte de `extra_config` del conector de Zipnova que la tarjeta necesita, con los
     * defaults aplicados (`ZipnovaCredentialsHelper::config_desde_conector()`).
     *
     * Se arma clave por clave, y no devolviendo el `extra_config` entero, para que lo que viaja
     * al navegador sea una lista cerrada: si mañana el conector guarda algo que no tiene por qué
     * verse, no se cuela solo. `webhook_registrado` reemplaza al `webhook_id`/`webhook_url`
     * crudos: a la tarjeta le alcanza con saber si Zipnova va a avisar los cambios de estado o
     * si dependen del comando de cada 30 minutos.
     *
     * @param PlatformConnector $connector Conector de Zipnova conectado.
     * @return array<string, mixed>
     */
    protected static function config_publica_de_zipnova(PlatformConnector $connector)
    {
        $config = ZipnovaCredentialsHelper::config_desde_conector($connector);

        return [
            'account_name'       => $config['account_name'],
            'accounts'           => is_array($config['accounts']) ? $config['accounts'] : [],
            'origin_id'          => $config['origin_id'],
            'origin_label'       => $config['origin_label'],
            'origins'            => is_array($config['origins']) ? $config['origins'] : [],
            'bulto_default'      => $config['bulto_default'],
            'declarar_valor'     => (bool) $config['declarar_valor'],
            'envio_gratis_desde' => $config['envio_gratis_desde'],
            'webhook_registrado' => !empty($config['webhook_id']),
            'conectado_en'       => $config['conectado_en'],
        ];
    }

    /**
     * Estado de una integración que el comercio nunca conectó.
     *
     * @return array<string, mixed>
     */
    protected static function estado_vacio()
    {
        return [
            'connected'        => false,
            'expires_at'       => null,
            'platform_user_id' => null,
        ];
    }
}
