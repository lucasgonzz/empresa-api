<?php

namespace App\Http\Controllers;

use App\Models\TableColumnPreference;
use Illuminate\Http\Request;

class TableColumnPreferenceController extends Controller
{
    protected function assert_preference_type(string $preference_type): void
    {
        if (! in_array($preference_type, ['table', 'search'], true)) {
            abort(404);
        }
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
            'columns.*.fade_when_truncated' => 'nullable|boolean',
        ]);

        $columns = collect($request->columns)
            ->map(function ($item) {
                return [
                    'key' => $item['key'],
                    'visible' => (bool) $item['visible'],
                    'order' => (int) $item['order'],
                    'width' => isset($item['width']) ? (int) $item['width'] : null,
                    'wrap_content' => isset($item['wrap_content']) ? (bool) $item['wrap_content'] : false,
                    'fade_when_truncated' => isset($item['fade_when_truncated']) ? (bool) $item['fade_when_truncated'] : true,
                ];
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
