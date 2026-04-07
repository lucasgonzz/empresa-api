<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Models\PdfColumnProfile;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PdfColumnProfileController extends Controller
{
    public function index(Request $request)
    {
        $models = PdfColumnProfile::where('user_id', $this->userId())->with(['sheet_type', 'pdf_column_options']);
        if ($request->query('model_name')) {
            $models->where('model_name', $request->query('model_name'));
        }

        $models = $models->orderBy('model_name')
            ->orderBy('name')
            ->get();

        return response()->json(['models' => $models], 200);
    }

    public function show($id)
    {
        PdfColumnProfile::where('user_id', $this->userId())
            ->where('id', $id)
            ->firstOrFail();

        return response()->json(['model' => $this->fullModel('PdfColumnProfile', $id)], 200);
    }

    public function store(Request $request)
    {
        $request->validate(
            $this->store_validation_rules($request),
            [],
            $this->validation_attribute_labels()
        );

        $this->assert_sum_of_column_widths_not_exceeds_paper($request, null);

        if ($request->is_default) {
            PdfColumnProfile::where('user_id', $this->userId())
                ->where('model_name', $request->model_name)
                ->update(['is_default' => false]);
        }

        $model = PdfColumnProfile::create([
            'user_id' => $this->userId(),
            'model_name' => $request->model_name,
            'name' => $request->name,
            'is_default' => (bool) $request->is_default,
            'paper_width_mm' => (int) $request->paper_width_mm,
            'printable_width_mm' => (int) $request->printable_width_mm,
            'margin_mm' => (int) $request->input('margin_mm', 5),
            'sheet_type_id' => $request->sheet_type_id,
            'is_afip_ticket' => (bool) $request->input('is_afip_ticket', false),
            'show_totals_on_each_page' => (bool) $request->input('show_totals_on_each_page', false),
            /**
             * Texto libre del pie de página; null si no se envía.
             */
            'footer_text' => $request->input('footer_text') ?: null,
            /**
             * Flag para mostrar/ocultar el total general en el pie del PDF.
             * Default true para compatibilidad con perfiles existentes.
             */
            'show_total_in_footer' => (bool) $request->input('show_total_in_footer', true),
        ]);

        GeneralHelper::attachModels(
            $model,
            'pdf_column_options',
            $request->pdf_column_options,
            ['visible', 'order', 'width', 'wrap_content']
        );

        return response()->json(['model' => $this->fullModel('PdfColumnProfile', $model->id)], 201);
    }

    public function update(Request $request, $id)
    {
        $model = PdfColumnProfile::where('user_id', $this->userId())
            ->where('id', $id)
            ->firstOrFail();

        $request->validate(
            $this->update_validation_rules($request, $model),
            [],
            $this->validation_attribute_labels()
        );

        $this->assert_printable_width_not_exceeds_paper($request, $model);

        $this->assert_sum_of_column_widths_not_exceeds_paper($request, $model);

        $new_model_name = $request->model_name ?: $model->model_name;

        if ($request->is_default) {
            PdfColumnProfile::where('user_id', $this->userId())
                ->where('model_name', $new_model_name)
                ->where('id', '!=', $model->id)
                ->update(['is_default' => false]);
        }

        $fillable = $request->only([
            'model_name',
            'name',
            'is_default',
            'paper_width_mm',
            'printable_width_mm',
            'margin_mm',
            'sheet_type_id',
            'is_afip_ticket',
            'show_totals_on_each_page',
            'footer_text',
            'show_total_in_footer',
        ]);
        $model->update($fillable);

        if ($request->has('pdf_column_options')) {
            GeneralHelper::attachModels(
                $model,
                'pdf_column_options',
                $request->pdf_column_options,
                ['visible', 'order', 'width', 'wrap_content']
            );
        }

        return response()->json(['model' => $this->fullModel('PdfColumnProfile', $model->id)], 200);
    }

    public function destroy($id)
    {
        $model = PdfColumnProfile::where('user_id', $this->userId())
            ->where('id', $id)
            ->firstOrFail();
        $model->delete();

        return response()->json(null, 204);
    }

    /**
     * Etiquetas en español para sustituir :attribute en mensajes de validación.
     *
     * @return array<string, string>
     */
    protected function validation_attribute_labels(): array
    {
        return [
            'model_name' => 'modelo',
            'name' => 'nombre',
            'is_default' => 'perfil por defecto',
            'paper_width_mm' => 'ancho de hoja (mm)',
            'printable_width_mm' => 'ancho imprimible (mm)',
            'margin_mm' => 'margen lateral (mm)',
            'sheet_type_id' => 'tipo de hoja',
            'is_afip_ticket' => 'perfil fiscal AFIP',
            'show_totals_on_each_page' => 'mostrar totales en cada hoja',
            'footer_text' => 'pie de página',
            'show_total_in_footer' => 'mostrar total en el pie',
            'pdf_column_options' => 'opciones de columnas',
            'pdf_column_options.*.id' => 'opción de columna',
            'pdf_column_options.*.pivot.visible' => 'visible',
            'pdf_column_options.*.pivot.order' => 'orden',
            'pdf_column_options.*.pivot.width' => 'ancho',
            'pdf_column_options.*.pivot.wrap_content' => 'salto de línea',
        ];
    }

    /**
     * Reglas para crear un perfil: coincide con columnas de `pdf_column_profiles` y pivots del attach.
     *
     * @param \Illuminate\Http\Request $request Payload entrante (debe incluir `model_name` para validar ids de opciones).
     * @return array<string, mixed>
     */
    protected function store_validation_rules(Request $request): array
    {
        $model_name = $request->input('model_name');

        return [
            'model_name' => ['required', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:120'],
            'is_default' => ['sometimes', 'boolean'],
            'paper_width_mm' => ['required', 'integer', 'min:1', 'max:50000'],
            'printable_width_mm' => ['required', 'integer', 'min:1', 'max:50000', 'lte:paper_width_mm'],
            'margin_mm' => ['sometimes', 'integer', 'min:0', 'max:5000'],
            'sheet_type_id' => ['nullable', 'integer', Rule::exists('sheet_types', 'id')],
            'is_afip_ticket' => ['sometimes', 'boolean'],
            'show_totals_on_each_page' => ['sometimes', 'boolean'],
            'footer_text' => ['nullable', 'string', 'max:2000'],
            'show_total_in_footer' => ['sometimes', 'boolean'],
            'pdf_column_options' => ['required', 'array', 'min:1'],
            'pdf_column_options.*.id' => [
                'required',
                'integer',
                Rule::exists('pdf_column_options', 'id')->where('model_name', $model_name),
            ],
            'pdf_column_options.*.pivot' => ['sometimes', 'array'],
            'pdf_column_options.*.pivot.visible' => ['sometimes', 'boolean'],
            'pdf_column_options.*.pivot.order' => ['sometimes', 'integer', 'min:0'],
            'pdf_column_options.*.pivot.width' => ['sometimes', 'integer', 'min:0', 'max:5000'],
            'pdf_column_options.*.pivot.wrap_content' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Reglas para actualizar: campos opcionales salvo que se envíen; `pdf_column_options` solo si viene en el body.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Models\PdfColumnProfile $model Perfil actual (resuelve `model_name` si no se reenvía).
     * @return array<string, mixed>
     */
    protected function update_validation_rules(Request $request, PdfColumnProfile $model): array
    {
        $resolved_model_name = $request->filled('model_name')
            ? $request->input('model_name')
            : $model->model_name;

        return [
            'model_name' => ['sometimes', 'string', 'max:60'],
            'name' => ['sometimes', 'string', 'max:120'],
            'is_default' => ['sometimes', 'boolean'],
            'paper_width_mm' => ['sometimes', 'integer', 'min:1', 'max:50000'],
            'printable_width_mm' => ['sometimes', 'integer', 'min:1', 'max:50000'],
            'margin_mm' => ['sometimes', 'integer', 'min:0', 'max:5000'],
            'sheet_type_id' => ['sometimes', 'nullable', 'integer', Rule::exists('sheet_types', 'id')],
            'is_afip_ticket' => ['sometimes', 'boolean'],
            'show_totals_on_each_page' => ['sometimes', 'boolean'],
            'footer_text' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'show_total_in_footer' => ['sometimes', 'boolean'],
            'pdf_column_options' => ['sometimes', 'array'],
            'pdf_column_options.*.id' => [
                'required_with:pdf_column_options',
                'integer',
                Rule::exists('pdf_column_options', 'id')->where('model_name', $resolved_model_name),
            ],
            'pdf_column_options.*.pivot' => ['sometimes', 'array'],
            'pdf_column_options.*.pivot.visible' => ['sometimes', 'boolean'],
            'pdf_column_options.*.pivot.order' => ['sometimes', 'integer', 'min:0'],
            'pdf_column_options.*.pivot.width' => ['sometimes', 'integer', 'min:0', 'max:5000'],
            'pdf_column_options.*.pivot.wrap_content' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Garantiza que el ancho imprimible no supere el de papel cuando el update envía solo uno de los dos.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Models\PdfColumnProfile $model Valores previos si faltan en el request.
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    protected function assert_printable_width_not_exceeds_paper(Request $request, PdfColumnProfile $model): void
    {
        $paper = (int) ($request->input('paper_width_mm') ?? $model->paper_width_mm);
        $printable = (int) ($request->input('printable_width_mm') ?? $model->printable_width_mm);
        if ($printable > $paper) {
            throw ValidationException::withMessages([
                'printable_width_mm' => ['El ancho imprimible no puede superar el ancho de hoja.'],
            ]);
        }
    }

    /**
     * Indica si el pivot del request cuenta como columna visible/activa para sumar ancho.
     * Si no viene `visible`, se asume activa (mismo criterio que el default en BD).
     *
     * @param array $pivot Fragmento `pivot` del JSON del cliente.
     * @return bool
     */
    protected function is_request_pivot_visible_for_width_sum(array $pivot): bool
    {
        if (! array_key_exists('visible', $pivot)) {
            return true;
        }
        $visible = $pivot['visible'];
        if ($visible === true || $visible === 1 || $visible === '1') {
            return true;
        }
        if ($visible === false || $visible === 0 || $visible === '0' || $visible === '') {
            return false;
        }

        return (bool) $visible;
    }

    /**
     * Indica si el pivot persistido está visible (columna activa).
     * Trata 0 / "0" / false como no visible (evita el fallo de `(bool) "0"` en PHP).
     *
     * @param \Illuminate\Database\Eloquent\Relations\Pivot|object $pivot Pivot de la relación.
     * @return bool
     */
    protected function is_attached_pivot_visible_for_width_sum($pivot): bool
    {
        if (! isset($pivot->visible)) {
            return true;
        }
        $visible = $pivot->visible;
        if ($visible === true || $visible === 1 || $visible === '1') {
            return true;
        }
        if ($visible === false || $visible === 0 || $visible === '0' || $visible === '') {
            return false;
        }

        return (bool) $visible;
    }

    /**
     * Suma los anchos (`pivot.width`) solo de columnas con `pivot.visible` activo.
     *
     * @param mixed $pdf_column_options Lista tal como llega del cliente (objetos con `pivot`).
     * @return int Suma en mm (filas ocultas o sin pivot activo no suman).
     */
    protected function sum_pivot_widths_from_request_options($pdf_column_options): int
    {
        if (! is_array($pdf_column_options)) {
            return 0;
        }
        $sum = 0;
        foreach ($pdf_column_options as $row) {
            if (! is_array($row)) {
                continue;
            }
            $pivot = (isset($row['pivot']) && is_array($row['pivot'])) ? $row['pivot'] : [];
            if (! $this->is_request_pivot_visible_for_width_sum($pivot)) {
                continue;
            }
            $sum += (int) ($pivot['width'] ?? 0);
        }

        return $sum;
    }

    /**
     * Suma anchos en pivots ya guardados, solo donde `visible` está activo.
     *
     * @param \App\Models\PdfColumnProfile $model Debe tener cargada la relación `pdf_column_options`.
     * @return int Suma en mm.
     */
    protected function sum_pivot_widths_from_attached_options(PdfColumnProfile $model): int
    {
        $sum = 0;
        foreach ($model->pdf_column_options as $option) {
            if (! $this->is_attached_pivot_visible_for_width_sum($option->pivot)) {
                continue;
            }
            $sum += (int) ($option->pivot->width ?? 0);
        }

        return $sum;
    }

    /**
     * Calcula ancho útil disponible para columnas visibles en mm.
     *
     * @param int $printable_width_mm Ancho imprimible total.
     * @param int $margin_mm Margen por lado (izquierdo y derecho).
     * @return int
     */
    protected function get_available_width_mm_for_columns(int $printable_width_mm, int $margin_mm): int
    {
        /**
         * Se descuentan ambos márgenes laterales para evitar desbordes.
         */
        $available_width_mm = $printable_width_mm - ($margin_mm * 2);

        return $available_width_mm > 0 ? $available_width_mm : 0;
    }

    /**
     * Impide que la suma de anchos de columnas visibles supere el ancho útil (imprimible - márgenes).
     *
     * - En store: usa siempre el body del request.
     * - En update con `pdf_column_options`: suma lo enviado y compara contra ancho imprimible/márgenes efectivos.
     * - En update solo con `printable_width_mm` o `margin_mm`: revalida la suma de pivots ya guardados.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Models\PdfColumnProfile|null $model Null en store; perfil actual en update.
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    protected function assert_sum_of_column_widths_not_exceeds_paper(Request $request, ?PdfColumnProfile $model = null): void
    {
        $printable_width_mm = (int) ($request->input('printable_width_mm') ?? ($model !== null ? $model->printable_width_mm : 0));
        $margin_mm = (int) ($request->input('margin_mm') ?? ($model !== null ? $model->margin_mm : 5));
        $available_width_mm = $this->get_available_width_mm_for_columns($printable_width_mm, $margin_mm);
        if ($available_width_mm <= 0) {
            return;
        }

        $sum_widths = null;

        if ($request->has('pdf_column_options')) {
            $sum_widths = $this->sum_pivot_widths_from_request_options($request->input('pdf_column_options'));
        } elseif ($model !== null && ($request->has('printable_width_mm') || $request->has('margin_mm'))) {
            $model->loadMissing('pdf_column_options');
            $sum_widths = $this->sum_pivot_widths_from_attached_options($model);
        }

        if ($sum_widths === null) {
            return;
        }

        if ($sum_widths > $available_width_mm) {
            throw ValidationException::withMessages([
                'pdf_column_options' => [
                    sprintf(
                        'La suma de los anchos visibles (%d mm) no puede superar el ancho disponible (%d mm) luego de descontar márgenes.',
                        $sum_widths,
                        $available_width_mm
                    ),
                ],
            ]);
        }
    }
}
