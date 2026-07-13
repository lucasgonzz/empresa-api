<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 358 - Correo propio por cliente.
 *
 * Agrega a online_configurations la configuracion de SMTP propia de cada cliente, para que los
 * mails salientes a sus compradores (ej. "ingreso stock del articulo que esperabas") se manden
 * desde la casilla del comercio en vez de la generica del .env. Si el cliente no configura nada
 * (o mail_enabled queda en false), el sistema sigue usando el .env como hasta ahora (fallback).
 */
class AddMailConfigToOnlineConfigurationsTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::table('online_configurations', function (Blueprint $table) {
            // Master switch: si esta en false, se ignora toda la config de abajo y se usa el .env.
            $table->boolean('mail_enabled')->default(false)->after('aviso_antes_de_confirmar_compra');
            // Host del servidor SMTP del cliente (ej. smtp.hostinger.com).
            $table->string('mail_host', 120)->nullable()->after('mail_enabled');
            // Puerto SMTP, 587 por defecto (TLS).
            $table->integer('mail_port')->nullable()->default(587)->after('mail_host');
            // Tipo de encriptacion del transporte SMTP: tls / ssl / null.
            $table->string('mail_encryption', 20)->nullable()->after('mail_port');
            // Usuario/casilla de la cuenta SMTP del cliente.
            $table->string('mail_username', 150)->nullable()->after('mail_encryption');
            // Contraseña de la cuenta SMTP, guardada ENCRIPTADA. Se usa text (no string) porque el
            // ciphertext de Laravel es mucho mas largo que la contraseña original y un varchar(255)
            // la trunca en silencio.
            $table->text('mail_password')->nullable()->after('mail_username');
            // Direccion "from" a usar en los mails; si esta vacia se usa mail_username.
            $table->string('mail_from_address', 150)->nullable()->after('mail_password');
            // Nombre "from" a usar en los mails; si esta vacio se usa el company_name del user.
            $table->string('mail_from_name', 120)->nullable()->after('mail_from_address');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('online_configurations', function (Blueprint $table) {
            $table->dropColumn([
                'mail_enabled',
                'mail_host',
                'mail_port',
                'mail_encryption',
                'mail_username',
                'mail_password',
                'mail_from_address',
                'mail_from_name',
            ]);
        });
    }
}
