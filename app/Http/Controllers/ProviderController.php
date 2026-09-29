<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\ExportHistoryHelper;
use App\Jobs\ProcessArticleExportJob;
use App\Jobs\ProcessProviderExportJob;
use App\Jobs\ProcessSetFinalPrices;
use App\Jobs\ProcessSincronizarDescuentosProveedorJob;
use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Http\Controllers\Helpers\article\ArticleProviderDiscountHelper;
use App\Imports\ProviderImport;
use App\Models\Provider;
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

        /*
         * Los tres datos del proveedor que lee el calculo de precios, tomados ANTES de pisarlos con
         * lo que manda el formulario: son los unicos que deciden si guardar el proveedor tiene que
         * recalcular precios (ver recalcular_precios_si_corresponde()).
         */
        $last_percentage_gain                           = $model->percentage_gain;
        $last_dolar                                     = $model->dolar;
        $last_price_from_cost_mas_iva                   = $model->price_from_cost_mas_iva;
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

        $this->recalcular_precios_si_corresponde(
            $model,
            $last_percentage_gain,
            $last_dolar,
            $last_price_from_cost_mas_iva
        );

        /*
         * `should_update_prices` lo sigue prendiendo ProviderDiscountController::destroy(), pero YA NO
         * dispara nada (ver recalcular_precios_si_corresponde()). Se lo sigue bajando para que la
         * columna no quede en 1 para siempre y nadie la lea como "hay un recalculo pendiente".
         */
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

    /**
     * Decide si guardar el proveedor tiene que recalcular precios en segundo plano, y con que alcance
     * (mision recalculo-precios-motor-rapido, 28/9/2026).
     *
     * El calculo de precios lee del proveedor TRES cosas y nada mas (ArticleHelper /
     * ArticlePricesHelper, relevado con grep el 28/9/2026): `percentage_gain`, `dolar` y
     * `price_from_cost_mas_iva`. Por eso son las unicas que disparan:
     *
     *   - margen o modalidad "precio desde costo mas IVA" -> TODOS los articulos del proveedor.
     *     La modalidad es nueva como disparador: hasta hoy cambiarla no recalculaba nada, y cambia
     *     el precio de todo el proveedor.
     *   - SOLO el dolar -> solo los articulos del proveedor con `cost_in_dollars = 1`
     *     (ProcessSetFinalPrices con `from_dolar = true` sobre un alcance = interseccion). El dolar
     *     del proveedor lo usa unicamente ArticleHelper::cotizar(), y solo para esos: recalcular el
     *     resto del proveedor es trabajo que no puede mover un centavo.
     *
     * 🔴 LO QUE YA NO DISPARA, y no hay que volver a agregar: un cambio SOLO en los descuentos del
     * proveedor (el viejo `hubo_cambios_en_provider_discounts()`, que miraba si algun
     * `provider_discount` se toco hace menos de 2 minutos, y el flag `should_update_prices` que prende
     * ProviderDiscountController::destroy()). Desde el prompt 261 el precio NO lee
     * `provider_discounts`: lee las copias materializadas en `article_discounts`, asi que ese
     * recalculo no puede mover un centavo (lo dice tambien
     * ArticleProviderDiscountHelper::propagar_a_articulos()). Lo que si mueve precios despues de
     * editar descuentos es la propagacion o el boton "Sincronizar articulos", que ya recalculan lo que
     * tocan. Medido en Servian el 28/9/2026 (574.359 articulos): Rejovot tardo 3 h 08 min para
     * recalcular 40.393 articulos y cambio de precio en 21; ETMAN (64.662) cambio 1; Zerbini (28.994)
     * cambio 1 y 0; Distrisuper y MAC FRNEOS, 0. Horas de cola por nada, trabando los recalculos que
     * si importaban.
     *
     * El `user_id` que se despacha es el del DUEÑO (`$this->userId()`), nunca el del empleado que
     * guardo: el productor filtra los articulos por `user_id`, y con el de un empleado no
     * encontraria ninguno.
     *
     * @param  \App\Models\Provider $provider            Proveedor ya guardado con los valores nuevos.
     * @param  mixed                $margen_anterior     `percentage_gain` antes de guardar.
     * @param  mixed                $dolar_anterior      `dolar` antes de guardar.
     * @param  mixed                $modalidad_anterior  `price_from_cost_mas_iva` antes de guardar.
     * @return string|null  'todo_el_proveedor', 'solo_en_dolares' o null si no se despacho nada.
     */
    protected function recalcular_precios_si_corresponde($provider, $margen_anterior, $dolar_anterior, $modalidad_anterior) {

        // Comparacion suelta (`!=`), la misma de siempre: "50.00" de la base contra 50 del
        // formulario no es un cambio, y null contra '' tampoco.
        $cambio_el_margen = $margen_anterior != $provider->percentage_gain;
        $cambio_el_dolar  = $dolar_anterior != $provider->dolar;

        /*
         * La modalidad es un tilde: se compara como booleano. filter_var y no un cast crudo, porque
         * `(bool) 'false'` es TRUE en PHP y el formulario puede mandar el valor como texto; null,
         * '', 0, '0' y false son todos "apagado".
         */
        $cambio_la_modalidad = filter_var($modalidad_anterior, FILTER_VALIDATE_BOOLEAN)
                                !== filter_var($provider->price_from_cost_mas_iva, FILTER_VALIDATE_BOOLEAN);

        if (!$cambio_el_margen && !$cambio_la_modalidad && !$cambio_el_dolar) {
            return null;
        }

        /*
         * UN solo despacho por guardado. Si cambio el margen o la modalidad, el recalculo de todo el
         * proveedor ya incluye a los articulos en dolares, asi que el dolar no suma un segundo
         * despacho: son los mismos articulos recalculados dos veces.
         */
        $solo_los_articulos_en_dolares = !$cambio_el_margen && !$cambio_la_modalidad;

        ProcessSetFinalPrices::dispatch(
            $this->userId(),
            'provider_id',
            $provider->id,
            $solo_los_articulos_en_dolares,
            'proveedor',
            $provider->name
        );

        Log::info('ProviderController: recalculo de precios despachado', [
            'provider_id' => $provider->id,
            'alcance'     => $solo_los_articulos_en_dolares ? 'solo_en_dolares' : 'todo_el_proveedor',
        ]);

        return $solo_los_articulos_en_dolares ? 'solo_en_dolares' : 'todo_el_proveedor';
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

    /* ==================================================================================
     * BOTON "SINCRONIZAR ARTICULOS" DE LA FICHA DEL PROVEEDOR (17/9/2026)
     *
     * Es otro camino que `propagar_descuentos*`, que se queda tal cual esta: aquel se ofrece
     * al GUARDAR el proveedor y esta gateado por la preferencia del comercio; este es un boton
     * explicito, alcanza tambien a los articulos que no tienen ningun descuento, y corre en
     * cola.
     * ================================================================================== */

    /**
     * Cuenta como quedaria la sincronizacion ANTES de hacerla. Es lo que llena el modal. No
     * modifica nada.
     *
     * @param  int $id Id del proveedor.
     * @return \Illuminate\Http\JsonResponse
     */
    function sincronizar_descuentos_preview($id) {

        $provider = $this->proveedor_del_comercio($id);

        if (is_null($provider)) {
            return response()->json(['message' => 'No se encontro el proveedor'], 404);
        }

        return response()->json(
            ArticleProviderDiscountHelper::preview_sincronizacion($provider),
            200
        );
    }

    /**
     * Encola la sincronizacion que el usuario confirmo en el modal y responde de inmediato.
     *
     * 🔴 Los tres parametros se validan contra LISTA BLANCA y se rechaza lo que no este en ella.
     * Es la clase de error "contrato de enumeracion partido entre cliente y servidor" (17/8/2026):
     * aceptar cualquier string dejaria que un `alcanze` mal escrito, o una SPA vieja mandando otro
     * valor, cayera en silencio en una rama que nadie eligio — y una de esas ramas borra
     * bonificaciones de compras.
     *
     * ⚠️ Una clave AUSENTE no es un valor invalido: se cae al default conservador
     * (`solo_con_descuentos` + `saltear`), que es el lado que no destruye nada. Lo que se rechaza es
     * la clave presente con un valor que no existe.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  int $id Id del proveedor.
     * @return \Illuminate\Http\JsonResponse
     */
    function sincronizar_descuentos(Request $request, $id) {

        $provider = $this->proveedor_del_comercio($id);

        if (is_null($provider)) {
            return response()->json(['message' => 'No se encontro el proveedor'], 404);
        }

        $alcance = $request->has('alcance')
            ? $request->input('alcance')
            : ArticleProviderDiscountHelper::ALCANCE_SOLO_CON_DESCUENTOS;

        if (!ArticleProviderDiscountHelper::alcance_valido($alcance)) {
            return response()->json([
                'message' => 'El alcance tiene que ser "todos" o "solo_con_descuentos"',
            ], 422);
        }

        $accion_sobre_compras = $request->has('accion_sobre_compras')
            ? $request->input('accion_sobre_compras')
            : ArticleProviderDiscountHelper::ACCION_COMPRAS_SALTEAR;

        if (!ArticleProviderDiscountHelper::accion_sobre_compras_valida($accion_sobre_compras)) {
            return response()->json([
                'message' => 'La accion sobre los descuentos de compra tiene que ser "saltear", "pisar" o "agregar"',
            ], 422);
        }

        // filter_var y no cast crudo: `(bool) 'false'` en PHP da TRUE. Mismo criterio que
        // `propagar_descuentos()`, unas lineas mas arriba.
        $pisar_editados = $request->has('pisar_editados_a_mano')
            ? filter_var($request->input('pisar_editados_a_mano'), FILTER_VALIDATE_BOOLEAN)
            : false;

        ProcessSincronizarDescuentosProveedorJob::dispatch(
            $provider->id,
            $this->userId(),
            $this->userId(false),
            $alcance,
            $pisar_editados,
            $accion_sobre_compras,
            uniqid('sincro_desc_', true)
        );

        return response()->json([
            'message' => 'La sincronizacion se esta procesando. Te avisamos cuando termine.',
        ], 200);
    }

    /**
     * Exporta a excel los articulos del proveedor que tienen descuentos tagueados que la ficha NO
     * puede reponer (origen compra, import o desconocido). Es lo que le permite al comercio mirar
     * la lista antes de elegir entre saltear, pisar y agregar, en vez de decidir a ciegas sobre un
     * contador.
     *
     * 🔴 NO hay codigo de excel nuevo: se reusa `ProcessArticleExportJob`, el mismo circuito del
     * export de articulos filtrados del listado. El usuario recibe el aviso de Pusher con el boton
     * "Descargar excel" que ya conoce y le queda en el historial de exportaciones.
     *
     * @param  int $id Id del proveedor.
     * @return \Illuminate\Http\JsonResponse
     */
    function sincronizar_descuentos_exportar_conflictos($id) {

        $provider = $this->proveedor_del_comercio($id);

        if (is_null($provider)) {
            return response()->json(['message' => 'No se encontro el proveedor'], 404);
        }

        $article_ids = ArticleProviderDiscountHelper::ids_articulos_con_descuentos_de_compra($provider);

        if (!count($article_ids)) {
            return response()->json([
                'message' => 'No hay articulos con descuentos de compra para exportar',
            ], 422);
        }

        $export_history = ExportHistoryHelper::create_pending(
            $this->userId(),
            $this->userId(false),
            'article'
        );

        ProcessArticleExportJob::dispatch(
            $this->userId(),
            $this->userId(false),
            $article_ids,
            $export_history->id
        );

        return response()->json([
            'message' => 'La exportacion se esta procesando',
        ], 200);
    }

    /**
     * Proveedor scopeado al comercio de la sesion. Mismo guard que ya usa
     * `propagar_descuentos_preview()`: sin el, un id de otro comercio devolveria sus datos y —peor—
     * dejaria sincronizarle el catalogo.
     *
     * @param  int $id
     * @return \App\Models\Provider|null
     */
    private function proveedor_del_comercio($id) {

        return Provider::where('id', $id)
                        ->where('user_id', $this->userId())
                        ->first();
    }
}
