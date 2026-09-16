<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las menciones que el servidor le anotó a una respuesta del asistente (misión
 * agente-ia-mano-derecha, §1 del contrato, 16/9/2026).
 *
 * Cada elemento es `{ tipo, id, texto }`, con `tipo` ∈ cliente|articulo y `texto` el literal exacto
 * tal como aparece en `contenido`. Es lo que le permite a la SPA pintar el nombre como clickeable
 * SIN que el texto del mensaje cambie: las menciones ANOTAN, no reemplazan.
 *
 * 🔴 POR QUÉ SE GUARDAN Y NO SE RECALCULAN AL LEER. Se arman cruzando lo que devolvieron las tools
 * de ESA respuesta contra el texto final, y eso solo existe adentro del loop del job. Recalcularlas
 * al servir el mensaje querría decir volver a correr las consultas —o peor, adivinar los ids
 * buscando nombres en la base—, y cada recarga de pantalla podría dar un resultado distinto al de
 * la primera vez. Guardadas, el dueño recarga y las menciones son exactamente las mismas.
 *
 * Nullable y sin default: todo mensaje que ya existe queda en null, que el modelo sirve como `[]`.
 * Una SPA vieja que ignora la clave ve exactamente lo de hoy.
 */
class AddMencionesToAiMessagesTable extends Migration
{
    /**
     * Agrega la columna, con guard hasColumn para que sea segura de re-ejecutar.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('ai_messages') || Schema::hasColumn('ai_messages', 'menciones')) {
            return;
        }

        Schema::table('ai_messages', function (Blueprint $table) {

            /* [{ tipo, id, texto }] con los clientes y artículos nombrados en `contenido` */
            $table->json('menciones')->nullable()->after('acciones_habilitadas');
        });
    }

    /**
     * Quita la columna.
     *
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('ai_messages') || !Schema::hasColumn('ai_messages', 'menciones')) {
            return;
        }

        Schema::table('ai_messages', function (Blueprint $table) {
            $table->dropColumn('menciones');
        });
    }
}
