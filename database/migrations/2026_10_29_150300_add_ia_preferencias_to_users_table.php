<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las preferencias de respuesta del agente que el dueño cambia desde la app (misión
 * app-ia-y-memoria, 29/10/2026): `{largo, forma}`.
 *
 * Las escriben el cuestionario de inicio y `PATCH api/ia-app/preferencias`, y las lee
 * `GET api/ia-app/cuestionario` aunque el cuestionario se haya salteado. La confianza NO va acá: vive en
 * `agente_confianza`, la columna de siempre de la configuración del asistente.
 *
 * Va en `users` del DUEÑO, como el resto de la configuración del agente. Nullable: sin nada guardado
 * valen los valores por defecto (CuestionarioDeInicioIaHelper). Con guard para que sea segura de
 * re-ejecutar.
 */
class AddIaPreferenciasToUsersTable extends Migration
{
    /**
     * Agrega la columna.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'ia_preferencias')) {
                /* {largo: breve|equilibrado|explayado, forma: numeros|graficos|explicado} */
                $table->json('ia_preferencias')->nullable();
            }
        });
    }

    /**
     * Elimina la columna si existe.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'ia_preferencias')) {
                $table->dropColumn('ia_preferencias');
            }
        });
    }
}
