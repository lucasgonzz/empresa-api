<?php

use App\Models\Platform;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Deja la fila `mercado_pago` en el catálogo `platforms`.
 *
 * Es SOLO catálogo: la app de ComercioCity en Mercado Pago Developers, la misma para todos los
 * clientes. No crea ningún conector ni toca la credencial de ningún comercio — eso es lo que la
 * decisión del 3/9/2026 dejó explícitamente afuera (ver
 * `2026_09_03_100300_copiar_credenciales_mp_a_conectores.php`).
 *
 * 🔴 POR QUÉ VIVE EN UNA MIGRACIÓN Y NO SOLO EN `PlatformSeeder`. Sin esta fila,
 * `MercadoPagoOAuthService::build_authorization_url()` corta con "Falta la plataforma
 * mercado_pago en el catálogo" y el comercio NO PUEDE CONECTAR NI A MANO: la función entera
 * queda muerta. Y en el upgrade de un cliente corren las migraciones, no necesariamente los
 * seeders. Es exactamente el mismo motivo por el que las filas de Mercado Libre y Tienda Nube
 * las siembra la migración `2026_05_13_160000_create_platforms_and_refactor_platform_connectors`
 * y no el seeder: el precedente ya estaba en el repo.
 *
 * `PlatformSeeder` la sigue teniendo igual, para una instalación desde cero. Las dos usan
 * `updateOrCreate` sobre el slug, así que correr las dos no duplica nada.
 */
class AsegurarPlataformaMercadoPago extends Migration
{
    /**
     * Crea la fila si falta; si ya existe, NO le pisa las credenciales con los null de un `.env`
     * sin configurar (que es lo que pasaría con un `updateOrCreate` de campos completos en una
     * instancia donde alguien ya las cargó a mano).
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('platforms')) {
            return;
        }

        if (Platform::where('slug', Platform::SLUG_MERCADO_PAGO)->exists()) {
            return;
        }

        $mp_app_id     = env('MP_APP_ID');
        $mp_app_secret = env('MP_APP_SECRET');

        Platform::create([
            'slug'          => Platform::SLUG_MERCADO_PAGO,
            'name'          => 'Mercado Pago',
            'client_id'     => empty($mp_app_id) ? null : $mp_app_id,
            'client_secret' => empty($mp_app_secret) ? null : $mp_app_secret,
            'extra_config'  => null,
        ]);
    }

    /**
     * No borra la fila: si algún comercio ya conectó, sus conectores la referencian por
     * `platform_id` y borrarla los dejaría huérfanos. Una fila de catálogo de más no molesta a
     * nadie — el ABM de conectores ya no la ofrece (`Platform::SLUGS_CONECTABLES_POR_ABM`).
     *
     * @return void
     */
    public function down()
    {
        //
    }
}
