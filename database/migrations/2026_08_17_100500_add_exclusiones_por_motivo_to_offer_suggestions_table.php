<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suma a la cabecera de una corrida del motor de ofertas el desglose de POR QUÉ un artículo
 * candidato no llegó a ser una sugerencia.
 *
 * `total_clientes_excluidos_por_deuda` ya contaba a la gente que quedó afuera; esto cuenta a los
 * ARTÍCULOS, que es la otra mitad de la misma pregunta. `TechoDeDescuentoService::evaluar()` devuelve
 * un motivo por cada línea que descarta (sin costo cargado, sin precio, margen no positivo, techo por
 * debajo del mínimo, costo en dólares en una cuenta con listas) y hasta el 15/8/2026 ese motivo se
 * tiraba a la basura en un `continue`: un artículo desaparecía de la corrida sin que nada lo dijera.
 *
 * La diferencia entre "no encontré nada" y "miré 800 artículos y 200 no tienen el costo cargado" no
 * es una estadística: la segunda es una tarea concreta para el comerciante, y es la única forma de
 * que se entere de que tiene el catálogo a medio cargar.
 *
 * Forma de la columna: JSON `{"sin_costo": 200, "margen_no_positivo": 12}`, motivo => cantidad de
 * líneas candidatas descartadas. Las claves son las constantes EXCLUIDO_* de TechoDeDescuentoService
 * y el texto en criollo lo pone OfertaSugeridaService::texto_de_exclusion(), en un solo lugar para
 * los dos consumidores (la vista y el bloque de datos de la IA).
 *
 * 🔴 Por qué un JSON en una columna y no una tabla `offer_suggestion_exclusions`: nadie joinea,
 * filtra ni agrega por esto — se lee entero junto con la cabecera y se muestra entero. Es el mismo
 * criterio con el que `offer_suggestion_lines.tramos_sugeridos` guarda los tramos del borrador
 * (§A.3): una tabla solo se justifica del lado que alguien consulta por partes.
 *
 * 🔴 Y por qué una migración nueva en vez de agregar la columna en el `create` de
 * 2026_08_17_100000: ese `create` arranca con `if (Schema::hasTable(...)) { return; }`, así que en
 * cualquier base donde la tabla ya exista —todas las de testing de los slots, y la de Lucas— editarlo
 * no aplicaría nada y la columna faltaría sin que ningún `migrate` lo avise. Aditivo y con guard
 * `hasColumn`, como el resto del schema.
 *
 * INFORMATIVO: se llena al calcular cada lote y NUNCA alimenta el cálculo de ningún porcentaje.
 */
class AddExclusionesPorMotivoToOfferSuggestionsTable extends Migration
{
    /** @var array Columnas que agrega esta migración */
    const COLUMNAS = ['exclusiones_por_motivo'];

    /**
     * Agrega la columna, con guard hasColumn para que sea segura de re-ejecutar.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('offer_suggestions')) {
            return;
        }

        Schema::table('offer_suggestions', function (Blueprint $table) {

            if (!Schema::hasColumn('offer_suggestions', 'exclusiones_por_motivo')) {
                /*
                 * text y no json: el resto del schema guarda sus JSON en text/longText (por ejemplo
                 * offer_suggestion_lines.tramos_sugeridos), y acá no hace falta ninguna de las
                 * operaciones de MySQL sobre JSON — se lee y se escribe entero. Nullable porque una
                 * corrida vieja, o una que todavía no terminó, no tiene desglose y eso NO es lo mismo
                 * que "no excluyó nada" (que es `{}`).
                 */
                $table->text('exclusiones_por_motivo')->nullable();
            }
        });
    }

    /**
     * Saca la columna, iterando el array con guard (molde del resto de los add_ del repo).
     *
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('offer_suggestions')) {
            return;
        }

        Schema::table('offer_suggestions', function (Blueprint $table) {

            foreach (self::COLUMNAS as $columna) {

                if (Schema::hasColumn('offer_suggestions', $columna)) {
                    $table->dropColumn($columna);
                }
            }
        });
    }
}
