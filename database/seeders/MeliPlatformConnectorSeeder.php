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
        $model = PlatformConnector::create([
            'user_id'            => config('app.USER_ID'),
            'platform_id'        => 1,
            'status'             => PlatformConnector::STATUS_CONECTADO,
            'auth_code'          => 'TG-6a0b1d8ef9fd4700014bb1b9-163250661',
            'access_token'       => 'APP_USR-4899702990695294-051810-29d958dbf51705add6041238e24bbcaf-163250661',
            'refresh_token'      => null,
            'expires_at'         => '2026-05-18 17:09:18',
            'platform_user_id'   => '163250661',
            'error_message'      => null,
        ]);
    }
}
