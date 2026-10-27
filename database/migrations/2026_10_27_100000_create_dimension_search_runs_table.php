<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea `dimension_search_runs`: una fila por cada búsqueda masiva de peso y medidas (misión
 * medidas-masivas-catalogo, 27/10/2026).
 *
 * Es el espejo de `image_assignment_runs`: un lanzamiento que busca en internet el peso y las medidas
 * (`peso`, `alto`, `ancho`, `profundidad`) de un grupo de artículos del dueño. Hoy nace de "todo el
 * catálogo" desde el acceso maestro o del comando `medidas:buscar` (`catalogo`); el origen `seleccion`
 * queda previsto para una futura entrada desde el Listado pero esta misión no construye su interfaz.
 * Los artículos viven en `dimension_search_items` (una fila por artículo, con su evidencia y su
 * diagnóstico). Esta tabla guarda el estado de la corrida y los contadores de gasto (búsquedas de
 * Serper y llamadas a la IA), que es lo que cuesta plata.
 *
 * Valores de `status`: pendiente | en_proceso | terminada | detenida | fallida.
 * Valores de `origen`: catalogo | seleccion.
 * Valores de `proveedor`: serper.
 *
 * Los contadores por estado de los artículos NO se guardan: se arman con una consulta agrupada sobre
 * `dimension_search_items` (igual que en imágenes).
 *
 * Sin foreign keys por convención del proyecto. Los contadores se escriben con incrementos atómicos
 * (`procesados = procesados + 1`) porque la corrida se procesa por tramos encadenados y un tramo
 * reanudado no puede pisar lo que sumó el anterior.
 */
class CreateDimensionSearchRunsTable extends Migration
{
    /**
     * Crea la tabla si todavía no existe.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('dimension_search_runs')) {
            return;
        }

        Schema::create('dimension_search_runs', function (Blueprint $table) {
            $table->id();

            // Dueño del comercio: todo endpoint filtra por esta columna.
            $table->unsignedBigInteger('user_id');
            $table->index('user_id', 'dsr_user_idx');

            // Quién la lanzó (la sesión del acceso maestro, o null si fue el comando de consola).
            $table->unsignedBigInteger('creada_por')->nullable();

            // catalogo | seleccion.
            $table->string('origen', 20)->default('catalogo');

            // pendiente | en_proceso | terminada | detenida | fallida.
            $table->string('status', 20)->default('pendiente');

            // Por qué terminó así (detenida, el error que la hizo fallar). Texto para mostrar.
            $table->text('motivo_estado')->nullable();

            // true = solo los artículos con stock (articles.stock > 0) entraron a la corrida.
            $table->boolean('solo_con_stock')->default(false);

            // Artículos de la corrida y cuántos ya salieron del motor (con el resultado que sea).
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('procesados')->default(0);

            // serper: con qué buscador se busca.
            $table->string('proveedor', 20)->default('serper');

            // Gasto: búsquedas de Serper que respondieron bien, llamadas a la IA que respondieron y
            // los tokens de esas llamadas.
            $table->unsignedInteger('busquedas')->default(0);
            $table->unsignedInteger('validaciones_ia')->default(0);
            $table->unsignedBigInteger('tokens_entrada')->default(0);
            $table->unsignedBigInteger('tokens_salida')->default(0);

            // Artículos seguidos en los que se le pidió algo a la IA y ninguna llamada respondió. Con
            // 5 seguidos la corrida se frena como fallida (reanudable).
            $table->unsignedSmallInteger('errores_ia_seguidos')->default(0);

            // Artículos seguidos en los que TODAS las búsquedas fallaron por el proveedor (sin
            // créditos, clave revocada, caído). Mismo corte que la IA.
            $table->unsignedSmallInteger('errores_proveedor_seguidos')->default(0);

            // Tramos seguidos que murieron sin terminar (ver ProcessDimensionSearchRunJob::failed()).
            $table->unsignedTinyInteger('fallos_consecutivos')->default(0);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            // Señal de vida: se renueva en cada artículo. Una corrida en_proceso que no la renueva
            // hace más de 15 minutos se muestra como "trabada" y se puede reanudar.
            $table->timestamp('last_progress_at')->nullable();

            // El dueño abrió la corrida terminada en Alertas (deja de contar en el badge).
            $table->timestamp('visto_at')->nullable();

            $table->timestamps();

            // El listado de Alertas: las del dueño, más nuevas primero.
            $table->index(['user_id', 'created_at'], 'dsr_user_created_idx');
        });
    }

    /**
     * Elimina la tabla (rollback completo).
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('dimension_search_runs');
    }
}
