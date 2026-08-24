<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Eventos crudos de comportamiento de los compradores en la tienda (misión
 * tracking-buyers-tienda). Esta tabla la CREA empresa-api y la ESCRIBE tienda-api:
 * el contrato entre los dos proyectos no es un endpoint, es esta base compartida.
 * Por ahora nadie la lee — la lectura desde el ERP (pantallas y motor de ofertas)
 * es una misión aparte.
 *
 * El porqué de que sea una tabla nueva y no una ampliación de `views` /
 * `last_searches`: esas dos tienen código vivo de la tienda apuntándoles
 * (ArticleViewedEventListener, LastSearchController@store, Buyer::views(),
 * LimpiarInventarioHelper) y tocarlas sería un cambio no aditivo. Acá se agrega,
 * no se migra.
 *
 * Es la tabla de alto volumen del sistema: una vista de producto de cada visitante
 * anónimo escribe una fila. De ahí salen las dos decisiones que la definen:
 *
 *   - Solo CUATRO índices. Cada índice extra es un árbol más que se actualiza en
 *     cada insert, y esta tabla es de escritura intensiva. La lista está pensada,
 *     no es la que fue quedando: ver el comentario de cada uno abajo.
 *   - Retención de 90 días (decisión de Lucas, 15/8/2026). La purga la hace
 *     `tracking:purgar-buyers`; lo que sobrevive al corte es el agregado diario de
 *     `buyer_tracking_daily`, que no se purga nunca.
 *
 * Los tipos de las FK lógicas están ESPEJADOS de su tabla de origen, verificados
 * contra las migraciones y contra el esquema real. No se elige "el que parezca
 * alcanzar": una columna derivada más angosta que su fuente es una clase de error
 * ya cometida en este repo — `views.buyer_id` es `integer unsigned` mientras
 * `buyers.id` es `bigint unsigned`, y esa inconsistencia sigue heredada ahí.
 *
 * Sin foreign keys físicas, siguiendo el estilo del resto del schema.
 *
 * Nota de operación (igual que 2026_08_14_120400 y 2026_07_30_150000): en MySQL 5.7
 * y 8, crear un índice secundario usa ALGORITHM=INPLACE por default y no bloquea
 * escrituras, pero en una tabla de 10^5 a 10^6 filas la creación puede tardar
 * varios minutos y consumir I/O. Hoy no aplica —la tabla nace vacía— pero el día
 * que alguien agregue un índice acá, esa migración va corrida fuera del horario de
 * atención del comercio.
 */
class CreateBuyerTrackingEventsTable extends Migration
{
    /**
     * Crea la tabla, con guard hasTable para que sea segura de re-ejecutar.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('buyer_tracking_events')) {
            return;
        }

        Schema::create('buyer_tracking_events', function (Blueprint $table) {

            /* Clave primaria autoincremental */
            $table->bigIncrements('id');

            /*
             * Comercio dueño de la instancia (config app.USER_ID).
             * Espejado de `users.id`, que es increments() = int unsigned
             * (2014_10_12_000000_create_users_table.php:17). NO unsignedBigInteger:
             * users es la única de las seis tablas de origen de esta misión cuyo id
             * no es bigint, y un tipo distinto al de la fuente rompe el uso del
             * índice en un join contra users.
             */
            $table->unsignedInteger('user_id');

            /*
             * Comprador logueado. NULL = visitante anónimo, que es el caso NORMAL
             * y no el borde: en tienda-api el grupo auth:sanctum está comentado y
             * la mayoría del tráfico de una tienda no está logueado.
             * Espejado de `buyers.id` = bigIncrements
             * (2020_05_01_143016_create_buyers_table.php:17).
             */
            $table->unsignedBigInteger('buyer_id')->nullable();

            /*
             * UUID del visitante, persistido en el localStorage del navegador.
             * Va SIEMPRE, también en los logueados: es lo que permite reconstruir
             * el recorrido previo al login de un comprador que después compra.
             */
            $table->char('visitor_id', 36);

            /* UUID de la sesión de navegación (se renueva a los 30 min de inactividad) */
            $table->char('session_id', 36);

            /* Tipo de evento; lista blanca en BuyerTrackingEvent::TIPOS */
            $table->string('event_type', 32);

            /*
             * Artículo del evento (vista de producto, carrito).
             * Espejado de `articles.id` = bigIncrements
             * (2019_10_24_002337_create_articles_table.php:17).
             */
            $table->unsignedBigInteger('article_id')->nullable();

            /*
             * Rubro del evento.
             * Espejado de `categories.id` = id()
             * (2019_10_24_002335_create_categories_table.php:17).
             */
            $table->unsignedBigInteger('category_id')->nullable();

            /*
             * Sub rubro del evento.
             * Espejado de `sub_categories.id` = id()
             * (2019_10_24_002336_create_sub_categories_table.php:17).
             */
            $table->unsignedBigInteger('sub_category_id')->nullable();

            /* Término buscado, en minúsculas y recortado a 120 (lo normaliza tienda-api) */
            $table->string('search_term', 120)->nullable();

            /*
             * Cantidad de resultados de la búsqueda. El 0 no es un vacío a ignorar:
             * "buscó y no encontró nada" es el dato más valioso para el motor de
             * ofertas, así que la columna es nullable para poder distinguir
             * "no hubo resultados" (0) de "no aplica" (null).
             */
            $table->unsignedInteger('results_count')->nullable();

            /* Unidades del movimiento de carrito */
            $table->unsignedInteger('quantity')->nullable();

            /*
             * Monto del evento (checkout completado). Mismo ancho que el resto de
             * los montos del sistema: `credit_account_snapshots.saldo` es
             * decimal(22,2) (2026_08_14_130000:52).
             */
            $table->decimal('amount', 22, 2)->nullable();

            /* Tiempo efectivo en pantalla, en milisegundos (el SPA pausa el reloj con la pestaña oculta) */
            $table->unsignedInteger('dwell_ms')->nullable();

            /*
             * Pedido creado en el checkout.
             * Espejado de `orders.id` = bigIncrements
             * (2020_08_28_145446_create_orders_table.php:17).
             */
            $table->unsignedBigInteger('order_id')->nullable();

            /*
             * Momento del evento. Es distinto de created_at a propósito: created_at
             * es cuándo llegó el lote a la API, occurred_at es cuándo pasó en el
             * navegador. Toda consulta de comportamiento usa occurred_at.
             */
            $table->datetime('occurred_at');

            $table->timestamps();

            /*
             * Consulta base del ERP, y además la que usan la purga y el rollup diario.
             *
             * NO le falta un índice suelto de `occurred_at`: como cada cliente tiene
             * su propia base, `user_id` toma casi siempre un único valor, así que
             * este compuesto funciona de hecho como el índice de `occurred_at` para
             * cualquier consulta que fije el user_id — y las tres lo fijan. Un
             * quinto árbol sobre una tabla de escritura intensiva costaría en cada
             * insert sin ganar nada. Si alguien viene a "agregar el índice que
             * falta", esto es lo que tiene que leer primero.
             */
            $table->index(['user_id', 'occurred_at'], 'bte_user_ocurrido_index');

            /* Comportamiento de un comprador puntual en una ventana de tiempo */
            $table->index(['buyer_id', 'occurred_at'], 'bte_buyer_ocurrido_index');

            /* Qué pasó con un artículo puntual (vistas, carritos) en una ventana de tiempo */
            $table->index(['article_id', 'occurred_at'], 'bte_articulo_ocurrido_index');

            /* Reconstruir el recorrido completo de una sesión anónima */
            $table->index(['visitor_id'], 'bte_visitante_index');
        });
    }

    /**
     * Elimina la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('buyer_tracking_events');
    }
}
