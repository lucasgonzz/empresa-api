<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\ChequeHelper;
use App\Http\Controllers\Helpers\caja\MovimientoCajaHelper;
use App\Models\AperturaCaja;
use App\Models\Caja;
use App\Models\MovimientoCaja;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Tesorería → Movimientos de una caja: el listado de una apertura, el alta de un movimiento manual,
 * y su corrección y baja.
 *
 * Misión movimientos-caja-manuales (9/10/2026). Hasta acá `index`, `update` y `destroy` resolvían
 * el id sin mirar el dueño, y `update`/`destroy` tocaban cualquier movimiento: el de una venta, el
 * de un gasto, el de un turno ya arqueado. Ahora:
 *
 * - Todo id (de la ruta o del cuerpo) se cruza con el dueño de la sesión por la CAJA
 *   (`movimiento_cajas` no tiene `user_id`). Un id ajeno se contesta igual que uno inexistente: 404
 *   si vino en la ruta, 422 si vino en el cuerpo.
 * - Solo un movimiento MANUAL de la apertura vigente se corrige o se elimina; el resto contesta 422
 *   con el motivo (MovimientoCaja::motivo_no_editable()), que la SPA muestra tal cual.
 * - El listado y las respuestas de `store`/`update` suman `editable` (bool) y `motivo_no_editable`
 *   (string|null). Son campos NUEVOS: nada de lo que ya viajaba cambia, y una SPA vieja los ignora.
 *
 * 🔴 Forma del 422, siempre: `{"message": <motivo>, "errors": {<campo>: [<motivo>]}}`, con `errors`
 * como OBJETO JSON (la SPA lo muestra como un aviso con el motivo, ver laravel_validation_toast.js).
 */
class MovimientoCajaController extends Controller
{
    /** El 404 de un movimiento de la ruta que no es del dueño, no existe o no es un id. */
    const MENSAJE_MOVIMIENTO_NO_ENCONTRADO = 'No se encontró el movimiento de caja.';

    /** El 404 de una apertura de la ruta (el listado) que no es del dueño, no existe o no es un id. */
    const MENSAJE_APERTURA_NO_ENCONTRADA = 'No se encontró la apertura de caja.';

    /** El 422 de un `caja_id` del cuerpo que no es del dueño o no existe (mismo texto que cheques). */
    const MENSAJE_CAJA_AJENA = 'La caja elegida no existe o no es de tu cuenta.';

    /** El 422 de un `apertura_caja_id` del cuerpo que no es de la caja elegida o no existe. */
    const MENSAJE_APERTURA_AJENA = 'La apertura elegida no existe o no es de esa caja.';

    /** El 422 de una corrección sin importe (los dos vacíos o en cero). */
    const MENSAJE_SIN_IMPORTE = 'Cargá el importe del movimiento en Ingreso o en Egreso.';

    /** El 422 de una corrección con los dos importes cargados. */
    const MENSAJE_DOS_IMPORTES = 'Un movimiento es un ingreso o un egreso: cargá el importe en uno solo de los dos.';

    /** El 422 de un importe negativo. */
    const MENSAJE_IMPORTE_NEGATIVO = 'El importe no puede ser negativo.';

    /** El 422 de un importe que no es un número. */
    const MENSAJE_IMPORTE_NO_NUMERICO = 'El importe tiene que ser un número.';

    /**
     * Los movimientos de una apertura, del más nuevo al más viejo, cada uno con `editable` y
     * `motivo_no_editable`.
     *
     * @param  mixed  $apertura_caja_id  El id de la ruta.
     * @return \Illuminate\Http\JsonResponse  200 `{models}`; 404 `{message}` si la apertura no es de
     *                                        una caja del dueño, no existe o no es un id.
     */
    public function index($apertura_caja_id) {

        $apertura = $this->apertura_del_dueno($apertura_caja_id);

        if (is_null($apertura)) {

            return response()->json(['message' => self::MENSAJE_APERTURA_NO_ENCONTRADA], 404);
        }

        $models = MovimientoCaja::where('apertura_caja_id', $apertura->id)
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();

        // Una vez para todas las filas: todas son de la misma apertura.
        $apertura_cerrada = MovimientoCaja::apertura_esta_cerrada($apertura, Caja::find($apertura->caja_id));

        $filas = [];

        foreach ($models as $model) {

            $filas[] = $this->con_editabilidad($model, $apertura_cerrada);
        }

        return response()->json(['models' => $filas], 200);
    }

    /**
     * El alta de un movimiento MANUAL (lo que carga una persona desde Tesorería → Movimientos).
     *
     * La caja del cuerpo tiene que ser del dueño y, si viene `apertura_caja_id`, tiene que ser una
     * apertura de esa caja: hasta acá ninguno de los dos se miraba, y MovimientoCajaHelper tampoco
     * lo hace, así que un movimiento propio podía caer en la caja de otro comercio y moverle el
     * saldo.
     *
     * Los importes se validan y se normalizan IGUAL que en update() (importe_normalizado() y
     * rechazo_de_importes()), y lo que se guarda es lo normalizado. Hasta acá entraban crudos: un
     * alta vacía dejaba `saldo` NULL en la fila y en la caja (y una fila así, primera del turno,
     * hacía reventar con 500 cualquier corrección o baja posterior, porque recalcular_saldos() no
     * le encuentra importe), y un alta `{ingreso: 0, egreso: 500}` contaba +0.
     *
     * No se agregó la regla de "turno cerrado" al alta: no se pidió (sin apertura vigente, el
     * helper sigue usando la última apertura de la caja, como siempre).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse  201 `{model}`; 422 si la caja o la apertura no son del
     *                                        dueño, o si los importes no cierran.
     */
    public function store(Request $request) {

        $caja_id = ChequeHelper::id_del_dueno(Caja::class, $request->caja_id, $this->userId());

        if (is_null($caja_id)) {

            return $this->rechazo(self::MENSAJE_CAJA_AJENA, 'caja_id');
        }

        // Sin apertura (null o '') el helper usa la última de la caja, como siempre. Cualquier otro
        // valor tiene que ser una apertura de ESA caja.
        $apertura_caja_id = null;

        if (!is_null($request->apertura_caja_id) && $request->apertura_caja_id !== '') {

            $apertura_caja_id = $this->apertura_de_la_caja($request->apertura_caja_id, $caja_id);

            if (is_null($apertura_caja_id)) {

                return $this->rechazo(self::MENSAJE_APERTURA_AJENA, 'apertura_caja_id');
            }
        }

        $ingreso = $this->importe_normalizado($request->ingreso);
        $egreso = $this->importe_normalizado($request->egreso);

        $rechazo = $this->rechazo_de_importes($ingreso, $egreso);

        if (!is_null($rechazo)) {

            return $rechazo;
        }

        $data = [
            'concepto_movimiento_caja_id'   => $request->concepto_movimiento_caja_id,
            'ingreso'                       => $ingreso,
            'egreso'                        => $egreso,
            'notas'                         => $request->notas,
            'apertura_caja_id'              => $apertura_caja_id,
            'caja_id'                       => $caja_id,
            // El único llamador de crear_movimiento() que lo pide: esto lo cargó una persona.
            'manual'                        => true,
        ];

        $helper = new MovimientoCajaHelper();
        $movimiento_caja = $helper->crear_movimiento($data);

        return response()->json(['model' => $this->con_editabilidad($movimiento_caja)], 201);
    }

    /**
     * Sin ruta: `Route::resource('movimiento-caja')` la excluye (`except('index', 'show')`).
     */
    public function show($id) {
        return response()->json(['model' => $this->fullModel('MovimientoCaja', $id)], 200);
    }

    /**
     * La corrección de un movimiento manual de la apertura vigente.
     *
     * Los importes se normalizan antes de guardar: null, '' y 0 son "sin importe" (NULL). Sin eso,
     * un `ingreso = 0` con `egreso = 500` se guardaba tal cual y set_saldos()/recalcular_saldos(),
     * que miran primero el ingreso si no es null, lo contaban como +0: el egreso desaparecía del
     * saldo.
     *
     * Después de guardar se recalculan los saldos de la apertura y sus totales, igual que destroy().
     * Hasta acá se llamaba recalcular_saldos($model), que no actualiza los totales de la apertura
     * (y deja `$apertura_caja` sin definir en ese camino).
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  mixed  $id  El id de la ruta.
     * @return \Illuminate\Http\JsonResponse  200 `{model}`; 404 si no es del dueño; 422 si no se puede
     *                                        corregir o los importes no cierran.
     */
    public function update(Request $request, $id) {

        $model = $this->movimiento_del_dueno($id);

        if (is_null($model)) {

            return $this->movimiento_no_encontrado();
        }

        $motivo = $model->motivo_no_editable();

        if (!is_null($motivo)) {

            return $this->rechazo($motivo, 'movimiento_caja');
        }

        $ingreso = $this->importe_normalizado($request->ingreso);
        $egreso = $this->importe_normalizado($request->egreso);

        $rechazo = $this->rechazo_de_importes($ingreso, $egreso);

        if (!is_null($rechazo)) {

            return $rechazo;
        }

        $model->concepto_movimiento_caja_id   = $request->concepto_movimiento_caja_id;
        $model->ingreso                       = $ingreso;
        $model->egreso                        = $egreso;
        $model->notas                         = $request->notas;

        /*
         * Si llegó hasta acá es un movimiento manual (motivo_no_editable() es null): se marca. Una
         * fila VIEJA (`manual` NULL, decidida por concepto) que se corrige por la pantalla queda
         * marcada, así cambiarle el concepto a uno de sistema no la bloquea para siempre. Solo si
         * la columna ya existe (ventana del deploy, ver MovimientoCaja::hay_columna_manual()).
         */
        if (MovimientoCaja::hay_columna_manual()) {
            $model->manual = true;
        }

        $model->save();

        // Igual que destroy(): saldos de toda la apertura vigente de la caja, y sus totales.
        MovimientoCajaHelper::recalcular_saldos(null, $model->caja_id);
        (new MovimientoCajaHelper())->set_apertura_caja_ingresos_egresos($model->apertura_caja_id);

        $this->sendAddModelNotification('MovimientoCaja', $model->id);
        return response()->json(['model' => $this->con_editabilidad($this->fullModel('MovimientoCaja', $model->id))], 200);
    }

    /**
     * La baja de un movimiento manual de la apertura vigente.
     *
     * 🔴 También la usa el borrado masivo (`PUT delete/movimiento_caja`, por
     * DeleteModelsHelper::process_delete()): `movimiento_caja` está en
     * DeleteModelsHelper::MODELOS_QUE_RESPETAN_RECHAZO, así que un 404/422 de acá no se cuenta como
     * eliminado.
     *
     * @param  mixed  $id  El id de la ruta.
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse  200 vacío; 404 si no es del
     *                                                                   dueño; 422 si no se puede eliminar.
     */
    public function destroy($id) {

        $model = $this->movimiento_del_dueno($id);

        if (is_null($model)) {

            return $this->movimiento_no_encontrado();
        }

        $motivo = $model->motivo_no_editable();

        if (!is_null($motivo)) {

            return $this->rechazo($motivo, 'movimiento_caja');
        }

        $caja_id = $model->caja_id;
        $apertura_caja_id = $model->apertura_caja_id;

        ImageController::deleteModelImages($model);
        $model->delete();

        MovimientoCajaHelper::recalcular_saldos(null, $caja_id);
        (new MovimientoCajaHelper())->set_apertura_caja_ingresos_egresos($apertura_caja_id);

        Log::info('Se elimino movimiento de caja '.$model->id);

        $this->sendDeleteModelNotification('MovimientoCaja', $model->id);
        return response(null);
    }

    /**
     * El movimiento de la ruta, si es de una caja del dueño de la sesión; null si es de otra cuenta,
     * si no existe o si lo que llegó no es un id (LA lectura de ids: ChequeHelper::id_del_pedido()).
     *
     * @param  mixed  $id
     * @return \App\Models\MovimientoCaja|null
     */
    protected function movimiento_del_dueno($id) {

        $user_id = $this->userId();

        // Sin dueño no hay nada de nadie.
        if (empty($user_id)) {

            return null;
        }

        $id = ChequeHelper::id_del_pedido($id);

        if ($id === 0) {

            return null;
        }

        return MovimientoCaja::where('id', $id)
                            ->whereHas('caja', function ($q) use ($user_id) {
                                $q->where('user_id', $user_id);
                            })
                            ->first();
    }

    /**
     * La apertura de la ruta, si es de una caja del dueño de la sesión; null si no.
     *
     * @param  mixed  $id
     * @return \App\Models\AperturaCaja|null
     */
    protected function apertura_del_dueno($id) {

        $user_id = $this->userId();

        if (empty($user_id)) {

            return null;
        }

        $id = ChequeHelper::id_del_pedido($id);

        if ($id === 0) {

            return null;
        }

        return AperturaCaja::where('id', $id)
                            ->whereHas('caja', function ($q) use ($user_id) {
                                $q->where('user_id', $user_id);
                            })
                            ->first();
    }

    /**
     * El id de la apertura del cuerpo si es una apertura de `$caja_id` (que ya se verificó que es
     * del dueño); null si no.
     *
     * @param  mixed  $valor
     * @param  int  $caja_id
     * @return int|null
     */
    protected function apertura_de_la_caja($valor, $caja_id) {

        $id = ChequeHelper::id_del_pedido($valor);

        if ($id === 0) {

            return null;
        }

        $existe = AperturaCaja::where('id', $id)
                            ->where('caja_id', $caja_id)
                            ->exists();

        return $existe ? $id : null;
    }

    /**
     * Un importe del pedido normalizado: null, '' y cero (0, '0', '0.00') son "sin importe" (null);
     * un número es un float; cualquier otra cosa (un texto, un booleano, un array) es false.
     *
     * @param  mixed  $valor
     * @return float|null|false
     */
    protected function importe_normalizado($valor) {

        if (is_null($valor)) {

            return null;
        }

        if (is_string($valor)) {

            $valor = trim($valor);

            if ($valor === '') {

                return null;
            }
        }

        if (is_bool($valor) || !is_numeric($valor)) {

            return false;
        }

        $numero = (float) $valor;

        // A dos decimales, que es lo que guarda la columna (decimal 20,2): un 0.001 se guardaría
        // como 0.00 y volvería a contar como "+0".
        if (round($numero, 2) == 0) {

            return null;
        }

        return $numero;
    }

    /**
     * El 422 de los importes ya normalizados, o null si cierran: uno solo de los dos, número y no
     * negativo.
     *
     * @param  float|null|false  $ingreso
     * @param  float|null|false  $egreso
     * @return \Illuminate\Http\JsonResponse|null
     */
    protected function rechazo_de_importes($ingreso, $egreso) {

        foreach (['ingreso' => $ingreso, 'egreso' => $egreso] as $campo => $valor) {

            if ($valor === false) {

                return $this->rechazo(self::MENSAJE_IMPORTE_NO_NUMERICO, $campo);
            }

            if (!is_null($valor) && $valor < 0) {

                return $this->rechazo(self::MENSAJE_IMPORTE_NEGATIVO, $campo);
            }
        }

        // Los dos casos que son del par y no de un campo van con la clave `importe`.
        if (!is_null($ingreso) && !is_null($egreso)) {

            return $this->rechazo(self::MENSAJE_DOS_IMPORTES, 'importe');
        }

        if (is_null($ingreso) && is_null($egreso)) {

            return $this->rechazo(self::MENSAJE_SIN_IMPORTE, 'importe');
        }

        return null;
    }

    /**
     * El movimiento como fila de la respuesta, con `editable` y `motivo_no_editable` sumados.
     * Se arma sobre el array y no sobre el modelo: un atributo que no es columna en un modelo que
     * alguien después guarde sería un error de SQL.
     *
     * @param  \App\Models\MovimientoCaja  $model
     * @param  bool|null  $apertura_cerrada  Ver MovimientoCaja::motivo_no_editable().
     * @return array
     */
    protected function con_editabilidad($model, $apertura_cerrada = null) {

        $motivo = $model->motivo_no_editable($apertura_cerrada);

        $fila = $model->toArray();
        $fila['editable'] = is_null($motivo);
        $fila['motivo_no_editable'] = $motivo;

        return $fila;
    }

    /**
     * El 422 con el motivo, en la forma que la SPA muestra: `errors` es un OBJETO (campo → lista).
     *
     * @param  string  $motivo
     * @param  string  $campo
     * @return \Illuminate\Http\JsonResponse
     */
    protected function rechazo($motivo, $campo) {

        return response()->json([
            'message' => $motivo,
            'errors'  => [$campo => [$motivo]],
        ], 422);
    }

    /**
     * El 404 de un movimiento que no es del dueño, no existe o no es un id: el mismo cuerpo para los
     * tres (un id ajeno se contesta igual que uno inexistente).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function movimiento_no_encontrado() {

        return response()->json(['message' => self::MENSAJE_MOVIMIENTO_NO_ENCONTRADO], 404);
    }
}
