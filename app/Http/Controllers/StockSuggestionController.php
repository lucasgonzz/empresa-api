<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\DepositMovementHelper;
use App\Jobs\GenerarResumenSugerenciaJob;
use App\Jobs\GenerateStockSuggestionChunksJob;
use App\Models\Article;
use App\Models\DepositMovement;
use App\Models\StockSuggestion;
use App\Models\StockSuggestionArticle;
use App\Services\StockSuggestion\ResumenIaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockSuggestionController extends Controller
{

    public function index() {
        $models = StockSuggestion::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = StockSuggestion::create([
            'modo'                  => $request->modo,
            'origen'                => $request->origen,
            'limite_origen'         => $request->limite_origen,
            'status'                => 'pendiente',
            'origen_generacion'     => 'manual',
            'user_id'               => $this->userId(),
        ]);

        $this->despachar_procesamiento($model);

        return response()->json(['model' => $this->fullModel('StockSuggestion', $model->id)], 201);
    }

    public function show($id) {
        // Filtro de dueño en show/update/destroy/create_deposit_movement,
        // mismo criterio que index() y articles(): sin él, cualquier id ajeno
        // se leía y —peor— el update reprocesador agrandó el radio de daño de
        // un id ajeno (borraba y recalculaba la sugerencia de otro comercio).
        $model = $this->sugerencia_del_usuario($id);

        if (!$model) {
            return response()->json(['message' => 'Sugerencia no encontrada.'], 404);
        }

        return response()->json(['model' => $this->fullModel('StockSuggestion', $model->id)], 200);
    }

    /**
     * Editar una sugerencia REPROCESA: guarda los parámetros nuevos, borra las
     * líneas viejas y vuelve a calcular. Editar sin reprocesar producía una
     * sugerencia que decía una cosa (parámetros) y mostraba otra (líneas
     * calculadas con los parámetros anteriores).
     */
    public function update(Request $request, $id) {
        $model = $this->sugerencia_del_usuario($id);

        if (!$model) {
            return response()->json(['message' => 'Sugerencia no encontrada.'], 404);
        }

        // Una sugerencia en curso no se edita: reprocesar acá encolaría una
        // segunda corrida de la misma sugerencia pisándose con la primera.
        if ($model->status === 'pendiente') {
            return response()->json(['message' => 'La sugerencia se está generando. Esperá a que termine para editarla.'], 422);
        }

        DB::transaction(function () use ($model, $request) {
            StockSuggestionArticle::where('stock_suggestion_id', $model->id)->delete();

            $model->modo                = $request->modo;
            $model->origen              = $request->origen;
            $model->limite_origen       = $request->limite_origen;
            $model->status              = 'pendiente';
            $model->total_chunks        = 0;
            $model->processed_chunks    = 0;
            $model->resumen_ia          = null;
            $model->resumen_ia_estado   = null;
            $model->resumen_ia_error    = null;
            $model->error_mensaje       = null;
            $model->save();
        });

        $this->despachar_procesamiento($model);

        return response()->json(['model' => $this->fullModel('StockSuggestion', $model->id)], 200);
    }

    public function destroy($id) {
        $model = $this->sugerencia_del_usuario($id);

        if (!$model) {
            return response()->json(['message' => 'Sugerencia no encontrada.'], 404);
        }

        // Una sugerencia en curso no se borra: sacarle el modelo de abajo a
        // los chunks que la están escribiendo deja la corrida manoteando ids
        // que ya no existen.
        if ($model->status === 'pendiente') {
            return response()->json(['message' => 'La sugerencia se está generando. Esperá a que termine para borrarla.'], 422);
        }

        ImageController::deleteModelImages($model);

        // Las líneas van en la misma transacción que la cabecera: antes
        // quedaban huérfanas y engordaban una tabla que nadie purgaba.
        DB::transaction(function () use ($model) {
            StockSuggestionArticle::where('stock_suggestion_id', $model->id)->delete();
            $model->delete();
        });

        $this->sendDeleteModelNotification('StockSuggestion', $model->id);
        return response(null);
    }

    /**
     * Líneas de una sugerencia, paginadas server-side y filtrables por
     * origen/destino. Es el contrato de la vista nueva (v2): el POST
     * stock-suggestion-article viejo queda intacto para el flujo de modales.
     *
     * @param Request $request page, per_page (default 50, techo 500),
     *                         from_address_id, to_address_id,
     *                         order ('prioridad' | 'cantidad')
     * @param int $id
     * @return \Illuminate\Http\JsonResponse {'models': paginador}
     */
    public function articles(Request $request, $id) {
        $suggestion = StockSuggestion::where('user_id', $this->userId())
            ->where('id', $id)
            ->first();

        if (!$suggestion) {
            return response()->json(['message' => 'Sugerencia no encontrada.'], 404);
        }

        // Mismo contrato de per_page que ProviderController@index: default si
        // no viene o es inválido, techo para que nadie pida la tabla entera.
        $per_page = (int) $request->input('per_page', 50);
        if ($per_page <= 0) {
            $per_page = 50;
        }
        if ($per_page > 500) {
            $per_page = 500;
        }

        $query = StockSuggestionArticle::with(['article', 'from_address', 'to_address'])
            ->where('stock_suggestion_id', $suggestion->id);

        if ($request->filled('from_address_id')) {
            $query->where('from_address_id', $request->from_address_id);
        }

        if ($request->filled('to_address_id')) {
            $query->where('to_address_id', $request->to_address_id);
        }

        if ($request->input('order') === 'cantidad') {
            $query->orderBy('suggested_amount', 'DESC');
        } else {
            // Orden default por urgencia. NULLs al final: las líneas de
            // sugerencias anteriores a la v2 no tienen prioridad calculada.
            $query->orderByRaw('prioridad IS NULL ASC')
                ->orderBy('prioridad', 'ASC');
        }
        $query->orderBy('id', 'ASC');

        $models = $query->paginate($per_page);

        // La clave del nombre es 'name': es lo que la tabla espera desde
        // siempre (el endpoint viejo mandaba 'article' y la columna Nombre
        // quedaba vacía — acá el contrato nace arreglado).
        $models->getCollection()->transform(function ($item) {
            $article = $item->article;
            $from_address = $item->from_address;
            $to_address = $item->to_address;

            return [
                'stock_suggestion_article_id' => $item->id,
                'article_id' => !empty($item->article_id) ? $item->article_id : 0,
                'name' => $article && !empty($article->name) ? $article->name : '',
                'provider_code' => $article && !empty($article->provider_code) ? $article->provider_code : '',
                'bar_code' => $article && !empty($article->bar_code) ? $article->bar_code : '',
                'cantidad' => !empty($item->suggested_amount) ? $item->suggested_amount : 0,
                'from_address_id' => !empty($item->from_address_id) ? $item->from_address_id : 0,
                'to_address_id' => !empty($item->to_address_id) ? $item->to_address_id : 0,
                'from_address' => $from_address && !empty($from_address->street) ? $from_address->street : '',
                'to_address' => $to_address && !empty($to_address->street) ? $to_address->street : '',
                'cobertura_dias' => $item->cobertura_dias,
                'velocidad_diaria' => $item->velocidad_diaria,
                'stock_destino' => $item->stock_destino,
                'prioridad' => $item->prioridad,
            ];
        });

        return response()->json(['models' => $models], 200);
    }

    public function create_deposit_movement(Request $request, $id) {
        $suggestion = $this->sugerencia_del_usuario($id);

        if (!$suggestion) {
            return response()->json(['message' => 'Sugerencia no encontrada.'], 404);
        }

        // IDs de filas stock_suggestion_articles (lo que el usuario seleccionó en pantalla)
        $stock_suggestion_article_ids = $request->input('stock_suggestion_article_ids', []);

        if (empty($stock_suggestion_article_ids)) {
            return response()->json(['message' => 'No se enviaron líneas de sugerencia.'], 422);
        }

        $suggestion_articles = StockSuggestionArticle::where('stock_suggestion_id', $suggestion->id)
            ->whereIn('id', $stock_suggestion_article_ids)
            ->get();

        // Agrupa los artículos por par único from_address_id / to_address_id
        $groups = [];
        foreach ($suggestion_articles as $sa) {
            $key = $sa->from_address_id . '_' . $sa->to_address_id;
            $groups[$key][] = $sa;
        }

        $created_movements = [];

        foreach ($groups as $key => $group_articles) {
            $first = $group_articles[0];

            $deposit_movement = DepositMovement::create([
                'num'                        => $this->num('deposit_movements'),
                'from_address_id'            => $first->from_address_id,
                'to_address_id'              => $first->to_address_id,
                'deposit_movement_status_id' => 1,
                'stock_suggestion_id'        => $id,
                // Sin employee_id, el listado de movimientos (que filtra por
                // employee_id = auth user) no le mostraba al empleado el
                // movimiento que él mismo acababa de generar.
                'employee_id'                => $this->userId(false),
                'user_id'                    => $this->userId(),
            ]);

            // Formatea los artículos en el formato que espera DepositMovementHelper
            $articles_to_attach = array_map(function ($sa) {
                return [
                    'id'    => $sa->article_id,
                    'pivot' => [
                        'amount'             => $sa->suggested_amount,
                        'article_variant_id' => null,
                    ],
                ];
            }, $group_articles);

            $helper = new DepositMovementHelper($deposit_movement);
            $helper->attach_articles($articles_to_attach);
            $helper->check_status();

            $this->sendAddModelNotification('DepositMovement', $deposit_movement->id);

            $created_movements[] = $this->fullModel('DepositMovement', $deposit_movement->id);
        }

        return response()->json(['deposit_movements' => $created_movements], 201);
    }

    /**
     * Camino único de despacho del cálculo (store y update comparten esto).
     *
     * Catálogos chicos: procesamiento inmediato (WAMP/local suele no tener
     * queue:work). Catálogos grandes: cola para no superar el timeout HTTP.
     * El conteo se acota al dueño de la sugerencia: en una base con varios
     * comercios, contar el catálogo ajeno inflaba la decisión.
     *
     * @param StockSuggestion $model
     * @return void
     */
    protected function despachar_procesamiento($model) {
        // Más de 500 artículos: cola + chunks; hasta 500: procesamiento inmediato en la request
        $sync_article_limit = 500;

        $article_count = Article::where('user_id', $model->user_id)->count();

        if ($article_count <= $sync_article_limit) {
            try {
                (new GenerateStockSuggestionChunksJob($model->id))->handle();
            } catch (\Throwable $e) {
                // failed() de los jobs solo corre en la vía de cola: sin esta
                // red, una excepción del camino sincrónico dejaba la
                // sugerencia 'pendiente' eterna y la vista nueva polleando por
                // un resultado que no iba a llegar. Mismo formato que failed():
                // status 'error' + error_mensaje, sin pisar una ya terminada.
                $model->refresh();

                if ($model->status !== 'terminado') {
                    $model->status = 'error';
                    $model->error_mensaje = $e->getMessage();
                    $model->save();
                }

                throw $e;
            }
        } else {
            dispatch(new GenerateStockSuggestionChunksJob($model->id));
        }
    }

    /**
     * Reintento del resumen IA desde la vista: resetea el estado del resumen y
     * vuelve a despachar el job, sin tocar el cálculo ya terminado. Es la
     * contracara de que el job no reintente solo ($tries = 1): el 529 de
     * Anthropic se resuelve con este botón, no tapando la cola de chunks.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function regenerar_resumen($id) {
        $model = $this->sugerencia_del_usuario($id);

        if (!$model) {
            return response()->json(['message' => 'Sugerencia no encontrada.'], 404);
        }

        // El resumen habla del resultado completo: sobre una corrida en curso
        // o fallida no hay nada serio que redactar.
        if ($model->status !== 'terminado') {
            return response()->json(['message' => 'La sugerencia no está terminada: el resumen se escribe sobre el resultado completo.'], 422);
        }

        if (!(new ResumenIaService())->hay_credenciales()) {
            return response()->json(['message' => 'La cuenta no tiene la IA configurada: no hay credenciales para pedir el resumen.'], 422);
        }

        $model->resumen_ia         = null;
        $model->resumen_ia_error   = null;
        // 'pendiente' desde ya: la vista muestra "lo estamos escribiendo" sin
        // esperar a que el worker levante el job.
        $model->resumen_ia_estado  = 'pendiente';
        $model->save();

        dispatch(new GenerarResumenSugerenciaJob($model->id));

        return response()->json(['model' => $model], 200);
    }

    /**
     * Resuelve una sugerencia del usuario autenticado (null si no existe o es
     * de otro comercio). Todos los métodos por id pasan por acá.
     *
     * @param int $id
     * @return StockSuggestion|null
     */
    protected function sugerencia_del_usuario($id) {
        return StockSuggestion::where('user_id', $this->userId())
            ->where('id', $id)
            ->first();
    }
}
