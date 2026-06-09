<?php

namespace Database\Seeders;

use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\CurrentAcountPagoHelper;
use App\Http\Controllers\Helpers\DeleteModelsHelper;
use App\Http\Controllers\Helpers\Seeders\SaleSeederHelper;
use App\Models\Address;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\Expense;
use App\Models\ProviderOrder;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Seeder que puebla datos de varios meses para testear el módulo de reportes con rangos de fechas.
 * Genera ventas mostrador, ventas a cuenta corriente, pagos de clientes,
 * compras a proveedores, pagos a proveedores y gastos por cada mes configurado.
 */
class ReportesMesSeeder extends Seeder
{
    /**
     * Ejecuta el seeder de datos históricos por mes.
     * Itera sobre la configuración de $meses y crea 4 registros distribuidos
     * en días distintos del mes para cada categoría.
     *
     * @return void
     */
    public function run()
    {
        // Id del usuario demo/owner desde .env; los helpers (ArticleHelper, UserHelper) lo requieren en CLI
        $user_id = config('app.USER_ID');

        // Sin sesión ni Auth, ProviderOrderHelper::updateArticleStock() falla al recalcular precios del artículo
        DeleteModelsHelper::setup_auth_context($user_id);

        // Configuración de cada mes a poblar:
        // meses_atras = cuántos meses hacia atrás respecto al actual (0 = mes actual)
        // los demás campos son los totales deseados para ese mes
        $meses = [
            [
                'meses_atras'           => 5,
                'ventas_mostrador'      => 80000,
                'ventas_cc'             => 120000,
                'pagos_clientes'        => 90000,
                'compras_proveedores'   => 60000,
                'pagos_proveedores'     => 40000,
                'gastos'                => 15000,
            ],
            [
                'meses_atras'           => 4,
                'ventas_mostrador'      => 95000,
                'ventas_cc'             => 140000,
                'pagos_clientes'        => 110000,
                'compras_proveedores'   => 70000,
                'pagos_proveedores'     => 55000,
                'gastos'                => 18000,
            ],
            [
                'meses_atras'           => 3,
                'ventas_mostrador'      => 110000,
                'ventas_cc'             => 160000,
                'pagos_clientes'        => 130000,
                'compras_proveedores'   => 80000,
                'pagos_proveedores'     => 65000,
                'gastos'                => 20000,
            ],
            [
                'meses_atras'           => 2,
                'ventas_mostrador'      => 130000,
                'ventas_cc'             => 180000,
                'pagos_clientes'        => 150000,
                'compras_proveedores'   => 90000,
                'pagos_proveedores'     => 75000,
                'gastos'                => 22000,
            ],
            [
                'meses_atras'           => 1,
                'ventas_mostrador'      => 150000,
                'ventas_cc'             => 200000,
                'pagos_clientes'        => 170000,
                'compras_proveedores'   => 100000,
                'pagos_proveedores'     => 85000,
                'gastos'                => 25000,
            ],
        ];

        // Contador de num para ventas; arranca en 5000 para no colisionar con datos del demo
        $num = 5000;

        // Contador de num para pagos y pedidos a proveedor; arranca en 5000 por la misma razón
        $num_pago = 5000;

        // Cantidad fija de registros por categoría para distribuir los totales del mes
        $cant_registros = 4;

        // Cuenta corriente del cliente 1 en pesos, usada para pagos recibidos y ventas CC
        $credit_account_cliente = CreditAccount::where('model_name', 'client')
                                               ->where('model_id', 1)
                                               ->where('moneda_id', 1)
                                               ->first();

        // Cuenta corriente del proveedor 1 en pesos, usada para pagos a proveedor
        $credit_account_proveedor = CreditAccount::where('model_name', 'provider')
                                                  ->where('model_id', 1)
                                                  ->where('moneda_id', 1)
                                                  ->first();

        $addresses = Address::all();

        foreach ($meses as $mes) {

            // Offsets de días para distribuir los registros a lo largo del mes;
            // se incrementan de a 4-6 días por registro para evitar fechas repetidas
            $dia_mostrador  = 0;
            $dia_cc         = 0;
            $dia_pagos_cl   = 0;
            $dia_compras    = 0;
            $dia_pagos_prov = 0;
            $dia_gastos     = 0;

            // ---------------------------------------------------------------
            // Ventas en mostrador (sin cuenta corriente, client_id = null)
            // ---------------------------------------------------------------
            $montos_mostrador = $this->distribuir($mes['ventas_mostrador'], $cant_registros);

            // Acumulamos las ventas en un array para pasarlas al helper en bloque
            $ventas_mostrador = [];

            // IDs de sucursales para repartir ventas mostrador de forma rotativa (1 por sucursal, ciclando si hay más de 4)
            $sucursal_ids = [1, 2, 3, 4];

            foreach ($montos_mostrador as $indice_mostrador => $monto) {
                // Avanzar entre 4 y 6 días para distribuir las ventas en el mes
                $dia_mostrador += rand(4, 6);
                $fecha = Carbon::now()->startOfMonth()->subMonths($mes['meses_atras'])->addDays($dia_mostrador);

                // address_id rota entre las 4 sucursales: registro 0 → 1, 1 → 2, …, 4 → 1, etc.
                $address_id = $sucursal_ids[$indice_mostrador % count($sucursal_ids)];

                $ventas_mostrador[] = [
                    'num'             => $num,
                    'total'           => $monto,
                    'employee_id'     => config('app.USER_ID'),
                    'address_id'      => $address_id,
                    'client_id'       => null,
                    'moneda_id'       => 1,
                    'articles'        => [
                        [
                            'id'           => 1,
                            // Precio igual al monto total ya que es 1 unidad
                            'price_vender' => $monto,
                            // Costo = mitad del precio de venta
                            'cost'         => $monto / 2,
                            'amount'       => 1,
                        ],
                    ],
                    // Pago en efectivo (id = 3) por el total completo
                    'payment_methods' => [
                        [
                            'id'     => 3,
                            'amount' => $monto,
                        ],
                    ],
                    'created_at' => $fecha,
                ];

                $num++;
            }

            SaleSeederHelper::create_sales($ventas_mostrador);

            // ---------------------------------------------------------------
            // Ventas a cuenta corriente (client_id = 1, sin payment_methods)
            // ---------------------------------------------------------------
            $montos_cc = $this->distribuir($mes['ventas_cc'], $cant_registros);

            // Acumulamos las ventas CC para pasarlas al helper en bloque
            $ventas_cc = [];

            foreach ($montos_cc as $monto) {
                $dia_cc += rand(4, 6);
                $fecha = Carbon::now()->startOfMonth()->subMonths($mes['meses_atras'])->addDays($dia_cc);

                $ventas_cc[] = [
                    'num'             => $num,
                    'total'           => $monto,
                    'employee_id'     => config('app.USER_ID'),
                    'address_id'      => count($addresses) >= 1 ? rand(1, count($addresses)) : null,
                    'client_id'       => 1,
                    'moneda_id'       => 1,
                    'articles'        => [
                        [
                            'id'           => 1,
                            'price_vender' => $monto,
                            'cost'         => $monto / 2,
                            'amount'       => 1,
                        ],
                    ],
                    // Sin métodos de pago: queda registrado como deuda en cuenta corriente
                    'payment_methods' => [],
                    'created_at'      => $fecha,
                ];

                $num++;
            }

            SaleSeederHelper::create_sales($ventas_cc);

            // ---------------------------------------------------------------
            // Pagos recibidos de clientes (abonos a la cuenta corriente del cliente 1)
            // ---------------------------------------------------------------
            $montos_pagos_cl = $this->distribuir($mes['pagos_clientes'], $cant_registros);

            foreach ($montos_pagos_cl as $monto) {
                $dia_pagos_cl += rand(4, 6);
                $fecha = Carbon::now()->startOfMonth()->subMonths($mes['meses_atras'])->addDays($dia_pagos_cl);

                // Crear el movimiento de cuenta corriente como pago recibido del cliente
                $pago_cliente = CurrentAcount::create([
                    'haber'             => $monto,
                    'detalle'           => 'Pago N°' . $num_pago,
                    'description'       => null,
                    'status'            => 'pago_from_client',
                    'user_id'           => config('app.USER_ID'),
                    'num_receipt'       => $num_pago,
                    'client_id'         => 1,
                    'created_at'        => $fecha,
                    'employee_id'       => config('app.USER_ID'),
                    'credit_account_id' => $credit_account_cliente->id,
                ]);

                $num_pago++;

                // Asociar el método de pago: efectivo (id = 3) por el total completo
                CurrentAcountPagoHelper::attachPaymentMethods($pago_cliente, [
                    [
                        'amount'                           => $monto,
                        'current_acount_payment_method_id' => 3,
                        'bank'                             => null,
                        'fecha_emision'                    => null,
                        'fecha_pago'                       => null,
                        'cobrado_at'                       => null,
                        'num'                              => null,
                        'credit_card_id'                   => null,
                        'credit_card_payment_plan_id'      => null,
                    ],
                ]);

                // Calcular y guardar el saldo resultante después del pago
                $pago_cliente->saldo = CurrentAcountHelper::getSaldo($credit_account_cliente->id, $pago_cliente) - $pago_cliente->haber;
                $pago_cliente->save();

                // Ejecutar el helper de pago para procesar imputaciones en la cuenta corriente
                $pago_helper = new CurrentAcountPagoHelper($credit_account_cliente->id, 'client', 1, $pago_cliente);
                $pago_helper->init();
            }

            // ---------------------------------------------------------------
            // Compras a proveedores (pedidos al proveedor 1)
            // ---------------------------------------------------------------
            $montos_compras = $this->distribuir($mes['compras_proveedores'], $cant_registros);

            foreach ($montos_compras as $monto) {
                $dia_compras += rand(4, 6);
                $fecha = Carbon::now()->startOfMonth()->subMonths($mes['meses_atras'])->addDays($dia_compras);

                // Crear el pedido al proveedor con estado inicial (id = 1)
                $order = ProviderOrder::create([
                    'num'                                    => $num_pago,
                    'total_with_iva'                         => 0,
                    'total_from_provider_order_afip_tickets' => 0,
                    'provider_id'                            => 1,
                    'provider_order_status_id'               => 1,
                    'days_to_advise'                         => 2,
                    'created_at'                             => $fecha,
                    'user_id'                                => config('app.USER_ID'),
                ]);

                $num_pago++;

                // Adjuntar artículo y débito en CC sin ProviderOrderHelper (usa getSaldo con firma vieja)
                $this->crear_compra_proveedor($order, $monto, $fecha, $credit_account_proveedor);
            }

            // ---------------------------------------------------------------
            // Pagos realizados a proveedores (egresos de la cuenta corriente del proveedor 1)
            // ---------------------------------------------------------------
            $montos_pagos_prov = $this->distribuir($mes['pagos_proveedores'], $cant_registros);

            foreach ($montos_pagos_prov as $monto) {
                $dia_pagos_prov += rand(4, 6);
                $fecha = Carbon::now()->startOfMonth()->subMonths($mes['meses_atras'])->addDays($dia_pagos_prov);

                // Crear el movimiento de cuenta corriente como pago al proveedor
                $pago_proveedor = CurrentAcount::create([
                    'haber'             => $monto,
                    'detalle'           => 'Pago N°' . $num_pago,
                    'description'       => null,
                    'status'            => 'pago_from_client',
                    'user_id'           => config('app.USER_ID'),
                    'num_receipt'       => $num_pago,
                    'provider_id'       => 1,
                    'created_at'        => $fecha,
                    'employee_id'       => config('app.USER_ID'),
                    'credit_account_id' => $credit_account_proveedor->id,
                ]);

                $num_pago++;

                // Asociar el método de pago: efectivo (id = 3) por el total completo
                CurrentAcountPagoHelper::attachPaymentMethods($pago_proveedor, [
                    [
                        'amount'                           => $monto,
                        'current_acount_payment_method_id' => 3,
                        'bank'                             => null,
                        'fecha_emision'                    => null,
                        'fecha_pago'                       => null,
                        'cobrado_at'                       => null,
                        'num'                              => null,
                        'credit_card_id'                   => null,
                        'credit_card_payment_plan_id'      => null,
                    ],
                ]);

                // Calcular y guardar el saldo resultante después del pago
                $pago_proveedor->saldo = CurrentAcountHelper::getSaldo($credit_account_proveedor->id, $pago_proveedor) - $pago_proveedor->haber;
                $pago_proveedor->save();

                // Ejecutar el helper de pago para procesar imputaciones en la cuenta corriente del proveedor
                $pago_helper = new CurrentAcountPagoHelper($credit_account_proveedor->id, 'provider', 1, $pago_proveedor);
                $pago_helper->init();
            }

            // ---------------------------------------------------------------
            // Gastos del mes
            // ---------------------------------------------------------------
            $montos_gastos = $this->distribuir($mes['gastos'], $cant_registros);

            foreach ($montos_gastos as $monto) {
                $dia_gastos += rand(4, 6);
                $fecha = Carbon::now()->startOfMonth()->subMonths($mes['meses_atras'])->addDays($dia_gastos);

                Expense::create([
                    'expense_concept_id'               => 1,
                    'amount'                           => $monto,
                    'current_acount_payment_method_id' => 3,
                    'user_id'                          => config('app.USER_ID'),
                    'created_at'                       => $fecha,
                ]);
            }
        }
    }

    /**
     * Vincula el artículo al pedido y crea el débito en cuenta corriente del proveedor.
     * Evita ProviderOrderHelper::attachArticles() porque recalcula stock y llama getSaldo con firma obsoleta.
     *
     * @param  ProviderOrder   $order                    Pedido ya persistido
     * @param  int             $monto                    Costo total del pedido (1 unidad)
     * @param  Carbon          $fecha                    Fecha del movimiento
     * @param  CreditAccount   $credit_account_proveedor Cuenta corriente del proveedor en pesos
     * @return void
     */
    private function crear_compra_proveedor($order, $monto, $fecha, $credit_account_proveedor)
    {
        // Artículo del pedido: 1 unidad recibida al costo indicado
        $order->articles()->attach(1, [
            'amount'          => 1,
            'notes'           => null,
            'received'        => 1,
            'cost'            => $monto,
            'price'           => null,
            'received_cost'   => null,
            'update_cost'     => null,
            'update_provider' => null,
            'cost_in_dollars' => null,
            'add_to_articles' => null,
            'address_id'      => null,
            'iva_id'          => null,
        ]);

        // Débito en cuenta corriente del proveedor (mismo criterio que createCurrentAcount, con getSaldo actual)
        $current_acount = CurrentAcount::create([
            'detalle'             => 'Pedido N°' . $order->num,
            'debe'                => $monto,
            'status'              => 'sin_pagar',
            'user_id'             => config('app.USER_ID'),
            'provider_id'         => 1,
            'provider_order_id'   => $order->id,
            'credit_account_id'   => $credit_account_proveedor->id,
            'created_at'          => $fecha,
        ]);

        $current_acount->saldo = CurrentAcountHelper::getSaldo($credit_account_proveedor->id, $current_acount) + $monto;
        $current_acount->save();

        CurrentAcountHelper::checkSaldos($credit_account_proveedor->id);
    }

    /**
     * Distribuye un total en $cant_registros enteros que sumen exactamente $total.
     * Aplica un factor aleatorio entre 0.6 y 1.4 para dar variación realista entre partes.
     * El último elemento se ajusta para cerrar la suma exacta independientemente del redondeo.
     *
     * @param  int  $total           Total entero a distribuir
     * @param  int  $cant_registros  Cantidad de partes a generar
     * @return array<int>            Array de $cant_registros enteros que suman $total
     */
    private function distribuir(int $total, int $cant_registros): array
    {
        // Array resultante con los montos de cada parte
        $partes = [];

        // Acumulador parcial para calcular el residuo del último elemento
        $suma = 0;

        for ($i = 0; $i < $cant_registros - 1; $i++) {
            // Factor aleatorio entre 0.6 y 1.4 para evitar que todas las partes sean iguales
            $factor = mt_rand(60, 140) / 100;
            $parte = (int) round(($total / $cant_registros) * $factor);

            $partes[] = $parte;
            $suma += $parte;
        }

        // El último elemento absorbe la diferencia para que la suma cierre exacto
        $partes[] = $total - $suma;

        return $partes;
    }
}
