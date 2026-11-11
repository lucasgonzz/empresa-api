<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mapeo de columnas que quien inicia la importación desde soporte ya confirmó con el cliente
 * (misión importacion-mapeo-previo-de-martin, 11/11/2026).
 *
 * Se guarda normalizado como lista JSON de {hoja, columna, campo}: `campo` ya resuelto a la clave
 * del importador, o null cuando la columna se ignora. El análisis lo aplica a las hojas y solo
 * pregunta lo que no quedó cubierto.
 */
class AddColumnasPreviasAImportacionesDePrueba extends Migration
{
    /**
     * Agrega la columna nullable; las corridas existentes quedan sin mapeo previo.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasColumn('importaciones_de_prueba', 'columnas_previas')) {
            return;
        }

        Schema::table('importaciones_de_prueba', function (Blueprint $table) {
            $table->longText('columnas_previas')->nullable();
        });
    }

    /**
     * Quita la columna si existe.
     *
     * @return void
     */
    public function down()
    {
        if (!Schema::hasColumn('importaciones_de_prueba', 'columnas_previas')) {
            return;
        }

        Schema::table('importaciones_de_prueba', function (Blueprint $table) {
            $table->dropColumn('columnas_previas');
        });
    }
}
