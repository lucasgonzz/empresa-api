<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El cuestionario de inicio de la app del agente (misión app-ia-y-memoria, 29/10/2026).
 *
 * Van en `users` del DUEÑO porque el cuestionario es del comercio, igual que la confianza del agente
 * (`agente_confianza`), que el mismo cuestionario escribe:
 *
 *   - `ia_cuestionario_completado_at`: cuándo lo terminó. Null = no lo hizo.
 *   - `ia_cuestionario_salteado_at`: cuándo tocó «Ahora no». Null = no lo salteó.
 *   - `ia_cuestionario_respuestas`: lo que contestó, tal cual llegó validado (para volver a mostrarlo
 *     y para poder rehacer la memoria si hiciera falta).
 *
 * Las tres nullable: un dueño que nunca abrió la app queda exactamente como estaba. Sin foreign
 * keys, con guard por columna para que sea segura de re-ejecutar.
 */
class AddIaCuestionarioToUsersTable extends Migration
{
    /**
     * Agrega las tres columnas, cada una con su propio guard hasColumn.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'ia_cuestionario_completado_at')) {
                /* Cuándo el dueño terminó el cuestionario de inicio de la app */
                $table->timestamp('ia_cuestionario_completado_at')->nullable();
            }

            if (!Schema::hasColumn('users', 'ia_cuestionario_salteado_at')) {
                /* Cuándo el dueño lo salteó con «Ahora no» */
                $table->timestamp('ia_cuestionario_salteado_at')->nullable();
            }

            if (!Schema::hasColumn('users', 'ia_cuestionario_respuestas')) {
                /* Las respuestas validadas del cuestionario */
                $table->json('ia_cuestionario_respuestas')->nullable();
            }
        });
    }

    /**
     * Elimina las columnas si existen.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['ia_cuestionario_completado_at', 'ia_cuestionario_salteado_at', 'ia_cuestionario_respuestas'] as $columna) {
                if (Schema::hasColumn('users', $columna)) {
                    $table->dropColumn($columna);
                }
            }
        });
    }
}
