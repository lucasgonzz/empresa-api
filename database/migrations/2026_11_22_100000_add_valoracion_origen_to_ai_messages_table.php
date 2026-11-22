<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * De dónde salió la valoración de una respuesta del asistente personal (misión asistente-reacciones-wa,
 * 22/11/2026).
 *
 * Hasta ahora la valoración (`valoracion`, `valoracion_comentario`, `valorado_at`) sólo la escribía el
 * sistema (PUT api/ai-mensajes/{id}/valoracion). Desde esta misión también la puede poner una reacción del
 * dueño por WhatsApp (👍 / 👎 / 😡 …), y hace falta distinguirlas: una reacción NO puede pisar el comentario
 * que el dueño escribió a mano en el sistema.
 *
 * Valores: `sistema` | `reaccion_whatsapp` | null. Las filas viejas con valoración y origen null se tratan
 * como `sistema` (protección conservadora). La lee también el análisis diario (herramientas/agente-analisis-diario).
 *
 * Nullable y sin índice; con guard para que sea segura de re-ejecutar.
 */
class AddValoracionOrigenToAiMessagesTable extends Migration
{
    /**
     * Agrega la columna.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('ai_messages', function (Blueprint $table) {
            if (!Schema::hasColumn('ai_messages', 'valoracion_origen')) {
                /* sistema | reaccion_whatsapp | null (sin valorar, o valoración anterior a esta columna) */
                $table->string('valoracion_origen', 20)->nullable();
            }
        });
    }

    /**
     * Elimina la columna si existe.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('ai_messages', function (Blueprint $table) {
            if (Schema::hasColumn('ai_messages', 'valoracion_origen')) {
                $table->dropColumn('valoracion_origen');
            }
        });
    }
}
