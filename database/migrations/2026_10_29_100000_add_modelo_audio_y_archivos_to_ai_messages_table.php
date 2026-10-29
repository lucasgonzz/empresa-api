<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que un mensaje de la app «ComercioCity IA» trae además del texto (misión app-ia-y-memoria,
 * 29/10/2026, §4 E1.3 y E1.4 del plan):
 *
 *   - `modelo_pedido` (string 20): con qué modelo pidió el dueño ESE turno desde el selector de la app
 *     (`rapido` | `pensamiento`). Va en el mensaje del asistente; null = la configuración del dueño, que
 *     es lo de siempre.
 *   - `audio` (json): la nota de voz del dueño — `{path, nombre, ext, mime, tamano, duracion_seg,
 *     transcripcion}` (ver AudioDelMensajeIaHelper).
 *   - `archivos` (json): los PDF / Excel / CSV que mandó, con el texto que se les sacó — `[{orden, nombre,
 *     tipo, mime, tamano, path, estado, texto, recortado, error}]` (ver ArchivosAdjuntosIaHelper).
 *
 * Todas nullable y sin default: los mensajes que ya existen quedan en null y se sirven igual que hoy.
 * Con guard hasColumn, como las demás de ai_messages (varias bases de testing la corren por separado).
 */
class AddModeloAudioYArchivosToAiMessagesTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('ai_messages')) {
            return;
        }

        Schema::table('ai_messages', function (Blueprint $table) {

            if (!Schema::hasColumn('ai_messages', 'modelo_pedido')) {
                /* rapido | pensamiento | null (la config del dueño) */
                $table->string('modelo_pedido', 20)->nullable();
            }

            if (!Schema::hasColumn('ai_messages', 'audio')) {
                /* {path, nombre, ext, mime, tamano, duracion_seg, transcripcion} */
                $table->json('audio')->nullable();
            }

            if (!Schema::hasColumn('ai_messages', 'archivos')) {
                /* [{orden, nombre, tipo, mime, tamano, path, estado, texto, recortado, error}] */
                $table->json('archivos')->nullable();
            }
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('ai_messages')) {
            return;
        }

        Schema::table('ai_messages', function (Blueprint $table) {

            foreach (['modelo_pedido', 'audio', 'archivos'] as $columna) {
                if (Schema::hasColumn('ai_messages', $columna)) {
                    $table->dropColumn($columna);
                }
            }
        });
    }
}
