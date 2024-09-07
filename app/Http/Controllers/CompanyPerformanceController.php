<?php

namespace App\Http\Controllers;

/* 
    * Este lo uso para agrupar los company_performance de distintos meses
        y sumar todos en uno solo
*/
use App\Http\Controllers\Helpers\CompanyPerformanceHelper;
use App\Http\Controllers\Helpers\CompanyPerformanceUsersAddressesPaymentMethodsHelper as PaymentMethodsHelper;
use App\Http\Controllers\Helpers\PerformanceHelper;
use App\Models\CompanyPerformance;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CompanyPerformanceController extends Controller
{

    function index($inicio = null, $fin = null) {

        if (!is_null($inicio)) {

            $helper = new CompanyPerformanceHelper();
            
            if (!is_null($fin)) {

                $result = $helper->get_company_performances_from_dates($inicio, $fin);

                $meses_anteriores = $result['meses_anteriores'];
                
                $company_performance = $result['company_performance'];

                return response()->json(['model' => $company_performance, 'meses_anteriores' => $meses_anteriores], 201);
            } else {

                $helper->create_company_performance_from_date($inicio);

                $company_performance = $this->get_company_performance_from_date($inicio);

                $helper = new PaymentMethodsHelper($company_performance, $this->userId());

                $helper->set_users_relation();

                $helper->set_addresses_relation();

                return response()->json(['model' => $company_performance, 'from_date' => $inicio], 201);
            }

        } else {

            $this->check_tiempo_ultima_creada();

            $company_performance = $this->get_company_performance_today(true);

            $helper = new PaymentMethodsHelper($company_performance, $this->userId());

            $helper->set_users_relation();

            $helper->set_addresses_relation();

            return response()->json(['model' => $company_performance], 201);
        }

    }

    function check_tiempo_ultima_creada() {

        $current_company_performance = $this->get_company_performance_today();

        if (is_null($current_company_performance) || $current_company_performance->created_at->lt(Carbon::now()->subMinutes(env('DURACION_REPORTES', 1)))) {

            if (!is_null($current_company_performance)) {

                $current_company_performance->delete();
            }
            

            $performance_helper = new PerformanceHelper(null, null, $this->userId());

            $performance_helper->create_company_performance();
        } 

    }

    function get_company_performance_from_date($inicio) {

        $year = explode('-', $inicio)[0];
        $month = explode('-', $inicio)[1];
        $day = explode('-', $inicio)[2];

        $company_performance = CompanyPerformance::where('user_id', $this->userId())
                                                    ->where('year', $year)
                                                    ->where('month', $month)
                                                    ->where('day', $day)
                                                    ->withAll()
                                                    ->first();

        return $company_performance;
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
    
    /*
        * Este metodo es llamado por el command "set_company_performances"
        * El metodo create_company_performance de la clase PerformanceHelper 
            es el que hace realmente el trabajo duro
    */
    function create($month, $year, $user_id) {

        $performance_helper = new PerformanceHelper($month, $year, $user_id);

        $performance_helper->create_company_performance();

    }

    function borrar_los_realizados_durante_el_mes($month, $year, $user_id) {

        $company_performances = CompanyPerformance::where('user_id', $user_id)
                            ->where('month', $month)
                            ->where('year', $year)
                            ->get();

        foreach ($company_performances as $company_performance) {
            
            $company_performance->delete();
        }

    }

}
