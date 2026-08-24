<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Líneas de una corrida del motor de ofertas: una fila por par
 * (cliente × artículo) que el motor decidió sugerir, con el porcentaje ya
 * recortado al techo determinista y la trazabilidad de cómo salió ese número.
 * Son SUGERENCIAS, no promociones vigentes: la oferta que la tienda lee vive
 * en `client_offers` y solo aparece cuando el comerciante activa la línea
 * (POST api/client-offer). `client_offer_id` cierra ese vínculo.
 *
 * El trío del techo, y por qué se guardan los tres: porcentaje_techo es el
 * máximo determinista que sale del margen del artículo (TechoDeDescuentoService:
 * techo = margen / (100 + margen) * 100) y se persiste para AUDITAR después por
 * qué se sugirió lo que se sugirió; porcentaje_piso es el mínimo de esa línea; y
 * porcentaje_sugerido es el final, YA recortado a [piso, techo] — la IA lo mueve
 * dentro del rango, nunca puede salirse.
 *
 * margen_base / precio_vigente / costo_real: la foto de los tres insumos al
 * momento de la corrida. Al activar se RE-VALIDA el techo con los datos de
 * hoy, así que esta foto es auditoría, no verdad vigente.
 *
 * criterio: 'afinidad' | 'carrito_abandonado' | 'reactivacion'. criterio_detalle
 * es la frase en criollo, escrita POR CÓDIGO y no por la IA: "Compró este
 * artículo 3 veces, la última hace 40 días".
 *
 * tramos_sugeridos: JSON [{"min":n,"max":n|null,"porcentaje":n}] cuando
 * tipo_descuento es 'cantidad'. JSON y no tabla propia porque la sugerencia es
 * un borrador con retención de 30 días que nadie joinea ni indexa; la tabla
 * real (client_offer_ranges) solo hace falta del lado que la tienda lee.
 * Alternativa descartada: offer_suggestion_line_ranges.
 *
 * es_buen_pagador: null = SIN DATOS de cuenta corriente, distinto de false =
 * mal pagador; no colapsar los dos a 0. Desde la decisión de Lucas del
 * 15/8/2026 el mal pagador se EXCLUYE antes de llegar acá, así que en la
 * práctica queda en true o null; el false sigue en el contrato para no perder
 * el dato si la regla cambia.
 * factor_credito: el multiplicador aplicado al techo. NUNCA es > 1: el crédito
 * solo puede BAJARLO. Es el invariante que blinda el techo, y se mantiene
 * aunque hoy el único valor posible sea 1.000.
 *
 * Sin foreign keys físicas, igual que el resto del schema.
 */
class CreateOfferSuggestionLinesTable extends Migration
{
    /**
     * Crea la tabla, con guard hasTable para que sea segura de re-ejecutar.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('offer_suggestion_lines')) {
            return;
        }

        Schema::create('offer_suggestion_lines', function (Blueprint $table) {

            /* Clave primaria autoincremental */
            $table->id();

            /*
             * Cabecera de la corrida (offer_suggestions.id, que es bigint).
             * Acá SÍ se copia el molde de
             * purchase_suggestion_articles.purchase_suggestion_id, que también
             * es integer contra un id() bigint: es una tabla propia de esta
             * misión y el volumen esperado (miles de corridas) no se acerca al
             * techo de int. Documentado para que sea decisión consciente y no
             * el mismo descuido que se corrige dos líneas más abajo.
             */
            $table->integer('offer_suggestion_id');

            /*
             * 🔴 client_id y article_id van unsignedBigInteger y NO integer.
             * Es la clase de error "columna derivada más angosta que su fuente"
             * de APRENDER_NO_PARCHEAR.md: `clients.id` es bigIncrements
             * (2019_10_24_003025_create_clients_table.php:17) y `articles.id`
             * también (2019_10_24_002337_create_articles_table.php:17): bigint
             * unsigned los dos. El molde (purchase_suggestion_articles:64)
             * declara article_id como integer y TIENE EL ERROR ADENTRO: en un
             * catálogo que pase los 2.147.483.647 ids el valor se trunca en
             * silencio. Acá no se copia ese pedazo: NO "alinear al molde".
             */
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('article_id');

            /* 'unidad' (un solo %) | 'cantidad' (tramos en tramos_sugeridos) */
            $table->string('tipo_descuento', 20);

            /* El % final ya recortado, y el techo/piso guardados para auditar */
            $table->decimal('porcentaje_sugerido', 6, 2);
            $table->decimal('porcentaje_techo', 6, 2);
            $table->decimal('porcentaje_piso', 6, 2);

            /* Espejan article_price_type.percentage (12,2) y .final_price (30,2) */
            $table->decimal('margen_base', 12, 2);
            $table->decimal('precio_vigente', 30, 2)->nullable();

            /*
             * 🔴 Espeja articles.costo_real DESPUÉS de
             * 2026_07_30_160000_extend_cost_decimals_on_articles_table.php, que
             * lo llevó de (22,2) a (22,6). Con (22,2) acá se perderían los
             * mismos decimales que esa migración vino a rescatar.
             */
            $table->decimal('costo_real', 22, 6)->nullable();

            /* De qué lista salió el precio; null = final_price único (cuenta sin listas) */
            $table->integer('price_type_id')->nullable();

            /* 'afinidad' | 'carrito_abandonado' | 'reactivacion' */
            $table->string('criterio', 30);
            $table->text('criterio_detalle')->nullable();

            /* null = sin datos de cuenta corriente; false = mal pagador. No son lo mismo */
            $table->boolean('es_buen_pagador')->nullable();

            /* Multiplicador aplicado al techo. Siempre <= 1: el crédito solo baja */
            $table->decimal('factor_credito', 5, 3)->default(1.000);

            /* Factor 0..1 de qué tan parado está el artículo (1 = totalmente parado) */
            $table->decimal('vendibilidad', 5, 3)->nullable();

            /* Puntaje para ordenar, y el ranking 1..N materializado al cerrar */
            $table->decimal('score', 14, 4)->nullable();
            $table->integer('prioridad')->nullable();

            /* Fecha de vencimiento que propone la IA, editable antes de activar */
            $table->date('fecha_vencimiento_sugerida')->nullable();

            /* JSON [{"min":n,"max":n|null,"porcentaje":n}] cuando tipo_descuento = 'cantidad' */
            $table->longText('tramos_sugeridos')->nullable();

            /* La frase de la IA para esta línea (criterio_detalle lo escribe el código) */
            $table->text('motivo_ia')->nullable();

            /* Espeja client_offers.id (bigint): la oferta activa que salió de esta línea */
            $table->unsignedBigInteger('client_offer_id')->nullable();

            $table->timestamps();

            /*
             * Orden por defecto del endpoint paginado (ORDER BY prioridad ASC
             * dentro de una corrida), igual que
             * purchase_suggestion_articles_suggestion_prioridad_index.
             */
            $table->index(['offer_suggestion_id', 'prioridad'], 'offer_suggestion_lines_suggestion_prioridad_index');

            /*
             * El filtro "ver solo lo de este cliente" de la vista y el agrupado
             * por cliente del resumen: sin él, cada filtro escanea TODAS las
             * líneas de la corrida. Ninguno más: cada índice es un árbol que se
             * actualiza en cada insert, y acá los inserts vienen de a 500.
             */
            $table->index(['offer_suggestion_id', 'client_id'], 'offer_suggestion_lines_suggestion_client_index');
        });
    }

    /**
     * Elimina la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('offer_suggestion_lines');
    }
}
