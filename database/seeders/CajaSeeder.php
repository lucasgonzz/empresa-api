<?php

namespace Database\Seeders;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\Helpers\caja\CajaAperturaHelper;
use App\Models\Address;
use App\Models\Caja;
use App\Models\DefaultPaymentMethodCaja;
use App\Models\Moneda;
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
    public function run() {

        if (
            env('FOR_USER') == 'racing_carts'
        ) {

            $this->una_caja_por_cada_empleado_cada_sucursal_y_cada_moneda();
        } else {

            $this->una_caja_para_cada_direccion_y_por_cada_metodo_de_pago();
        }

    }

    /**
     * Cada sucursal maneja su propia caja de EFECTIVO; el resto (Mercado Pago, Banco, Tarjetas,
     * Cheques) son compartidas entre las 4 -- es la estructura real de un comercio con locales:
     * la plata física está en el mostrador de cada local, pero la cuenta de Mercado Pago y la
     * cuenta bancaria son una sola de la empresa (pedido de Lucas, 3/8/2026, grupo 321).
     *
     * Antes armaba el producto cartesiano completo (sucursal x método de pago = 24 cajas): esto
     * deja 9 -- 4 de efectivo (una por sucursal) + Mercado Pago + Banco + Tarjetas + Cheques +
     * Caja Fuerte.
     */
    function una_caja_para_cada_direccion_y_por_cada_metodo_de_pago() {

        $this->addresses = Address::where('user_id', config('app.USER_ID'))
                                ->get();

        $models = [
            [
                'name'    => 'Caja Fuerte',
                'user_id'   => config('app.USER_ID')
            ],
        ];

        if ($this->addresses->isEmpty()) {

            // Sin ninguna Address sembrada (antes de que el prompt 01 se haya aplicado, o un
            // FOR_USER que no siembra sucursales), un foreach sobre $this->addresses no crearía
            // ninguna caja de efectivo -- peor que la alternativa. Una única caja compartida,
            // default del método 3 sin address_id (igual que las demás compartidas).
            $models[] = [
                'name'          => 'Efectivo',
                'address_id'    => null,
                'user_id'       => config('app.USER_ID'),
                'default_payment_method_caja' => [
                    'payment_method_id' => 3,
                ],
            ];
        } else {

            foreach ($this->addresses as $address) {

                // El nombre lleva la calle de la sucursal para poder distinguirlas en el
                // desglose por caja del Flujo de Caja. Default SOLO de su propia sucursal.
                $models[] = [
                    'name'          => 'Efectivo '.$address->street,
                    'address_id'    => $address->id,
                    'user_id'       => config('app.USER_ID'),
                    'default_payment_method_caja' => [
                        'payment_method_id' => 3,
                        'address_id'        => $address->id,
                    ],
                ];
            }
        }

        // Compartidas: sin address_id, default para las 4 sucursales. set_cajas_por_defecto()
        // resuelve esto solo -- cuando el modelo no trae 'address_id' dentro de
        // 'default_payment_method_caja', itera $this->addresses y crea una fila por sucursal
        // apuntando todas al mismo caja_id.
        $models[] = [
            'name'          => 'Mercado Pago',
            'address_id'    => null,
            'user_id'       => config('app.USER_ID'),
            'default_payment_method_caja' => [
                'payment_method_id' => 6,
            ],
        ];

        $models[] = [
            'name'          => 'Banco',
            'address_id'    => null,
            'user_id'       => config('app.USER_ID'),
            'default_payment_method_caja' => [
                'payment_method_id' => 4,
            ],
        ];

        // Débito y crédito entran a la misma cuenta en la liquidación de tarjetas de un
        // comercio real: una sola caja, default de los dos métodos (lista, no un id suelto).
        $models[] = [
            'name'          => 'Tarjetas',
            'address_id'    => null,
            'user_id'       => config('app.USER_ID'),
            'default_payment_method_caja' => [
                'payment_method_id' => [2, 5],
            ],
        ];

        $models[] = [
            'name'          => 'Cheques',
            'address_id'    => null,
            'user_id'       => config('app.USER_ID'),
            'default_payment_method_caja' => [
                'payment_method_id' => 1,
            ],
        ];

        $num = 1;

        foreach ($models as $model) {
            Log::info('creando caja '.$model['name']);
            $model_to_create = [];
            $model_to_create['num'] = $num;
            $model_to_create['name'] = $model['name'];
            $model_to_create['address_id'] = $model['address_id'] ?? null;
            $model_to_create['user_id'] = $model['user_id'];

            // Arranca en 0, no en 10000: el objetivo de la semilla es que el saldo de una caja
            // sea exactamente la suma de los movimientos que el sembrador genera. Un saldo
            // inicial arbitrario mete ruido que no corresponde a ninguna operación real y hace
            // que esa verificación deje de cerrar. No "arreglar" esto sumando un valor de nuevo.
            $model_to_create['saldo'] = 0;

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

    public function una_caja_por_cada_empleado_cada_sucursal_y_cada_moneda()
    {
        $models = [
            [
                'name'    => 'Caja Fuerte',
                'moneda_id' => 1,
                'user_id'   => config('app.USER_ID')
            ],
        ];

        $employees = User::where('owner_id', config('app.USER_ID'))
                                    ->get();

        $monedas = Moneda::all();

        foreach ($monedas as $moneda) {
            // code...
            foreach ($employees as $employee) {

                $models[] = [
                    'moneda_id' => $moneda->id,
                    'name'      => 'Efectivo',
                    // 'employee_id'   => $employee->id,
                    'address_id'    => $employee->address_id,
                    'user_id'   => config('app.USER_ID'),
                    'saldo'     => 10000,
                    'default_payment_method_caja' => [
                        'payment_method_id'     => 3,
                        'address_id'            => $employee->address_id,
                    ],
                    'user_con_acceso'   => [
                        $employee->id,
                    ],
                ];
                
                $models[] = [
                    'moneda_id' => $moneda->id,
                    // 'employee_id'   => $employee->id,
                    'name'      => 'Debitos',
                    'address_id'    => $employee->address_id,
                    'user_id'   => config('app.USER_ID'),
                    'saldo'     => 10000,
                    'default_payment_method_caja' => [
                        'payment_method_id'     => 2,
                        'address_id'            => $employee->address_id,
                    ],
                    'user_con_acceso'   => [
                        $employee->id,
                    ],
                ];
                
                $models[] = [
                    'moneda_id' => $moneda->id,
                    // 'employee_id'   => $employee->id,
                    'name'      => 'Tarjetas',
                    'address_id'    => $employee->address_id,
                    'user_id'   => config('app.USER_ID'),
                    'saldo'     => 10000,
                    'default_payment_method_caja' => [
                        'payment_method_id'     => 5,
                        'address_id'            => $employee->address_id,
                    ],
                    'user_con_acceso'   => [
                        $employee->id,
                    ],
                ];
                
                $models[] = [
                    'moneda_id' => $moneda->id,
                    // 'employee_id'   => $employee->id,
                    'name'      => 'Transferencias',
                    'address_id'    => $employee->address_id,
                    'user_id'   => config('app.USER_ID'),
                    'saldo'     => 10000,
                    'default_payment_method_caja' => [
                        'payment_method_id'     => 4,
                        'address_id'            => $employee->address_id,
                    ],
                    'user_con_acceso'   => [
                        $employee->id,
                    ],
                ];
            }
        }


        $num = 1;

        foreach ($models as $model) {
            Log::info('creando caja '.$model['name']);
            $model_to_create = [];
            $model_to_create['num'] = $num;
            $model_to_create['name'] = $model['name'];
            $model_to_create['moneda_id'] = $model['moneda_id'];
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

            if (isset($model['user_con_acceso'])) {
                foreach ($model['user_con_acceso'] as $user_id) {
                    $caja->users()->attach($user_id);
                }
            }

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

            // payment_method_id acepta un id suelto (caso histórico: una caja, un método) o un
            // array de ids (grupo 321: la caja Tarjetas es default de Débito Y Crédito a la
            // vez). Normalizar a array acá deja el resto del método sin cambios para el caso
            // viejo -- itera un array de un solo elemento, mismo resultado que antes.
            $payment_method_ids = is_array($default_payment_method_caja['payment_method_id'])
                ? $default_payment_method_caja['payment_method_id']
                : [$default_payment_method_caja['payment_method_id']];

            foreach ($payment_method_ids as $payment_method_id) {

                if (isset($default_payment_method_caja['address_id'])) {

                    DefaultPaymentMethodCaja::create([

                        'current_acount_payment_method_id'  => $payment_method_id,
                        'address_id'                        => $default_payment_method_caja['address_id'],
                        'caja_id'                           => $caja_creada->id,
                        'user_id'                           => $caja_creada->user_id,
                    ]);

                } else if ($this->addresses->isEmpty()) {

                    // Sin ninguna sucursal sembrada, no hay por dónde repartir el default: una
                    // sola fila con address_id null, en vez de dejar la caja compartida sin
                    // ningún default (que es lo que pasaba antes de este chequeo: el foreach de
                    // abajo, sobre una colección vacía, no creaba nada).
                    DefaultPaymentMethodCaja::create([

                        'current_acount_payment_method_id'  => $payment_method_id,
                        'address_id'                         => null,
                        'caja_id'                            => $caja_creada->id,
                        'user_id'                            => $caja_creada->user_id,
                    ]);

                } else {

                    foreach ($this->addresses as $address) {

                        DefaultPaymentMethodCaja::create([

                            'current_acount_payment_method_id'  => $payment_method_id,
                            'address_id'                        => $address['id'],
                            'caja_id'                           => $caja_creada->id,
                            'user_id'                           => $caja_creada->user_id,
                        ]);

                    }
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
