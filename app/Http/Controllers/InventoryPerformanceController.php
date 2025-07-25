<?php

namespace App\Http\Controllers;

use App\Exports\ArticleStockMinimoExport;
use App\Http\Controllers\Helpers\inventoryPerformance\InventoryPerformanceHelper;
use App\Models\Article;
use App\Models\InventoryPerformance;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class InventoryPerformanceController extends Controller
{
    function index() {

        $inventory_performance = $this->get_current();

        return response()->json(['models' => [$inventory_performance]], 200);

    }

    function get_current() {

        $inventory_performance = InventoryPerformance::where('user_id', $this->userId())
                                    ->orderBy('created_at', 'DESC')
                                    ->first();

        if (is_null($inventory_performance) 
            || $inventory_performance->created_at->lt(Carbon::now()->subMinutes(env('DURACION_REPORTES', 1)))) {

            if (!is_null($inventory_performance)) {

                $inventory_performance->delete();
            }

            $helper = new InventoryPerformanceHelper();
            
            $inventory_performance = $helper->create();
        }

        return $inventory_performance;
    }

    function stock_minimo_excel() {
        
        return Excel::download(new ArticleStockMinimoExport(), 'articulos_stock_minimo'.date_format(Carbon::now(), 'd-m-y').'.xlsx');
    }
}
