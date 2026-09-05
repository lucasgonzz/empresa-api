<?php

namespace App\Http\Controllers\Stock\SetArticleStock;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckToAddress {

    /**
     * Conceptos que SI pueden abrir el primer deposito de un articulo que hasta ahora llevaba el
     * stock en `articles.stock`: son los caminos donde el usuario esta repartiendo su stock global
     * en depositos a proposito (el modal de crear depositos, la edicion de stock por sucursal, el
     * Excel con columnas por sucursal, los traslados). Cualquier otro concepto que llegue con
     * `to_address_id` sobre un articulo sin depositos se aplica al stock global.
     */
    const CONCEPTOS_QUE_REPARTEN = [
        'Creacion de deposito',
        'Actualizacion de deposito',
        'Importacion de excel',
        'Mov entre depositos',
        'Mov manual entre depositos',
    ];

    /*
        *   Aca se aumenta el stock del deposito destino
            En caso de que sea:

            * Modificacion de stock (desde el modal de article)

            * Pedido de proveedor
            * Actualizacion Pedido de proveedor

            * Movimiento de depositos

            * Importacion de excel

            * Actualizacion de venta
            * Se elimino de una venta
            * Se elimino la venta

            * Nota de credito

            * Ingreso manual

            * Creacion de deposito
            * Actualizacion de deposito
            * Mov entre depositos

            * Produccion
    */
    static function check_to_address($stock_movement, $article) {

        if (
            is_null($stock_movement->to_address_id)
            || $stock_movement->to_address_id == 0
        ) {
            return;
        }

        /*
            Un movimiento de VARIANTE no toca el pivot del articulo: el deposito de la variante lo
            lleva CheckVariants, y despues ArticleHelper::setArticleStockFromAddresses() reconstruye
            los depositos del articulo sumando los de sus variantes. Tocarlo aca era trabajo que se
            pisaba solo.
        */
        if (
            !is_null($stock_movement->article_variant_id)
            && $stock_movement->article_variant_id != 0
        ) {
            return;
        }

        $article->load('addresses');

        $to_address = null;

        foreach ($article->addresses as $address) {
            if ($address->id == $stock_movement->to_address_id) {
                $to_address = $address;
            }
        }

        if (!is_null($to_address)) {

            Self::sumar_al_deposito($article, $stock_movement->to_address_id, (float)$stock_movement->amount);

            return;
        }

        /*
            🔴 LA GUARDA QUE FALTABA (auditoria de stock, 5/9/2026).

            Hasta aca, cualquier movimiento con `to_address_id` le ATTACHEABA el deposito al articulo
            aunque el articulo nunca hubiera repartido su stock por depositos. El caso que lo hacia
            visible: un articulo con stock GLOBAL de 20, se vende 1 (CheckGlobalStock baja a 19,
            camino correcto) y se borra la venta. La devolucion viene con `to_address_id` = la
            sucursal de la venta, se creaba el pivot con amount 1, y desde ese momento el articulo
            "tiene depositos": CheckGlobalStock deja de aplicar y setArticleStockFromAddresses PISA el
            stock global con la suma de depositos. 20 -> 19 -> 1. Se perdian 18 unidades en silencio.
            Lo mismo pasaba con la nota de credito desde Vender y con cualquier ingreso manual que
            eligiera deposito.

            La regla: el primer deposito de un articulo se abre solo si (a) el articulo no tiene stock
            global que perder, o (b) el concepto es uno de los que reparten stock a proposito. En
            cualquier otro caso el movimiento se aplica al stock global (CheckGlobalStock, que corre
            despues y solo actua cuando el articulo no tiene depositos).
        */
        if (!Self::puede_abrir_el_primer_deposito($stock_movement, $article)) {

            Log::info('CheckToAddress: el articulo '.$article->id.' lleva stock global ('.$article->stock.') y el concepto no reparte stock: el movimiento '.$stock_movement->id.' se aplica al stock global, no se le abre el deposito '.$stock_movement->to_address_id);

            return;
        }

        Log::info('Agregando address_id: '.$stock_movement->to_address_id.' al articulo '.$article->name);

        $article->addresses()->attach($stock_movement->to_address_id, [
            'amount'    => $stock_movement->amount,
        ]);
    }

    /**
     * Si a este articulo se le puede abrir el primer deposito con este movimiento.
     *
     * @param  \App\Models\StockMovement  $stock_movement
     * @param  \App\Models\Article        $article         Con `addresses` ya cargadas.
     * @return bool
     */
    static function puede_abrir_el_primer_deposito($stock_movement, $article) {

        // Ya reparte por depositos: uno nuevo es un deposito mas.
        if (count($article->addresses) >= 1) {
            return true;
        }

        // Sin stock global no hay nada que pisar: el deposito nace con lo que entra.
        if (is_null($article->stock) || (float)$article->stock == 0) {
            return true;
        }

        $concepto = $stock_movement->concepto_movement;

        if (is_null($concepto)) {
            return false;
        }

        return in_array($concepto->name, Self::CONCEPTOS_QUE_REPARTEN);
    }

    /**
     * Suma (o resta) sobre el pivot del deposito en UNA sola sentencia SQL.
     *
     * Antes se leia `pivot->amount`, se sumaba en PHP y se escribia el resultado: dos ventas del
     * mismo articulo en el mismo segundo leian el mismo valor y una pisaba a la otra (la clase de
     * error "lost update"). Con el incremento en SQL cada movimiento suma sobre lo que haya en la
     * fila en ese instante, sin importar quien llego antes.
     *
     * @param  \App\Models\Article  $article
     * @param  int                  $address_id
     * @param  float                $delta
     * @return void
     */
    static function sumar_al_deposito($article, $address_id, $delta) {

        DB::table('address_article')
            ->where('article_id', $article->id)
            ->where('address_id', $address_id)
            ->update([
                'amount'     => DB::raw('COALESCE(amount, 0) + ('.sprintf('%.4F', $delta).')'),
                'updated_at' => now(),
            ]);
    }

}
