<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea `image_assignment_items`: una fila por artículo de cada asignación inteligente de imágenes
 * (misión imagenes-catalogo-completo, 27/9/2026). Es lo que la SPA muestra en las tres solapas
 * del detalle de una asignación (No asignadas / A revisar / Asignadas), con el diagnóstico de
 * por qué quedó así.
 *
 * Valores de `status`:
 *   pendiente | procesando | asignada | a_revisar | no_asignada | aprobada | rechazada | quitada |
 *   sin_procesar.
 *
 * `motivo` es un código corto (ver el contrato §5.2 del plan): sin_resultados, imagenes_chicas,
 * no_corresponden, ia_dudosa, confianza_media, ... y `motivo_detalle` es la frase lista para
 * mostrar.
 *
 * `diagnostico` (json) guarda, por criterio de búsqueda (código de barras y nombre), la consulta,
 * cuántas búsquedas gastó, cuántos resultados trajo y qué pasó con cada candidata. Un criterio que
 * NO se usó (código inventado) también va, con `usado: false` y el motivo: así se ve por qué no se
 * buscó por código.
 *
 * `imagen_meta` (json) guarda los datos de la imagen elegida (medidas, fondo blanco medido, dominio,
 * veredicto de la IA y avisos).
 *
 * Las "a revisar" NO son filas de `images` (la tienda no las ve): su archivo vive como
 * `imgcand_<uuid>.webp` en el storage y recién aprobarla crea la fila de `images`. Por eso
 * `imagen_archivo` e `image_id` son columnas distintas.
 *
 * Snapshot de nombre y código de barras: el artículo puede editarse o borrarse después, y el
 * diagnóstico tiene que seguir mostrando con qué datos se buscó.
 *
 * Sin foreign keys por convención del proyecto.
 */
class CreateImageAssignmentItemsTable extends Migration
{
    /**
     * Crea la tabla si todavía no existe.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('image_assignment_items')) {
            return;
        }

        Schema::create('image_assignment_items', function (Blueprint $table) {
            $table->id();

            // Asignación a la que pertenece.
            $table->unsignedBigInteger('run_id');
            $table->index('run_id', 'iai_run_idx');

            // Dueño del comercio (repetido acá para los filtros por dueño sin pasar por la corrida).
            $table->unsignedBigInteger('user_id');

            // Artículo buscado.
            $table->unsignedBigInteger('article_id');

            // Snapshot del artículo al crear la asignación.
            $table->string('article_name', 255)->nullable();
            $table->string('article_bar_code', 100)->nullable();

            // Orden de procesamiento dentro de la corrida (1..N): el de la selección, o el de
            // prioridad del catálogo (publicados en la tienda → con stock → el resto).
            $table->unsignedInteger('orden')->default(0);

            // Estado del artículo dentro de la corrida (ver el docblock de la clase).
            $table->string('status', 20)->default('pendiente');

            // Código del motivo principal y la frase lista para mostrar.
            $table->string('motivo', 40)->nullable();
            $table->text('motivo_detalle')->nullable();

            // codigo_de_barras | nombre: de qué búsqueda salió la imagen elegida.
            $table->string('criterio_usado', 20)->nullable();

            // Búsquedas y validaciones con IA que gastó este artículo.
            $table->unsignedTinyInteger('busquedas')->default(0);
            $table->unsignedTinyInteger('validaciones_ia')->default(0);

            // Veces que un tramo lo reclamó para procesarlo. Con 2 intentos fallidos el artículo
            // queda como error_interno: uno "venenoso" no puede trabar la corrida entera.
            $table->unsignedTinyInteger('intentos')->default(0);

            // Diagnóstico por criterio de búsqueda (ver el docblock de la clase).
            $table->json('diagnostico')->nullable();

            // La imagen elegida: URL pública, nombre del archivo en storage y sus datos.
            $table->string('imagen_url', 500)->nullable();
            $table->string('imagen_archivo', 120)->nullable();
            $table->json('imagen_meta')->nullable();

            // La fila de `images` cuando quedó asignada (directo o al aprobarla).
            $table->unsignedBigInteger('image_id')->nullable();

            // Quién la aprobó / rechazó / quitó, y cuándo.
            $table->unsignedBigInteger('revisado_por')->nullable();
            $table->timestamp('revisado_at')->nullable();

            // Cuándo terminó el motor con este artículo.
            $table->timestamp('procesado_at')->nullable();

            $table->timestamps();

            // Solapas del detalle (por estado) y el próximo pendiente del tramo (por orden).
            $table->index(['run_id', 'status'], 'iai_run_status_idx');
            $table->index(['run_id', 'orden'], 'iai_run_orden_idx');

            // La selección del catálogo: "¿este artículo está a revisar o ya se buscó sin éxito?".
            $table->index(['user_id', 'article_id'], 'iai_user_article_idx');

            // El badge de Alertas: cuántas imágenes esperan revisión en todo el comercio.
            $table->index(['user_id', 'status'], 'iai_user_status_idx');
        });
    }

    /**
     * Elimina la tabla (rollback completo).
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('image_assignment_items');
    }
}
