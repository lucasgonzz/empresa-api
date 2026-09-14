<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega `platform_connectors.extra_config` (json, nullable).
 *
 * Por qué hace falta: la conexión con Zipnova (misión zipnova-envios, 14/9/2026) necesita guardar,
 * por comercio, cosas que no son credenciales y que la tabla genérica no tenía dónde poner: el
 * nombre de la cuenta, el depósito de origen elegido, el bulto por defecto para cotizar artículos
 * sin peso y medidas, el umbral de envío gratis y el id del webhook registrado en Zipnova.
 *
 * Va como JSON y no como columnas sueltas a propósito: son preferencias de UNA plataforma, y cada
 * integración futura (Mercado Libre, Tienda Nube) puede usar la misma columna con sus propias
 * claves sin otra migración. `platforms.extra_config` ya sigue este mismo criterio a nivel
 * catálogo; ésta es la contraparte a nivel conector.
 *
 * NO lleva nada secreto: el token de Zipnova sigue en `access_token`, que tiene cast `encrypted`.
 *
 * Aditiva y nullable: ninguna fila existente cambia. `tienda-api` (que comparte la base) la lee
 * con cast `array` y tolera que falte, porque los dos proyectos no llegan a producción a la vez.
 */
class AddExtraConfigToPlatformConnectorsTable extends Migration
{
    /**
     * Agrega la columna si todavía no existe.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('platform_connectors')) {
            return;
        }

        if (Schema::hasColumn('platform_connectors', 'extra_config')) {
            return;
        }

        Schema::table('platform_connectors', function (Blueprint $table) {
            $table->json('extra_config')->nullable()->after('error_message');
        });
    }

    /**
     * Saca la columna. Lo único que se pierde son preferencias que el comercio vuelve a cargar
     * desde la tarjeta de la integración; las credenciales quedan intactas en `access_token`.
     *
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('platform_connectors')) {
            return;
        }

        if (!Schema::hasColumn('platform_connectors', 'extra_config')) {
            return;
        }

        Schema::table('platform_connectors', function (Blueprint $table) {
            $table->dropColumn('extra_config');
        });
    }
}
