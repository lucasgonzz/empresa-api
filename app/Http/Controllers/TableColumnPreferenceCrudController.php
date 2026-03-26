<?php

namespace App\Http\Controllers;

use App\Models\TableColumnPreference;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TableColumnPreferenceCrudController extends Controller
{
    public function index()
    {
        $models = TableColumnPreference::where('user_id', $this->userId())
            ->orderBy('model_name')
            ->orderBy('preference_type')
            ->get();

        return response()->json(['models' => $models], 200);
    }

    public function show($id)
    {
        $model = TableColumnPreference::where('user_id', $this->userId())
            ->where('id', $id)
            ->firstOrFail();

        return response()->json(['model' => $model], 200);
    }

    public function store(Request $request)
    {
        $user_id = $this->userId();
        $columns = $this->normalizeColumnsInput($request->input('columns'));

        $request->merge(['columns' => $columns]);

        $request->validate([
            'model_name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('table_column_preferences', 'model_name')->where(function ($query) use ($user_id, $request) {
                    return $query->where('user_id', $user_id)
                        ->where('preference_type', $request->input('preference_type'));
                }),
            ],
            'preference_type' => 'required|string|in:table,search',
            'columns' => 'required|array',
            'columns.*.key' => 'required|string',
            'columns.*.visible' => 'required|boolean',
            'columns.*.order' => 'required|integer',
            'columns.*.width' => 'nullable|integer|min:40|max:1200',
            'columns.*.wrap_content' => 'nullable|boolean',
        ]);

        $model = TableColumnPreference::create([
            'user_id' => $user_id,
            'model_name' => $request->model_name,
            'preference_type' => $request->preference_type,
            'columns' => $columns,
        ]);

        return response()->json(['model' => $model], 201);
    }

    public function update(Request $request, $id)
    {
        $model = TableColumnPreference::where('user_id', $this->userId())
            ->where('id', $id)
            ->firstOrFail();

        $user_id = $this->userId();

        if ($request->has('columns')) {
            $columns = $this->normalizeColumnsInput($request->input('columns'));
            $request->merge(['columns' => $columns]);
        }

        $request->validate([
            'model_name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('table_column_preferences', 'model_name')
                    ->ignore($model->id)
                    ->where(function ($query) use ($user_id, $request, $model) {
                        $type = $request->has('preference_type')
                            ? $request->input('preference_type')
                            : $model->preference_type;

                        return $query->where('user_id', $user_id)
                            ->where('preference_type', $type);
                    }),
            ],
            'preference_type' => 'sometimes|string|in:table,search',
            'columns' => 'sometimes|array',
            'columns.*.key' => 'required_with:columns|string',
            'columns.*.visible' => 'required_with:columns|boolean',
            'columns.*.order' => 'required_with:columns|integer',
            'columns.*.width' => 'nullable|integer|min:40|max:1200',
            'columns.*.wrap_content' => 'nullable|boolean',
        ]);

        $fillable = $request->only(['model_name', 'preference_type', 'columns']);
        if (isset($fillable['columns'])) {
            $fillable['columns'] = $this->normalizeColumnsInput($fillable['columns']);
        }

        $model->update($fillable);

        return response()->json(['model' => $model->fresh()], 200);
    }

    public function destroy($id)
    {
        $model = TableColumnPreference::where('user_id', $this->userId())
            ->where('id', $id)
            ->firstOrFail();
        $model->delete();

        return response()->json(null, 204);
    }

    /**
     * @param  mixed  $columns
     */
    private function normalizeColumnsInput($columns): array
    {
        if (is_string($columns)) {
            $decoded = json_decode($columns, true);
            $columns = is_array($decoded) ? $decoded : [];
        }
        if (! is_array($columns)) {
            return [];
        }

        return $columns;
    }
}
