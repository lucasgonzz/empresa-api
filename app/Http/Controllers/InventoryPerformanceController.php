<?php

namespace App\Http\Controllers;

use App\Exports\ArticleStockMinimoExport;
use App\Http\Controllers\Helpers\inventoryPerformance\InventoryPerformanceHelper;
use App\Models\Article;
use App\Http\Controllers\Helpers\inventoryPerformance\StockMinimoConsulta;
use App\Models\InventoryPerformance;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\Facades\Excel;

class InventoryPerformanceController extends Controller
{
    /**
     * Endpoint de sólo lectura: nunca genera ni encola nada dentro del request. Responde al
     * instante con el último reporte disponible (sólo sus contadores, sin la relación pesada de
     * artículos), o `null` si el comercio nunca generó uno.
     *
     * Desde la misión reporte-inventario-manual (15/9/2026) esto ya no dispara una regeneración
     * por antigüedad (se sacó la red de seguridad de 7 días que había acá): en horario comercial,
     * con el servidor en uso, es exactamente cuando NO conviene disparar una corrida que en
     * catálogos grandes tarda 18-20 minutos. Las únicas dos formas de generar el reporte son la
     * corrida nocturna (`inventario:generar`, Kernel, 04:00 — con el servidor libre) y `generate()`,
     * el botón Actualizar. Un comercio sin reporte (recién dado de alta, por ejemplo) se queda sin
     * reporte hasta la próxima 04:00 o hasta que alguien lo pida a mano.
     */
    function index(Request $request) {

        $user_id = $this->userId();

        // Sin withAll(): sólo se devuelven los contadores (stock_minimo, sin_stock, valores).
        // La campana cuenta todo el alcance autorizado, nunca el filtro temporal del listado.
        $consulta = new StockMinimoConsulta($request->user(), $user_id, new Request());
        $inventory_performance = $consulta->reporte();
        if (!is_null($inventory_performance)) {
            $resumen = $consulta->resumen($inventory_performance);
            $inventory_performance->stock_minimo = $resumen['stock_minimo'];
        }
        if ($consulta->restricted && !is_null($inventory_performance)) {
            // El reporte almacenado es del dueño: no serializar sus valuaciones ni porcentajes globales.
            $seguro = $inventory_performance->only(['id', 'user_id', 'created_at', 'updated_at']);
            foreach ($inventory_performance->getAttributes() as $key => $value) {
                if (!array_key_exists($key, $seguro)) { $seguro[$key] = null; }
            }
            $inventory_performance = array_merge($seguro, $resumen);
        }

        return response()->json([
            'models'     => [$inventory_performance],
            'generating' => InventoryPerformanceHelper::esta_generando($user_id),
            'stock_minimo_scope' => $consulta->metadata(),
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
     * Encola la regeneración del reporte sólo si no hay ya una generación en curso: el candado
     * atómico (Cache::add) vive en el helper, compartido con el comando inventario:generar.
     *
     * @param  int $user_id
     * @return void
     */
    function dispatch_generacion($user_id) {

        // Quién apretó el botón (dueño o empleado): con eso el reporte aparece en la píldora de
        // procesos. El comando nocturno no lo pasa y por eso no se registra (misión
        // procesos-en-segundo-plano, 18/9/2026).
        InventoryPerformanceHelper::encolar_generacion($user_id, $this->userId(false));
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

    function stock_minimo_excel(Request $request) {

        $consulta = new StockMinimoConsulta($request->user(), $this->userId(), $request);

        return Excel::download(new ArticleStockMinimoExport($consulta), 'articulos_stock_minimo'.date_format(Carbon::now(), 'd-m-y').'.xlsx');
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
        $consulta = new StockMinimoConsulta($request->user(), $this->userId(), $request);
        $inventory_performance = $consulta->reporte();

        if (is_null($inventory_performance)) {

            return response()->json(['models' => [], 'scope' => $consulta->metadata(), 'summary' => $consulta->resumen(null)], 200);
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

        $query = $consulta->query($inventory_performance)
                    ->select($columns)
                    // Relaciones que las columnas configurables del frontend pueden mostrar
                    // (props_to_show + propertyText). Sin este eager loading serían N
                    // queries extra por cada artículo de la página.
                    ->with(['category', 'sub_category', 'provider', 'brand', 'images']);

        $models = $query->orderBy('articles.id')->orderBy('article_inventory_performance.address_id')->paginate($per_page);
        $models->getCollection()->transform(function ($article) use ($consulta) { return $consulta->preparar_fila($article); });

        return response()->json(['models' => $models, 'scope' => $consulta->metadata(), 'summary' => $consulta->resumen($inventory_performance)], 200);
    }
}
