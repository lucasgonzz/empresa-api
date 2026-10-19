<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los envíos a clientes del asistente que FALLARON después de que el dueño dijo que sí (misión
 * asistente-arreglos-segunda-prueba, 19/10/2026, defecto N3).
 *
 * 🔴 EL CASO REAL. En la segunda prueba por WhatsApp contra demo3 el dueño confirmó mandarle un
 * presupuesto a un cliente por mail; el asistente le contestó «le mandé el presupuesto» y el mail
 * murió en el worker (Mailgun devolvió 401). Ningún registro ataba ese fallo a la tarjeta ni a la
 * conversación: quedó en `failed_jobs` y en el log, y el dueño nunca se enteró. Lo mismo con los
 * recordatorios de cobro y las promociones, que salen en segundo plano (un job por cliente) y solo
 * dejaban un renglón en el log.
 *
 * Una fila por envío que no salió (comprobante: una por tarjeta y canal) o por LOTE (recordatorios y
 * promo: una por tarjeta y canal, con `cantidad` y los nombres), para que el aviso al dueño sea uno y
 * no cuarenta. El aviso sale por el canal de avisos que ya existe (GET avisos-pendientes del admin,
 * `AvisosDelAsistenteHelper`), con el id `envio_fallido:{uuid}`.
 *
 * Aditiva y sin claves foráneas: `tienda-api` comparte la base y no la usa; los índices tienen nombre
 * corto (MySQL corta en 64 caracteres) y las columnas string, largo acotado. Con `hasTable` para que
 * correrla dos veces (o sobre una base que ya la tiene) no rompa.
 */
class CreateAiEnviosFallidosTable extends Migration
{
    /**
     * Crea la tabla si falta.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('ai_envios_fallidos')) {
            return;
        }

        Schema::create('ai_envios_fallidos', function (Blueprint $table) {
            $table->bigIncrements('id');

            // El id del aviso para el admin ("envio_fallido:{uuid}"): las demos corren migrate:fresh y
            // el autoincremental se repite entre reseteos; el uuid no (mismo criterio que los escaneos).
            $table->string('uuid', 36)->default('');

            /*
             * El dueño del negocio (la cuenta, `ai_message_actions.user_id`).
             *
             * 🔴 SE LLAMA `owner_id` Y NO `user_id`, A PROPÓSITO. EsquemaDeDatosIaHelper deriva como
             * entidad consultable por el asistente TODA tabla con `user_id` que no esté en su lista
             * negra (TABLAS_EXCLUIDAS): con `user_id`, este registro interno aparecía en
             * que_puedo_consultar y en los recursos MCP. Las demás tablas `ai_*` están en esa lista;
             * esta queda afuera sin tocar el helper del esquema, por no tener la columna.
             */
            $table->unsignedBigInteger('owner_id')->default(0);

            // La conversación y la tarjeta del envío (sin FK: se borran por su lado sin arrastrar esto).
            $table->unsignedBigInteger('ai_conversation_id')->nullable();
            $table->unsignedBigInteger('ai_message_action_id')->nullable();

            // comprobante | recordatorios | promocion
            $table->string('tipo_envio', 20)->default('');

            // mail | whatsapp
            $table->string('canal', 10)->default('');

            // La casilla o el teléfono al que no llegó (en un lote, el del primero).
            $table->string('destino', 191)->nullable();

            // El cliente (en un lote, el primero que falló).
            $table->string('cliente', 191)->nullable();

            // En un lote: los nombres de los que no recibieron, en JSON y con tope (ver el helper).
            $table->string('nombres', 1000)->nullable();

            // Qué se mandaba, como lo lee el dueño: "el presupuesto N° 900005".
            $table->string('que', 191)->nullable();

            // Por qué no salió, en palabras del dueño (nunca el error crudo del servidor).
            $table->string('motivo', 255)->nullable();

            // Cuántos no salieron (1 en un comprobante; en un lote, los que se fueron sumando).
            $table->unsignedInteger('cantidad')->default(1);

            // Si hay que avisarle al dueño por WhatsApp: la tarjeta la propuso un mensaje de WhatsApp y
            // el admin de este dueño anunció que consulta los avisos (mismo criterio que el escaneo).
            $table->boolean('avisar_por_whatsapp')->default(false);

            // Cuándo el admin mandó el aviso (avisos/avisado).
            $table->timestamp('avisado_por_whatsapp_at')->nullable();

            // Cuándo el fallo ya se le dijo al dueño en el mismo turno (cola sync): no se avisa dos veces.
            $table->timestamp('dicho_en_el_turno_at')->nullable();

            $table->timestamps();

            $table->index('uuid', 'aef_uuid_idx');
            $table->index(['owner_id', 'avisar_por_whatsapp'], 'aef_owner_avisar_idx');
            $table->index(['ai_message_action_id', 'canal'], 'aef_accion_canal_idx');
        });
    }

    /**
     * Saca la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('ai_envios_fallidos');
    }
}
