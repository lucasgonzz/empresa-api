<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\CommonLaravel\Helpers\UserHelper;
use App\Http\Controllers\Helpers\CompanyPerformanceUsersAddressesPaymentMethodsHelper as PaymentMethodsHelper;
use App\Http\Controllers\Helpers\PerformanceHelper;
use App\Models\Address;
use App\Models\CompanyPerformance;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\ExpenseConcept;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CompanyPerformanceHelper {

    function __construct() {
        
        $this->user_id = UserHelper::userId(); 
    }

	function set_fechas($fecha_inicio, $fecha_fin) {

        $ano_inicio = explode('-', $fecha_inicio)[0];
        $mes_inicio = explode('-', $fecha_inicio)[1];

        if (!is_null($fecha_fin)) {

            $ano_fin = explode('-', $fecha_fin)[0];
            $mes_fin = explode('-', $fecha_fin)[1];
        } else {

            $ano_fin = explode('-', $fecha_inicio)[0];
            $mes_fin = explode('-', $fecha_inicio)[1];
        } 


        $this->fecha_inicio = Carbon::create($ano_inicio, $mes_inicio, 1);
        $this->fecha_fin = Carbon::create($ano_fin, $mes_fin, 1);
	}

    function create_company_performance_from_date($fecha_inicio) {

        $this->set_fechas($fecha_inicio, null);

        $this->delete_current_company_performance_from_date($fecha_inicio);

        $performance_helper = new PerformanceHelper(
            $this->fecha_inicio->month, 
            $this->fecha_inicio->year, 
            $this->user_id,
            $fecha_inicio,
        );

        $performance_helper->create_company_performance();

    }

    function delete_current_company_performance_from_date($fecha_inicio) {

        $fecha_inicio = Carbon::parse($fecha_inicio)->startOfDay();
        
        Log::info('buscando para borrar el:');
        Log::info('year: '.$fecha_inicio->year);
        Log::info('month: '.$fecha_inicio->month);
        Log::info('day: '.$fecha_inicio->day);


        $company_performance = CompanyPerformance::where('user_id', $this->user_id)
                                                    ->where('year', $fecha_inicio->year)
                                                    ->where('month', $fecha_inicio->month)
                                                    ->where('day', $fecha_inicio->day)
                                                    ->first();
        if (!is_null($company_performance)) {
            $company_performance->delete();
        }
    }
	
    function get_company_performances_from_dates($fecha_inicio, $fecha_fin) {
    	
        $this->set_fechas($fecha_inicio, $fecha_fin);

        $this->meses_anteriores = [];

        Carbon::setLocale('es');

        $mes_actual = Carbon::today()->startOfMonth();

        while ($this->fecha_inicio->lte($this->fecha_fin)) {

            if ($this->fecha_inicio->eq($mes_actual)) {

                Log::info('Entro al mes corriente');

                $this->crear_company_performance_del_mes_corriente();

            }


            // Log::info('Buscando company_performance del mes: '.$this->fecha_inicio->month.' año: '.$this->fecha_inicio->year);

            $company_performance = CompanyPerformance::where('user_id', $this->user_id)
                                ->where('year', $this->fecha_inicio->year)
                                ->where('month', $this->fecha_inicio->month)
                                ->withAll()
                                ->first();

            if (!is_null($company_performance)) {

                $company_performance->fecha = Carbon::create($company_performance->year, $company_performance->month, 1)->isoFormat('MMMM').' '.$company_performance->year;


                $helper = new PaymentMethodsHelper($company_performance, $this->user_id);

                $helper->set_users_relation();

                $helper->set_addresses_relation();

                $this->meses_anteriores[] = $company_performance;
            } else {
                Log::info('No habia para la fecha');
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
        $this->init_relation('payment_methods', 'gastos');
        $this->init_relation('expense_concepts', 'expense_concepts');

        $this->init_users_payment_methods_relation();
        $this->init_addresses_payment_methods_relation();

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


            $this->sumar_relation($company_performance, 'ingresos_mostrador');

            $this->sumar_relation($company_performance, 'ingresos_cuenta_corriente');

            $this->sumar_relation($company_performance, 'gastos');

            $this->sumar_relation($company_performance, 'expense_concepts');

            $this->sumar_payment_methods_relation($company_performance, 'users_payment_methods_formated', 'user');
            $this->sumar_payment_methods_relation($company_performance, 'addresses_payment_methods_formated', 'address');

        }

        $this->format_ingresos('ingresos_mostrador');
        $this->format_ingresos('ingresos_cuenta_corriente');
        $this->format_ingresos('gastos');
        $this->format_ingresos('expense_concepts');

        $this->format_ingresos('users_payment_methods_formated');
        $this->format_ingresos('addresses_payment_methods_formated');
        $this->format_payment_methods('users_payment_methods_formated');
        $this->format_payment_methods('addresses_payment_methods_formated');

    }

    function format_ingresos($ingresos_name) {
        $ingresos = [];
        foreach ($this->suma_company_performances[$ingresos_name] as $ingreso) {
            $ingresos[] = $ingreso;
        }
        $this->suma_company_performances[$ingresos_name] = $ingresos;
    }

    function format_payment_methods($relation_name) {

        $relations = [];

        foreach ($this->suma_company_performances[$relation_name] as $model_payment_methods) {

            $payment_methods = [];

            foreach ($model_payment_methods['payment_methods'] as $payment_method) {
                
                $payment_methods[] = $payment_method;
            }

            $model_payment_methods['payment_methods'] = $payment_methods;

            $relations[] = $model_payment_methods;
        }

        $this->suma_company_performances[$relation_name] = $relations;
    }

    function set_payment_methods() {
        $this->payment_methods = CurrentAcountPaymentMethod::all();
    }

    function set_expense_concepts() {
        $this->expense_concepts = ExpenseConcept::where('user_id', $this->user_id)
                                ->get();
    }

    function sumar_payment_methods_relation($company_performance, $relation_name, $model_name) {

        foreach ($company_performance->{$relation_name} as $model_payment_methods) {

            foreach ($model_payment_methods['payment_methods'] as $payment_method) {

                $this->suma_company_performances[$relation_name][$model_payment_methods[$model_name]['id']]['payment_methods'][$payment_method->id]['total'] += $payment_method->pivot->amount;

                $this->suma_company_performances[$relation_name][$model_payment_methods[$model_name]['id']]['total_vendido'] += $payment_method->pivot->amount;
            }
        }
    }

    function init_addresses_payment_methods_relation() {

        $addresses = Address::where('user_id', $this->user_id)
                            ->get();

        $addresses_payment_methods = [];

        $performance_helper = new PerformanceHelper(null, null, $this->user_id);

        foreach ($addresses as $address) {
                
            $addresses_payment_methods[$address->id] = [
                'payment_methods'   => $performance_helper->get_payment_methods(),
                'address'           => $address,
                'total_vendido'     => 0,
            ];
        }

        $this->suma_company_performances['addresses_payment_methods_formated'] = $addresses_payment_methods;
    }

    function init_users_payment_methods_relation() {

        $employees = User::where('owner_id', $this->user_id)
                            ->get();

        $users_payment_methods = [];

        $performance_helper = new PerformanceHelper(null, null, $this->user_id);


        foreach ($employees as $employee) {
                
            $users_payment_methods[$employee->id] = [
                'payment_methods'   => $performance_helper->get_payment_methods(),
                'user'              => $employee,
                'total_vendido'     => 0,
            ];
        }

        $users_payment_methods[$this->user_id] = [
            'payment_methods'   => $performance_helper->get_payment_methods(),
            'user'              => UserHelper::user(),
            'total_vendido'     => 0,
        ];

        $this->suma_company_performances['users_payment_methods_formated'] = $users_payment_methods;
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

    function sumar_relation($company_performance, $relation_name) {
        foreach ($company_performance->{$relation_name} as $payment_method) {

            $this->suma_company_performances[$relation_name][$payment_method->id]['pivot']['amount'] += $payment_method->pivot->amount;
        }
    }

    function crear_company_performance_del_mes_corriente() {

        $company_performance_mes_corriente = CompanyPerformance::where('user_id', $this->user_id)
                                                            ->where('year', $this->fecha_inicio->year)
                                                            ->where('month', $this->fecha_inicio->month)
                                                            ->first();

        // if (is_null($company_performance_mes_corriente) 
        //     || $company_performance_mes_corriente->created_at->lt(Carbon::now()->subMinutes(env('DURACION_REPORTES', 1)))) {

        if (!is_null($company_performance_mes_corriente)) {
            $company_performance_mes_corriente->delete();
        }
            

        Log::info('Creando company_performance para el mes corriente: mes: '.$this->fecha_inicio->month.' año: '.$this->fecha_inicio->year);

        $performance_helper = new PerformanceHelper(
            $this->fecha_inicio->month, 
            $this->fecha_inicio->year, 
            $this->user_id
        );

        $performance_helper->create_company_performance();
    }

}