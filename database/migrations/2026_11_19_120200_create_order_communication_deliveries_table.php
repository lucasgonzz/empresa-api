<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOrderCommunicationDeliveriesTable extends Migration
{
    public function up()
    {
        Schema::create('order_communication_deliveries', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            // Owner persistido del pedido (orders.user_id), nunca el usuario de sesión.
            $table->unsignedInteger('owner_id');
            $table->unsignedBigInteger('order_id');
            $table->string('channel', 32)->charset('ascii')->collation('ascii_bin');
            $table->string('communication_type', 32)->charset('ascii')->collation('ascii_bin');
            // SHA-256 hexadecimal de trim + lowercase del correo. No guardar la dirección.
            $table->char('recipient_hash', 64)->charset('ascii')->collation('ascii_bin');
            $table->enum('status', [
                'prepared', 'attempting', 'transport_accepted', 'failed', 'uncertain',
            ])->default('prepared');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->dateTime('last_attempt_at')->nullable();
            $table->dateTime('accepted_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            // Sólo código controlado por la aplicación, nunca excepción/respuesta SMTP con PII.
            $table->string('error_code', 64)->nullable();
            $table->timestamps();
            // ASCII acotado: compatible también con índices InnoDB de 767 bytes.
            $table->unique(
                ['owner_id', 'order_id', 'channel', 'communication_type', 'recipient_hash'],
                'order_comm_delivery_identity_unique'
            );
        });
    }

    public function down()
    {
        Schema::dropIfExists('order_communication_deliveries');
    }
}
