<?php

namespace Database\Seeders;

use App\Models\PdfColumnOption;
use App\Models\PdfColumnProfile;
use App\Models\SheetType;
use Illuminate\Database\Seeder;

class PdfColumnSinPreciosSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        /**
         * Tipo de hoja A4 para asociar en perfiles legacy.
         */
        $a4_sheet_type = SheetType::where('name', 'A4')->first();

        /**
         * Configuración editable de opciones incluidas por perfil.
         */
        $models = [
            [
                'model_name'         => 'sale',
                'name'               => 'Sin Precios',
                'paper_width_mm'     => 210,
                'printable_width_mm' => 210,
                'margin_mm'          => 5,
                'is_afip_ticket'     => 0,
                'options'            => [
                    'Índice de fila',
                    'Número de artículo',
                    'Código de barras',
                    [
                        'name'  => 'Nombre del artículo',
                        'width' => 132,
                    ],
                    'Cantidad',
                ],
            ],
        ];

        foreach ($models as $model) {

            $profile = PdfColumnProfile::updateOrCreate(
                [
                    'user_id'    => config('app.USER_ID'),
                    'model_name' => $model['model_name'],
                    'name'       => $model['name'],
                ],
                [
                    'is_default'               => false,
                    'paper_width_mm'           => $model['paper_width_mm'],
                    'printable_width_mm'       => $model['printable_width_mm'],
                    'margin_mm'                => $model['margin_mm'],
                    'sheet_type_id'            => $a4_sheet_type ? $a4_sheet_type->id : null,
                    'is_afip_ticket'           => $model['is_afip_ticket'],
                    'show_totals_on_each_page' => false,
                    'columns'                  => [],
                ]
            );

            /**
             * Opciones visibles por defecto para el perfil actual.
             */
            /**
             * Definición editable de opciones visibles.
             * Permite dos formatos:
             * - string: 'Nombre de option' (usa width default)
             * - array: ['name' => 'Nombre de option', 'width' => 70] (sobrescribe width)
             */
            $visible_options_definition = $model['options'];

            /**
             * Mapa de visibilidad por name con width personalizado opcional.
             * La clave es el `name` de `PdfColumnOption` y el valor es:
             * - int: width personalizado
             * - null: visible sin override de width
             */
            $visible_option_width_by_name = [];
            foreach ($visible_options_definition as $visible_option_definition) {
                /**
                 * Caso 1: se define solo por nombre (string).
                 */
                if (is_string($visible_option_definition)) {
                    $visible_option_width_by_name[$visible_option_definition] = null;
                    continue;
                }

                /**
                 * Caso 2: se define como array con name y width opcional.
                 */
                if (is_array($visible_option_definition) && isset($visible_option_definition['name'])) {
                    $visible_option_name = $visible_option_definition['name'];

                    /**
                     * Width personalizado opcional para el pivot (si no viene, queda null).
                     */
                    $visible_option_width_by_name[$visible_option_name] = isset($visible_option_definition['width'])
                        ? (int) $visible_option_definition['width']
                        : null;
                }
            }

            /**
             * Se cargan TODAS las opciones del modelo para que el usuario pueda
             * activar luego desde Vue las que inicialmente queden ocultas.
             */
            $options = PdfColumnOption::where('model_name', $model['model_name'])
                                    ->orderBy('order')
                                    ->get();

            $sync = [];
            $columns = [];
            foreach ($options as $index => $option) {
                /**
                 * Solo quedan visibles por defecto los names definidos en `options`.
                 */
                $is_visible = array_key_exists($option->name, $visible_option_width_by_name);

                /**
                 * Width final para el pivot/columns:
                 * - si la option está visible y tiene width personalizado, se usa ese
                 * - si no, se usa el default_width de `PdfColumnOption`
                 */
                $option_width = (int) $option->default_width;
                if ($is_visible && $visible_option_width_by_name[$option->name] !== null) {
                    $option_width = (int) $visible_option_width_by_name[$option->name];
                }

                $sync[$option->id] = [
                    'visible'      => $is_visible,
                    'order'        => $index,
                    'width'        => $option_width,
                    'wrap_content' => false,
                ];

                $columns[] = [
                    'option_id'      => $option->id,
                    'name'           => $option->name,
                    'label'          => $option->label,
                    'value_resolver' => $option->value_resolver,
                    'visible'        => $is_visible,
                    'order'          => $index,
                    'width'          => $option_width,
                    'wrap_content'   => false,
                ];
            }

            $profile->pdf_column_options()->sync($sync);
            $profile->columns = $columns;
            $profile->save();
        }
    }
}
