<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCurrentAcountsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('current_acounts', function (Blueprint $table) {
            $table->id();
            $table->text('detalle')->nullable();
            // $table->integer('page')->nullable();
            $table->string('description')->nullable();
            $table->decimal('debe', 15,2)->nullable();
            $table->decimal('haber', 15,2)->nullable();
            $table->decimal('saldo', 15,2)->nullable();
            $table->enum('status', [
                'saldo_inicial',
                'sin_pagar', 
                'pagandose', 
                'pagado', 
                'nota_credito',
                'pago_from_client',
                'pago_for_commissioner',
            ]);
            $table->decimal('pagandose', 15,2)->nullable();
            $table->integer('num_receipt')->nullable();

            $table->integer('to_pay_id')->unsigned()->nullable();

            $table->integer('user_id')->unsigned()->nullable();

            $table->bigInteger('client_id')->unsigned()->nullable();
            // $table->foreign('client_id')->references('id')->on('clients');
            $table->bigInteger('commissioner_id')->unsigned()->nullable();
            // $table->foreign('commissioner_id')->references('id')->on('commissioners');
            $table->bigInteger('seller_id')->unsigned()->nullable();
            // $table->foreign('seller_id')->references('id')->on('sellers');
            $table->bigInteger('sale_id')->unsigned()->nullable();
            // $table->foreign('sale_id')->references('id')->on('sales');
            $table->bigInteger('budget_id')->unsigned()->nullable();

            $table->bigInteger('order_production_id')->unsigned()->nullable();

            $table->bigInteger('provider_id')->unsigned()->nullable();
            $table->bigInteger('provider_order_id')->unsigned()->nullable();
            
            $table->bigInteger('current_acount_payment_method_id')->unsigned()->nullable();
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
        Schema::dropIfExists('current_acounts');
    }
}
