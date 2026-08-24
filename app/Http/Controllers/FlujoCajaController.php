<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\contabilidad\FlujoCajaHelper;
use Illuminate\Http\Request;

/**
 * Controller del Flujo de Caja PERCIBIDO (Grupo 226, Prompt 01).
 *
 * Es aditivo: no reemplaza ningún reporte existente. Toda la lógica de negocio vive en
 * `FlujoCajaHelper` (que a su vez consume EXCLUSIVAMENTE `ContabilidadRepository` para las
 * líneas por moneda). Este controller solo resuelve el usuario autenticado y los parámetros del
 * request — es el único punto del flujo HTTP que puede leer la sesión, justamente para pasarle el
 * `user_id` explícito al helper (mismo criterio que `EstadoResultadosController`/
 * `PosicionFiscalController`, cron-safe).
 */
class FlujoCajaController extends Controller
{
    /**
     * GET api/reportes/flujo-caja
     *
     * Devuelve la estructura completa del Flujo de Caja percibido del período pedido: ingresos y
     * egresos con su desglose por caja y método de pago, el flujo neto, y la sección aparte de
     * "plata en tránsito" (liquidaciones pendientes + cheques diferidos, que no suma al neto).
     *
     * @param  Request $request Query params: `desde`, `hasta` (Y-m-d, ambos inclusive), `moneda`
     *                           ('pesos' | 'dolares' | 'consolidado', default 'pesos'), y los
     *                           filtros opcionales `caja_id`, `address_id`.
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        // Filtros opcionales: se arman acá y se pasan intactos al helper, que los reenvía tal
        // cual a ContabilidadRepository (para las líneas por moneda) y a sus propias queries de
        // "plata en tránsito" (liquidaciones/cheques).
        $filtros_extra = [];

        if ($request->filled('caja_id')) {
            $filtros_extra['caja_id'] = $request->input('caja_id');
        }

        if ($request->filled('address_id')) {
            $filtros_extra['address_id'] = $request->input('address_id');
        }

        $flujo_caja = FlujoCajaHelper::flujo_caja(
            $this->userId(),
            $request->input('desde'),
            $request->input('hasta'),
            $request->input('moneda', 'pesos'),
            $filtros_extra
        );

        return response()->json(['flujo_caja' => $flujo_caja], 200);
    }
}
