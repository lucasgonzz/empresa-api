<?php

namespace App\Http\Controllers\Helpers;

use App\Exports\ArticleSalesExport;
use App\Http\Controllers\Helpers\ProviderOrderHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Pdf\Reportes\ClientesPdf;
use App\Http\Controllers\Pdf\Reportes\InventarioPdf;
use App\Models\ArticlePerformance;
use App\Models\CompanyPerformance;
use App\Models\CurrentAcount;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\Expense;
use App\Models\ExpenseConcept;
use App\Models\ProviderOrder;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class PerformanceHelper
{

    public $sales;
    public $articulos_vendidos;
    public $total_vendido;

    function __construct($month, $year, $user_id) {

        $this->user_id = $user_id;
        
        $this->month = $month;
        $this->year = $year;


        if (!is_null($month) && !is_null($year)) {
            $this->mes_inicio = Carbon::createFromFormat('Y-m', "$year-$month")->startOfMonth();
            $this->from_today = 0;
        } else {
            $this->mes_inicio = Carbon::now()->startOfDay();
            $this->from_today = 1;
        }

        $mes_inicio = $this->mes_inicio->copy();

        $this->mes_fin = $mes_inicio->endOfMonth();
    }


    function create_company_performance() {


        $this->company_performance = CompanyPerformance::create([
            'month'      => $this->month,
            'year'       => $this->year,
            'from_today' => $this->from_today,
            'user_id'    => $this->user_id,
        ]);

        $this->set_sales();

        $this->set_article_performances();

        $this->procesar_sales();

        $this->procesar_pagos();

        $this->procesar_devoluciones();

        $this->procesar_gastos();

        $this->set_company_performance_props();

        $this->attach_ingresos_por_metodos_de_pago();

        $this->attach_gastos_por_conceptos();

        return $this->company_performance;

        // Ventas
        

        $metodos_de_pago_cc = $this->get_ingresos_pagos_de_cuentas_corrientes($mes_inicio, $mes_fin);

        // Log::info('metodos_de_pago_cc');
        // Log::info($metodos_de_pago_cc);

        /* 
            Aca sumo los totales de los metodos de pago de lo vendido en mostrador
            Y de los pagos de los clientes en las cc
        */
        $metodos_de_pago = $this->sumar_metodos_de_pago($metodos_de_pago_ventas_mostrador, $metodos_de_pago_cc['metodos_de_pago']);
        
        if (!is_null($mes_inicio)) {
            $ventas_por_mes     = $total_vendido_result['ventas_por_mes'];
        } else {
            $ventas_por_mes = [];
        }



        // Log::info('mes_inicio despues de ventas');
        // Log::info($mes_inicio);
        // Gastos

        $total_gastado_result = $this->get_gastos($mes_inicio, $mes_fin);
        $total_gastado = $total_gastado_result['total_gastado'];

        if (isset($total_gastado_result['gastos_por_mes'])) {
            $gastos_por_mes     = $total_gastado_result['gastos_por_mes'];
        } else {
            $gastos_por_mes = [];
        }

        $rentabilidad = $total_vendido - $total_gastado;
    
        return response()->json([
            'total_vendido'          => $total_vendido,
            'pagado_en_mostrador'    => $pagado_en_mostrador,
            'a_cuentas_corrientes'   => $a_cuentas_corrientes,
            'gastos'                 => $total_gastado,
            'rentabilidad'           => $rentabilidad,
            'articulos_vendidos'     => $articulos_vendidos,
            'cantidad_ventas'        => $cantidad_ventas,
            'ventas_por_mes'         => $ventas_por_mes,
            'gastos_por_mes'         => $gastos_por_mes,
            'metodos_de_pago'        => $metodos_de_pago,
            'ingresos_pagos_de_cuentas_corrientes' => $metodos_de_pago_cc['total'],
        ], 200);
    }

    function attach_ingresos_por_metodos_de_pago() {

        $this->attach_ingresos_mostrador();

        $this->attach_ingresos_cuenta_corriente();
    }

    function attach_gastos_por_conceptos() {

        foreach ($this->expense_concepts as $expense_concept) {
            $this->company_performance->expense_concepts()->attach($expense_concept['id'], [
                'amount'    => $expense_concept['total'],
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
                                ->whereDate('created_at', '>=', $this->mes_inicio)
                                ->whereDate('created_at', '<=', $this->mes_fin)
                                ->where('terminada', 1)
                                ->with('articles')
                                ->get();
    }


    function procesar_sales() {

        $this->total_vendido = 0;

        $this->total_pagado_mostrador = 0;

        $this->cantidad_ventas = 0;

        $this->total_vendido_a_cuenta_corriente = 0;

        $this->ingresos_mostrador = $this->get_payment_methods();

        foreach ($this->sales as $sale) {

            $this->cantidad_ventas++;

            $total_sale = SaleHelper::getTotalSale($sale);
            
            $this->total_vendido += $total_sale;


            if (is_null($sale->client_id) || $sale->omitir_en_cuenta_corriente) {

                $this->total_pagado_mostrador += $total_sale;

                $current_acount_payment_method_id = $sale->current_acount_payment_method_id;

                if (is_null($current_acount_payment_method_id)) {
                    $current_acount_payment_method_id = 3;
                }

                $this->ingresos_mostrador[$current_acount_payment_method_id]['total'] += $total_sale;


                
            } else if (!is_null($sale->current_acount)) {

                $this->total_vendido_a_cuenta_corriente += $total_sale;

            } else {
                /* 
                    Aca entrarian las ventas a los clientes que tienen desactivado
                    pasar_ventas_a_la_cuenta_corriente_sin_esperar_a_facturar
                    y todavia no se han facturado
                */
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

                $this->add_to_articles($article, $sale);
            }
        }


        foreach ($this->articulos_vendidos as $article) {
            ArticlePerformance::create([
                'article_id'    => $article['id'],
                'article_name'  => $article['name'],
                'cost'          => $article['cost'],
                'price'         => $article['price'],
                'amount'        => $article['amount'],
                'provider_id'   => $article['provider_id'],
                'category_id'   => $article['category_id'],

                // Este $article['sale_created_at'] es la fecha de la venta, ver cuando se agregar a $this->articulos_vendidos
                'created_at'    => $article['sale_created_at'],
                'company_performance_id'    => $this->company_performance->id,
            ]);
        }
    }

    function add_to_articles($article, $sale) {
        $index = array_search($article->id, array_column($this->articulos_vendidos, 'id'));
        if (!$index) {
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
        } else {
            $this->articulos_vendidos[$index]['amount']   += (float)$article->pivot->amount;
            $this->articulos_vendidos[$index]['cost']     += (float)$article->pivot->cost;
            $this->articulos_vendidos[$index]['price']    += (float)$article->pivot->price;
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
        Log::info('procesar_pagos mes_inicio: '.$this->mes_inicio);
        $pagos = CurrentAcount::where('user_id', $this->user_id)
                                ->whereNotNull('haber')
                                ->where('status', 'pago_from_client')
                                ->whereDate('created_at', '>=', $this->mes_inicio)
                                ->whereDate('created_at', '<=', $this->mes_fin)
                                ->get();

        $this->total_pagado_a_cuenta_corriente = 0;

        $this->ingresos_cuenta_corriente = $this->get_payment_methods();

        foreach ($pagos as $pago) {

            Log::info('sumando a total_pagado_a_cuenta_corriente: '.$pago->haber);

            $this->total_pagado_a_cuenta_corriente += $pago->haber;

            if (count($pago->current_acount_payment_methods) >= 1) {

                foreach ($pago->current_acount_payment_methods as $payment_method) {

                    $this->ingresos_cuenta_corriente[$payment_method->id]['total'] += $payment_method->pivot->amount;
                } 
            } else {
                $this->ingresos_cuenta_corriente[3]['total'] += $pago->haber;
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

        foreach ($expenses as $expense) {
            
            $this->total_gastos += $expense->amount;

            $this->expense_concepts[$expense->expense_concept_id]['total'] += $expense->amount;
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

    function get_gastos($mes_inicio, $mes_fin) {
        if (!is_null($mes_inicio)) {

            $gastos_por_mes = [];

            // Log::info('mes inicio: '.$mes_inicio);
            // Log::info('mes fin: '.$mes_fin);

            while ($mes_inicio->lte($mes_fin)) {
                // Log::info('entro con mes inicio = '.$mes_inicio);

                $provider_orders = ProviderOrder::where('user_id', $this->user_id)
                                                ->whereDate('created_at', '>=', $mes_inicio)
                                                ->whereDate('created_at', '<=', $mes_inicio->addMonth())
                                                ->get();

                $mes_inicio->subMonth();

                $datos_del_mes = [];

                $nombre_del_mes = $this->mes_es_espanol($mes_inicio->formatLocalized('%B'));

                $datos_del_mes['mes']                   = $nombre_del_mes;
                $datos_del_mes['total_gastado']         = $this->get_total_provider_orders($provider_orders);

                $gastos_por_mes[] = $datos_del_mes;

                $mes_inicio->addMonth();
            }

            $total_gastado = 0;

            foreach ($gastos_por_mes as $datos_del_mes) {
                $total_gastado      += $datos_del_mes['total_gastado'];
            }

            return [
                'total_gastado'         => $total_gastado,
                'gastos_por_mes'        => $gastos_por_mes,
            ];

        } else {

            $provider_orders = ProviderOrder::where('user_id', $this->user_id)
                                                ->whereDate('created_at', Carbon::today())
                                                ->get();

            $total = 0;

            foreach ($provider_orders as $provider_order) {
                $total += ProviderOrderHelper::getTotal($provider_order->id);
            }

            return [
                'total_gastado' => $total,
            ];

        }
    }

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


    function get_total_provider_orders($provider_orders) {

        $total_gastado = 0;

        foreach ($provider_orders as $provider_order) {
            $total_gastado += ProviderOrderHelper::getTotal($provider_order->id);
        }

        return $total_gastado;

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