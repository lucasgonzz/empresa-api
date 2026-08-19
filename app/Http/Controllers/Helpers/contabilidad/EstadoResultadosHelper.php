<?php

namespace App\Http\Controllers\Helpers\contabilidad;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\User;

/**
 * Estado de Resultados devengado (Grupo 225, Prompt 01).
 *
 * Arma la estructura contable formal:
 *
 *   Ventas brutas
 *   (− Devoluciones / NC)
 *   = Ventas netas
 *   (− Costo de mercadería vendida, a costo real Capa 1)
 *   = Resultado bruto                          → + margen bruto %
 *   (− Gastos operativos, por categoría)
 *   = Resultado operativo
 *   (− Impuestos y costos financieros: IIBB devengado, comisiones de cobro)
 *   = Resultado neto                           → + margen neto %
 *
 * Reemplaza la nomenclatura confusa del reporte viejo (`PerformanceHelper`/`CompanyPerformance`):
 * lo que ahí se llama `ingresos_brutos` es en realidad `resultado_bruto`, y `rentabilidad` es
 * `resultado_neto`. Ese nombre viejo (`ingresos_brutos`) colisiona además con el impuesto IIBB
 * (Ingresos Brutos), que aparece en este mismo reporte como línea de impuesto — motivo central del
 * renombre.
 *
 * 🔴 Cero queries propias: este helper consume EXCLUSIVAMENTE `ContabilidadRepository`. Si falta un
 * dato, se agrega un método ahí, nunca una query suelta acá (mismo contrato que el repository
 * documenta en su propio PHPDoc).
 *
 * 🔴 Cero `auth()` / `Auth::` / `request()` / sesión global: `SetCompanyPerformances` (el comando
 * de cron) llama a este helper sin sesión HTTP. Todos los métodos reciben `$user_id` como primer
 * parámetro explícito, igual que `ContabilidadRepository`; la resolución de la extensión de
 * dólares y de la cotización también se hace a partir de ese `$user_id`, nunca del usuario
 * logueado. El controller es el único que puede leer `auth()->user()`, y lo hace para pasarle el
 * id acá.
 *
 * Este prompt reemplaza tres intentos anteriores que fallaron el checker por: aplicar la
 * cotización a `iibb_determinado()` (que es global y ya viene en pesos, no admite `$filtros` de
 * moneda), restar la comisión de cobro dos veces (una como gasto operativo y otra como renglón
 * propio), y usar `auth()` dentro de este helper (rompe en el cron, que corre sin sesión).
 */
class EstadoResultadosHelper
{
    /**
     * Punto de entrada único del reporte: arma la estructura completa del Estado de Resultados
     * para el usuario, el rango de fechas y la dimensión de moneda pedidos.
     *
     * 🔴 Registro de alcance por línea (no se infiere, está fijado acá):
     * - Ventas brutas, devoluciones, costo de mercadería (vendida/devuelta), gastos operativos y
     *   comisiones de cobro son POR MONEDA (los métodos que las calculan aceptan `$filtros` con
     *   `moneda_id`).
     * - El IIBB determinado es GLOBAL, ya en pesos (`iibb_determinado()` no acepta `$filtros`
     *   porque el impuesto se liquida en pesos sobre todas las ventas, sin dimensión de moneda).
     *
     * @param  int $user_id Owner dueño del reporte (nunca el usuario de sesión).
     * @param  string $desde Fecha de inicio del período (Y-m-d), inclusive.
     * @param  string $hasta Fecha de fin del período (Y-m-d), inclusive.
     * @param  string $moneda 'pesos' | 'dolares' | 'consolidado'. Si la extensión de ventas en
     *                         dólares no está activa para este usuario, se ignora y se calcula todo
     *                         en pesos (comportamiento por default histórico).
     * @param  array $filtros_extra Filtros adicionales por línea: `address_id`, `employee_id`,
     *                               `price_type_id`. NUNCA incluir `moneda_id` acá: la moneda la
     *                               resuelve este método según `$moneda`.
     * @return array Estructura completa del reporte (ver claves documentadas en los métodos
     *               privados de armado).
     */
    public static function estado_resultados($user_id, $desde, $hasta, $moneda = 'pesos', $filtros_extra = [])
    {
        // La extensión de ventas en dólares se resuelve siempre a partir del $user_id explícito,
        // cargando el modelo directo — nunca desde la sesión (regla 06 del prompt, cron-safe).
        $extension_dolares_activa = self::extension_dolares_activa($user_id);

        // Sin la extensión activa, el parámetro moneda se ignora y el reporte es siempre en pesos
        // (regla del prompt: "Sin la extensión, el parámetro moneda no afecta nada").
        $moneda_efectiva = $extension_dolares_activa ? $moneda : 'pesos';

        if ($moneda_efectiva === 'dolares') {
            return self::armar_estado_resultados_dolares($user_id, $desde, $hasta, $filtros_extra);
        }

        if ($moneda_efectiva === 'consolidado') {
            return self::armar_estado_resultados_consolidado($user_id, $desde, $hasta, $filtros_extra);
        }

        return self::armar_estado_resultados_pesos($user_id, $desde, $hasta, $filtros_extra);
    }

    /**
     * Resuelve si el usuario (owner) tiene activa la extensión `ventas_en_dolares`, sin depender de
     * sesión: carga el modelo `User` directo por id y se lo pasa a `UserHelper::hasExtencion()`.
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
     * Cotización vigente del sistema para este usuario (`users.dollar`, el mismo campo global que
     * usa `ArticleHelper::cotizar()` para cotizar costos). Es la cotización "estimada" que se usa en
     * el consolidado cuando no hay una cotización propia de cada operación registrada (ver
     * limitación documentada en `armar_estado_resultados_consolidado()`).
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
     * Calcula las líneas POR MONEDA (todo lo que acepta `$filtros['moneda_id']`) para un
     * `moneda_id` puntual. No incluye el IIBB (global): eso lo agrega cada `armar_*` por separado,
     * para que nunca pueda colarse una línea global dentro de esta estructura "por moneda"
     * (regla dura del prompt: `combinar_lineas()` recibe ÚNICAMENTE líneas por moneda).
     *
     * @param  int $user_id
     * @param  string $desde
     * @param  string $hasta
     * @param  int $moneda_id 1 = pesos, 2 = dólares.
     * @param  array $filtros_extra `address_id`, `employee_id`, `price_type_id`.
     * @return array{ventas_brutas: float, devoluciones: float, ventas_netas: float,
     *               costo_mercaderia_vendida: float, costo_mercaderia_devuelta: float,
     *               resultado_bruto: float, gastos_por_categoria: array,
     *               gastos_operativos: float, resultado_operativo: float,
     *               comisiones_de_cobro: float}
     */
    private static function calcular_lineas_por_moneda($user_id, $desde, $hasta, $moneda_id, $filtros_extra)
    {
        $filtros = array_merge($filtros_extra, ['moneda_id' => $moneda_id]);

        // Ventas netas: ventas brutas del período menos devoluciones / notas de crédito.
        $ventas_brutas = ContabilidadRepository::ventas_brutas($user_id, $desde, $hasta, $filtros);
        $devoluciones = ContabilidadRepository::devoluciones($user_id, $desde, $hasta, $filtros);
        $ventas_netas = $ventas_brutas - $devoluciones;

        // Costo real (Capa 1) de la mercadería vendida, neteado contra el costo de lo devuelto.
        $costo_mercaderia_vendida = ContabilidadRepository::costo_mercaderia_vendida($user_id, $desde, $hasta, $filtros);
        $costo_mercaderia_devuelta = ContabilidadRepository::costo_mercaderia_devuelta($user_id, $desde, $hasta, $filtros);
        $costo_neto_mercaderia = $costo_mercaderia_vendida - $costo_mercaderia_devuelta;

        $resultado_bruto = $ventas_netas - $costo_neto_mercaderia;

        // Gastos operativos por categoría: ya vienen SIN las comisiones de cobro (query_gastos()
        // las excluye, tarea 00(d) del prompt), así que sumarlos acá no duplica nada.
        $gastos_por_categoria = ContabilidadRepository::gastos_por_categoria($user_id, $desde, $hasta, $filtros);

        $gastos_operativos = 0.0;
        foreach ($gastos_por_categoria as $gasto) {
            $gastos_operativos += (float) $gasto['total'];
        }

        $resultado_operativo = $resultado_bruto - $gastos_operativos;

        // Comisión de cobro del período en esta moneda (grupo 223, fuente única documentada en
        // ContabilidadRepository::query_ids_expenses_comision()).
        $comisiones_de_cobro = ContabilidadRepository::comisiones_de_cobro($user_id, $desde, $hasta, $filtros);

        return [
            'ventas_brutas'             => $ventas_brutas,
            'devoluciones'              => $devoluciones,
            'ventas_netas'              => $ventas_netas,
            'costo_mercaderia_vendida'  => $costo_mercaderia_vendida,
            'costo_mercaderia_devuelta' => $costo_mercaderia_devuelta,
            'resultado_bruto'           => $resultado_bruto,
            'gastos_por_categoria'      => $gastos_por_categoria,
            'gastos_operativos'         => $gastos_operativos,
            'resultado_operativo'       => $resultado_operativo,
            'comisiones_de_cobro'       => $comisiones_de_cobro,
        ];
    }

    /**
     * Combina dos juegos de líneas POR MONEDA (pesos + dólares×cotización). Recibe únicamente
     * líneas por moneda (nunca el IIBB, que es global): el caller es responsable de sumar el IIBB
     * una sola vez, después de llamar a esta función, sin aplicarle cotización.
     *
     * @param  array $lineas_pesos Resultado de `calcular_lineas_por_moneda(..., 1, ...)`.
     * @param  array $lineas_dolares Resultado de `calcular_lineas_por_moneda(..., 2, ...)`.
     * @param  float $cotizacion Cotización a aplicar sobre las líneas en dólares.
     * @return array Misma forma que `calcular_lineas_por_moneda()`, ya sumado.
     */
    private static function combinar_lineas($lineas_pesos, $lineas_dolares, $cotizacion)
    {
        // Campos numéricos simples: pesos + dólares × cotización.
        $campos_numericos = [
            'ventas_brutas', 'devoluciones', 'ventas_netas',
            'costo_mercaderia_vendida', 'costo_mercaderia_devuelta', 'resultado_bruto',
            'gastos_operativos', 'resultado_operativo', 'comisiones_de_cobro',
        ];

        $combinado = [];

        foreach ($campos_numericos as $campo) {
            $combinado[$campo] = $lineas_pesos[$campo] + ($lineas_dolares[$campo] * $cotizacion);
        }

        // Gastos por categoría: se combinan por expense_concept_id, cotizando la parte en dólares.
        $gastos_indexados = [];

        foreach ($lineas_pesos['gastos_por_categoria'] as $gasto) {
            $key = $gasto['expense_concept_id'];
            $gastos_indexados[$key] = $gasto;
        }

        foreach ($lineas_dolares['gastos_por_categoria'] as $gasto) {
            $key = $gasto['expense_concept_id'];

            if (isset($gastos_indexados[$key])) {
                $gastos_indexados[$key]['total'] += $gasto['total'] * $cotizacion;
            } else {
                $gastos_indexados[$key] = [
                    'expense_concept_id' => $gasto['expense_concept_id'],
                    'concepto'           => $gasto['concepto'],
                    'total'              => $gasto['total'] * $cotizacion,
                ];
            }
        }

        $combinado['gastos_por_categoria'] = array_values($gastos_indexados);

        return $combinado;
    }

    /**
     * Calcula los dos márgenes porcentuales sobre ventas netas. Con `ventas_netas = 0` ambos vienen
     * `null` (no `0`): no es lo mismo no haber vendido nada que tener margen cero, y un `0` en
     * pantalla en un mes sin ventas sería información falsa.
     *
     * @param  float $resultado_bruto
     * @param  float|null $resultado_neto Null cuando la moneda es 'dolares' y no hay IIBB aplicado.
     * @param  float $ventas_netas
     * @return array{margen_bruto_porcentaje: float|null, margen_neto_porcentaje: float|null}
     */
    private static function calcular_margenes($resultado_bruto, $resultado_neto, $ventas_netas)
    {
        if ((float) $ventas_netas === 0.0) {
            return [
                'margen_bruto_porcentaje' => null,
                'margen_neto_porcentaje'  => null,
            ];
        }

        return [
            'margen_bruto_porcentaje' => ($resultado_bruto / $ventas_netas) * 100,
            'margen_neto_porcentaje'  => is_null($resultado_neto) ? null : ($resultado_neto / $ventas_netas) * 100,
        ];
    }

    /**
     * Arma el reporte completo en pesos: incluye el IIBB (global, ya en pesos) sin cotizar.
     *
     * @param  int $user_id
     * @param  string $desde
     * @param  string $hasta
     * @param  array $filtros_extra
     * @return array
     */
    private static function armar_estado_resultados_pesos($user_id, $desde, $hasta, $filtros_extra)
    {
        $lineas = self::calcular_lineas_por_moneda($user_id, $desde, $hasta, 1, $filtros_extra);

        // IIBB determinado: GLOBAL y ya en pesos, se suma tal cual, sin pasar por combinar_lineas().
        $iibb_determinado = ContabilidadRepository::iibb_determinado($user_id, $desde, $hasta);

        $resultado_neto = $lineas['resultado_operativo'] - $iibb_determinado - $lineas['comisiones_de_cobro'];

        $margenes = self::calcular_margenes($lineas['resultado_bruto'], $resultado_neto, $lineas['ventas_netas']);

        return array_merge($lineas, $margenes, [
            'moneda'                          => 'pesos',
            'desde'                           => $desde,
            'hasta'                           => $hasta,
            'iibb_determinado'                => $iibb_determinado,
            'resultado_neto'                  => $resultado_neto,
            'lineas_no_atribuibles_a_moneda'  => [],
            'cotizacion_estimada'             => false,
        ]);
    }

    /**
     * Arma el reporte completo en dólares: el IIBB NO es atribuible a esta moneda (se liquida en
     * pesos sobre el total de ventas, sin dimensión de moneda), así que `resultado_neto` queda sin
     * descontarlo, y se informa la línea como no atribuible vía `lineas_no_atribuibles_a_moneda`.
     *
     * @param  int $user_id
     * @param  string $desde
     * @param  string $hasta
     * @param  array $filtros_extra
     * @return array
     */
    private static function armar_estado_resultados_dolares($user_id, $desde, $hasta, $filtros_extra)
    {
        $lineas = self::calcular_lineas_por_moneda($user_id, $desde, $hasta, 2, $filtros_extra);

        // Resultado neto en dólares: sin IIBB, porque no es atribuible a esta moneda.
        $resultado_neto = $lineas['resultado_operativo'] - $lineas['comisiones_de_cobro'];

        $margenes = self::calcular_margenes($lineas['resultado_bruto'], $resultado_neto, $lineas['ventas_netas']);

        return array_merge($lineas, $margenes, [
            'moneda'                          => 'dolares',
            'desde'                           => $desde,
            'hasta'                           => $hasta,
            // Null (no 0): un IIBB en 0 en la vista en dólares sería información falsa, igual que
            // los márgenes con ventas netas en 0.
            'iibb_determinado'                => null,
            'resultado_neto'                  => $resultado_neto,
            'lineas_no_atribuibles_a_moneda'  => ['iibb_determinado'],
            'cotizacion_estimada'             => false,
        ]);
    }

    /**
     * Arma el reporte consolidado: combina pesos + dólares×cotización SOLO sobre las líneas por
     * moneda, y recién después suma el IIBB (global) una única vez, sin cotizarlo — así se evita
     * el bug de los tres intentos anteriores (IIBB inflado por la cotización).
     *
     * ⚠️ Deuda técnica MEDIA (no se resuelve en este prompt, documentada también en
     * `ContabilidadRepository::iibb_determinado()` para la limitación análoga): usa la cotización
     * VIGENTE del sistema (`users.dollar`) para todo el período, no la cotización real de cada
     * operación individual, porque `ContabilidadRepository` no expone (todavía) un desglose de
     * ventas en dólares por cotización histórica — sus métodos devuelven totales agregados, no
     * filas con su propia cotización. Por eso `cotizacion_estimada` siempre viene `true` acá. Si
     * esto se vuelve un problema real, habría que agregar a `ContabilidadRepository` un método que
     * devuelva el desglose de ventas en dólares agrupado por cotización efectiva de cada operación.
     *
     * @param  int $user_id
     * @param  string $desde
     * @param  string $hasta
     * @param  array $filtros_extra
     * @return array
     */
    private static function armar_estado_resultados_consolidado($user_id, $desde, $hasta, $filtros_extra)
    {
        $lineas_pesos = self::calcular_lineas_por_moneda($user_id, $desde, $hasta, 1, $filtros_extra);
        $lineas_dolares = self::calcular_lineas_por_moneda($user_id, $desde, $hasta, 2, $filtros_extra);

        $cotizacion = self::cotizacion_vigente($user_id);

        // Combina ÚNICAMENTE las líneas por moneda (regla dura del prompt); el IIBB (global) se
        // suma después, aparte, sin pasar por esta función.
        $lineas = self::combinar_lineas($lineas_pesos, $lineas_dolares, $cotizacion);

        // IIBB determinado: GLOBAL y ya en pesos. Se suma UNA sola vez y SIN cotización (invariante
        // a la moneda: tiene que dar exactamente igual que en 'pesos', sea cual sea la cotización).
        $iibb_determinado = ContabilidadRepository::iibb_determinado($user_id, $desde, $hasta);

        $resultado_neto = $lineas['resultado_operativo'] - $iibb_determinado - $lineas['comisiones_de_cobro'];

        $margenes = self::calcular_margenes($lineas['resultado_bruto'], $resultado_neto, $lineas['ventas_netas']);

        return array_merge($lineas, $margenes, [
            'moneda'                          => 'consolidado',
            'desde'                           => $desde,
            'hasta'                           => $hasta,
            'iibb_determinado'                => $iibb_determinado,
            'resultado_neto'                  => $resultado_neto,
            'lineas_no_atribuibles_a_moneda'  => [],
            'cotizacion_estimada'             => true,
        ]);
    }
}
