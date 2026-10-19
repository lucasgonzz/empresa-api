<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\DeliveryZone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Formas de envío PROPIAS de la tienda online (`delivery_zones`: nombre, descripción y precio).
 * En la pantalla se llaman "Formas de envío propias" desde la misión formas-envio-direcciones-maps
 * (19/10/2026); en el código siguen siendo zonas de entrega.
 *
 * Desde esa misión una forma propia puede tener el costo A CONVENIR (`costo_a_convenir`): por
 * ejemplo "Acordar con el vendedor". La tienda no le cobra nada online al comprador y el vendedor
 * coordina la entrega —y el costo, si lo hay— cuando gestiona el pedido. Ver `store`/`update`.
 */
class DeliveryZoneController extends Controller
{

    public function index() {
        $models = DeliveryZone::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    /**
     * Alta de una forma de envío propia.
     *
     * Con `costo_a_convenir` el precio se guarda en 0, diga lo que diga el request (ver
     * `precio_a_guardar`). Sin la clave en el request (una pantalla vieja) la forma nace con el
     * costo fijo, como siempre.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request) {
        $costo_a_convenir = $this->costo_a_convenir_pedido($request, false);

        $atributos = [
            // 'num'                   => $this->num('delivery_zones'),
            'name'                  => $request->name,
            'description'           => $request->description,
            'price'                 => $this->precio_a_guardar($request, $costo_a_convenir),
            'user_id'               => $this->userId(),
        ];

        if ($this->hay_columna_costo_a_convenir()) {
            $atributos['costo_a_convenir'] = $costo_a_convenir ? 1 : 0;
        }

        $model = DeliveryZone::create($atributos);
        $this->sendAddModelNotification('delivery_zone', $model->id);
        return response()->json(['model' => $this->fullModel('DeliveryZone', $model->id)], 201);
    }

    public function show($id) {
        return response()->json(['model' => $this->fullModel('DeliveryZone', $id)], 200);
    }

    /**
     * Edición de una forma de envío propia.
     *
     * Si el request no trae `costo_a_convenir` (una pantalla abierta desde antes del deploy) se
     * conserva el que tenía guardado, y con eso el precio en 0 si estaba a convenir: la pantalla
     * vieja no puede convertir sola una forma a convenir en una con costo.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id) {
        $model = DeliveryZone::find($id);

        $hay_columna = $this->hay_columna_costo_a_convenir();
        $costo_a_convenir = $this->costo_a_convenir_pedido(
            $request,
            $hay_columna ? (bool) $model->costo_a_convenir : false
        );

        $model->name                = $request->name;
        $model->description         = $request->description;
        $model->price               = $this->precio_a_guardar($request, $costo_a_convenir);

        if ($hay_columna) {
            $model->costo_a_convenir = $costo_a_convenir ? 1 : 0;
        }

        $model->save();
        $this->sendAddModelNotification('delivery_zone', $model->id);
        return response()->json(['model' => $this->fullModel('DeliveryZone', $model->id)], 200);
    }

    public function destroy($id) {
        $model = DeliveryZone::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('delivery_zone', $model->id);
        return response(null);
    }

    /**
     * Si la forma queda con el costo a convenir según el request, o `$actual` si el request no
     * lo dice (no trae la clave, o la trae en null: `$request->boolean()` leería null como false
     * y apagaría la bandera sin que nadie lo pidiera).
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  bool  $actual  Lo que hay guardado (false en un alta).
     * @return bool
     */
    protected function costo_a_convenir_pedido(Request $request, $actual) {

        if (!$request->has('costo_a_convenir') || is_null($request->input('costo_a_convenir'))) {
            return (bool) $actual;
        }

        return $request->boolean('costo_a_convenir');
    }

    /**
     * El precio que se guarda: 0 si la forma tiene el costo a convenir, el del request si no.
     *
     * 🔴 El 0 no es cosmético. La tienda nueva ignora el precio de una forma a convenir, pero una
     * `tienda-api` vieja (desplegada después que el ERP) no conoce la bandera y COBRARÍA el precio
     * guardado en el total, en el mail y en la preferencia de Mercado Pago. Con 0 guardado, la
     * tienda vieja tampoco cobra nada. Además el SPA oculta el precio cuando se tilda a convenir y
     * lo manda vacío, y la columna `price` es NOT NULL.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  bool  $costo_a_convenir
     * @return mixed
     */
    protected function precio_a_guardar(Request $request, $costo_a_convenir) {

        if ($costo_a_convenir) {
            return 0;
        }

        return $request->price;
    }

    /**
     * Si `delivery_zones.costo_a_convenir` existe. Un deploy sube el código ANTES de correr las
     * migraciones (`DeploymentService::execute_steps()` de admin-api): en esa ventana, escribir la
     * columna haría caer el alta y la edición de toda forma de envío.
     *
     * @return bool
     */
    protected function hay_columna_costo_a_convenir() {
        return Schema::hasColumn('delivery_zones', 'costo_a_convenir');
    }
}
