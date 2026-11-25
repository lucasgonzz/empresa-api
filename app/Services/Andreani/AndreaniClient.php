<?php

namespace App\Services\Andreani;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente HTTP de la API pública de Andreani (misión envios-andreani, 25/11/2026).
 *
 * En esta etapa solo se usan dos consultas, ambas SIN usuario ni contraseña (medido el 25/11/2026):
 *
 *   GET /v1/tarifas?cpDestino=&contrato=&cliente=&bultos[i][valorDeclarado|volumen|kilos]=  → cotizar
 *   GET /v2/sucursales?codigoPostal=                                                         → sucursales de retiro
 *
 * La tarifa se identifica con el número de cliente y el contrato del comercio. El cotizador NO rechaza por
 * tamaño (5 m3 y 80 kg dan 200), así que los topes de paquetería los aplica `AndreaniTarifaNormalizer`.
 *
 * Un solo intento y timeout corto: la cotización de la tienda es pública y Zipnova cotiza al lado; si Andreani
 * tarda no puede frenar al comprador. Los corchetes del query van LITERALES (`bultos[0][kilos]`), tal como los
 * documenta Andreani; `http_build_query` los escapa y se los deshace.
 *
 * 🔴 ARCHIVO ESPEJO ENTRE `empresa-api` Y `tienda-api` (solo cambia el `namespace`/`use`). Se compara con `diff`.
 * 🔴 No se loguea nada del comercio (cliente ni contrato): solo método, ruta y código HTTP.
 */
class AndreaniClient
{
    /** Host por defecto de la API pública. */
    const BASE_URL_DEFAULT = 'https://apis.andreani.com';

    /** Segundos máximos de espera por respuesta si la config no dice otra cosa. */
    const TIMEOUT_DEFAULT = 15;

    /**
     * Host base de la API, configurable por `.env` (`ANDREANI_BASE_URL`) sin tocar código.
     *
     * @return string
     */
    public static function base_url()
    {
        $base_url = (string) config('services.andreani.base_url', self::BASE_URL_DEFAULT);

        if ($base_url === '') {
            $base_url = self::BASE_URL_DEFAULT;
        }

        return rtrim($base_url, '/');
    }

    /**
     * Cotiza un envío: la tarifa con y sin IVA de uno o varios bultos para un código postal.
     *
     * @param string $cliente Número de cliente de Andreani (ej. `CL0003750`).
     * @param string $contrato Contrato (domicilio o sucursal según lo que se cotice).
     * @param string $cp_destino Código postal de destino (si viene como CPA se usan sus 4 dígitos).
     * @param array $bultos Lista de `{valorDeclarado, volumen (cm3), kilos}`, ya con las claves de Andreani.
     * @param int|string|null $sucursal_origen Sucursal desde la que despacha el comercio, si la cargó.
     * @return array Respuesta cruda: `{pesoAforado, tarifaSinIva{...}, tarifaConIva{..., total}}`.
     * @throws AndreaniException Ante cualquier respuesta que no sea 2xx o si no hubo respuesta.
     */
    public function tarifa($cliente, $contrato, $cp_destino, array $bultos, $sucursal_origen = null)
    {
        $consulta = [
            // Andreani sólo entiende los 4 dígitos del CP: con un CPA ("X5000ABC") responde 400.
            'cpDestino' => AndreaniTarifaNormalizer::codigo_postal_numerico($cp_destino),
            'contrato'  => (string) $contrato,
            'cliente'   => (string) $cliente,
        ];

        if (!is_null($sucursal_origen) && $sucursal_origen !== '') {
            $consulta['sucursalOrigen'] = (string) $sucursal_origen;
        }

        // `array_values`: Andreani espera los índices 0..n-1 sin saltos.
        $consulta['bultos'] = array_values($bultos);

        $respuesta = $this->pedir('/v1/tarifas', $consulta);

        // Un 200 sin `tarifaConIva.total` no es una tarifa: es una respuesta rota (proxy, mantenimiento). Se
        // trata como "Andreani no respondió bien" (status 0, no es de validación) y nunca como "sin precio".
        if (!isset($respuesta['tarifaConIva']['total']) || !is_numeric($respuesta['tarifaConIva']['total'])) {
            throw new AndreaniException('Andreani respondió sin una tarifa. Probá de nuevo en un rato.', 0, $respuesta);
        }

        return $respuesta;
    }

    /**
     * Sucursales de Andreani de un código postal (para ofrecer el retiro).
     *
     * @param string $cp Código postal.
     * @return array Lista cruda de sucursales (vacía si Andreani no devolvió una lista).
     * @throws AndreaniException Ante cualquier respuesta que no sea 2xx o si no hubo respuesta.
     */
    public function sucursales($cp)
    {
        $respuesta = $this->pedir('/v2/sucursales', ['codigoPostal' => AndreaniTarifaNormalizer::codigo_postal_numerico($cp)]);

        return array_values($respuesta);
    }

    /**
     * Hace el GET y devuelve el JSON decodificado; lanza `AndreaniException` si no es 2xx o no hubo respuesta.
     *
     * @param string $path Ruta relativa al host.
     * @param array $consulta Parámetros del query (los anidados salen como `a[0][b]`).
     * @return array
     */
    protected function pedir($path, array $consulta)
    {
        // Corchetes literales: `http_build_query` los codifica como %5B/%5D y la doc de Andreani los muestra sin escapar.
        $query = str_replace(['%5B', '%5D'], ['[', ']'], http_build_query($consulta));

        $url = self::base_url() . $path . '?' . $query;

        $timeout = (int) config('services.andreani.timeout', self::TIMEOUT_DEFAULT);
        if ($timeout <= 0) {
            $timeout = self::TIMEOUT_DEFAULT;
        }

        try {
            $response = Http::withOptions(self::http_client_options())
                ->withHeaders(['Accept' => 'application/json'])
                ->timeout($timeout)
                ->get($url);
        } catch (\Throwable $e) {
            // 🔴 Solo la ruta (sin query) y el tipo de error: el mensaje de Guzzle trae la URL completa, con el número
            // de cliente y el contrato del comercio, y no se loguea.
            Log::warning('AndreaniClient: sin respuesta de Andreani en GET ' . $path . ' (' . get_class($e) . ')');

            throw new AndreaniException('No pudimos comunicarnos con Andreani. Probá de nuevo en un rato.', 0, [], $e);
        }

        $json = $response->json();
        $cuerpo = is_array($json) ? $json : [];

        if (!$response->successful()) {
            // Solo código y un pedacito del cuerpo: el query lleva el número de cliente y no se loguea.
            Log::warning('AndreaniClient: GET ' . $path . ' respondió ' . $response->status() . ': ' . substr((string) $response->body(), 0, 200));

            throw new AndreaniException(self::mensaje_de_error($response->status()), $response->status(), $cuerpo);
        }

        return $cuerpo;
    }

    /**
     * Opciones Guzzle: verificación SSL por config (`ANDREANI_GUZZLE_VERIFY_SSL`) y bundle propio opcional.
     *
     * @return array
     */
    public static function http_client_options()
    {
        $ca_bundle = (string) config('services.andreani.guzzle_ca_bundle', '');

        if ($ca_bundle !== '') {
            return ['verify' => $ca_bundle];
        }

        return ['verify' => (bool) config('services.andreani.guzzle_verify', true)];
    }

    /**
     * Mensaje legible, en castellano, para un código de error de Andreani.
     *
     * @param int $status
     * @return string
     */
    protected static function mensaje_de_error($status)
    {
        if (in_array((int) $status, [400, 404, 422], true)) {
            return 'Andreani no pudo calcular la tarifa con esos datos.';
        }

        if ((int) $status === 429) {
            return 'Andreani recibió demasiadas consultas. Probá de nuevo en un rato.';
        }

        return 'Andreani no respondió como se esperaba (código ' . (int) $status . '). Probá de nuevo en un rato.';
    }
}
