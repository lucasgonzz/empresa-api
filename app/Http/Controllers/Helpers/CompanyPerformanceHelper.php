<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\UserHelper;
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

  /**
   * Normaliza el rango a instancias Carbon con inicio del día y fin del día.
   * Acepta fechas en formato Y-m-d (o Y-m para compatibilidad heredada).
   * Si inicio > fin, los intercambia automáticamente.
   *
   * @param string $fecha_inicio Fecha de inicio (Y-m-d o Y-m)
   * @param string $fecha_fin    Fecha de fin (Y-m-d o Y-m)
   */
	function set_fechas($fecha_inicio, $fecha_fin) {

        $this->fecha_inicio = Carbon::parse($fecha_inicio)->startOfDay();
        $this->fecha_fin    = Carbon::parse($fecha_fin)->endOfDay();

        /* Garantizar que inicio no sea posterior al fin */
        if ($this->fecha_inicio->gt($this->fecha_fin)) {
            $temporal           = $this->fecha_inicio->copy()->startOfDay();
            $this->fecha_inicio = $this->fecha_fin->copy()->startOfDay();
            $this->fecha_fin    = $temporal->endOfDay();
        }
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
	
  /**
   * Genera reportes calculados en tiempo real para el rango de fechas solicitado.
   * Itera mes a mes dentro del rango, recortando el primer y último mes si son parciales.
   * Cada segmento se calcula con PerformanceHelper usando from_day + until_day para precisión diaria.
   * Los registros temporales se eliminan tras usar los datos, sin afectar la caché de meses completos.
   *
   * @param string $fecha_inicio Fecha inicio en Y-m-d
   * @param string $fecha_fin    Fecha fin en Y-m-d
   */
    function get_company_performances_from_dates($fecha_inicio, $fecha_fin) {
    	
        $this->set_fechas($fecha_inicio, $fecha_fin);

        $this->meses_anteriores = [];

        Carbon::setLocale('es');

        /* Cursor que avanza mes a mes dentro del rango */
        $cursor_mes = $this->fecha_inicio->copy()->startOfMonth();

        while ($cursor_mes->startOfMonth()->lte($this->fecha_fin)) {

            $inicio_del_mes = $cursor_mes->copy()->startOfMonth()->startOfDay();
            $fin_del_mes    = $cursor_mes->copy()->endOfMonth()->endOfDay();

            /* Recortar el segmento al rango solicitado */
            $segmento_inicio = $this->fecha_inicio->greaterThan($inicio_del_mes)
                ? $this->fecha_inicio->copy()
                : $inicio_del_mes;

            $segmento_fin = $this->fecha_fin->lessThan($fin_del_mes)
                ? $this->fecha_fin->copy()
                : $fin_del_mes;

            Log::info('Calculando segmento: '.$segmento_inicio->format('d/m/Y').' → '.$segmento_fin->format('d/m/Y'));

            /* Calcular el reporte en tiempo real para el segmento */
            $performance_helper = new PerformanceHelper(
                $segmento_inicio->month,
                $segmento_inicio->year,
                $this->user_id,
                $segmento_inicio->format('Y-m-d'),
                false,
                $segmento_fin->format('Y-m-d')
            );

            $company_performance = $performance_helper->create_company_performance();

            $company_performance->fecha = $this->format_segment_label($segmento_inicio, $segmento_fin);

            $helper = new PaymentMethodsHelper($company_performance, $this->user_id);

            $helper->set_users_relation();

            $helper->set_addresses_relation();

            /* Buscar el snapshot de deuda más reciente disponible hasta el último día del segmento.
               Se usa el snapshot para no calcular la deuda en tiempo real en reportes históricos. */
            $snapshot = \App\Models\DebtSnapshot::where('user_id', $this->user_id)
                ->where('date', '<=', $segmento_fin->toDateString())
                ->orderBy('date', 'desc')
                ->first();

            /* Asignar los valores de deuda desde el snapshot (null si no hay snapshot disponible) */
            $company_performance->deuda_clientes        = $snapshot?->deuda_clientes ?? null;
            $company_performance->deuda_clientes_usd    = $snapshot?->deuda_clientes_usd ?? null;
            $company_performance->deuda_proveedores     = $snapshot?->deuda_proveedores ?? null;
            $company_performance->deuda_proveedores_usd = $snapshot?->deuda_proveedores_usd ?? null;

            /* Flag para que el frontend sepa si hay datos históricos de deuda para este segmento */
            $company_performance->snapshot_disponible = !is_null($snapshot);

            $this->meses_anteriores[] = $company_performance;

            /* Eliminar el registro temporal para no contaminar la caché de reportes */
            $company_performance->delete();

            $cursor_mes->addMonth()->startOfMonth();
        }

        $this->sumar_company_performances();

        return [
        	'meses_anteriores'	=> $this->meses_anteriores,
        	'company_performance'	=> $this->suma_company_performances,
        ];


    }

  /**
   * Genera la etiqueta de un segmento para mostrar en los gráficos.
   * Si el segmento cubre el mes completo, muestra "Mes Año".
   * Si es un único día, muestra "15 de marzo 2026".
   * Para rangos parciales muestra "15 mar → 31 mar 2026".
   *
   * @param \Carbon\Carbon $segmento_inicio
   * @param \Carbon\Carbon $segmento_fin
   * @return string
   */
    function format_segment_label($segmento_inicio, $segmento_fin) {

        $es_mes_completo = $segmento_inicio->isSameDay($segmento_inicio->copy()->startOfMonth())
            && $segmento_fin->isSameDay($segmento_fin->copy()->endOfMonth());

        if ($es_mes_completo) {
            return Carbon::create($segmento_inicio->year, $segmento_inicio->month, 1)->isoFormat('MMMM').' '.$segmento_inicio->year;
        }

        if ($segmento_inicio->isSameDay($segmento_fin)) {
            return $segmento_inicio->isoFormat('D [de] MMMM YYYY');
        }

        return $segmento_inicio->isoFormat('D MMM').' → '.$segmento_fin->isoFormat('D MMM YYYY');
    }

    function sumar_company_performances() {
        $this->suma_company_performances = [
            'total_vendido'                     => 0,
            'total_vendido_usd'                     => 0,
            'total_facturado'                   => 0,
            'total_pagado_a_proveedores'        => 0,
            'total_pagado_a_proveedores_usd'        => 0,
            'total_iva_comprado'                => 0,
            'total_vendido_costos'              => 0,
            'total_vendido_costos_usd'              => 0,
            'total_pagado_mostrador'            => 0,
            'total_pagado_mostrador_usd'            => 0,
            'total_vendido_a_cuenta_corriente'  => 0,
            'total_vendido_a_cuenta_corriente_usd'  => 0,
            'total_pagado_a_cuenta_corriente'   => 0,
            'total_pagado_a_cuenta_corriente_usd'   => 0,
            'total_devolucion'                  => 0,
            'total_devolucion_usd'                  => 0,
            'total_ingresos'                    => 0,
            'total_ingresos_usd'                    => 0,
            'cantidad_ventas'                   => 0,
            'total_gastos'                      => 0,
            'total_gastos_usd'                      => 0,
            'total_comprado'                    => 0,
            'total_comprado_usd'                    => 0,
            'ingresos_netos'                    => 0,
            'ingresos_netos_usd'                    => 0,
            'rentabilidad'                      => 0,
            'rentabilidad_usd'                      => 0,
            'company_performance_info_facturacion'  => [],

            /* Deudas: son un stock (no un flujo), se toma inicio y fin del período.
               Inicio = snapshot del primer segmento con datos; fin = snapshot del último segmento con datos. */
            'deuda_clientes_inicio'             => null,
            'deuda_clientes_fin'                => null,
            'deuda_clientes_usd_inicio'         => null,
            'deuda_clientes_usd_fin'            => null,
            'deuda_proveedores_inicio'          => null,
            'deuda_proveedores_fin'             => null,
            'deuda_proveedores_usd_inicio'      => null,
            'deuda_proveedores_usd_fin'         => null,

            /* true si al menos un segmento tiene snapshot de deuda disponible */
            'snapshot_disponible'               => false,
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
            $this->suma_company_performances['total_vendido_usd']     += $company_performance->total_vendido_usd;
            
            $this->suma_company_performances['total_facturado']     += $company_performance->total_facturado;

            $this->suma_company_performances['total_pagado_a_proveedores']     += $company_performance->total_pagado_a_proveedores;
            $this->suma_company_performances['total_pagado_a_proveedores_usd']     += $company_performance->total_pagado_a_proveedores_usd;

            $this->suma_company_performances['total_iva_comprado']     += $company_performance->total_iva_comprado;
            
            $this->suma_company_performances['total_vendido_costos']     += $company_performance->total_vendido_costos;
            $this->suma_company_performances['total_vendido_costos_usd']     += $company_performance->total_vendido_costos_usd;
            
            $this->suma_company_performances['total_pagado_mostrador']    += $company_performance->total_pagado_mostrador;
            $this->suma_company_performances['total_pagado_mostrador_usd']    += $company_performance->total_pagado_mostrador_usd;
            
            $this->suma_company_performances['total_vendido_a_cuenta_corriente']  += $company_performance->total_vendido_a_cuenta_corriente;
            $this->suma_company_performances['total_vendido_a_cuenta_corriente_usd']  += $company_performance->total_vendido_a_cuenta_corriente_usd;
            
            $this->suma_company_performances['total_pagado_a_cuenta_corriente']   += $company_performance->total_pagado_a_cuenta_corriente;
            $this->suma_company_performances['total_pagado_a_cuenta_corriente_usd']   += $company_performance->total_pagado_a_cuenta_corriente_usd;
            
            $this->suma_company_performances['total_devolucion']  += $company_performance->total_devolucion;
            $this->suma_company_performances['total_devolucion_usd']  += $company_performance->total_devolucion_usd;
            
            $this->suma_company_performances['total_ingresos']    += $company_performance->total_ingresos;
            $this->suma_company_performances['total_ingresos_usd']    += $company_performance->total_ingresos_usd;
            
            $this->suma_company_performances['cantidad_ventas']   += $company_performance->cantidad_ventas;
            
            $this->suma_company_performances['total_gastos']      += $company_performance->total_gastos;
            $this->suma_company_performances['total_gastos_usd']      += $company_performance->total_gastos_usd;
            
            $this->suma_company_performances['total_comprado']    += $company_performance->total_comprado;
            $this->suma_company_performances['total_comprado_usd']    += $company_performance->total_comprado_usd;

            $this->suma_company_performances['ingresos_netos']    += $company_performance->ingresos_netos;
            $this->suma_company_performances['ingresos_netos_usd']    += $company_performance->ingresos_netos_usd;

            $this->suma_company_performances['rentabilidad']      += $company_performance->rentabilidad;
            $this->suma_company_performances['rentabilidad_usd']      += $company_performance->rentabilidad_usd;


            $this->sumar_relation($company_performance, 'ingresos_mostrador');

            $this->sumar_relation($company_performance, 'ingresos_cuenta_corriente');

            $this->sumar_relation($company_performance, 'gastos');

            $this->sumar_relation($company_performance, 'expense_concepts');

            $this->sumar_payment_methods_relation($company_performance, 'users_payment_methods_formated', 'user');
            $this->sumar_payment_methods_relation($company_performance, 'addresses_payment_methods_formated', 'address');

            /* Deudas: son un stock (no un flujo), se registra inicio y fin del período.
               Solo se actualiza con segmentos que tienen snapshot disponible.
               El valor de inicio se toma del primer segmento con snapshot; el de fin, del último. */
            if ($company_performance->snapshot_disponible) {

                /* Marcar que el resumen tiene al menos un segmento con snapshot */
                $this->suma_company_performances['snapshot_disponible'] = true;

                /* Inicio: se fija con el primer segmento que tenga snapshot (solo si aún es null) */
                if (is_null($this->suma_company_performances['deuda_clientes_inicio'])) {
                    $this->suma_company_performances['deuda_clientes_inicio']        = $company_performance->deuda_clientes;
                    $this->suma_company_performances['deuda_clientes_usd_inicio']    = $company_performance->deuda_clientes_usd;
                    $this->suma_company_performances['deuda_proveedores_inicio']     = $company_performance->deuda_proveedores;
                    $this->suma_company_performances['deuda_proveedores_usd_inicio'] = $company_performance->deuda_proveedores_usd;
                }

                /* Fin: se actualiza con cada segmento con snapshot (el último sobreescribe) */
                $this->suma_company_performances['deuda_clientes_fin']        = $company_performance->deuda_clientes;
                $this->suma_company_performances['deuda_clientes_usd_fin']    = $company_performance->deuda_clientes_usd;
                $this->suma_company_performances['deuda_proveedores_fin']     = $company_performance->deuda_proveedores;
                $this->suma_company_performances['deuda_proveedores_usd_fin'] = $company_performance->deuda_proveedores_usd;
            }

            // $this->sumar_info_facturacion($company_performance);

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

    // function sumar_info_facturacion($company_performance) {
    //     foreach ($company_performance->company_performance_info_facturacion as $info_facturacion) {
            
    //         $this->suma_company_performances['info_facturacion'][]      += $company_performance->total_gastos;
    //     }

    // }

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

            if (isset($this->suma_company_performances[$relation_name][$payment_method->id])) {

                $this->suma_company_performances[$relation_name][$payment_method->id]['pivot']['amount'] += $payment_method->pivot->amount;
            }

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
            $this->user_id,
            null,

            // Este true hace referencia a que es el mes actual, para que no se creen los article_performances 
            true
        );

        $performance_helper->create_company_performance();
    }

}