<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\PriceUpdateRun;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Expone los proveedores de una corrida de recálculo de precios.
 *
 * El broadcast lleva sólo números (el límite de 10 KB de Pusher no da para una lista de
 * largo impredecible), así que los nombres los pide el modal por acá cuando se abre. Mismo
 * patrón que /import-history/repeated-code-articles/{id}.
 */
class PriceUpdateRunController extends Controller
{
    /**
     * Nombres de los proveedores cuyos artículos cambiaron de precio en la corrida.
     *
     * Devuelve la lista COMPLETA, sin tope. El tope de 50 que tenía antes convivía con un
     * total aparte, y el modal prometía "ver los 80" para después mostrar 50 sin ninguna
     * señal de que faltaran: dos números que podían discrepar es peor que uno solo.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function desglose(Request $request, $id)
    {
        $run = PriceUpdateRun::find($id);

        /*
         * 🔴 Pertenencia al usuario autenticado. Esto es multi-tenant: una corrida ajena
         * filtra la composición del catálogo de otro comercio (qué proveedores tiene). Se
         * responde 404 y no 403 a propósito: un 403 ya confirma que la corrida existe.
         */
        if (is_null($run) || (int) $run->user_id !== (int) UserHelper::userId()) {
            return response()->json([
                'message' => 'No se encontro la actualizacion de precios',
            ], 404);
        }

        $filas = DB::table('price_update_run_articles')
            ->join('articles', 'articles.id', '=', 'price_update_run_articles.article_id')
            ->leftJoin('providers', 'providers.id', '=', DB::raw('articles.provider_id'))
            ->where('price_update_run_articles.price_update_run_id', $run->id)
            ->select(DB::raw('providers.name as nombre'))
            ->groupBy(DB::raw('articles.provider_id'), DB::raw('providers.name'))
            ->get();

        $proveedores = [];

        foreach ($filas as $fila) {
            $nombre = $fila->nombre;

            /* Los artículos sin proveedor son un grupo más: si se descartaran, la lista no
               cerraría con el número que el modal ya mostró y se lee como un error. */
            if (is_null($nombre) || $nombre === '') {
                $nombre = 'Sin proveedor';
            }

            $proveedores[] = $nombre;
        }

        /* Alfabético: la lista es para buscar un nombre, no para ver quién tuvo más. */
        sort($proveedores, SORT_NATURAL | SORT_FLAG_CASE);

        return response()->json([
            'proveedores' => $proveedores,
        ]);
    }
}
