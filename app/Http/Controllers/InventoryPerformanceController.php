<?php

namespace App\Http\Controllers;

use App\Exports\ArticleStockMinimoExport;
use App\Http\Controllers\Helpers\inventoryPerformance\InventoryPerformanceHelper;
use App\Models\Article;
use App\Models\InventoryPerformance;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\Facades\Excel;

class InventoryPerformanceController extends Controller
{
    /**
     * Endpoint no bloqueante con semántica stale-while-revalidate: nunca genera el reporte
     * dentro del request. Responde al instante con el último reporte disponible (sólo sus
     * contadores, sin la relación pesada de artículos).
     *
     * Desde la 4.0.24 (misión optimizacion-vps-fase1) ya NO regenera por edad en cada entrada:
     * el reporte lo genera de noche `inventario:generar` (Kernel, 04:00) y a pedido `generate()`
     * (el botón Actualizar). Acá sólo se encola si no hay ningún reporte o si el último tiene más
     * de 7 días — la red de seguridad de InventoryPerformanceHelper::debe_regenerar(), que explica
     * el porqué del plazo. La forma de la respuesta no cambia (`models`, `generating`); el
     * "actualizado hace N horas" lo calcula la SPA con el created_at, que ya viaja.
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
            'generating' => InventoryPerformanceHelper::esta_generando($user_id),
        ], 200);
    }

    /**
     * POST inventory-performance/generate (4.0.24): el botón Actualizar del modal de inventario y
     * de la lista de stock mínimo. Encola la generación con el mismo candado atómico que index() y
     * que el comando nocturno; si ya había una en curso no encola otra y responde lo mismo, así que
     * apretar el botón dos veces (o desde dos pestañas) es inocuo. El aviso de "terminó" le llega a
     * la SPA por el mismo broadcast de siempre (InventoryPerformanceGenerated, desde el job).
     *
     * @return \Illuminate\Http\JsonResponse { generating: true }
     */
    function generate() {

        $this->dispatch_generacion($this->userId());

        return response()->json(['generating' => true], 200);
    }

    /**
     * Delegado en el helper para que index(), generate() y el comando nocturno compartan un solo
     * criterio. Ya no lee `duracion_reporte_inventario`: el porqué de los 7 días fijos está en
     * InventoryPerformanceHelper::debe_regenerar().
     *
     * @param  InventoryPerformance|null $inventory_performance
     * @return bool
     */
    function debe_regenerar($inventory_performance) {

        return InventoryPerformanceHelper::debe_regenerar($inventory_performance);
    }

    /**
     * Encola la regeneración del reporte sólo si no hay ya una generación en curso: el candado
     * atómico (Cache::add) vive en el helper, compartido con el comando inventario:generar.
     *
     * @param  int $user_id
     * @return void
     */
    function dispatch_generacion($user_id) {

        InventoryPerformanceHelper::encolar_generacion($user_id);
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
