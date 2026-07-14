<?php

namespace App\Http\Controllers;

use App\Exports\ArticleStockMinimoExport;
use App\Jobs\ProcessInventoryPerformanceJob;
use App\Models\InventoryPerformance;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Facades\Excel;

class InventoryPerformanceController extends Controller
{
    /**
     * Endpoint no bloqueante con semántica stale-while-revalidate: nunca genera el reporte
     * dentro del request. Responde al instante con el último reporte disponible (sólo sus
     * contadores, sin la relación pesada de artículos) y, si está vencido, encola la
     * regeneración en segundo plano.
     */
    function index() {

        $user_id = $this->userId();

        // Sin withAll(): sólo se devuelven los contadores (stock_minimo, sin_stock, valores).
        $inventory_performance = $this->get_created_inventory_performance(false);

        if ($this->debe_regenerar($inventory_performance)) {

            $this->dispatch_generacion($user_id);
        }

        return response()->json([
            'models'     => [$inventory_performance],
            'generating' => Cache::has('inventory_performance_generating_'.$user_id),
        ], 200);
    }

    /**
     * Determina si el reporte debe regenerarse: cuando no existe todavía o cuando su
     * created_at es anterior a la vigencia configurada por el owner.
     *
     * @param  InventoryPerformance|null $inventory_performance
     * @return bool
     */
    function debe_regenerar($inventory_performance) {

        if (is_null($inventory_performance)) {

            return true;
        }

        // Minutos de vigencia del reporte, configurados por owner (columna duracion_reporte_inventario).
        // Si viniera null o menor a 1, se usa 30 como valor por defecto seguro.
        $user = User::find($this->userId());

        $duracion = (!is_null($user) && !is_null($user->duracion_reporte_inventario) && $user->duracion_reporte_inventario >= 1)
                        ? $user->duracion_reporte_inventario
                        : 30;

        return $inventory_performance->created_at->lt(Carbon::now()->subMinutes($duracion));
    }

    /**
     * Encola la regeneración del reporte sólo si no hay ya una generación en curso.
     * Cache::add() es atómico en cualquier driver y devuelve false si la clave ya existía,
     * evitando que varios admins entrando a la vez disparen varios jobs en paralelo.
     *
     * @param  int $user_id
     * @return void
     */
    function dispatch_generacion($user_id) {

        $key = 'inventory_performance_generating_'.$user_id;

        // El TTL de 60 minutos es una red de seguridad por si el worker muere sin liberar el candado.
        if (Cache::add($key, true, Carbon::now()->addMinutes(60))) {

            ProcessInventoryPerformanceJob::dispatch($user_id);
        }
    }

    function get_created_inventory_performance($with_all = false) {

        $inventory_performance = InventoryPerformance::where('user_id', $this->userId())
                                    ->orderBy('created_at', 'DESC');
        if ($with_all) {
            $inventory_performance = $inventory_performance->withAll();
        }
                                    
        $inventory_performance = $inventory_performance->first();
        
        return $inventory_performance;
    }

    function stock_minimo_excel() {
        
        return Excel::download(new ArticleStockMinimoExport(), 'articulos_stock_minimo'.date_format(Carbon::now(), 'd-m-y').'.xlsx');
    }
}
