<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\PotencialDeArmadoHelper;

/**
 * Pantalla "Potencial de armado" de ProduccionV2.
 *
 * Es de SOLO LECTURA: no escribe stock, no crea ningun StockMovement y no toca el modulo de
 * ventas. Ver el encabezado de PotencialDeArmadoHelper para el criterio de calculo y por que
 * es de un solo nivel.
 */
class PotencialDeArmadoController extends Controller
{
    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $models = PotencialDeArmadoHelper::calcular($this->userId());

        return response()->json(['models' => $models], 200);
    }
}
