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
    /**
     * Agrega las columnas que falten. Guardas `hasColumn` una por una, no una sola por la tabla:
     * mismo criterio que `2026_09_14_100200_add_envio_to_carts_and_orders_tables.php`, para
     * tolerar que alguna ya se haya agregado a mano en producción antes de que llegue el release
     * (pasó de verdad, ver `parche-manual-en-produccion-de-truvari` en el repo de conocimiento) —
     * sin la guarda, esa migración corta con "Duplicate column name" y deja el upgrade a medias.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('buyers', function (Blueprint $table) {
            if (!Schema::hasColumn('buyers', 'envio_zipcode')) {
                $table->string('envio_zipcode', 20)->nullable();
            }
            if (!Schema::hasColumn('buyers', 'envio_city')) {
                $table->string('envio_city', 120)->nullable();
            }
            if (!Schema::hasColumn('buyers', 'envio_state')) {
                $table->string('envio_state', 120)->nullable();
            }
        });
    }

    /**
     * Saca las columnas que estén. El comprador simplemente vuelve a escribir su código postal
     * la próxima vez.
     *
     * @return void
     */
    public function down()
    {
        $presentes = array_values(array_filter(
            ['envio_zipcode', 'envio_city', 'envio_state'],
            function ($columna) {
                return Schema::hasColumn('buyers', $columna);
            }
        ));

        if (count($presentes) === 0) {
            return;
        }

        Schema::table('buyers', function (Blueprint $table) use ($presentes) {
            $table->dropColumn($presentes);
        });
    }
}
