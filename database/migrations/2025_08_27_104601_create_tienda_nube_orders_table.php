<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTiendaNubeOrdersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('tienda_nube_orders', function (Blueprint $table) {
            $table->id();

            $table->integer('external_id'); // ID de Tienda Nube
            $table->string('customer_name')->nullable();
            $table->decimal('total', 12, 2)->nullable();
            $table->json('data')->nullable(); // toda la respuesta cruda de TN
            $table->integer('tienda_nube_order_status_id');
            $table->string('payment_status');
            $table->text('notes')->nullable();
            $table->integer('user_id'); // ID de Tienda Nube

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('tienda_nube_orders');
    }
}
