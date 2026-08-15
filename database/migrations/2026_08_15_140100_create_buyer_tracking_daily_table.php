<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agregado diario de los eventos de comportamiento de los compradores
 * (misión tracking-buyers-tienda). Lo escribe `tracking:agregar-buyers` a las
 * 03:30, colapsando el día anterior de `buyer_tracking_events`.
 *
 * El porqué de que exista: los eventos crudos se purgan a los 90 días
 * (decisión de Lucas, 15/8/2026) porque son de alto volumen. Esta tabla NO se
 * purga nunca, y es lo que hace barata la lectura del ERP con años de historia:
 * "cuántas veces vieron este artículo en 2025" se contesta sobre miles de filas
 * agregadas en vez de millones de eventos que ya ni existen.
 *
 * Nombre en singular a propósito, igual que `demo_tracking_config`: es una tabla
 * de agregado diario, no una colección de "dailies". El modelo lo declara con
 * $table explícito porque Eloquent inferiría `buyer_tracking_dailies`.
 *
 * Los tipos de las FK lógicas están espejados de su tabla de origen, iguales a
 * los de `buyer_tracking_events` (ver esa migración para el detalle de cada uno).
 *
 * Sin foreign keys físicas, siguiendo el estilo del resto del schema.
 */
class CreateBuyerTrackingDailyTable extends Migration
{
    /**
     * Crea la tabla, con guard hasTable para que sea segura de re-ejecutar.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('buyer_tracking_daily')) {
            return;
        }

        Schema::create('buyer_tracking_daily', function (Blueprint $table) {

            /* Clave primaria autoincremental */
            $table->id();

            /* Comercio dueño de la instancia; espejado de `users.id` = increments (2014_10_12_000000:17) */
            $table->unsignedInteger('user_id');

            /* Día agregado (el día de occurred_at de los eventos que colapsó) */
            $table->date('fecha');

            /* Comprador; NULL agrupa a todos los visitantes anónimos del día. Espejado de `buyers.id` (2020_05_01_143016:17) */
            $table->unsignedBigInteger('buyer_id')->nullable();

            /* Artículo; espejado de `articles.id` (2019_10_24_002337:17) */
            $table->unsignedBigInteger('article_id')->nullable();

            /* Tipo de evento agregado; misma lista blanca que los eventos crudos */
            $table->string('event_type', 32);

            /* Cantidad de eventos crudos que colapsaron en esta fila */
            $table->unsignedInteger('total');

            /* Suma de dwell_ms del grupo. bigInteger porque son milisegundos sumados: un día de tienda desborda unsignedInteger (49 días) sin esfuerzo */
            $table->unsignedBigInteger('dwell_ms_total')->default(0);

            /* Suma de amount del grupo, mismo ancho que los montos del sistema */
            $table->decimal('amount_total', 22, 2)->default(0);

            $table->timestamps();

            /*
             * Barrido del ERP por comercio y ventana de fechas, y además la
             * consulta con la que el rollup borra el día antes de reinsertarlo.
             *
             * 🔴 Índice NORMAL, NO unique — y esto es a propósito, no un olvido.
             * El unique natural sería (user_id, fecha, buyer_id, article_id,
             * event_type), pero tres de esas cinco columnas son nullable y en MySQL
             * NULL != NULL: dos filas con buyer_id null NUNCA chocarían, así que el
             * unique no protegería de nada mientras cobraría el costo de un índice
             * más ancho. La idempotencia no la da el esquema, la da el comando:
             * `tracking:agregar-buyers` BORRA el día completo del comercio y lo
             * reinserta, así que correrlo dos veces deja exactamente el mismo
             * resultado y se puede recorrer el histórico hacia atrás sin duplicar.
             * Si alguien viene a "arreglar esto" poniendo un unique, esto es lo que
             * tiene que leer primero.
             */
            $table->index(['user_id', 'fecha'], 'btd_user_fecha_index');

            /* Historial de un comprador puntual sin escanear el día entero */
            $table->index(['buyer_id', 'fecha'], 'btd_buyer_fecha_index');

            /* Historial de un artículo puntual (el que va a usar el motor de ofertas) */
            $table->index(['article_id', 'fecha'], 'btd_articulo_fecha_index');
        });
    }

    /**
     * Elimina la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('buyer_tracking_daily');
    }
}
