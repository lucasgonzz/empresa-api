<?php

namespace App\Http\Controllers\Helpers\sale;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\puntos\PuntosAcumulacionHelper;
use App\Http\Controllers\Helpers\puntos\PuntosCanjeHelper;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\Sale;
use App\Models\SellerCommission;
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
     * Descuenta nuevamente stock, simbológica inversa de DeleteSaleHelper::regresar_stock.
     *
     * Usa movimientos con concepto "Venta" y signo negativo como en el flujo normal de venta.
     *
     * @param Sale $sale Venta con artículos/combos/promos cargados.
     * @return void
     */
    public static function descontar_stock_tras_restaurar(Sale $sale) {

        if (!$sale->to_check && !$sale->checked && $sale->discount_stock) {

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
