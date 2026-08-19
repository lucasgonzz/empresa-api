<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: configuración del motor de ofertas por cliente en users.
 *
 * Mismo patrón que sugerencias_compras_periodicidad (compras) y
 * sugerencias_periodicidad (stock): columna en users → el comando
 * ofertas:generar decide contra ella si hoy toca correr.
 *
 * - ofertas_periodicidad: nunca / diaria / semanal / quincenal / mensual.
 *   🔴 Default 'nunca', y acá el default pesa más que en las otras dos: una
 *   corrida de ofertas termina mandándole MAILS a los clientes del comercio.
 *   NINGUNA cuenta existente puede empezar a hacer eso sola por el simple
 *   hecho de migrar.
 * - ofertas_ultima_generacion_at: cuándo generó por última vez el comando
 *   automático; contra esto (comparado por startOfDay(), no por el día del
 *   calendario) se decide si hoy toca, para que una instancia apagada un día
 *   no se saltee el ciclo.
 *   🔴 Esta columna la escribe SOLO el comando, nunca UserController@update:
 *   si el PUT del form la aceptara, cada guardado retrocedería la marca y
 *   adelantaría la próxima generación.
 *
 * Ninguna de las dos activa nada por sí sola: sin la extensión
 * 'motor_de_ofertas' en el comercio, ofertas:generar no genera aunque la
 * periodicidad diga que hoy toca (doble gate, y el --force no saltea el gate
 * de la extensión).
 *
 * Sin foreign keys, longitudes explícitas en los string.
 */
class AddOfertasConfigToUsersTable extends Migration
{
    /**
     * Ejecuta la migración: agrega cada columna solo si no existe (guards
     * hasColumn para que sea segura de re-ejecutar).
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasColumn('users', 'ofertas_periodicidad')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('ofertas_periodicidad', 20)->default('nunca');
            });
        }

        if (! Schema::hasColumn('users', 'ofertas_ultima_generacion_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('ofertas_ultima_generacion_at')->nullable();
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
            'ofertas_periodicidad',
            'ofertas_ultima_generacion_at',
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
