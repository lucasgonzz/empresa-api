<?php

namespace App\Http\Controllers;

use App\Models\PdfColumnOption;
use App\Services\PdfColumnService;
use Illuminate\Http\Request;

class PdfColumnOptionController extends Controller
{
    public function index(Request $request)
    {
        $model_name = $request->query('model_name');

        if ($model_name) {
            $models = PdfColumnService::get_options($model_name);
            return response()->json(['models' => $models], 200);
        }

        $models = PdfColumnOption::orderBy('model_name')
            ->orderBy('order')
            ->get();

        return response()->json(['models' => $models], 200);
    }

    public function show($id)
    {
        PdfColumnOption::where('id', $id)->firstOrFail();

        return response()->json(['model' => $this->fullModel('PdfColumnOption', $id)], 200);
    }
}

