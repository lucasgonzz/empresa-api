<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\PriceUpdateRun;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Expone el desglose completo de una corrida de recálculo de precios.
 *
 * El broadcast sólo lleva los grupos más grandes (el límite de 10 KB de Pusher no da para
 * más); cuando el usuario aprieta "Ver los N restantes", el modal viene acá. Mismo patrón
 * que /import-history/repeated-code-articles/{id}.
 */
class PriceUpdateRunController extends Controller
{
    /**
     * Desglose por proveedor o por categoría de una corrida.
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
         * filtra la composición del catálogo de otro comercio (qué proveedores tiene y con
         * cuántos artículos cada uno). Se responde 404 y no 403 a propósito: un 403 ya
         * confirma que la corrida existe.
         */
        if (is_null($run) || (int) $run->user_id !== (int) UserHelper::userId()) {
            return response()->json([
                'message' => 'No se encontro la actualizacion de precios',
            ], 404);
        }

        $tipo = $request->tipo == 'category' ? 'category' : 'provider';

        /** Tope de filas; el default acompaña al que ya usa el modal de importación. */
        $limit = (int) $request->limit;
        if ($limit <= 0 || $limit > 200) {
            $limit = 50;
        }

        $es_proveedor      = $tipo == 'provider';
        $tabla_relacionada = $es_proveedor ? 'providers' : 'categories';
        $columna_fk        = $es_proveedor ? 'articles.provider_id' : 'articles.category_id';
        $sin_nombre        = $es_proveedor ? 'Sin proveedor' : 'Sin categoría';

        $query = DB::table('price_update_run_articles')
            ->join('articles', 'articles.id', '=', 'price_update_run_articles.article_id')
            ->leftJoin($tabla_relacionada, $tabla_relacionada . '.id', '=', DB::raw($columna_fk))
            ->where('price_update_run_articles.price_update_run_id', $run->id)
            ->select(
                DB::raw($tabla_relacionada . '.name as nombre'),
                DB::raw('COUNT(*) as cantidad')
            )
            ->groupBy(DB::raw($columna_fk), DB::raw($tabla_relacionada . '.name'))
            ->orderBy('cantidad', 'DESC');

        /* El total es la cantidad de GRUPOS, para que el modal sepa si mostró todos. */
        $total = DB::table(DB::raw('(' . $query->toSql() . ') as grupos'))
            ->mergeBindings($query)
            ->count();

        $items = [];

        foreach ($query->limit($limit)->get() as $fila) {
            $nombre = $fila->nombre;

            if (is_null($nombre) || $nombre === '') {
                $nombre = $sin_nombre;
            }

            $items[] = [
                'nombre'   => $nombre,
                'cantidad' => (int) $fila->cantidad,
            ];
        }

        return response()->json([
            'items' => $items,
            'total' => $total,
        ]);
    }
}
