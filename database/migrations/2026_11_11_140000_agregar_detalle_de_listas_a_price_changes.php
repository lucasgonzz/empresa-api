<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AgregarDetalleDeListasAPriceChanges extends Migration
{
    public function up()
    {
        Schema::table('price_changes', function (Blueprint $table) {
            // Guarda de reintento: cada columna y el índice se crean sólo si faltan (vuelta atrás que dejó restos).
            if (!Schema::hasColumn('price_changes', 'precio_final_anterior')) {
                $table->decimal('precio_final_anterior', 22, 2)->nullable();
            }
            if (!Schema::hasColumn('price_changes', 'cambios_listas')) {
                $table->json('cambios_listas')->nullable();
            }
            if (!Schema::hasColumn('price_changes', 'origen')) {
                $table->string('origen', 100)->nullable();
            }
            if (!Schema::hasColumn('price_changes', 'operacion_uuid')) {
                $table->uuid('operacion_uuid')->nullable();
            }
            // Nombre por defecto de Laravel, el mismo que usa dropIndex(['operacion_uuid']) en down().
            if (!$this->existe_indice('price_changes', 'price_changes_operacion_uuid_index')) {
                $table->index('operacion_uuid');
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

    public function down()
    {
        Schema::table('price_changes', function (Blueprint $table) {
            $table->dropIndex(['operacion_uuid']);
            $table->dropColumn(['precio_final_anterior', 'cambios_listas', 'origen', 'operacion_uuid']);
        });
    }
}
