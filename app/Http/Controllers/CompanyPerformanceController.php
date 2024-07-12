<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\CompanyPerformanceHelper;
use App\Http\Controllers\Helpers\PerformanceHelper;
use App\Models\CompanyPerformance;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CompanyPerformanceController extends Controller
{

    function index($inicio = null, $fin = null) {

        if (!is_null($inicio) && !is_null($fin)) {

            $helper = new CompanyPerformanceHelper();

            $result = $helper->get_company_performances_from_dates($inicio, $fin);

            $meses_anteriores = $result['meses_anteriores'];
            
            $company_performance = $result['company_performance'];

            return response()->json(['model' => $company_performance, 'meses_anteriores' => $meses_anteriores], 201);
        } else {
            
            $this->delete_current_company_performance();

            $performance_helper = new PerformanceHelper(null, null, $this->userId());

            $performance_helper->create_company_performance();

            $company_performance = $this->get_company_performance_today(true);

            return response()->json(['model' => $company_performance], 201);
        }

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
