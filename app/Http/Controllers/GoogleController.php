<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessArticleBatchImagesJob;
use App\Models\GeocoderCounter;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GoogleController extends Controller
{

    /**
     * Despacha el job de asignación masiva de imágenes a artículos en segundo plano.
     * El resultado se notifica al frontend vía Pusher cuando el job finaliza.
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

        // Se usa la key propia del owner si la tiene configurada; si no, la key de fallback
        // global que viene de config/services.php (.env del servidor). Antes esa fallback estaba
        // hardcodeada aca; el repositorio es publico, prohibido volver a escribir el valor real
        // como default (grupo 220, prompt 02). Si tampoco esta configurada en el .env, queda
        // vacia y el error lo termina devolviendo la propia llamada a la API de Google.
        $google_api_key = ($owner && $owner->google_custom_search_api_key)
            ? $owner->google_custom_search_api_key
            : config('services.google_search.api_key');

        $google_cuota = ($owner && $owner->google_cuota) ? (int) $owner->google_cuota : 10;

        /*
         * El UUID de la corrida se genera ACÁ y no adentro del job, para poder devolvérselo a
         * quien disparó el lote.
         *
         * 🔴 Sin esto el frontend no tiene forma de saber cuál de los eventos que llegan por el
         * canal es el suyo. El canal `article_batch_images.{owner_id}` es PÚBLICO, así que dos
         * instalaciones que compartan el id del owner y la app de Pusher reciben los eventos de
         * la otra: la pestaña acepta el primero que llega, se da de baja del canal y se queda sin
         * el propio. Pasa también con dos lotes seguidos del mismo usuario. Medido el 28/8/2026
         * entre dos instancias de demo, donde el síntoma era "el modal de resumen no aparece
         * nunca" aunque las imágenes se asignaban bien.
         *
         * El campo se AGREGA a la respuesta y no reemplaza nada: un frontend viejo lo ignora y
         * sigue funcionando igual que antes.
         */
        $batch_uuid = (string) Str::uuid();

        ProcessArticleBatchImagesJob::dispatch(
            $request->article_ids,
            (int) $this->userId(),
            $google_api_key,
            'c442e5f346f314951',
            $google_cuota,
            $batch_uuid
        );

        return response()->json([
            'status'     => 'processing',
            'batch_uuid' => $batch_uuid,
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
