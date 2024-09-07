<?php

namespace App\Http\Controllers\Helpers;

use App\Exports\ArticleSalesExport;
use App\Http\Controllers\CommonLaravel\Helpers\Numbers;
use App\Http\Controllers\Helpers\ProviderOrderHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Pdf\Reportes\ClientesPdf;
use App\Http\Controllers\Pdf\Reportes\InventarioPdf;
use App\Models\Address;
use App\Models\ArticlePerformance;
use App\Models\Client;
use App\Models\CompanyPerformance;
use App\Models\CurrentAcount;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\Expense;
use App\Models\ExpenseConcept;
use App\Models\ProviderOrder;
use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class PerformanceHelper
{

    public $sales;
    public $articulos_vendidos;
    public $total_vendido;

    function __construct($month, $year, $user_id, $from_day = null, $from_current_month = false) {

        $this->user_id = $user_id;
        
        $this->month = $month;
        $this->year = $year;
        $this->from_day = $from_day;
        $this->from_current_month = $from_current_month;


        if (!is_null($month) && !is_null($year)) {

            $this->mes_inicio = Carbon::createFromFormat('Y-m', "$year-$month")->startOfMonth();
            $this->from_today = 0;
        } else {

            $this->mes_inicio = Carbon::now()->startOfDay();
            $this->from_today = 1;
        }

        if (!is_null($from_day)) {

            $this->mes_inicio = Carbon::parse($from_day)->startOfDay();
            $this->mes_fin = Carbon::parse($from_day)->endOfDay();

        } else {

            $mes_inicio = $this->mes_inicio->copy();
            $this->mes_fin = $mes_inicio->endOfMonth();
        }

    }

    function set_day() {

        if (!is_null($this->from_day)) {

            $this->company_performance->day = $this->mes_inicio->day;
            $this->company_performance->save();
        }
    }


    function create_company_performance() {


        $this->company_performance = CompanyPerformance::create([
            'month'      => $this->month,
            'year'       => $this->year,
            'from_today' => $this->from_today,
            'user_id'    => $this->user_id,
        ]);

        $this->set_day();

        $this->set_sales();

        $this->set_compras_a_proveedores();

        $this->set_pagos_a_proveedores();

        $this->set_total_iva_comprado();

        $this->total_vendido_costos = 0;
        
        if (!$this->from_current_month && is_null($this->from_day)) {

            $this->set_article_performances();
        }


        $this->set_users_payment_methods();

        $this->set_addresses_payment_methods();



        $this->procesar_sales();

        $this->procesar_pagos();

        $this->procesar_devoluciones();

        $this->procesar_gastos();

        $this->set_company_performance_props();


        $this->attach_ingresos_por_metodos_de_pago();
        
        if (is_null($this->from_day)) {

            $this->set_deuda_clientes();
        }



        $this->attach_users_payment_methods();

        $this->attach_addresses_payment_methods();



        $this->attach_gastos_por_conceptos();

        $this->attach_gastos_metodos_de_pago();

        return $this->company_performance;
    }

    function set_compras_a_proveedores() {

        $this->total_comprado = 0;

        $provider_orders = ProviderOrder::where('user_id', $this->user_id)
                                    ->whereDate('created_at', '>=', $this->mes_inicio)
                                    ->whereDate('created_at', '<=', $this->mes_fin)
                                    ->pluck('id');

        // Log::info('provider_orders id:');
        // Log::info($provider_orders);

        foreach ($provider_orders as $provider_order) {
            
            $total = ProviderOrderHelper::getTotal($provider_order);

            $this->total_comprado += $total;
        }
    }

    function set_total_iva_comprado() {

        $this->total_iva_comprado = 0;

        $provider_orders = ProviderOrder::where('user_id', $this->user_id)
                                    ->whereDate('created_at', '>=', $this->mes_inicio)
                                    ->whereDate('created_at', '<=', $this->mes_fin)
                                    ->get();

        foreach ($provider_orders as $provider_order) {

            foreach ($provider_order->provider_order_afip_tickets as $afip_ticket) {

                $this->total_iva_comprado += $afip_ticket->total;
            }
        }
    }

    function set_pagos_a_proveedores() {

        $pagos = CurrentAcount::where('user_id', $this->user_id)
                                ->whereNotNull('haber')
                                ->whereNotNull('provider_id')
                                ->where('status', 'pago_from_client')
                                ->whereDate('created_at', '>=', $this->mes_inicio)
                                ->whereDate('created_at', '<=', $this->mes_fin)
                                ->get();

        $this->total_pagado_a_proveedores = 0;

        $this->pagos_a_proveedores = $this->get_payment_methods();

        foreach ($pagos as $pago) {

            $this->total_pagado_a_proveedores += $pago->haber;

            if (count($pago->current_acount_payment_methods) >= 1) {

                foreach ($pago->current_acount_payment_methods as $payment_method) {

                    $total = (float)$payment_method->pivot->amount;

                    if ($total == 0) {
                      
                        $total = (float)$pago->haber;
                    } 

                    $this->pagos_a_proveedores[$payment_method->id]['total'] += $total;

                } 
            } else {

                $this->pagos_a_proveedores[3]['total'] += $pago->haber;
            }

        }

    }

    function set_deuda_clientes() {
        $clients = Client::where('user_id', $this->user_id)
                        ->where('status', 'active')
                        ->get();

        $deuda_clientes = 0;

        foreach ($clients as $client) {
            
            $deuda_clientes += $client->saldo;    
        }

        $this->company_performance->deuda_clientes = $deuda_clientes;
        $this->company_performance->save();
    }

    function attach_ingresos_por_metodos_de_pago() {

        $this->attach_ingresos_mostrador();

        $this->attach_ingresos_cuenta_corriente();
    }

    function attach_users_payment_methods() {

        foreach ($this->users_payment_methods as $user_payment_methods) {
            
            foreach ($user_payment_methods['payment_methods'] as $payment_method) {
                
                $this->company_performance->users_payment_methods()->attach($payment_method['id'], [
                    'amount'     => $payment_method['total'],
                    'user_id'    => $user_payment_methods['user_id'],
                ]);
            }
        }
    }

    function attach_addresses_payment_methods() {

        foreach ($this->addresses_payment_methods as $address_payment_methods) {
            
            foreach ($address_payment_methods['payment_methods'] as $payment_method) {
                
                $this->company_performance->addresses_payment_methods()->attach($payment_method['id'], [
                    'amount'        => $payment_method['total'],
                    'address_id'    => $address_payment_methods['address_id'],
                ]);
            }
        }
    }

    function attach_gastos_por_conceptos() {

        foreach ($this->expense_concepts as $expense_concept) {
            $this->company_performance->expense_concepts()->attach($expense_concept['id'], [
                'amount'    => $expense_concept['total'],
            ]);
        }
    }

    function attach_gastos_metodos_de_pago() {

        foreach ($this->payment_methods_gastos as $payment_method) {
            $this->company_performance->gastos()->attach($payment_method['id'], [
                'amount'    => $payment_method['total'],
            ]);
        }
    }

    function attach_ingresos_mostrador() {

        foreach ($this->ingresos_mostrador as $metodos_de_pago) {
            $this->company_performance->ingresos_mostrador()->attach($metodos_de_pago['id'], [
                'amount'    => $metodos_de_pago['total'],
            ]);
        }
    }

    function attach_ingresos_cuenta_corriente() {

        foreach ($this->ingresos_cuenta_corriente as $metodos_de_pago) {
            $this->company_performance->ingresos_cuenta_corriente()->attach($metodos_de_pago['id'], [
                'amount'    => $metodos_de_pago['total'],
            ]);
        }
    }

    function set_sales() {
        $this->sales = Sale::where('user_id', $this->user_id)
                            ->where(function ($query) {

                                $query->where(function ($subQuery) {
                                    $subQuery->whereDate('created_at', '>=', $this->mes_inicio)
                                             ->whereDate('created_at', '<=', $this->mes_fin);
                                })
                                ->orWhere(function ($subQuery) {
                                    $subQuery->whereDate('terminada_at', '>=', $this->mes_inicio)
                                             ->whereDate('terminada_at', '<=', $this->mes_fin);
                                });
                            })
                            ->where('terminada', 1)
                            ->with('articles')
                            ->get();
    }


    function procesar_sales() {

        $this->total_vendido = 0;

        $this->total_facturado = 0;

        $this->total_pagado_mostrador = 0;

        $this->cantidad_ventas = 0;

        $this->total_vendido_a_cuenta_corriente = 0;

        $this->ingresos_mostrador = $this->get_payment_methods();

        foreach ($this->sales as $sale) {

            $this->cantidad_ventas++;

            $this->sale = $sale;

            // $this->total_sale = $sale->total;

            // if (is_null($this->total_sale)) {

                $this->total_sale = SaleHelper::getTotalSale($sale);
                
                $sale->total = $this->total_sale;
                $sale->timestamps = false;
                $sale->save();
            // }

            
            $this->total_vendido += $this->total_sale;

            if (!is_null($sale->afip_information_id) 
                && $sale->afip_information_id != 0 
                && !is_null($sale->afip_ticket)
                && $sale->afip_ticket->resultado == 'A') {

                $this->total_facturado += $sale->afip_ticket->importe_total;
            }


            if (is_null($sale->client_id) || $sale->omitir_en_cuenta_corriente) {

                $this->total_pagado_mostrador += $this->total_sale;

                if (count($sale->current_acount_payment_methods) >= 1) {

                    foreach ($sale->current_acount_payment_methods as $payment_method) {

                        $total = $payment_method->pivot->amount;

                        if (!is_null($payment_method->pivot->discount_amount)) {

                            $total -= $payment_method->pivot->discount_amount;
                        }

                        $this->ingresos_mostrador[$payment_method->id]['total'] += $total;
                    }

                } else {

                    $current_acount_payment_method_id = $sale->current_acount_payment_method_id;

                    if (is_null($current_acount_payment_method_id) || $current_acount_payment_method_id == 0) {
                        $current_acount_payment_method_id = 3;
                    }

                    $this->ingresos_mostrador[$current_acount_payment_method_id]['total'] += $this->total_sale;
                }



                
            } else if (!is_null($sale->current_acount)) {

                $this->total_vendido_a_cuenta_corriente += $this->total_sale;

            } else {
                Log::info('ENTRO ACA LA VENTA N° '.$this->sale->num. ' de '.$this->total_sale);
                /* 
                    Aca entrarian las ventas a los clientes que tienen desactivado
                    pasar_ventas_a_la_cuenta_corriente_sin_esperar_a_facturar
                    y todavia no se han facturado
                */
            }


            $this->set_employee_payment_methods();

            $this->set_address_payment_methods();

        }

    }

    function set_users_payment_methods() {

        $this->users_payment_methods = [];

        $employees = User::where('owner_id', $this->user_id)
                            ->get();

        foreach ($employees as $employee) {
            
            $this->users_payment_methods[$employee->id] = [
                'user_id'           => $employee->id,
                'payment_methods'   => $this->get_payment_methods(),
            ];
        }

        $this->users_payment_methods[$this->user_id] = [
            'user_id'         => $this->user_id,
            'payment_methods' => $this->get_payment_methods(),
        ];
    }

    function set_addresses_payment_methods() {

        $this->addresses_payment_methods = [];

        $addresses = Address::where('user_id', $this->user_id)
                                ->get();

        foreach ($addresses as $address) {
            
            $this->addresses_payment_methods[$address->id] = [
                'payment_methods'   => $this->get_payment_methods(),
                'address_id'        => $address->id,
            ];
        }
    }

    function set_employee_payment_methods() {

        if (!is_null($this->sale->employee_id)) {

            $employee_id = $this->sale->employee_id;
        } else {

            $employee_id = $this->user_id;
        }

        if (is_null($this->sale->client_id) || $this->sale->omitir_en_cuenta_corriente) {

            $payment_method_id = $this->sale->current_acount_payment_method_id;

            if (!is_null($payment_method_id) && $payment_method_id != 0) {

                $this->users_payment_methods[$employee_id]['payment_methods'][$payment_method_id]['total'] += (float)$this->total_sale;
            
            } else if (count($this->sale->current_acount_payment_methods) >= 1) {

                foreach ($this->sale->current_acount_payment_methods as $payment_method) {
                
                    $total = (float)$payment_method->pivot->amount;
                    
                    if (!is_null($payment_method->pivot->discount_amount)) {

                        $total -= (float)$payment_method->pivot->discount_amount;
                    }

                    $this->users_payment_methods[$employee_id]['payment_methods'][$payment_method->id]['total'] += $total;
                }
            }
        }


    }

    function set_address_payment_methods() {

        $address_id = $this->sale->address_id;

        if (!is_null($address_id) && $address_id != 0 && isset($this->addresses_payment_methods[$address_id])) {

            $payment_method_id = $this->sale->current_acount_payment_method_id;

            if (!is_null($payment_method_id) && $payment_method_id != 0) {

                $this->addresses_payment_methods[$address_id]['payment_methods'][$payment_method_id]['total'] += (float)$this->total_sale;
            
            } else if (count($this->sale->current_acount_payment_methods) >= 1) {

                foreach ($this->sale->current_acount_payment_methods as $payment_method) {
                
                    $total = (float)$payment_method->pivot->amount;
                    
                    if (!is_null($payment_method->pivot->discount_amount)) {

                        $total -= (float)$payment_method->pivot->discount_amount;
                    }

                    $this->addresses_payment_methods[$address_id]['payment_methods'][$payment_method->id]['total'] += $total;
                }
            }
        }

    }

    function set_company_performance_props() {

        Log::info('set_company_performance_props: ');
        Log::info('total_vendido: '.$this->total_vendido);
        Log::info('total_pagado_mostrador: '.$this->total_pagado_mostrador);
        Log::info('total_vendido_a_cuenta_corriente: '.$this->total_vendido_a_cuenta_corriente);
        Log::info('total_pagado_a_cuenta_corriente: '.$this->total_pagado_a_cuenta_corriente);


        $this->company_performance->total_vendido = $this->total_vendido;
        
        $this->company_performance->total_facturado = $this->total_facturado;

        $this->company_performance->total_comprado = $this->total_comprado;

        $this->company_performance->total_pagado_a_proveedores = $this->total_pagado_a_proveedores;

        $this->company_performance->total_iva_comprado = $this->total_iva_comprado;
        
        $this->company_performance->total_vendido_costos = $this->total_vendido_costos;

        $this->company_performance->ingresos_netos = $this->total_vendido - $this->total_vendido_costos;
        
        $this->company_performance->rentabilidad = $this->total_vendido - $this->total_vendido_costos - $this->total_gastos;

        $this->company_performance->total_pagado_mostrador = $this->total_pagado_mostrador;

        $this->company_performance->total_vendido_a_cuenta_corriente = $this->total_vendido_a_cuenta_corriente;

        $this->company_performance->total_pagado_a_cuenta_corriente = $this->total_pagado_a_cuenta_corriente;

        $this->company_performance->total_devolucion = $this->total_devolucion;

        $this->company_performance->total_ingresos = $this->total_pagado_mostrador + $this->total_pagado_a_cuenta_corriente;


        $this->company_performance->total_gastos = $this->total_gastos;

        $this->company_performance->cantidad_ventas = $this->cantidad_ventas;

        $this->company_performance->save();
    }

    function set_article_performances() {

        $this->articulos_vendidos = [];

        foreach ($this->sales as $sale) {

            foreach ($sale->articles as $article) {

                $cost = $article->pivot->cost;

                if (!is_null($cost) || (is_null($article->pivot->price) || $article->pivot->price == 0)) {

                    $this->total_vendido_costos += $cost * $article->pivot->amount;
                } 

                $this->add_to_articles($article, $sale);
            }
        }

        foreach ($this->articulos_vendidos as $article) {
            ArticlePerformance::create([
                'article_id'                => $article['id'],
                'article_name'              => $article['name'],
                'cost'                      => $article['cost'],
                'price'                     => $article['price'],
                'amount'                    => $article['amount'],
                'provider_id'               => $article['provider_id'],
                'category_id'               => $article['category_id'],
                'performance_date'          => $this->mes_inicio,

                // Este $article['sale_created_at'] es la fecha de la venta, ver cuando se agregar a $this->articulos_vendidos
                'created_at'                => $article['sale_created_at'],
                'company_performance_id'    => $this->company_performance->id,
            ]);
        }
    }

    function add_to_articles($article, $sale) {
        $index = array_search($article->id, array_column($this->articulos_vendidos, 'id'));
        if ($index !== false) {
            $this->articulos_vendidos[$index]['amount']   += (float)$article->pivot->amount;
            $this->articulos_vendidos[$index]['cost']     += (float)$article->pivot->cost;
            $this->articulos_vendidos[$index]['price']    += (float)$article->pivot->price;
        } else {
            $this->articulos_vendidos[] = [
                'id'            => $article->id,
                'name'          => $article->name,
                'amount'        => (float)$article->pivot->amount,
                'cost'          => (float)$article->pivot->cost,
                'price'         => (float)$article->pivot->price,
                'provider_id'   => $article->provider_id,
                'category_id'   => $article->category_id,
                'sale_created_at'    => $sale->created_at,
            ]; 
        }
    }

    function sumar_metodos_de_pago($metodos_de_pago_ventas_mostrador, $metodos_de_pago_cc) {
        $metodos_de_pago = $this->get_payment_methods();
        foreach ($metodos_de_pago as $id => $metodo_de_pago) {
            // Log::info('sumando '.$metodo_de_pago['nombre'].' en mostrador de '.$metodos_de_pago_ventas_mostrador[$id]['total']);
            // Log::info('y '.$metodo_de_pago['nombre'].' en cc de '.$metodos_de_pago_cc[$id]['total']);
            $metodos_de_pago[$id]['total'] += $metodos_de_pago_ventas_mostrador[$id]['total'] + $metodos_de_pago_cc[$id]['total'];
            // Log::info('_______________________________');    
        }
        return $metodos_de_pago;
    }

    function procesar_pagos() {

        $pagos = CurrentAcount::where('user_id', $this->user_id)
                                ->whereNotNull('haber')
                                ->whereNotNull('client_id')
                                ->where('status', 'pago_from_client')
                                ->whereDate('created_at', '>=', $this->mes_inicio)
                                ->whereDate('created_at', '<=', $this->mes_fin)
                                ->get();

        $this->total_pagado_a_cuenta_corriente = 0;

        $this->ingresos_cuenta_corriente = $this->get_payment_methods();

        $delta = 0.00001; // Margen de error para la comparación

        foreach ($pagos as $pago) {

            $this->total_pagado_a_cuenta_corriente += $pago->haber;

            $suma_payment_methods = 0;

            if (!is_null($pago->employee_id)) {

                $employee_id = $pago->employee_id;
            } else {

                $employee_id = $this->user_id;
            }

            if (count($pago->current_acount_payment_methods) >= 1) {

                foreach ($pago->current_acount_payment_methods as $payment_method) {

                    $total = (float)$payment_method->pivot->amount;

                    if ($total == 0 && count($pago->current_acount_payment_methods) == 1) {
                      
                        $total = (float)$pago->haber;
                    } else {

                        Log::info('El total estaba en 0 y tiene mas de un metodo de pago');
                    }

                    $suma_payment_methods += $total;

                    $this->ingresos_cuenta_corriente[$payment_method->id]['total'] += $total;

                    $this->users_payment_methods[$employee_id]['payment_methods'][$payment_method->id]['total'] += $total;
                } 
            } else {

                $suma_payment_methods = null;

                $this->ingresos_cuenta_corriente[3]['total'] += $pago->haber;

                $this->users_payment_methods[$employee_id]['payment_methods'][3]['total'] += $pago->haber;
            }

        }

    }

    function procesar_devoluciones() {
        $notas_de_credito = CurrentAcount::where('user_id', $this->user_id)
                                        ->whereNotNull('haber')
                                        ->where('status', 'nota_credito')
                                        ->whereDate('created_at', '>=', $this->mes_inicio)
                                        ->whereDate('created_at', '<=', $this->mes_fin)
                                        ->get();

        $this->total_devolucion = 0;

        foreach ($notas_de_credito as $nota_de_credito) {

            $this->total_devolucion += $nota_de_credito->haber;
        }

    }

    function procesar_gastos() {
        $expenses = Expense::where('user_id', $this->user_id)
                            ->whereDate('created_at', '>=', $this->mes_inicio)
                            ->whereDate('created_at', '<=', $this->mes_fin)
                            ->get();

        $this->total_gastos = 0;
        
        $this->expense_concepts = $this->get_expense_concepts();

        $this->payment_methods_gastos = $this->get_payment_methods();

        foreach ($expenses as $expense) {

            // Log::info('procesando gasto de '.$expense->amount);
            // Log::info('procesando gasto de '.$expense->expense_concept->name.' de '.$expense->amount);

            if (!is_null($expense->expense_concept_id) && $expense->expense_concept_id != 0) {
                
                $payment_method_id = $expense->current_acount_payment_method_id;
                
                if (is_null($payment_method_id) || $payment_method_id == 0) {
                    $payment_method_id = 3;
                }
                
                $this->total_gastos += $expense->amount;

                $this->expense_concepts[$expense->expense_concept_id]['total'] += $expense->amount;

                $this->payment_methods_gastos[$payment_method_id]['total'] += $expense->amount;
            }

        }
    }

    function get_expense_concepts() {
        $_expense_concepts = ExpenseConcept::where('user_id', $this->user_id)
                                                        ->get();

        $expense_concepts = [];

        foreach ($_expense_concepts as $expense_concept) {
            $expense_concepts[$expense_concept->id] = [
                'id'        => $expense_concept->id,
                'nombre'    => $expense_concept->name,
                'total'     => 0,
            ];
        }

        return $expense_concepts;
    }
 
    function total_vendido($_mes_inicio, $_mes_fin) {


        if (!is_null($_mes_inicio)) {

            $mes_inicio = $_mes_inicio->copy();
            $mes_fin = $_mes_fin->copy();

            $ventas_por_mes = [];

            $metodos_de_pago = $this->get_payment_methods();

            $mes_inicio->subMonth();

            $total_vendido = $this->get_total_sales($sales);

            $datos_del_mes = [];

            $nombre_del_mes = $this->mes_es_espanol($mes_inicio->formatLocalized('%B'));

            $datos_del_mes['mes']                   = $nombre_del_mes;
            $datos_del_mes['total_vendido']         = $total_vendido['total_vendido'];
            $datos_del_mes['articulos_vendidos']    = $total_vendido['articulos_vendidos'];
            $datos_del_mes['cantidad_ventas']       = $total_vendido['cantidad_ventas'];
            $datos_del_mes['pagado_en_mostrador']       = $total_vendido['pagado_en_mostrador'];
            
            $datos_del_mes['a_cuentas_corrientes']       = $total_vendido['a_cuentas_corrientes'];

            $ventas_por_mes[] = $datos_del_mes;

            foreach ($metodos_de_pago as $metodo_de_pago) {
                $metodo_de_pago['total'] += $total_vendido['metodos_de_pago'][$metodo_de_pago['id']]['total'];
            }

            $mes_inicio->addMonth();

            $total_vendido = 0;
            $articulos_vendidos = 0;
            $cantidad_ventas = 0;
            $pagado_en_mostrador = 0;
            $a_cuentas_corrientes = 0;

            foreach ($ventas_por_mes as $datos_del_mes) {
                $total_vendido          += $datos_del_mes['total_vendido'];
                $articulos_vendidos     += $datos_del_mes['articulos_vendidos'];
                $cantidad_ventas        += $datos_del_mes['cantidad_ventas'];
                $pagado_en_mostrador    += $datos_del_mes['pagado_en_mostrador'];
                $a_cuentas_corrientes    += $datos_del_mes['a_cuentas_corrientes'];
            }

            return [
                'total_vendido'         => $total_vendido,
                'articulos_vendidos'    => $articulos_vendidos,
                'pagado_en_mostrador'   => $pagado_en_mostrador,
                'a_cuentas_corrientes'  => $a_cuentas_corrientes,
                'cantidad_ventas'       => $cantidad_ventas,
                'ventas_por_mes'        => $ventas_por_mes,
                'metodos_de_pago'       => $metodos_de_pago,
            ];

        } else {

            $sales = $this->get_ventas_del_dia();

            return $this->get_total_sales($sales);

        }

    }

    function get_ventas_del_dia() {
        return Sale::where('user_id', $this->user_id)
                            ->whereDate('created_at', Carbon::today())
                            ->get();
    }

    // function get_gastos($mes_inicio, $mes_fin) {
    //     if (!is_null($mes_inicio)) {

    //         $gastos_por_mes = [];

    //         // Log::info('mes inicio: '.$mes_inicio);
    //         // Log::info('mes fin: '.$mes_fin);

    //         while ($mes_inicio->lte($mes_fin)) {
    //             // Log::info('entro con mes inicio = '.$mes_inicio);

    //             $provider_orders = ProviderOrder::where('user_id', $this->user_id)
    //                                             ->whereDate('created_at', '>=', $mes_inicio)
    //                                             ->whereDate('created_at', '<=', $mes_inicio->addMonth())
    //                                             ->get();

    //             $mes_inicio->subMonth();

    //             $datos_del_mes = [];

    //             $nombre_del_mes = $this->mes_es_espanol($mes_inicio->formatLocalized('%B'));

    //             $datos_del_mes['mes']                   = $nombre_del_mes;
    //             $datos_del_mes['total_gastado']         = $this->get_total_provider_orders($provider_orders);

    //             $gastos_por_mes[] = $datos_del_mes;

    //             $mes_inicio->addMonth();
    //         }

    //         $total_gastado = 0;

    //         foreach ($gastos_por_mes as $datos_del_mes) {
    //             $total_gastado      += $datos_del_mes['total_gastado'];
    //         }

    //         return [
    //             'total_gastado'         => $total_gastado,
    //             'gastos_por_mes'        => $gastos_por_mes,
    //         ];

    //     } else {

    //         $provider_orders = ProviderOrder::where('user_id', $this->user_id)
    //                                             ->whereDate('created_at', Carbon::today())
    //                                             ->get();

    //         $total = 0;

    //         foreach ($provider_orders as $provider_order) {
    //             $total += ProviderOrderHelper::getTotal($provider_order->id);
    //         }

    //         return [
    //             'total_gastado' => $total,
    //         ];

    //     }
    // }

    function get_total_sales($sales) {

        $total_vendido = 0;

        $pagado_en_mostrador = 0;

        $a_cuentas_corrientes = 0;

        $articulos_vendidos = 0;

        $metodos_de_pago = $this->get_payment_methods();

        foreach ($sales as $sale) {
            $total_sale = SaleHelper::getTotalSale($sale);
            
            $total_vendido += $total_sale;

            if (is_null($sale->client_id)) {
                $pagado_en_mostrador += $total_sale;

                $current_acount_payment_method_id = $sale->current_acount_payment_method_id;

                if (is_null($current_acount_payment_method_id)) {
                    $current_acount_payment_method_id = 3;
                }

                $metodos_de_pago[$current_acount_payment_method_id]['total'] += $total_sale;
            } else {
                $a_cuentas_corrientes += $total_sale;
            }

            foreach ($sale->articles as $article) {
                
            }

        }

        return [
            'total_vendido'         => $total_vendido,
            'pagado_en_mostrador'   => $pagado_en_mostrador,
            'a_cuentas_corrientes'  => $a_cuentas_corrientes,
            'articulos_vendidos'    => $articulos_vendidos,
            'metodos_de_pago'       => $metodos_de_pago,
            'cantidad_ventas'       => count($sales),
        ];

    }

    function get_payment_methods() {
        $current_acount_payment_methods = CurrentAcountPaymentMethod::all();

        $metodos_de_pago = [];

        foreach ($current_acount_payment_methods as $payment_method) {
            $metodos_de_pago[$payment_method->id] = [
                'id'        => $payment_method->id,
                'nombre'    => $payment_method->name,
                'total'     => 0,
            ];
        }

        return $metodos_de_pago;
    }

    function mes_es_espanol($mes) {
        $months = [
            'January' => 'Enero',
            'February' => 'Febrero',
            'March' => 'Marzo',
            'April' => 'Abril',
            'May' => 'Mayo',
            'June' => 'Junio',
            'July' => 'Julio',
            'August' => 'Agosto',
            'September' => 'Septiembre',
            'October' => 'Octubre',
            'November' => 'Noviembre',
            'December' => 'Diciembre',
        ];
        return $months[$mes];
    }

    function inventario($company_name, $periodo) {
        $pdf = new InventarioPdf($company_name, $periodo);
    }

    function clientes($company_name, $periodo) {
        $pdf = new ClientesPdf($company_name, $periodo);
    }

    function excel_articulos($company_name, $mes) {
        return Excel::download(new ArticleSalesExport($company_name), 'cc-articulos-ventas-'.$mes.'.xlsx');
    }
}
