<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\CatalogoPorListaHelper;
use App\Models\ArticleTicketDesign;
use App\Http\Controllers\Helpers\PriceTypeHelper;
use App\Http\Controllers\Helpers\combo\ComboCalculadoHelper;
use App\Jobs\ProcessSetFinalPrices;
use App\Models\Article;
use App\Models\PriceType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PriceTypeController extends Controller
{

    public function index() {
        $models = PriceType::where('user_id', $this->userId())
                            ->orderBy('position', 'ASC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        /**
         * Array de notificaciones para devolver al frontend en respuestas exitosas.
         *
         * Se usa como feedback inmediato cuando la creación de un price_type dispara el recálculo masivo
         * en background (queue).
         *
         * @var array<int, array{message:string,type:string}>
         */
        $notifications = [];

        $model = PriceType::create([
            'num'                   => $this->num('price_types'),
            'name'                  => $request->name,
            'percentage'            => $request->percentage,
            'apply_percentage_on_existing_articles' => 1,
            // 'apply_percentage_on_existing_articles' => $request->apply_percentage_on_existing_articles,
            // Ya no se persiste el modo (misión sincronizar-margen-lista-precios, 1/10/2026): los
            // artículos se sincronizan con el botón "Sincronizar artículos" del modal. Ver update().
            'update_existing_articles_percentage_mode' => 'none',
            'position'              => $request->position,
            'ocultar_al_publico'    => $request->ocultar_al_publico,
            'incluir_en_lista_de_precios_de_excel'    => $request->incluir_en_lista_de_precios_de_excel,
            'setear_precio_final'    => $request->setear_precio_final,
            'se_usa_en_tienda_nube'    => $request->se_usa_en_tienda_nube,
            'se_usa_en_ml'    => $request->se_usa_en_ml,
            // Interruptor "Catálogo restringido en la tienda" (misión catalogo-por-lista-tienda,
            // 5/10/2026). Sin la clave (SPA viejo) la lista nace en NULL = sin restricción; con la
            // clave, saneada a 1 o 0.
            'catalogo_restringido_en_tienda' => $request->has('catalogo_restringido_en_tienda')
                ? CatalogoPorListaHelper::interruptor_de_lista($request->input('catalogo_restringido_en_tienda'))
                : null,
            'user_id'               => $this->userId(),
        ]);

        if ($this->user()->listas_de_precio) {
            // Solo aplica el nuevo tipo de precio a artículos existentes cuando el usuario lo pidió.
            $this->agregar_a_articulos_existentes($model, $request);

            // Feedback inmediato: el recálculo se ejecuta en segundo plano y puede demorar.
            $notifications[] = [
                'message' => 'Se inició la actualización de precios en segundo plano. Te avisaremos cuando termine.',
                'type'    => 'info',
            ];
        }


        $this->sendAddModelNotification('price_type', $model->id);

        /*
            Lista nueva -> su diseño de etiquetas de góndola (misión disenos-etiquetas-gondola,
            29/9/2026). Lo crea PriceTypeObserver::created() en el PriceType::create() de arriba,
            así también lo reciben las listas de la importación de clientes y de la demo. Acá solo
            se avisa a las otras sesiones del negocio, que es algo que un observer no puede hacer
            (sendAddModelNotification es del Controller). Un error acá no rompe el alta.
        */
        try {
            $diseno_de_la_lista = ArticleTicketDesign::where('user_id', $model->user_id)
                                                        ->where('price_type_id', $model->id)
                                                        ->first();

            if (!is_null($diseno_de_la_lista)) {
                $this->sendAddModelNotification('article_ticket_design', $diseno_de_la_lista->id);
            }
        } catch (\Throwable $e) {
            Log::warning('PriceTypeController@store: no se pudo avisar el diseño de etiquetas de la lista '.$model->id.': '.$e->getMessage());
        }

        $this->updateRelationsCreated('price_type', $model->id, $request->childrens);
        
        GeneralHelper::attachModels($model, 'categories', $request->categories, ['percentage']);
        GeneralHelper::attachModels($model, 'sub_categories', $request->sub_categories, ['percentage']);

        return response()->json([
            'model' => $this->fullModel('PriceType', $model->id),
            'notifications' => $notifications,
        ], 201);
    }  

    /**
     * Decide si dispara el recálculo masivo al crear un tipo de precio.
     *
     * @param PriceType $price_type
     * @param Request $request
     * @return void
     */
    function agregar_a_articulos_existentes($price_type, $request) {
        // Flag persistente que indica si corresponde aplicar el nuevo tipo a artículos existentes.
        // $apply_percentage_on_existing_articles = (bool) $price_type->apply_percentage_on_existing_articles;

        // Permite compatibilidad con request explícito si viene seteado desde frontend.
        // if (!is_null($request->apply_percentage_on_existing_articles)) {
        //     $apply_percentage_on_existing_articles = (bool) $request->apply_percentage_on_existing_articles;
        // }

        // if ($apply_percentage_on_existing_articles) {
            ProcessSetFinalPrices::dispatch($this->userId(), null, null, false, 'tipo_de_precio');
        // }

    }

    public function show($id) {
        return response()->json(['model' => $this->fullModel('PriceType', $id)], 200);
    }

    /**
     * Los números del modal "Sincronizar artículos" del margen por defecto de una lista, ANTES de
     * sincronizar: el margen guardado, cuántos artículos tiene la lista, cuántos coinciden con el
     * margen guardado y cuántos tienen el precio fijado a mano.
     *
     * `GET api/price-type/{id}/sincronizar-margen/preview`. Solo listas del dueño; ajena o
     * inexistente → 404. El cálculo vive en PriceTypeHelper::preview_sincronizar_margen().
     *
     * @param  int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function sincronizar_margen_preview($id) {

        $model = PriceType::where('user_id', $this->userId())
                            ->where('id', $id)
                            ->first();

        if (is_null($model)) {
            return response()->json(['message' => 'No se encontro la lista de precios.'], 404);
        }

        return response()->json(PriceTypeHelper::preview_sincronizar_margen($model), 200);
    }

    /**
     * El contador "X habilitados de Y" del interruptor "Catálogo restringido en la tienda" de la
     * lista (misión catalogo-por-lista-tienda, 5/10/2026): cuántos artículos ven en la tienda los
     * compradores de esta lista si se la restringe.
     *
     * `GET api/price-type/{id}/habilitados-en-tienda` → `200 {"habilitados": int, "total": int}`.
     * Solo listas del dueño; ajena o inexistente → 404 (y no se cuenta nada). El conteo vive en
     * CatalogoPorListaHelper::contar_habilitados().
     *
     * @param  int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function habilitados_en_tienda($id) {

        $model = PriceType::where('user_id', $this->userId())
                            ->where('id', $id)
                            ->first();

        if (is_null($model)) {
            return response()->json(['message' => 'No se encontro la lista de precios.'], 404);
        }

        return response()->json(CatalogoPorListaHelper::contar_habilitados($model), 200);
    }

    public function update(Request $request, $id) {
        /**
         * Notificaciones para la respuesta (la SPA muestra `response.data.notifications`): hoy solo
         * el resultado de "Sincronizar artículos".
         *
         * @var array<int, array{message:string,type:string}>
         */
        $notifications = [];

        $model = PriceType::find($id);
        // El margen GUARDADO antes de este request: contra él se mide "coinciden con el actual"
        // al sincronizar, y con él decide el camino de compatibilidad del SPA viejo.
        $old_percentage = $model->percentage;
        $model->name                = $request->name;
        $model->percentage          = $request->percentage;
        // apply_percentage_on_existing_articles solo tiene sentido en el alta; no se reescribe en update.
        // El modo ya no se persiste (misión sincronizar-margen-lista-precios, 1/10/2026): si se
        // guardara, la fila arrastraría un 'all' viejo que el SPA nuevo ya no muestra ni deja
        // cambiar. Lo que el SPA viejo mande en ESTE guardado se honra abajo, sin guardarlo.
        $model->update_existing_articles_percentage_mode = 'none';
        $model->position            = $request->position;
        $model->ocultar_al_publico  = $request->ocultar_al_publico;
        $model->incluir_en_lista_de_precios_de_excel  = $request->incluir_en_lista_de_precios_de_excel;
        $model->setear_precio_final  = $request->setear_precio_final;
        $model->se_usa_en_tienda_nube  = $request->se_usa_en_tienda_nube;
        $model->se_usa_en_ml  = $request->se_usa_en_ml;

        /*
         * 🔴 CONDICIONAL, a diferencia de los campos de arriba (misión catalogo-por-lista-tienda,
         * 5/10/2026). Este update() asigna campo por campo lo que venga en el request: un SPA viejo
         * (cacheado en la PWA) no conoce `catalogo_restringido_en_tienda` y no lo manda, y con la
         * asignación de siempre la lista quedaría en NULL — o sea, le APAGARÍA la restricción a un
         * comercio que la prendió desde otra pestaña ya actualizada, y sus mayoristas pasarían a
         * ver todo el catálogo sin que nadie lo note. Solo se escribe si el request trae la clave.
         *
         * Y tampoco con la clave en `null`: es el eco de un modelo cargado (el ABM reenvía
         * `{...this.model}` entero) y no un pedido de apagarlo. Qué cuenta como "escribir" lo decide
         * CatalogoPorListaHelper::interruptor_a_escribir_en_update(), con el porqué.
         */
        $interruptor = CatalogoPorListaHelper::interruptor_a_escribir_en_update($request);

        if (!is_null($interruptor)) {
            $model->catalogo_restringido_en_tienda = $interruptor;
        }

        $model->save();

        /*
         * Sincronizar el margen con los artículos (misión sincronizar-margen-lista-precios,
         * 1/10/2026). Desde esta misión un cambio de margen NO toca ningún artículo por sí solo:
         * solo lo hace el pedido explícito del botón "Sincronizar artículos", que viaja en este
         * mismo PUT (así "primero se guarda la lista y después se actualizan los artículos").
         *
         *  - `sincronizar_margen` con alcance válido → se sincroniza contra el margen GUARDADO
         *    antes de este request ($old_percentage) con el margen recién guardado, y se avisa.
         *    Con alcance inválido se ignora: la lista ya quedó guardada.
         *  - Sin `sincronizar_margen`, compatibilidad con el SPA viejo cacheado en la PWA: si ESTE
         *    request trae `update_existing_articles_percentage_mode` en 'all' u
         *    'only_default_matches' y el margen cambió, se honra como siempre (el usuario del SPA
         *    viejo eligió esa opción en este guardado). El SPA nuevo no lo manda.
         */
        $pedido_de_sincronizar = PriceTypeHelper::leer_pedido_de_sincronizar_margen($request->sincronizar_margen);

        /*
         * 🔴 La sincronización solo sobre una lista PROPIA. `update()` busca la lista por id sin
         * mirar el dueño (ya era así antes de esta misión y no se cambia acá), pero sincronizar es
         * destructivo: con alcance "todos" y el tilde les saca el precio fijado a mano a todos los
         * artículos de la lista. En una base compartida por varios comercios (ids secuenciales) eso
         * no puede quedar al alcance de un id ajeno. El preview ya filtra por dueño; acá se ignora
         * el pedido igual que uno inválido.
         */
        if (!is_null($pedido_de_sincronizar) && (int) $model->user_id !== (int) $this->userId()) {
            Log::warning('PriceTypeController@update: pedido de sincronizar el margen sobre la lista '.$model->id.' de otro dueño; se ignora.');
            $pedido_de_sincronizar = null;
        }

        if (!is_null($pedido_de_sincronizar)) {

            $cantidad = PriceTypeHelper::sincronizar_margen(
                $model,
                $old_percentage,
                $pedido_de_sincronizar['alcance'],
                $pedido_de_sincronizar['incluir_precio_fijado_a_mano']
            );

            $notifications[] = PriceTypeHelper::notificacion_de_sincronizar_margen($cantidad);

        } else if (is_null($request->sincronizar_margen)) {

            $modo_del_spa_viejo = $request->update_existing_articles_percentage_mode;

            // Solo sincroniza pivots cuando realmente cambia el porcentaje por defecto.
            if (in_array($modo_del_spa_viejo, ['all', 'only_default_matches'], true)
                && (string) $old_percentage !== (string) $model->percentage) {

                Log::info('Cambio el percentage de '.$model->name.' (modo del SPA viejo: '.$modo_del_spa_viejo.')');
                PriceTypeHelper::sync_existing_articles_percentage(
                    $model,
                    $old_percentage,
                    $modo_del_spa_viejo
                );
            }
        }

        GeneralHelper::attachModels($model, 'categories', $request->categories, ['percentage']);
        GeneralHelper::attachModels($model, 'sub_categories', $request->sub_categories, ['percentage']);
        
        $this->sendAddModelNotification('price_type', $model->id);

        PriceTypeHelper::check_recargos($model);

        // Cambiar el nombre, la posición o la visibilidad de una lista cambia qué fila se copia a
        // `combos.price`; el porcentaje cambia el precio de los componentes. Ver el método.
        $this->recalcular_combos_calculados();

        return response()->json([
            'model'         => $this->fullModel('PriceType', $model->id),
            'notifications' => $notifications,
        ], 200);
    }

    public function destroy($id) {
        $model = PriceType::find($id);

        // Eliminar relaciones con artículos
        $model->articles()->detach();
        
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('PriceType', $model->id);

        // Sus filas de `combo_price_type` ya no apuntan a nada, y los combos calculados pierden esa
        // lista (y `combos.price` puede pasar a otra). Ver el método.
        ComboCalculadoHelper::olvidar_lista($model->id);
        $this->recalcular_combos_calculados();

        return response(null);
    }

    /**
     * Recalcula los combos calculados del dueño después de tocar una lista de precios.
     *
     * Antes de esto solo el ALTA de una lista los recalculaba (por ProcessSetFinalPrices, que cierra
     * con el recálculo de combos); editar o borrar una lista los dejaba con el precio por lista
     * viejo hasta la red de seguridad de la noche: `combos.price` (lo único que lee una tienda
     * vieja) seguía saliendo de la lista que ya no era la por defecto, y una lista borrada seguía
     * con su fila.
     *
     * 🔴 Una falla acá NUNCA tumba al que llamó: guardar o borrar una lista es lo importante, y un
     * combo desactualizado lo corrige el recálculo de la noche. `recalcular_de_un_dueno()` ya
     * atrapa cada combo y la guarda de esquema; el try/catch cubre lo que escape (ej. el `userId()`).
     * Empleado → dueño lo resuelve el helper.
     *
     * @return void
     */
    protected function recalcular_combos_calculados() {

        try {

            ComboCalculadoHelper::recalcular_de_un_dueno_o_encolar($this->userId());

        } catch (\Throwable $e) {

            Log::warning('PriceTypeController: no se pudieron recalcular los combos calculados', [
                'motivo' => $e->getMessage(),
            ]);
        }
    }
}
