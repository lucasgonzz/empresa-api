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
        // client_id / client_secret de la app de ComercioCity en Mercado Libre: se leen de las
        // mismas MERCADO_LIBRE_CLIENT_ID / MERCADO_LIBRE_CLIENT_SECRET que ya se usan mas abajo
        // como fallback de Tienda Nube. Antes estaban hardcodeados aca (grupo 220, prompt 02);
        // el repositorio es publico, prohibido volver a escribir el valor real como default.
        $ml_client_id     = env('MERCADO_LIBRE_CLIENT_ID');
        $ml_client_secret = env('MERCADO_LIBRE_CLIENT_SECRET');

        if ((empty($ml_client_id) || empty($ml_client_secret)) && $this->command) {
            // No frena el seeder: solo avisa que estos datos quedaron sin configurar.
            $this->command->warn('PlatformSeeder: MERCADO_LIBRE_CLIENT_ID / MERCADO_LIBRE_CLIENT_SECRET no estan configurados, se sembraron como null.');
        }

        Platform::query()->updateOrCreate(
            ['slug' => Platform::SLUG_MERCADO_LIBRE],
            [
                'name'          => 'Mercado Libre',
                'client_id'     => empty($ml_client_id) ? null : $ml_client_id,
                'client_secret' => empty($ml_client_secret) ? null : $ml_client_secret,
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

        // Mercado Pago: la fila que ancla el conector de cobros de cada comercio. Las
        // credenciales son las de la APLICACION de ComercioCity en Mercado Pago Developers
        // (MP_APP_ID / MP_APP_SECRET del .env), nunca las del comercio — esas se guardan por
        // comercio en `platform_connectors` cuando completa el OAuth.
        //
        // Mismo patron que ML de mas arriba: si faltan en el .env se siembran como null y solo
        // se avisa, para no frenar el seeder de una instancia que todavia no conecto Mercado
        // Pago. Prohibido escribir el valor real como default: el repositorio es publico.
        $mp_app_id     = env('MP_APP_ID');
        $mp_app_secret = env('MP_APP_SECRET');

        if ((empty($mp_app_id) || empty($mp_app_secret)) && $this->command) {
            $this->command->warn('PlatformSeeder: MP_APP_ID / MP_APP_SECRET no estan configurados, se sembraron como null.');
        }

        Platform::query()->updateOrCreate(
            ['slug' => Platform::SLUG_MERCADO_PAGO],
            [
                'name'          => 'Mercado Pago',
                'client_id'     => empty($mp_app_id) ? null : $mp_app_id,
                'client_secret' => empty($mp_app_secret) ? null : $mp_app_secret,
                'extra_config'  => null,
            ]
        );
    }
}
