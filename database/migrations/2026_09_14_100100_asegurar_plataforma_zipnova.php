<?php

use App\Models\Platform;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Deja la fila `zipnova` en el catálogo `platforms` (misión zipnova-envios, 14/9/2026).
 *
 * Es SOLO catálogo. A diferencia de Mercado Pago, Zipnova no tiene una app de ComercioCity con
 * `client_id`/`client_secret`: cada comercio se conecta con SU API Token + API Secret, generados
 * en su propia cuenta de Zipnova (Configuración → Integraciones → Gestionar credenciales y
 * webhooks), y eso vive en `platform_connectors.access_token`. Acá van los dos campos en null.
 *
 * 🔴 POR QUÉ VIVE EN UNA MIGRACIÓN Y NO SOLO EN `PlatformSeeder`: mismo motivo que
 * `2026_09_03_100400_asegurar_plataforma_mercado_pago`. Sin esta fila,
 * `PlatformConnector::find_or_create_for_user_and_slug()` no tiene a qué colgar el conector y el
 * botón "Conectar" de la tarjeta muere con "falta la plataforma". En el upgrade de un cliente
 * corren las migraciones, no necesariamente los seeders.
 */
class AsegurarPlataformaZipnova extends Migration
{
    /**
     * Crea la fila si falta. No pisa nada si ya existe.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('platforms')) {
            return;
        }

        if (Platform::where('slug', Platform::SLUG_ZIPNOVA)->exists()) {
            return;
        }

        Platform::create([
            'slug'          => Platform::SLUG_ZIPNOVA,
            'name'          => 'Zipnova',
            'client_id'     => null,
            'client_secret' => null,
            'extra_config'  => null,
        ]);
    }

    /**
     * No borra la fila: los conectores de los comercios que ya conectaron la referencian por
     * `platform_id`. Una fila de catálogo de más no molesta a nadie.
     *
     * @return void
     */
    public function down()
    {
        //
    }
}
