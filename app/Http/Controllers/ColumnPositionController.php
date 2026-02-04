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
        $positions_raw = $request->positions ?? [];
        $positions = [];

        // Normalizamos las posiciones a un formato estable:
        // - key: strtolower(trim(text))
        // - value: strtoupper(trim(letra))
        foreach ($positions_raw as $item) {
            if (isset($item['text']) && isset($item['letra'])) {
                $key = strtolower(trim((string) $item['text']));
                $val = strtoupper(trim((string) $item['letra']));
                if ($key !== '' && $val !== '') {
                    $positions[$key] = $val;
                }
            }
        }

        // Orden estable para que no dependa del orden del array
        ksort($positions);

        // Armamos payload de comparación (lo que define que sea "idéntica")
        $comparison_payload = [
            'user_id' => (int) $this->userId(),
            'model_name' => 'article',
            'start_row' => (int) ($request->start_row ?? 1),
            'provider_id' => $request->provider_id ? (int) $request->provider_id : null,
            'create_and_edit' => (int) ($request->create_and_edit ?? 0),
            'no_actualizar_otro_proveedor' => (int) ($request->no_actualizar_otro_proveedor ?? 0),
            'positions' => $positions,
        ];

        $current_signature = md5(json_encode($comparison_payload));

        // Traemos la última config creada para este user/model
        $last = ColumnPosition::where('user_id', $this->userId())
            ->where('model_name', 'article')
            ->orderBy('id', 'desc')
            ->first();

        if ($last) {
            $last_positions_raw = $last->positions;

            // Puede venir como array (casts) o string JSON
            if (is_string($last_positions_raw)) {
                $decoded = json_decode($last_positions_raw, true);
                $last_positions_raw = is_array($decoded) ? $decoded : [];
            }

            $last_positions = [];
            foreach (($last_positions_raw ?? []) as $item) {
                if (isset($item['text']) && isset($item['letra'])) {
                    $key = strtolower(trim((string) $item['text']));
                    $val = strtoupper(trim((string) $item['letra']));
                    if ($key !== '' && $val !== '') {
                        $last_positions[$key] = $val;
                    }
                }
            }
            ksort($last_positions);

            $last_payload = [
                'user_id' => (int) $last->user_id,
                'model_name' => (string) $last->model_name,
                'start_row' => (int) $last->start_row,
                'provider_id' => $last->provider_id ? (int) $last->provider_id : null,
                'create_and_edit' => (int) ($last->create_and_edit ?? 0),
                'no_actualizar_otro_proveedor' => (int) ($last->no_actualizar_otro_proveedor ?? 0),
                'positions' => $last_positions,
            ];

            $last_signature = md5(json_encode($last_payload));

            // Si es idéntica a la última: no guardamos
            if ($current_signature === $last_signature) {
                return response()->json([
                    'model' => $last,
                    'saved' => false,
                    'message' => 'La configuración es idéntica a la última guardada. No se creó una nueva.',
                ], 200);
            }
        }

        // Nombre (nota: H:i para minutos, H:m te pone mes)
        $name = '';
        $provider_id = $request->provider_id;

        if ($provider_id) {
            $provider = Provider::find($provider_id);
            if ($provider) {
                $name .= $provider->name . ' ';
            }
        }

        $name .= Carbon::now()->format('d/m/y H:i');

        // Si es distinta: guardamos
        $model = ColumnPosition::create([
            'user_id' => $this->userId(),
            'name' => $name,
            'model_name' => 'article',
            'start_row' => $request->start_row,
            'positions' => $request->positions, // guardamos el raw para no romper el front
            'provider_id' => $request->provider_id,
            'create_and_edit' => $request->create_and_edit,
            'no_actualizar_otro_proveedor' => $request->no_actualizar_otro_proveedor,
        ]);

        return response()->json([
            'model' => $model,
            'saved' => true,
        ], 201);
    }


    // public function store(Request $request)
    // {

    //     $positions_raw = $request->positions;
    //     $positions = [];

    //     foreach ($positions_raw as $item) {
    //         if (isset($item['text']) && isset($item['letra'])) {
    //             $positions[strtolower($item['text'])] = $item['letra'];
    //         }
    //     }

    //     $name = '';
        
    //     $provider_id = $request->provider_id;

    //     if ($provider_id) {
    //         $provider = Provider::find($provider_id);
    //         if ($provider) {
    //             $name .= $provider->name.' ';
    //         }
    //     }

    //     $name .= Carbon::now()->format('d/m/y H:m');

    //     $model = ColumnPosition::create([
    //         'user_id' => $this->userId(),
    //         'name' => $name,
    //         'model_name' => 'article',
    //         // 'model_name' => $request->model_name,
    //         'start_row' => $request->start_row,
    //         'positions' => $request->positions,
    //         'provider_id'                   => $request->provider_id,
    //         'create_and_edit'               => $request->create_and_edit,
    //         'no_actualizar_otro_proveedor'  => $request->no_actualizar_otro_proveedor,
    //     ]);

    //     return response()->json(['model' => $model], 201);
    // }

    public function destroy($id)
    {
        $column_position = ColumnPosition::where('id', $id)
                                         ->firstOrFail();

        $column_position->delete();

        return response()->json(['message' => 'Preset eliminado.']);
    }
}
