<?php

namespace Database\Seeders;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\Helpers\caja\CajaAperturaHelper;
use App\Models\Address;
use App\Models\Caja;
use App\Models\DefaultPaymentMethodCaja;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class CajaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        Log::info('Caja seeder');
        $models = [
            [
                'name'    => 'Caja Fuerte',
                'user_id'   => env('USER_ID')
            ],
            [
                'name'    => 'MercadoPago Transferencias',
                'user_id'   => env('USER_ID'),
                'default_payment_method_caja'   => [
                    // Id de CurrentAcountPaymentMethod "MercadoPago"
                    'payment_method_id'    => 6,
                    'payment_method_id'    => 4,
                ],  
            ],
            [
                'name'    => 'Santender',
                'saldo'   => 10000,
                'user_id'   => env('USER_ID'),
                'default_payment_method_caja'   => [
                    // Id de CurrentAcountPaymentMethod "Debito"
                    'payment_method_id'    => 2,
                    'payment_method_id'    => 5,
                ],  
            ],
        ];

        $this->addresses = Address::where('user_id', env('USER_ID'))
                                ->get();

        $employees = User::where('owner_id', env('USER_ID'))
                                    ->get();

        foreach ($this->addresses as $address) {

            foreach ($employees as $employee) {

                $models[] = [
                    // 'name'      => $address->street.' efectivo',
                    'name'      => 'Efectivo',
                    'employee_id'   => $employee->id,
                    'address_id'    => $address->id,
                    'user_id'   => env('USER_ID'),
                    'saldo'     => 10000,
                    'default_payment_method_caja' => [
                        'payment_method_id'     => 3,
                        'address_id'            => $address->id,
                    ],
                ];
                
                $models[] = [
                    // 'name'      => $address->street.' debito',
                    'employee_id'   => $employee->id,
                    'name'      => 'Debitos',
                    'address_id'    => $address->id,
                    'user_id'   => env('USER_ID'),
                    'saldo'     => 10000,
                ];
                
                $models[] = [
                    // 'name'      => $address->street.' credito',
                    'employee_id'   => $employee->id,
                    'name'      => 'Tarjetas',
                    'address_id'    => $address->id,
                    'user_id'   => env('USER_ID'),
                    'saldo'     => 10000,
                ];
                
                $models[] = [
                    // 'name'      => $address->street.' credito',
                    'employee_id'   => $employee->id,
                    'name'      => 'Transferencias',
                    'address_id'    => $address->id,
                    'user_id'   => env('USER_ID'),
                    'saldo'     => 10000,
                ];
            }
            
        }

        $num = 1;

        foreach ($models as $model) {
            Log::info('creando caja '.$model['name']);
            $model_to_create = [];
            $model_to_create['num'] = $num;
            $model_to_create['name'] = $model['name'];
            $model_to_create['address_id'] = $model['address_id'] ?? null;
            $model_to_create['user_id'] = $model['user_id'];

            if(isset($model['saldo'])) {

                $model_to_create['saldo'] = $model['saldo'];
            } 
            
            if(isset($model['employee_id'])) {

                $model_to_create['employee_id'] = $model['employee_id'];
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

            if (isset($default_payment_method_caja['address_id'])) {

                DefaultPaymentMethodCaja::create([

                    'current_acount_payment_method_id'  => $default_payment_method_caja['payment_method_id'],
                    'address_id'                        => $default_payment_method_caja['address_id'],
                    'caja_id'                           => $caja_creada->id,
                    'user_id'                           => $caja_creada->user_id,
                ]);

            } else {

                foreach ($this->addresses as $address) {
                    
                    DefaultPaymentMethodCaja::create([

                        'current_acount_payment_method_id'  => $default_payment_method_caja['payment_method_id'],
                        'address_id'                        => $address['id'],
                        'caja_id'                           => $caja_creada->id,
                        'user_id'                           => $caja_creada->user_id,
                    ]);

                }
            }

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
