<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProviderOrderAfipTicketsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('provider_order_afip_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('code')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->decimal('total')->nullable();
            $table->integer('provider_order_id');
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
        Schema::dropIfExists('provider_order_afip_tickets');
    }
}
