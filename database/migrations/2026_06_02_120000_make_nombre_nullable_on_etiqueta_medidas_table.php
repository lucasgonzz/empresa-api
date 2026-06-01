<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permite medidas predeterminadas sin nombre (se muestra ancho × alto en el front).
 */
class MakeNombreNullableOnEtiquetaMedidasTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::table('etiqueta_medidas', function (Blueprint $table) {
            $table->string('nombre', 80)->nullable()->change();
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('etiqueta_medidas', function (Blueprint $table) {
            $table->string('nombre', 50)->nullable(false)->change();
        });
    }
}
