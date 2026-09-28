<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea `image_service_calls`: el registro de TODAS las consultas a servicios externos del circuito
 * de imágenes de artículos (misión imagenes-catalogo-completo, agregado del 27/9/2026, plan §12.1).
 * Una fila por llamada: cada búsqueda a Serper / Google y cada validación con IA (Anthropic).
 *
 * Pedido de Lucas: "que quede registro de todas las consultas que se hacen tanto a esta nueva API
 * para obtener las imágenes como a la IA para chequear si la imagen pertenece al artículo, y poder
 * verlo desde el admin en cada cliente". Lo lee AdminSync\ImagenesController.
 *
 * Valores:
 *   origen:    asignacion (el motor de las asignaciones inteligentes) | validacion_individual
 *              (ArticleImageValidationService::validate(): la búsqueda por código de barras del
 *              asistente y el lote viejo).
 *   tipo:      busqueda | validacion_ia
 *   proveedor: serper | google | anthropic
 *   criterio:  codigo_de_barras | nombre (null en validacion_individual)
 *   ok:        el servicio respondió bien.  cobrada: el servicio la cobra (una búsqueda que
 *              respondió bien aunque viniera vacía; una llamada a la IA que Anthropic respondió).
 *
 * 🔴 `error` NUNCA lleva claves: lo tapa ImageServiceCallLogger antes de grabar (un timeout de
 * Guzzle trae la URL entera, y la de Google lleva `?key=`).
 *
 * NO guarda costo: la plata la pone el admin con su propia tabla de precios, para poder corregirla
 * sin tocar a los clientes (mismo criterio que `ai_token_usages` y `ia_precios.php`). Los tokens
 * también quedan en `ai_token_usages` como siempre: acá van repetidos por llamada, para el detalle.
 *
 * Retención: 180 días (se purga al crear una asignación, ImageServiceCallLogger::purgar_viejas()).
 *
 * Sin foreign keys por convención del proyecto.
 */
class CreateImageServiceCallsTable extends Migration
{
    /**
     * Crea la tabla si todavía no existe.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('image_service_calls')) {
            return;
        }

        Schema::create('image_service_calls', function (Blueprint $table) {
            $table->id();

            // Dueño del comercio al que se le imputa la consulta.
            $table->unsignedBigInteger('user_id');

            // De qué asignación y de qué artículo de la asignación salió (null en validacion_individual).
            $table->unsignedBigInteger('run_id')->nullable();
            $table->unsignedBigInteger('item_id')->nullable();

            // El artículo (null si la validación fue sobre un artículo que no existe en la base, como
            // el de la búsqueda por código de barras del asistente) y su nombre al momento de consultar.
            $table->unsignedBigInteger('article_id')->nullable();
            $table->string('article_name', 255)->nullable();

            // Qué consulta fue (ver el docblock de la clase).
            $table->string('origen', 30)->default('asignacion');
            $table->string('tipo', 20)->default('busqueda');
            $table->string('proveedor', 20)->default('');
            $table->string('modelo', 80)->nullable();
            $table->string('criterio', 20)->nullable();
            $table->string('consulta', 255)->nullable();

            // Cómo salió.
            $table->boolean('ok')->default(false);
            $table->boolean('cobrada')->default(false);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error', 500)->nullable();

            // Qué devolvió: resultados de una búsqueda, candidatas que vio la IA y un resumen legible
            // ("10 resultados", "2 sí, 1 no, 1 dudosa").
            $table->unsignedSmallInteger('resultados')->nullable();
            $table->unsignedTinyInteger('candidatas')->nullable();
            $table->string('resumen', 255)->nullable();

            // Tokens de la llamada a la IA (null en las búsquedas).
            $table->unsignedInteger('tokens_entrada')->nullable();
            $table->unsignedInteger('tokens_salida')->nullable();
            $table->unsignedInteger('tokens_cache_escritura')->nullable();
            $table->unsignedInteger('tokens_cache_lectura')->nullable();

            // Cuánto tardó el servicio en responder.
            $table->unsignedInteger('duracion_ms')->nullable();

            $table->timestamps();

            // El admin pide siempre "las de este dueño en este rango de días", de la más nueva a la
            // más vieja; y el filtro por asignación del registro de consultas.
            $table->index(['user_id', 'created_at'], 'isc_user_created_idx');
            $table->index('run_id', 'isc_run_idx');
        });
    }

    /**
     * Elimina la tabla (rollback completo).
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('image_service_calls');
    }
}
