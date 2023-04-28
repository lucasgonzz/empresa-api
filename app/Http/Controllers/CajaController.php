<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\CajaHelper;
use Illuminate\Http\Request;

class CajaController extends Controller
{

    public function reports($from_date, $until_date = null) {
        $reports = CajaHelper::reports($this, $from_date, $until_date);
        return response()->json(['models' => $reports], 200);
    }

}
