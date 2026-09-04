<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega `platform_connectors.public_key`.
 *
 * Por que hace falta: al mudar Mercado Pago desde `online_configurations` a `platform_connectors`
 * hay cinco datos que mover y la tabla generica solo tenia lugar para cuatro
 * (`mp_access_token` -> `access_token`, `mp_refresh_token` -> `refresh_token`,
 * `mp_token_expires_at` -> `expires_at`, `mp_user_id` -> `platform_user_id`). Faltaba donde
 * poner `mp_public_key`, que es el dato que el checkout de la tienda necesita para tokenizar la
 * tarjeta en el navegador del comprador.
 *
 * NO es secreta (viaja al navegador de cualquiera que abra el checkout), asi que va como columna
 * comun sin cast `encrypted`, igual que `payment_methods.public_key`, que es de donde sale hoy.
 *
 * La columna es nullable y aditiva: ninguna fila existente se rompe y Mercado Libre / Tienda
 * Nube simplemente la dejan en null.
 *
 * @see database/migrations/2026_09_03_100300_copiar_credenciales_mp_a_conectores.php
 */
class AddPublicKeyToPlatformConnectorsTable extends Migration
{
    /**
     * Agrega la columna si todavia no existe.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('platform_connectors')) {
            return;
        }

        if (Schema::hasColumn('platform_connectors', 'public_key')) {
            return;
        }

        Schema::table('platform_connectors', function (Blueprint $table) {
            $table->string('public_key', 191)->nullable()->after('platform_user_id');
        });
    }

    /**
     * Saca la columna. No se pierde nada que no este tambien en su origen: esta mision COPIA las
     * credenciales, no las mueve — `payment_methods.public_key` y
     * `online_configurations.mp_public_key` siguen intactas.
     *
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('platform_connectors')) {
            return;
        }

        if (!Schema::hasColumn('platform_connectors', 'public_key')) {
            return;
        }

        Schema::table('platform_connectors', function (Blueprint $table) {
            $table->dropColumn('public_key');
        });
    }
}
