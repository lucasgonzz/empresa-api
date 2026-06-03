<?php

namespace Database\Seeders;

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
            'article',
        ];

        foreach ($supported_models as $model_name) {
            PdfColumnService::sync_catalog_options($model_name);
        }
    }
}
