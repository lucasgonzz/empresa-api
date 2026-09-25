<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El tope diario de búsquedas por código de barras en internet, empujado por el admin junto con el
 * resto del plan de IA (misión asistente-fotos-barras-y-compras, 24/9/2026).
 *
 * La herramienta `buscar_producto_por_codigo_de_barras` del asistente sale a buscar en la web con
 * la clave de Anthropic de la PLATAFORMA (no la del negocio), y cada búsqueda web se paga aparte.
 * Lucas decidió un tope por negocio por día, configurable desde el admin.
 *
 * 🔴 NULLABLE Y SIN DEFAULT EN LA BASE A PROPÓSITO. Null = "el admin no mandó nada" y se lee como
 * el defecto de config (`services.asistente_ia.busqueda_codigo_barras.tope_diario_defecto`, 30).
 * Si la columna naciera con 30, un cambio del defecto por .env no le llegaría a ningún cliente que
 * ya existía, y un admin viejo (que no manda la clave) dejaría a todos clavados en el número de hoy.
 *
 * Sin foreign keys, con guard por columna para que sea segura de re-ejecutar.
 */
class AddPlanIaTopeBusquedasWebDiariasToUsersTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'plan_ia_tope_busquedas_web_diarias')) {
                /* Búsquedas web por código de barras por día. Null o 0 = el defecto de config. */
                $table->unsignedInteger('plan_ia_tope_busquedas_web_diarias')->nullable();
            }
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'plan_ia_tope_busquedas_web_diarias')) {
                $table->dropColumn('plan_ia_tope_busquedas_web_diarias');
            }
        });
    }
}
