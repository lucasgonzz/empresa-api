<?php

namespace Database\Seeders;

use App\Models\AfipTipoComprobante;
use Illuminate\Database\Seeder;

class AfipTipoComprobanteSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $models = [
            [
                'name'      => 'Factura A',
                'codigo'    => 1,
            ],
            [
                'name'      => 'Factura B',
                'codigo'    => 6,
            ],
            [
                'name'      => 'Factura C',
                'codigo'    => 11,
            ],
            [
                'name'      => 'Factura M',
                'codigo'    => 51,
            ],
            [
                'name'      => 'Factura de Credito Electronica MiPyMEs (FCE) A',
                'codigo'    => 201,
            ],
            [
                'name'      => 'Factura de credito Electronica MiPyMEs (FCE) B',
                'codigo'    => 206,
            ],
            [
                'name'      => 'Factura de credito Electronica MiPyMEs (FCE) C',
                'codigo'    => 211,
            ],
        ];

        foreach ($models as $model) {
            AfipTipoComprobante::create($model);
        }
    }
}
