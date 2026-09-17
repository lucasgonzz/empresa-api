<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\article\ArticleProviderDiscountHelper;
use App\Models\ArticleDiscount;
use App\Models\ProviderDiscount;
use Illuminate\Http\Request;

class ProviderDiscountController extends Controller
{

    public function store(Request $request) {
        $model = ProviderDiscount::create([
            'percentage'                  => $request->percentage,
            'nombre'                        => $this->nombre_normalizado($request),
            'provider_id'                   => $request->model_id,
        ]);
        $this->sendAddModelNotification('ProviderDiscount', $model->id);
        return response()->json(['model' => $this->fullModel('ProviderDiscount', $model->id)], 201);
    }

    public function show($id) {
        return response()->json(['model' => $this->fullModel('ProviderDiscount', $id)], 200);
    }

    public function update(Request $request, $id) {

        $model = $this->descuento_del_comercio($id);

        if (is_null($model)) {
            return response()->json(['message' => 'No se encontro el descuento'], 404);
        }

        // Nombre que tenia ANTES de guardar: es lo unico que permite saber si hubo renombre y no
        // disparar el UPDATE masivo de abajo en cada guardado del proveedor.
        $nombre_anterior = $model->nombre;

        $model->percentage                = $request->percentage;

        /*
         * 🔴 EL NOMBRE SOLO SE TOCA SI EL REQUEST LO TRAE. Un update parcial que no lo menciona
         * pasa derecho y lo deja como estaba.
         *
         * Sin esta guarda, `nombre_normalizado()` devuelve null cuando la clave no viene, y el
         * update lo borra de la ficha — y peor, dispara el UPDATE masivo de abajo poniendo
         * `nombre = NULL` en TODOS los articulos sincronizados de ese descuento.
         *
         * Y el escenario no es hipotetico, es el del despliegue normal: `empresa-api` y
         * `empresa-spa` nunca llegan juntas a produccion, asi que hay horas o dias con la API nueva
         * y la SPA vieja, que no conoce este campo. Todos sus updates de descuento de proveedor son
         * updates parciales. El comercio cargaria los nombres y los perderia —en la ficha y en el
         * catalogo entero— la primera vez que alguien editara un porcentaje desde la version vieja.
         *
         * ⚠️ El predicado es `has()` y NO `!is_null()`, y la diferencia importa: el middleware
         * global `ConvertEmptyStringsToNull` (Kernel.php:23) convierte la cadena vacia en null antes
         * de que el request llegue aca. O sea que un usuario que BORRA el nombre a proposito manda
         * la clave con null, exactamente igual que como se ve un campo que el servidor no puede
         * distinguir de otra forma. Con `!is_null()` ese borrado legitimo se ignoraria en silencio;
         * con `has()` se aplica, porque la clave esta presente. `filled()` tampoco sirve: da false
         * con null (verificado con el binario 7.4).
         *
         * Es el mismo criterio que `DescuentoRecargoExcluyenteHelper::hay_conflicto()` aplica a
         * porcentaje y monto, y el que `ArticleDiscountController` aplica a `tipo`.
         */
        if ($request->has('nombre')) {
            $model->nombre = $this->nombre_normalizado($request);
        }

        $model->save();

        $this->propagar_nombre_a_los_articulos($model, $nombre_anterior);

        $this->sendAddModelNotification('ProviderDiscount', $model->id);
        return response()->json(['model' => $this->fullModel('ProviderDiscount', $model->id)], 200);
    }

    public function destroy($id) {

        $model = $this->descuento_del_comercio($id);

        if (is_null($model)) {
            return response()->json(['message' => 'No se encontro el descuento'], 404);
        }

        $provider = $model->provider;
        $provider->should_update_prices = 1;
        $provider->save();

        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('ProviderDiscount', $model->id);
        return response(null);
    }

    /**
     * Cuando se RENOMBRA un descuento del proveedor, los articulos ya sincronizados pasan a mostrar
     * el nombre nuevo (decision de Lucas, 17/9/2026: "el nombre nuevo, siempre").
     *
     * 🔴 Un solo UPDATE masivo filtrando por `provider_discount_id`, NO un barrido articulo por
     * articulo. Un proveedor de un comercio grande tiene miles de articulos sincronizados: un
     * `foreach` con un `save()` por fila serian miles de queries adentro de un request que el
     * usuario esta esperando, para cambiar un texto. La columna tiene indice justamente por esto
     * (migracion 2026_09_17_110000).
     *
     * ⚠️ El UPDATE toca SOLO `nombre`. No se recalcula ningun precio ni se marca
     * `should_update_prices`: renombrar no mueve un centavo, y disparar el recalculo de precios de
     * todo el proveedor por un cambio de texto seria un costo enorme sin ningun efecto.
     *
     * ⚠️ Los `article_discounts` anteriores a la migracion tienen `provider_discount_id` en NULL y
     * NO entran en este UPDATE, aunque esten tagueados a este proveedor. Es correcto: no hay forma
     * de saber cual de las bonificaciones los origino, y adivinarlo les pondria el nombre de otra.
     * Se van poblando solos a medida que se los sincroniza.
     *
     * @param  \App\Models\ProviderDiscount $model           Descuento ya guardado.
     * @param  string|null                  $nombre_anterior Nombre que tenia antes del save().
     * @return void
     */
    private function propagar_nombre_a_los_articulos($model, $nombre_anterior) {

        // Comparacion estricta entre string|null: sin renombre no hay nada que propagar.
        if ($nombre_anterior === $model->nombre) {
            return;
        }

        ArticleDiscount::where('provider_discount_id', $model->id)
                            ->update(['nombre' => $model->nombre]);
    }

    /**
     * Descuento de proveedor scopeado al comercio de la sesion. Devuelve null si el id no existe o
     * es de otro comercio, y el llamador contesta 404.
     *
     * 🔴 POR QUE ESTE SCOPE NO SE SACA "PARA SIMPLIFICAR". Hasta esta mision, `update()` resolvia
     * con un `find($id)` pelado y el daño de un id ajeno se limitaba a pisarle un porcentaje a otro
     * comercio: una fila. Desde esta mision, `update()` dispara ademas
     * `propagar_nombre_a_los_articulos()`, que es UN SOLO UPDATE MASIVO sobre `article_discounts`
     * filtrando unicamente por `provider_discount_id` — sin `user_id` de por medio, porque la
     * columna no existe en esa tabla. O sea que un `PUT provider-discount/{id}` con un id de otro
     * comercio le reescribe el nombre a TODOS los articulos sincronizados de ese descuento, miles
     * de filas, en una query y sin dejar rastro. El scope de aca es lo unico que lo frena.
     *
     * Y el `find()` pelado tampoco resistia un id inexistente: `$model->nombre` sobre null es un
     * fatal error, no un 404.
     *
     * ⚠️ La pertenencia va POR EL PROVEEDOR, no directa: `provider_discounts` no tiene `user_id`
     * (ver la migracion 2025_09_12_143743). Por eso el `whereHas` sobre la relacion, con el mismo
     * criterio que `ProviderController::proveedor_del_comercio()` — y, como aquel, hereda el scope
     * de SoftDeletes de `Provider`: un descuento cuyo proveedor esta borrado no se edita ni se
     * borra desde aca, que es lo mismo que ya pasa con el proveedor en si.
     *
     * @param  int $id
     * @return \App\Models\ProviderDiscount|null
     */
    private function descuento_del_comercio($id) {

        $user_id = $this->userId();

        return ProviderDiscount::where('id', $id)
                                ->whereHas('provider', function ($query) use ($user_id) {
                                    $query->where('user_id', $user_id);
                                })
                                ->first();
    }

    /**
     * Normaliza el nombre que llega del request con el MISMO criterio con el que lo copia
     * `ArticleProviderDiscountHelper::create_tagged_discounts()` (vacio -> null, recortado a 191).
     *
     * Se reusa ese metodo a proposito en vez de reescribir la regla aca: es el mismo invariante, y
     * el mismo invariante decidido con dos criterios en dos archivos es una clase de error conocida
     * (12/8/2026). Si mañana se cambia el largo de la columna, se cambia en un solo lugar.
     *
     * @param  \Illuminate\Http\Request $request
     * @return string|null
     */
    private function nombre_normalizado(Request $request) {

        return ArticleProviderDiscountHelper::leer_nombre_del_descuento(
            (object) ['nombre' => $request->input('nombre')]
        );
    }
}
