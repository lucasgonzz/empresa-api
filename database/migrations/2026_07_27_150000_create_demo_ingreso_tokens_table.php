<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tokens de ingreso con sesión iniciada para leads que acceden a una demo
 * desde admin-api sin pantalla de login. A diferencia de los tokens de
 * transferencia entre versiones, este NO es de un solo uso: vale durante
 * toda la ventana del turno de demo.
 */
class CreateDemoIngresoTokensTable extends Migration
{
    /**
     * Crea la tabla de tokens de ingreso a demos.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('demo_ingreso_tokens', function (Blueprint $table) {
            $table->id();
            /** Hash del token que viaja en la URL (nunca se guarda en claro de este lado). */
            $table->string('token_hash', 64)->unique();
            /** Usuario demo que se autentica con este token. */
            $table->unsignedBigInteger('user_id');
            /** Fin de la ventana del turno mas la gracia. Nunca null. */
            $table->timestamp('expires_at');
            /** Revocacion manual desde el admin. Null = vigente. */
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'expires_at']);
        });
    }

    /**
     * Elimina la tabla de tokens de ingreso a demos.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('demo_ingreso_tokens');
    }
}
