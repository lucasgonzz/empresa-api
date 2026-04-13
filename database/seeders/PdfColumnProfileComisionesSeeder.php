<?php

namespace Database\Seeders;

use App\Http\Controllers\Helpers\Seeders\PdfColumnProfileSeederHelper;
use App\Models\PdfColumnProfile;
use App\Models\SheetType;
use Illuminate\Database\Seeder;

class PdfColumnProfileComisionesSeeder extends Seeder
{
    /**
     * Crea perfil de remito orientado a mostrar comisiones en el footer.
     *
     * @return void
     */
    public function run()
    {
        $a4_sheet_type = SheetType::where('name', 'A4')->first();

        $model = [
            'model_name' => 'sale',
            'name' => 'Remito costos',
            'paper_width_mm' => 210,
            'printable_width_mm' => 210,
            'margin_mm' => 5,
            'is_afip_ticket' => 0,
            'show_comissions' => 1,
            'show_total_costs' => 1,
            'options' => [
                'Índice de fila',
                'Número de artículo',
                'Código de barras',
                'Nombre del artículo',
                'Cantidad',
                'Precio unitario',
                'Descuento porcentaje',
                'Subtotal línea',
            ],
        ];

        $profile = PdfColumnProfile::updateOrCreate(
            [
                'user_id' => config('app.USER_ID'),
                'model_name' => $model['model_name'],
                'name' => $model['name'],
            ],
            [
                'is_default' => false,
                'paper_width_mm' => $model['paper_width_mm'],
                'printable_width_mm' => $model['printable_width_mm'],
                'margin_mm' => $model['margin_mm'],
                'sheet_type_id' => $a4_sheet_type ? $a4_sheet_type->id : null,
                'is_afip_ticket' => $model['is_afip_ticket'],
                'show_totals_on_each_page' => false,
                'show_comissions' => (bool) $model['show_comissions'],
                'show_total_costs' => (bool) $model['show_total_costs'],
                'columns' => [],
            ]
        );

        PdfColumnProfileSeederHelper::assign_profile_options(
            $profile,
            $model['model_name'],
            $model['options']
        );
    }
}
