<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\CurrentAcountPaymentMethodDiscount;
use Illuminate\Http\Request;

class CurrentAcountPaymentMethodDiscountController extends Controller
{

    /**
     * Descuentos por metodo de pago del usuario.
     *
     * 🔴 `whereHas` NO ES UN FILTRO DE MAS: ES LA GUARDA QUE PROTEGE A TODO EL SPA.
     *
     * Un descuento cuyo metodo de pago fue borrado deja la relacion
     * `current_acount_payment_method` en null, y el SPA le lee `.name` en SEIS lugares distintos
     * sin preguntar: el catalogo de columnas del listado, el buscador de articulos de Vender, la
     * consultora de precios, el remito, y —el peor— `vender_set_total.js`, que es el CALCULO DEL
     * TOTAL de una venta. Cualquiera de esos tira un TypeError y voltea la pantalla entera.
     *
     * Paso en la produccion de masquito el 3/9/2026: el dueno borro un metodo de pago desde el ABM
     * y el comercio quedo sin poder editar stock ni ver las columnas de sucursal, por un dato que
     * no tenia ninguna relacion aparente con eso.
     *
     * Se filtra ACA y no en los seis consumidores por lo mismo de siempre: parchear las instancias
     * que el stack trace nombra deja afuera a la septima, que todavia no existe. Un descuento sin
     * metodo no se puede aplicar a nada, asi que no tiene por que viajar.
     *
     * ⚠️ Esto convive con la limpieza de `CurrentAcountPaymentMethodController::destroy()` y con la
     * migracion que borra los huerfanos que ya existen: aquella evita los nuevos, la migracion saca
     * los viejos, y esta es la red por si algun otro camino vuelve a generarlos.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index() {
        $models = CurrentAcountPaymentMethodDiscount::where('user_id', $this->userId())
                            ->whereHas('current_acount_payment_method')
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = CurrentAcountPaymentMethodDiscount::create([
            'current_acount_payment_method_id'                  => $request->current_acount_payment_method_id,
            'discount_percentage'                  => $request->discount_percentage,
            // Cantidad de cuotas a la que aplica esta regla. NULL = aplica sin importar cuotas (Prompt 260).
            'cuotas'                => $request->cuotas,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('CurrentAcountPaymentMethodDiscount', $model->id);
        return response()->json(['model' => $this->fullModel('CurrentAcountPaymentMethodDiscount', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('CurrentAcountPaymentMethodDiscount', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = CurrentAcountPaymentMethodDiscount::find($id);
        $model->current_acount_payment_method_id                = $request->current_acount_payment_method_id;
        $model->discount_percentage                = $request->discount_percentage;
        // Cantidad de cuotas a la que aplica esta regla. NULL = aplica sin importar cuotas (Prompt 260).
        $model->cuotas                = $request->cuotas;
        $model->save();
        $this->sendAddModelNotification('CurrentAcountPaymentMethodDiscount', $model->id);
        return response()->json(['model' => $this->fullModel('CurrentAcountPaymentMethodDiscount', $model->id)], 200);
    }

    public function destroy($id) {
        $model = CurrentAcountPaymentMethodDiscount::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('CurrentAcountPaymentMethodDiscount', $model->id);
        return response(null);
    }
}
