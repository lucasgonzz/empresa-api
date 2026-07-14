<?php

namespace App\Http\Controllers;

use App\Exports\ArticleStockMinimoExport;
use App\Jobs\ProcessInventoryPerformanceJob;
use App\Models\Article;
use App\Models\InventoryPerformance;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
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

    /**
     * Endpoint paginado de artículos bajo el stock mínimo del último reporte generado.
     * Reemplaza el envío de todos los artículos dentro del JSON del reporte (index()):
     * hay cuentas con decenas de miles de artículos bajo el mínimo, y devolverlos todos
     * en cada login movía el problema de OOM del servidor al navegador.
     *
     * Query params soportados:
     * - page: página a devolver (lo resuelve Laravel automáticamente desde ?page=).
     * - per_page: cantidad de resultados por página (default 25, tope 200 para blindar
     *   contra un ?per_page=999999 que vuelva a traer todo de una).
     * - search: texto opcional para filtrar por nombre, código de barras o código de proveedor.
     *
     * @param  Request $request
     * @return \Illuminate\Http\JsonResponse { models: paginador de artículos bajo el mínimo }
     */
    function articles_stock_minimo(Request $request) {

        // Último reporte generado para el usuario autenticado (sin cargar la relación
        // pesada de artículos: acá se pagina aparte).
        $inventory_performance = $this->get_created_inventory_performance();

        if (is_null($inventory_performance)) {

            return response()->json(['models' => []], 200);
        }

        // per_page configurable por el frontend, con tope de 200 para evitar que alguien
        // pida todo el listado de una sola vez desde la query string.
        $per_page = $request->filled('per_page') ? min((int) $request->per_page, 200) : 25;

        // Columnas del artículo excluyendo 'embedding' (vector de 1536 floats que no
        // aporta nada acá), prefijadas con 'articles.' porque esta consulta hace join
        // contra la tabla pivot y sin el prefijo MySQL tira "Column 'id' ambiguous".
        $columns = collect(Schema::getColumnListing((new Article)->getTable()))
                    ->reject(function ($column) {
                        return in_array($column, ['embedding'], true);
                    })
                    ->map(function ($column) {
                        return 'articles.'.$column;
                    })
                    ->values()
                    ->all();

        $query = $inventory_performance->articles_stock_minimo()
                    ->select($columns)
                    // Relaciones que las columnas configurables del frontend pueden mostrar
                    // (props_to_show + propertyText). Sin este eager loading serían N
                    // queries extra por cada artículo de la página.
                    ->with(['category', 'sub_category', 'provider', 'brand', 'images']);

        // Buscador opcional por nombre, código de barras o código de proveedor.
        if ($request->filled('search')) {

            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->where('articles.name', 'LIKE', '%'.$search.'%')
                  ->orWhere('articles.bar_code', 'LIKE', '%'.$search.'%')
                  ->orWhere('articles.provider_code', 'LIKE', '%'.$search.'%');
            });
        }

        $models = $query->paginate($per_page);

        return response()->json(['models' => $models], 200);
    }
}
