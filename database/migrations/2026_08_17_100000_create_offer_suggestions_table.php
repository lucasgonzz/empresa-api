<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cabecera de una corrida del motor de ofertas personalizadas por cliente
 * (misión motor-de-ofertas-por-cliente). Mismo rol que `purchase_suggestions`
 * cumple para las sugerencias de compra a proveedores, adaptado al dominio de
 * marketing: a qué cliente conviene ofrecerle qué artículo y con cuánto
 * descuento sin romper el margen.
 *
 * status: 'pendiente' (chunks en curso) → 'terminado' (todas las líneas
 * calculadas y priorizadas) | 'error' (la corrida falló, ver error_mensaje).
 *
 * origen_generacion: 'manual' (botón del usuario) | 'automatica' (comando
 * ofertas:generar según users.ofertas_periodicidad).
 *
 * resumen_ia_estado: null (no se pidió, p.ej. sin ANTHROPIC_API_KEY) |
 * 'pendiente' | 'listo' | 'error' (ver resumen_ia_error). Una falla del
 * resumen NUNCA cambia el status de la sugerencia: son dos contratos
 * independientes.
 *
 * Los cinco parámetros del motor, persistidos en la cabecera para que la
 * corrida quede auditable y el form de una corrida nueva precargue los
 * últimos valores usados:
 * - dias_historial_afinidad (180): ventana de `article_purchases` para afinidad.
 * - dias_inactividad_reactivacion (60): desde cuántos días sin comprar un
 *   cliente es "dormido".
 * - dias_carrito_abandonado (7): ventana de `cart_add` sin compra posterior.
 * - max_ofertas_por_cliente (3): techo de líneas por cliente. Un cliente con
 *   40 ofertas no es una campaña, es spam.
 * - dias_vigencia_sugerida (15): vigencia por defecto que propone el motor.
 *
 * total_clientes / total_lineas / total_clientes_excluidos_por_deuda:
 * INFORMATIVOS, se llenan al cerrar la corrida. Nunca alimentan el cálculo de
 * ningún porcentaje. El tercero cuenta los candidatos que quedaron afuera por
 * tener ventas sin cobrar (decisión de Lucas del 15/8/2026: al que debe hace
 * mucho no se le ofrece nada), y no es decoración: va a la vista y al bloque
 * de datos de la IA, y es lo que le muestra al comerciante que el sistema tuvo
 * criterio y no le ofreció un descuento a alguien que le debe.
 *
 * Sin foreign keys físicas, siguiendo el estilo del resto del schema.
 */
class CreateOfferSuggestionsTable extends Migration
{
    /**
     * Crea la tabla, con guard hasTable para que sea segura de re-ejecutar.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('offer_suggestions')) {
            return;
        }

        Schema::create('offer_suggestions', function (Blueprint $table) {

            /* Clave primaria autoincremental */
            $table->id();

            /*
             * Dueño de la corrida. integer y no unsignedBigInteger porque
             * `users.id` es increments() -> int unsigned
             * (2014_10_12_000000_create_users_table.php:17): espejar la fuente
             * acá SIGNIFICA integer.
             */
            $table->integer('user_id');

            /* 'pendiente' | 'terminado' | 'error' */
            $table->string('status', 20)->default('pendiente');

            /* Detalle cuando la corrida entera falla (status 'error') */
            $table->text('error_mensaje')->nullable();

            /* 'manual' | 'automatica' */
            $table->string('origen_generacion', 20)->default('manual');

            /* Progreso de los jobs por chunk */
            $table->integer('total_chunks')->default(0);
            $table->integer('processed_chunks')->default(0);

            /* Resumen en criollo escrito por la IA sobre el resultado ya calculado */
            $table->text('resumen_ia')->nullable();
            $table->string('resumen_ia_estado', 20)->nullable();
            $table->text('resumen_ia_error')->nullable();

            /* Los cinco parámetros del motor, editables por corrida */
            $table->integer('dias_historial_afinidad')->default(180);
            $table->integer('dias_inactividad_reactivacion')->default(60);
            $table->integer('dias_carrito_abandonado')->default(7);
            $table->integer('max_ofertas_por_cliente')->default(3);
            $table->integer('dias_vigencia_sugerida')->default(15);

            /*
             * Informativos, calculados al cerrar la corrida. El tercero existe
             * porque el mal pagador se EXCLUYE entero, no se le baja el techo
             * (decisión de negocio de Lucas del 15/8/2026): sin el conteo, la
             * corrida no puede explicar por qué hay menos clientes de los que
             * el comerciante esperaba.
             */
            $table->integer('total_clientes')->nullable();
            $table->integer('total_lineas')->nullable();
            $table->integer('total_clientes_excluidos_por_deuda')->nullable();

            $table->timestamps();

            /*
             * Único índice, y el porqué: el listado del módulo es "las corridas
             * de este comercio, la más nueva arriba" — el WHERE user_id +
             * ORDER BY created_at DESC de OfferSuggestionController@index. Sin
             * él, full scan. Ninguno más "por las dudas": cada índice es un
             * árbol que se actualiza en cada insert.
             */
            $table->index(['user_id', 'created_at'], 'offer_suggestions_user_id_created_at_index');
        });
    }

    /**
     * Elimina la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('offer_suggestions');
    }
}
