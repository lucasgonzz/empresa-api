<?php

namespace App\Http\Controllers;

use App\Models\TableColumnPreference;
use Illuminate\Http\Request;

class TableColumnPreferenceController extends Controller
{
    /**
     * Valida que preference_type sea table, search o belongs_to_many (prefijo btm_).
     */
    protected function assert_preference_type(string $preference_type): void
    {
        if (in_array($preference_type, ['table', 'search'], true)) {
            return;
        }

        if (preg_match('/^btm_[a-z0-9_]+$/', $preference_type)) {
            return;
        }

        abort(404);
    }

    /**
     * Normaliza una columna guardada en JSON (tabla, búsqueda o belongs_to_many).
     */
    protected function normalize_column_payload(array $item): array
    {
        $column = [
            'key' => $item['key'],
            'visible' => (bool) $item['visible'],
            'order' => (int) $item['order'],
            'width' => isset($item['width']) ? (int) $item['width'] : null,
            'wrap_content' => isset($item['wrap_content']) ? (bool) $item['wrap_content'] : false,
        ];

        if (isset($item['source']) && $item['source'] !== '') {
            $column['source'] = $item['source'];
        }

        if (isset($item['row_id']) && $item['row_id'] !== '') {
            $column['row_id'] = $item['row_id'];
        }

        return $column;
    }

    public function show($model_name, $preference_type)
    {
        $this->assert_preference_type($preference_type);

        $model = TableColumnPreference::where('user_id', $this->userId())
            ->where('model_name', $model_name)
            ->where('preference_type', $preference_type)
            ->first();

        return response()->json([
            'model' => $model,
        ], 200);
    }

    public function update(Request $request, $model_name, $preference_type)
    {
        $this->assert_preference_type($preference_type);

        $request->validate([
            'columns' => 'required|array',
            'columns.*.key' => 'required|string',
            'columns.*.visible' => 'required|boolean',
            'columns.*.order' => 'required|integer',
            'columns.*.width' => 'nullable|integer|min:40|max:1200',
            'columns.*.wrap_content' => 'nullable|boolean',
            'columns.*.source' => 'nullable|string|in:model_prop,pivot_show,pivot_set',
            'columns.*.row_id' => 'nullable|string|max:120',
        ]);

        $columns = collect($request->columns)
            ->map(function ($item) {
                return $this->normalize_column_payload($item);
            })
            ->sortBy('order')
            ->values()
            ->all();

        $model = TableColumnPreference::updateOrCreate(
            [
                'user_id' => $this->userId(),
                'model_name' => $model_name,
                'preference_type' => $preference_type,
            ],
            [
                'columns' => $columns,
            ]
        );

        return response()->json([
            'model' => $model,
        ], 200);
    }
}
