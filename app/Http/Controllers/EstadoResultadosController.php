<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\contabilidad\EstadoResultadosHelper;
use Illuminate\Http\Request;

/**
 * Controller del Estado de Resultados devengado (Grupo 225, Prompt 01).
 *
 * Es aditivo: `CompanyPerformanceController` y `PerformanceHelper` siguen existiendo y
 * funcionando sin cambios; la pantalla vieja de reportes no se toca hasta el grupo 227. Este
 * endpoint es la nueva estructura contable formal, con la moneda como dimensión de la vista en vez
 * de duplicar tarjetas.
 *
 * Toda la lógica de negocio vive en `EstadoResultadosHelper` (que a su vez consume EXCLUSIVAMENTE
 * `ContabilidadRepository`). Este controller solo resuelve el usuario autenticado y los parámetros
 * del request — es el único punto del flujo HTTP que puede leer la sesión, justamente para
 * pasarle el `user_id` explícito al helper, que no puede depender de `auth()` porque también corre
 * por cron (`SetCompanyPerformances`).
 */
class EstadoResultadosController extends Controller
{
    /**
     * GET api/reportes/estado-resultados
     *
     * Devuelve la estructura completa del Estado de Resultados devengado del período pedido, en la
     * dimensión de moneda solicitada.
     *
     * @param  Request $request Query params: `desde`, `hasta` (Y-m-d, ambos inclusive), `moneda`
     *                           ('pesos' | 'dolares' | 'consolidado', default 'pesos'), y los
     *                           filtros opcionales por línea: `address_id`, `employee_id`,
     *                           `price_type_id`.
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        // Filtros opcionales por línea (ventas, costo de mercadería, gastos, comisiones): se arman
        // acá y se pasan intactos al helper, que los reenvía tal cual a ContabilidadRepository.
        $filtros_extra = [];

        if ($request->filled('address_id')) {
            $filtros_extra['address_id'] = $request->input('address_id');
        }

        if ($request->filled('employee_id')) {
            $filtros_extra['employee_id'] = $request->input('employee_id');
        }

        if ($request->filled('price_type_id')) {
            $filtros_extra['price_type_id'] = $request->input('price_type_id');
        }

        $estado_resultados = EstadoResultadosHelper::estado_resultados(
            $this->userId(),
            $request->input('desde'),
            $request->input('hasta'),
            $request->input('moneda', 'pesos'),
            $filtros_extra
        );

        return response()->json(['estado_resultados' => $estado_resultados], 200);
    }
}
