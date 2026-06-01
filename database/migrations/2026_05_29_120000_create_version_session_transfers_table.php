<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tokens de un solo uso para iniciar sesión en la versión SPA correcta
 * tras un login válido en otra versión (API distinta, misma base de datos).
 */
class CreateVersionSessionTransfersTable extends Migration
{
    /**
     * Crea la tabla de transferencias de sesión entre versiones.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('version_session_transfers', function (Blueprint $table) {
            $table->id();
            /** Hash del token enviado al navegador (no se guarda en claro). */
            $table->string('token_hash', 64)->unique();
            /** Usuario autenticado que generó el token. */
            $table->unsignedBigInteger('user_id');
            /** Expiración del token (uso único, ventana corta). */
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['user_id', 'expires_at']);
        });
    }

    /**
     * Elimina la tabla de transferencias.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('version_session_transfers');
    }
}
