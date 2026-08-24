<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\contabilidad\PosicionFiscalHelper;
use Illuminate\Http\Request;

/**
 * Controller de la Posición Fiscal (Grupo 225, Prompt 02): IVA, IIBB y pagos a cuenta de Ganancias.
 *
 * Es aditivo: no reemplaza ningún reporte existente. Toda la lógica de negocio vive en
 * `PosicionFiscalHelper` (que a su vez consume EXCLUSIVAMENTE `ContabilidadRepository`). Este
 * controller solo resuelve el usuario autenticado y los parámetros del request — es el único punto
 * del flujo HTTP que puede leer la sesión, justamente para pasarle el `user_id` explícito al
 * helper, que no puede depender de `auth()` porque también puede terminar corriendo sin sesión
 * (mismo motivo que `EstadoResultadosController`/`EstadoResultadosHelper`, prompt 01).
 *
 * 🔴 Sin parámetro `moneda`: a diferencia del endpoint del prompt 01, acá no se acepta ni se aplica
 * ninguna dimensión de moneda — los seis renglones que arma este reporte son globales y ya vienen
 * en pesos (ver PHPDoc de clase de `PosicionFiscalHelper`).
 */
class PosicionFiscalController extends Controller
{
    /**
     * GET api/reportes/posicion-fiscal
     *
     * Devuelve las tres posiciones fiscales del período pedido, con todos sus renglones
     * desglosados (nunca pre-neteados): posición de IVA, posición de IIBB y pagos a cuenta de
     * Ganancias.
     *
     * @param  Request $request Query params: `desde`, `hasta` (Y-m-d, ambos inclusive).
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        // Usuario autenticado (owner dueño del reporte): único punto de este flujo que puede leer
        // la sesión, se lo pasa explícito al helper.
        $user_id = $this->userId();

        $desde = $request->input('desde');
        $hasta = $request->input('hasta');

        $posicion_fiscal = [
            'posicion_iva'             => PosicionFiscalHelper::posicion_iva($user_id, $desde, $hasta),
            'posicion_iibb'            => PosicionFiscalHelper::posicion_iibb($user_id, $desde, $hasta),
            'pagos_a_cuenta_ganancias' => PosicionFiscalHelper::pagos_a_cuenta_ganancias($user_id, $desde, $hasta),
            'desde'                    => $desde,
            'hasta'                    => $hasta,
        ];

        return response()->json(['posicion_fiscal' => $posicion_fiscal], 200);
    }
}
