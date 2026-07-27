<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class AddCosteoCondicionFiscalToUsersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * Mueve `condicion_iva_precios` de `user_configurations` a `users` (grupo 231, prompt 01) y
     * crea la bandera `usar_condicion_fiscal_en_costeo`, apagada por default para no afectar el
     * costeo de ninguna cuenta existente.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            // Mismo largo (10) que la columna original en user_configurations: no dejarlo como
            // string() sin tamaño (regla del workspace de longitudes acotadas en índices/strings).
            $table->string('condicion_iva_precios', 10)->default('RRII');
            // Apagada por default: las cuentas existentes no activan la dinámica nueva de costeo
            // por condición fiscal con este movimiento de columna. Las cuentas nuevas la reciben
            // encendida explícitamente en HelperController::store_user() y en los seeders.
            $table->boolean('usar_condicion_fiscal_en_costeo')->default(0);
        });

        // Copia el valor ya elegido por cada cuenta desde user_configurations. El filtro
        // "!= ''" es necesario: hay filas con condicion_iva_precios en string vacío (el motivo por
        // el que existía el comando user-configurations:set-condicion-iva-precios-defaults), y sin
        // excluirlas el UPDATE pisaría el default 'RRII' de users con '' dejando esas cuentas sin
        // condición fiscal válida.
        DB::statement("UPDATE users u INNER JOIN user_configurations uc ON uc.user_id = u.id SET u.condicion_iva_precios = uc.condicion_iva_precios WHERE uc.condicion_iva_precios IS NOT NULL AND uc.condicion_iva_precios != ''");
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            //
        });
    }
}
