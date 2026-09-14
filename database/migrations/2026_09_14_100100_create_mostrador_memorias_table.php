<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Memoria del mostrador por dueño (misión modulo-ia-mostrador, 14/9/2026).
 *
 * La skill /mostrador lee las conversaciones que el dueño tuvo sobre sus informes
 * (ai_conversations con origen 'mostrador_reporte'), sintetiza qué le importa y lo
 * deposita acá como texto. `hasta_ai_message_id` es el último ai_messages.id que ya
 * entró en esa síntesis: la próxima corrida pide solo lo posterior.
 *
 * Una fila por dueño (unique). Sin foreign keys físicas, como el resto del schema.
 */
class CreateMostradorMemoriasTable extends Migration
{
    /**
     * Crea la tabla, con guard hasTable para que sea segura de re-ejecutar.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('mostrador_memorias')) {
            return;
        }

        Schema::create('mostrador_memorias', function (Blueprint $table) {

            /* Clave primaria autoincremental */
            $table->bigIncrements('id');

            /* ID del dueño de la cuenta (users con owner_id null) */
            $table->unsignedBigInteger('user_id');

            /* Síntesis redactada por la skill de lo que le importa a este dueño */
            $table->longText('texto')->nullable();

            /* Último ai_messages.id ya sintetizado en `texto` */
            $table->unsignedBigInteger('hasta_ai_message_id')->nullable();

            $table->timestamps();

            /* Una memoria por dueño */
            $table->unique(['user_id'], 'mm_user_unique');
        });
    }

    /**
     * Elimina la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('mostrador_memorias');
    }
}
