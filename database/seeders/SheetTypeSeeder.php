<?php

namespace Database\Seeders;

use App\Models\SheetType;
use Illuminate\Database\Seeder;

class SheetTypeSeeder extends Seeder
{
    /**
     * Carga catálogo base de tipos de hoja para configuración de PDFs.
     *
     * @return void
     */
    public function run()
    {
        /**
         * Tipos iniciales reutilizables en perfiles de impresión.
         */
        $models = [
            [
                'name'      => 'A4',
                'width'     => 297,
                'height'    => 210,
            ],
            [
                'name'      => 'Ticket 55 mm',
                'width'     => 55,
                'height'    => null,
            ],
            [
                'name'      => 'Ticket 80 mm',
                'width'     => 80,
                'height'    => null,
            ],
        ];

        foreach ($models as $model) {
            SheetType::updateOrCreate(
                ['name' => $model['name']],
                [
                    'width' => $model['width'],
                    'height' => $model['height'],
                ]
            );
        }
    }
}
