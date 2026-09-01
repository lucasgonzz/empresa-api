<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\LimiteCreditoHelper;
use App\Http\Controllers\Helpers\OrderHelper;
use App\Http\Controllers\Helpers\Order\CreateSaleOrderHelper;
use App\Http\Controllers\Helpers\Order\OrderStatusHelper;
use App\Http\Controllers\Helpers\sale\DeleteSaleHelper;
use App\Http\Controllers\Pdf\OrderPdf;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{

    public function index($from_date = null, $until_date = null) {
        $models = Order::where('user_id', $this->userId())
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
        $models = OrderHelper::setArticlesVariant($models);
        return response()->json(['models' => $models], 200);
    }

    function indexUnconfirmed() {
        $models = Order::where('user_id', $this->userId())
                        ->where('order_status_id', 1)
                        ->orderBy('created_at', 'DESC')
                        ->withAll()
                        ->get();
        // $models = OrderHelper::setArticlesVariant($models);
        return response()->json(['models' => $models], 200);
    }

    public function show($id) {
        $model = $this->fullModel('Order', $id);
        $model = OrderHelper::setArticlesVariant([$model])[0];
        return response()->json(['model' => $model], 200);
    }

    public function update(Request $request, $id) {
        /**
         * Pedido a actualizar.
         * Se usa como base para evaluar transición de estado y creación de venta.
         */
        $model = Order::find($id);

        /**
         * Estado previo del pedido antes de persistir cambios.
         */
        $prev_status = $model->order_status;

        /**
         * Nombre del estado desde el que sale y al que va el pedido.
         *
         * Se resuelven por NOMBRE y no por id: `order_statuses` no tiene ids garantizados entre
         * instalaciones (cada base corre su seeder por su cuenta), asi que el id solo sirve para
         * escribir la columna, nunca para decidir.
         *
         * Si la request no trae estado, el pedido se queda donde esta: `hacia` = `desde`, que
         * `puede_pasar()` siempre permite.
         */
        $nombre_desde = $prev_status ? $prev_status->name : null;
        $nombre_hacia = $nombre_desde;

        if (!is_null($request->order_status_id)) {

            $status_pedido = OrderStatus::find($request->order_status_id);

            /**
             * 🔴 Un id que no resuelve se RECHAZA, no se ignora.
             *
             * Ignorarlo dejaba `$nombre_hacia` igual a `$nombre_desde` —o sea, la guarda de mas
             * abajo lo daba por bueno— y despues se escribia el id basura igual, porque
             * `orders.order_status_id` no tiene foreign key. El pedido quedaba en un estado que no
             * existe, y a partir de ahi `$prev_status` es null y CUALQUIER transicion se permite:
             * el candado se abria por el mismo agujero que dice cerrar.
             */
            if (is_null($status_pedido)) {

                return response()->json([
                    'message'                    => 'El estado indicado no existe.',
                    'error_transicion_de_estado' => true,
                ], 422);
            }

            $nombre_hacia = $status_pedido->name;
        }

        /**
         * 🔴 El estado de un pedido solo puede AVANZAR o cancelarse (decision de Lucas, 22/8/2026).
         *
         * Esto no es una validacion de formulario que se pueda dejar solo del lado de la SPA: desde
         * que se sacaron los botones del modal, el estado se cambia por el select generico, que
         * ofrece TODAS las filas de `order_statuses`. Sin esta guarda, mandar un pedido ya entregado
         * de vuelta a "Sin confirmar" dejaba una venta viva colgada de un pedido sin confirmar, y el
         * candado de `sales.order_id` impedia despues volver a crearla.
         *
         * Va ANTES de abrir la transaccion porque no necesita escribir nada para decidir.
         */
        if (!OrderStatusHelper::puede_pasar($nombre_desde, $nombre_hacia)) {

            return response()->json([
                'message'                    => OrderStatusHelper::motivo_del_rechazo($nombre_desde, $nombre_hacia),
                'error_transicion_de_estado' => true,
            ], 422);
        }

        /**
         * Todo el update va en una transacción (prompt 610).
         *
         * El chequeo de límite de crédito no puede correr antes de escribir: el estado nuevo y —si
         * la request los trae— los renglones y el total se resuelven acá adentro, y el total es
         * justamente el número contra el que hay que comparar. Así que corre abajo, con el pedido
         * ya actualizado, y un rechazo hace `DB::rollBack()` explícito para no dejar el pedido
         * confirmado sin su venta. Mismo patrón que `SaleController::update()`.
         */
        DB::beginTransaction();

        try {

            /**
             * 🔴 Candado contra la DOBLE CONFIRMACIÓN bajo carrera (tanda correctivos 2408,
             * ítem 10). La transacción ya estaba, pero el candado de idempotencia de más
             * abajo era un `Sale::where('order_id')->exists()` SIN lock: dos updates
             * simultáneos del mismo pedido (dos pestañas, un reintento del cliente HTTP)
             * leían los dos "no hay venta" y creaban DOS ventas del mismo pedido.
             *
             * El SELECT ... FOR UPDATE sobre la fila del pedido serializa los dos requests:
             * el segundo espera acá hasta el commit del primero, y recién entonces evalúa
             * `$has_sale`, que ya ve la venta creada. Es el mismo patrón con el que los
             * presupuestos cerraron esta misma carrera (BudgetController::confirmar()).
             *
             * NO se agrega unique a `sales.order_id` a propósito: `sales` usa SoftDeletes,
             * y la venta borrada por una cancelación sigue ocupando el valor — un unique
             * rompería cualquier flujo legítimo que vuelva a crear la venta del pedido.
             *
             * No se reasigna $model: la validación de transición de arriba ya corrió sobre
             * la foto leída y la máquina de estados no se toca en este ítem. Lo que importa
             * es el lock de la fila, no releer los atributos.
             */
            Order::where('id', $model->id)->lockForUpdate()->first();

            /**
             * Este endpoint acepta payloads PARCIALES: cada campo se toca solo si la request lo trae.
             *
             * 🔴 No lo simplifiques a las asignaciones directas de antes. Tiene dos consumidores con
             * payloads distintos: el formulario del pedido manda el modelo entero (con `articles` y
             * `address_id`), y hay payloads parciales que mandan unicamente `order_status_id`
             * (hasta el 22/8/2026 los mandaba el boton "Confirmar pedido" del modal, que se saco;
             * el endpoint los sigue aceptando y hay tests que los cubren). Con las asignaciones incondicionales, ese boton dejaba `address_id`
             * en null y —peor— `GeneralHelper::attachModels()` arranca con un `detach()`
             * incondicional (GeneralHelper.php:103), asi que el pedido perdia TODOS sus renglones y
             * la venta que nace mas abajo salia vacia, sin descontar stock.
             *
             * `order_status_id` entra en la misma regla y no por simetria: `orders.order_status_id` es
             * NOT NULL, asi que un payload que no lo traiga rompia con un QueryException 500.
             *
             * Se pregunta por `is_null()` y no por `has()` por el mismo motivo que los renglones usan
             * `is_array()`: `has()` da true tambien cuando la clave viene con valor null, que es
             * justo el caso que la columna no acepta. Una guarda que deja pasar lo que dice frenar no
             * es una guarda.
             */
            if (!is_null($request->order_status_id)) {
                $model->order_status_id = $request->order_status_id;
            }

            if ($request->has('address_id')) {
                $model->address_id = $request->address_id;
            }

            $model->save();

            /**
             * Los renglones se re-adjuntan —y el total se recalcula— SOLO cuando la request los trae.
             *
             * Se pregunta por `is_array()` y no por `has()`: `has()` da true tambien para
             * `articles: null`, y ahi el `detach()` de attachModels ya borro todo antes de que el
             * `foreach(null)` tire el error. La guarda tiene que impedir que se entre, no avisar
             * despues.
             *
             * 🔴 Y el recalculo del total va adentro del if, no afuera. Si nadie toco los renglones no
             * hay nada que recalcular: escribir `total` igual es pisar un dato que lo puso otro (el
             * pedido nace del lado de la tienda) con el resultado de una cuenta propia. Para un pedido
             * de la tienda hoy los dos numeros coinciden —`orders.total` guarda el subtotal de
             * articulos, misma cuenta que `OrderHelper::get_total()`; ver el docblock de
             * `OrderTotalsHelper` en tienda-api—, pero eso es una coincidencia de formulas, no un
             * contrato, y no es motivo para reescribir el campo en cada cambio de estado.
             */
            if (is_array($request->articles)) {

                $this->adjuntar_renglones($model, $request->articles);

                $model->total = OrderHelper::get_total($model);
                $model->save();
            }

            /**
             * Estado nuevo del pedido luego del update.
             * Se carga para validar regla de transición y evitar crear venta al cancelar.
             */
            $model->load('order_status');

            /**
             * Indica si ya existe una venta vinculada al pedido.
             * Evita duplicados cuando el usuario vuelve a pasar por estados posteriores.
             */
            $has_sale = Sale::where('order_id', $model->id)->exists();

            /**
             * Cancelar un pedido que ya tiene venta la DA DE BAJA, con todo lo que eso implica
             * (decision de Lucas, 22/8/2026).
             *
             * 🔴 Y no llama a `OrderHelper::restartArticleStock()`, que es lo que hacia el
             * `cancel()` que esta mision borro. Ese metodo devolvia stock que el PEDIDO nunca
             * descontó —el pedido de la tienda no toca stock; el unico que descuenta es la venta—,
             * asi que cancelar uno sin confirmar inflaba el inventario con unidades que no
             * existian. El stock sigue a la VENTA: lo devuelve `DeleteSaleHelper::eliminar_venta()`,
             * que es quien lo habia descontado.
             *
             * La baja usa el mismo helper que `SaleController@destroy`, sin compensar caja: la
             * venta de un pedido va siempre a cuenta corriente cuando el comprador tiene cliente
             * vinculado, y cuando no lo tiene hoy no entra a ninguna caja (hueco conocido, anotado
             * en `ideas/ideas.md` el 22/8/2026).
             */
            if (OrderStatusHelper::es_la_cancelacion($nombre_desde, $nombre_hacia)) {

                $venta_del_pedido = Sale::where('order_id', $model->id)->first();

                if (!is_null($venta_del_pedido)) {

                    /**
                     * No se borra una venta con comprobante fiscal vivo.
                     *
                     * Si tiene factura emitida y todavia no se le hizo la nota de credito, borrarla
                     * dejaria un comprobante en ARCA sin nada detras. Primero la nota de credito,
                     * despues la cancelacion. Es la misma distincion que ya hace `destroy()` para
                     * decidir si toca la cuenta corriente.
                     */
                    if (
                        count($venta_del_pedido->afip_tickets) > 0
                        && count($venta_del_pedido->nota_credito_afip_tickets) == 0
                    ) {

                        DB::rollBack();

                        return response()->json([
                            'message'                   => 'La venta de este pedido (N° '.$venta_del_pedido->num.') ya está facturada. Hacé la nota de crédito antes de cancelar el pedido.',
                            'error_venta_facturada'     => true,
                        ], 422);
                    }

                    DeleteSaleHelper::eliminar_venta($venta_del_pedido, $this);
                }
            }

            /**
             * La venta nace en la PRIMERA salida de "Sin confirmar" hacia algo que no sea
             * "Cancelado". Vale tambien para los saltos: "Sin confirmar" -> "Entregado" confirma
             * igual, porque saltear hacia adelante esta permitido.
             *
             * `!$has_sale` sigue siendo el candado de idempotencia (`sales.order_id`).
             */
            if (
                OrderStatusHelper::es_la_confirmacion($nombre_desde, $nombre_hacia)
                && !$has_sale
            ) {

                /**
                 * Límite de crédito del cliente al confirmar el pedido (prompt 610).
                 *
                 * Hasta este prompt el límite sólo se miraba en `SaleController::store()`/`update()`.
                 * La venta de un pedido la crea `CreateSaleOrderHelper` sin pasar por ahí, así que un
                 * cliente al tope de su límite no podía comprar en el mostrador y sí desde la tienda.
                 *
                 * Lo que garantiza que un rechazo no deje nada escrito es la TRANSACCIÓN de
                 * arriba, no la posición de estas líneas: el `rollBack()` del 422 revierte por
                 * igual el estado del pedido, el stock y el movimiento de cuenta corriente. Que el
                 * chequeo esté antes de `save_sale()` es para no hacer el trabajo al pedo
                 * —`attachProperies()` descuenta stock, crea comisiones, toca caja y puntos— ni
                 * tomar los locks de `num('sales')` para después revertirlos.
                 *
                 * ⚠️ Y como el que aísla es el rollback, esto depende de InnoDB: con tablas MyISAM
                 * es un no-op silencioso.
                 *
                 * 🔴 Va en el controller y no adentro de `save_sale()` porque los caminos de
                 * Tienda Nube y Mercado Libre también pasan por ese helper y no tienen a quién
                 * devolverle un 422 (`OrderDownloaderService` corre sin request). Además esas
                 * ventas nacen sin `client_id`, o sea sin cuenta corriente que pueda excederse.
                 */
                $error_limite_credito = $this->chequear_limite_credito_del_pedido($model, $request);

                if (!is_null($error_limite_credito)) {

                    DB::rollBack();
                    return response()->json($error_limite_credito, 422);
                }

                CreateSaleOrderHelper::save_sale($model, $this);
            }

            DB::commit();

        } catch (\Throwable $e) {

            DB::rollBack();

            /*
                Se re-lanza y NO se llama a report(). El patrón de SaleController::store() sí lo
                llama, pero porque allá la excepción se come y se responde un 500 armado a mano:
                sin ese report() el fallo no llegaría a errores/. Acá la excepción sigue viaje al
                handler global, que la reporta él. Agregar report() la dejaría dos veces.
            */
            throw $e;
        }

        $this->sendAddModelNotification('Order', $model->id);
        return response()->json(['model' => $this->fullModel('Order', $model->id)], 200);
    }

    /**
     * Cuerpo del 422 del límite de crédito, o null si el pedido se puede confirmar.
     *
     * 🔴 El aviso es SALTEABLE (decisión de Lucas, 22/8/2026): con `ignorar_limite_credito` en el
     * request, el dueño confirma igual. Es la diferencia con el mostrador, donde el mismo límite
     * es freno duro (misión 160): allá hay un vendedor con el cliente adelante y se corrige en el
     * momento; acá el pedido ya lo mandó el comprador y el que confirma está mirando una tanda.
     *
     * Los tres valores se derivan con los MISMOS helpers que `CreateSaleOrderHelper::createSale()`
     * va a usar un instante después, para que el chequeo no mida una cosa y se guarde otra.
     *
     * @param  \App\Models\Order  $model  Pedido ya pasado al estado nuevo.
     * @param  \Illuminate\Http\Request  $request
     * @return array|null
     */
    private function chequear_limite_credito_del_pedido($model, $request) {

        // `boolean()` y no el truthy pelado: por form-data la bandera puede llegar como el string
        // "false", que a secas daría true y saltearía el aviso justo cuando nadie lo pidió.
        if ($request->boolean('ignorar_limite_credito')) {
            return null;
        }

        // El pedido puede quedar en un estado que igual no crea venta (por ejemplo con
        // `save_sale_after_finish_order` apagado): ahí no hay nada que chequear.
        if (!CreateSaleOrderHelper::va_a_crear_venta($model)) {
            return null;
        }

        return LimiteCreditoHelper::validar_pedido_confirmado(
            CreateSaleOrderHelper::get_client_id($model, false, false),
            CreateSaleOrderHelper::get_to_check(),
            $model->total
        );
    }

    public function destroy($id) {
        $model = Order::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('Order', $model->id);
        return response(null);
    }

    /**
     * Re-adjunta los renglones del pedido CONSERVANDO las columnas del pivote que el formulario no
     * maneja.
     *
     * 🔴 Reemplaza a `GeneralHelper::attachModels($model, 'articles', ..., ['price','amount'])`, y
     * el motivo es que desde el 22/8/2026 este es el UNICO camino que queda.
     *
     * `article_order` tiene nueve columnas (`Order::articles()` las declara con `withPivot`):
     * `cost`, `price`, `amount`, `variant_id`, `color_id`, `size_id`, `with_dolar`, `address_id` y
     * `notes`. `attachModels` hace `detach()` y re-`attach()` escribiendo SOLO las que se le pasan,
     * asi que las otras siete quedaban en null.
     *
     * Antes eso era alcanzable solo si alguien abria el formulario y guardaba, porque el boton
     * "Confirmar pedido" mandaba unicamente `order_status_id` y no entraba nunca por aca. Al
     * sacarse el boton, el formulario manda SIEMPRE el modelo entero: cada cambio de estado pasaba
     * a borrar la variante, el talle, el color, la nota del comprador y —lo mas caro— el `cost`
     * congelado del pedido, con lo cual `CreateSaleOrderHelper::attach_sale_properties()` caia al
     * costo ACTUAL del articulo (`$article->pivot->cost ?? $article->cost`) y la venta nacia con el
     * margen mal calculado.
     *
     * Regla: lo que viene en la request manda; lo que no viene se conserva del pivote que ya
     * estaba. `price` y `amount` van siempre desde la request, que es lo que el formulario edita.
     *
     * ⚠️ Limitacion conocida: la foto de los pivotes previos se indexa por `article_id`. Un pedido
     * con el MISMO articulo en dos renglones (dos variantes distintas) recibe en los dos el pivote
     * del ultimo. Sigue siendo estrictamente mejor que dejarlos en null, pero si algun dia hace
     * falta resolverlo bien, el camino es que el front mande el id del pivote.
     *
     * @param  \App\Models\Order  $model
     * @param  array              $articles  Renglones tal como los manda el formulario.
     * @return void
     */
    private function adjuntar_renglones($model, $articles) {

        /** Columnas del pivote que el formulario NO edita y hay que conservar. */
        $columnas_a_conservar = ['cost', 'variant_id', 'color_id', 'size_id', 'with_dolar', 'address_id', 'notes'];

        /** Foto de los pivotes actuales, por article_id. Se toma ANTES del detach. */
        $pivotes_previos = [];

        foreach ($model->articles as $article_previo) {
            $pivotes_previos[$article_previo->id] = $article_previo->pivot;
        }

        $model->articles()->detach();

        foreach ($articles as $article) {

            $article_id = isset($article['id']) ? $article['id'] : null;

            if (is_null($article_id)) {
                continue;
            }

            $previo = isset($pivotes_previos[$article_id]) ? $pivotes_previos[$article_id] : null;

            $valores = [
                'price'  => GeneralHelper::getPivotValue($article, 'price'),
                'amount' => GeneralHelper::getPivotValue($article, 'amount'),
            ];

            foreach ($columnas_a_conservar as $columna) {

                $de_la_request = GeneralHelper::getPivotValue($article, $columna);

                if (!is_null($de_la_request)) {
                    $valores[$columna] = $de_la_request;
                } else if (!is_null($previo)) {
                    $valores[$columna] = $previo->{$columna};
                }
            }

            $model->articles()->attach($article_id, $valores);
        }

        /**
         * 🔴 Se DESCARGA la relacion, porque el `foreach ($model->articles ...)` de arriba la dejo
         * cargada en memoria con los pivotes VIEJOS y el `detach()`/`attach()` escribe la base sin
         * enterarla. No lo borres por parecer al pedo: sin esta linea, todo lo que lea
         * `$order->articles` despues de llamar acá recibe las cantidades y los precios que el
         * formulario acaba de reemplazar.
         *
         * Lo que costaba, medido sobre `demo` el 31/8/2026 con un renglon de 7 unidades bajado a 3:
         *
         *   - `OrderHelper::get_total()` devolvia el total de 7 y `orders.total` quedaba viejo;
         *   - `chequear_limite_credito_del_pedido()`, que compara contra ese `total`, avisaba con el
         *     numero equivocado;
         *   - `CreateSaleOrderHelper::save_sale()` creaba la venta con `pivot->amount` de 7 y con
         *     `'total' => $order->total`, o sea descontaba 7 de stock y le cargaba 7 a la cuenta
         *     corriente del cliente.
         *
         * Se confirmaba un pedido por 3 y se facturaban 7, y el error no se descubria solo: el
         * pedido queda diciendo 3 y la venta diciendo 7, y no hay ninguna pantalla donde esos dos
         * numeros se vean juntos.
         *
         * Va acá adentro y no en `update()` a proposito: el metodo que desincroniza el modelo es el
         * que tiene que dejarlo coherente. Ponerlo en el llamador arregla este llamador; ponerlo acá
         * arregla tambien al que se agregue mañana.
         *
         * `unsetRelation()` y no `load()`: descarga y deja que el proximo acceso la recargue solo,
         * asi el camino que no crea venta —que no vuelve a mirar los renglones— no paga la consulta.
         */
        $model->unsetRelation('articles');
    }

    function pdf($id) {
        $model = Order::find($id);

        if (is_null($model)) {
            abort(404);
        }

        new OrderPdf($model);
    }
}
