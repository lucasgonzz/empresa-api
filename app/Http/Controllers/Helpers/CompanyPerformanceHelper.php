<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\CommonLaravel\Helpers\UserHelper;
use App\Models\CompanyPerformance;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\ExpenseConcept;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CompanyPerformanceHelper {



	function set_fechas($fecha_inicio, $fecha_fin) {

        $ano_inicio = explode('-', $fecha_inicio)[0];
        $mes_inicio = explode('-', $fecha_inicio)[1];

        $ano_fin = explode('-', $fecha_fin)[0];
        $mes_fin = explode('-', $fecha_fin)[1];

        $this->fecha_inicio = Carbon::create($ano_inicio, $mes_inicio, 1);
        $this->fecha_fin = Carbon::create($ano_fin, $mes_fin, 1);
	}
	
    function get_company_performances_from_dates($fecha_inicio, $fecha_fin) {

        $this->user_id = UserHelper::userId(); 
    	
        $this->set_fechas($fecha_inicio, $fecha_fin);

        $this->meses_anteriores = [];

        Carbon::setLocale('es');

        while ($this->fecha_inicio->lt($this->fecha_fin)) {
            Log::info('buscando rendimiento de '.$this->fecha_inicio->format('d/m/Y'));
            $company_performance = CompanyPerformance::where('user_id', $this->user_id)
                                ->where('year', $this->fecha_inicio->year)
                                ->where('month', $this->fecha_inicio->month)
                                ->first();

            if (!is_null($company_performance)) {
                $company_performance->fecha = Carbon::create($company_performance->year, $company_performance->month, 1)->isoFormat('MMMM').' '.$company_performance->year;
                $this->meses_anteriores[] = $company_performance;
                Log::info('Entro con rendimientos de '.$company_performance->fecha);
            }
            $this->fecha_inicio->addMonth();
        }

        $this->sumar_company_performances();

        return [
        	'meses_anteriores'	=> $this->meses_anteriores,
        	'company_performance'	=> $this->suma_company_performances,
        ];


    }

    function sumar_company_performances() {
        $this->suma_company_performances = [
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

        $this->set_payment_methods();
        $this->set_expense_concepts();

        $this->init_relation('payment_methods', 'ingresos_mostrador');
        $this->init_relation('payment_methods', 'ingresos_cuenta_corriente');
        $this->init_relation('expense_concepts', 'gastos');

        foreach ($this->meses_anteriores as $company_performance) {

            $this->suma_company_performances['total_vendido']     += $company_performance->total_vendido;
            $this->suma_company_performances['total_pagado_mostrador']    += $company_performance->total_pagado_mostrador;
            $this->suma_company_performances['total_vendido_a_cuenta_corriente']  += $company_performance->total_vendido_a_cuenta_corriente;
            $this->suma_company_performances['total_pagado_a_cuenta_corriente']   += $company_performance->total_pagado_a_cuenta_corriente;
            $this->suma_company_performances['total_devolucion']  += $company_performance->total_devolucion;
            $this->suma_company_performances['total_ingresos']    += $company_performance->total_ingresos;
            $this->suma_company_performances['cantidad_ventas']   += $company_performance->cantidad_ventas;
            $this->suma_company_performances['total_gastos']  += $company_performance->total_gastos;
            $this->suma_company_performances['total_comprado']    += $company_performance->total_comprado;


            $this->sumar_ingresos($company_performance, 'ingresos_mostrador');

            $this->sumar_ingresos($company_performance, 'ingresos_cuenta_corriente');

            $this->sumar_ingresos($company_performance, 'gastos');

            

            // $this->suma_company_performances['ingresos_cuenta_corriente'] = [];
            // foreach ($company_performance->ingresos_cuenta_corriente as $ingresos_cuenta_corriente) {
            //     $this->suma_company_performances['ingresos_cuenta_corriente'][] = $ingresos_cuenta_corriente;
            // }

            // $this->suma_company_performances['expense_concepts'] = [];
            // foreach ($company_performance->expense_concepts as $expense_concepts) {
            //     $this->suma_company_performances['expense_concepts'][] = $expense_concepts;
            // }

            // Aca tengo que sumar tambien el array de pagos
        }

        $this->format_ingresos('ingresos_mostrador');
        $this->format_ingresos('ingresos_cuenta_corriente');

    }

    function format_ingresos($ingresos_name) {
        $ingresos = [];
        foreach ($this->suma_company_performances[$ingresos_name] as $ingreso) {
            $ingresos[] = $ingreso;
        }
        $this->suma_company_performances[$ingresos_name] = $ingresos;
    }

    function set_payment_methods() {
        $this->payment_methods = CurrentAcountPaymentMethod::all();
    }

    function set_expense_concepts() {
        $this->expense_concepts = ExpenseConcept::where('user_id', $this->user_id)
                                ->get();
    }

    function init_relation($relations, $ingresos_name) {
    	
    	$relations_result = [];

    	foreach ($this->{$relations} as $relation) {
    		$payment_method_to_add = $relation->toArray();
    		$payment_method_to_add['pivot'] = [
    			'amount'	=> 0
    		];
    		$relations_result[$relation->id] = $payment_method_to_add;
    	}

    	$this->suma_company_performances[$ingresos_name] = $relations_result;
    }

    function sumar_relation($company_performance, $relation_name, $ingresos_name) {
        foreach ($company_performance->{$ingresos_name} as $payment_method) {

            $this->suma_company_performances[$ingresos_name][$payment_method->id]['pivot']['amount'] += $payment_method->pivot->amount;
        }
    }

}