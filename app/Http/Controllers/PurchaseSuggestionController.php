<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\providerOrder\NewProviderOrderHelper;
use App\Jobs\GeneratePurchaseSuggestionChunksJob;
use App\Jobs\GenerarResumenSugerenciaCompraJob;
use App\Models\ProviderOrder;
use App\Models\PurchaseSuggestion;
use App\Models\PurchaseSuggestionArticle;
use App\Services\PurchaseSuggestion\ResumenIaComprasService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Los 7 endpoints de sugerencias de compra a proveedores (contrato
 * congelado en el plan de la misión, §9 — la SPA ya está escrita contra él:
 * rutas, claves de respuesta y nombres de campo no se tocan sin avisar).
 *
 * Sin endpoint update (D10 del plan): reprocesar una sugerencia es idéntico
 * a crear una nueva, así que no existe un camino de edición.
 */
class PurchaseSuggestionController extends Controller
{

    public function index() {
        $models = PurchaseSuggestion::where('user_id', $this->userId())
            ->orderBy('created_at', 'DESC')
            ->withAll()
            ->get();

        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = PurchaseSuggestion::create([
            'user_id'                  => $this->userId(),
            'status'                   => 'pendiente',
            'origen_generacion'        => 'manual',
            'dias_punto_pedido'        => (int) $request->input('dias_punto_pedido', 15),
            'dias_cobertura_objetivo'  => (int) $request->input('dias_cobertura_objetivo', 30),
            'dias_lead_time'           => (int) $request->input('dias_lead_time', 7),
            'dias_vigencia_oferta'     => (int) $request->input('dias_vigencia_oferta', 120),
        ]);

        // GeneratePurchaseSuggestionChunksJob procesa TODOS sus chunks inline
        // (no despacha hijos, ver su docblock): un solo dispatch alcanza. Con
        // QUEUE_CONNECTION=database (producción) esto solo encola; con
        // QUEUE_CONNECTION=sync (tests) corre en el mismo request.
        dispatch(new GeneratePurchaseSuggestionChunksJob($model->id));

        return response()->json(['model' => $this->fullModel('PurchaseSuggestion', $model->id)], 201);
    }

    public function show($id) {
        $model = $this->sugerencia_del_usuario($id);

        if (!$model) {
            return response()->json(['message' => 'Sugerencia no encontrada.'], 404);
        }

        return response()->json(['model' => $this->fullModel('PurchaseSuggestion', $model->id)], 200);
    }

    public function destroy($id) {
        $model = $this->sugerencia_del_usuario($id);

        if (!$model) {
            return response()->json(['message' => 'Sugerencia no encontrada.'], 404);
        }

        // Las líneas van en la misma transacción que la cabecera: sin esto
        // quedaban huérfanas en purchase_suggestion_articles.
        DB::transaction(function () use ($model) {
            PurchaseSuggestionArticle::where('purchase_suggestion_id', $model->id)->delete();
            $model->delete();
        });

        $this->sendDeleteModelNotification('PurchaseSuggestion', $model->id);

        // 204 explícito: el contrato congelado del plan lo pide así (a
        // diferencia de response(null) sin status, que responde 200).
        return response(null, 204);
    }

    /**
     * Líneas de una sugerencia, paginadas server-side. Contrato congelado
     * (plan §9): per_page default 50, techo 500; filtros provider_id y
     * solo_cambio_de_proveedor; orden prioridad (default) | cantidad |
     * ahorro; respuesta ['models' => paginador] con claves fijas aplanadas,
     * el nombre del artículo en la clave 'name'.
     *
     * @param Request $request page, per_page, provider_id, solo_cambio_de_proveedor, order
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function articles(Request $request, $id) {
        $suggestion = $this->sugerencia_del_usuario($id);

        if (!$suggestion) {
            return response()->json(['message' => 'Sugerencia no encontrada.'], 404);
        }

        $per_page = (int) $request->input('per_page', 50);
        if ($per_page <= 0) {
            $per_page = 50;
        }
        if ($per_page > 500) {
            $per_page = 500;
        }

        $query = PurchaseSuggestionArticle::withAll()
            ->where('purchase_suggestion_id', $suggestion->id);

        if ($request->filled('provider_id')) {
            $query->where('provider_id', $request->provider_id);
        }

        if ($request->filled('solo_cambio_de_proveedor')) {
            // Comparación null-safe (<=>): ambos NULL no es "cambio" (nunca
            // hubo proveedor de ningún lado); un NULL contra un valor SÍ lo
            // es (se asignó uno donde antes no había titular, o viceversa).
            $query->whereRaw('NOT (provider_id <=> provider_id_titular)');
        }

        $order = $request->input('order');

        if ($order === 'cantidad') {
            $query->orderByRaw('cantidad_sugerida IS NULL ASC')->orderBy('cantidad_sugerida', 'DESC');
        } elseif ($order === 'ahorro') {
            $query->orderByRaw('ahorro_estimado IS NULL ASC')->orderBy('ahorro_estimado', 'DESC');
        } else {
            // Orden default por urgencia. NULLs al final: prioridad se
            // materializa recién al cerrar la corrida (asignar_prioridades()).
            $query->orderByRaw('prioridad IS NULL ASC')->orderBy('prioridad', 'ASC');
        }
        $query->orderBy('id', 'ASC');

        $models = $query->paginate($per_page);

        $models->getCollection()->transform(function ($item) {
            $article = $item->article;
            $provider = $item->provider;
            $provider_titular = $item->provider_titular;

            return [
                'purchase_suggestion_article_id' => $item->id,
                'article_id'               => !empty($item->article_id) ? $item->article_id : 0,
                'name'                     => $article && !empty($article->name) ? $article->name : '',
                'provider_code'            => $article && !empty($article->provider_code) ? $article->provider_code : '',
                'bar_code'                 => $article && !empty($article->bar_code) ? $article->bar_code : '',
                // Numéricos SIN fallback a 0: null es un dato real acá (p. ej.
                // stock_min_global null = "ninguna sucursal tiene mínimo
                // cargado", distinto de 0 = "mínimo cargado en cero").
                'cantidad'                 => $item->cantidad_sugerida,
                'stock_global'             => $item->stock_global,
                'stock_min_global'         => $item->stock_min_global,
                'velocidad_diaria'         => $item->velocidad_diaria,
                'cobertura_dias'           => $item->cobertura_dias,
                'provider_id'              => $item->provider_id,
                'provider_nombre'          => $provider && !empty($provider->name) ? $provider->name : '',
                'provider_id_titular'      => $item->provider_id_titular,
                'provider_titular_nombre'  => $provider_titular && !empty($provider_titular->name) ? $provider_titular->name : '',
                'costo_estimado'           => $item->costo_estimado,
                'costo_proveedor_titular'  => $item->costo_proveedor_titular,
                'ahorro_estimado'          => $item->ahorro_estimado,
                'oferta_fecha'             => $item->oferta_fecha,
                'prioridad'                => $item->prioridad,
            ];
        });

        return response()->json(['models' => $models], 200);
    }

    /**
     * Reintento del resumen IA desde la vista: resetea el estado del resumen
     * y vuelve a despachar el job, sin tocar el cálculo ya terminado. Es la
     * contracara de que el job no reintente solo ($tries = 1): un 529 de
     * Anthropic se resuelve con este botón.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function resumen($id) {
        $model = $this->sugerencia_del_usuario($id);

        if (!$model) {
            return response()->json(['message' => 'Sugerencia no encontrada.'], 404);
        }

        // El resumen habla del resultado completo: sobre una corrida en curso
        // o fallida no hay nada serio que redactar.
        if ($model->status !== 'terminado') {
            return response()->json(['message' => 'La sugerencia no está terminada: el resumen se escribe sobre el resultado completo.'], 422);
        }

        if (!(new ResumenIaComprasService())->hay_credenciales()) {
            return response()->json(['message' => 'La cuenta no tiene la IA configurada: no hay credenciales para pedir el resumen.'], 422);
        }

        $model->resumen_ia        = null;
        $model->resumen_ia_error  = null;
        // 'pendiente' desde ya: la vista muestra "lo estamos escribiendo" sin
        // esperar a que el worker levante el job.
        $model->resumen_ia_estado = 'pendiente';
        $model->save();

        // Sin la posta: la corrida ya fue anunciada cuando terminó, este es
        // solo un reintento del resumen.
        dispatch(new GenerarResumenSugerenciaCompraJob($model->id));

        return response()->json(['model' => $model], 200);
    }

    /**
     * Genera una o más ProviderOrder a partir de las líneas seleccionadas de
     * la sugerencia, agrupadas por proveedor (plan §8). La orden nace
     * cargada y pendiente, igual a una tipeada a mano y sin confirmar
     * todavía: update_stock/update_prices/generate_current_acount en 0, y
     * NO se llama procesar_pedido() ni precargar_bonificaciones_proveedor().
     *
     * 🔴 Caveat de IVA: costo_estimado viene NETO. article_provider_order.cost
     * es el valor literal tipeado, que el sistema interpreta como bruto si
     * la orden tiene precios_incluyen_iva=true. Por eso la orden nace con
     * precios_incluyen_iva=false: literal = neto = correcto.
     *
     * @param Request $request purchase_suggestion_article_ids: int[]
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function create_provider_order(Request $request, $id) {
        $suggestion = $this->sugerencia_del_usuario($id);

        if (!$suggestion) {
            return response()->json(['message' => 'Sugerencia no encontrada.'], 404);
        }

        $purchase_suggestion_article_ids = $request->input('purchase_suggestion_article_ids', []);

        if (empty($purchase_suggestion_article_ids)) {
            return response()->json(['message' => 'No se enviaron líneas de la sugerencia.'], 422);
        }

        $lineas = PurchaseSuggestionArticle::with('article')
            ->where('purchase_suggestion_id', $suggestion->id)
            ->whereIn('id', $purchase_suggestion_article_ids)
            ->get();

        if ($lineas->isEmpty()) {
            return response()->json(['message' => 'No se encontraron líneas de la sugerencia para esa selección.'], 422);
        }

        // Alguna línea sin proveedor asignado: se frena TODO, no se crea
        // nada — el usuario tiene que resolver esa línea antes de pedir.
        $falta_proveedor = false;
        foreach ($lineas as $linea) {
            if (empty($linea->provider_id)) {
                $falta_proveedor = true;
                break;
            }
        }

        if ($falta_proveedor) {
            return response()->json(['message' => 'Hay líneas sin proveedor asignado: asignales un proveedor al artículo o sacalas de la selección.'], 422);
        }

        $grupos = $lineas->groupBy('provider_id');

        $created_orders = [];

        foreach ($grupos as $provider_id => $lineas_del_proveedor) {
            $order = ProviderOrder::create([
                'num'                       => $this->num('provider_orders'),
                'provider_id'               => (int) $provider_id,
                'provider_order_status_id'  => 1, // 'En proceso'
                'update_stock'              => 0, // no es una compra recibida
                'update_prices'             => 0, // costo SUGERIDO: no puede pisar article_provider como si fuera real
                'generate_current_acount'   => 0, // no genera deuda hasta que se confirme
                'precios_incluyen_iva'      => false, // costo_estimado ya viene NETO
                'moneda_id'                 => 1,
                'purchase_suggestion_id'    => $suggestion->id,
                'user_id'                   => $this->userId(),
            ]);

            $articles_payload = [];

            foreach ($lineas_del_proveedor as $linea) {
                $article = $linea->article;

                $articles_payload[] = [
                    'id'            => $linea->article_id,
                    // update_article_data() lee estas dos claves sin isset: tienen que existir siempre.
                    'bar_code'      => $article ? $article->bar_code : null,
                    'provider_code' => $article ? $article->provider_code : null,
                    'pivot'         => [
                        'amount'        => $linea->cantidad_sugerida,
                        'amount_pedida' => $linea->cantidad_sugerida,
                        'cost'          => $linea->costo_estimado,
                    ],
                ];
            }

            $helper = new NewProviderOrderHelper($order, $articles_payload);
            $helper->attach_articles();

            $this->sendAddModelNotification('ProviderOrder', $order->id);

            $created_orders[] = $this->fullModel('ProviderOrder', $order->id);
        }

        return response()->json(['provider_orders' => $created_orders], 201);
    }

    /**
     * Resuelve una sugerencia del usuario autenticado (null si no existe o es
     * de otro comercio). Todos los métodos por id pasan por acá.
     *
     * @param int $id
     * @return PurchaseSuggestion|null
     */
    protected function sugerencia_del_usuario($id) {
        return PurchaseSuggestion::where('user_id', $this->userId())
            ->where('id', $id)
            ->first();
    }
}
