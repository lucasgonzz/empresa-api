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

        PlatformConnector::query()->updateOrCreate(
            [
                'user_id'     => config('app.USER_ID'),
                'platform_id' => $platform->id,
            ],
            [
                'status'            => PlatformConnector::STATUS_CONECTADO,
                'platform_user_id'  => '163250661',
                'access_token'      => 'APP_USR-6355072095226922-100808-bbbc8667dc3a7ad5b3f46d59dcb41840-163250661',
                'refresh_token'     => 'TG-68e65bc4e8377c0001fbe915-163250661',
                'expires_at'        => '2025-10-08 15:40:38',
            ]
        );
    }
}
