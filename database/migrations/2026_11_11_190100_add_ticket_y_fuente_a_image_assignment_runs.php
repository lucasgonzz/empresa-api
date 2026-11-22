<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Las fotos que la tarea de soporte trae de la web de un proveedor (misión importaciones-de-martin,
 * 11/11/2026) quedan en una asignación de imágenes con origen `proveedor_web`, una por ticket y fuente:
 * estas dos columnas son la clave con la que se reusa entre tandas (y la idempotencia por artículo).
 *
 * Aditiva y nullable: las asignaciones de siempre quedan con las dos en null.
 */
class AddTicketYFuenteAImageAssignmentRuns extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('image_assignment_runs')) {
            return;
        }

        // Guarda de reintento: columnas e índice se crean sólo si faltan (vuelta atrás que dejó restos).
        Schema::table('image_assignment_runs', function (Blueprint $table) {
            if (!Schema::hasColumn('image_assignment_runs', 'support_ticket_id')) {
                $table->unsignedBigInteger('support_ticket_id')->nullable();
            }
            if (!Schema::hasColumn('image_assignment_runs', 'fuente')) {
                $table->string('fuente', 190)->nullable();
            }
            if (!$this->existe_indice('image_assignment_runs', 'iar_user_origen_ticket_idx')) {
                $table->index(['user_id', 'origen', 'support_ticket_id'], 'iar_user_origen_ticket_idx');
            }
        });
    }

    /**
     * Indica si la tabla ya tiene un índice con ese nombre (para no crearlo dos veces al reintentar).
     *
     * @param string $tabla
     * @param string $nombre
     * @return bool
     */
    private function existe_indice(string $tabla, string $nombre): bool
    {
        return count(DB::select('SHOW INDEX FROM `' . $tabla . '` WHERE Key_name = ?', [$nombre])) > 0;
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('image_assignment_runs') || !Schema::hasColumn('image_assignment_runs', 'support_ticket_id')) {
            return;
        }

        Schema::table('image_assignment_runs', function (Blueprint $table) {
            $table->dropIndex('iar_user_origen_ticket_idx');
            $table->dropColumn(['support_ticket_id', 'fuente']);
        });
    }
}
