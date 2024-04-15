<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DatabaseHelper {

	static function set_user_conecction($user = null) {
        Log::info('----------------------------------------');

        // if (is_null($user)) {

        // }
        // $ct = new Controller();
        // $owner = $ct->user();
        // Log::info('set_user_conecction');

        // if (!is_null($owner)) {
        //     Log::info('owner id: '.$owner->id);
        // } else {
        //     Log::info('owner vino null');
        // }

        if (!is_null($user->base_de_datos)) {
            Config::set('database.connections.mysql.database', $user->base_de_datos);
            DB::purge('mysql');
            DB::reconnect('mysql');
            Log::info('set_user_conecction -> se uso la base_de_datos: '.config('database.connections.mysql.database'));

            // $user = $ct->user(false);
            // $user = User::where('doc_number', $user->doc_number)
            //             ->first();
                        
            // Log::info('se seteo Auth::login');
            // Log::info(Auth()->user()->id);
        }
        Log::info('----------------------------------');
	}
	
}