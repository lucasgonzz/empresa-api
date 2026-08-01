<?php

namespace App\Http\Controllers\Stock\SetArticleStock;

use App\Http\Controllers\Helpers\CartArticleAmountInsificienteHelper;
use Illuminate\Support\Facades\Log;

class CheckCartAmount {

    /**
     * Verifica si hay carritos con cantidad insuficiente luego de un movimiento de stock.
     *
     * El chequeo se saltea a proposito cuando el movimiento es entre depositos o una
     * importacion de excel, porque en esos casos el stock no se consumio realmente y
     * notificar al cliente seria un falso positivo.
     *
     * @param  \App\Models\StockMovement  $stock_movement  Movimiento de stock recien guardado.
     * @param  \App\Models\Article        $article         Articulo afectado por el movimiento.
     * @return void
     */
    static function check_cart_amount($stock_movement, $article) {

        // Relacion con el concepto del movimiento. Puede venir null cuando el concepto no
        // se pudo resolver (ver SetConcepto): la tabla de conceptos puede estar vacia o el
        // nombre buscado puede no existir en la base del cliente.
        $concepto_movement = $stock_movement->concepto_movement;

        if (is_null($concepto_movement)) {

            // Decision conservadora: sin concepto no podemos saber si el movimiento es uno de
            // los casos excluidos, y correr el chequeo igual podria mandarle a clientes reales
            // notificaciones de stock insuficiente por una importacion. Se saltea y se loguea.
            Log::warning('CheckCartAmount: stock_movement sin concepto, se saltea el chequeo de carritos. stock_movement_id: '.$stock_movement->id.' concepto_stock_movement_id: '.$stock_movement->concepto_stock_movement_id);
            return;
        }

        $concepto = $concepto_movement->name;

        if (
            $concepto != 'Mov entre depositos'
            && $concepto != 'Mov manual entre depositos' 
            && $concepto != 'Importacion de excel' 
        ) {
            CartArticleAmountInsificienteHelper::checkCartsAmounts($article);
        }
    }


}