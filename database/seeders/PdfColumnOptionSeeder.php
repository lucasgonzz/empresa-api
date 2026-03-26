<?php

namespace Database\Seeders;

use App\Models\PdfColumnOption;
use App\Services\PdfColumnService;
use Illuminate\Database\Seeder;

class PdfColumnOptionSeeder extends Seeder
{
    /**
     * Crea/actualiza las opciones fijas de columnas PDF por model_name.
     */
    public function run()
    {
        $supported_models = [
            'sale',
        ];

        foreach ($supported_models as $model_name) {
            $defaults = PdfColumnService::default_options($model_name);

            foreach ($defaults as $index => $item) {
                /**
                 * Upsert por model_name + label + value_resolver (identificador estable del catálogo).
                 */
                PdfColumnOption::updateOrCreate(
                    [
                        'model_name' => $model_name,
                        'label' => $item['label'],
                        'value_resolver' => $item['value_resolver'],
                    ],
                    [
                        'name' => $item['name'],
                        'default_width' => $item['default_width'],
                        'allow_wrap_content' => $item['allow_wrap_content'],
                        'is_active' => true,
                        'order' => $index,
                    ]
                );
            }
        }
    }
}
