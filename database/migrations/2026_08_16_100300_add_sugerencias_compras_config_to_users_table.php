<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: configuración de sugerencias de compra a proveedores en users.
 *
 * Mismo patrón que sugerencias_periodicidad (stock): columna en users →
 * el comando compras:generar decide contra ella si hoy toca correr.
 *
 * - sugerencias_compras_periodicidad: nunca / diaria / semanal / quincenal /
 *   mensual. Default 'nunca': NINGUNA cuenta existente empieza a generar
 *   sugerencias de compra sola por el simple hecho de migrar.
 * - sugerencias_compras_ultima_generacion_at: cuándo generó por última vez
 *   el comando automático; contra esto (comparado por startOfDay(), no por
 *   el día del calendario) se decide si hoy toca, para que una instancia
 *   apagada un día no se saltee el ciclo.
 *
 * Ninguna de las dos columnas activa nada por sí sola: sin la extensión
 * 'sugerencias_compras' en el comercio, compras:generar no genera aunque la
 * periodicidad diga que hoy toca (doble gate, ver GenerarSugerenciasDeCompra).
 *
 * Sin foreign keys, longitudes explícitas en los string.
 */
class AddSugerenciasComprasConfigToUsersTable extends Migration
{
    /**
     * Ejecuta la migración: agrega cada columna solo si no existe (guards
     * hasColumn para que sea segura de re-ejecutar).
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasColumn('users', 'sugerencias_compras_periodicidad')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('sugerencias_compras_periodicidad', 20)->default('nunca');
            });
        }

        if (! Schema::hasColumn('users', 'sugerencias_compras_ultima_generacion_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('sugerencias_compras_ultima_generacion_at')->nullable();
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
            'sugerencias_compras_periodicidad',
            'sugerencias_compras_ultima_generacion_at',
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
