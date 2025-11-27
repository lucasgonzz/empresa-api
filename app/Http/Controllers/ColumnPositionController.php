<?php

namespace App\Http\Controllers;

use App\Models\ColumnPosition;
use App\Models\Provider;
use Illuminate\Http\Request;
use Carbon\Carbon;

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

        $name = '';
        
        $provider_id = $request->provider_id;

        if ($provider_id) {
            $provider = Provider::find($provider_id);
            if ($provider) {
                $name .= $provider->name.' ';
            }
        }

        $name .= Carbon::now()->format('d/m/y H:m');

        $model = ColumnPosition::create([
            'user_id' => $this->userId(),
            'name' => $name,
            'model_name' => 'article',
            // 'model_name' => $request->model_name,
            'start_row' => $request->start_row,
            'positions' => $request->positions,
            'provider_id'                   => $request->provider_id,
            'create_and_edit'               => $request->create_and_edit,
            'no_actualizar_otro_proveedor'  => $request->no_actualizar_otro_proveedor,
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
