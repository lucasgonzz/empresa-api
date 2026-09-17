<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\Helpers\ImportHelper;
use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\ProviderOrderHelper;
use App\Http\Controllers\Pdf\ProviderOrderPdf;
use App\Http\Controllers\Helpers\providerOrder\ModoFacturacionHelper;
use App\Http\Controllers\Helpers\providerOrder\NewProviderOrderHelper;
use App\Http\Controllers\Helpers\providerOrder\ProviderOrderAltaHelper;
use App\Imports\ProviderOrderArticleImport;
use App\Jobs\ProcessProviderOrderArticleImport;
use App\Models\ProviderOrder;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

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

    function import_excel_articles(Request $request) {

        $columns = GeneralHelper::getImportColumns($request);

        Log::info('columns provider_order:');
        Log::info($columns);

        if ($request->has('models') && $request->file('models')->isValid()) {

            Log::info('se va a guardar archivo');
            Log::info($request->file('models'));

            $original_extension = 'xlsx';
            // $original_extension = $request->file('models')->getClientOriginalExtension();
            
            $filename = 'import_' . time() . '.' . $original_extension;
            $archivo_excel_path = $request->file('models')->storeAs('imported_files', $filename);

            Log::info($archivo_excel_path);

        } else if ($request->has('archivo_excel_path')) {

            Log::info('ya viene la ruta del archivo');
            $archivo_excel_path = $request->archivo_excel_path;

        } else {
            Log::info('NO se va a guardar archivo');
            Log::info($request->file('models')->getError());
        }

        Log::info('archivo_excel_path: '.$archivo_excel_path);
        $archivo_excel = storage_path('app/' . $archivo_excel_path);

        $user = $this->user();

        $provider_order = ProviderOrder::find($request->provider_order_id);


        $import_type        = $request->input('import_type', 'pedido');
        $overwrite_articles = $request->boolean('overwrite_articles', false);

        try {
            /*
             * Hoja elegida por el usuario, 0-based. Las dos claves son OPCIONALES:
             * ausentes => hoja 0, que es la primera y lo que veia un cliente viejo.
             *
             * ⚠️ Hasta esta mision Maatwebsite recorria TODAS las hojas del libro, y aca
             * eso significaba procesar la compra una vez por hoja (ver
             * ProviderOrderArticleImport::sheets()). Ahora se recorre una sola.
             */
            Excel::import(new ProviderOrderArticleImport(
                $columns,
                $request->start_row,
                $request->finish_row,
                $user,
                $provider_order,
                $import_type,
                $overwrite_articles,
                $request->input('hoja', 0),
                $request->input('hoja_nombre'),
            ), $archivo_excel_path);
        } catch (\Throwable $exception) {
            Log::error('Error al importar Excel de compra a proveedor', [
                'provider_order_id' => $request->provider_order_id,
                'message' => $exception->getMessage(),
            ]);

            $error_payload = ImportHelper::buildImportErrorPayload(
                $exception,
                'Hubo un error durante la importación de artículos de la compra'
            );

            return response()->json($error_payload, 422);
        }

        // ProcessProviderOrderArticleImport::dispatch($columns, $request->start_row, $request->finish_row, $owner, $provider_order, $archivo_excel_path);

        if ($import_type === 'recibido') {
            $diff = $this->calculate_received_diff($provider_order);
            return response()->json(['diff' => $diff], 200);
        }

        return response(null, 200);
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

    private function calculate_received_diff($provider_order) {

        $provider_order->load('articles');

        return $provider_order->articles->map(function ($article) {
            $amount   = $article->pivot->amount;
            $received = $article->pivot->received;
            $diff     = $received - $amount;

            if ($received == $amount) {
                $status = 'completo';
            } elseif ($received == 0) {
                $status = 'no_recibido';
            } elseif ($received > $amount) {
                $status = 'exceso';
            } else {
                $status = 'parcial';
            }

            return [
                'id'       => $article->id,
                'name'     => $article->name,
                'pedida'   => $amount,
                'recibida' => $received,
                'diff'     => $diff,
                'status'   => $status,
            ];
        })->values();
    }
}
