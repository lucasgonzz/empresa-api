<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\ImagenesAutomaticasHelper;
use App\Models\GeocoderCounter;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GoogleController extends Controller
{

    /**
     * Despacha el job de asignación masiva de imágenes a artículos en segundo plano.
     * El resultado se notifica al frontend vía Pusher cuando el job finaliza.
     *
     * Misión asistente-masivas-imagenes-y-remito (19/9/2026): la lógica (clave del owner o de
     * config, cx, cuota, uuid, dispatch) vive en ImagenesAutomaticasHelper::encolar(), que comparte
     * con el asistente; acá queda la validación del request y la respuesta, que es exactamente la
     * de siempre (`{status, batch_uuid}`). Lo único nuevo es que el registro visible del proceso
     * nace `pendiente` al encolar, en vez de recién cuando el worker levanta el job.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    function batch_assign_images(Request $request)
    {
        $request->validate([
            'article_ids'   => 'required|array|min:1',
            'article_ids.*' => 'integer',
        ]);

        $owner = User::find($this->userId());

        // Sin dueño resuelto no hay con qué buscar (ni key, ni cuota): antes del helper tipado.
        if (is_null($owner)) {
            return response()->json(['message' => 'No se pudo identificar la cuenta.'], 422);
        }

        $encolado = ImagenesAutomaticasHelper::encolar($owner, $request->article_ids, $this->userId(false));

        /*
         * El campo `batch_uuid` se AGREGA a la respuesta y no reemplaza nada: un frontend viejo lo
         * ignora y sigue funcionando igual que antes. Ver el porqué del uuid en el helper.
         */
        return response()->json([
            'status'     => 'processing',
            'batch_uuid' => $encolado['batch_uuid'],
        ], 200);
    }

    function aumentar_contador_custom_search() {
        $counter = $this->get_current_acounter();
        $counter->counter += 1;
        $counter->save();
        Log::info('Aumentando busqueda a '.$counter->counter);
        return response()->json(['model'    => $counter]);
    }

    function get_current() {
        $counter = $this->get_current_acounter();
        return response()->json(['model'    => $counter]);
    }

    function get_current_acounter() {

        $counter = GeocoderCounter::where('user_id', $this->userId())
                                    ->whereDate('created_at', Carbon::today())
                                    ->first();

        if (!$counter) {
            $counter = GeocoderCounter::create([
                'counter'   => 0,
                'user_id'   => $this->userId(),
            ]);
        } 

        return $counter;
    }

}
