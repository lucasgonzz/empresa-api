<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\CatalogoDeDatosIaHelper;
use App\Http\Controllers\Helpers\contabilidad\EstadoResultadosHelper;
use App\Http\Controllers\Helpers\contabilidad\FlujoCajaHelper;
use App\Http\Controllers\Helpers\contabilidad\PosicionFiscalHelper;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * LOS TRES REPORTES CONTABLES DEL SISTEMA, COMO UNA TOOL DEL ASISTENTE.
 *
 * Misión asistente-omnisciente (21/9/2026). El Estado de Resultados (devengado), el Flujo de Caja
 * (percibido) y la Posición Fiscal (IVA, IIBB, Ganancias) ya existen como reportes de pantalla, con
 * su criterio cerrado y probado (EstadoResultadosHelper, FlujoCajaHelper, PosicionFiscalHelper,
 * todos cron-safe: reciben `$user_id`, nunca leen Auth). Esto los llama tal cual y PODA la salida
 * a lo que un tool_result aguanta.
 *
 * QUÉ SE PODA Y POR QUÉ. Medido el 21/9/2026 sobre el fixture de testing (365 días):
 *
 *   - estado_resultados: 550 bytes. Se deja entero; `gastos_por_categoria` se topea en TOPE_LINEAS
 *     por si un comercio tiene cien conceptos de gasto.
 *   - flujo_caja: 1.343 bytes en el fixture, pero `ingresos_por_caja_metodo` /
 *     `egresos_por_caja_metodo` traen SOLO ids (`current_acount_payment_method_id`, `caja_id`) y
 *     crecen con cajas × métodos: acá se les resuelve el nombre (una consulta por tabla) y se
 *     topean. `plata_en_transito` trae tres listas con la misma información (liquidaciones por
 *     fecha, cheques por fecha y el calendario que combina las dos): viaja solo `total_estimado` y
 *     el `calendario` topeado, que ya dice el origen de cada renglón.
 *   - posicion_fiscal: 419 bytes. Entero.
 *
 * Lo que se poda se dice en `podado`, para que el modelo sepa que existe más detalle en la pantalla
 * y no afirme que "no hay cheques" porque no le llegó la lista.
 */
class ReporteContableIaHelper
{
    /** Reportes válidos. */
    const REPORTES = ['estado_resultados', 'flujo_caja', 'posicion_fiscal'];

    /** Monedas válidas (las de los helpers contables). */
    const MONEDAS = ['pesos', 'dolares', 'consolidado'];

    /** Techo del rango en días. */
    const TOPE_DIAS = 400;

    /** Tope de renglones de cualquier desglose. */
    const TOPE_LINEAS = 30;

    /**
     * EL REPORTE.
     *
     * @param  int     $owner_id  Dueño. Nunca Auth.
     * @param  string  $reporte   estado_resultados | flujo_caja | posicion_fiscal.
     * @param  string  $desde     AAAA-MM-DD (inclusive).
     * @param  string  $hasta     AAAA-MM-DD (inclusive).
     * @param  string  $moneda    pesos (default) | dolares | consolidado. La posición fiscal no tiene moneda.
     * @return array<string, mixed>  Con la clave `error` cuando el pedido no se puede atender
     */
    public static function reporte(int $owner_id, $reporte, $desde, $hasta, $moneda = 'pesos'): array
    {
        $reporte = strtolower(trim((string) $reporte));

        if (! in_array($reporte, self::REPORTES, true)) {
            return ['error' => 'El reporte "' . $reporte . '" no existe. Las opciones son: ' . implode(', ', self::REPORTES) . '.'];
        }

        $dia_desde = CatalogoDeDatosIaHelper::dia_de($desde);
        $dia_hasta = CatalogoDeDatosIaHelper::dia_de($hasta);

        if (is_null($dia_desde) || is_null($dia_hasta)) {
            return ['error' => 'Necesito `desde` y `hasta` en formato AAAA-MM-DD (los dos, inclusive).'];
        }

        if ($dia_desde > $dia_hasta) {
            return ['error' => '`desde` (' . $dia_desde . ') es posterior a `hasta` (' . $dia_hasta . ').'];
        }

        if (Carbon::parse($dia_desde)->diffInDays(Carbon::parse($dia_hasta)) + 1 > self::TOPE_DIAS) {
            return ['error' => 'El rango pedido tiene mas de ' . self::TOPE_DIAS . ' dias. Acotalo o pedilo en dos partes.'];
        }

        $moneda = strtolower(trim((string) $moneda));

        if ($moneda === '') {
            $moneda = 'pesos';
        }

        if (! in_array($moneda, self::MONEDAS, true)) {
            return ['error' => 'La moneda "' . $moneda . '" no existe: va pesos, dolares o consolidado.'];
        }

        if ($reporte === 'estado_resultados') {
            return self::estado_resultados($owner_id, $dia_desde, $dia_hasta, $moneda);
        }

        if ($reporte === 'flujo_caja') {
            return self::flujo_caja($owner_id, $dia_desde, $dia_hasta, $moneda);
        }

        return self::posicion_fiscal($owner_id, $dia_desde, $dia_hasta);
    }

    /**
     * Estado de Resultados devengado. Se deja entero salvo el tope del desglose de gastos.
     *
     * @param  int     $owner_id
     * @param  string  $desde
     * @param  string  $hasta
     * @param  string  $moneda
     * @return array<string, mixed>
     */
    protected static function estado_resultados(int $owner_id, string $desde, string $hasta, string $moneda): array
    {
        $datos = EstadoResultadosHelper::estado_resultados($owner_id, $desde, $hasta, $moneda);

        $podado = [];

        if (isset($datos['gastos_por_categoria']) && is_array($datos['gastos_por_categoria'])) {
            $datos['gastos_por_categoria'] = self::topear($datos['gastos_por_categoria'], 'gastos_por_categoria', $podado);
        }

        return [
            'reporte'   => 'estado_resultados',
            'que_es'    => 'Devengado: lo vendido en el periodo se haya cobrado o no, neto de IVA declarado, menos costo de mercaderia, gastos, comisiones de cobro e IIBB. resultado_bruto y resultado_neto son montos, no porcentajes. Si moneda no coincide con la pedida es porque el negocio no tiene la extension de ventas en dolares.',
            'datos'     => $datos,
            'podado'    => $podado,
        ];
    }

    /**
     * Flujo de Caja percibido: se resuelven los nombres de caja y de método de pago en los
     * desgloses y se recorta la plata en tránsito al calendario.
     *
     * @param  int     $owner_id
     * @param  string  $desde
     * @param  string  $hasta
     * @param  string  $moneda
     * @return array<string, mixed>
     */
    protected static function flujo_caja(int $owner_id, string $desde, string $hasta, string $moneda): array
    {
        $datos = FlujoCajaHelper::flujo_caja($owner_id, $desde, $hasta, $moneda);

        $podado = [];

        foreach (['ingresos_por_caja_metodo', 'egresos_por_caja_metodo'] as $clave) {
            if (isset($datos[$clave]) && is_array($datos[$clave])) {
                $datos[$clave] = self::topear(self::con_nombres_de_caja_y_metodo($datos[$clave]), $clave, $podado);
            }
        }

        if (isset($datos['gastos_pagados_por_categoria']) && is_array($datos['gastos_pagados_por_categoria'])) {
            $datos['gastos_pagados_por_categoria'] = self::topear($datos['gastos_pagados_por_categoria'], 'gastos_pagados_por_categoria', $podado);
        }

        if (isset($datos['plata_en_transito']) && is_array($datos['plata_en_transito'])) {
            $transito = $datos['plata_en_transito'];

            $datos['plata_en_transito'] = [
                'total_estimado' => isset($transito['total_estimado']) ? $transito['total_estimado'] : 0,
                'calendario'     => self::topear(isset($transito['calendario']) ? $transito['calendario'] : [], 'plata_en_transito.calendario', $podado),
            ];

            $podado[] = 'plata_en_transito.liquidaciones_pendientes y .cheques_diferidos: son las mismas filas que calendario, separadas por origen';
        }

        return [
            'reporte'  => 'flujo_caja',
            'que_es'   => 'Percibido: la plata que efectivamente entro y salio en el periodo (cobros, pagos a proveedores, gastos pagados), con su desglose por caja y metodo de pago. plata_en_transito son liquidaciones pendientes y cheques diferidos: NO suma al flujo neto. Si moneda no coincide con la pedida es porque el negocio no tiene la extension de ventas en dolares.',
            'datos'    => $datos,
            'podado'   => $podado,
        ];
    }

    /**
     * Posición fiscal: IVA, IIBB y pagos a cuenta de Ganancias, con los renglones separados como
     * los pide la DDJJ. Sin moneda (los impuestos se liquidan en pesos).
     *
     * @param  int     $owner_id
     * @param  string  $desde
     * @param  string  $hasta
     * @return array<string, mixed>
     */
    protected static function posicion_fiscal(int $owner_id, string $desde, string $hasta): array
    {
        return [
            'reporte' => 'posicion_fiscal',
            'que_es'  => 'Impuestos del periodo, en pesos y con los renglones separados: IVA (debito menos credito, percepciones y retenciones sufridas), IIBB (determinado menos percepciones y retenciones) y pagos a cuenta de Ganancias. En cada saldo, tipo dice si es a_pagar o a_favor. iibb_configurado en false quiere decir que el negocio no cargo la alicuota, no que no deba.',
            'datos'   => [
                'desde'                    => $desde,
                'hasta'                    => $hasta,
                'posicion_iva'             => PosicionFiscalHelper::posicion_iva($owner_id, $desde, $hasta),
                'posicion_iibb'            => PosicionFiscalHelper::posicion_iibb($owner_id, $desde, $hasta),
                'pagos_a_cuenta_ganancias' => PosicionFiscalHelper::pagos_a_cuenta_ganancias($owner_id, $desde, $hasta),
            ],
            'podado'  => [],
        ];
    }

    /**
     * A un desglose por caja y método (que trae solo ids) le pone `caja` y `metodo` con el nombre,
     * en una consulta por tabla.
     *
     * @param  array  $lineas
     * @return array<int, array<string, mixed>>
     */
    protected static function con_nombres_de_caja_y_metodo(array $lineas): array
    {
        $ids_caja = [];
        $ids_metodo = [];

        foreach ($lineas as $linea) {
            if (! empty($linea['caja_id'])) {
                $ids_caja[] = (int) $linea['caja_id'];
            }

            if (! empty($linea['current_acount_payment_method_id'])) {
                $ids_metodo[] = (int) $linea['current_acount_payment_method_id'];
            }
        }

        $cajas = empty($ids_caja) ? [] : DB::table('cajas')->whereIn('id', array_unique($ids_caja))->pluck('name', 'id')->all();
        $metodos = empty($ids_metodo) ? [] : DB::table('current_acount_payment_methods')->whereIn('id', array_unique($ids_metodo))->pluck('name', 'id')->all();

        $resultado = [];

        foreach ($lineas as $linea) {
            $caja_id = empty($linea['caja_id']) ? 0 : (int) $linea['caja_id'];
            $metodo_id = empty($linea['current_acount_payment_method_id']) ? 0 : (int) $linea['current_acount_payment_method_id'];

            $resultado[] = [
                'metodo' => $metodo_id > 0 && isset($metodos[$metodo_id]) ? (string) $metodos[$metodo_id] : 'Sin metodo',
                'caja'   => $caja_id > 0 && isset($cajas[$caja_id]) ? (string) $cajas[$caja_id] : 'Sin caja',
                'total'  => isset($linea['total']) ? $linea['total'] : null,
            ];
        }

        return $resultado;
    }

    /**
     * Recorta una lista a TOPE_LINEAS y lo anota en `podado`.
     *
     * @param  array   $lineas
     * @param  string  $nombre
     * @param  array   $podado  Se llena por referencia.
     * @return array
     */
    protected static function topear(array $lineas, string $nombre, array &$podado): array
    {
        if (count($lineas) <= self::TOPE_LINEAS) {
            return $lineas;
        }

        $podado[] = $nombre . ': ' . count($lineas) . ' renglones, viajan los primeros ' . self::TOPE_LINEAS;

        return array_slice($lineas, 0, self::TOPE_LINEAS);
    }
}
