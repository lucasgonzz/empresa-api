<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\ExportHistoryHelper;
use App\Jobs\ProcessProviderExportJob;
use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Http\Controllers\Helpers\article\ArticleProviderDiscountHelper;
use App\Imports\ProviderImport;
use App\Models\Provider;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\AfipConstanciaInscripcionController;

class ProviderController extends Controller
{

    public function index(Request $request) {
        /**
         * El tamaño de página viene del request con default y tope. Antes estaba fijo acá y
         * duplicado en el per_page del store del front, que además nunca lo mandaba: el front
         * creía que las páginas eran de 200 y el backend las mandaba de 500, así que la lógica
         * que decidía "¿quedan más páginas?" comparando longitudes nunca daba verdadero y el
         * listado se cortaba en la primera página (4/8/2026).
         *
         * Techo en 2000: mismo criterio que ArticleController@index_deleted, entidad liviana
         * por fila (sin las ~20 relaciones que carga el catálogo de artículos).
         */
        $per_page = (int) $request->input('per_page', 100);
        if ($per_page <= 0) {
            $per_page = 100;
        }
        if ($per_page > 2000) {
            $per_page = 2000;
        }

        $models = Provider::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->where('status', 'active')
                            ->paginate($per_page);
        return response()->json(['models' => $models], 200);
    }

    /**
     * Lista liviana para selectores y acumuladores que necesitan el catálogo entero. A propósito no usa
     * withAll() ni pagina: la versión completa (index) trae el modelo con todas sus relaciones y de a 100,
     * y el front sólo pedía la primera página, así que trabajaba sobre un catálogo parcial sin saberlo
     * (4/8/2026). Si alguien necesita más columnas acá, primero preguntarse si no debería leer la relación
     * ya cargada del modelo que tiene a mano.
     */
    public function options() {
        $models = Provider::where('user_id', $this->userId())
                            ->where('status', 'active')
                            ->orderBy('name', 'ASC')
                            ->select('id', 'name')
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function get_afip_information_by_cuit($cuit) {
        $ct = new AfipConstanciaInscripcionController();
        
        $data = $ct->get_constancia_inscripcion($cuit);

        if (isset($data['hubo_un_error']) && $data['hubo_un_error']) {
            return response()->json([
                'hubo_un_error'     => true,
                'error'             => $data['error'],
            ]);
        } else {
            $model = Provider::where('user_id', $this->userId())
                                    ->where('cuit', $cuit)
                                    ->withAll()
                                    ->first();
            return response()->json([
                'model'  => $model,
                'afip_data'     => $data['afip_data'],
            ]);
        }
    }

    public function store(Request $request) {
        $model = Provider::create([
            'num'                               => $this->num('providers'),
            'name'                              => $request->name,  
            'phone'                             => $request->phone, 
            'address'                           => $request->address,   
            'email'                             => $request->email, 
            'razon_social'                      => $request->razon_social,  
            'cuit'                              => $request->cuit,  
            'observations'                      => $request->observations,  
            'location_id'                       => $request->location_id,   
            'provincia_id'                      => $request->provincia_id,   
            'iva_condition_id'                  => $request->iva_condition_id,  
            'percentage_gain'                   => $request->percentage_gain,   
            'porcentaje_comision_negro'         => $request->porcentaje_comision_negro,   
            'porcentaje_comision_blanco'        => $request->porcentaje_comision_blanco,   
            'dolar'                             => $request->dolar, 
            'price_from_cost_mas_iva'           => $request->price_from_cost_mas_iva, 
            'user_id'                           => $this->userId(),
        ]);

        CreditAccountHelper::crear_credit_accounts('provider', $model->id);

        $this->updateRelationsCreated('provider', $model->id, $request->childrens);

        // $this->updateRelationsCreated('Provider', $model->id, $request->childrens);

        $this->sendAddModelNotification('Provider', $model->id);
        return response()->json(['model' => $this->fullModel('Provider', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('Provider', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = Provider::find($id);
        $last_percentage_gain                           = $model->percentage_gain;
        $last_dolar                                     = $model->dolar;
        $model->name                                    = $request->name;
        $model->phone                                   = $request->phone; 
        $model->address                                 = $request->address;   
        $model->email                                   = $request->email; 
        $model->razon_social                            = $request->razon_social;  
        $model->cuit                                    = $request->cuit;  
        $model->observations                            = $request->observations;  
        $model->location_id                             = $request->location_id;   
        $model->provincia_id                            = $request->provincia_id;   
        $model->iva_condition_id                        = $request->iva_condition_id;  
        $model->percentage_gain                         = $request->percentage_gain;   
        $model->dolar                                   = $request->dolar; 
        $model->porcentaje_comision_negro               = $request->porcentaje_comision_negro; 
        $model->porcentaje_comision_blanco              = $request->porcentaje_comision_blanco; 
        $model->price_from_cost_mas_iva                 = $request->price_from_cost_mas_iva; 
        $model->save();


        $should_update_prices = $model->should_update_prices;

        if ($should_update_prices) {
            Log::info('Cambios por should_update_prices');
        }

        // Log::info('dolar antes: '.$last_dolar);
        // Log::info('dolar ahora: '.$model->dolar);

        if ($last_percentage_gain != $model->percentage_gain) {
            $should_update_prices = true;
            Log::info('Cambios en el margen');
        }

        if ($last_dolar != $model->dolar) {
            $should_update_prices = true;
            Log::info('Cambios en el dolar');
        }


        $should_update_prices = $this->hubo_cambios_en_provider_discounts($model, $should_update_prices);

        if ($should_update_prices) {
            GeneralHelper::checkNewValuesForArticlesPrices($this, 0, 1, 'provider_id', $model->id);
        }

        $model->should_update_prices = 0;
        $model->save();

        $this->sendAddModelNotification('Provider', $model->id);
        return response()->json(['model' => $this->fullModel('Provider', $model->id)], 200);
    }

    public function destroy($id) {
        $model = Provider::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('Provider', $model->id);
        return response(null);
    }

    function import(Request $request) {
        $columns = GeneralHelper::getImportColumns($request);

        /*
         * Hoja elegida por el usuario en el selector del modal de importacion, 0-based.
         * Las dos claves son OPCIONALES: ausentes => hoja 0, que es la primera hoja y lo
         * que este endpoint hacia con un cliente viejo (la SPA sin desplegar).
         *
         * ⚠️ Ojo con lo que cambia de verdad: hasta esta mision, Maatwebsite recorria
         * TODAS las hojas del libro aplicandoles el mismo mapeo (ver ProviderImport::sheets()).
         * Ahora se importa una sola.
         */
        Excel::import(
            new ProviderImport(
                $columns,
                $request->create_and_edit,
                $request->start_row,
                $request->finish_row,
                $request->provider_id,
                $request->input('hoja', 0),
                $request->input('hoja_nombre')
            ),
            $request->file('models')
        );
    }

    /**
     * Encola la exportación de proveedores a excel y responde de inmediato al frontend.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    function export() {
        $export_history = ExportHistoryHelper::create_pending(
            $this->userId(),
            $this->userId(false),
            'provider'
        );

        ProcessProviderExportJob::dispatch(
            $this->userId(),
            $this->userId(false),
            $export_history->id
        );

        return response()->json([
            'message' => 'La exportacion de proveedores se esta procesando',
        ], 200);
    }

    function hubo_cambios_en_provider_discounts($provider, $should_update_prices) {

        foreach ($provider->provider_discounts as $provider_discount) {

            if ($provider_discount->updated_at > Carbon::now()->subMinutes(2)) {

                $should_update_prices = true;
                Log::info('Cambios en provider_discounts');
            }
        }

        return $should_update_prices;
    }

    /**
     * Mision descuentos-proveedor-propagar (4/9/2026): cuenta como quedaria propagar los descuentos
     * actuales del proveedor a sus articulos, ANTES de hacerlo. Es lo que llena la ventana de
     * confirmacion que se muestra al guardar el proveedor. No modifica nada.
     *
     * @param  int $id Id del proveedor.
     * @return \Illuminate\Http\JsonResponse
     */
    function propagar_descuentos_preview($id) {

        $provider = Provider::where('id', $id)
                                ->where('user_id', $this->userId())
                                ->first();

        if (is_null($provider)) {
            return response()->json(['message' => 'No se encontro el proveedor'], 404);
        }

        return response()->json(
            ArticleProviderDiscountHelper::preview_propagacion($provider),
            200
        );
    }

    /**
     * Aplica la propagacion que el usuario confirmo en la ventana.
     *
     * `pisar_editados_a_mano` sale del tilde de esa ventana y por defecto es FALSE: un articulo al
     * que alguien le puso a mano un porcentaje distinto refleja una decision comercial para ese
     * articulo puntual, y no se pisa salvo que lo pidan explicitamente.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  int $id Id del proveedor.
     * @return \Illuminate\Http\JsonResponse
     */
    function propagar_descuentos(Request $request, $id) {

        $provider = Provider::where('id', $id)
                                ->where('user_id', $this->userId())
                                ->first();

        if (is_null($provider)) {
            return response()->json(['message' => 'No se encontro el proveedor'], 404);
        }

        $pisar_editados = $request->has('pisar_editados_a_mano')
            ? filter_var($request->pisar_editados_a_mano, FILTER_VALIDATE_BOOLEAN)
            : false;

        $resultado = ArticleProviderDiscountHelper::propagar_a_articulos($provider, $pisar_editados);

        return response()->json($resultado, 200);
    }
}
