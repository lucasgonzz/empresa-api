<?php

use App\Models\Platform;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Deja la fila `andreani` en el catálogo `platforms` (misión envios-andreani, 25/11/2026).
 *
 * Es SOLO catálogo. Igual que Zipnova, Andreani no tiene una app de ComercioCity con `client_id`/`client_secret`:
 * cada comercio carga SU número de cliente y sus contratos (en `platform_connectors.platform_user_id` y
 * `extra_config`), así que los dos campos van en null.
 *
 * 🔴 POR QUÉ VIVE EN UNA MIGRACIÓN Y NO SOLO EN `PlatformSeeder`: sin esta fila
 * `PlatformConnector::find_or_create_for_user_and_slug()` no tiene a qué colgar el conector y el botón "Conectar"
 * de la tarjeta muere con "falta la plataforma". En el upgrade de un cliente corren las migraciones, no
 * necesariamente los seeders. Es idempotente: si la fila ya existe no hace nada.
 */
class AsegurarPlataformaAndreani extends Migration
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

        if (Platform::where('slug', Platform::SLUG_ANDREANI)->exists()) {
            return;
        }

        Platform::create([
            'slug'          => Platform::SLUG_ANDREANI,
            'name'          => 'Andreani',
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
