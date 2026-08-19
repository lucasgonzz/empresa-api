<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración Grupo 225 · Prompt 01 (reportes — Estado de Resultados devengado).
 *
 * Agrega a `company_performances` una columna para cachear la estructura nueva del Estado de
 * Resultados (`EstadoResultadosHelper::estado_resultados()`), calculada por
 * `SetCompanyPerformances` en el mismo momento que hoy calcula el resto del snapshot.
 *
 * Nullable a propósito (tarea 04 del prompt): los snapshots viejos, generados antes de este
 * prompt, no tienen este dato y siguen siendo perfectamente legibles por la pantalla vieja de
 * reportes — esta columna es aditiva, no reemplaza ninguna de las existentes.
 *
 * Sin foreign keys (regla del workspace).
 */
class AddEstadoResultadosSnapshotToCompanyPerformancesTable extends Migration
{
    /**
     * Ejecuta la migración: suma la columna de snapshot del Estado de Resultados.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('company_performances', function (Blueprint $table) {
            // JSON con la estructura completa devuelta por EstadoResultadosHelper::estado_resultados()
            // para este período (moneda 'consolidado', que internamente cae a 'pesos' si el usuario
            // no tiene activa la extensión de ventas en dólares). Null = snapshot generado antes de
            // este prompt, o aún no recalculado.
            $table->json('estado_resultados_snapshot')->nullable()->after('cantidad_ventas');
        });
    }

    /**
     * Revierte la migración: elimina la columna agregada.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('company_performances', function (Blueprint $table) {
            $table->dropColumn('estado_resultados_snapshot');
        });
    }
}
