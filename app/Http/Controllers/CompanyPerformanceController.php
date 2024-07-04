<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\PerformanceHelper;
use App\Models\CompanyPerformance;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CompanyPerformanceController extends Controller
{

    function index($inicio = null, $fin = null) {

        if (!is_null($inicio) && !is_null($fin)) {

            $company_performances = $this->get_company_performances($inicio, $fin);

            $company_performance = $this->sumar_company_performances($company_performances);

            return response()->json(['model' => $company_performance, 'meses_anteriores' => $company_performances], 201);
        } else {
            
            $this->delete_current_company_performance();

            $performance_helper = new PerformanceHelper(null, null, $this->userId());

            $performance_helper->create_company_performance();

            $company_performance = $this->get_company_performance_today(true);

            return response()->json(['model' => $company_performance], 201);
        }

    }

    function get_company_performances($fecha_inicio, $fecha_fin) {
        $ano_inicio = explode('-', $fecha_inicio)[0];
        $mes_inicio = explode('-', $fecha_inicio)[1];

        $ano_fin = explode('-', $fecha_fin)[0];
        $mes_fin = explode('-', $fecha_fin)[1];

        $fecha_inicio = Carbon::create($ano_inicio, $mes_inicio, 1);
        $fecha_fin = Carbon::create($ano_fin, $mes_fin, 1);

        $company_performances = [];

        while ($fecha_inicio->lt($fecha_fin)) {
            Log::info('buscando rendimiento de '.$fecha_inicio->format('d/m/Y'));
            $company_performance = CompanyPerformance::where('user_id', $this->userId())
                                ->where('year', $fecha_inicio->year)
                                ->where('month', $fecha_inicio->month)
                                ->first();

            if (!is_null($company_performance)) {
                $company_performances[] = $company_performance;
            }
            $fecha_inicio->addMonth();
        }
        Log::info('Esto quedo:');
        Log::info($company_performances);
        return $company_performances;
    }

    function sumar_company_performances($company_performances) {
        $company_performance_result = [
            'total_vendido'     => 0,
            'total_pagado_mostrador'    => 0,
            'total_vendido_a_cuenta_corriente'  => 0,
            'total_pagado_a_cuenta_corriente'   => 0,
            'total_devolucion'  => 0,
            'total_ingresos'    => 0,
            'cantidad_ventas'   => 0,
            'total_gastos'  => 0,
            'total_comprado'    => 0,
        ];

        foreach ($company_performances as $company_performance) {

            $company_performance_result['total_vendido']     += $company_performance->total_vendido;
            $company_performance_result['total_pagado_mostrador']    += $company_performance->total_pagado_mostrador;
            $company_performance_result['total_vendido_a_cuenta_corriente']  += $company_performance->total_vendido_a_cuenta_corriente;
            $company_performance_result['total_pagado_a_cuenta_corriente']   += $company_performance->total_pagado_a_cuenta_corriente;
            $company_performance_result['total_devolucion']  += $company_performance->total_devolucion;
            $company_performance_result['total_ingresos']    += $company_performance->total_ingresos;
            $company_performance_result['cantidad_ventas']   += $company_performance->cantidad_ventas;
            $company_performance_result['total_gastos']  += $company_performance->total_gastos;
            $company_performance_result['total_comprado']    += $company_performance->total_comprado;


            // Se esta sobreescribiendo los valores
            $company_performance_result['ingresos_mostrador'] = [];
            foreach ($company_performance->ingresos_mostrador as $ingresos_mostrador) {
                $company_performance_result['ingresos_mostrador'][] = $ingresos_mostrador;
            }

            $company_performance_result['ingresos_cuenta_corriente'] = [];
            foreach ($company_performance->ingresos_cuenta_corriente as $ingresos_cuenta_corriente) {
                $company_performance_result['ingresos_cuenta_corriente'][] = $ingresos_cuenta_corriente;
            }

            $company_performance_result['expense_concepts'] = [];
            foreach ($company_performance->expense_concepts as $expense_concepts) {
                $company_performance_result['expense_concepts'][] = $expense_concepts;
            }

            // Aca tengo que sumar tambien el array de pagos
        }

        return $company_performance_result;        
    }

    function delete_current_company_performance() {

        $current_company_performance = $this->get_company_performance_today();

        if (!is_null($current_company_performance)) {
            $current_company_performance->delete();
        }
    }

    function get_company_performance_today($with_all = false) {

        $model = CompanyPerformance::where('user_id', $this->userId())
                                                        ->where('from_today', 1);
        if ($with_all) {
            $model = $model->withAll();
        }
        $model = $model->first();
        return $model;
    }
    
    function create($month, $year, $user_id) {

        $performance_helper = new PerformanceHelper($month, $year, $user_id);

        $performance_helper->create_company_performance();

    }

}
