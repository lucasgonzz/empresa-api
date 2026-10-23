<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\ImagenesAutomaticasHelper;
use App\Models\GeocoderCounter;
use App\Models\User;
use App\Services\ProductFacts\TopeSerperDemo;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

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
     * Desde el 23/10/2026 (pedido de Lucas) los artículos elegidos que ya tienen imagen no se buscan:
     * la respuesta suma `articulos` y `saltados_con_imagen`, y si todos tenían imagen responde 422.
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

        // Misión tope-serper-demos (23/10/2026): una demo sin búsquedas no encola nada. Mismo 422
        // {message} que ya muestra la SPA para este botón.
        if (TopeSerperDemo::agotado($owner)) {
            return response()->json(['message' => TopeSerperDemo::MENSAJE], 422);
        }

        $encolado = ImagenesAutomaticasHelper::encolar($owner, $request->article_ids, $this->userId(false));

        /*
         * 23/10/2026: los que ya tienen imagen no se buscan. Si eran TODOS, no se creó ninguna
         * corrida y se avisa con un 422 con forma de validación de Laravel (`errors.article_ids`):
         * la SPA nueva lo muestra como aviso y la vieja como toast de su interceptor global.
         */
        if (is_null($encolado['batch_uuid'])) {
            $con_imagen = (int) $encolado['saltados_con_imagen'];

            throw ValidationException::withMessages([
                'article_ids' => $con_imagen === 1
                    ? 'El artículo elegido ya tiene imagen: no se buscó nada.'
                    : 'Los '.$con_imagen.' artículos elegidos ya tienen imagen: no se buscó nada.',
            ]);
        }

        /*
         * Los campos `batch_uuid`, `articulos` y `saltados_con_imagen` se AGREGAN a la respuesta y no
         * reemplazan nada: un frontend viejo los ignora y sigue funcionando igual que antes. Ver el
         * porqué del uuid en el helper.
         */
        return response()->json([
            'status'              => 'processing',
            'batch_uuid'          => $encolado['batch_uuid'],
            'articulos'           => (int) $encolado['articulos'],
            'saltados_con_imagen' => (int) $encolado['saltados_con_imagen'],
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
