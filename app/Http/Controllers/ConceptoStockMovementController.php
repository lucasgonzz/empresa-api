<?php

namespace App\Http\Controllers;

use App\Models\ConceptoStockMovement;
use Illuminate\Http\Request;

class ConceptoStockMovementController extends Controller
{

    public function index() {
        $models = ConceptoStockMovement::orderBy('created_at', 'DESC')
                            ->get();
        return response()->json(['models' => $models], 200);
    }
}
