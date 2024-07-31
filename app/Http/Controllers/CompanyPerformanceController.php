<?php

namespace App\Http\Controllers;

/* 
    * Este lo uso para agrupar los company_performance de distintos meses
        y sumar todos en uno solo
*/
use App\Http\Controllers\Helpers\CompanyPerformanceHelper;



/* 
    * Este lo uso para crear un company_performance de un mes en especifico
*/
use App\Http\Controllers\Helpers\PerformanceHelper;



use App\Models\CompanyPerformance;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CompanyPerformanceController extends Controller
{

    private $duracion_diaria = 1;

    function index($inicio = null, $fin = null) {

        if (!is_null($inicio) && !is_null($fin)) {

            $helper = new CompanyPerformanceHelper();

            $result = $helper->get_company_performances_from_dates($inicio, $fin);

            $meses_anteriores = $result['meses_anteriores'];
            
            $company_performance = $result['company_performance'];

            return response()->json(['model' => $company_performance, 'meses_anteriores' => $meses_anteriores], 201);
        } else {

            $this->check_tiempo_ultima_creada();

            $company_performance = $this->get_company_performance_today(true);

            return response()->json(['model' => $company_performance], 201);
        }

    }

    function check_tiempo_ultima_creada() {

        $current_company_performance = $this->get_company_performance_today();

        if (is_null($current_company_performance) || $current_company_performance->created_at->lt(Carbon::now()->subMinutes($this->duracion_diaria))) {

            if (!is_null($current_company_performance)) {

                $current_company_performance->delete();
            }
            

            $performance_helper = new PerformanceHelper(null, null, $this->userId());

            $performance_helper->create_company_performance();
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
    
    /*
        * Este metodo es llamado por el command "set_company_performances"
        * El metodo create_company_performance de la clase PerformanceHelper 
            es el que hace realmente el trabajo duro
    */
    function create($month, $year, $user_id) {

        $performance_helper = new PerformanceHelper($month, $year, $user_id);

        $performance_helper->create_company_performance();

    }

}
