<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\BalanzaHelper;
use App\Models\Balanza;
use Illuminate\Http\Request;

/**
 * ABM de las balanzas del comercio (misión balanzas-configurables, 3/10/2026), calcado de
 * ChequeBancoController: un catálogo por dueño.
 *
 * Cada balanza dice cómo leer los tickets que imprime (prefijo del código, importe o peso, cuántos
 * dígitos) y a qué artículo se le imputan. Se usan en VENDER cuando el dueño eligió
 * `tickets_de_balanza = 'balanzas'` (ver BalanzaHelper::leer_ticket_por_balanzas()).
 *
 * Su `index()` cumple las cuatro condiciones de la whitelist de RecursosInicialesController (sin
 * parámetros, sin leer el request, sin paginar, con la clave `models`), así que la SPA lo baja en
 * la descarga inicial: lo necesita para leer tickets sin conexión. Para quien no usa balanzas es
 * una lista vacía.
 *
 * La normalización y las validaciones (prefijo vacío o repetido, artículo que no es del dueño)
 * viven en BalanzaHelper::error_de_validacion(); acá solo se decide y se responde.
 */
class BalanzaController extends Controller
{
    /**
     * Las balanzas del dueño, ordenadas por prefijo, con su artículo.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index() {
        $models = Balanza::where('user_id', $this->userId())
                            ->orderBy('prefijo', 'ASC')
                            ->orderBy('id', 'ASC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    /**
     * Alta. 422 `{message}` si el prefijo queda vacío o ya lo usa otra balanza del dueño, o si el
     * artículo elegido no existe o no es de este comercio.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request) {
        $datos = BalanzaHelper::datos_desde_request($request);

        $error = BalanzaHelper::error_de_validacion($datos, $this->userId());

        if (!is_null($error)) {
            return response()->json(['message' => $error], 422);
        }

        $datos['user_id'] = $this->userId();

        $model = Balanza::create($datos);
        $this->sendAddModelNotification('Balanza', $model->id);
        return response()->json(['model' => $this->fullModel('Balanza', $model->id)], 201);
    }

    /**
     * Una balanza del dueño. Un id de otra cuenta es un 404 (a diferencia del molde, que no
     * scopeaba el show): en una base compartida no se le muestra a nadie la balanza de otro.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id) {
        $model = $this->balanza_del_dueno($id);
        return response()->json(['model' => $this->fullModel('Balanza', $model->id)], 200);
    }

    /**
     * Edición. Mismas reglas que el alta; el prefijo no choca con la propia balanza.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id) {
        $model = $this->balanza_del_dueno($id);

        $datos = BalanzaHelper::datos_desde_request($request);

        $error = BalanzaHelper::error_de_validacion($datos, $this->userId(), $model->id);

        if (!is_null($error)) {
            return response()->json(['message' => $error], 422);
        }

        $model->nombre      = $datos['nombre'];
        $model->prefijo     = $datos['prefijo'];
        $model->article_id  = $datos['article_id'];
        $model->tipo_dato   = $datos['tipo_dato'];
        $model->digitos     = $datos['digitos'];
        $model->save();

        $this->sendAddModelNotification('Balanza', $model->id);
        return response()->json(['model' => $this->fullModel('Balanza', $model->id)], 200);
    }

    /**
     * Baja. No toca ninguna venta: los renglones que entraron por esta balanza ya son renglones
     * comunes del artículo.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id) {
        $model = $this->balanza_del_dueno($id);
        $model->delete();
        $this->sendDeleteModelNotification('Balanza', $model->id);
        return response(null);
    }

    /**
     * La balanza, scopeada por dueño: un id de otra cuenta es un 404, no una balanza ajena editada.
     *
     * @param  int  $id
     * @return \App\Models\Balanza
     */
    protected function balanza_del_dueno($id) {
        return Balanza::where('user_id', $this->userId())
                        ->where('id', $id)
                        ->firstOrFail();
    }
}
