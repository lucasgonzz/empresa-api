<?php

namespace App\Http\Controllers;

use App\Models\ColumnPosition;
use Illuminate\Http\Request;

class ColumnPositionController extends Controller
{
    public function index(Request $request)
    {
        $models = ColumnPosition::where('user_id', $this->userId())
                             ->orderBy('created_at', 'DESC')
                             ->get();

        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request)
    {

        $positions_raw = $request->positions;
        $positions = [];

        foreach ($positions_raw as $item) {
            if (isset($item['text']) && isset($item['letra'])) {
                $positions[strtolower($item['text'])] = $item['letra'];
            }
        }

        $model = ColumnPosition::create([
            'user_id' => $this->userId(),
            'name' => $request->name,
            'model_name' => 'article',
            // 'model_name' => $request->model_name,
            'start_row' => $request->start_row,
            'positions' => $request->positions,
        ]);

        return response()->json(['model' => $model], 201);
    }

    public function destroy($id)
    {
        $column_position = ColumnPosition::where('id', $id)
                                         ->firstOrFail();

        $column_position->delete();

        return response()->json(['message' => 'Preset eliminado.']);
    }
}
