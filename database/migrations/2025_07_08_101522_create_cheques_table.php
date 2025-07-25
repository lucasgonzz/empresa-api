<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateChequesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('cheques', function (Blueprint $table) {
            $table->id();

            // Datos del cheque
            $table->string('numero');
            $table->string('banco')->nullable();
            $table->decimal('amount', 22, 2);
            $table->date('fecha_emision');
            $table->date('fecha_pago');

            // Tipo de cheque: recibido (de cliente) o emitido (a proveedor)
            $table->enum('tipo', ['recibido', 'emitido']);

            // Cliente que entregó el cheque (si tipo = recibido)
            $table->integer('client_id')->nullable();

            // Proveedor al que se le emitió el cheque (si tipo = emitido)
            $table->integer('provider_id')->nullable();

            // Cuenta corriente relacionada
            $table->integer('current_acount_id')->nullable();

            // Usuario que registró el cheque
            $table->integer('employee_id')->nullable();
            $table->integer('user_id')->nullable();

            // Caja utilizada al momento de cobro (recibido) o egreso (emitido)
            $table->integer('caja_id')->nullable();

            // Datos de endoso (solo si tipo = recibido)
            $table->integer('endosado_a_provider_id')->nullable();
            $table->timestamp('fecha_endoso')->nullable();

            // Estado actual manual (solo si fue cobrado o rechazado)
            $table->enum('estado_manual', ['cobrado', 'rechazado'])->nullable();
            $table->timestamp('cobrado_en')->nullable();
            $table->timestamp('rechazado_en')->nullable();
            $table->integer('cobrado_por_id')->nullable();
            $table->integer('rechazado_por_id')->nullable();
            $table->integer('rechazado_observaciones')->nullable();

            $table->boolean('es_echeq')->nullable();

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
        Schema::dropIfExists('cheques');
    }
}
