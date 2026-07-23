<?php

namespace App\Http\Controllers;

use App\Models\TableColumnPreference;
use Illuminate\Database\QueryException;
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

        // Id real del usuario autenticado (dueño o empleado) y del dueño de la cuenta.
        $real_user_id = $this->userId(false);
        $owner_id = $this->userId(true);

        // orderBy determinista por id: si por algun motivo quedaran duplicados sin limpiar
        // (por ejemplo si la migracion de dedupe todavia no corrio en esta base), siempre
        // se toma la misma fila entre requests.
        $model = TableColumnPreference::where('user_id', $real_user_id)
            ->where('model_name', $model_name)
            ->where('preference_type', $preference_type)
            ->orderBy('id', 'desc')
            ->first();

        // El usuario (empleado) no tiene override propio: cae a la config compartida del dueño.
        if (!$model && $real_user_id != $owner_id) {
            $model = TableColumnPreference::where('user_id', $owner_id)
                ->where('model_name', $model_name)
                ->where('preference_type', $preference_type)
                ->orderBy('id', 'desc')
                ->first();
        }

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

        // Cada usuario (dueño o empleado) guarda su propia fila, sin pisar la de otros.
        $keys = [
            'user_id' => $this->userId(false),
            'model_name' => $model_name,
            'preference_type' => $preference_type,
        ];

        $values = [
            'columns' => $columns,
        ];

        try {
            $model = TableColumnPreference::updateOrCreate($keys, $values);
        } catch (QueryException $e) {
            // updateOrCreate hace SELECT y despues INSERT: no es atomico. Con el indice unico
            // (migracion 2026_07_24_100000) dos PUT simultaneos del mismo usuario para el mismo
            // modelo y tipo pueden intentar insertar los dos; el segundo choca con el unico.
            // Ese caso puntual se reintenta como update, que es lo que el usuario pidio.
            if (!$this->is_duplicate_entry_exception($e)) {
                throw $e;
            }

            $model = TableColumnPreference::where($keys)->first();

            if (!$model) {
                throw $e;
            }

            $model->update($values);
        }

        return response()->json([
            'model' => $model,
        ], 200);
    }

    /**
     * Indica si la excepcion de base corresponde a una violacion de indice unico (MySQL 1062).
     */
    protected function is_duplicate_entry_exception(QueryException $e)
    {
        return isset($e->errorInfo[1]) && $e->errorInfo[1] == 1062;
    }
}
