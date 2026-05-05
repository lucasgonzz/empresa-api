<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega precio_plan: monto fijo base por el sistema (plan), aparte del cobro por cuentas y módulos.
 */
class AddPrecioPlanToUsersTable extends Migration
{
    /**
     * Crea la columna precio_plan en users.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('precio_plan', 12, 2)->default(0)->after('precio_por_cuenta');
        });
    }

    /**
     * Elimina la columna precio_plan.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('precio_plan');
        });
    }
}
