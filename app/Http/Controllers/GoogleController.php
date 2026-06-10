<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessArticleBatchImagesJob;
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

        $google_api_key = ($owner && $owner->google_custom_search_api_key)
            ? $owner->google_custom_search_api_key
            : 'AIzaSyC4sUC-MuEDsMNoIQqwUPmYWZmw74rsHOI';

        $google_cuota = ($owner && $owner->google_cuota) ? (int) $owner->google_cuota : 10;

        ProcessArticleBatchImagesJob::dispatch(
            $request->article_ids,
            (int) $this->userId(),
            $google_api_key,
            'c442e5f346f314951',
            $google_cuota
        );

        return response()->json(['status' => 'processing'], 200);
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
