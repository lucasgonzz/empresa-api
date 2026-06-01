<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de medidas de etiquetas configurables por usuario.
 */
class CreateEtiquetaMedidasTable extends Migration
{
    /**
     * Crea la tabla etiqueta_medidas.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('etiqueta_medidas', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id')->index();
            $table->string('nombre', 50);
            $table->unsignedSmallInteger('ancho');
            $table->unsignedSmallInteger('alto');
            $table->boolean('es_predeterminada')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'es_predeterminada'], 'etiq_med_user_pred_idx');
        });
    }

    /**
     * Elimina la tabla etiqueta_medidas.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('etiqueta_medidas');
    }
}
