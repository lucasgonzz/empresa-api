<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\ChequeHelper;
use App\Models\RetencionImpuesto;
use App\Models\RetencionSufrida;
use Illuminate\Http\Request;

/**
 * ABM de los impuestos de retención propios del comercio (misión retenciones-abm-impuestos,
 * 8/10/2026), calcado de ChequeBancoController: un catálogo por dueño con nombre y nada más.
 *
 * Son los impuestos que se suman a los tres de siempre (Ganancias, IVA, Ingresos Brutos, que son
 * constantes y no pasan por acá) en el selector del certificado de retención del cobro. El
 * certificado los guarda como `imp_<id>` en `retenciones_sufridas.impuesto`.
 *
 * Su `index()` cumple las cuatro condiciones de la whitelist de RecursosInicialesController (sin
 * parámetros, sin leer el request, sin paginar, con la clave `models`), así que la SPA lo baja en
 * la descarga inicial junto con el resto de los catálogos.
 */
class RetencionImpuestoController extends Controller
{
    /**
     * El 404 de un impuesto de la ruta (show, update, destroy) que no es de esta cuenta, no existe o
     * no es un id: un mensaje de comerciante y el mismo cuerpo para los tres.
     */
    const MENSAJE_IMPUESTO_NO_ENCONTRADO = 'El impuesto no existe o no es de tu cuenta.';

    /** Largo máximo del nombre: el de la columna `name` (string de 255). */
    const LARGO_MAXIMO_DEL_NOMBRE = 255;

    public function index() {
        $models = RetencionImpuesto::where('user_id', $this->userId())
                            ->orderBy('name', 'ASC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $name = $this->nombre_valido($request, null);

        if ($name instanceof \Illuminate\Http\JsonResponse) {

            return $name;
        }

        $model = RetencionImpuesto::create([
            'name'                  => $name,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('RetencionImpuesto', $model->id);
        return response()->json(['model' => $this->fullModel('RetencionImpuesto', $model->id)], 201);
    }

    /**
     * Un impuesto del catálogo, resuelto por impuesto_del_dueno() igual que update() y destroy(): un
     * id de otra cuenta, uno que no existe o uno que no es un id son el mismo 404 (en una base
     * compartida los ids son correlativos entre comercios, y un `fullModel` pelado leía el de
     * cualquiera sumando 1).
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id) {
        $model = $this->impuesto_del_dueno($id);

        if (is_null($model)) {

            return $this->impuesto_no_encontrado();
        }

        return response()->json(['model' => $this->fullModel('RetencionImpuesto', $model->id)], 200);
    }

    public function update(Request $request, $id) {
        $model = $this->impuesto_del_dueno($id);

        if (is_null($model)) {

            return $this->impuesto_no_encontrado();
        }

        $name = $this->nombre_valido($request, $model->id);

        if ($name instanceof \Illuminate\Http\JsonResponse) {

            return $name;
        }

        $model->name                = $name;
        $model->save();
        $this->sendAddModelNotification('RetencionImpuesto', $model->id);
        return response()->json(['model' => $this->fullModel('RetencionImpuesto', $model->id)], 200);
    }

    /**
     * Borra el impuesto del catálogo, SOLO si ningún certificado lo usa.
     *
     * 🔴 Un impuesto con certificados cargados NO se borra (422): un certificado de retención es un
     * respaldo fiscal y no se reasigna a otro impuesto ni se deja huérfano con un `imp_<id>` que ya
     * no se sabe qué es. Si el comercio no quiere que se siga ofreciendo en el selector, la salida
     * es renombrarlo; para borrarlo, primero hay que borrar los cobros que lo usan.
     */
    public function destroy($id) {
        $model = $this->impuesto_del_dueno($id);

        if (is_null($model)) {

            return $this->impuesto_no_encontrado();
        }

        $certificados = RetencionSufrida::where('user_id', $this->userId())
                                        ->where('impuesto', RetencionImpuesto::clave($model->id))
                                        ->count();

        if ($certificados > 0) {

            $mensaje = $certificados == 1
                ? 'No se puede borrar: hay 1 certificado de retención cargado con este impuesto.'
                : 'No se puede borrar: hay '.$certificados.' certificados de retención cargados con este impuesto.';

            return response()->json(['message' => $mensaje], 422);
        }

        $model->delete();
        $this->sendDeleteModelNotification('RetencionImpuesto', $model->id);
        return response(null);
    }

    /**
     * El nombre del request, listo para guardar, o un 422 con mensaje de comerciante.
     *
     * Se rechaza: vacío, más largo que la columna, y repetido. "Repetido" se compara sin
     * mayúsculas, sin espacios y sin tildes, contra los demás impuestos de ESTE dueño y contra los
     * tres de siempre (más "Ingresos Brutos", que es como se escribe IIBB completo).
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int|null  $id_propio  El impuesto que se está editando (para no chocar consigo mismo).
     * @return string|\Illuminate\Http\JsonResponse  El nombre sin espacios en los bordes, o el 422.
     */
    protected function nombre_valido(Request $request, $id_propio) {
        $name = $request->input('name');

        $name = is_string($name) ? trim($name) : '';

        if ($name === '') {

            return response()->json(['message' => 'Escribí el nombre del impuesto.'], 422);
        }

        if (mb_strlen($name, 'UTF-8') > self::LARGO_MAXIMO_DEL_NOMBRE) {

            return response()->json(['message' => 'El nombre del impuesto es demasiado largo.'], 422);
        }

        $buscado = RetencionImpuesto::normalizar_nombre($name);

        if (in_array($buscado, RetencionImpuesto::NOMBRES_RESERVADOS)) {

            return response()->json(['message' => 'Ese impuesto ya existe: Ganancias, IVA e Ingresos Brutos vienen con el sistema.'], 422);
        }

        $query = RetencionImpuesto::where('user_id', $this->userId());

        if (!is_null($id_propio)) {

            $query->where('id', '!=', $id_propio);
        }

        foreach ($query->get(['id', 'name']) as $existente) {

            if (RetencionImpuesto::normalizar_nombre($existente->name) === $buscado) {

                return response()->json(['message' => 'Ya tenés un impuesto con ese nombre.'], 422);
            }
        }

        return $name;
    }

    /**
     * El impuesto, scopeado por dueño, o null: un id de otra cuenta es un 404, no un impuesto ajeno
     * leído, editado o borrado. Es el resolvedor de show(), update() y destroy().
     *
     * @param  mixed  $id  El id de la ruta.
     * @return \App\Models\RetencionImpuesto|null  null si no es del dueño, no existe o lo que llegó
     *                                              no es un id.
     */
    protected function impuesto_del_dueno($id) {
        // El id de la ruta, con LA lectura de ids (ChequeHelper::id_del_pedido()): MySQL castea el
        // texto de la ruta y un id escrito con basura al final sería el impuesto de ese número.
        $id = ChequeHelper::id_del_pedido($id);

        if ($id === 0) {

            return null;
        }

        return RetencionImpuesto::where('user_id', $this->userId())
                            ->where('id', $id)
                            ->first();
    }

    /**
     * El 404 de un impuesto que no es de esta cuenta, no existe o no es un id: el MISMO cuerpo para
     * los tres, con un mensaje de comerciante (y no el técnico de un firstOrFail()).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function impuesto_no_encontrado() {
        return response()->json(['message' => self::MENSAJE_IMPUESTO_NO_ENCONTRADO], 404);
    }
}
