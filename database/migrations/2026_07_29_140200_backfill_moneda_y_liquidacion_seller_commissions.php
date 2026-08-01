<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Grupo 268 · Prompt 01 — backfill de moneda_id y liquidada_at sobre seller_commissions ya
 * existentes. Migracion de datos pura (sin Schema), idempotente: correrla dos veces no cambia
 * nada la segunda vez porque las dos actualizaciones solo tocan filas con el campo en null.
 *
 * Todo por UPDATE...JOIN en SQL, sin cargar filas en PHP: hay clientes con muchos miles de
 * comisiones.
 */
class BackfillMonedaYLiquidacionSellerCommissions extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // moneda_id — filas con sale_id: heredan sales.moneda_id, o 1 si esa venta no tiene
        // moneda cargada (null o 0). Idempotente: solo toca moneda_id IS NULL.
        DB::statement("
            UPDATE seller_commissions sc
            INNER JOIN sales s ON s.id = sc.sale_id
            SET sc.moneda_id = IF(s.moneda_id IS NULL OR s.moneda_id = 0, 1, s.moneda_id)
            WHERE sc.sale_id IS NOT NULL
            AND sc.moneda_id IS NULL
        ");

        // moneda_id — filas sin sale_id (pagos al vendedor, saldos iniciales historicos): pesos.
        DB::statement("
            UPDATE seller_commissions
            SET moneda_id = 1
            WHERE sale_id IS NULL
            AND moneda_id IS NULL
        ");

        // moneda_id — resguardo: filas con sale_id que apunta a una venta que ya no existe (venta
        // borrada dura). El primer UPDATE no las alcanza (el INNER JOIN contra sales las excluye)
        // y sin este resguardo quedarian con moneda_id null para siempre, aunque el read-path las
        // interprete como pesos por convencion. Se completan explicitamente para no dejarlas
        // afuera de un agrupamiento por moneda_id real en el prompt 02.
        DB::statement("
            UPDATE seller_commissions
            SET moneda_id = 1
            WHERE moneda_id IS NULL
        ");

        // liquidada_at — solo filas active sin liquidar todavia. Para las que tienen sale_id, se
        // busca el maximo created_at de las imputaciones (pagado_por.debe_id) de los
        // current_acounts de esa venta. Sin imputaciones, o sin sale_id, cae siempre al
        // created_at de la propia comision (COALESCE lo garantiza para toda fila, nunca queda
        // liquidada_at nula en una fila active).
        DB::statement("
            UPDATE seller_commissions sc
            LEFT JOIN (
                SELECT ca.sale_id AS sale_id, MAX(pp.created_at) AS max_pagado_at
                FROM current_acounts ca
                INNER JOIN pagado_por pp ON pp.debe_id = ca.id
                WHERE ca.sale_id IS NOT NULL
                GROUP BY ca.sale_id
            ) liq ON liq.sale_id = sc.sale_id
            SET sc.liquidada_at = COALESCE(liq.max_pagado_at, sc.created_at)
            WHERE sc.status = 'active'
            AND sc.liquidada_at IS NULL
        ");
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        //
    }
}
