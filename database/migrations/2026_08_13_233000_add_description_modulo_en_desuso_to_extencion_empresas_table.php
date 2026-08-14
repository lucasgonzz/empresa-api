<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Misión 54 — el catálogo de extensiones deja de ser una lista de nombres sueltos.
 *
 * Hasta acá `extencion_empresas` tenía nombre y slug y nada más, así que el configurador era
 * una lista plana de 91 checkboxes donde dos extensiones podían compartir el nombre visible
 * exacto y hacer cosas opuestas (`check_article_stock_en_vender` impide agregar el artículo,
 * `warn_article_stock_en_vender` solo avisa). Las tres columnas que agrega esta migración son
 * lo que hace falta para que el formulario se pueda leer:
 *
 *  - `description`: el comportamiento real, escrito por la misión 53 leyendo el código.
 *  - `modulo`: para agrupar el formulario, en vez de 91 filas seguidas sin orden.
 *  - `en_desuso`: las que ningún código lee. Marcar NO es borrar: la fila y las asignaciones
 *    de cada cliente se quedan donde están.
 */
class AddDescriptionModuloEnDesusoToExtencionEmpresasTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('extencion_empresas', function (Blueprint $table) {
            $table->text('description')->nullable()->after('slug');
            $table->string('modulo')->nullable()->after('description');
            $table->boolean('en_desuso')->default(false)->after('modulo');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('extencion_empresas', function (Blueprint $table) {
            $table->dropColumn(['description', 'modulo', 'en_desuso']);
        });
    }
}
