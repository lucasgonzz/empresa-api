<?php

namespace App\Http\Controllers\Helpers\contabilidad;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Cheque;
use App\Models\MovimientoCaja;
use App\Models\User;
use Carbon\Carbon;

/**
 * Flujo de Caja PERCIBIDO (Grupo 226, Prompt 01).
 *
 * A diferencia del Estado de Resultados (Grupo 225, devengado: lo vendido, se haya cobrado o
 * no), acá se reporta la plata que efectivamente se movió: cobros/pagos reales, con su desglose
 * por caja y por método de pago (la pregunta operativa real: "¿cuánto entró por MP?"), más una
 * sección aparte de "plata en tránsito" (liquidaciones pendientes + cheques diferidos en cartera)
 * que NUNCA suma al flujo neto del período — meterla adentro sería el mismo error conceptual que
 * este reporte viene a corregir (mezclar devengado y percibido).
 *
 * 🔴 Cero queries propias sobre `sales`/`current_acounts`/`expenses`: todo lo que es "por
 * moneda" (ingresos y egresos) consume EXCLUSIVAMENTE `ContabilidadRepository`, mismo contrato
 * que `EstadoResultadosHelper`/`PosicionFiscalHelper` (grupo 225). La "plata en tránsito" SÍ
 * arma queries propias acá (sobre `movimiento_cajas`/`cajas` y `cheque`) porque
 * `ContabilidadRepository` está pensado para movimientos YA percibidos/devengados, no para lo
 * que todavía está en camino — agregarle esa responsabilidad ahí mezclaría dos conceptos.
 *
 * 🔴 Cero `auth()` / `Auth::` / `request()`: mismo motivo que el resto de los helpers de este
 * módulo (cron-safe). Todos los métodos reciben `$user_id` explícito.
 *
 * 🔴 Regla dura del prompt (tarea 06, el error más fácil de cometer acá): la comisión de cobro
 * que crea el grupo 223 YA aparece dentro de "gastos pagados" (vía
 * `ContabilidadRepository::gastos_pagados_por_categoria()`/`egresos_por_caja_metodo()`, que no
 * la excluyen a propósito). Nunca se resta de nuevo del lado de ingresos: el ingreso entra por
 * su monto BRUTO.
 */
class FlujoCajaHelper
{
    /**
     * Punto de entrada único del reporte: arma el flujo de caja del período para el usuario y la
     * dimensión de moneda pedidos, y le agrega la sección de "plata en tránsito" (que no depende
     * de la combinación pesos/dólares del flujo neto, se calcula aparte).
     *
     * @param  int $user_id Owner dueño del reporte (nunca el usuario de sesión).
     * @param  string $desde Fecha de inicio del período (Y-m-d), inclusive.
     * @param  string $hasta Fecha de fin del período (Y-m-d), inclusive.
     * @param  string $moneda 'pesos' | 'dolares' | 'consolidado'. Si la extensión de ventas en
     *                         dólares no está activa para el usuario, se ignora (siempre pesos),
     *                         igual que `EstadoResultadosHelper::estado_resultados()`.
     * @param  array $filtros_extra Filtros opcionales: `address_id`, `caja_id`.
     * @return array Estructura completa del reporte (ingresos, egresos, flujo neto y plata en
     *               tránsito).
     */
    public static function flujo_caja($user_id, $desde, $hasta, $moneda = 'pesos', $filtros_extra = [])
    {
        $extension_dolares_activa = self::extension_dolares_activa($user_id);

        // Sin la extensión activa, el parámetro moneda se ignora (mismo criterio que el grupo 225).
        $moneda_efectiva = $extension_dolares_activa ? $moneda : 'pesos';

        if ($moneda_efectiva === 'dolares') {
            $flujo = self::armar_flujo_dolares($user_id, $desde, $hasta, $filtros_extra);
        } elseif ($moneda_efectiva === 'consolidado') {
            $flujo = self::armar_flujo_consolidado($user_id, $desde, $hasta, $filtros_extra);
        } else {
            $flujo = self::armar_flujo_pesos($user_id, $desde, $hasta, $filtros_extra);
        }

        // Plata en tránsito: sección aparte, fuera del flujo neto del período (regla dura del
        // prompt). No se combina con `combinar_lineas()`; se calcula una sola vez acá.
        $flujo['plata_en_transito'] = self::plata_en_transito($user_id, $moneda_efectiva, $filtros_extra);

        return $flujo;
    }

    /**
     * Resuelve si el usuario (owner) tiene activa la extensión `ventas_en_dolares`, sin depender
     * de sesión (mismo criterio que `EstadoResultadosHelper::extension_dolares_activa()`).
     *
     * @param  int $user_id
     * @return bool
     */
    private static function extension_dolares_activa($user_id)
    {
        $user = User::find($user_id);

        if (is_null($user)) {
            return false;
        }

        return UserHelper::hasExtencion('ventas_en_dolares', $user);
    }

    /**
     * Cotización vigente del sistema para este usuario (`users.dollar`), usada para el
     * consolidado (mismo campo que consume `EstadoResultadosHelper::cotizacion_vigente()`).
     *
     * @param  int $user_id
     * @return float
     */
    private static function cotizacion_vigente($user_id)
    {
        $user = User::find($user_id);

        return $user ? (float) $user->dollar : 0.0;
    }

    /**
     * Calcula ingresos y egresos POR MONEDA para un `moneda_id` puntual, con su desglose por
     * caja y método de pago.
     *
     * @param  int $user_id
     * @param  string $desde
     * @param  string $hasta
     * @param  int $moneda_id 1 = pesos, 2 = dólares.
     * @param  array $filtros_extra `address_id`, `caja_id`.
     * @return array{cobros_mostrador: float, cobranzas_cuenta_corriente: float,
     *               total_ingresos: float, ingresos_por_caja_metodo: array,
     *               pagos_a_proveedores: float, gastos_pagados: float,
     *               gastos_pagados_por_categoria: array, total_egresos: float,
     *               egresos_por_caja_metodo: array, flujo_neto: float}
     */
    private static function calcular_lineas_por_moneda($user_id, $desde, $hasta, $moneda_id, $filtros_extra)
    {
        $filtros = array_merge($filtros_extra, ['moneda_id' => $moneda_id]);

        // Ingresos: cobros en mostrador + cobranzas de cuenta corriente, con su desglose.
        $cobros_mostrador = ContabilidadRepository::cobranzas_mostrador($user_id, $desde, $hasta, $filtros);
        $cobranzas_cuenta_corriente = ContabilidadRepository::cobranzas_cuenta_corriente($user_id, $desde, $hasta, $filtros);
        $total_ingresos = $cobros_mostrador + $cobranzas_cuenta_corriente;

        $ingresos_por_caja_metodo = ContabilidadRepository::ingresos_por_caja_metodo($user_id, $desde, $hasta, $filtros);

        // Egresos: pagos a proveedores + gastos pagados (INCLUYE la comisión de cobro, tarea 06:
        // no se resta dos veces, entra una sola vez acá).
        $pagos_a_proveedores = ContabilidadRepository::pagos_a_proveedores($user_id, $desde, $hasta, $filtros);
        $gastos_pagados_por_categoria = ContabilidadRepository::gastos_pagados_por_categoria($user_id, $desde, $hasta, $filtros);

        $gastos_pagados = 0.0;
        foreach ($gastos_pagados_por_categoria as $gasto) {
            $gastos_pagados += (float) $gasto['total'];
        }

        $total_egresos = $pagos_a_proveedores + $gastos_pagados;

        $egresos_por_caja_metodo = ContabilidadRepository::egresos_por_caja_metodo($user_id, $desde, $hasta, $filtros);

        return [
            'cobros_mostrador'             => $cobros_mostrador,
            'cobranzas_cuenta_corriente'   => $cobranzas_cuenta_corriente,
            'total_ingresos'               => $total_ingresos,
            'ingresos_por_caja_metodo'     => $ingresos_por_caja_metodo,
            'pagos_a_proveedores'          => $pagos_a_proveedores,
            'gastos_pagados'               => $gastos_pagados,
            'gastos_pagados_por_categoria' => $gastos_pagados_por_categoria,
            'total_egresos'                => $total_egresos,
            'egresos_por_caja_metodo'      => $egresos_por_caja_metodo,
            'flujo_neto'                   => $total_ingresos - $total_egresos,
        ];
    }

    /**
     * Combina dos juegos de líneas POR MONEDA (pesos + dólares×cotización), igual criterio que
     * `EstadoResultadosHelper::combinar_lineas()`.
     *
     * @param  array $lineas_pesos
     * @param  array $lineas_dolares
     * @param  float $cotizacion
     * @return array Misma forma que `calcular_lineas_por_moneda()`, ya sumado.
     */
    private static function combinar_lineas($lineas_pesos, $lineas_dolares, $cotizacion)
    {
        $campos_numericos = [
            'cobros_mostrador', 'cobranzas_cuenta_corriente', 'total_ingresos',
            'pagos_a_proveedores', 'gastos_pagados', 'total_egresos', 'flujo_neto',
        ];

        $combinado = [];

        foreach ($campos_numericos as $campo) {
            $combinado[$campo] = $lineas_pesos[$campo] + ($lineas_dolares[$campo] * $cotizacion);
        }

        // Desgloses por categoría/caja-método: se combinan por clave, cotizando la parte en dólares.
        $combinado['gastos_pagados_por_categoria'] = self::combinar_por_concepto(
            $lineas_pesos['gastos_pagados_por_categoria'],
            $lineas_dolares['gastos_pagados_por_categoria'],
            $cotizacion
        );

        $combinado['ingresos_por_caja_metodo'] = self::combinar_por_caja_metodo(
            $lineas_pesos['ingresos_por_caja_metodo'],
            $lineas_dolares['ingresos_por_caja_metodo'],
            $cotizacion
        );

        $combinado['egresos_por_caja_metodo'] = self::combinar_por_caja_metodo(
            $lineas_pesos['egresos_por_caja_metodo'],
            $lineas_dolares['egresos_por_caja_metodo'],
            $cotizacion
        );

        return $combinado;
    }

    /**
     * Combina dos desgloses por `expense_concept_id`, cotizando la parte en dólares (mismo
     * criterio que `EstadoResultadosHelper::combinar_lineas()` para `gastos_por_categoria`).
     *
     * @param  array $pesos
     * @param  array $dolares
     * @param  float $cotizacion
     * @return array<int, array{expense_concept_id: int|null, concepto: string, total: float}>
     */
    private static function combinar_por_concepto($pesos, $dolares, $cotizacion)
    {
        $indexado = [];

        foreach ($pesos as $gasto) {
            $indexado[$gasto['expense_concept_id']] = $gasto;
        }

        foreach ($dolares as $gasto) {
            $key = $gasto['expense_concept_id'];

            if (isset($indexado[$key])) {
                $indexado[$key]['total'] += $gasto['total'] * $cotizacion;
            } else {
                $indexado[$key] = [
                    'expense_concept_id' => $gasto['expense_concept_id'],
                    'concepto'           => $gasto['concepto'],
                    'total'              => $gasto['total'] * $cotizacion,
                ];
            }
        }

        return array_values($indexado);
    }

    /**
     * Combina dos desgloses por caja+método de pago, cotizando la parte en dólares.
     *
     * @param  array $pesos
     * @param  array $dolares
     * @param  float $cotizacion
     * @return array<int, array{current_acount_payment_method_id: int, caja_id: int|null, total: float}>
     */
    private static function combinar_por_caja_metodo($pesos, $dolares, $cotizacion)
    {
        $indexado = [];

        foreach ($pesos as $fila) {
            $key = $fila['current_acount_payment_method_id'].'-'.$fila['caja_id'];
            $indexado[$key] = $fila;
        }

        foreach ($dolares as $fila) {
            $key = $fila['current_acount_payment_method_id'].'-'.$fila['caja_id'];

            if (isset($indexado[$key])) {
                $indexado[$key]['total'] += $fila['total'] * $cotizacion;
            } else {
                $indexado[$key] = [
                    'current_acount_payment_method_id' => $fila['current_acount_payment_method_id'],
                    'caja_id'                            => $fila['caja_id'],
                    'total'                               => $fila['total'] * $cotizacion,
                ];
            }
        }

        return array_values($indexado);
    }

    /**
     * Arma el reporte completo en pesos.
     *
     * @param  int $user_id
     * @param  string $desde
     * @param  string $hasta
     * @param  array $filtros_extra
     * @return array
     */
    private static function armar_flujo_pesos($user_id, $desde, $hasta, $filtros_extra)
    {
        $lineas = self::calcular_lineas_por_moneda($user_id, $desde, $hasta, 1, $filtros_extra);

        return array_merge($lineas, [
            'moneda'               => 'pesos',
            'desde'                => $desde,
            'hasta'                => $hasta,
            'cotizacion_estimada'  => false,
        ]);
    }

    /**
     * Arma el reporte completo en dólares.
     *
     * @param  int $user_id
     * @param  string $desde
     * @param  string $hasta
     * @param  array $filtros_extra
     * @return array
     */
    private static function armar_flujo_dolares($user_id, $desde, $hasta, $filtros_extra)
    {
        $lineas = self::calcular_lineas_por_moneda($user_id, $desde, $hasta, 2, $filtros_extra);

        return array_merge($lineas, [
            'moneda'               => 'dolares',
            'desde'                => $desde,
            'hasta'                => $hasta,
            'cotizacion_estimada'  => false,
        ]);
    }

    /**
     * Arma el reporte consolidado: combina pesos + dólares×cotización.
     *
     * ⚠️ Deuda técnica MEDIA (no se resuelve en este prompt, misma limitación ya documentada en
     * `EstadoResultadosHelper::armar_estado_resultados_consolidado()`): usa la cotización VIGENTE
     * del sistema para todo el período, no la cotización real de cada operación individual.
     *
     * @param  int $user_id
     * @param  string $desde
     * @param  string $hasta
     * @param  array $filtros_extra
     * @return array
     */
    private static function armar_flujo_consolidado($user_id, $desde, $hasta, $filtros_extra)
    {
        $lineas_pesos = self::calcular_lineas_por_moneda($user_id, $desde, $hasta, 1, $filtros_extra);
        $lineas_dolares = self::calcular_lineas_por_moneda($user_id, $desde, $hasta, 2, $filtros_extra);

        $cotizacion = self::cotizacion_vigente($user_id);

        $lineas = self::combinar_lineas($lineas_pesos, $lineas_dolares, $cotizacion);

        return array_merge($lineas, [
            'moneda'               => 'consolidado',
            'desde'                => $desde,
            'hasta'                => $hasta,
            'cotizacion_estimada'  => true,
        ]);
    }

    // =========================================================================================
    // PLATA EN TRANSITO (liquidaciones pendientes + cheques diferidos en cartera)
    // =========================================================================================

    /**
     * Arma la sección de "plata en tránsito": liquidaciones pendientes (grupo 223) y cheques
     * diferidos en cartera por vencer, agrupadas en un mismo calendario por fecha con el origen
     * de cada línea, para que la UI pueda mostrar "esta semana entran $X".
     *
     * ⚠️ Deuda técnica BAJA (documentada, no se resuelve acá): los cheques (`cheque`) no tienen
     * columna de moneda en el esquema actual — se asume que están siempre en pesos (convención
     * real del negocio: cheques/echeqs se emiten en ARS). En modo `dolares` la sección de cheques
     * queda vacía a propósito (no hay forma de saber si un cheque sin columna de moneda debería
     * contarse ahí); en `pesos`/`consolidado` se listan igual. Si el negocio empieza a operar con
     * cheques en dólares, hay que agregar esa columna al modelo.
     *
     * @param  int $user_id
     * @param  string $moneda_efectiva 'pesos' | 'dolares' | 'consolidado'.
     * @param  array $filtros_extra `address_id`, `caja_id`.
     * @return array{liquidaciones_pendientes: array, cheques_diferidos: array,
     *               calendario: array, total_estimado: float}
     */
    private static function plata_en_transito($user_id, $moneda_efectiva, $filtros_extra)
    {
        // moneda_id a aplicar sobre las liquidaciones (que sí tienen dimensión de moneda vía
        // caja.moneda_id): en consolidado se traen las dos y se sabe reflejar cada una con su
        // propio origen, sin cotizar acá (el total ya viene expresado por línea, la UI decide).
        $moneda_id_liquidaciones = ($moneda_efectiva === 'dolares') ? 2 : (($moneda_efectiva === 'consolidado') ? null : 1);

        $liquidaciones_pendientes = self::liquidaciones_pendientes($user_id, $moneda_id_liquidaciones, $filtros_extra);

        // Cheques: sin dimensión de moneda en el esquema (ver PHPDoc), se listan siempre salvo en
        // modo dólares puro, donde no corresponde mostrarlos (asumidos en pesos).
        $cheques_diferidos = ($moneda_efectiva === 'dolares') ? [] : self::cheques_diferidos($user_id);

        // Calendario combinado: ambas fuentes agrupadas por fecha, con su origen explícito.
        $calendario = [];
        $total_estimado = 0.0;

        foreach ($liquidaciones_pendientes as $fila) {
            $fecha = $fila['fecha'];

            if (!isset($calendario[$fecha])) {
                $calendario[$fecha] = ['fecha' => $fecha, 'total' => 0.0, 'origenes' => []];
            }

            $calendario[$fecha]['total'] += $fila['total'];
            $calendario[$fecha]['origenes'][] = ['origen' => 'liquidacion', 'total' => $fila['total']];
            $total_estimado += $fila['total'];
        }

        foreach ($cheques_diferidos as $fila) {
            $fecha = $fila['fecha'];

            if (!isset($calendario[$fecha])) {
                $calendario[$fecha] = ['fecha' => $fecha, 'total' => 0.0, 'origenes' => []];
            }

            $calendario[$fecha]['total'] += $fila['total'];
            $calendario[$fecha]['origenes'][] = ['origen' => 'cheque', 'total' => $fila['total']];
            $total_estimado += $fila['total'];
        }

        ksort($calendario);

        return [
            'liquidaciones_pendientes' => $liquidaciones_pendientes,
            'cheques_diferidos'        => $cheques_diferidos,
            'calendario'               => array_values($calendario),
            'total_estimado'           => $total_estimado,
        ];
    }

    /**
     * Movimientos de caja con liquidación pendiente (`fecha_liquidacion_estimada` futura),
     * agrupados por fecha estimada, para este owner.
     *
     * FUENTE: `movimiento_cajas` (join `cajas` para resolver `user_id`/`moneda_id`/`address_id`,
     * columnas que `movimiento_cajas` no tiene propias). Un negocio sin cajas con liquidación
     * configurada simplemente no tiene filas con `fecha_liquidacion_estimada` no nula, y esta
     * query devuelve un array vacío sin romper (criterio de éxito del prompt).
     *
     * @param  int $user_id
     * @param  int|null $moneda_id 1 = pesos, 2 = dólares, null = sin filtrar (todas).
     * @param  array $filtros_extra `address_id`, `caja_id`.
     * @return array<int, array{fecha: string, total: float}>
     */
    private static function liquidaciones_pendientes($user_id, $moneda_id, $filtros_extra)
    {
        // Base sin agrupar, compartida con el total/detalle del drill-down (Grupo 226, Prompt 02):
        // así el agregado por fecha de acá y las filas sueltas de `liquidaciones_pendientes_detalle()`
        // nunca pueden divergir (regla 04 de ese prompt).
        $rows = self::query_liquidaciones_pendientes($user_id, $moneda_id, $filtros_extra)
            ->selectRaw('movimiento_cajas.fecha_liquidacion_estimada as fecha, SUM(movimiento_cajas.monto_neto_estimado) as total')
            ->groupBy('movimiento_cajas.fecha_liquidacion_estimada')
            ->orderBy('movimiento_cajas.fecha_liquidacion_estimada', 'ASC')
            ->get();

        $resultado = [];

        foreach ($rows as $row) {
            $resultado[] = [
                'fecha' => (string) $row->fecha,
                'total' => (float) $row->total,
            ];
        }

        return $resultado;
    }

    /**
     * Query base (sin agrupar, sin seleccionar columnas) de los movimientos de caja con liquidación
     * pendiente, factorizada para que `liquidaciones_pendientes()` (agregado por fecha, ya existente
     * desde el prompt 01), `liquidaciones_pendientes_total()` y `liquidaciones_pendientes_detalle()`
     * (ambos nuevos del prompt 02, drill-down) apliquen siempre el mismo filtro (regla 04/08: el
     * total del detalle tiene que coincidir al centavo con la tarjeta).
     *
     * @param  int $user_id
     * @param  int|null $moneda_id 1 = pesos, 2 = dólares, null = sin filtrar (todas).
     * @param  array $filtros_extra `address_id`, `caja_id`.
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private static function query_liquidaciones_pendientes($user_id, $moneda_id, $filtros_extra)
    {
        $hoy = Carbon::today()->toDateString();

        $query = MovimientoCaja::query()
            ->join('cajas', 'cajas.id', '=', 'movimiento_cajas.caja_id')
            ->where('cajas.user_id', $user_id)
            // Prompt 380/03: solo INGRESOS pendientes de liquidar. Sin este filtro, un movimiento
            // compensatorio (egreso) que revierte un cobro "a liquidar" -- desde que
            // DeleteCajaCompensacionHelper::revertir_liquidacion_del_original() le copia la fecha
            // futura del original, para que CajaLiquidacionHelper::calcular_saldos_liquidez() lo
            // clasifique bien -- tambien caía en esta query y sumaba en POSITIVO como si fuera plata
            // por cobrar, duplicando (en sentido contrario) la "plata en transito" de una venta que
            // ya se eliminó. Mismo filtro que ya usa CajaController::liquidaciones_pendientes()
            // (la línea de tiempo por caja) para el mismo concepto.
            ->whereNotNull('movimiento_cajas.ingreso')
            ->whereNotNull('movimiento_cajas.fecha_liquidacion_estimada')
            ->whereDate('movimiento_cajas.fecha_liquidacion_estimada', '>', $hoy);

        if (!is_null($moneda_id)) {
            if ((int) $moneda_id === 2) {
                $query->where('cajas.moneda_id', 2);
            } else {
                $query->where(function ($sub) {
                    $sub->whereNull('cajas.moneda_id')->orWhereIn('cajas.moneda_id', [0, 1]);
                });
            }
        }

        if (!empty($filtros_extra['address_id'])) {
            $query->where('cajas.address_id', $filtros_extra['address_id']);
        }

        if (!empty($filtros_extra['caja_id'])) {
            $query->where('movimiento_cajas.caja_id', $filtros_extra['caja_id']);
        }

        return $query;
    }

    /**
     * Total monetario de liquidaciones pendientes, para la tarjeta del drill-down
     * (Grupo 226, Prompt 02 — `concepto=liquidaciones_pendientes`). Misma query base que
     * `liquidaciones_pendientes_detalle()` (regla 04 del prompt).
     *
     * @param  int $user_id
     * @param  int|null $moneda_id
     * @param  array $filtros_extra
     * @return float
     */
    public static function liquidaciones_pendientes_total($user_id, $moneda_id, $filtros_extra = [])
    {
        return (float) self::query_liquidaciones_pendientes($user_id, $moneda_id, $filtros_extra)
            ->sum('movimiento_cajas.monto_neto_estimado');
    }

    /**
     * Detalle paginado (fila por fila, SIN agrupar por fecha a diferencia de
     * `liquidaciones_pendientes()`) de los movimientos de caja con liquidación pendiente, para el
     * drill-down (Grupo 226, Prompt 02). `desde`/`hasta` no aplican acá (mismo criterio que el resto
     * de la sección "plata en tránsito": solo importa la fecha de liquidación ESTIMADA futura, no un
     * rango de período histórico).
     *
     * @param  int $user_id
     * @param  int|null $moneda_id
     * @param  array $filtros_extra
     * @param  int $page
     * @param  int $per_page
     * @return array{registros: array, total: int, page: int, per_page: int}
     */
    public static function liquidaciones_pendientes_detalle($user_id, $moneda_id, $filtros_extra = [], $page = 1, $per_page = 50)
    {
        $base = function () use ($user_id, $moneda_id, $filtros_extra) {
            return self::query_liquidaciones_pendientes($user_id, $moneda_id, $filtros_extra);
        };

        $total = $base()->count();

        $rows = $base()
            ->orderBy('movimiento_cajas.fecha_liquidacion_estimada', 'ASC')
            ->skip(($page - 1) * $per_page)
            ->take($per_page)
            ->get(['movimiento_cajas.id', 'movimiento_cajas.fecha_liquidacion_estimada', 'movimiento_cajas.monto_neto_estimado']);

        $registros = [];

        foreach ($rows as $row) {
            $registros[] = [
                'id'          => $row->id,
                'fecha'       => (string) $row->fecha_liquidacion_estimada,
                'descripcion' => 'Liquidación pendiente #'.$row->id,
                'monto'       => (float) $row->monto_neto_estimado,
                'link_tipo'   => 'movimiento_caja',
                'link_id'     => $row->id,
            ];
        }

        return [
            'registros' => $registros,
            'total'     => $total,
            'page'      => (int) $page,
            'per_page'  => (int) $per_page,
        ];
    }

    /**
     * Cheques recibidos en cartera con fecha de vencimiento futura, no endosados, no cobrados
     * ("depositados") ni rechazados, agrupados por fecha de pago (vencimiento).
     *
     * FUENTE: tabla `cheque` (NUNCA `checks`, que es legacy en desuso — criterio de éxito del
     * prompt). Estados exactos replicados de `ChequeController::index()` (única fuente de verdad
     * de estos estados en el sistema, no se inventan acá):
     * - `estado_manual = 'cobrado'` o `'rechazado'` → estado final, se excluye.
     * - `endosado_a_provider_id` no nulo → el cheque ya salió de la cartera (fue endosado a un
     *   proveedor), se excluye.
     * - `fecha_pago` es la fecha a partir de la cual el cheque es cobrable (no hay una columna de
     *   "vencimiento" separada en el modelo); se toma como la fecha de vencimiento de este
     *   calendario. Solo se listan los que todavía están en el futuro respecto de hoy.
     *
     * @param  int $user_id
     * @return array<int, array{fecha: string, total: float}>
     */
    private static function cheques_diferidos($user_id)
    {
        // Base sin agrupar, compartida con el total/detalle del drill-down (Grupo 226, Prompt 02):
        // así el agregado por fecha de acá y las filas sueltas de `cheques_diferidos_detalle()`
        // nunca pueden divergir (regla 04 de ese prompt).
        $rows = self::query_cheques_diferidos($user_id)
            ->selectRaw('fecha_pago as fecha, SUM(amount) as total')
            ->groupBy('fecha_pago')
            ->orderBy('fecha_pago', 'ASC')
            ->get();

        $resultado = [];

        foreach ($rows as $row) {
            $resultado[] = [
                'fecha' => Carbon::parse($row->fecha)->toDateString(),
                'total' => (float) $row->total,
            ];
        }

        return $resultado;
    }

    /**
     * Query base (sin agrupar, sin seleccionar columnas) de los cheques diferidos en cartera,
     * factorizada para que `cheques_diferidos()` (agregado por fecha, ya existente desde el
     * prompt 01), `cheques_diferidos_total()` y `cheques_diferidos_detalle()` (ambos nuevos del
     * prompt 02, drill-down) apliquen siempre el mismo filtro (regla 04/08: el total del detalle
     * tiene que coincidir al centavo con la tarjeta). Mismos estados exactos que
     * `ChequeController::index()` (ver PHPDoc de `cheques_diferidos()` más arriba, no se repite acá).
     *
     * @param  int $user_id
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private static function query_cheques_diferidos($user_id)
    {
        $hoy = Carbon::today()->toDateString();

        return Cheque::query()
            ->where('user_id', $user_id)
            ->where('tipo', 'recibido')
            ->whereNull('endosado_a_provider_id')
            ->where(function ($sub) {
                $sub->whereNull('estado_manual')->orWhereNotIn('estado_manual', ['cobrado', 'rechazado']);
            })
            ->whereDate('fecha_pago', '>', $hoy);
    }

    /**
     * Total monetario de cheques diferidos en cartera, para la tarjeta del drill-down
     * (Grupo 226, Prompt 02 — `concepto=cheques_en_cartera`). Misma query base que
     * `cheques_diferidos_detalle()` (regla 04 del prompt).
     *
     * @param  int $user_id
     * @return float
     */
    public static function cheques_diferidos_total($user_id)
    {
        return (float) self::query_cheques_diferidos($user_id)->sum('amount');
    }

    /**
     * Detalle paginado (fila por fila, SIN agrupar por fecha a diferencia de
     * `cheques_diferidos()`) de los cheques diferidos en cartera, para el drill-down
     * (Grupo 226, Prompt 02). Sin dimensión de moneda propia (ver PHPDoc de `cheques_diferidos()`):
     * el caller (`ReporteDetalleController`) es responsable de no invocar este método en modo
     * dólares — acá no se filtra por moneda porque el modelo no tiene esa columna.
     *
     * @param  int $user_id
     * @param  int $page
     * @param  int $per_page
     * @return array{registros: array, total: int, page: int, per_page: int}
     */
    public static function cheques_diferidos_detalle($user_id, $page = 1, $per_page = 50)
    {
        $base = function () use ($user_id) {
            return self::query_cheques_diferidos($user_id);
        };

        $total = $base()->count();

        $rows = $base()
            ->orderBy('fecha_pago', 'ASC')
            ->skip(($page - 1) * $per_page)
            ->take($per_page)
            ->get(['id', 'numero', 'banco', 'fecha_pago', 'amount']);

        $registros = [];

        foreach ($rows as $row) {
            $registros[] = [
                'id'          => $row->id,
                'fecha'       => Carbon::parse($row->fecha_pago)->toDateString(),
                'descripcion' => 'Cheque N° '.($row->numero ?: $row->id).($row->banco ? ' — '.$row->banco : ''),
                'monto'       => (float) $row->amount,
                'link_tipo'   => 'cheque',
                'link_id'     => $row->id,
            ];
        }

        return [
            'registros' => $registros,
            'total'     => $total,
            'page'      => (int) $page,
            'per_page'  => (int) $per_page,
        ];
    }
}
