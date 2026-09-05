<?php

namespace App\Http\Controllers\Helpers\sale;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\puntos\PuntosAcumulacionHelper;
use App\Http\Controllers\Helpers\puntos\PuntosCanjeHelper;
use App\Models\Article;
use App\Models\ConceptoStockMovement;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\Sale;
use App\Models\SellerCommission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Revierte los efectos colaterales de SaleController::destroy al restaurar una venta desde la papelera.
 *
 * Orden alineado con el destroy (al revés del último paso hacia el primero en cuanto a stock):
 * stock vuelve a descontarse, ArticlePurchase, Cuenta Corriente (si aplicaba su eliminación), comisiones,
 * y recalculo de saldos del cliente.
 */
class RestoreSaleFromPapeleraHelper {

    /**
     * Ejecuta la restauración funcional de una venta ya revivida (deleted_at en null).
     *
     * @param Sale $sale Instancia persistida de la venta restaurada.
     * @return void
     */
    public static function run(Sale $sale) {

        // Relaciones usadas por stock, compras de artículos, comisiones y reglas de negocio.
        $sale->load([
            'articles',
            'combos.articles',
            'promocion_vinotecas',
            'nota_credito_afip_tickets',
            'user',
            'client',
        ]);

        /*
         * ─────────────────────────────────────────────────────────────────────────────
         *  🔴 PUNTOS PARA CLIENTES: EL CANJE VA PRIMERO, ANTES DE TOCAR LA CUENTA CORRIENTE.
         * ─────────────────────────────────────────────────────────────────────────────
         *
         *  `SaleController@destroy` deshace los DOS lados del módulo (el canje y los puntos
         *  ganados) y hasta el 22/8/2026 este helper no mencionaba ninguno. Una venta de
         *  cuenta corriente zafaba de rebote —recrear el débito dispara checkPagos y el
         *  reconciliador cuelga de ahí—, pero una venta de MOSTRADOR no pasa por ninguna
         *  cuenta corriente: volvía de la papelera con el total descontado por un canje que ya
         *  no existía y sin ningún lote de puntos. Depender del rebote es depender de un
         *  camino que la mitad de las ventas no recorre.
         *
         *  `restaurar()` va acá arriba, y no al final con el resto del módulo, porque puede
         *  CORREGIR `sales.total` (cuando el cliente ya no tiene saldo para re-canjear) y el
         *  débito de cuenta corriente que se crea unas líneas más abajo se arma con ese total.
         */
        PuntosCanjeHelper::restaurar($sale);

        self::descontar_stock_tras_restaurar($sale);

        // Misma condición que al generar compras al confirmar/guardar venta (no depósito).
        if (!$sale->to_check && !$sale->checked) {

            $article_purchase_helper = new ArticlePurchaseHelper();
            $article_purchase_helper->set_article_purcase($sale);
        }

        self::restaurar_cuenta_corriente_si_corresponde($sale);

        // Necesario para comisiones (p. ej. Fenix) que leen la relación current_acount.
        $sale->unsetRelation('current_acount');
        $sale->load('current_acount');

        self::restaurar_comisiones_si_corresponde($sale);

        self::recalcular_saldos_cliente_si_corresponde($sale);

        /*
         * Y los puntos GANADOS: la venta vuelve a existir, así que vuelve a otorgar lo que le
         * corresponda. `destroy` había anulado su lote y escrito el 'revertidos'; el
         * reconciliador revive esa misma fila y borra el reverso (el unique de
         * `movimiento_puntos` no deja crear una segunda).
         *
         * 🔴 VA AL FINAL, después de que la cuenta corriente esté recreada: una venta de
         * cuenta corriente solo acumula cuando su débito está saldado, y preguntarlo antes de
         * que el débito exista daría "no corresponde". Es idempotente, así que no molesta que
         * el rebote de `recalcular_saldos_cliente_si_corresponde()` ya lo haya hecho: lo que
         * agrega es cubrir la venta de mostrador, que no rebota por ningún lado.
         */
        PuntosAcumulacionHelper::reconciliar_venta($sale);
    }

    /**
     * Vuelve a sacar del stock lo que el borrado había repuesto: la inversa exacta de
     * DeleteSaleHelper::regresar_stock().
     *
     * 🔴 Desde la auditoría de stock (5/9/2026) el borrado repone según el libro de movimientos de
     * la venta (lo que de verdad se descontó), y la restauración deshace ESO: por cada
     * (artículo, variante) se suma lo que los "Se elimino la venta" de esta venta devolvieron y
     * se vuelve a descontar con un "Venta". Así una venta con una devolución sin `returned_amount`
     * (una NC de Devoluciones sin "actualizar unidades devueltas") no vuelve a descontar lo que ya
     * había devuelto, y una venta borrada dos veces por un doble clic vuelve a dejar el libro en
     * su neto original.
     *
     * Si la venta no tiene ningún "Se elimino la venta" (se borró antes de que el borrado dejara
     * movimientos), se cae al criterio de antes: descontar los renglones de la venta, netos de lo
     * devuelto por NC.
     *
     * @param Sale $sale Venta con artículos/combos/promos cargados.
     * @return void
     */
    public static function descontar_stock_tras_restaurar(Sale $sale) {

        $concepto = ConceptoStockMovement::where('name', 'Se elimino la venta')->first();

        $devueltos = collect();

        if (!is_null($concepto)) {

            /*
                Solo el ULTIMO borrado: los "Se elimino la venta" posteriores al ultimo movimiento
                de cualquier otro concepto de la venta. Una venta que ya fue borrada y restaurada
                antes tiene en su libro el ciclo anterior completo (Venta, Se elimino, Venta de la
                restauracion); sumar todos los "Se elimino la venta" de su historia descontaria de
                mas en cada ciclo nuevo.
            */
            $ultimo_de_otro_concepto = (int) DB::table('stock_movements')
                                            ->where('sale_id', $sale->id)
                                            ->where('concepto_stock_movement_id', '<>', $concepto->id)
                                            ->max('id');

            $devueltos = DB::table('stock_movements')
                            ->select('article_id', DB::raw('COALESCE(NULLIF(article_variant_id, 0), NULL) AS article_variant_id'), DB::raw('SUM(amount) AS devuelto'))
                            ->where('sale_id', $sale->id)
                            ->where('concepto_stock_movement_id', $concepto->id)
                            ->where('id', '>', $ultimo_de_otro_concepto)
                            ->whereNotNull('article_id')
                            ->groupBy('article_id', DB::raw('COALESCE(NULLIF(article_variant_id, 0), NULL)'))
                            ->havingRaw('ABS(SUM(amount)) > 0.0001')
                            ->get();
        }

        if ($devueltos->count() > 0) {

            foreach ($devueltos as $renglon) {

                $article = Article::find($renglon->article_id);

                if (is_null($article)) {
                    continue;
                }

                ArticleHelper::storeStockMovement(
                    $article,
                    $sale->id,
                    -(float) $renglon->devuelto,
                    $sale->address_id,
                    null,
                    'Venta',
                    $renglon->article_variant_id
                );
            }

        } else if (!$sale->to_check && !$sale->checked && $sale->discount_stock) {

            foreach ($sale->articles as $article) {

                if (!is_null($article->stock)) {

                    // Cantidad vendida neta: se restan unidades ya devueltas vía nota de crédito.
                    $amount = (float) $article->pivot->amount;
                    $amount -= (float) DeleteSaleHelper::get_unidades_ya_devueltas_en_nota_de_credito($sale, $article);

                    if ($amount != 0) {
                        ArticleHelper::storeStockMovement(
                            $article,
                            $sale->id,
                            -$amount,
                            $sale->address_id,
                            null,
                            'Venta',
                            $article->pivot->article_variant_id
                        );
                    }
                }
            }

            foreach ($sale->combos as $combo) {

                foreach ($combo->articles as $article) {

                    if (!is_null($article->stock)) {

                        $amount = (float) $combo->pivot->amount * (float) $article->pivot->amount;

                        if ($amount != 0) {
                            ArticleHelper::storeStockMovement(
                                $article,
                                $sale->id,
                                -$amount,
                                $sale->address_id,
                                null,
                                'Venta',
                                $article->pivot->article_variant_id
                            );
                        }
                    }
                }
            }
        }

        // El stock de una promoción no pasa por stock_movements: se sigue descontando por su renglón.
        if (!$sale->to_check && !$sale->checked && $sale->discount_stock) {

            foreach ($sale->promocion_vinotecas as $promocion_vinoteca) {

                $promocion_vinoteca->stock -= (float) $promocion_vinoteca->pivot->amount;
                $promocion_vinoteca->save();
            }
        }
    }

    /**
     * Recrea el movimiento de debe en cuenta corriente solo si destroy lo habría eliminado.
     *
     * No recrea si hay notas de crédito AFIP (en destroy no se borraba la C/C en ese caso).
     * Si ya existe un registro debe para la venta, no duplica.
     *
     * @param Sale $sale Venta restaurada.
     * @return void
     */
    public static function restaurar_cuenta_corriente_si_corresponde(Sale $sale) {

        if (is_null($sale->client_id)) {
            return;
        }

        // En destroy la C/C de la venta no se eliminaba si había NC AFIP vinculadas.
        if (count($sale->nota_credito_afip_tickets) > 0) {
            return;
        }

        $existing = CurrentAcount::where('sale_id', $sale->id)
            ->whereNull('haber')
            ->first();

        if (!is_null($existing)) {
            return;
        }

        if ($sale->save_current_acount && !$sale->omitir_en_cuenta_corriente) {
            SaleHelper::create_current_acount($sale);
        }
    }

    /**
     * Vuelve a generar comisiones de vendedor si destroy las había borrado (solo cuando hay cliente).
     *
     * Evita duplicar si ya existen líneas debe activas para la venta.
     *
     * @param Sale $sale Venta con user y current_acount (si aplica) cargados.
     * @return void
     */
    public static function restaurar_comisiones_si_corresponde(Sale $sale) {

        if (is_null($sale->client_id)) {
            return;
        }

        $hay_comision_debe = SellerCommission::where('sale_id', $sale->id)
            ->whereNull('haber')
            ->exists();

        if ($hay_comision_debe) {
            return;
        }

        SaleHelper::crear_comision($sale);
    }

    /**
     * Replica el chequeo posterior al destroy: saldos y pagos del credit account del cliente.
     *
     * @param Sale $sale Venta con client cargado.
     * @return void
     */
    public static function recalcular_saldos_cliente_si_corresponde(Sale $sale) {

        if (is_null($sale->client_id)) {
            return;
        }

        if (!is_null($sale->client->deleted_at)) {
            Log::info('RestoreSaleFromPapeleraHelper: cliente eliminado, se omite check_saldos_y_pagos para venta '.$sale->id);
            return;
        }

        $credit_account = CreditAccount::where('model_name', 'client')
            ->where('model_id', $sale->client_id)
            ->where('moneda_id', $sale->moneda_id)
            ->first();

        if (!is_null($credit_account)) {
            CurrentAcountHelper::check_saldos_y_pagos($credit_account->id);
        }
    }
}
