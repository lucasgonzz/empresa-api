<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El token de transferencia de sesión entre versiones no decía si la sesión de origen era de una demo.
 * La sesión destino arrancaba sin el marcador `demo_ingreso_token_id`, y sin marcador
 * `DemoSessionVigente` no mira nada: revocar el token ya no la cortaba (medido el 3/11/2026, misión
 * vigencia-token-demo-rojos). Nullable y sin FK: una fila de un cliente real la deja en null.
 */
class AddDemoIngresoTokenIdToVersionSessionTransfersTable extends Migration
{
    /**
     * Agrega la columna del marcador de demo.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('version_session_transfers', function (Blueprint $table) {
            /** Espeja `demo_ingreso_token_id` de la sesión de origen. */
            $table->unsignedBigInteger('demo_ingreso_token_id')->nullable()->after('skip_offline_articles_sync');
        });
    }

    /**
     * Elimina la columna agregada.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('version_session_transfers', function (Blueprint $table) {
            $table->dropColumn('demo_ingreso_token_id');
        });
    }
}
