<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddGoogleLoginToOnlineConfigurationsTable extends Migration
{
    /**
     * Agrega a online_configurations las credenciales de Google para el login con Google de la
     * tienda online, que hasta ahora vivian en el .env de tienda-api (prompt 589, grupo 164).
     * Todas las columnas son nullable/con default para no romper las filas ya existentes.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('online_configurations', function (Blueprint $table) {
            // Master switch: si el comercio tiene habilitado el login con Google en su tienda.
            $table->boolean('google_login_enabled')->default(false);
            // Client ID de la app de Google OAuth del comercio. No es secreto, pero se guarda
            // junto al resto de la configuracion del comercio.
            $table->string('google_client_id', 191)->nullable();
            // Client Secret de la app de Google OAuth del comercio. Sensible: nunca debe
            // loguearse ni exponerse en logs (ver comentario en el controller).
            $table->string('google_client_secret', 191)->nullable();
        });
    }

    /**
     * Revierte las columnas de credenciales de Google agregadas en up().
     *
     * @return void
     */
    public function down()
    {
        Schema::table('online_configurations', function (Blueprint $table) {
            $table->dropColumn('google_login_enabled');
            $table->dropColumn('google_client_id');
            $table->dropColumn('google_client_secret');
        });
    }
}
