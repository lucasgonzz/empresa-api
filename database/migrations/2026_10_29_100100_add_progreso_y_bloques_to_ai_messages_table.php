<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El progreso y los bloques de una respuesta del asistente (misión app-ia-y-memoria, 29/10/2026, §4 E1.5
 * y E1.6 del plan):
 *
 *   - `progreso` (string 120): mientras el mensaje está `pendiente`, la etiqueta de lo que está haciendo
 *     el loop de herramientas («Buscando las ventas…»). Vuelve a null al terminar. Ver ProgresoIaHelper.
 *   - `bloques` (json): los gráficos, tablas e imágenes generadas que la respuesta muestra debajo del
 *     texto en la app. Formato en BloquesIaHelper (§3 del plan).
 *
 * Nullable y sin default: los mensajes que ya existen quedan en null.
 */
class AddProgresoYBloquesToAiMessagesTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('ai_messages')) {
            return;
        }

        Schema::table('ai_messages', function (Blueprint $table) {

            if (!Schema::hasColumn('ai_messages', 'progreso')) {
                $table->string('progreso', 120)->nullable();
            }

            if (!Schema::hasColumn('ai_messages', 'bloques')) {
                $table->json('bloques')->nullable();
            }
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('ai_messages')) {
            return;
        }

        Schema::table('ai_messages', function (Blueprint $table) {

            foreach (['progreso', 'bloques'] as $columna) {
                if (Schema::hasColumn('ai_messages', $columna)) {
                    $table->dropColumn($columna);
                }
            }
        });
    }
}
