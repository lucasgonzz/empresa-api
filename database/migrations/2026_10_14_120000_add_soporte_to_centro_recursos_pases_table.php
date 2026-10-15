<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca de "pase de soporte" en los pases del centro de recursos (misión
 * centro-recursos-soporte-y-tema, 14/10/2026).
 *
 * Un login maestro entra a la página del centro como el dueño pero en SOLO LECTURA: se ve su nombre
 * y su avance, y no se guarda nada. Esa decisión nace acá: el pase que emite `CentroRecursosController::pase()`
 * para un login maestro se marca `soporte = 1`, y el canje (`admin-sync/recursos/canjear-pase`) se
 * lo cuenta al admin para que abra una sesión de solo lectura.
 *
 * - `soporte`: boolean, default `false`. Todo pase que ya existe (y todo el que emita un usuario
 *   común) es un pase normal. Con default no hay que rellenar nada ni tocar los pases vivos.
 *
 * Idempotente: si la tabla todavía no existe (no debería, la crea la migración del 13/10) o la
 * columna ya está, no hace nada. Sin foreign keys (regla del repo).
 */
class AddSoporteToCentroRecursosPasesTable extends Migration
{
    /**
     * Agrega la columna `soporte` a `centro_recursos_pases`.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('centro_recursos_pases') || Schema::hasColumn('centro_recursos_pases', 'soporte')) {
            return;
        }

        Schema::table('centro_recursos_pases', function (Blueprint $table) {
            $table->boolean('soporte')->default(false)->after('video');
        });
    }

    /**
     * Saca la columna. Los pases viven 5 minutos: no se pierde nada que importe.
     *
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('centro_recursos_pases') || !Schema::hasColumn('centro_recursos_pases', 'soporte')) {
            return;
        }

        Schema::table('centro_recursos_pases', function (Blueprint $table) {
            $table->dropColumn('soporte');
        });
    }
}
