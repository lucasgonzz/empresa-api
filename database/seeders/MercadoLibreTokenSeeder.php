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
            'user_id'           => env('USER_ID'),
            'meli_user_id'      => '163250661',
            'access_token'      => 'APP_USR-6355072095226922-100616-c9640bd1db6b0b68f537306528c0f4d2-163250661',
            'refresh_token'     => 'TG-68e421ab1540d10001d3e908-163250661',
            'expires_at'        => '2025-10-06 23:08:11',
        ]);

    }

    function leudinox() {
        
        MercadoLibreToken::create([
            'user_id'           => env('USER_ID'),
            'meli_user_id'      => '41181056',
            'access_token'      => 'APP_USR-6355072095226922-100616-6e9e4f77a92469ccacd27c559a7cc00d-41181056',
            'refresh_token'     => 'TG-68e41fd103af2300015943ec-41181056',
            'expires_at'        => '2025-10-03 18:18:50',
        ]);

    }
}
