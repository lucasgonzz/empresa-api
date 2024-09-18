<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\DepositMovementStatus;
use Illuminate\Http\Request;

class DepositMovementStatusController extends Controller
{

    public function index() {
        $models = DepositMovementStatus::orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }
}
