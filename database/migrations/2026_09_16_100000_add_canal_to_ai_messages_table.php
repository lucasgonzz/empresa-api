<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Por dónde entró cada mensaje del asistente de IA (misión asistente-por-whatsapp, 16/9/2026).
 *
 * Hasta hoy todo mensaje venía del panel del chat de la SPA. Desde esta misión el dueño le puede
 * hablar al MISMO asistente desde WhatsApp: el admin recibe el mensaje en el número de
 * ComercioCity y se lo empuja a este API por admin-sync. La conversación es la misma clase de
 * objeto y se lee desde el sistema; lo único que cambia es el canal, y el canal cambia dos cosas
 * concretas adentro del loop: el prompt (texto plano corto, sin tarjetas para tocar) y qué
 * herramientas se declaran (en WhatsApp la confirmación es por texto, con
 * confirmar_carga_pendiente).
 *
 * 🔴 Default 'sistema': todo mensaje que ya existe y todo POST de la SPA quedan exactamente como
 * hoy. Es la condición de compatibilidad hacia atrás del §7 del plan — una SPA vieja contra este
 * API se comporta igual que antes de la misión.
 *
 * `whatsapp_message_id` guarda el wamid del mensaje entrante que originó este AiMessage. NO se usa
 * de este lado para resolver conversaciones (la cita la resuelve el admin, que es el único que
 * conoce los wamid): sirve para poder rastrear, mirando una sola fila, qué mensaje de WhatsApp
 * terminó en qué mensaje del asistente cuando algo sale mal. Indexado por eso mismo.
 */
class AddCanalToAiMessagesTable extends Migration
{
    /**
     * Agrega las dos columnas, cada una con su guard hasColumn para que sea segura de re-ejecutar.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('ai_messages')) {
            return;
        }

        if (!Schema::hasColumn('ai_messages', 'canal')) {

            Schema::table('ai_messages', function (Blueprint $table) {

                /* 'sistema' (el panel del chat) | 'whatsapp' (el dueño desde su teléfono) */
                $table->string('canal', 20)->default('sistema')->after('acciones_habilitadas');
            });
        }

        if (!Schema::hasColumn('ai_messages', 'whatsapp_message_id')) {

            Schema::table('ai_messages', function (Blueprint $table) {

                /* wamid del mensaje entrante de WhatsApp que originó este mensaje; null en el sistema */
                $table->string('whatsapp_message_id', 128)->nullable()->after('canal');

                /* Para rastrear un wamid puntual sin escanear la tabla entera */
                $table->index(['whatsapp_message_id'], 'ai_messages_wamid_idx');
            });
        }
    }

    /**
     * Quita las dos columnas (el índice se va con la columna).
     *
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('ai_messages')) {
            return;
        }

        if (Schema::hasColumn('ai_messages', 'whatsapp_message_id')) {

            Schema::table('ai_messages', function (Blueprint $table) {
                $table->dropIndex('ai_messages_wamid_idx');
                $table->dropColumn('whatsapp_message_id');
            });
        }

        if (Schema::hasColumn('ai_messages', 'canal')) {

            Schema::table('ai_messages', function (Blueprint $table) {
                $table->dropColumn('canal');
            });
        }
    }
}
