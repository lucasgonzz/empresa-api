<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los "Diseños de etiquetas de góndola" de cada negocio (misión disenos-etiquetas-gondola,
 * 29/9/2026).
 *
 * Un diseño dice cuántas etiquetas entran a lo ancho y a lo alto de la hoja A4 y qué campos del
 * artículo lleva cada etiqueta, dónde y con qué letra (el JSON `diseno`, §3.3 del plan). El listado
 * de artículos ofrece una opción de impresión por cada diseño, y el PDF lo dibuja
 * `Pdf\ArticleTicket\ArticleTicketDesignPdf`.
 *
 * - `user_id` es siempre el DUEÑO: un empleado ve y edita los del negocio.
 * - `price_type_id` es la lista de precios para la que EL SISTEMA generó el diseño (el seeder o el
 *   alta de una lista nueva). Es lo que hace idempotente a `ArticleTicketDesignSeeder`: "ya tiene
 *   el diseño de esta lista". En los que crea o duplica el usuario queda en NULL.
 * - `diseno` es el JSON ya normalizado por `ArticleTicketDesignHelper::normalizar_diseno()`.
 *   `longText` y no `json`: nadie consulta adentro del diseño desde SQL (mismo criterio que
 *   `vender_layouts.layout`).
 *
 * Sin foreign keys físicas ni unique compuestos (reglas del repo) y sin soft deletes: ningún otro
 * registro apunta a un diseño, así que borrar uno no deja nada colgando. Solo agrega una tabla:
 * compatible hacia atrás con un SPA que no la conoce.
 */
class CreateArticleTicketDesignsTable extends Migration
{
    /**
     * Crea la tabla, con guard hasTable para que sea segura de re-ejecutar.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('article_ticket_designs')) {
            return;
        }

        Schema::create('article_ticket_designs', function (Blueprint $table) {

            $table->increments('id');

            /* El dueño del negocio. Todas las lecturas son "los diseños de este dueño". */
            $table->unsignedBigInteger('user_id')->index();

            /* Nombre que se ve en la tarjeta del ABM y en el menú de impresión del listado. */
            $table->string('name', 120);

            /* La lista para la que el sistema generó el diseño (idempotencia del seeder). */
            $table->unsignedBigInteger('price_type_id')->nullable()->index();

            /* Orden en el menú del listado y en el ABM. */
            $table->integer('position')->default(0);

            /* JSON del diseño (§3.3 del plan). */
            $table->longText('diseno')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Elimina la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('article_ticket_designs');
    }
}
