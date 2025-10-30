<?php

namespace App\Http\Controllers;

use App\Models\ClientHome;
use App\Models\User;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    function clients() {
        $models = ClientHome::orderBy('home_position', 'ASC')
                        ->get();
        return response()->json(['models' => $models], 200);
    }
}
