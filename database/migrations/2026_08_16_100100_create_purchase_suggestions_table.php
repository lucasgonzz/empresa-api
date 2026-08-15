<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cabecera de una corrida del motor de sugerencia de compra a proveedores
 * (misión sugerencias de compra). Es el mismo rol que `stock_suggestions`
 * cumple para las sugerencias de traslado entre sucursales, adaptado al
 * dominio de compras: qué comprar, cuánto y a quién.
 *
 * status: 'pendiente' (chunks en curso) → 'terminado' (todas las líneas
 * calculadas y priorizadas) | 'error' (la corrida falló, ver error_mensaje).
 *
 * origen_generacion: 'manual' (botón del usuario) | 'automatica' (comando
 * compras:generar según users.sugerencias_compras_periodicidad).
 *
 * resumen_ia_estado: null (no se pidió, p.ej. sin ANTHROPIC_API_KEY) |
 * 'pendiente' | 'listo' | 'error' (ver resumen_ia_error). Una falla del
 * resumen NUNCA cambia el status de la sugerencia: son dos contratos
 * independientes.
 *
 * dias_punto_pedido / dias_cobertura_objetivo / dias_lead_time /
 * dias_vigencia_oferta: los cuatro parámetros del algoritmo (ver
 * PurchaseSuggestionService), persistidos en la cabecera para que la corrida
 * quede auditable y el form de una corrida nueva pueda precargar los últimos
 * valores usados. Defaults 15/30/7/120 tal como los fija el servicio.
 *
 * total_estimado / contexto_financiero: datos INFORMATIVOS calculados al
 * cerrar la corrida (ContextoFinancieroService). Nunca alimentan el cálculo
 * de cantidades: por diseño, la plata no puede recortar una sugerencia.
 * contexto_financiero es longText porque guarda un JSON con el detalle por
 * proveedor (deuda en pesos y dólares, disponible en cajas).
 *
 * Sin foreign keys físicas, siguiendo el estilo del resto del schema.
 */
class CreatePurchaseSuggestionsTable extends Migration
{
    /**
     * Crea la tabla, con guard hasTable para que sea segura de re-ejecutar.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('purchase_suggestions')) {
            return;
        }

        Schema::create('purchase_suggestions', function (Blueprint $table) {

            /* Clave primaria autoincremental */
            $table->id();

            /* Dueño de la corrida */
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

            /* Los cuatro parámetros del algoritmo, editables por corrida */
            $table->integer('dias_punto_pedido')->default(15);
            $table->integer('dias_cobertura_objetivo')->default(30);
            $table->integer('dias_lead_time')->default(7);
            $table->integer('dias_vigencia_oferta')->default(120);

            /* Informativos, calculados al cerrar la corrida: nunca alimentan el cálculo */
            $table->decimal('total_estimado', 22, 2)->nullable();
            $table->longText('contexto_financiero')->nullable();

            $table->timestamps();

            /* Listado por comercio, más reciente primero */
            $table->index(['user_id', 'created_at'], 'purchase_suggestions_user_id_created_at_index');
        });
    }

    /**
     * Elimina la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('purchase_suggestions');
    }
}
