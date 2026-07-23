<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMercadopagoZippinCredentialsToOnlineConfigurationsTable extends Migration
{
    /**
     * Agrega a online_configurations el estado de conexion y los tokens OAuth de Mercado Pago
     * (cobros) y Zippin (envios) para el checkout propio de cada comercio (grupo 169, prompt
     * 597). ComercioCity no es intermediario: cada comercio conecta su propia cuenta via OAuth y
     * los tokens quedan guardados aca, igual que las credenciales de Google (prompt 589/590).
     * Todas las columnas son nullable/con default para no romper las filas ya existentes.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('online_configurations', function (Blueprint $table) {
            // --- Mercado Pago ---
            // Master switch: si el comercio tiene habilitado el cobro con Mercado Pago.
            $table->boolean('mp_enabled')->default(false);
            // user_id del vendedor en Mercado Pago que devuelve el OAuth. No es secreto.
            $table->string('mp_user_id', 191)->nullable();
            // Public key de la cuenta conectada, se usa en el frontend para tokenizar la tarjeta.
            $table->string('mp_public_key', 191)->nullable();
            // Access token OAuth de Mercado Pago. Sensible: se guarda cifrado (cast 'encrypted'
            // en el modelo) y nunca debe exponerse en un response ni loguearse.
            $table->text('mp_access_token')->nullable();
            // Refresh token OAuth de Mercado Pago, usado para renovar el access_token vencido.
            // Sensible, mismo tratamiento que mp_access_token.
            $table->text('mp_refresh_token')->nullable();
            // Fecha/hora de expiracion del access_token vigente, para saber cuando refrescarlo.
            $table->timestamp('mp_token_expires_at')->nullable();

            // --- Zippin ---
            // Master switch: si el comercio tiene habilitado el calculo/gestion de envios con Zippin.
            $table->boolean('zippin_enabled')->default(false);
            // Identificador de la cuenta de Zippin conectada. No es secreto.
            $table->string('zippin_account_id', 191)->nullable();
            // Access token OAuth de Zippin. Sensible: cifrado (cast 'encrypted'), nunca expuesto.
            $table->text('zippin_access_token')->nullable();
            // Refresh token OAuth de Zippin, usado para renovar el access_token vencido.
            $table->text('zippin_refresh_token')->nullable();
            // Fecha/hora de expiracion del access_token vigente de Zippin.
            $table->timestamp('zippin_token_expires_at')->nullable();
        });
    }

    /**
     * Revierte las columnas de credenciales de Mercado Pago y Zippin agregadas en up().
     *
     * @return void
     */
    public function down()
    {
        Schema::table('online_configurations', function (Blueprint $table) {
            $table->dropColumn('mp_enabled');
            $table->dropColumn('mp_user_id');
            $table->dropColumn('mp_public_key');
            $table->dropColumn('mp_access_token');
            $table->dropColumn('mp_refresh_token');
            $table->dropColumn('mp_token_expires_at');

            $table->dropColumn('zippin_enabled');
            $table->dropColumn('zippin_account_id');
            $table->dropColumn('zippin_access_token');
            $table->dropColumn('zippin_refresh_token');
            $table->dropColumn('zippin_token_expires_at');
        });
    }
}
