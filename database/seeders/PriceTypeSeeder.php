<?php

namespace Database\Seeders;

use App\Models\PriceType;
use App\Models\PriceTypeSurchage;
use App\Models\User;
use Illuminate\Database\Seeder;

class PriceTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $for_user = config('app.FOR_USER');

        if ($for_user == 'pack_descartables') {
            $this->pack_descartables();
        } else if ($for_user == 'colman' || $for_user == 'ferretodo') {
            $this->colman();
        } else if ($for_user == 'golo_norte') {
            $this->golo_norte();
        } else if ($for_user == 'electro_lacarra') {
            $this->electro_lacarra();
        } else if ($for_user == 'mza_group') {
            $this->mza_group();
        } else if ($for_user == 'bad_girls') {
            $this->bad_girls();
        } else if ($for_user == 'leudinox') {
            $this->leudinox();
        } else {
            $this->golo_norte();
        }

    }


    function leudinox() {

        $models = [
            [
                'num'           => 1,
                'name'          => 'Mercado Libre',
                'position'      => 1,
                'percentage'    => 15,
                'setear_precio_final'   => 1,
                'se_usa_en_ml' => 1,
                'price_type_surchages'  => [
                    [
                        'name'  => 'Comision Meli',
                        'percentage'  => 0.2,
                        'position'    => 2,
                    ],
                    [
                        'name'  => 'Costo impositivo',
                        'percentage'  => 0.3,
                        'position'    => 3,
                    ],
                ],
            ],
            [
                'num'           => 3,
                'name'          => 'Venta comun',
                'position'      => 3,
                'percentage'    => 70,
                'se_usa_en_ml' => 0,
                'setear_precio_final'   => 0,
            ],
            
        ];


        foreach ($models as $model) {

            $price_type = PriceType::create([
                'num'                           => $model['num'],
                'name'                          => $model['name'],
                'position'                      => $model['position'],
                'percentage'                    => $model['percentage'],
                'setear_precio_final'           => $model['setear_precio_final'],
                'se_usa_en_ml'         => $model['se_usa_en_ml'],
                'user_id'                       => config('app.USER_ID'),
            ]);



            if (isset($model['price_type_surchages'])) {

                foreach ($model['price_type_surchages'] as $price_type_surchage) {

                    PriceTypeSurchage::create([
                        'name'                   => $price_type_surchage['name'],
                        'percentage'            => isset($price_type_surchage['percentage']) ? $price_type_surchage['percentage'] : null,
                        'amount'                => isset($price_type_surchage['amount']) ? $price_type_surchage['amount'] : null,
                        'position'              => $price_type_surchage['position'],
                        'price_type_id'         => $price_type->id,
                    ]);

                }
            }
        }
    }

    function mza_group() {

        $models = [
            [
                'num'           => 1,
                'name'          => 'Tienda Nube Contado',
                'position'      => 1,
                'percentage'    => 15,
                'setear_precio_final'   => 1,
                'se_usa_en_tienda_nube' => 1,
                'price_type_surchages'  => [
                    [
                        'name'  => 'Descuento pago efectivo',
                        'percentage'  => 15,
                        'position'    => 1,
                    ],
                    [
                        'name'  => 'Comision T.N.',
                        'percentage'  => 0.1,
                        'position'    => 2,
                    ],
                    [
                        'name'  => 'Costo impositivo',
                        'percentage'  => 0.2,
                        'position'    => 3,
                    ],
                ],
            ],
            [
                'num'           => 2,
                'name'          => 'Tienda Nube 3 cuotas',
                'position'      => 2,
                'percentage'    => 15,
                'setear_precio_final'   => 1,
                'se_usa_en_tienda_nube' => 0,
                'price_type_surchages'  => [
                    [
                        'name'  => 'Comision T.N.',
                        'percentage'  => 0.1,
                        'position'    => 1,
                    ],
                    [
                        'name'  => 'Costo impositivo',
                        'percentage'  => 3.5,
                        'position'    => 2,
                    ],
                    [
                        'name'  => 'Comision plataforma de pago',
                        'percentage'  => 4.1,
                        'position'    => 3,
                    ],
                    [
                        'name'  => 'Costo absovido 3 cuotas',
                        'percentage'  => 15.55,
                        'position'    => 4,
                    ],
                    [
                        'name'  => 'Comision Fija',
                        'amount'  => 100,
                        'position'    => 5,
                    ],
                ],
            ],
            [
                'num'           => 3,
                'name'          => 'Venta credito',
                'position'      => 3,
                'percentage'    => 70,
                'se_usa_en_tienda_nube' => 0,
                'setear_precio_final'   => 0,
            ],
            
        ];


        foreach ($models as $model) {

            $price_type = PriceType::create([
                'num'                           => $model['num'],
                'name'                          => $model['name'],
                'position'                      => $model['position'],
                'percentage'                    => $model['percentage'],
                'setear_precio_final'           => $model['setear_precio_final'],
                'se_usa_en_tienda_nube'         => $model['se_usa_en_tienda_nube'],
                'user_id'                       => config('app.USER_ID'),
            ]);



            if (isset($model['price_type_surchages'])) {

                foreach ($model['price_type_surchages'] as $price_type_surchage) {

                    PriceTypeSurchage::create([
                        'name'                   => $price_type_surchage['name'],
                        'percentage'            => isset($price_type_surchage['percentage']) ? $price_type_surchage['percentage'] : null,
                        'amount'                => isset($price_type_surchage['amount']) ? $price_type_surchage['amount'] : null,
                        'position'              => $price_type_surchage['position'],
                        'price_type_id'         => $price_type->id,
                    ]);

                }
            }
        }
    }

    function bad_girls() {

        $models = [
            [
                'num'           => 1,
                'name'          => '12 pares',
                'position'      => 1,
                'percentage'    => 10,
                'setear_precio_final'   => 1,
                'user_id'       => config('app.USER_ID'),
            ],
            [
                'num'           => 1,
                'name'          => '6 pares',
                'position'      => 1,
                'percentage'    => 20,
                'setear_precio_final'   => 1,
                'incluir_en_lista_de_precios_de_excel'      => 0,
                'user_id'       => config('app.USER_ID'),
            ],
            [
                'num'           => 2,
                'name'          => 'Unidad',
                'percentage'    => 30,
                'position'      => 2,
                'setear_precio_final'   => 1,
                'user_id'       => config('app.USER_ID'),
            ],
            
        ];
        foreach ($models as $model) {

            PriceType::create($model);
        }
    }

    function electro_lacarra() {

        $models = [
            [
                'num'           => 1,
                'name'          => 'Mayorista 1',
                'position'      => 1,
                'percentage'    => 15,
                'incluir_en_lista_de_precios_de_excel'      => 0,
                'user_id'       => config('app.USER_ID'),
            ],
            [
                'num'           => 1,
                'name'          => 'Mayorista 2',
                'position'      => 1,
                'percentage'    => 15,
                'incluir_en_lista_de_precios_de_excel'      => 0,
                'user_id'       => config('app.USER_ID'),
            ],
            [
                'num'           => 2,
                'name'          => 'Minorista',
                'percentage'    => 50,
                'position'      => 2,
                'incluir_en_lista_de_precios_de_excel'    => 1,
                'user_id'       => config('app.USER_ID'),
            ],
            
        ];
        foreach ($models as $model) {

            PriceType::create($model);
        }
    }

    function golo_norte() {

        $models = [
            [
                'num'           => 1,
                'name'          => 'Distribuidor',
                'percentage'    => 5,
                'position'      => 1,
                'ocultar_al_publico'    => 1,
                'user_id'       => config('app.USER_ID'),
            ],
            [
                'num'           => 2,
                'name'          => 'Mayorista',
                'percentage'    => 10,
                'position'      => 2,
                'user_id'       => config('app.USER_ID'),
            ],
            [
                'num'           => 3,
                'name'          => 'Minorista',
                'percentage'    => 15,
                'position'      => 3,
                'user_id'       => config('app.USER_ID'),
            ],
            
        ];
        foreach ($models as $model) {

            PriceType::create($model);
        }
    }

    function pack_descartables() {

        $models = [
            [
                'num'           => 1,
                'name'          => 'Mayorista',
                'percentage'    => 20,
                'position'      => 1,
                'user_id'       => config('app.USER_ID'),
            ],
            [
                'num'           => 2,
                'name'          => 'Minorista',
                'percentage'    => 40,
                'position'      => 2,
                'user_id'       => config('app.USER_ID'),
            ],
        ];
        foreach ($models as $model) {

            PriceType::create($model);
        }
    }

    function colman() {

        $models = [
            [
                'num'           => 1,
                'name'          => 'Mayorista',
                'percentage'    => 100,
                'position'      => 1,
                'user_id'       => config('app.USER_ID'),
            ],
            [
                'num'           => 2,
                'name'          => 'Comercio',
                'percentage'    => 100,
                'position'      => 2,
                'user_id'       => config('app.USER_ID'),
            ],
            [
                'num'           => 3,
                'name'          => 'Consumidor final',
                'percentage'    => 100,
                'position'      => 3,
                'user_id'       => config('app.USER_ID'),
            ],
        ];
        foreach ($models as $model) {

            PriceType::create($model);
        }
    }
}
