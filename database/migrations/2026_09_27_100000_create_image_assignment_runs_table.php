<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea `image_assignment_runs`: una fila por cada "asignación inteligente" de imágenes (misión
 * imagenes-catalogo-completo, 27/9/2026).
 *
 * Una asignación es un lanzamiento: el botón del listado sobre una selección, el asistente, o
 * "todo el catálogo" desde el acceso maestro. Sus artículos viven en `image_assignment_items`
 * (una fila por artículo, con su diagnóstico). Esta tabla guarda el estado de la corrida y los
 * contadores que la SPA muestra en Alertas → Imágenes: cuántos se procesaron y, sobre todo,
 * CUÁNTAS BÚSQUEDAS SE USARON (por código de barras y por nombre), que es lo que cuesta plata.
 *
 * Valores de `status`: pendiente | en_proceso | terminada | detenida | fallida.
 * Valores de `origen`: catalogo | seleccion | asistente.
 * Valores de `proveedor`: serper | google.
 *
 * Sin foreign keys por convención del proyecto: el dueño y el registro visible se resuelven en
 * Eloquent. Los contadores se escriben con incrementos atómicos (`procesados = procesados + 1`)
 * porque la corrida se procesa por tramos encadenados y un tramo reanudado no puede pisar lo que
 * sumó el anterior.
 */
class CreateImageAssignmentRunsTable extends Migration
{
    /**
     * Crea la tabla si todavía no existe.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('image_assignment_runs')) {
            return;
        }

        Schema::create('image_assignment_runs', function (Blueprint $table) {
            $table->id();

            // Dueño del comercio: todo endpoint filtra por esta columna.
            $table->unsignedBigInteger('user_id');
            $table->index('user_id', 'iar_user_idx');

            // Quién la lanzó (dueño, empleado o la sesión del acceso maestro). Null si fue el sistema.
            $table->unsignedBigInteger('auth_user_id')->nullable();

            // El mismo uuid que POST google/batch-assign-images devuelve como `batch_uuid` y que
            // viaja en el evento de Pusher: con él la pestaña reconoce SU corrida en un canal público.
            $table->string('uuid', 40);
            $table->index('uuid', 'iar_uuid_idx');

            // catalogo | seleccion | asistente.
            $table->string('origen', 20)->default('seleccion');

            // serper | google: con qué proveedor de búsqueda de imágenes se busca.
            $table->string('proveedor', 20)->default('google');

            // pendiente | en_proceso | terminada | detenida | fallida.
            $table->string('status', 20)->default('pendiente');

            // Por qué terminó así (cupo agotado, detenida, el error que la hizo fallar). Texto para mostrar.
            $table->text('motivo_estado')->nullable();

            // Artículos de la asignación y cuántos ya salieron del motor (con el resultado que sea).
            $table->unsignedInteger('total_articulos')->default(0);
            $table->unsignedInteger('procesados')->default(0);

            // Búsquedas que el proveedor respondió bien (aunque vinieran vacías), total y por criterio.
            $table->unsignedInteger('busquedas')->default(0);
            $table->unsignedInteger('busquedas_codigo')->default(0);
            $table->unsignedInteger('busquedas_nombre')->default(0);

            // Llamadas a la IA de visión que respondieron (las que se pagan).
            $table->unsignedInteger('validaciones_ia')->default(0);

            // true = consume el cupo diario de búsquedas del dueño (geocoder_counters) y corta al
            // agotarlo. Las de catálogo (acceso maestro) van en false: no le comen el cupo del día.
            $table->boolean('aplica_tope_diario')->default(true);

            // Tramos seguidos que murieron sin terminar (ver ProcessImageAssignmentRunJob::failed()).
            $table->unsignedTinyInteger('fallos_consecutivos')->default(0);

            // El registro visible (background_processes) de esta corrida.
            $table->unsignedBigInteger('background_process_id')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            // Señal de vida: se renueva en cada artículo. Una corrida en_proceso que no la renueva
            // hace más de 15 minutos se muestra como "trabada" y se puede reanudar.
            $table->timestamp('last_progress_at')->nullable();

            // El dueño abrió la corrida terminada en Alertas (deja de contar en el badge).
            $table->timestamp('visto_at')->nullable();

            $table->timestamps();

            // El listado de Alertas: las del dueño, más nuevas primero.
            $table->index(['user_id', 'created_at'], 'iar_user_created_idx');
        });
    }

    /**
     * Elimina la tabla (rollback completo).
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('image_assignment_runs');
    }
}
