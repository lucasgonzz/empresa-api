<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Último código postal (y localidad/provincia resuelta) con el que un comprador cotizó un envío
 * en la tienda, para no volver a pedírselo la próxima vez (misión envio-cp-buyer-modal,
 * 16/9/2026). Prefijo `envio_` a propósito: es la caché del cotizador de Zipnova, un dato
 * distinto de `buyers.ciudad/barrio/address` (dirección del checkout con zona propia, ya
 * existente) — no se reutiliza esa columna para no pisar una dirección real con lo que haya
 * resuelto el geocodificador.
 *
 * empresa-api es dueño de este esquema porque el deploy de tienda no corre `migrate`
 * (arquitectura_tecnica.md:470): tienda-api solo lee/escribe estas columnas si ya existen
 * (Schema::hasColumn), igual que ZipnovaEsquemaHelper.
 */
class AddEnvioZipcodeToBuyersTable extends Migration
{
    public function up()
    {
        Schema::table('buyers', function (Blueprint $table) {
            $table->string('envio_zipcode', 20)->nullable();
            $table->string('envio_city', 120)->nullable();
            $table->string('envio_state', 120)->nullable();
        });
    }

    public function down()
    {
        Schema::table('buyers', function (Blueprint $table) {
            $table->dropColumn(['envio_zipcode', 'envio_city', 'envio_state']);
        });
    }
}
