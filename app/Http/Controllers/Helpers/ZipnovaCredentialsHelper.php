<?php

namespace App\Http\Controllers\Helpers;

use App\Models\Platform;
use App\Models\PlatformConnector;
use App\Services\Zipnova\ZipnovaClient;
use App\Services\Zipnova\ZipnovaPaquetesHelper;
use Illuminate\Support\Facades\Log;

/**
 * Resuelve las credenciales y la configuración de Zipnova de un comercio
 * (misión zipnova-envios, 14/9/2026).
 *
 * Una sola fuente: el conector `zipnova` de `platform_connectors`, conectado
 * (`PlatformConnector::is_connected()`). No hay fallback a `online_configurations.zippin_*`: ese
 * OAuth nunca cotizó ni despachó nada, así que no hay comercio que hoy dependa de él.
 *
 * Leer `access_token` descifra con la APP_KEY y puede tirar `DecryptException` (cliente con
 * clave distinta, fila en plano). Se atrapa acá y se devuelve "no conectado" con un log, para
 * que un checkout público nunca reviente con 500 por esto.
 *
 * 🔴 ARCHIVO ESPEJO ENTRE `empresa-api` Y `tienda-api` (solo cambia el `use` de los modelos).
 */
class ZipnovaCredentialsHelper
{
    /**
     * Valores por defecto de la configuración del conector. Cualquier clave que falte en
     * `extra_config` toma el default; así una versión vieja del ERP que guardó menos claves no
     * rompe a la tienda.
     *
     * @return array
     */
    public static function config_defaults()
    {
        return [
            'account_name'        => null,
            'accounts'            => [],
            'origin_id'           => null,
            'origin_label'        => null,
            'origins'             => [],
            'bulto_default'       => ZipnovaPaquetesHelper::BULTO_FALLBACK,
            'declarar_valor'      => true,
            'envio_gratis_desde'  => null,
            'webhook_id'          => null,
            'webhook_url'         => null,
            'conectado_en'        => null,
        ];
    }

    /**
     * Conector de Zipnova del comercio, solo si está conectado.
     *
     * @param int $user_id Comercio (owner).
     * @return PlatformConnector|null
     */
    public static function connector($user_id)
    {
        $connector = PlatformConnector::find_for_user_and_slug((int) $user_id, Platform::SLUG_ZIPNOVA);

        if (!$connector || !$connector->is_connected()) {
            return null;
        }

        return $connector;
    }

    /**
     * True si el comercio tiene Zipnova conectado y la credencial se puede leer.
     *
     * @param int $user_id
     * @return bool
     */
    public static function esta_conectado($user_id)
    {
        $credentials = self::credentials($user_id);

        return !is_null($credentials['basic']);
    }

    /**
     * Credenciales vigentes del comercio. Nunca lanza: si no hay conector o el token no se puede
     * descifrar, `basic` y `account_id` vienen en null.
     *
     * @param int $user_id
     * @return array{basic: string|null, account_id: string|null, config: array}
     */
    public static function credentials($user_id)
    {
        $vacio = ['basic' => null, 'account_id' => null, 'config' => self::config_defaults()];

        $connector = self::connector($user_id);
        if (!$connector) {
            return $vacio;
        }

        try {
            $basic = (string) $connector->access_token;
        } catch (\Throwable $e) {
            Log::warning('ZipnovaCredentialsHelper: no se pudo descifrar el access_token del conector ' . $connector->id . ' (user_id ' . $user_id . '): ' . $e->getMessage());

            return $vacio;
        }

        if ($basic === '') {
            return $vacio;
        }

        return [
            'basic'      => $basic,
            'account_id' => is_null($connector->platform_user_id) ? null : (string) $connector->platform_user_id,
            'config'     => self::config_desde_conector($connector),
        ];
    }

    /**
     * Configuración del conector con los defaults aplicados.
     *
     * @param int $user_id
     * @return array
     */
    public static function config($user_id)
    {
        $connector = self::connector($user_id);

        if (!$connector) {
            return self::config_defaults();
        }

        return self::config_desde_conector($connector);
    }

    /**
     * `extra_config` del conector mezclado con los defaults (clave por clave; `bulto_default`
     * se completa campo por campo).
     *
     * @param PlatformConnector $connector
     * @return array
     */
    public static function config_desde_conector(PlatformConnector $connector)
    {
        $config = self::config_defaults();

        $extra = null;
        try {
            $extra = $connector->extra_config;
        } catch (\Throwable $e) {
            $extra = null;
        }
        if (!is_array($extra)) {
            return $config;
        }

        foreach ($config as $clave => $default) {
            if (!array_key_exists($clave, $extra) || is_null($extra[$clave])) {
                continue;
            }
            $config[$clave] = $extra[$clave];
        }

        $bulto = is_array($config['bulto_default']) ? $config['bulto_default'] : [];
        $config['bulto_default'] = ZipnovaPaquetesHelper::bulto_default_completo($bulto);
        $config['declarar_valor'] = (bool) $config['declarar_valor'];
        $config['envio_gratis_desde'] = is_numeric($config['envio_gratis_desde']) && (float) $config['envio_gratis_desde'] > 0
            ? (float) $config['envio_gratis_desde']
            : null;

        return $config;
    }

    /**
     * Cliente de Zipnova listo para usar, o null si el comercio no está conectado.
     *
     * @param int $user_id
     * @return ZipnovaClient|null
     */
    public static function client($user_id)
    {
        $credentials = self::credentials($user_id);

        if (is_null($credentials['basic'])) {
            return null;
        }

        return new ZipnovaClient($credentials['basic'], $credentials['account_id']);
    }
}
