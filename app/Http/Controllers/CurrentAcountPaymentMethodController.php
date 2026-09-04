<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\CurrentAcountPaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CurrentAcountPaymentMethodController extends Controller
{

    public function index() {
        $models = CurrentAcountPaymentMethod::orderBy('created_at', 'DESC')
                                        ->withAll()
                                        ->get();
        return response()->json(['models' => $models], 200);
    }

    function store(Request $request) {
        $model = CurrentAcountPaymentMethod::create([
            'name'                          => $request->name,
            'c_a_payment_method_type_id'    => $request->c_a_payment_method_type_id,
        ]);
        return response()->json(['model' => $model], 201);
    }

    public function update(Request $request, $id) {
        $model = CurrentAcountPaymentMethod::find($id);
        $model->name                            = $request->name;
        $model->c_a_payment_method_type_id      = $request->c_a_payment_method_type_id;
        $model->save();
        return response()->json(['model' => $model], 200);
    }

    /**
     * Borra el metodo de pago y, con el, sus descuentos configurados.
     *
     * 🔴 LOS DESCUENTOS SE VAN CON EL METODO, Y NO ES PROLIJIDAD: SIN ESTO SE ROMPE EL LISTADO.
     *
     * Un current_acount_payment_method_discount que apunta a un metodo borrado deja la relacion
     * `current_acount_payment_method` en null. Del lado del SPA,
     * add_article_dynamic_columns() le lee `.name` para armar la columna del descuento, y esa
     * excepcion se llevaba puestas las columnas de sucursal y el boton de editar stock, que se
     * arman despues en la misma funcion. Paso en la produccion de masquito el 3/9/2026: el
     * comercio quedo sin poder editar stock por haber borrado un medio de pago, y los tres
     * sintomas no se parecian en nada a la causa. La tabla no tiene FK con cascade
     * (2024_07_10_103738) y una migracion ya aplicada en 40 bases no se edita, asi que la
     * limpieza va aca.
     *
     * ⚠️ SOLO los descuentos. Este metodo tambien lo referencian `sales`, `expenses`,
     * `current_acounts`, los pivots y `company_performance_*`: eso es HISTORIAL —ventas y gastos
     * que ya ocurrieron— y borrarlo seria muchisimo peor que el problema que se arregla. Que esas
     * filas queden apuntando a un metodo borrado es un asunto distinto y mas viejo, con el que el
     * sistema convive sin romperse.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id) {
        $model = CurrentAcountPaymentMethod::find($id);

        if (is_null($model)) {
            return response(null, 200);
        }

        /* Los dos borrados van juntos: si falla el segundo, el metodo no se tiene que ir solo. */
        DB::transaction(function () use ($model) {
            $model->discounts()->delete();
            $model->delete();
        });

        return response(null, 200);
    }
}
