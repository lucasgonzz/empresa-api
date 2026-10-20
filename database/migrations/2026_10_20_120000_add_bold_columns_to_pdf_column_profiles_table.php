<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddBoldColumnsToPdfColumnProfilesTable extends Migration
{
    /**
     * Negrita en el valor de las columnas de la tabla de un diseño de PDF (misión
     * pdf-negrita-perfil, 20/10/2026).
     *
     * - bold_columns: JSON nullable con la lista de ids de `pdf_column_options` cuyo VALOR se
     *   imprime en negrita (p. ej. [3, 7]). El encabezado de la tabla ya es negrita siempre.
     *   NULL = ninguna columna en negrita: el PDF sale EXACTAMENTE como siempre. Por eso la
     *   columna no se rellena con ningún seeder ni comando.
     *
     * 🔴 Va en el perfil y NO en el pivot `pdf_column_option_profile`: los pivots se leen con
     * `withPivot([...])` explícito, y una columna nueva ahí rompería todo PDF con tabla en los
     * minutos entre que se suben los archivos y corre `migrate`. En una columna JSON del perfil
     * (`select *`) esa ventana no existe: el atributo llega null.
     *
     * Sin foreign keys (regla del proyecto): son ids de opciones y la lectura ignora los que ya
     * no existan en el perfil.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasColumn('pdf_column_profiles', 'bold_columns')) {
            Schema::table('pdf_column_profiles', function (Blueprint $table) {
                $table->json('bold_columns')->nullable()->after('page_layout');
            });
        }
    }

    /**
     * Revierte la columna si existe (rollback seguro en entornos desalineados).
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('pdf_column_profiles', 'bold_columns')) {
            Schema::table('pdf_column_profiles', function (Blueprint $table) {
                $table->dropColumn('bold_columns');
            });
        }
    }
}
