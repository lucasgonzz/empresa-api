<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla `envios`: el envío real generado en un proveedor de logística (hoy Zipnova) a partir de
 * un pedido de la tienda (misión zipnova-envios, 14/9/2026).
 *
 * Es distinta de `orders.envio_opcion` a propósito: la opción es lo que el comprador ELIGIÓ al
 * comprar; el envío es lo que el comercio GENERÓ después (con id en Zipnova, número de guía,
 * etiqueta y estado que va cambiando hasta "entregado"). Un pedido puede tener la opción y ningún
 * envío todavía (no se confirmó, o Zipnova falló), y un envío cancelado puede reemplazarse por
 * otro del mismo pedido: por eso `order_id` no es único.
 *
 * `status`/`status_name` guardan tal cual el código y el nombre que manda Zipnova
 * (ver docs.zipnova.com/envios/referencia/estados-de-envio), más un valor propio `error` para
 * "nunca se pudo crear" (con el motivo en `error_message`). `respuesta` es el último payload
 * completo de Zipnova, para no perder datos que la tabla no modela.
 *
 * Sin foreign keys, como todo el repo. `tienda-api` la lee (solo lectura) para mostrarle al
 * comprador el seguimiento en "Mis pedidos", chequeando `Schema::hasTable('envios')` antes.
 */
class CreateEnviosTable extends Migration
{
    /**
     * Crea la tabla si no existe.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('envios')) {
            return;
        }

        Schema::create('envios', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('sale_id')->nullable();

            $table->string('proveedor', 20)->default('zipnova');
            $table->string('proveedor_envio_id', 40)->nullable();
            $table->string('external_id', 40)->nullable();
            $table->string('account_id', 40)->nullable();

            $table->string('carrier_id', 20)->nullable();
            $table->string('carrier_name', 120)->nullable();
            $table->string('carrier_logo', 255)->nullable();
            $table->string('service_type', 60)->nullable();
            $table->string('service_name', 120)->nullable();
            $table->string('logistic_type', 60)->nullable();

            $table->string('status', 60)->nullable();
            $table->string('status_name', 120)->nullable();
            $table->string('substatus_code', 60)->nullable();
            $table->string('substatus_name', 120)->nullable();

            $table->string('tracking_url', 255)->nullable();
            $table->string('tracking_external_url', 255)->nullable();
            $table->string('carrier_tracking_id', 120)->nullable();
            $table->string('delivery_id', 120)->nullable();

            $table->decimal('price', 20, 2)->nullable();
            $table->decimal('price_incl_tax', 20, 2)->nullable();
            $table->decimal('declared_value', 20, 2)->nullable();
            $table->timestamp('estimated_delivery')->nullable();

            $table->json('destino')->nullable();
            $table->json('bultos')->nullable();
            $table->json('respuesta')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('ultima_sincronizacion')->nullable();

            $table->timestamps();

            $table->index('user_id', 'envios_user_id_idx');
            $table->index('order_id', 'envios_order_id_idx');
            $table->index('proveedor_envio_id', 'envios_proveedor_envio_id_idx');
        });
    }

    /**
     * Borra la tabla. Los envíos siguen existiendo en Zipnova; solo se pierde el vínculo con el
     * pedido.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('envios');
    }
}
