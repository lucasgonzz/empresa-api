<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega la columna `condicion_iva_precios` a `user_configurations`.
 *
 * Esta columna define, por cuenta, qué condición frente al IVA se usa para
 * calcular precios y costos ('RRII' = Responsable Inscripto, 'MT' =
 * Monotributista). Es independiente de `afip_information.iva_condition`
 * (que está atada al punto de venta / CUIT y no sirve para este cálculo,
 * porque una misma cuenta puede tener varios CUIT / puntos de venta).
 *
 * El default 'RRII' es deliberado: reproduce el comportamiento actual del
 * sistema (costo neto, IVA sumado al vender), así que las cuentas
 * existentes no cambian de comportamiento al correr esta migración.
 */
class AddCondicionIvaPreciosToUserConfigurationsTable extends Migration
{
    /**
     * Agrega la columna condicion_iva_precios (string, largo 10, default 'RRII', not null).
     *
     * @return void
     */
    public function up()
    {
        Schema::table('user_configurations', function (Blueprint $table) {
            // Condición de IVA usada para el cálculo de precios/costos de la cuenta: 'RRII' o 'MT'.
            $table->string('condicion_iva_precios', 10)->default('RRII')->nullable(false)->after('vender_right_width');
        });
    }

    /**
     * Elimina la columna condicion_iva_precios.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('user_configurations', function (Blueprint $table) {
            $table->dropColumn('condicion_iva_precios');
        });
    }
}
