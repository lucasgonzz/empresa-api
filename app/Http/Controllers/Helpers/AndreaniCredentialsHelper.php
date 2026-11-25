<?php

namespace App\Http\Controllers\Helpers;

use App\Models\Platform;
use App\Models\PlatformConnector;
use App\Services\Zipnova\ZipnovaPaquetesHelper;
use Illuminate\Support\Facades\Log;

/**
 * Resuelve el número de cliente, los contratos y las preferencias de Andreani de un comercio
 * (misión envios-andreani, 25/11/2026).
 *
 * Una sola fuente: el conector `andreani` de `platform_connectors`, conectado
 * (`PlatformConnector::is_connected()`):
 *  - `platform_user_id` = número de cliente (ej. `CL0003750`).
 *  - `access_token`     = JSON `{"usuario":null,"password":null}` cifrado por el cast del modelo. En la etapa 1 no
 *                         hay usuario ni contraseña (la cotización es pública); queda no vacío para que
 *                         `is_connected()` funcione sin tocar el modelo. La etapa 2 llena ahí usuario y contraseña.
 *  - `extra_config`     = contratos, sucursal de origen, bulto por defecto, declarar valor, envío gratis desde y
 *                         «ofrecer en la tienda».
 *
 * Leer `access_token` descifra con la APP_KEY y puede tirar `DecryptException` (cliente con clave distinta, fila
 * en plano). Se atrapa acá y se devuelve "no conectado" con un log, para que un checkout público nunca reviente
 * con 500 por esto. Este helper nunca lanza.
 *
 * 🔴 ARCHIVO ESPEJO ENTRE `empresa-api` Y `tienda-api` (solo cambia el `use` de los modelos). Se compara con `diff`.
 */
class AndreaniCredentialsHelper
{
    /**
     * Valores por defecto de la configuración del conector. Cualquier clave que falte en `extra_config` toma el
     * default; así una versión vieja del ERP que guardó menos claves no rompe a la tienda.
     *
     * @return array
     */
    public static function config_defaults()
    {
        return [
            'contrato_domicilio' => null,
            'contrato_sucursal'  => null,
            'sucursal_origen'    => null,
            'bulto_default'      => ZipnovaPaquetesHelper::BULTO_FALLBACK,
            'declarar_valor'     => true,
            'envio_gratis_desde' => null,
            'ofrecer_en_tienda'  => true,
            'conectado_en'       => null,
        ];
    }

    /**
     * Conector de Andreani del comercio, solo si está conectado.
     *
     * @param int $user_id Comercio (owner).
     * @return PlatformConnector|null
     */
    public static function connector($user_id)
    {
        $connector = PlatformConnector::find_for_user_and_slug((int) $user_id, Platform::SLUG_ANDREANI);

        if (!$connector || !$connector->is_connected()) {
            return null;
        }

        return $connector;
    }

    /**
     * Datos vigentes del comercio. Nunca lanza: si no hay conector, el token no se puede descifrar o falta el
     * contrato de domicilio, `cliente` viene en null ("no conectado").
     *
     * @param int $user_id
     * @return array{cliente: string|null, config: array, api: array|null}
     */
    public static function credentials($user_id)
    {
        $vacio = ['cliente' => null, 'config' => self::config_defaults(), 'api' => null];

        $connector = self::connector($user_id);
        if (!$connector) {
            return $vacio;
        }

        try {
            $token = (string) $connector->access_token;
        } catch (\Throwable $e) {
            Log::warning('AndreaniCredentialsHelper: no se pudo descifrar el access_token del conector ' . $connector->id . ' (user_id ' . $user_id . '): ' . $e->getMessage());

            return $vacio;
        }

        if ($token === '') {
            return $vacio;
        }

        $cliente = is_null($connector->platform_user_id) ? '' : trim((string) $connector->platform_user_id);
        $config = self::config_desde_conector($connector);

        // Sin número de cliente o sin contrato de domicilio no hay con qué cotizar.
        if ($cliente === '' || is_null($config['contrato_domicilio'])) {
            return $vacio;
        }

        return [
            'cliente' => $cliente,
            'config'  => $config,
            'api'     => self::api_desde_token($token),
        ];
    }

    /**
     * True si el comercio tiene Andreani conectado y utilizable.
     *
     * @param int $user_id
     * @return bool
     */
    public static function esta_conectado($user_id)
    {
        $credentials = self::credentials($user_id);

        return !is_null($credentials['cliente']);
    }

    /**
     * True si el comercio tiene Andreani conectado Y quiere ofrecerlo en la tienda («Ofrecer Andreani en la
     * tienda» sin destildar).
     *
     * @param int $user_id
     * @return bool
     */
    public static function se_ofrece_en_la_tienda($user_id)
    {
        $credentials = self::credentials($user_id);

        return !is_null($credentials['cliente']) && (bool) $credentials['config']['ofrecer_en_tienda'];
    }

    /**
     * `extra_config` del conector mezclado con los defaults (clave por clave; `bulto_default` se completa campo
     * por campo).
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
        $config['ofrecer_en_tienda'] = (bool) $config['ofrecer_en_tienda'];
        $config['contrato_domicilio'] = self::texto_o_null($config['contrato_domicilio']);
        $config['contrato_sucursal'] = self::texto_o_null($config['contrato_sucursal']);
        $config['sucursal_origen'] = is_numeric($config['sucursal_origen']) ? (int) $config['sucursal_origen'] : null;
        $config['envio_gratis_desde'] = is_numeric($config['envio_gratis_desde']) && (float) $config['envio_gratis_desde'] > 0
            ? (float) $config['envio_gratis_desde']
            : null;

        return $config;
    }

    /**
     * Usuario y contraseña de la API de Andreani guardados en el token (etapa 2). Null si no hay (etapa 1) o el
     * token no es el JSON esperado.
     *
     * @param string $token Contenido ya descifrado de `access_token`.
     * @return array{usuario: string, password: string}|null
     */
    protected static function api_desde_token($token)
    {
        $json = json_decode($token, true);

        if (!is_array($json) || empty($json['usuario']) || empty($json['password'])) {
            return null;
        }

        return ['usuario' => (string) $json['usuario'], 'password' => (string) $json['password']];
    }

    /**
     * Texto recortado o null si queda vacío.
     *
     * @param mixed $valor
     * @return string|null
     */
    protected static function texto_o_null($valor)
    {
        if (is_null($valor)) {
            return null;
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }
}
