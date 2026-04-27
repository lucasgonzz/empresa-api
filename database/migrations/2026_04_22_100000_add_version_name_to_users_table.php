<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Almacena la etiqueta de versión del sistema (p. ej. 1.0.4) informada al sincronizar desde admin-api.
 */
class AddVersionNameToUsersTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('version_name', 50)->nullable()->after('estable_version');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('version_name');
        });
    }
}
