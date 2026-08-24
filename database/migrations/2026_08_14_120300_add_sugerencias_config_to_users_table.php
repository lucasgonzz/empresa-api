<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: configuración de sugerencias de stock en users.
 *
 * Es el patrón vigente de "Configuración → general" (mismo camino que
 * usar_condicion_fiscal_en_costeo): columna en users → asignación en
 * UserController@update → propiedad en empresa-spa src/models/user.js.
 *
 * - sugerencias_periodicidad: nunca / diaria / semanal / quincenal / mensual.
 *   Default 'nunca': NINGUNA cuenta existente empieza a generar sugerencias
 *   sola por el simple hecho de migrar.
 * - sugerencias_modo / sugerencias_origen / sugerencias_limite_origen:
 *   valores por defecto con los que el comando automático crea la sugerencia
 *   (y con los que la SPA precarga el form de una manual). Mismos vocabularios
 *   que las columnas homónimas de stock_suggestions, más el modo nuevo
 *   'maximo'.
 * - sugerencias_ultima_generacion_at: cuándo generó por última vez el comando
 *   automático; contra esto (y no contra el día del calendario) se decide si
 *   hoy toca, para que una instancia apagada un día no se saltee el ciclo.
 *
 * Sin foreign keys, longitudes explícitas en los string.
 */
class AddSugerenciasConfigToUsersTable extends Migration
{
    /**
     * Ejecuta la migración: agrega cada columna solo si no existe (guards
     * hasColumn para que sea segura de re-ejecutar).
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasColumn('users', 'sugerencias_periodicidad')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('sugerencias_periodicidad', 20)->default('nunca');
            });
        }

        if (! Schema::hasColumn('users', 'sugerencias_modo')) {
            Schema::table('users', function (Blueprint $table) {
                // minimo / ideal / maximo — mismo vocabulario que stock_suggestions.modo.
                $table->string('sugerencias_modo', 20)->default('minimo');
            });
        }

        if (! Schema::hasColumn('users', 'sugerencias_origen')) {
            Schema::table('users', function (Blueprint $table) {
                // absoluto / relativo — mismo vocabulario que stock_suggestions.origen.
                $table->string('sugerencias_origen', 20)->default('absoluto');
            });
        }

        if (! Schema::hasColumn('users', 'sugerencias_limite_origen')) {
            Schema::table('users', function (Blueprint $table) {
                // minimo / ideal / sin_limite — mismo vocabulario que stock_suggestions.limite_origen.
                $table->string('sugerencias_limite_origen', 20)->default('minimo');
            });
        }

        if (! Schema::hasColumn('users', 'sugerencias_ultima_generacion_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('sugerencias_ultima_generacion_at')->nullable();
            });
        }
    }

    /**
     * Revierte la migración: elimina cada columna solo si existe.
     *
     * @return void
     */
    public function down()
    {
        $columnas = [
            'sugerencias_periodicidad',
            'sugerencias_modo',
            'sugerencias_origen',
            'sugerencias_limite_origen',
            'sugerencias_ultima_generacion_at',
        ];

        foreach ($columnas as $columna) {
            if (Schema::hasColumn('users', $columna)) {
                Schema::table('users', function (Blueprint $table) use ($columna) {
                    $table->dropColumn($columna);
                });
            }
        }
    }
}
