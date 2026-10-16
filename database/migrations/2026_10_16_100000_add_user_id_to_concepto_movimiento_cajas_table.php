<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega `user_id` a `concepto_movimiento_cajas` (misión destrabar-videos-centro, 16/10/2026):
 * los conceptos que crea un comercio pasan a ser SUYOS.
 *
 * Hasta acá la tabla no tenía dueño: `ConceptoMovimientoCajaController::index()` devolvía todos
 * los conceptos de la base y `update()`/`destroy()` no miraban de quién eran. En una base
 * compartida (`u767360347_empresa`, 51 comercios) el concepto de un comercio lo veían todos, y
 * cualquiera podía renombrar o borrar los del sistema, que el código usa por id fijo
 * (`MovimientoCaja::CONCEPTO_*`) y por nombre ("Pago a Vendedor").
 *
 * 🔴 NULLABLE y sin backfill: NULL = concepto del sistema, visible para todos y protegido. Las
 * filas que ya existen quedan en NULL, también las que haya creado algún comercio antes de esta
 * columna (no hay forma segura de saber de quién son): siguen viéndose como hasta hoy, solo que ya
 * no se pueden renombrar ni borrar desde el ABM. Una SPA vieja contra esta API sigue andando: el
 * listado trae las mismas filas de siempre más las propias.
 *
 * Guard idempotente con `Schema::hasColumn`.
 */
class AddUserIdToConceptoMovimientoCajasTable extends Migration
{
    /**
     * Agrega la columna nueva si todavía no existe.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasColumn('concepto_movimiento_cajas', 'user_id')) {
            Schema::table('concepto_movimiento_cajas', function (Blueprint $table) {
                $table->integer('user_id')->nullable()->index();
            });
        }
    }

    /**
     * Revierte la columna agregada si existe.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('concepto_movimiento_cajas', 'user_id')) {
            Schema::table('concepto_movimiento_cajas', function (Blueprint $table) {
                $table->dropIndex(['user_id']);
                $table->dropColumn('user_id');
            });
        }
    }
}
