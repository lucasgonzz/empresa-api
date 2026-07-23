<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de estados anti-CSRF del flujo OAuth de Mercado Pago (grupo 170, prompt 598).
 *
 * `connect` genera un `state` aleatorio y lo persiste acá atado al comercio (user_id) que lo
 * pidió, con un vencimiento corto. `callback` valida que el `state` recibido exista, no haya
 * vencido y todavía no se haya usado, y así sabe a qué comercio corresponde el `code` que
 * Mercado Pago le devuelve — sin esto, cualquiera podría mandar un `code` ajeno a un comercio
 * distinto del que autorizó (CSRF).
 *
 * No se usa el `Cache` facade del framework a propósito: este entorno corre CACHE_DRIVER=array
 * en local (ver .env), que no persiste entre requests, y el `connect` y el `callback` son dos
 * requests HTTP separados (el medio es la ventana del navegador que el comercio abre hacia
 * Mercado Pago). Una tabla chica evita depender de qué driver de cache esté configurado en cada
 * entorno.
 */
class CreateMercadoPagoOauthStatesTable extends Migration
{
    /**
     * Crea la tabla mercado_pago_oauth_states.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('mercado_pago_oauth_states', function (Blueprint $table) {
            $table->id();
            // Valor aleatorio (no adivinable) que se manda como `state` en la URL de autorización
            // de Mercado Pago y que el callback debe recibir de vuelta idéntico.
            $table->string('state', 64);
            // Comercio (user_id, igual criterio que el resto del repo: owner) que inició el
            // connect y al que hay que atar los tokens cuando vuelva el callback.
            $table->unsignedBigInteger('user_id');
            // Vencimiento corto del state: pasado este momento el callback lo rechaza aunque el
            // valor sea correcto (ventana de autorización abandonada/vieja).
            $table->timestamp('expires_at');
            // Se completa cuando el callback ya consumió este state, para que no pueda
            // reutilizarse dos veces (replay del mismo `state`/`code`).
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            // Índice simple (no unique compuesto) para el lookup del callback por state.
            $table->index('state', 'mpo_state_idx');
            // Índice para poder limpiar/listar states pendientes por comercio.
            $table->index('user_id', 'mpo_user_idx');
        });
    }

    /**
     * Elimina la tabla mercado_pago_oauth_states.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('mercado_pago_oauth_states');
    }
}
