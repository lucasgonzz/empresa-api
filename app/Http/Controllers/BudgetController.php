<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\Budget\BudgetDuplicarHelper;
use App\Http\Controllers\Helpers\BudgetHelper;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\PriceTypeHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\currentAcount\CuentaCorrienteLock;
use App\Http\Controllers\Helpers\sale\ForzarTotalEsquemaHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Pdf\BudgetPdf;
use App\Models\Budget;
use App\Models\Client;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Exception;
use Illuminate\Support\Facades\Log;

class BudgetController extends Controller
{

    /**
     * Estados de presupuesto, de `BudgetStatusSeeder`: se siembran en este orden y la tabla
     * `budget_statuses` es GLOBAL, no por usuario, y de solo lectura (`BudgetStatusController` solo
     * expone `index()`). O sea que los ids son fijos y no los puede inventar un cliente.
     *
     * Se nombran acá porque el 1 y el 2 ya estaban hardcodeados en la SPA (`Budget.vue`
     * `show_btn_save`, `BtnActualizarEnVender` `:disabled`) y en el back no tenían nombre.
     *
     * ⚠️ `BudgetHelper::checkStatus()` compara por NOMBRE (`budget_status->name == 'Confirmado'`),
     * no por id. Son dos formas de preguntar lo mismo; si alguna vez se renombra un estado hay que
     * tocar los dos lados.
     */
    const ESTADO_SIN_CONFIRMAR = 1;
    const ESTADO_CONFIRMADO    = 2;

    public function index($from_date = null, $until_date = null) {
        $models = Budget::where('user_id', $this->userId())
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

    public function store(Request $request) {

        /*
         * Lista de precios obligatoria (misión vender-lista-obligatoria, 17/9/2026): el mismo 422
         * que `SaleController::store()`, con el mismo resolvedor. Va ANTES de la transacción para
         * que un rechazo no consuma número de presupuesto ni deje nada que revertir. Un presupuesto
         * de VENDER lleva cliente siempre, así que la lista del cliente rescata antes de llegar al
         * rechazo; el 422 queda para el cliente que tampoco tiene lista. Por qué se rechaza y no se
         * completa con la lista por defecto: docblock de PriceTypeHelper (los renglones ya vienen
         * preciados por el front y `attachArticles()` los persiste tal cual).
         *
         * `Client::find()` sin trashed, igual que la relación `Budget::client()` que después lee
         * `BudgetHelper::get_price_type_id()` al confirmar: el alta y la confirmación miran al
         * mismo cliente.
         */
        $client_del_presupuesto = $request->client_id ? Client::find($request->client_id) : null;

        $price_type_id = PriceTypeHelper::resolver_price_type_id_para_guardar($request->price_type_id, $client_del_presupuesto);

        if (is_null($price_type_id) && PriceTypeHelper::requiere_lista_de_precios($this->user())) {

            Log::info('store budget: rechazado sin lista de precios (user_id '.$this->userId().', client_id '.$request->client_id.').');

            return response()->json([
                'message'               => PriceTypeHelper::mensaje_sin_lista_presupuesto(),
                'sin_lista_de_precios'  => true,
            ], 422);
        }

        DB::beginTransaction();

        try {

            /*
                🔴 Candado de la cuenta corriente del cliente como primera sentencia (misión
                cuenta-corriente-carrera-y-velocidad, 23/9/2026): un presupuesto que nace confirmado
                crea la venta y su movimiento en la cuenta corriente. Mismo motivo y mismo orden que
                confirmar(). Ver CuentaCorrienteLock.
            */
            CuentaCorrienteLock::bloquear('client', $request->client_id);

            $model = Budget::create(ForzarTotalEsquemaHelper::agregar_al_payload([
                'num'                       => $this->num('budgets'),
                'client_id'                 => $request->client_id,
                'start_at'                  => $request->start_at,
                'finish_at'                 => $request->finish_at,
                'observations'              => $request->observations,
                // Ya resuelta y validada antes de la transacción: request → cliente → null (o 422).
                'price_type_id'             => $price_type_id,
                'sale_status_id'            => $request->sale_status_id,
                'discount_stock'            => !is_null($request->discount_stock) ? $request->discount_stock : 1,
                'iva_aplicado'              => !is_null($request->iva_aplicado) ? $request->iva_aplicado : 1,
                'total'                     => $request->total,
                'budget_status_id'          => $request->budget_status_id,
                'address_id'                => $request->address_id,
                'surchages_in_services'     => $request->surchages_in_services,
                'discounts_in_services'     => $request->discounts_in_services,
                'aplicar_recargos_directo_a_items' => $request->aplicar_recargos_directo_a_items,
                /*
                 * Default 1 (pesos) si no viaja, igual que `SaleController::store()` (tanda 2 de la
                 * mision vender-lista-obligatoria, 18/9/2026, item A6). Hasta hoy iba pelado: la
                 * columna es NOT NULL default 1, asi que un request sin la clave (la SPA la manda
                 * desde septiembre de 2025) insertaba null y el alta moria con un 500 que no
                 * nombraba la causa; en una base sin modo estricto quedaba 0, `getCost()` no
                 * cotizaba (ni `== 1` ni `== 2`) y `saveSale()` le pasaba ese 0 a la venta.
                 */
                'moneda_id'                 => !is_null($request->moneda_id) ? $request->moneda_id : 1,
                'valor_dolar'               => $request->valor_dolar,
                /*
                 * 🔴 Un presupuesto NO se puede omitir de la cuenta corriente: al confirmarlo, la
                 * venta va SIEMPRE a la cuenta del cliente (decision de Lucas, 18/9/2026, tanda 3
                 * de la mision vender-lista-obligatoria). Se fija 0 pase lo que mande el request
                 * --la SPA manda 0 desde esa fecha, y una SPA vieja podia mandar el 1 que tuviera
                 * el store de Vender-- y `BudgetHelper::saveSale()` escribe 0 en la venta. La
                 * columna queda por compatibilidad (existe desde marzo de 2026 y es NOT NULL).
                 *
                 * Por que no se honra un "omitir" en el presupuesto: la confirmacion desde el
                 * listado no trae ningun dato de cobro, y una venta de contado sin metodo de pago
                 * ni movimiento de caja es justo lo que `SaleController::store()` rechaza con el
                 * 422 `sin_metodo_de_pago`. El cobro de un presupuesto confirmado se registra como
                 * pago sobre la cuenta corriente del cliente.
                 */
                'omitir_en_cuenta_corriente' => 0,
                'employee_id'               => $this->userId(false),
                'user_id'                   => $this->userId(),
            /*
             * El monto del total forzado (mision forzar-total-por-monto, 17/9/2026): plata con
             * signo, negativo = descuento, positivo = recargo, null = no se forzo nada.
             *
             * Sin el, el `total` forzado que manda VENDER se guardaria igual pero la validacion de
             * treinta lineas mas abajo —`BudgetHelper::getTotal()` contra `$model->total`, margen
             * de 3— lo rechazaria con "El total del presupuesto no corresponde con los productos
             * ingresados", porque los renglones suman el total SIN forzar. La otra mitad del
             * arreglo esta en `BudgetHelper::getTotal()`.
             *
             * 🔴 Y entra por el helper de esquema, no como una clave mas: un deploy de empresa sube
             * los archivos ANTES de migrar, y en esa ventana `Budget` —que declara `$guarded = []`—
             * mandaria la columna en el INSERT aunque valga null, tumbando el alta de TODO
             * presupuesto. Ver `ForzarTotalEsquemaHelper`.
             */
            ], SaleHelper::normalized_forzar_total_monto($request), 'budgets'));
            GeneralHelper::attachModels($model, 'discounts', $request->discounts, ['percentage'], false);
            GeneralHelper::attachModels($model, 'surchages', $request->surchages, ['percentage'], false);

            $previus_articles = $model->articles;

            BudgetHelper::attachArticles($model, $request->articles);

            BudgetHelper::attachServices($model, $request->services);
            BudgetHelper::attachPromocionVinotecas($model, $request->promocion_vinotecas);
            BudgetHelper::attachCombos($model, $request->combos);

            BudgetHelper::checkStatus($this->fullModel('Budget', $model->id), $previus_articles);

            $this->sendAddModelNotification('Budget', $model->id);



            $total_helper = (int)BudgetHelper::getTotal($model);
            $total_budget = (int)$model->total;

            // Calcula la diferencia absoluta
            $diferencia = abs($total_helper - $total_budget);

            if ($diferencia > 3) {
                Log::info('Total mal para presupuesto '.$model->id);
                Log::info('total_helper: '.$total_helper);
                Log::info('total_budget: '.$total_budget);

                $message = 'El total del presupuesto no corresponde con los productos ingresados';
                
                throw new Exception($message);
            }

            DB::commit();

            return response()->json(['model' => $this->fullModel('Budget', $model->id)], 201);

        } catch(\Throwable $e) {

            DB::rollBack();

            // El reporter de errores esta enganchado al handler global (Handler::register ->
            // reportable), que Laravel solo invoca para excepciones NO manejadas. Como esta la
            // capturamos nosotros para poder hacer rollback y responder 500, hay que empujarla a
            // mano con report(): sin esta linea el fallo no llega a errores/ y no existe para
            // nadie. Ya paso: dos actualizaciones de venta de Fenix que murieron por lock wait
            // timeout el 7/8/2026 no dejaron rastro fuera de este archivo de log.
            report($e);

            return response()->json(['error' => true, 'message' => $e->getMessage()], 500);
        }
    }  

    /**
     * Duplica un presupuesto existente (mismo usuario) en uno nuevo en estado sin confirmar.
     *
     * @param int|string $id Identificador del presupuesto origen.
     * @return \Illuminate\Http\JsonResponse Modelo creado o error 500.
     */
    public function duplicate($id) {

        if (!UserHelper::hasExtencion('duplicar_presupuestos')) {
            return response()->json(['error' => true, 'message' => 'No autorizado'], 403);
        }

        DB::beginTransaction();

        try {

            /** Origen con el mismo criterio de carga que el listado / alta. */
            $source = Budget::withAll()->find($id);
            if (is_null($source)) {
                throw new Exception('Presupuesto no encontrado');
            }

            $model = BudgetDuplicarHelper::duplicate($source, $this);

            $this->sendAddModelNotification('Budget', $model->id);

            $total_helper = (int) BudgetHelper::getTotal($model);
            $total_budget = (int) $model->total;

            $diferencia = abs($total_helper - $total_budget);

            if ($diferencia > 3) {
                Log::info('Total mal para presupuesto duplicado '.$model->id);
                Log::info('total_helper: '.$total_helper);
                Log::info('total_budget: '.$total_budget);

                throw new Exception('El total del presupuesto no corresponde con los productos ingresados');
            }

            DB::commit();

            return response()->json(['model' => $this->fullModel('Budget', $model->id)], 201);

        } catch (\Throwable $e) {

            DB::rollBack();

            // El reporter de errores esta enganchado al handler global (Handler::register ->
            // reportable), que Laravel solo invoca para excepciones NO manejadas. Como esta la
            // capturamos nosotros para poder hacer rollback y responder 500, hay que empujarla a
            // mano con report(): sin esta linea el fallo no llega a errores/ y no existe para
            // nadie. Ya paso: dos actualizaciones de venta de Fenix que murieron por lock wait
            // timeout el 7/8/2026 no dejaron rastro fuera de este archivo de log.
            report($e);

            return response()->json(['error' => true, 'message' => $e->getMessage()], 500);
        }
    }

    public function show($id) {
        return response()->json(['model' => $this->fullModel('Budget', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = Budget::find($id);

        if (is_null($model)) {
            return response()->json(['message' => 'El presupuesto no existe.'], 404);
        }

        /*
            Un presupuesto confirmado no se edita: primero se anula.

            La interfaz ya lo impedia por dos lados (Budget.vue esconde el boton de guardar cuando
            budget_status_id == 2, y BtnActualizarEnVender esta disabled para ese estado), pero el
            endpoint quedaba alcanzable con la sesion abierta. Y llegar aca con un presupuesto
            confirmado significaba borrar y recrear su venta, incluso si ya estaba facturada, porque
            deleteSale() llama a SaleController::destroy() y ese metodo no chequea el AfipTicket.
            Mismo criterio que SaleHelper::motivo_por_el_que_no_se_puede_editar(): la decision vive
            en el back, no solo en el front.
        */
        if ($model->budget_status_id == Self::ESTADO_CONFIRMADO) {

            return response()->json([
                'message' => 'El presupuesto esta confirmado. Anulalo antes de editarlo.',
            ], 422);
        }

        /*
            Lista de precios obligatoria (mision vender-lista-obligatoria, 17/9/2026). Mismo patron
            que `SaleController::update()` y que `aplicar_recargos_directo_a_items` mas abajo: SOLO
            si el request manda la clave. La SPA anterior no manda `price_type_id` en el PUT de
            presupuestos (`vender_presupuestos.js::actualizar()` recien lo manda desde esta mision),
            asi que a secas cualquier edicion desde esa SPA dejaria el presupuesto sin lista y la
            venta que nace al confirmarlo saldria con la del cliente o con ninguna. Clave ausente =
            se preserva lo guardado.

            Clave presente y en null (o en 0, que se lee como null: ver PriceTypeHelper) con la
            cuenta trabajando con listas = 422 ANTES de escribir nada. Desde la tanda 2 de la
            mision (18/9/2026, item A1) este metodo corre en una transaccion, pero el 422 sigue
            yendo antes de abrirla: es una respuesta, no un fallo que haya que revertir.
        */
        $actualizar_price_type_id = $request->exists('price_type_id');

        $price_type_id_nuevo = null;

        if ($actualizar_price_type_id) {

            $price_type_id_nuevo = PriceTypeHelper::normalizar_price_type_id($request->price_type_id);

            if (is_null($price_type_id_nuevo) && PriceTypeHelper::requiere_lista_de_precios($this->user())) {

                Log::info('update budget id '.$id.': rechazado sin lista de precios.');

                return response()->json([
                    'message'               => PriceTypeHelper::mensaje_sin_lista_presupuesto(),
                    'sin_lista_de_precios'  => true,
                ], 422);
            }
        }

        /*
            🔴 TODO LO QUE SIGUE ES UNA SOLA TRANSACCION (tanda 2 de la mision
            vender-lista-obligatoria, 18/9/2026, item A1). Hasta hoy este metodo era el unico de la
            clase sin `DB::beginTransaction()` ni try/catch: store(), duplicate(), confirmar() y
            anular() envuelven todo. Y el orden de adentro lo hacia peligroso: el `save()` de los
            campos y el detach TOTAL de los articulos (`BudgetHelper::attachArticles()` empieza por
            `detach()`) van ANTES de `attachArticles()`, `attachCombos()` y `checkStatus()`. Un
            fallo en cualquiera de esos tres —un renglon mal formado, un `budget_status_id` que no
            existe y deja `$budget->budget_status` en null— respondia 500 con el `total` ya
            cambiado y el presupuesto SIN RENGLONES, o con los renglones a medias. El vendedor veia
            un error y, si volvia a abrir el presupuesto, lo encontraba vacio.

            Los 404 y 422 de arriba quedan afuera a proposito: no escriben nada y no tienen que
            abrir ni cerrar nada. El orden de lo de adentro NO se cambia; solo se lo hace atomico.
            Mismo estilo que store(): `report()` a mano porque el reporter global solo corre para
            excepciones no manejadas.
        */
        DB::beginTransaction();

        try {

            /*
                🔴 Candado de la cuenta corriente del cliente que tenía y del que queda, como primera
                sentencia (misión cuenta-corriente-carrera-y-velocidad, 23/9/2026). Guardar un
                presupuesto confirmado borra la venta vieja y crea la nueva (BudgetHelper::checkStatus),
                con su stock y su movimiento: la cuenta tiene que quedar tomada ANTES del stock, el mismo
                orden que la edición de una venta. Ver CuentaCorrienteLock.
            */
            CuentaCorrienteLock::bloquear('client', [$model->client_id, $request->client_id]);

            /*
                Se lee el estado GUARDADO antes de pisarlo con el del request: es lo que despues permite
                saber si el estado cambio de verdad en este update.
            */
            $estado_anterior = $model->budget_status_id;

            $model->client_id                 = $request->client_id;
            $model->start_at                  = $request->start_at;
            $model->finish_at                 = $request->finish_at;
            $model->observations              = $request->observations;
            $model->total                     = $request->total;
            /*
                El monto del total forzado, asignado PELADO y junto a `total` (mision
                forzar-total-por-monto, 17/9/2026).

                🔴 Sin guarda de `$request->exists()`, al reves que `aplicar_recargos_directo_a_items`
                cinco lineas mas abajo, y por el mismo motivo que en `SaleController::update()`: el
                monto esta definido CONTRA el total de la linea de arriba, que se asigna pelado. Los dos
                se escriben juntos o el presupuesto queda con un total sin forzar y un monto de forzado
                viejo colgando, y en ese estado `BudgetHelper::getTotal()` suma el monto de mas y el
                proximo guardado muere con "El total del presupuesto no corresponde con los productos
                ingresados".

                ⚠️ Adentro de la guarda de esquema por el mismo motivo que el alta: entre que el deploy
                sube los archivos y corre las migraciones, la columna puede no existir y esta asignacion
                tumbaria la actualizacion de cualquier presupuesto. Ver `ForzarTotalEsquemaHelper`.
            */
            if (ForzarTotalEsquemaHelper::hay_columna_en_budgets()) {
                $model->forzar_total_monto    = SaleHelper::normalized_forzar_total_monto($request);
            }
            $model->budget_status_id          = $request->budget_status_id;
            $model->address_id                = $request->address_id;
            // Misma guarda que en SaleController::update(): sin la clave, la lista guardada no se toca.
            if ($actualizar_price_type_id) {
                $model->price_type_id         = $price_type_id_nuevo;
            }
            /*
                Un presupuesto no se puede omitir de la cuenta corriente (decision de Lucas,
                18/9/2026): en la edicion se fija 0 pase lo que mande el request, igual que en el
                alta. Hasta la tanda 2 de la mision la clave ni se guardaba; en la tanda 2 se guardo
                con `exists()`, y en la tanda 3 quedo asi.
            */
            $model->omitir_en_cuenta_corriente = 0;

            $model->surchages_in_services     = $request->surchages_in_services;
            $model->discounts_in_services     = $request->discounts_in_services;
            /*
                Se PRESERVA el valor guardado si el request no trae el campo, igual que `discount_stock`
                e `iva_aplicado` dos lineas mas abajo, y a diferencia de `SaleController::update()`, que
                lo asigna pelado.

                El motivo es la SPA VIEJA, no la actual: `vender_presupuestos.js::actualizar()` SI manda
                este campo desde esta misma tanda, pero la api y la spa no llegan juntas a produccion y
                entre un despliegue y el otro hay una ventana con la spa anterior, que no lo manda.

                Con una asignacion pelada --como la de `SaleController::update()`-- ese PUT dejaria el
                flag en null con los precios del pivot todavia recargados. Y no falla ahi, que es lo
                peligroso: `update()` no valida el total como `store()`, asi que el presupuesto se
                guarda mal y recien al confirmarlo la venta nace inflada el porcentaje del recargo.
            */
            $model->aplicar_recargos_directo_a_items = !is_null($request->aplicar_recargos_directo_a_items)
                                                        ? $request->aplicar_recargos_directo_a_items
                                                        : $model->aplicar_recargos_directo_a_items;
            // Sin la clave se preserva la guardada (columna NOT NULL): mismo motivo que el default 1 de store().
            $model->moneda_id                 = !is_null($request->moneda_id) ? $request->moneda_id : $model->moneda_id;
            $model->sale_status_id            = $request->sale_status_id;
            $model->discount_stock            = !is_null($request->discount_stock) ? $request->discount_stock : $model->discount_stock;
            $model->iva_aplicado              = !is_null($request->iva_aplicado) ? $request->iva_aplicado : $model->iva_aplicado;

            $model->save();
            GeneralHelper::attachModels($model, 'discounts', $request->discounts, ['percentage'], false);
            GeneralHelper::attachModels($model, 'surchages', $request->surchages, ['percentage'], false);
        
            $previus_articles = $model->articles;

            BudgetHelper::attachArticles($model, $request->articles, true);
            BudgetHelper::attachServices($model, $request->services);
            BudgetHelper::attachPromocionVinotecas($model, $request->promocion_vinotecas);
            /*
                Va DESPUES de los otros tres y antes de `checkStatus()`: si este update confirma el
                presupuesto, `checkStatus()` crea la venta leyendo `$budget->combos` de la base, asi que
                los combos ya tienen que estar adjuntados.
            */
            BudgetHelper::attachCombos($model, $request->combos);

            /*
                🔴 checkStatus() SOLO si el estado cambio de verdad.

                Hasta el 21/8/2026 esto se llamaba en cada update, y checkStatus() arranca siempre por
                deleteCurrentAcount() + deleteSale() antes de mirar el estado. O sea que guardar un
                presupuesto confirmado —aunque no se le tocara nada— borraba su venta y creaba una
                nueva CON NUMERO NUEVO (saveSale usa $ct->num('sales')), devolviendo y volviendo a
                descontar el stock y rehaciendo el movimiento de cuenta corriente.

                El guard del estado confirmado de mas arriba ya corta el caso peor. Esta condicion cubre
                el resto: un update que no toca el estado no tiene por que pasar por el borrado.

                Por que condicional y no sacarlo del todo (decision de Lucas, 21/8/2026): asi una SPA
                todavia no actualizada, que confirma cambiando el select y guardando, sigue creando la
                venta igual. El orden de despliegue entre api y spa deja de importar.
            */
            if ($estado_anterior != $model->budget_status_id) {

                BudgetHelper::checkStatus($this->fullModel('Budget', $model->id), $previus_articles);
            }

            DB::commit();

            /*
                La notificacion va DESPUES del commit, como en confirmar() y anular(): una
                notificacion de un update que despues se revierte le anunciaria a la SPA un
                presupuesto que no cambio.
            */
            $this->sendAddModelNotification('Budget', $model->id);
            return response()->json(['model' => $this->fullModel('Budget', $model->id)], 200);

        } catch (\Throwable $e) {

            DB::rollBack();

            // report() a mano por el mismo motivo que en store(): el reporter global solo corre
            // para excepciones NO manejadas, y esta la capturamos nosotros.
            report($e);

            return response()->json(['error' => true, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Confirma un presupuesto y le crea la venta.
     *
     * Reemplaza al gesto viejo de cambiar el select de estado y guardar el presupuesto entero
     * (pedido de Lucas en el audio 2.6 del volcado y en prompt_161). La diferencia no es solo de
     * comodidad: por el camino de `update()` la confirmacion viajaba adentro de un guardado
     * completo, asi que confirmar y editar eran el mismo request y no se podian distinguir.
     *
     * Idempotente a proposito: confirmar dos veces no crea dos ventas.
     *
     * @param  int|string $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function confirmar($id) {

        DB::beginTransaction();

        try {

            /*
                lockForUpdate ademas del filtro por usuario: sin el, dos POST simultaneos —dos
                pestañas, el modal abierto sobre el listado (que monta DOS instancias del boton,
                cada una con su propio `loading`), o un reintento del cliente HTTP— leen los dos
                "no hay venta" y crean DOS. Y despues no se puede arreglar desde la interfaz,
                porque anular() borra una sola con ->first() y deja la otra viva.

                El chequeo de `loading` del boton es por instancia: no coordina nada entre
                pestañas. La exclusion tiene que estar acá.
            */
            $model = Budget::where('id', $id)
                            ->where('user_id', $this->userId())
                            ->lockForUpdate()
                            ->first();

            if (is_null($model)) {
                DB::rollBack();
                return response()->json(['message' => 'El presupuesto no existe.'], 404);
            }

            /*
                🔴 Candado de la cuenta corriente del cliente, después del del presupuesto y antes de
                cualquier lectura común (misión cuenta-corriente-carrera-y-velocidad, 23/9/2026):
                confirmar crea la venta y su movimiento en la cuenta corriente. Tomarlo acá, y no
                recién cuando CurrentAcountFromSaleHelper lo pide, deja el mismo orden que la edición
                de una venta (cuenta antes que stock) y evita que las dos se esperen en círculo.
            */
            CuentaCorrienteLock::bloquear('client', $model->client_id);

            /*
                Sin withTrashed: una venta borrada por una anulacion anterior no cuenta como venta
                existente, justamente para que se pueda volver a confirmar despues de anular.
            */
            $venta_existente = Sale::where('budget_id', $model->id)->first();

            if ($model->budget_status_id == Self::ESTADO_CONFIRMADO && !is_null($venta_existente)) {

                DB::commit();

                return response()->json(['model' => $this->fullModel('Budget', $model->id)], 200);
            }

            $model->budget_status_id = Self::ESTADO_CONFIRMADO;
            $model->save();

            /*
                saveSale() tiene su propio guard `is_null($budget->sale)`, asi que un presupuesto que
                quedo confirmado sin venta (estado inconsistente) se repara al confirmarlo de nuevo.

                El segundo parametro es $previus_articles, que checkStatus/saveSale/attachSaleArticles
                se pasan entre si y ninguno lee. Se manda la coleccion actual para no cambiar la firma,
                que la usan tambien store() y update().
            */
            BudgetHelper::saveSale($this->fullModel('Budget', $model->id), $model->articles);

            DB::commit();

            $this->sendAddModelNotification('Budget', $model->id);

            return response()->json(['model' => $this->fullModel('Budget', $model->id)], 200);

        } catch(\Throwable $e) {

            DB::rollBack();

            /*
                Sin transaccion, un fallo a mitad de saveSale() dejaba la venta creada, el stock ya
                descontado y el presupuesto en estado 2, con un 500 en la cara del usuario. El caso
                concreto que lo dispara: un cliente sin `credit_accounts` para la moneda del
                presupuesto hace que CurrentAcountFromSaleHelper reviente en su linea 54, DESPUES de
                haber creado la venta y descontado el stock.

                report() a mano por el mismo motivo que en store(): el reporter global solo corre
                para excepciones NO manejadas, y esta la capturamos nosotros.
            */
            report($e);

            return response()->json(['error' => true, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Anula un presupuesto confirmado: le borra la venta y lo devuelve a "Sin confirmar".
     *
     * 🔴 Antes de esto NO habia forma de desconfirmar un presupuesto desde la interfaz. El select de
     * estado solo se puede cambiar guardando, y `Budget.vue` esconde el boton de guardar cuando el
     * presupuesto esta confirmado: un confirmado era un callejon sin salida.
     *
     * @param  int|string $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function anular($id) {

        DB::beginTransaction();

        try {

            $model = Budget::where('id', $id)
                            ->where('user_id', $this->userId())
                            ->lockForUpdate()
                            ->first();

            if (is_null($model)) {
                DB::rollBack();
                return response()->json(['message' => 'El presupuesto no existe.'], 404);
            }

            if ($model->budget_status_id != Self::ESTADO_CONFIRMADO) {
                DB::rollBack();
                return response()->json(['message' => 'El presupuesto no esta confirmado.'], 422);
            }

            $sale = Sale::where('budget_id', $model->id)->first();

            /*
                🔴 Se valida ANTES de tocar nada. Un 422 no puede dejar el presupuesto desconfirmado
                con la venta viva: quedarian los dos estados peleados.

                Se pregunta por la regla completa y no solo por la factura (decision de Lucas,
                21/8/2026). El motivo es que BudgetHelper::deleteSale() llama a
                SaleController::destroy() con un Request vacio, o sea con compensar_caja en false: si
                la venta ya movio una caja, borrarla descuadra el arqueo sin dejar movimiento
                compensatorio. La regla de motivo_por_el_que_no_se_puede_editar() ya cubre ese caso y
                varios mas (facturada, cerrada, mas de un metodo de pago), y esta centralizada desde
                el 3/8/2026 justamente para no duplicarla en cada controlador.
            */
            if (!is_null($sale)) {

                /*
                    El segundo argumento en true es el criterio CONSERVADOR de tickets:
                    cualquier AfipTicket congela la anulacion, tambien uno sin CAE. Desde el
                    24/8/2026 la EDICION de la venta solo se congela con CAE (decision de
                    Lucas: un rechazado no existe ante ARCA y no debe trabar el mostrador),
                    pero anular BORRA la venta, y con un ticket pendiente-sin-respuesta el
                    borrado podria dejar un CAE huerfano si ARCA aprueba despues. La politica
                    de anulacion queda como estaba hasta que Lucas la revise.
                */
                $motivo = SaleHelper::motivo_por_el_que_no_se_puede_editar($sale, true);

                if (!is_null($motivo)) {
                    DB::rollBack();
                    return response()->json(['message' => $motivo], 422);
                }

                BudgetHelper::deleteSale($model);
            }

            /*
                Va FUERA del `if ($sale)`: el movimiento de cuenta corriente se busca por budget_id,
                no depende de que exista la venta. Hoy es un no-op porque nadie llama a
                BudgetHelper::saveCurrentAcount() desde presupuestos, pero si esas filas vuelven a
                existir, un presupuesto confirmado SIN venta tiene que poder limpiarlas igual.
            */
            BudgetHelper::deleteCurrentAcount($model);

            $model->budget_status_id = Self::ESTADO_SIN_CONFIRMAR;
            $model->save();

            DB::commit();

            $this->sendAddModelNotification('Budget', $model->id);

            return response()->json(['model' => $this->fullModel('Budget', $model->id)], 200);

        } catch(\Throwable $e) {

            DB::rollBack();

            /*
                Sin transaccion, si deleteSale() reventaba a mitad quedaba el presupuesto todavia
                confirmado, sin su movimiento de cuenta corriente y con la venta viva. Mismo criterio
                que store() y duplicate(), que ya envolvian todo.
            */
            report($e);

            return response()->json(['error' => true, 'message' => $e->getMessage()], 500);
        }
    }

    public function destroy($id) {

        $model = Budget::find($id);

        // Quito esto porque los presupuestos confirmados no se pueden eliminar, y los sin confirmar no impactan en la cuenta corriente
        // if (BudgetHelper::deleteCurrentAcount($model)) {
            
        //     CurrentAcountHelper::checkSaldos($model->credit_account_id);
        //     SaleHelper::deleteSaleFrom('budget', $model->id, $this);
        //     $this->sendAddModelNotification('client', $model->client_id, false);
        // }

        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('Budget', $model->id);
        return response(null);
    }

    function pdf($id, $with_prices, $with_images) {
        $budget = Budget::find($id);

        if (is_null($budget)) {
            abort(404);
        }

        $pdf = new BudgetPdf($budget, $with_prices, $with_images);
    }
}
