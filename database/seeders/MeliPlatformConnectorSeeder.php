<?php

namespace Database\Seeders;

use App\Models\PlatformConnector;
use Illuminate\Database\Seeder;

class MeliPlatformConnectorSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Access token de Mercado Libre para el conector de ejemplo: viene de config/services.php
        // (.env del servidor), nunca hardcodeado (grupo 220, prompt 02, repositorio publico).
        $access_token = config('services.mercado_libre.access_token');

        if (empty($access_token)) {
            // No frena el seeder: solo avisa que este dato quedo sin configurar.
            if ($this->command) {
                $this->command->warn('MeliPlatformConnectorSeeder: MERCADO_LIBRE_SEED_ACCESS_TOKEN no esta configurado, se sembro access_token=null.');
            }
            $access_token = null;
        }

        $model = PlatformConnector::create([
            'user_id'            => config('app.USER_ID'),
            'platform_id'        => 1,
            'status'             => PlatformConnector::STATUS_CONECTADO,
            'auth_code'          => 'TG-6a0b1d8ef9fd4700014bb1b9-163250661',
            'access_token'       => $access_token,
            'refresh_token'      => null,
            'expires_at'         => '2026-05-18 17:09:18',
            'platform_user_id'   => '163250661',
            'error_message'      => null,
        ]);
    }
}
