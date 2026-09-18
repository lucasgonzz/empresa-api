<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El token de transferencia de sesión entre versiones (ver create_version_session_token() en
 * AuthController) solo guardaba el user_id. Un login maestro que dispara la redirección de
 * versión llegaba al otro lado como una sesión cualquiera del usuario real: tomaba su candado de
 * sesión única y disparaba la descarga de artículos offline. Estas dos columnas dejan que
 * login_from_version_session_token() reproduzca en la API destino el mismo estado que tenía la
 * sesión de origen.
 */
class AddMasterLoginStateToVersionSessionTransfersTable extends Migration
{
    /**
     * Agrega las columnas de estado de login maestro.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('version_session_transfers', function (Blueprint $table) {
            /** Espeja `master_login_bypass_user_last_activity` de la sesión de origen. */
            $table->boolean('master_login_bypass')->default(false)->after('user_id');
            /** Espeja `skip_offline_articles_sync` de la sesión de origen. */
            $table->boolean('skip_offline_articles_sync')->default(false)->after('master_login_bypass');
        });
    }

    /**
     * Elimina las columnas agregadas.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('version_session_transfers', function (Blueprint $table) {
            $table->dropColumn(['master_login_bypass', 'skip_offline_articles_sync']);
        });
    }
}
