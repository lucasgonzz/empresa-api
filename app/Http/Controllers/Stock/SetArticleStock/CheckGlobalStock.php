<?php

namespace App\Http\Controllers\Stock\SetArticleStock;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckGlobalStock {


    static function check_global_stock($stock_movement, $article, $set_updated_at) {

        if (is_null($article->stock)) {
            $article->stock = 0;
            $article->save();
        }

        $article->load('addresses');

        if (
            !is_null($article->stock)
            && count($article->addresses) == 0
            && count($article->article_variants) == 0
        ) {

            Log::info('Se va a sumar global stock: '.$stock_movement->amount);

            /*
                Se aumenta el stock del articulo con la amount del stock_movement
                Ya que, si es una venta, amount va a ser negativo.

                🔴 La suma la hace el SQL (`stock = stock + amount`), no PHP. Antes se leia
                `$article->stock`, se sumaba y se guardaba: dos ventas del mismo articulo en el
                mismo instante leian el mismo stock y la segunda pisaba a la primera (con 10 en
                stock, vender 2 y 3 a la vez dejaba 7 u 8, nunca 5). Con el incremento en SQL cada
                movimiento suma sobre lo que haya en la fila en ese momento.
            */
            $cambios = [
                'stock' => DB::raw('COALESCE(stock, 0) + ('.sprintf('%.4F', (float)$stock_movement->amount).')'),
            ];

            // Mismo comportamiento que el save() de antes: solo la importacion de Excel
            // ($set_updated_at) toca el updated_at del articulo; una venta no.
            if ($set_updated_at) {
                $cambios['updated_at'] = Carbon::now();
            }

            DB::table('articles')
                ->where('id', $article->id)
                ->update($cambios);

            // El modelo en memoria sigue en uso mas adelante (avisos, carritos): se lo deja al dia.
            $article->stock = DB::table('articles')->where('id', $article->id)->value('stock');

            // if (!isset($request->from_excel_import) || !$request->from_excel_import) {
            //     $ct = new InventoryLinkageHelper(null, $user_id);
            //     $ct->check_is_agotado($article);
            // }

        }
    }


}
