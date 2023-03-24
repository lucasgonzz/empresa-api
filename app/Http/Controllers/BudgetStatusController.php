<?php

namespace App\Http\Controllers;

use App\Models\BudgetStatus;
use Illuminate\Http\Request;

class BudgetStatusController extends Controller
{
    function index() {
        $models = BudgetStatus::all();
        return response()->json(['models' => $models], 200);
    }
}
