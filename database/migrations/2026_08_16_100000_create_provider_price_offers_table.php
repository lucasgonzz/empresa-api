<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla del histórico de precios ofertados por proveedor (misión sugerencias de
 * compra a proveedores).
 *
 * Cada fila es "en esta fecha, este proveedor ofrecía este artículo a este
 * costo": un HECHO de precio fechado, no un estado actual. El estado actual
 * sigue viviendo en `article_provider` (esta tabla no lo reemplaza).
 *
 * - user_id: dueño (articles.user_id). Sin esta columna no hay forma de
 *   escribir una query scopeada por comercio: `article_provider` no tiene
 *   user_id, y ese es justo el agujero que esta tabla no puede heredar.
 * - cost: decimal(22,6), la misma precisión que articles.cost. El pivot
 *   article_provider.cost es INT — todo costo con centavos entra redondeado
 *   ahí — y esta tabla nace para no repetir ese error.
 * - moneda_id: 1 = Peso, 2 = Dólar, default 1. Sin conversión entre monedas:
 *   una oferta en dólares se guarda tal cual, no se convierte a pesos.
 * - origen: 'importacion' (lista de precios por Excel) | 'compra' (se le
 *   compró de verdad a ese precio) | 'manual' (carga a mano, hoy sin UI: la
 *   constante existe para no tener que cambiar el esquema el día que exista)
 *   | 'pivot_dedupe' (fila rescatada del pivot por la migración de dedupe).
 * - fecha: día de vigencia de la oferta. NO es created_at: la migración de
 *   dedupe backfilea filas con fecha vieja (la del pivot que rescata), y
 *   necesita una columna de negocio separada de "cuándo se escribió la fila".
 * - referencia_id: import_history_id o provider_order_id según el origen, o
 *   null (manual, pivot_dedupe). Sin FK física, es solo trazabilidad.
 *
 * El unique (article_id, provider_id, fecha, origen) es la pieza clave de la
 * deduplicación temporal: una lista de precios se reimporta entera cada
 * semana, y sin este unique "una fila por importación" son millones de filas
 * idénticas con el correr de los meses. El detalle de esa regla (por qué se
 * compara con epsilon, por qué el upsert pisa solo cost/provider_code/
 * referencia_id/updated_at) vive en OfertasDeProveedorService, el único
 * punto de escritura de esta tabla — acá solo el esqueleto que lo sostiene.
 * El unique cubre por prefijo izquierdo las lecturas por article_id solo y
 * por (article_id, provider_id).
 *
 * Sin foreign keys físicas, siguiendo el estilo del resto del schema.
 */
class CreateProviderPriceOffersTable extends Migration
{
    /**
     * Crea la tabla, con guard hasTable para que sea segura de re-ejecutar.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('provider_price_offers')) {
            return;
        }

        Schema::create('provider_price_offers', function (Blueprint $table) {

            /* Clave primaria autoincremental */
            $table->id();

            /* Dueño del artículo (articles.user_id): sin esto no hay query scopeada */
            $table->integer('user_id');

            /* Artículo ofertado */
            $table->integer('article_id');

            /* Proveedor que hizo la oferta */
            $table->integer('provider_id');

            /* Código con el que ESE proveedor llama al artículo, si vino en la oferta */
            $table->string('provider_code', 191)->nullable();

            /* Costo NETO ofertado, misma precisión que articles.cost */
            $table->decimal('cost', 22, 6);

            /* 1 = Peso, 2 = Dólar. Sin conversión: se guarda tal cual llegó la oferta */
            $table->integer('moneda_id')->default(1);

            /* 'importacion' | 'compra' | 'manual' | 'pivot_dedupe' */
            $table->string('origen', 20);

            /* Día de vigencia de la oferta (no created_at: el dedupe backfilea fechas viejas) */
            $table->date('fecha');

            /* import_history_id | provider_order_id | null, según origen */
            $table->integer('referencia_id')->nullable();

            $table->timestamps();

            /* Motor de la deduplicación temporal: una fila por combinación día/origen */
            $table->unique(
                ['article_id', 'provider_id', 'fecha', 'origen'],
                'provider_price_offers_articulo_proveedor_fecha_origen_unique'
            );

            /* Historial de precios de un proveedor puntual, ordenado por fecha */
            $table->index(['provider_id', 'fecha'], 'provider_price_offers_proveedor_fecha_index');

            /* Barrido de todas las ofertas de un comercio a una fecha */
            $table->index(['user_id', 'fecha'], 'provider_price_offers_user_fecha_index');
        });
    }

    /**
     * Elimina la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('provider_price_offers');
    }
}
