<?php

namespace Database\Seeders;

use App\Models\Platform;
use App\Models\PlatformConnector;
use Illuminate\Database\Seeder;

/**
 * Seed de desarrollo: conector ML conectado para config('app.USER_ID').
 * Reemplaza el antiguo MercadoLibreTokenSeeder (tabla mercado_libre_tokens).
 */
class MercadoLibreTokenSeeder extends Seeder
{
    /**
     * @return void
     */
    public function run()
    {
        $this->personal();
    }

    /**
     * Conector de ejemplo (tokens de desarrollo; rotar en producción).
     *
     * @return void
     */
    protected function personal()
    {
        $platform = Platform::query()
            ->where('slug', Platform::SLUG_MERCADO_LIBRE)
            ->first();
        if (!$platform) {
            return;
        }

        // Tokens de Mercado Libre para el conector de ejemplo: vienen de config/services.php
        // (.env del servidor), nunca hardcodeados (grupo 220, prompt 02, repositorio publico).
        $access_token  = config('services.mercado_libre.access_token');
        $refresh_token = config('services.mercado_libre.refresh_token');

        if (empty($access_token) || empty($refresh_token)) {
            // No frena el seeder: solo avisa que estos datos quedaron sin configurar.
            if ($this->command) {
                $this->command->warn('MercadoLibreTokenSeeder: MERCADO_LIBRE_SEED_ACCESS_TOKEN / MERCADO_LIBRE_SEED_REFRESH_TOKEN no estan configurados, se sembraron como null.');
            }
        }

        PlatformConnector::query()->updateOrCreate(
            [
                'user_id'     => config('app.USER_ID'),
                'platform_id' => $platform->id,
            ],
            [
                'status'            => PlatformConnector::STATUS_CONECTADO,
                'platform_user_id'  => '163250661',
                'access_token'      => empty($access_token) ? null : $access_token,
                'refresh_token'     => empty($refresh_token) ? null : $refresh_token,
                'expires_at'        => '2025-10-08 15:40:38',
            ]
        );
    }
}
