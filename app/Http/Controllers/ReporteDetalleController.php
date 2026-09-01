<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\contabilidad\ContabilidadRepository;
use App\Http\Controllers\Helpers\contabilidad\FlujoCajaHelper;
use Illuminate\Http\Request;

/**
 * Endpoint genérico de drill-down para los reportes contables nuevos (Grupos 224/225/226):
 * Estado de Resultados, Posición Fiscal y Flujo de Caja.
 *
 * Principio de diseño (Grupo 226, Prompt 02, pedido explícito de Lucas): "todo número es auditable
 * con un clic". Un endpoint genérico (`?concepto=...`) en vez de veinte endpoints específicos — cada
 * tarjeta de cualquiera de los tres reportes de arriba abre acá el listado de registros que la
 * componen, con link al comprobante de origen.
 *
 * 🔴 Lista blanca dura (regla 02 del prompt): el `switch` de abajo es la ÚNICA fuente de verdad de
 * qué conceptos existen. Un concepto no reconocido devuelve 422, nunca un 500 ni una respuesta
 * vacía. Nunca se usa un método variable dinámico (`$repo->{$concepto}()`) — eso sería inyección de
 * método disfrazada de elegancia.
 *
 * 🔴 Coherencia con las tarjetas (regla 04 del prompt): el `total` que devuelve este endpoint tiene
 * que ser EXACTAMENTE el mismo número que muestra la tarjeta del reporte correspondiente. Por eso
 * cada `case` calcula el total con el mismo método (y los mismos `$filtros`) que ya usa
 * `EstadoResultadosHelper`/`PosicionFiscalHelper`/`FlujoCajaHelper` para armar esa tarjeta, y el
 * listado paginado sale siempre del `*_detalle()` gemelo de `ContabilidadRepository` (o de
 * `FlujoCajaHelper`, para "plata en tránsito", que por diseño no pasa por el repository — ver su
 * propio PHPDoc de clase).
 *
 * Toda la lógica de negocio vive en `ContabilidadRepository`/`FlujoCajaHelper`: este controller solo
 * resuelve el usuario autenticado, valida el `concepto` contra la lista blanca, arma los filtros del
 * request y pagina la respuesta — mismo criterio que el resto de los controllers de este módulo de
 * reportes (`EstadoResultadosController`, `PosicionFiscalController`, `FlujoCajaController`).
 */
class ReporteDetalleController extends Controller
{
    /**
     * Tope duro de `per_page` (regla 03 del prompt): un cliente con 100.000 ventas puede tocar
     * "Ventas brutas" en un rango anual, y sin este tope eso mata el servidor.
     */
    const PER_PAGE_MAX = 500;

    /** Cantidad de registros por página cuando el request no manda `per_page`. */
    const PER_PAGE_DEFAULT = 50;

    /**
     * GET api/reportes/detalle
     *
     * Devuelve el listado paginado de registros que componen una tarjeta puntual de cualquiera de
     * los reportes contables nuevos, junto con el total monetario de esa tarjeta (para que el
     * frontend pueda verificar coherencia) y los datos de paginación.
     *
     * @param  Request $request Query params: `concepto` (obligatorio, ver lista blanca en el
     *                           `switch` de abajo), `desde`, `hasta` (Y-m-d, ambos inclusive;
     *                           algunos conceptos de "plata en tránsito" los ignoran, ver PHPDoc de
     *                           `FlujoCajaHelper`), `moneda` ('pesos' | 'dolares' | 'consolidado',
     *                           default 'pesos' — 'consolidado' se resuelve como 'pesos' para el
     *                           detalle fila a fila, misma limitación ya documentada en cada
     *                           `*_detalle()` de `ContabilidadRepository`, que no admite una
     *                           cotización combinada), `page` (default 1), `per_page` (default 50,
     *                           tope 500), y los filtros específicos opcionales `address_id`,
     *                           `employee_id`, `caja_id`, `categoria` (id de `expense_concept`, solo
     *                           aplica al concepto `gastos`).
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        // Usuario autenticado (owner dueño del reporte): único punto de este flujo que puede leer
        // la sesión, se lo pasa explícito a los helpers/repository (regla 06, scope de seguridad).
        $user_id = $this->userId();

        $concepto = $request->input('concepto');
        $desde = $request->input('desde');
        $hasta = $request->input('hasta');
        $moneda = $request->input('moneda', 'pesos');

        // Paginación (regla 03): page nunca menor a 1, per_page acotado entre 1 y el tope duro.
        $page = max(1, (int) $request->input('page', 1));
        $per_page = min(self::PER_PAGE_MAX, max(1, (int) $request->input('per_page', self::PER_PAGE_DEFAULT)));

        // moneda_id efectivo: 'dolares' -> 2, cualquier otro valor ('pesos', 'consolidado' o vacío)
        // -> 1 (pesos). El drill-down fila a fila no soporta 'consolidado' (mezcla de pesos + dólares
        // por cotización): ninguno de los `*_detalle()` existentes en `ContabilidadRepository` lo
        // admite (deuda técnica ya documentada ahí), así que este endpoint no la inventa de nuevo.
        $moneda_id = ($moneda === 'dolares') ? 2 : 1;

        // Filtros específicos opcionales (ver Diseño del prompt): cada concepto usa los que le
        // aplican y ContabilidadRepository/FlujoCajaHelper ignoran silenciosamente el resto, mismo
        // contrato que ya tienen esas clases.
        $filtros = ['moneda_id' => $moneda_id];

        if ($request->filled('address_id')) {
            $filtros['address_id'] = $request->input('address_id');
        }

        if ($request->filled('employee_id')) {
            $filtros['employee_id'] = $request->input('employee_id');
        }

        if ($request->filled('caja_id')) {
            $filtros['caja_id'] = $request->input('caja_id');
        }

        if ($request->filled('categoria')) {
            $filtros['expense_concept_id'] = $request->input('categoria');
        }

        switch ($concepto) {

            case 'ventas_brutas':
                $total = ContabilidadRepository::ventas_brutas($user_id, $desde, $hasta, $filtros);
                $detalle = ContabilidadRepository::ventas_brutas_detalle($user_id, $desde, $hasta, $filtros, $page, $per_page);
                break;

            case 'devoluciones':
                $total = ContabilidadRepository::devoluciones($user_id, $desde, $hasta, $filtros);
                $detalle = ContabilidadRepository::devoluciones_detalle($user_id, $desde, $hasta, $filtros, $page, $per_page);
                break;

            case 'costo_mercaderia_vendida':
                $total = ContabilidadRepository::costo_mercaderia_vendida($user_id, $desde, $hasta, $filtros);
                $detalle = ContabilidadRepository::costo_mercaderia_vendida_detalle($user_id, $desde, $hasta, $filtros, $page, $per_page);
                break;

            case 'gastos':
                // "Gastos operativos" del Estado de Resultados (grupo 225): SIN la comisión de
                // cobro (query_gastos() ya la excluye). El filtro `categoria` (expense_concept_id,
                // ya mapeado arriba a $filtros['expense_concept_id']) acota a una sola categoría del
                // desglose; sin ese filtro, el total es la suma de TODAS las categorías, igual que
                // la tarjeta "Gastos operativos" completa.
                $gastos_por_categoria = ContabilidadRepository::gastos_por_categoria($user_id, $desde, $hasta, $filtros);

                $total = 0.0;
                foreach ($gastos_por_categoria as $gasto) {
                    $total += (float) $gasto['total'];
                }

                $detalle = ContabilidadRepository::gastos_por_categoria_detalle($user_id, $desde, $hasta, $filtros, $page, $per_page);
                break;

            case 'iva_debito':
                // Sin dimensión de moneda (global, ya en pesos — mismo criterio que PosicionFiscalHelper).
                $total = ContabilidadRepository::iva_debito($user_id, $desde, $hasta);
                $detalle = ContabilidadRepository::iva_debito_detalle($user_id, $desde, $hasta, $page, $per_page);
                break;

            case 'iva_notas_credito':
                // Sin dimensión de moneda, igual que iva_debito: los impuestos se liquidan en
                // pesos sobre toda la operación (ver PHPDoc de clase de PosicionFiscalHelper).
                $total = ContabilidadRepository::iva_notas_credito($user_id, $desde, $hasta);
                $detalle = ContabilidadRepository::iva_notas_credito_detalle($user_id, $desde, $hasta, $page, $per_page);
                break;

            case 'iva_credito':
                $total = ContabilidadRepository::iva_credito($user_id, $desde, $hasta);
                $detalle = ContabilidadRepository::iva_credito_detalle($user_id, $desde, $hasta, $page, $per_page);
                break;

            case 'percepciones_iva':
                $percepciones = ContabilidadRepository::percepciones_sufridas($user_id, $desde, $hasta);
                $total = $percepciones['iva'];
                $detalle = ContabilidadRepository::percepciones_iva_detalle($user_id, $desde, $hasta, $page, $per_page);
                break;

            case 'percepciones_iibb':
                $percepciones = ContabilidadRepository::percepciones_sufridas($user_id, $desde, $hasta);
                $total = $percepciones['iibb'];
                $detalle = ContabilidadRepository::percepciones_iibb_detalle($user_id, $desde, $hasta, $page, $per_page);
                break;

            case 'retenciones':
                // Combina los tres impuestos (IVA + IIBB + Ganancias) en una única tarjeta genérica:
                // a diferencia de percepciones, la especificación no separó este concepto por
                // impuesto, así que el total y el detalle salen combinados de la misma fuente.
                $retenciones = ContabilidadRepository::retenciones_sufridas($user_id, $desde, $hasta);
                $total = (float) $retenciones['iva'] + (float) $retenciones['iibb'] + (float) $retenciones['ganancias'];
                $detalle = ContabilidadRepository::retenciones_sufridas_detalle($user_id, $desde, $hasta, $page, $per_page);
                break;

            case 'cobranzas':
                $total = ContabilidadRepository::cobranzas($user_id, $desde, $hasta, $filtros);
                $detalle = ContabilidadRepository::cobranzas_detalle($user_id, $desde, $hasta, $filtros, $page, $per_page);
                break;

            case 'pagos_proveedores':
                $total = ContabilidadRepository::pagos_a_proveedores($user_id, $desde, $hasta, $filtros);
                $detalle = ContabilidadRepository::pagos_a_proveedores_detalle($user_id, $desde, $hasta, $filtros, $page, $per_page);
                break;

            case 'liquidaciones_pendientes':
                // "Plata en tránsito" (FlujoCajaHelper): no pasa por ContabilidadRepository (ver
                // PHPDoc de clase de FlujoCajaHelper) y no acepta `desde`/`hasta` — solo importan las
                // liquidaciones estimadas a futuro respecto de hoy, mismo criterio que
                // FlujoCajaHelper::plata_en_transito(). Solo reenvía address_id/caja_id.
                $filtros_transito = [];

                if (isset($filtros['address_id'])) {
                    $filtros_transito['address_id'] = $filtros['address_id'];
                }

                if (isset($filtros['caja_id'])) {
                    $filtros_transito['caja_id'] = $filtros['caja_id'];
                }

                $total = FlujoCajaHelper::liquidaciones_pendientes_total($user_id, $moneda_id, $filtros_transito);
                $detalle = FlujoCajaHelper::liquidaciones_pendientes_detalle($user_id, $moneda_id, $filtros_transito, $page, $per_page);
                break;

            case 'cheques_en_cartera':
                // Sin dimensión de moneda en el esquema (ver PHPDoc de FlujoCajaHelper::cheques_diferidos()):
                // en modo dólares no hay cheques que listar (mismo criterio que plata_en_transito()).
                if ($moneda_id === 2) {
                    $total = 0.0;
                    $detalle = [
                        'registros' => [],
                        'total'     => 0,
                        'page'      => $page,
                        'per_page'  => $per_page,
                    ];
                } else {
                    $total = FlujoCajaHelper::cheques_diferidos_total($user_id);
                    $detalle = FlujoCajaHelper::cheques_diferidos_detalle($user_id, $page, $per_page);
                }
                break;

            default:
                // Regla 02 del prompt: lista blanca dura, un concepto no reconocido nunca llega a
                // ejecutar ninguna query — 422 con mensaje claro, nunca un 500 ni una respuesta vacía.
                return response()->json([
                    'message' => 'Concepto de reporte no reconocido: '.$concepto,
                ], 422);
        }

        return response()->json([
            'concepto'   => $concepto,
            'total'      => $total,
            'registros'  => $detalle['registros'],
            'paginacion' => [
                'page'            => (int) $page,
                'per_page'        => (int) $per_page,
                'total_registros' => $detalle['total'],
            ],
        ], 200);
    }
}
