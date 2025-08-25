<?php

namespace App\Http\Controllers;

use App\Models\GeocoderCounter;
use Illuminate\Http\Request;
use Carbon\Carbon;

class GoogleController extends Controller
{
    
    function aumentar_contador_custom_search() {
        $counter = $this->get_current_acounter();
        $counter->counter += 1;
        $counter->save();
        return response(null, 200);
    }

    function get_current_acounter() {

        $counter = GeocoderCounter::where('user_id', $this->userId())
                                    ->whereDate('created_at', Carbon::today())
                                    ->first();

        if (!$counter) {
            $counter = GeocoderCounter::create([
                'counter'   => 0,
                'user_id'   => $this->userId(),
            ]);
        } 

        return $counter;
    }

}
