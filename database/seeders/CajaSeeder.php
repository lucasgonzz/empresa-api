<?php

namespace Database\Seeders;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\Helpers\caja\CajaAperturaHelper;
use App\Models\Address;
use App\Models\Caja;
use App\Models\DefaultPaymentMethodCaja;
use Illuminate\Database\Seeder;

class CajaSeeder extends Seeder
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
                'name'    => 'Caja principal Efectivo',
                'user_id'   => env('USER_ID')
            ],
            [
                'name'    => 'MercadoPago Transferencias',
                'user_id'   => env('USER_ID'),
                'current_acount_payment_methods'    => [
                    [
                        'id'    => 6,
                    ]
                ],
            ],
            [
                'name'    => 'MercadoPago QR',
                'user_id'   => env('USER_ID'),
                'current_acount_payment_methods'    => [
                    [
                        'id'    => 6,
                    ]
                ],
            ],
            [
                'name'    => 'BNA',
                'saldo'   => 10000,
                'user_id'   => env('USER_ID'),
                'current_acount_payment_methods'    => [
                    [
                        'id'    => 5,
                    ]
                ],
            ],
        ];

        $addresses = Address::where('user_id', env('USER_ID'))
                                ->get();

        foreach ($addresses as $address) {
            
            $models[] = [
                'name'      => $address->street.' efectivo',
                'user_id'   => env('USER_ID'),
                'default_payment_method_caja' => [
                    'payment_method_id'     => 3,
                    'address_id'            => $address->id,
                ],
                'current_acount_payment_methods'    => [
                    [
                        'id'    => 3,
                    ]
                ],
            ];
            
            $models[] = [
                'name'      => $address->street.' credito',
                'user_id'   => env('USER_ID'),
                'default_payment_method_caja' => [
                    'payment_method_id'     => 5,
                    'address_id'            => $address->id,
                ],
                'current_acount_payment_methods'    => [
                    [
                        'id'    => 5,
                    ]
                ],
            ];
        }

        $num = 1;

        foreach ($models as $model) {
            $model_to_create = [];
            $model_to_create['num'] = $num;
            $model_to_create['name'] = $model['name'];
            $model_to_create['user_id'] = $model['user_id'];

            if(isset($model['saldo'])) {

                $model_to_create['saldo'] = $model['saldo'];
            } 
            
            $caja = Caja::create($model_to_create);
            $num++;

            $this->set_metodos_de_pago_disponibles($caja, $model);

            $this->set_cajas_por_defecto($caja, $model);

            $this->abrir_caja($caja);

        }
    }

    function abrir_caja($caja) {

        $helper = new CajaAperturaHelper($caja->id);
        $helper->abrir_caja();
    }

    function set_metodos_de_pago_disponibles($caja, $seeder_model) {
        if (isset($seeder_model['current_acount_payment_methods'])) {

            GeneralHelper::attachModels($caja, 'current_acount_payment_methods', $seeder_model['current_acount_payment_methods']);
        }
    }

    function set_cajas_por_defecto($caja_creada, $seeder_model) {

        if (isset($seeder_model['default_payment_method_caja'])) {

            $default_payment_method_caja = $seeder_model['default_payment_method_caja'];

            DefaultPaymentMethodCaja::create([

                'current_acount_payment_method_id'  => $default_payment_method_caja['payment_method_id'],
                'address_id'                        => $default_payment_method_caja['address_id'],
                'caja_id'                           => $caja_creada->id,
                'user_id'                           => $caja_creada->user_id,
            ]);
        }
    }

    function get_payment_methods() {
        return [
            [
                'id'    => 1,
            ],
            [
                'id'    => 2,
            ],
            [
                'id'    => 3,
            ],
            [
                'id'    => 4,
            ],
            [
                'id'    => 5,
            ],
            [
                'id'    => 6,
            ],
        ];
    }
}
