<?php

namespace App\Http\Controllers\CommonLaravel;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\MasiveUpdateHelper;
use Illuminate\Http\Request;

class UpdateController extends Controller
{
    /**
     * Encola una actualización masiva y registra criterios para historial y reversión.
     *
     * Misión asistente-masivas-imagenes-y-remito (19/9/2026): el cuerpo vive en
     * MasiveUpdateHelper::encolar_actualizacion(), que comparte con el asistente de IA. Acá solo se
     * traduce el request y se devuelve lo que el helper decidió: las respuestas (200 con
     * `queued_count`, los 422 con su `message`) son exactamente las de siempre.
     *
     * @param \Illuminate\Http\Request $request
     * @param string $model_name
     * @return \Illuminate\Http\JsonResponse
     */
    function update(Request $request, $model_name) {

        $resultado = MasiveUpdateHelper::encolar_actualizacion(
            $model_name,
            (boolean) $request->from_filter,
            $request->filter_form,
            $request->update_form,
            $request->models_id,
            $this->userId(true),
            $this->userId(false),
            null,
            null,
            // Freno de negocio de la pantalla (misión masiva-sin-tope-confirmar-75): el indicador es
            // opt-in para que una pantalla vieja, que no sabe mostrar la confirmación, no se bloquee.
            [
                'check_inventory_percentage' => $request->boolean('check_inventory_percentage'),
                'confirmed_massive'          => $request->boolean('confirmed_massive'),
            ]
        );

        return response()->json($resultado['body'], $resultado['status']);
    }
}
