<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Prompt 382 - fix. Las 3 columnas nuevas (notificar_pedido_al_negocio,
 * notificar_pedido_al_cliente, avisar_ingreso_stock_por_mail) son NOT NULL con
 * default true, pero algunas filas quedaron en NULL: el controller pisaba el
 * valor con lo que llegara del request, y si el front todavia no tenia la
 * clave (pantalla abierta antes de que se agregara el campo), el request
 * llegaba sin ese valor y el controller lo guardaba como null.
 *
 * El controller ya se corrigio (OnlineConfigurationController@update usa
 * $request->boolean() con fallback al valor actual), esta migracion solo
 * repara los datos que ya quedaron en null.
 */
class FixNullNotificacionesMailInOnlineConfigurationsTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        DB::table('online_configurations')
            ->whereNull('notificar_pedido_al_negocio')
            ->update(['notificar_pedido_al_negocio' => true]);

        DB::table('online_configurations')
            ->whereNull('notificar_pedido_al_cliente')
            ->update(['notificar_pedido_al_cliente' => true]);

        DB::table('online_configurations')
            ->whereNull('avisar_ingreso_stock_por_mail')
            ->update(['avisar_ingreso_stock_por_mail' => true]);
    }

    /**
     * Data fix, no tiene reversa (no tiene sentido volver estos campos a null).
     *
     * @return void
     */
    public function down()
    {
        //
    }
}
