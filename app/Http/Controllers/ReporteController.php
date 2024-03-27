<?php

namespace App\Http\Controllers;

use App\Exports\ArticleSalesExport;
use App\Http\Controllers\Helpers\ProviderOrderHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Pdf\Reportes\ClientesPdf;
use App\Http\Controllers\Pdf\Reportes\InventarioPdf;
use App\Models\CurrentAcount;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\ProviderOrder;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class ReporteController extends Controller
{

    function index($mes_inicio = null, $mes_fin = null) {

        if (!is_null($mes_inicio) && !is_null($mes_fin)) {
            $mes_inicio = Carbon::createFromFormat('Y-m', $mes_inicio)->startOfMonth();
            $mes_fin = Carbon::createFromFormat('Y-m', $mes_fin)->startOfMonth();
        }


        // Ventas

        $total_vendido_result   = $this->total_vendido($mes_inicio, $mes_fin);

        $total_vendido          = $total_vendido_result['total_vendido'];
        $pagado_en_mostrador    = $total_vendido_result['pagado_en_mostrador'];
        $a_cuentas_corrientes   = $total_vendido_result['a_cuentas_corrientes'];
        $articulos_vendidos     = $total_vendido_result['articulos_vendidos'];
        $cantidad_ventas        = $total_vendido_result['cantidad_ventas'];
        
        $metodos_de_pago_ventas_mostrador        = $total_vendido_result['metodos_de_pago'];

        $metodos_de_pago_cc = $this->get_ingresos_pagos_de_cuentas_corrientes($mes_inicio, $mes_fin);

        Log::info('metodos_de_pago_cc');
        Log::info($metodos_de_pago_cc);

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



        Log::info('mes_inicio despues de ventas');
        Log::info($mes_inicio);
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

    function sumar_metodos_de_pago($metodos_de_pago_ventas_mostrador, $metodos_de_pago_cc) {
        $metodos_de_pago = $this->get_payment_methods();
        foreach ($metodos_de_pago as $id => $metodo_de_pago) {
            Log::info('sumando '.$metodo_de_pago['nombre'].' en mostrador de '.$metodos_de_pago_ventas_mostrador[$id]['total']);
            Log::info('y '.$metodo_de_pago['nombre'].' en cc de '.$metodos_de_pago_cc[$id]['total']);
            $metodos_de_pago[$id]['total'] += $metodos_de_pago_ventas_mostrador[$id]['total'] + $metodos_de_pago_cc[$id]['total'];
            Log::info('_______________________________');    
        }
        return $metodos_de_pago;
    }

    function get_ingresos_pagos_de_cuentas_corrientes($mes_inicio, $mes_fin) {
        $pagos = CurrentAcount::where('user_id', $this->userId())
                                ->whereNotNull('haber');

        if (is_null($mes_inicio)) {
            $pagos = $pagos->whereDate('created_at', Carbon::today());
        } else {
            $pagos = $pagos->whereDate('created_at', '>=', $mes_inicio)
                            ->whereDate('created_at', '<=', $mes_fin);
        }
                                
        $pagos = $pagos->get();

        $total = 0;

        $metodos_de_pago = $this->get_payment_methods();

        Log::info('pagos:');
        Log::info($pagos);

        foreach ($pagos as $pago) {
            Log::info('pago de '.$pago->haber);
            Log::info($pago->current_acount_payment_methods);
            $total += $pago->haber;
            foreach ($pago->current_acount_payment_methods as $payment_method) {
                $metodos_de_pago[$payment_method->id]['total'] += $payment_method->pivot->amount;
            }
        }

        return [
            'total'             => $total,
            'metodos_de_pago'   => $metodos_de_pago,
        ];

    }

    function total_vendido($_mes_inicio, $_mes_fin) {


        if (!is_null($_mes_inicio)) {

            $mes_inicio = $_mes_inicio->copy();
            $mes_fin = $_mes_fin->copy();

            $ventas_por_mes = [];

            $metodos_de_pago = $this->get_payment_methods();

            while ($mes_inicio->lte($mes_fin)) {

                $sales = Sale::where('user_id', $this->userId())
                                ->whereDate('created_at', '>=', $mes_inicio)
                                ->whereDate('created_at', '<=', $mes_inicio->addMonth())
                                ->get();

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
            }

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
        return Sale::where('user_id', $this->userId())
                            ->whereDate('created_at', Carbon::today())
                            ->get();
    }

    function get_gastos($mes_inicio, $mes_fin) {
        if (!is_null($mes_inicio)) {

            $gastos_por_mes = [];

            Log::info('mes inicio: '.$mes_inicio);
            Log::info('mes fin: '.$mes_fin);

            while ($mes_inicio->lte($mes_fin)) {
                Log::info('entro con mes inicio = '.$mes_inicio);

                $provider_orders = ProviderOrder::where('user_id', $this->userId())
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

            $provider_orders = ProviderOrder::where('user_id', $this->userId())
                                                ->whereDate('created_at', Carbon::today())
                                                ->get();

            $total = 0;

            foreach ($provider_orders as $provider_order) {
                $total += ProviderOrderHelper::getTotal($provider_order);
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
                $metodos_de_pago[$sale->current_acount_payment_method_id]['total'] += $total_sale;
            } else {
                $a_cuentas_corrientes += $total_sale;

            }

            // $articulos_vendidos += count($sale->articles);
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
