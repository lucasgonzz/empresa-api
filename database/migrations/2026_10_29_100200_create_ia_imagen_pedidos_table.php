<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los pedidos de imagen generada del agente (misión app-ia-y-memoria, 29/10/2026, §4 E1.7 del plan).
 * Ver App\Models\IaImagenPedido y PedidosDeImagenIaHelper.
 *
 * `uuid` es la identidad que comparten este API y el admin (contratos C1.4 y C2.1). `user_id` el dueño
 * (la tenencia es por dueño). `path` la imagen en el disco `local` cuando llegó.
 */
class CreateIaImagenPedidosTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('ia_imagen_pedidos')) {
            return;
        }

        Schema::create('ia_imagen_pedidos', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('uuid', 36)->unique();

            /* El dueño de la cuenta. */
            $table->unsignedBigInteger('user_id');

            /* El mensaje del asistente que muestra el bloque de la imagen. */
            $table->unsignedBigInteger('ai_message_id')->nullable();

            $table->text('prompt');

            /* cuadrada | vertical | horizontal */
            $table->string('tamano', 20)->default('cuadrada');

            $table->string('estilo', 200)->nullable();

            /* pendiente | lista | fallida | no_disponible */
            $table->string('estado', 20)->default('pendiente');

            /* ia_imagenes/{owner}/{uuid}.{ext} en el disco local */
            $table->string('path', 191)->nullable();

            $table->string('error', 500)->nullable();

            $table->timestamp('enviado_al_admin_en')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'created_at'], 'iip_user_created_idx');
            $table->index(['ai_message_id'], 'iip_message_idx');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('ia_imagen_pedidos');
    }
}
