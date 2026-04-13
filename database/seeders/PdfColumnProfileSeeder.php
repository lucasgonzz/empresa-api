<?php

namespace Database\Seeders;

use App\Http\Controllers\Helpers\Seeders\PdfColumnProfileSeederHelper;
use App\Models\PdfColumnProfile;
use App\Models\SheetType;
use Illuminate\Database\Seeder;

class PdfColumnProfileSeeder extends Seeder
{
    /**
     * Crea perfiles PDF por defecto para todos los usuarios.
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
                'name'               => 'Remito',
                'paper_width_mm'     => 210,
                'printable_width_mm' => 210,
                'margin_mm'          => 5,
                'is_afip_ticket'     => 0,
                'options'            => [
                    'Índice de fila',
                    'Número de artículo',
                    'Código de barras',
                    'Nombre del artículo',
                    'Cantidad',
                    'Precio unitario',
                    'Descuento porcentaje',
                    'Subtotal línea',
                ],
            ],
            [
                'model_name'         => 'sale',
                'name'               => 'Factura comun',
                'paper_width_mm'     => 210,
                'printable_width_mm' => 210,
                'margin_mm'          => 5,
                'is_afip_ticket'     => 1,
                'options'            => [
                    'Índice de fila',
                    'Número de artículo',
                    'Código de barras',
                    'Nombre del artículo',
                    'Cantidad',
                    'Precio sin IVA',
                    'Importe IVA',
                    'Total con IVA',
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

            PdfColumnProfileSeederHelper::assign_profile_options(
                $profile,
                $model['model_name'],
                $model['options']
            );
        }
    }
}

