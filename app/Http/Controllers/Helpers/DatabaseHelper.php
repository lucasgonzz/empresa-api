<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DatabaseHelper {

	static function set_user_conecction($base_de_datos, $set_user_name = true) {
        // Log::info('----------------------------------------');

        if (!is_null($base_de_datos)) {
            Config::set('database.connections.mysql.database', $base_de_datos);

            if (env('APP_ENV') == 'production') {
                Log::info('Setenado bbdd');
                if ($set_user_name) {
                    Config::set('database.connections.mysql.username', $base_de_datos);
                } else {
                    Config::set('database.connections.mysql.username', 'u767360347_lucas');
                }
            }


            DB::purge('mysql');
            DB::reconnect('mysql');
            // Log::info('Se puso la base_de_datos: '.config('database.connections.mysql.database'));
            // Log::info('Se puso la username: '.config('database.connections.mysql.username'));

            // $user = $ct->user(false);
            // $user = User::where('doc_number', $user->doc_number)
            //             ->first();
                        
        }
        // Log::info('----------------------------------');
	}
	
}