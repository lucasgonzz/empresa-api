<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los adjuntos que el servidor le cuelga a una respuesta del asistente (misión
 * asistente-omnisciente, §1 del contrato, 21/9/2026).
 *
 * Cada elemento es `{ tipo, url, texto, articulo_id }`, con `tipo` = 'imagen' (hoy el único), `url`
 * la misma que devuelve la ficha del artículo (`ArticleHelper::getFirstImage`), `texto` el
 * epígrafe y `articulo_id` para que el dato sea trazable. Es lo que le permite al asistente
 * contestar "mostrame la foto": la SPA pinta las miniaturas debajo del texto y el admin las manda
 * por WhatsApp como imagen, cada una con su epígrafe.
 *
 * 🔴 POR QUÉ SE GUARDAN Y NO SE RECALCULAN AL LEER. Misma razón que `menciones`: se arman con lo
 * que devolvieron las tools de ESA respuesta (`mostrar_imagenes_de_articulos`), y eso solo existe
 * adentro del loop del job. Recalcularlas al servir el mensaje querría decir adivinar qué
 * artículos quiso mostrar el modelo buscando nombres en el texto, y cada recarga podría dar otra
 * foto. Guardadas, el dueño recarga y las fotos son exactamente las mismas.
 *
 * Nullable y sin default: todo mensaje que ya existe queda en null, que el modelo sirve como `[]`.
 * Una SPA vieja y un admin viejo ignoran la clave y ven exactamente lo de hoy.
 */
class AddAdjuntosToAiMessagesTable extends Migration
{
    /**
     * Agrega la columna, con guard hasColumn para que sea segura de re-ejecutar (la misma
     * disciplina que la de `menciones`: tres bases de testing la corren por separado).
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('ai_messages') || Schema::hasColumn('ai_messages', 'adjuntos')) {
            return;
        }

        Schema::table('ai_messages', function (Blueprint $table) {

            /* [{ tipo, url, texto, articulo_id }] con las imágenes que la respuesta lleva adjuntas */
            $table->json('adjuntos')->nullable()->after('menciones');
        });
    }

    /**
     * Quita la columna.
     *
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('ai_messages') || !Schema::hasColumn('ai_messages', 'adjuntos')) {
            return;
        }

        Schema::table('ai_messages', function (Blueprint $table) {
            $table->dropColumn('adjuntos');
        });
    }
}
