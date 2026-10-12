<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddUserIdToSheetTypesTable extends Migration
{
    /**
     * Anchos de comandera propios de cada negocio (misión diseno-ticket-comandera, 9/10/2026).
     *
     * - user_id: NULL = tipo de hoja del sistema (A4, Ticket 55 mm, Ticket 80 mm, los de
     *   SheetTypeSeeder, que ven todos los negocios). Con valor = un ancho de comandera que agregó
     *   ese dueño desde el formulario de Diseño de PDF ("+ Agregar un ancho de comandera…"): lo ven
     *   solo él y sus empleados (SheetType::scopeDelSistemaODelDueno()).
     *
     * Aditiva y sin foreign key (regla del repo): las filas que ya existen quedan con user_id NULL,
     * o sea del sistema, que es lo que son. Índice corto para listar los del dueño.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasColumn('sheet_types', 'user_id')) {
            Schema::table('sheet_types', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable()->after('height');
                $table->index('user_id', 'sheet_types_user_idx');
            });
        }
    }

    /**
     * Saca la columna y su índice si existen (rollback seguro en entornos desalineados).
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('sheet_types', 'user_id')) {
            Schema::table('sheet_types', function (Blueprint $table) {
                $table->dropIndex('sheet_types_user_idx');
                $table->dropColumn('user_id');
            });
        }
    }
}
