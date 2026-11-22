<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea `unmet_demands` (misión demanda-sin-stock-vender): una fila por cada vez que Vender
 * frenó un artículo por falta de stock (no había, o había pero no alcanzaba).
 *
 * Para qué sirve: es la señal de "alguien quiso comprar esto y no lo teníamos". La lee el motor
 * de sugerencias de compra (tercer disparador, ventana de 30 días) y el asistente de IA.
 *
 * Columnas:
 *  - user_id: el DUEÑO del comercio (no el empleado que vendía).
 *  - employee_id: quién estaba vendiendo (puede ser el mismo dueño). Nullable.
 *  - article_id: el artículo frenado (siempre el id del artículo, también en variantes).
 *  - article_variant_id / combo_id / address_id: variante elegida, combo del que el artículo es
 *    componente, y sucursal de Vender. Todos nullable.
 *  - motivo: 'sin_stock' | 'stock_insuficiente'.
 *  - cantidad_pedida: null cuando el freno fue al elegir (todavía sin cantidad).
 *  - stock_disponible: el stock con el que el sistema comparó.
 *  - canceled_at: lo marca el vendedor con "Cancelar" (fue un error). La fila no se borra.
 *
 * Sin foreign keys y sin unique (reglas del proyecto). Índices con nombre corto.
 * Guard idempotente para poder re-correrla sin romper nada.
 */
class CreateUnmetDemandsTable extends Migration
{
    /**
     * Crea la tabla si todavía no existe.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('unmet_demands')) {
            return;
        }

        Schema::create('unmet_demands', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->unsignedBigInteger('article_id');
            $table->unsignedBigInteger('article_variant_id')->nullable();
            $table->unsignedBigInteger('combo_id')->nullable();
            $table->unsignedBigInteger('address_id')->nullable();
            $table->string('motivo', 30)->default('sin_stock');
            $table->decimal('cantidad_pedida', 14, 2)->nullable();
            $table->decimal('stock_disponible', 14, 2)->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();

            // Antiduplicado y resumen para el motor (por dueño y artículo, por fecha).
            $table->index(['user_id', 'article_id', 'created_at'], 'ud_user_article_idx');
            // Listados y asistente (por dueño, por fecha).
            $table->index(['user_id', 'created_at'], 'ud_user_created_idx');
        });
    }

    /**
     * Elimina la tabla si existe.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('unmet_demands');
    }
}
