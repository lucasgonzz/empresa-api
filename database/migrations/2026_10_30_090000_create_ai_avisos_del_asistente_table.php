<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los avisos que el asistente le deja al dueño para que salgan POR WHATSAPP cuando un turno no se pudo
 * terminar (misión agente-mejoras-uso-real, ronda de arreglos de la revisión independiente, 30/10/2026).
 *
 * 🔴 EL CASO REAL (Ferre Total, CONV 15, 29/10/2026). Pablo mandó tres fotos para los clavos que
 * faltaban, el turno se cortó y le llegó la disculpa fija del admin («Uf, no pude contestarte esta
 * vez…»). El texto del empresa que decía «me llegaron 3 fotos y quedaron guardadas: escribime "seguí"»
 * nunca salió: con el mensaje en `error`, el admin no lee el contenido y manda su disculpa. Y un turno
 * que se queda colgado en `pendiente` (la cola sin consumidor) no le avisaba nada a nadie: el admin
 * deja de esperar a los 180 s y el pedido se pierde.
 *
 * Estos avisos salen por el MISMO camino que el de «ya leí la factura» y el de un envío que falló: el
 * admin consulta GET admin-sync/asistente/avisos-pendientes, manda el texto y lo sella con
 * avisos/avisado (`turno_sin_terminar:{uuid}`). Una fila por mensaje del asistente que no terminó.
 *
 * Aditiva y sin claves foráneas, con `hasTable` (correrla dos veces no rompe). `owner_id` y no
 * `user_id`, por lo mismo que `ai_envios_fallidos`: EsquemaDeDatosIaHelper deriva como entidad
 * consultable toda tabla con `user_id` que no esté en su lista negra.
 */
class CreateAiAvisosDelAsistenteTable extends Migration
{
    /**
     * Crea la tabla si falta.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('ai_avisos_del_asistente')) {
            return;
        }

        Schema::create('ai_avisos_del_asistente', function (Blueprint $table) {
            $table->bigIncrements('id');

            // El id del aviso para el admin ("turno_sin_terminar:{uuid}"): las demos corren
            // migrate:fresh y el autoincremental se repite entre reseteos; el uuid no.
            $table->string('uuid', 36)->default('');

            // El dueño del negocio (ver el docblock: `owner_id` a propósito).
            $table->unsignedBigInteger('owner_id')->default(0);

            // La conversación y el mensaje del asistente que no terminó (sin FK).
            $table->unsignedBigInteger('ai_conversation_id')->nullable();
            $table->unsignedBigInteger('ai_message_id')->nullable();

            // turno_sin_terminar (el único tipo por ahora).
            $table->string('tipo', 30)->default('');

            // El texto que sale por WhatsApp, tal cual.
            $table->text('texto')->nullable();

            // El sello del admin: el WhatsApp salió (y su wamid).
            $table->timestamp('avisado_por_whatsapp_at')->nullable();
            $table->string('whatsapp_message_id', 191)->nullable();

            $table->timestamps();

            $table->index(['owner_id', 'avisado_por_whatsapp_at'], 'ai_avisos_owner_avisado_idx');
            $table->index('ai_message_id', 'ai_avisos_message_idx');
            $table->index('uuid', 'ai_avisos_uuid_idx');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('ai_avisos_del_asistente');
    }
}
