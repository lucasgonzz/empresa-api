<?php

namespace Database\Seeders;

use App\Models\MercadoLibreToken;
use Illuminate\Database\Seeder;

class MercadoLibreTokenSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {

        $this->personal();
        // $this->leudinox();
    }

    function personal() {
        
        MercadoLibreToken::create([
            'user_id'           => config('app.USER_ID'),
            'meli_user_id'      => '163250661',
            'access_token'      => 'APP_USR-6355072095226922-100808-bbbc8667dc3a7ad5b3f46d59dcb41840-163250661',
            'refresh_token'     => 'TG-68e65bc4e8377c0001fbe915-163250661',
            'expires_at'        => '2025-10-08 15:40:38',
        ]);

    }

    function leudinox() {
        
        MercadoLibreToken::create([
            'user_id'           => config('app.USER_ID'),
            'meli_user_id'      => '41181056',
            'access_token'      => 'APP_USR-6355072095226922-100811-eefc234429d163b11a3c55ebc9d3aef4-41181056',
            'refresh_token'     => 'TG-68e683c53b3f250001ad6813-41181056',
            'expires_at'        => '2025-10-08 18:31:18',
        ]);

    }
}
