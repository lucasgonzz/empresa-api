<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Líneas de una corrida de sugerencia de compra: una fila por artículo que el
 * motor decidió que hay que reponer, con la cantidad calculada y a qué
 * proveedor conviene comprarle (ver PurchaseSuggestionService y
 * OfertasDeProveedorService::mejores_ofertas_para()).
 *
 * - provider_id: el proveedor ELEGIDO para esta línea (oferta vigente más
 *   barata, en la misma moneda que el titular — ver la regla de elección en
 *   el plan). Nullable: sin titular y sin ninguna oferta vigente, la línea
 *   igual se guarda con provider_id null, agrupada como "sin proveedor
 *   asignado" — el usuario necesita ver que le falta comprar eso, no que la
 *   línea desaparezca.
 * - provider_id_titular: articles.provider_id al momento de la corrida.
 *   Se guarda aparte del elegido para poder mostrar "se cambiaría de
 *   proveedor" en la vista sin tener que ir a buscarlo a `articles`.
 * - cantidad_sugerida: max(cantidad por cobertura, cantidad por mínimo),
 *   redondeada para arriba, piso 1.
 * - stock_global / stock_min_global: suma de todas las sucursales del
 *   comercio al momento del cálculo (foto auditable: una venta de mañana no
 *   reescribe esta corrida).
 * - velocidad_diaria / cobertura_dias: salida de
 *   CoberturaService::velocidades_globales() (NO velocidades_para(): ver el
 *   docblock de ese método sobre por qué no son sumables por sucursal).
 * - costo_estimado: costo NETO del proveedor elegido para esta línea.
 * - costo_proveedor_titular / ahorro_estimado: para poder mostrar cuánto se
 *   ahorra cambiando de proveedor. Ambos quedan null cuando el elegido ES el
 *   titular (no hay ahorro que mostrar) o falta alguno de los dos costos.
 * - oferta_fecha: fecha de la oferta que ganó, para que la vista pueda
 *   marcar ofertas viejas aunque sigan dentro de la ventana de vigencia.
 * - prioridad: ranking 1..N materializado por PrioridadComprasService en una
 *   pasada final, no calculado al leer (mismo patrón que
 *   stock_suggestion_articles.prioridad).
 *
 * Sin foreign keys físicas, siguiendo el estilo del resto del schema.
 */
class CreatePurchaseSuggestionArticlesTable extends Migration
{
    /**
     * Crea la tabla, con guard hasTable para que sea segura de re-ejecutar.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('purchase_suggestion_articles')) {
            return;
        }

        Schema::create('purchase_suggestion_articles', function (Blueprint $table) {

            /* Clave primaria autoincremental */
            $table->id();

            /* Cabecera de la corrida (purchase_suggestions.id) */
            $table->integer('purchase_suggestion_id');

            /* Artículo a reponer */
            $table->integer('article_id');

            /* Proveedor elegido para comprar esta línea, null si no hay ninguno disponible */
            $table->integer('provider_id')->nullable();

            /* articles.provider_id al momento de la corrida, para comparar contra el elegido */
            $table->integer('provider_id_titular')->nullable();

            /* Cantidad a comprar: max(cobertura, mínimo), ceil, piso 1 */
            $table->decimal('cantidad_sugerida', 20, 2);

            /* Foto de stock al momento del cálculo (todas las sucursales sumadas) */
            $table->decimal('stock_global', 20, 2)->nullable();
            $table->decimal('stock_min_global', 20, 2)->nullable();

            /* Salida de CoberturaService::velocidades_globales() / cobertura() */
            $table->decimal('velocidad_diaria', 14, 4)->nullable();
            $table->decimal('cobertura_dias', 14, 2)->nullable();

            /* Costo NETO del proveedor elegido y del titular, para calcular el ahorro */
            $table->decimal('costo_estimado', 22, 6)->nullable();
            $table->decimal('costo_proveedor_titular', 22, 6)->nullable();
            $table->decimal('ahorro_estimado', 22, 6)->nullable();

            /* Fecha de la oferta que ganó */
            $table->date('oferta_fecha')->nullable();

            /* Ranking 1..N materializado al cerrar la corrida */
            $table->integer('prioridad')->nullable();

            $table->timestamps();

            /* Orden por urgencia dentro de una corrida (endpoint paginado, default) */
            $table->index(['purchase_suggestion_id', 'prioridad'], 'purchase_suggestion_articles_suggestion_prioridad_index');

            /* Filtro por proveedor dentro de una corrida (endpoint paginado) */
            $table->index(['purchase_suggestion_id', 'provider_id'], 'purchase_suggestion_articles_suggestion_provider_index');
        });
    }

    /**
     * Elimina la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('purchase_suggestion_articles');
    }
}
