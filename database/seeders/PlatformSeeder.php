<?php

namespace Database\Seeders;

use App\Models\Platform;
use Illuminate\Database\Seeder;

/**
 * Carga o actualiza las plataformas globales con las claves de la app Comercio City.
 *
 * Variables de entorno esperadas:
 * - ML: `MERCADO_LIBRE_CLIENT_ID`, `MERCADO_LIBRE_CLIENT_SECRET`
 * - TN: `TN_CLIENT_ID`, `TN_CLIENT_SECRET` (si faltan, se reutilizan las de ML solo como fallback de seed)
 * - TN opcional: `TN_APP_ID` en `extra_config` para la URL `/apps/{app_id}/authorize`
 */
class PlatformSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        Platform::query()->updateOrCreate(
            ['slug' => Platform::SLUG_MERCADO_LIBRE],
            [
                'name'          => 'Mercado Libre',
                'client_id'     => '6355072095226922',
                'client_secret' => 'nHBJ178VU9RTQYzpaGq7hWis37101wM9',
                'extra_config'  => null,
            ]
        );

        $tn_extra = null;
        $tn_app_id = env('TN_APP_ID');
        if (!empty($tn_app_id)) {
            $tn_extra = ['app_id' => $tn_app_id];
        }

        Platform::query()->updateOrCreate(
            ['slug' => Platform::SLUG_TIENDA_NUBE],
            [
                'name'          => 'Tienda Nube',
                'client_id'     => env('TN_CLIENT_ID') ?: env('MERCADO_LIBRE_CLIENT_ID'),
                'client_secret' => env('TN_CLIENT_SECRET') ?: env('MERCADO_LIBRE_CLIENT_SECRET'),
                'extra_config'  => $tn_extra,
            ]
        );
    }
}
