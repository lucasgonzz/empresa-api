<?php

namespace App\Http\Controllers;

use App\Models\TableColumnPreference;
use Illuminate\Http\Request;

class TableColumnPreferenceController extends Controller
{
    /**
     * Tipos de preference_type soportados:
     * - table: columnas visibles de la tabla principal de un modelo.
     * - search: columnas del modal de búsqueda de un modelo.
     * - form_has_many: columnas del formulario has_many embebido.
     * - global_search: props que entran en la búsqueda por texto del buscador general
     *   (guarda también, por columna, `keyword_mode`, y a nivel de fila el `settings` con el
     *   modo de búsqueda del modelo, ej: { "conector": "or" }).
     * - global_search_filters: filtros fijos que el usuario agrega al lado del buscador general
     *   (guarda por columna `filter_kind`, `operator` y `default_value`).
     * - btm_*: columnas de una relación belongs_to_many puntual (ej: btm_provider_order_articles).
     *
     * Valida que preference_type sea alguno de los anteriores; aborta con 404 si no.
     */
    protected function assert_preference_type(string $preference_type): void
    {
        if (in_array($preference_type, ['table', 'search', 'form_has_many', 'global_search', 'global_search_filters'], true)) {
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

        // Normaliza y castea el ancho (cols, 1..12) de la columna dentro del formulario has_many
        if (isset($item['cols']) && $item['cols'] !== '' && $item['cols'] !== null) {
            $column['cols'] = (int) $item['cols'];
        }

        // filter_kind: tipo de filtro fijo del buscador general (solo global_search_filters).
        // Se conserva únicamente si viene y es uno de los tres valores soportados.
        if (isset($item['filter_kind']) && in_array($item['filter_kind'], ['relation', 'numeric_comparison', 'numeric_presence'], true)) {
            $column['filter_kind'] = $item['filter_kind'];
        }

        // operator: operador de comparación del filtro fijo (solo global_search_filters).
        if (isset($item['operator']) && in_array($item['operator'], ['=', '>', '<', '>=', '<='], true)) {
            $column['operator'] = $item['operator'];
        }

        // default_value: valor por defecto del filtro fijo, casteado a string. Se descarta si viene vacío.
        if (isset($item['default_value']) && $item['default_value'] !== '') {
            $column['default_value'] = (string) $item['default_value'];
        }

        // keyword_mode: cómo se comporta esta prop frente a las palabras del criterio (solo global_search).
        if (isset($item['keyword_mode']) && in_array($item['keyword_mode'], ['todas', 'alguna'], true)) {
            $column['keyword_mode'] = $item['keyword_mode'];
        }

        return $column;
    }

    public function show($model_name, $preference_type)
    {
        $this->assert_preference_type($preference_type);

        // Id real del usuario autenticado (dueño o empleado) y del dueño de la cuenta.
        $real_user_id = $this->userId(false);
        $owner_id = $this->userId(true);

        $model = TableColumnPreference::where('user_id', $real_user_id)
            ->where('model_name', $model_name)
            ->where('preference_type', $preference_type)
            ->first();

        // El usuario (empleado) no tiene override propio: cae a la config compartida del dueño.
        if (!$model && $real_user_id != $owner_id) {
            $model = TableColumnPreference::where('user_id', $owner_id)
                ->where('model_name', $model_name)
                ->where('preference_type', $preference_type)
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
            // Ancho de columna (en unidades de grilla, 1..12) para formularios has_many
            'columns.*.cols' => 'nullable|integer|min:1|max:12',
            // Filtro fijo del buscador general (solo global_search_filters).
            'columns.*.filter_kind' => 'nullable|string|in:relation,numeric_comparison,numeric_presence',
            'columns.*.operator' => 'nullable|string|in:=,>,<,>=,<=',
            'columns.*.default_value' => 'nullable|string|max:120',
            // Modo de coincidencia por palabra clave de la prop (solo global_search).
            'columns.*.keyword_mode' => 'nullable|string|in:todas,alguna',
            // Modo de búsqueda a nivel de modelo (no por columna): { conector: 'or' | 'and' }.
            'settings' => 'nullable|array',
            'settings.conector' => 'nullable|string|in:or,and',
        ]);

        $columns = collect($request->columns)
            ->map(function ($item) {
                return $this->normalize_column_payload($item);
            })
            ->sortBy('order')
            ->values()
            ->all();

        // Valores a persistir: columns siempre se reemplaza entero.
        $values = [
            'columns' => $columns,
        ];

        // settings solo se toca si vino en el request: un PUT que solo actualiza columnas
        // no debe borrar el modo de búsqueda ya guardado.
        if ($request->has('settings') && is_array($request->settings)) {
            // Se queda únicamente con las claves conocidas (hoy: conector), descartando el resto.
            $values['settings'] = array_intersect_key($request->settings, array_flip(['conector']));
        }

        $model = TableColumnPreference::updateOrCreate(
            [
                // Cada usuario (dueño o empleado) guarda su propia fila, sin pisar la de otros.
                'user_id' => $this->userId(false),
                'model_name' => $model_name,
                'preference_type' => $preference_type,
            ],
            $values
        );

        return response()->json([
            'model' => $model,
        ], 200);
    }
}
