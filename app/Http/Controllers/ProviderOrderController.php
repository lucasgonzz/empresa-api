<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\Helpers\ImportHelper;
use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\import\article\ImportFailureHandler;
use App\Http\Controllers\Helpers\ProviderOrderHelper;
use App\Http\Controllers\Pdf\ProviderOrderPdf;
use App\Http\Controllers\Helpers\providerOrder\ModoFacturacionHelper;
use App\Http\Controllers\Helpers\providerOrder\NewProviderOrderHelper;
use App\Http\Controllers\Helpers\providerOrder\ProviderOrderAltaHelper;
use App\Imports\ProviderOrderArticleImport;
use App\Jobs\ProcessProviderOrderArticleImport;
use App\Models\ImportHistory;
use App\Models\ImportStatus;
use App\Models\ProviderOrder;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProviderOrderController extends Controller
{

    public function index($from_date = null, $until_date = null) {
        $models = ProviderOrder::where('user_id', $this->userId())
                        ->orderBy('created_at', 'DESC')
                        ->withAll();
        if (!is_null($from_date)) {
            if (!is_null($until_date)) {
                $models = $models->whereDate('created_at', '>=', $from_date)
                                ->whereDate('created_at', '<=', $until_date);
            } else {
                $models = $models->whereDate('created_at', $from_date);
            }
        }

        $models = $models->get();
        return response()->json(['models' => $models], 200);
    }

    /*
     * Sin parametros: la ruta (`provider-order/days-to-advise/not-received`) no declara ninguno
     * y el cuerpo nunca los uso — la firma vieja `($from_date, $until_date = null)` venia
     * copiada del index por fechas. MEDIDO en la exploracion de Alertas (3/9/2026): el endpoint
     * respondia 200 igual con esa firma (el dispatcher de rutas invoca sin reventar aunque
     * `$from_date` no tenga de donde salir), asi que esto es limpieza de firma muerta, no el
     * arreglo de un 500. Se saca para que nadie vuelva a "arreglar" un parametro que no existe.
     */
    public function indexDaysToAdvise() {
        $models = ProviderOrder::where('user_id', $this->userId())
                                ->orderBy('created_at', 'DESC')
                                ->withAll()
                                ->whereNotNull('days_to_advise')
                                ->where('days_to_advise', '>', 0)
                                ->where('provider_order_status_id', 1)
                                ->get();
        $results = [];
        foreach ($models as $model) {
            if (
                $model->created_at->addDays($model->days_to_advise)->lte(Carbon::today())
                && !is_null($model->provider)
            ) {
                $results[] = $model;
            }
        }

        return response()->json(['models' => $results], 200);
    }

    /**
     * El cuerpo de este alta vive en ProviderOrderAltaHelper (misión asistente-por-whatsapp,
     * 16/9/2026): el asistente de IA también crea compras —la que va a recibir la foto de la
     * factura que el dueño manda por WhatsApp— y no tiene request del que leer los campos.
     * Dejar el alta inline acá era la clase de error "el store() que lee un subconjunto del
     * request y el llamador que le manda el resto" (APRENDER_NO_PARCHEAR.md, 5/9/2026).
     *
     * La extracción no cambió nada observable: misma transacción, mismo orden, mismo evento de
     * demo y misma respuesta 201 con el fullModel.
     */
    public function store(Request $request) {

        $model = ProviderOrderAltaHelper::crear([
            'user_id'                                   => $this->userId(),
            'modo_facturacion'                          => $request->modo_facturacion,
            'total_with_iva'                            => $request->total_with_iva,
            'total_from_provider_order_afip_tickets'    => $request->total_from_provider_order_afip_tickets,
            'provider_id'                               => $request->provider_id,
            'provider_order_status_id'                  => $request->provider_order_status_id,
            'days_to_advise'                            => $request->days_to_advise,
            'update_stock'                              => $request->update_stock,
            'update_prices'                             => $request->update_prices,
            'precios_incluyen_iva'                      => $request->precios_incluyen_iva,
            'moneda_id'                                 => $request->moneda_id,
            'generate_current_acount'                   => $request->generate_current_acount,
            'address_id'                                => $request->address_id,
            'numero_comprobante'                        => $request->numero_comprobante,
            'childrens'                                 => $request->childrens,
            'articles'                                  => $request->articles,
        ]);

        return response()->json(['model' => $this->fullModel('ProviderOrder', $model->id)], 201);
    }

    public function show($id) {
        return response()->json(['model' => $this->fullModel('ProviderOrder', $id)], 200);
    }

    public function update(Request $request, $id) {

        /*
         * Misma transacción que store() (tanda correctivos 2408, ítem 14): la actualización
         * re-adjunta artículos, recalcula stock/precios y rehace la cuenta corriente del
         * proveedor; a mitad de camino sin transacción quedaba una compra inconsistente.
         */
        $model = DB::transaction(function () use ($request, $id) {

            $model = ProviderOrder::find($id);

            $ya_se_actualizo_stock = $model->update_stock;

            $model->total_with_iva                              = $request->total_with_iva;
            $model->modo_facturacion                            = $request->modo_facturacion;
            $model->total_from_provider_order_afip_tickets      = $request->total_from_provider_order_afip_tickets;
            $model->provider_id                                 = $request->provider_id;
            $model->provider_order_status_id                    = $request->provider_order_status_id;
            $model->days_to_advise                              = $request->days_to_advise;
            $model->update_stock                                = $request->update_stock;
            $model->update_prices                               = $request->update_prices;
            $model->precios_incluyen_iva                        = $request->precios_incluyen_iva;
            $model->generate_current_acount                     = $request->generate_current_acount;
            $model->numero_comprobante                          = $request->numero_comprobante;
            $model->moneda_id                                   = $request->moneda_id;
            $model->save();


            $helper = new NewProviderOrderHelper($model, $request->articles, $ya_se_actualizo_stock);

            $helper->attach_articles(true);

            ModoFacturacionHelper::check_modo_facturacion($model, $helper);

            $helper->procesar_pedido();

            return $model;
        });

        return response()->json(['model' => $this->fullModel('ProviderOrder', $model->id)], 200);
    }

    public function destroy($id) {
        $model = ProviderOrder::find($id);
        
        ProviderOrderHelper::deleteCurrentAcount($model);
        ProviderOrderHelper::resetArticlesStock($model);

        // if (!is_null($model->provider)) {
        //     $model->provider->pagos_checkeados = 0;
        //     $model->provider->save();
        // }
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('ProviderOrder', $model->id);
        return response(null);
    }

    /**
     * Recibe el Excel de artículos de una compra y despacha su procesamiento a la cola: hasta la
     * misión `import-excel-compras-chunks` (14/9/2026) esto corría síncrono dentro del request
     * (Excel::import de un solo tiro), lo que hacía que un archivo de 500-700 filas arriesgara el
     * max_execution_time del servidor. Ahora el request solo prepara el tracking y responde; el
     * trabajo real lo hace ProcessProviderOrderArticleImport, y el frontend se entera del avance
     * por el mismo mecanismo (ImportStatus + WebSocket) que ya usa la importación de artículos.
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    function import_excel_articles(Request $request) {

        $user = $this->user();

        /*
         * 🔴 Mismo guard que InitExcelImport::tiene_importacion_en_curso() (import de artículos):
         * sin esto, dos imports concurrentes del mismo usuario (doble click, o reabrir el modal
         * para otra compra mientras la anterior todavía procesa) pueden correr
         * attach_articles()/procesar_pedido() en paralelo sobre la MISMA compra si
         * update_stock=1 (el default), duplicando el StockMovement generado. Antes de esta
         * misión el import era síncrono y el botón quedaba deshabilitado durante todo el
         * proceso; ahora el modal se cierra apenas se despacha el job, así que la ventana para
         * disparar esto por accidente es mucho más ancha. Chequeo temprano, antes de guardar el
         * archivo, para no gastar esa escritura si de entrada no va a procesarse.
         */
        /*
         * 🔴 Y filtrado por `model_name`: el candado es de COMPRAS contra COMPRAS, nunca contra la
         * importación de catálogo. Sin este filtro (como estaba hasta el 17/9/2026) un usuario que
         * dejó corriendo la importación de su catálogo de artículos —que tarda minutos u horas—
         * se comía un 409 al querer importar el Excel de una compra, y eso es una REGRESIÓN: antes
         * de pasar compras a la cola los dos caminos convivían sin pisarse. Lo que este guard tiene
         * que evitar es que DOS importaciones de la MISMA compra corran a la vez; el import de
         * artículos no toca `article_provider_order` ni `procesar_pedido()`.
         */
        if (ImportHistory::where('user_id', $user->id)
                            ->where('model_name', 'provider_order')
                            ->whereIn('status', ['en_preparacion', 'en_proceso'])
                            ->exists()) {
            return response()->json([
                'message' => 'Ya tenés una importación en curso. Esperá a que termine antes de iniciar otra.',
            ], 409);
        }

        $columns = GeneralHelper::getImportColumns($request);

        if ($request->has('models') && $request->file('models')->isValid()) {

            $original_extension = 'xlsx';

            /*
             * 'import_' . time() (sin más entropía) es el patrón que usan también el import de
             * artículos/clientes/proveedores. Con un procesamiento SÍNCRONO eso nunca importó: el
             * archivo se leía en el mismo request, milisegundos después de guardarse. Desde esta
             * misión (14/9/2026) el de compras quedó asíncrono, con una ventana real entre
             * guardar y leer (hasta que el worker levanta el job) — y dos imports de CUALQUIER
             * modelo en el mismo segundo pueden pisarse el archivo en `imported_files/`.
             * Medido de verdad: corriendo esta suite en paralelo con tests/Import, este job leyó
             * el fixture de OTRO test ("Barcode repetido nuevo") porque compartían el mismo
             * nombre. uniqid() (microtime con más precisión) saca a compras de esa colisión; los
             * otros importadores siguen expuestos entre sí, pero eso es preexistente y está fuera
             * de esta misión.
             */
            $filename = 'import_provider_order_' . time() . '_' . uniqid() . '.' . $original_extension;
            $archivo_excel_path = $request->file('models')->storeAs('imported_files', $filename);

        } else if ($request->has('archivo_excel_path')) {

            $archivo_excel_path = $request->archivo_excel_path;

        } else {
            Log::info('NO se va a guardar archivo');
            Log::info($request->file('models')->getError());
        }

        $provider_order = ProviderOrder::find($request->provider_order_id);

        if (is_null($provider_order)) {
            return response()->json(['message' => 'No se encontró la compra a importar'], 404);
        }

        $import_type        = $request->input('import_type', 'pedido');
        $overwrite_articles = $request->boolean('overwrite_articles', false);
        $start_row          = $request->start_row;
        $finish_row         = $request->finish_row;

        /*
         * Hoja elegida por el usuario, 0-based. Las dos claves son OPCIONALES: ausentes => hoja
         * 0, que es la primera y lo que veia un cliente viejo. El NOMBRE le gana al indice (ver
         * ProviderOrderArticleImport::sheets()).
         */
        $hoja        = $request->input('hoja', 0);
        $hoja_nombre = $request->input('hoja_nombre');

        /*
         * Declaradas ANTES del try para que el catch pueda limpiarlas: si el dispatch falla (Redis
         * caído, cola mal configurada) estas dos filas ya existen y nadie las va a cerrar. Ver el
         * catch más abajo.
         */
        $import_status  = null;
        $import_history = null;

        try {

            $total_chunks = ProviderOrderArticleImport::calcular_total_chunks($start_row, $finish_row);

            $import_status = ImportStatus::create([
                'user_id'           => $user->id,
                'provider_id'       => $provider_order->provider_id,
                'provider_order_id' => $provider_order->id,
                'total_chunks'      => $total_chunks,
                'processed_chunks'  => 0,
                'created_models'    => 0,
                'updated_models'    => 0,
                'articles_match'    => 0,
                'filas_procesadas'  => 0,
                'status'            => 'pendiente',
            ]);

            $import_history = ImportHistory::create([
                'user_id'           => $user->id,
                'model_name'        => 'provider_order',
                'provider_id'       => $provider_order->provider_id,
                'provider_order_id' => $provider_order->id,
                'total_chunks'      => $total_chunks,
                'processed_chunks'  => 0,
                'created_models'    => 0,
                'updated_models'    => 0,
                'status'            => 'en_preparacion',
                'observations'      => 'Importación de excel de la compra #' . $provider_order->id . ' (' . $import_type . ')',
                /*
                 * 🔴 Link al ImportStatus, igual que InitExcelImport::crear_import_history() en el
                 * camino de artículos. Sin esto el watchdog `imports:detectar-colgadas` solo puede
                 * cerrar el ImportHistory y el ImportStatus se queda en 'en_proceso' PARA SIEMPRE —
                 * y un ImportStatus huérfano en 'en_proceso' deja mudo al comando
                 * `articles:generate-embeddings` de ese usuario (ver el comentario de
                 * GenerateArticleEmbeddings), o sea que el agente de WhatsApp de ese cliente no
                 * vuelve a encontrar ningún artículo nuevo. Falla muda y cara.
                 */
                'import_status_id'  => $import_status->id,
            ]);

            ProcessProviderOrderArticleImport::dispatch(
                $columns,
                $start_row,
                $finish_row,
                $user,
                $provider_order,
                $import_type,
                $overwrite_articles,
                $hoja,
                $hoja_nombre,
                $archivo_excel_path,
                $import_status->id,
                $import_history->id
            );

        } catch (\Throwable $exception) {
            Log::error('Error al preparar la importación de Excel de compra a proveedor', [
                'provider_order_id' => $request->provider_order_id,
                'message' => $exception->getMessage(),
            ]);

            /*
             * 🔴 Soltar el candado antes de contestar el error. Si el dispatch falla después de
             * haber creado las dos filas, el ImportHistory queda en 'en_preparacion' y bloquea
             * TODA importación de compras de este usuario hasta que el watchdog lo levante — y el
             * watchdog tiene un umbral mínimo de 45 minutos. El usuario ve un error, reintenta, y
             * rebota media hora contra un 409 que no le explica nada.
             *
             * Se marcan como fallidas en vez de borrarlas: el historial de importaciones del
             * usuario tiene que mostrar que el intento existió y por qué no salió, que es
             * exactamente para lo que está `error_message`. Va por ImportFailureHandler porque es
             * el único punto que marca un import como fallido, es idempotente y no tira nunca.
             */
            ImportFailureHandler::registrar(
                !is_null($import_history) ? $import_history->id : null,
                !is_null($import_status) ? $import_status->id : null,
                $user->id,
                'No se pudo iniciar la importación de la compra #' . $provider_order->id . '. '
                    . ImportHelper::formatImportErrorMessage($exception)
            );

            $error_payload = ImportHelper::buildImportErrorPayload(
                $exception,
                'Hubo un error al iniciar la importación de artículos de la compra'
            );

            return response()->json($error_payload, 422);
        }

        return response(null, 200);
    }

    /**
     * Diff pedido/recibido de la última importación en modo 'recibido' de esta compra. El
     * frontend lo pide cuando ve, por WebSocket, que el ImportStatus de esta compra llegó a
     * 'completado' — antes de la misión `import-excel-compras-chunks` (14/9/2026) este dato viajaba
     * en la respuesta HTTP directa del import, que ahora responde antes de que el procesamiento
     * exista.
     *
     * @param int $id ID de la ProviderOrder.
     * @return \Illuminate\Http\JsonResponse
     */
    function import_diff($id) {

        /*
         * 🔴 Escopado por user_id — sin esto, cualquier usuario autenticado podía pedir el diff
         * de una ProviderOrder de OTRO comercio con solo cambiar el id en la URL. Grave en las
         * bases compartidas por varios comercios (ver contexto del proyecto: u767360347_empresa
         * tiene 51 comercios en una sola base, con ids de ProviderOrder correlativos entre
         * todos). Mismo criterio que index()/pdf() de este controlador.
         */
        $import_history = ImportHistory::where('provider_order_id', $id)
            ->where('model_name', 'provider_order')
            ->where('user_id', $this->userId())
            ->orderBy('id', 'desc')
            ->first();

        $diff = [];

        if (!is_null($import_history) && !is_null($import_history->operaciones)) {
            $diff = $import_history->operaciones['diff'] ?? [];
        }

        return response()->json(['diff' => $diff], 200);
    }

    /**
     * Genera e imprime el PDF de la compra al proveedor.
     *
     * @param int $id ID del ProviderOrder
     */
    function pdf($id) {
        $model = ProviderOrder::where('id', $id)
            ->where('user_id', $this->userId())
            ->withAll()
            ->first();

        if (is_null($model)) {
            abort(404);
        }

        new ProviderOrderPdf($model);
    }
}
