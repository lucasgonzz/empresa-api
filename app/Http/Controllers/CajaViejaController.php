<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\CajaChartsHelper;
use App\Http\Controllers\Helpers\CajaReportsHelper;
use Illuminate\Http\Request;

class CajaViejaController extends Controller
{

    public function reports($from_date, $until_date = 0, $employee_id = 0) {
        $reports = CajaReportsHelper::reports($this, $from_date, $until_date, $employee_id);
        return response()->json(['models' => $reports], 200);
    }

    public function charts($from_date, $until_date = null) {
        $reports = CajaChartsHelper::charts($this, $from_date, $until_date);
        return response()->json(['models' => $reports], 200);
    }

}
