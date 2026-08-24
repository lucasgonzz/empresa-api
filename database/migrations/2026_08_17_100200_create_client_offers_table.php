<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🔴 LA OFERTA ACTIVA. Esta tabla ES EL CONTRATO CON LA TIENDA (misión 5).
 *
 * No hay endpoint entre los dos sistemas: `empresa` (el ERP) y `tienda` (el
 * ecommerce) comparten la MISMA base del cliente. `empresa-api` crea el esquema
 * —el deploy de la tienda no corre `migrate`— y `tienda-api` lo lee por SQL.
 * Por eso el contrato se escribe acá, textual, y no en un README que nadie abre.
 *
 * ── LA QUERY EXACTA QUE VA A CORRER LA TIENDA ──────────────────────────────
 * Va textual para que la misión 5 la COPIE y no invente otra:
 *
 *   -- 1) Las ofertas vigentes del comprador logueado, para los artículos de la página
 *   SELECT co.id, co.article_id, co.tipo_descuento, co.porcentaje
 *   FROM client_offers co
 *   WHERE co.user_id  = :user_id                    -- el comercio
 *     AND co.client_id = :comercio_city_client_id   -- buyers.comercio_city_client_id del buyer logueado
 *     AND co.estado   = 'activa'
 *     AND co.desde   <= CURDATE()
 *     AND co.hasta   >= CURDATE()
 *     AND co.article_id IN (:article_ids)
 *
 *   -- 2) Los tramos, solo para las ofertas de tipo 'cantidad'
 *   SELECT r.client_offer_id, r.min, r.max, r.porcentaje
 *   FROM client_offer_ranges r
 *   WHERE r.client_offer_id IN (:offer_ids)
 *   ORDER BY r.min ASC
 * ───────────────────────────────────────────────────────────────────────────
 *
 * POR QUÉ CON ESO ALCANZA Y LA TIENDA NO RECALCULA NADA: la oferta se aplica
 * DESPUÉS de checkPriceTypes() como un `* (1 - porcentaje/100)` sobre el precio
 * ya resuelto. Es correcto porque el porcentaje es INVARIANTE al IVA y a los
 * sale_taxes: los dos son factores multiplicativos, así que
 * precio_bruto = precio_neto * K, y `precio_bruto * (1 - d/100)` neteado da
 * `precio_neto * (1 - d/100)`. El mismo número vale sobre el neto y sobre el
 * bruto. La tienda no tiene que conocer costos, márgenes ni la cascada de
 * precios del ERP: ese es el punto del diseño.
 *
 * Sin buyer logueado, o con un buyer sin `comercio_city_client_id`, no hay
 * client_id → no hay oferta: el anónimo nunca ve una personalizada. Intencional.
 *
 * COMPATIBILIDAD HACIA ATRÁS EN LAS DOS DIRECCIONES: tienda vieja + esquema
 * nuevo = cuatro tablas que nadie lee, inofensivo. Tienda nueva + esquema
 * ausente = la misión 5 guarda con Schema::hasTable('client_offers') MEMOIZADO
 * en una static antes de cualquier acceso, nunca dentro del loop de artículos.
 * Todo aditivo: nada se renombra, nada se saca, ningún tipo cambia.
 *
 * porcentaje: el % cuando tipo_descuento es 'unidad'; NULL cuando es 'cantidad'
 * (el número está en los tramos). Se deja null a propósito para que nadie lo
 * lea por accidente y aplique el descuento equivocado.
 *
 * La verdad de la vigencia la dan las FECHAS (desde/hasta), no `estado`:
 * `estado` es el gesto del comerciante ('cancelada') y la marca del barrido de
 * higiene ('vencida'), pero la query filtra igual por CURDATE(), así que una
 * oferta pasada de fecha deja de aplicarse aunque el barrido no haya corrido.
 *
 * Sin foreign keys físicas, igual que el resto del schema.
 */
class CreateClientOffersTable extends Migration
{
    /**
     * Crea la tabla, con guard hasTable para que sea segura de re-ejecutar.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('client_offers')) {
            return;
        }

        Schema::create('client_offers', function (Blueprint $table) {

            /* Clave primaria autoincremental */
            $table->id();

            /*
             * 🔴 Scope por comercio: la tienda SIEMPRE filtra por acá. integer
             * porque `users.id` es increments() -> int unsigned (users:17).
             */
            $table->integer('user_id');

            /*
             * 🔴 unsignedBigInteger y NO integer, en las dos: es la clase de
             * error "columna derivada más angosta que su fuente" de
             * APRENDER_NO_PARCHEAR.md. `clients.id` es bigIncrements
             * (2019_10_24_003025_create_clients_table.php:17) y `articles.id`
             * también (2019_10_24_002337_create_articles_table.php:17). El molde
             * de compras (purchase_suggestion_articles:64) declara article_id
             * como integer y tiene el error adentro: acá no se copia ese pedazo,
             * NO "alinear al molde". client_id es además la punta del join con
             * buyers.comercio_city_client_id que hace la tienda.
             */
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('article_id');

            /* 'unidad' usa `porcentaje`; 'cantidad' usa client_offer_ranges y lo deja null */
            $table->string('tipo_descuento', 20);
            $table->decimal('porcentaje', 6, 2)->nullable();

            /* Vigencia. La query de la tienda filtra por estas dos contra CURDATE() */
            $table->date('desde');
            $table->date('hasta');

            /* 'activa' | 'vencida' | 'cancelada' */
            $table->string('estado', 20)->default('activa');

            /* Trazabilidad; null si algún día se carga una oferta a mano */
            $table->unsignedBigInteger('offer_suggestion_line_id')->nullable();

            /*
             * A qué mail se avisó (el del buyer, o clients.email) y cuándo.
             * notificada_email_at null = no se pudo avisar: la oferta se activa
             * IGUAL y queda como "sin notificar". El mail es un extra, no una
             * precondición — medido: el 84% de los clientes no tiene ni mail ni
             * teléfono. Ídem el par de whatsapp: null si no hay teléfono usable.
             */
            $table->string('email_destino', 191)->nullable();
            $table->dateTime('notificada_email_at')->nullable();
            $table->string('whatsapp_telefono', 32)->nullable();
            $table->text('whatsapp_url')->nullable();

            $table->timestamps();

            /*
             * 🔴 ES EXACTAMENTE LA QUERY DE LA TIENDA. Igualdad en user_id y
             * client_id, IN (...) en article_id, y `estado` de cuarta para que
             * el filtro se resuelva dentro del índice sin ir a buscar la fila.
             * Sin este índice, cada carga de una página de la tienda es un
             * scan de client_offers. Si se cambia el orden de las columnas se
             * rompe el contrato de rendimiento con la misión 5.
             */
            $table->index(['user_id', 'client_id', 'article_id', 'estado'], 'client_offers_user_client_article_estado_index');

            /*
             * Los dos consumidores del ERP: el listado "ofertas activas" de la
             * vista (estado = 'activa' ordenado por `hasta`) y el barrido de
             * higiene de ofertas:generar, que marca 'vencida' lo que ya pasó de
             * fecha. Los dos entran por user_id + estado y ordenan por `hasta`.
             * Ninguno más: cada índice es un árbol que se actualiza en cada insert.
             */
            $table->index(['user_id', 'estado', 'hasta'], 'client_offers_user_estado_hasta_index');

            /*
             * 🔴 A PROPÓSITO NO HAY UNIQUE sobre (user_id, client_id,
             * article_id): una oferta 'cancelada' o 'vencida' del mismo par
             * tiene que poder convivir con una 'activa' nueva, porque el
             * historial no se borra. El invariante "una sola oferta ACTIVA por
             * (comercio, cliente, artículo)" se sostiene EN CÓDIGO al activar
             * (ClientOfferController vence la anterior en la misma transacción)
             * y tiene su test. El unique acá rompería la segunda activación.
             */
        });
    }

    /**
     * Elimina la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('client_offers');
    }
}
