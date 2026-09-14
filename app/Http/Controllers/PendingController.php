<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\agenda\AgendaHelper;
use App\Models\ExpenseConcept;
use App\Models\Pending;
use App\Models\PendingCompleted;
use App\Models\UnidadFrecuencia;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PendingController extends Controller
{

    function recurrentes() {
        $models = Pending::where('user_id', $this->userId())
                            ->where('es_recurrente', 1)
                            ->orderBy('created_at', 'ASC')
                            ->withAll()
                            ->get();

        foreach ($models as $model) {
            $model->fecha_realizacion = null;
        }

        return response()->json(['models' => $models], 200);
    }

    public function index($from_date = null, $until_date = null) {
        $pendings = Pending::where('user_id', $this->userId())
                                    ->where('es_recurrente', 0)
                                    ->where('completado', 0)
                                    ->whereBetween('fecha_realizacion', [$from_date, $until_date])
                                    ->orderBy('created_at', 'DESC')
                                    ->withAll()
                                    ->get();

        $recurrentes = Pending::where('user_id', $this->userId())
                                    ->where('es_recurrente', 1)
                                    ->orderBy('created_at', 'DESC')
                                    ->withAll()
                                    ->get();

        foreach ($recurrentes as $recurrente) {

            $fecha_de_realizacion = Carbon::parse($recurrente->fecha_realizacion);

            // Log::info('fecha_de_realizacion: '.$fecha_de_realizacion);
            
            while ($fecha_de_realizacion->lt($from_date)) {
                $fecha_de_realizacion->addUnit($recurrente->unidad_frecuencia->slug, $recurrente->cantidad_frecuencia);
            }

            // $ultima_realizada = PendingCompleted::where('pending_id', $recurrente->id)
            //                                         ->orderBy('created_at', 'DESC')
            //                                         ->first();

            while ($fecha_de_realizacion->between($from_date, $until_date)) {

                // Log::info('comparando fecha_de_realizacion: '.$fecha_de_realizacion);
                $pending_completed = PendingCompleted::where('pending_id', $recurrente->id)
                                                        ->whereDate('fecha_realizacion', $fecha_de_realizacion)
                                                        ->first();

                if (is_null($pending_completed)) {

                    // Log::info('No habia tarea, agregando al array:');
                    $pendings->push([
                        'id'                    => $recurrente->id,
                        'detalle'               => $recurrente->detalle,
                        'fecha_realizacion'     => $fecha_de_realizacion->copy(),
                        'unidad_frecuencia_id'  => $recurrente->unidad_frecuencia_id,
                        'cantidad_frecuencia'   => $recurrente->cantidad_frecuencia,
                        'expense_concept_id'    => $recurrente->expense_concept_id,
                        'notas'                 => $recurrente->notas,
                        'es_recurrente'         => 1,
                        'completado'            => 0,
                    ]);

                    // Log::info($pendings);
                }

                // Log::info('Se agregaron '.$recurrente->cantidad_frecuencia.' - '.$recurrente->unidad_frecuencia->slug.':');
                $fecha_de_realizacion->addUnit($recurrente->unidad_frecuencia->slug, $recurrente->cantidad_frecuencia);
                // Log::info($fecha_de_realizacion);
            }
        }

        return response()->json(['models' => $pendings], 200);
    }

    /**
     * Máximo de días (inclusive) que acepta un rango de la agenda. La lista pide 60 y el
     * calendario un mes con sus bordes (42): 120 deja margen y corta un pedido que expandiría
     * miles de ocurrencias por error.
     */
    const MAX_DIAS_RANGO_AGENDA = 120;

    /**
     * Misión agenda-tareas-calendario (14/9/2026): `GET pending-agenda/{desde}/{hasta}`. Devuelve
     * las ocurrencias del rango (puntuales y recurrentes expandidas) más las vencidas de la
     * cuenta, que van aparte y sin rango. Ver AgendaHelper.
     *
     * `index()` y `recurrentes()` de arriba quedan como estaban: la SPA vieja los usa.
     *
     * @param  string  $desde  Y-m-d, inclusive.
     * @param  string  $hasta  Y-m-d, inclusive.
     * @return \Illuminate\Http\JsonResponse
     */
    public function agenda($desde, $hasta) {

        $desde_c = $this->parsear_fecha($desde);
        $hasta_c = $this->parsear_fecha($hasta);

        if (is_null($desde_c) || is_null($hasta_c)) {

            return response()->json(['message' => 'Las fechas tienen que venir como AAAA-MM-DD.'], 422);
        }

        if ($hasta_c->lt($desde_c)) {

            return response()->json(['message' => 'La fecha hasta no puede ser anterior a la fecha desde.'], 422);
        }

        if ($desde_c->diffInDays($hasta_c) > self::MAX_DIAS_RANGO_AGENDA) {

            return response()->json(['message' => 'El rango de la agenda no puede superar los '.self::MAX_DIAS_RANGO_AGENDA.' días.'], 422);
        }

        // Carbon::today() sale en la zona de la app (America/Argentina/Buenos_Aires), que es la
        // que define qué es "hoy" para el comercio.
        $hoy = Carbon::today();

        return response()->json([
            'hoy'           => $hoy->format('Y-m-d'),
            'vencidas'      => AgendaHelper::vencidas($this->userId(), $hoy),
            'ocurrencias'   => AgendaHelper::ocurrencias_entre($this->userId(), $desde_c, $hasta_c),
        ], 200);
    }

    public function store(Request $request) {

        $datos = $this->validar_tarea($request);

        if (is_string($datos)) {

            return response()->json(['message' => $datos], 422);
        }

        $datos['completado'] = 0;
        $datos['user_id'] = $this->userId();

        $model = Pending::create($datos);

        $this->sendAddModelNotification('Pending', $model->id);
        return response()->json(['model' => $this->fullModel('Pending', $model->id)], 201);
    }

    public function show($id) {

        $model = $this->tarea_de_la_cuenta($id);

        if (is_null($model)) {

            return response()->json(['message' => 'La tarea no existe.'], 404);
        }

        return response()->json(['model' => $this->fullModel('Pending', $model->id)], 200);
    }

    public function update(Request $request, $id) {

        $model = $this->tarea_de_la_cuenta($id);

        if (is_null($model)) {

            return response()->json(['message' => 'La tarea no existe.'], 404);
        }

        $datos = $this->validar_tarea($request);

        if (is_string($datos)) {

            return response()->json(['message' => $datos], 422);
        }

        /*
         * Si cambió la REGLA de una recurrente (primera fecha, unidad o cantidad) y la primera
         * fecha nueva quedó en el pasado, la base se mueve a la primera ocurrencia de la regla
         * nueva desde hoy. Sin esto, las ocurrencias ya hechas bajo la regla vieja reaparecían
         * como vencidas: una mensual del 5 con tres realizadas, editada al 10, devolvía 10/6, 10/7
         * y 10/8 en rojo, porque la expansión arranca siempre en la base con la regla actual y
         * las PendingCompleted viejas quedan colgadas de otras fechas. La edición de una regla
         * es "de acá en adelante"; lo ya hecho queda en Realizadas con su fecha. Una edición que
         * no toca la regla (detalle, notas, monto, gasto) no mueve nada. Lo encontró el chequeo
         * independiente del 14/9/2026.
         */
        $datos['fecha_realizacion'] = $this->reanclar_si_cambio_la_regla($model, $datos);

        /*
         * Hasta el 14/9/2026 update() no escribía `expense_amount`: el monto se perdía al editar
         * la tarea. Ahora se guarda el mismo conjunto de columnas que en store(), incluida
         * `fecha_fin_recurrencia`.
         */
        foreach ($datos as $columna => $valor) {

            $model->{$columna} = $valor;
        }

        /*
         * `completado` solo se puede BAJAR desde acá, y solo si viene explícito en false: es la
         * salida para las puntuales que la SPA vieja dejó en `completado = 1` sin PendingCompleted
         * (no tienen nada que deshacer en PendingCompletedController). Subirlo a 1 sigue siendo
         * trabajo exclusivo de marcar como hecha, que es lo que registra el gasto.
         */
        if ($request->has('completado') && !$request->boolean('completado')) {

            $model->completado = 0;
        }

        $model->save();

        $this->sendAddModelNotification('Pending', $model->id);
        return response()->json(['model' => $this->fullModel('Pending', $model->id)], 200);
    }

    public function destroy($id) {

        $model = $this->tarea_de_la_cuenta($id);

        if (is_null($model)) {

            return response()->json(['message' => 'La tarea no existe.'], 404);
        }

        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('Pending', $model->id);
        return response(null);
    }

    /**
     * Fecha base que corresponde guardar al editar. Si la tarea sigue (o pasa a ser) recurrente y
     * cambió alguno de los tres datos de la regla, y la primera fecha pedida es anterior a hoy,
     * devuelve la primera ocurrencia de la regla nueva que cae en hoy o después. En cualquier
     * otro caso devuelve la fecha tal como vino. Ver el comentario en update().
     *
     * @param  \App\Models\Pending  $model  La tarea como está guardada hoy.
     * @param  array  $datos  Lo validado por validar_tarea().
     * @return string  `Y-m-d 00:00:00`
     */
    protected function reanclar_si_cambio_la_regla($model, array $datos) {

        if (!$datos['es_recurrente']) {

            return $datos['fecha_realizacion'];
        }

        $misma_regla = (bool) $model->es_recurrente
            && Carbon::parse($model->fecha_realizacion)->format('Y-m-d') === substr($datos['fecha_realizacion'], 0, 10)
            && (int) $model->unidad_frecuencia_id === (int) $datos['unidad_frecuencia_id']
            && (int) $model->cantidad_frecuencia === (int) $datos['cantidad_frecuencia'];

        if ($misma_regla) {

            return $datos['fecha_realizacion'];
        }

        $hoy = Carbon::today();
        $base = Carbon::parse($datos['fecha_realizacion'])->startOfDay();

        if ($base->gte($hoy)) {

            return $datos['fecha_realizacion'];
        }

        // Una tarea "de mentira" con la regla nueva, solo para expandirla con AgendaHelper.
        $regla = new Pending([
            'es_recurrente'         => 1,
            'fecha_realizacion'     => $base->format('Y-m-d 00:00:00'),
            'unidad_frecuencia_id'  => $datos['unidad_frecuencia_id'],
            'cantidad_frecuencia'   => $datos['cantidad_frecuencia'],
        ]);
        $regla->setRelation('unidad_frecuencia', UnidadFrecuencia::find($datos['unidad_frecuencia_id']));

        for ($k = AgendaHelper::k_inicial($regla, $base, $hoy); ; $k++) {

            $ocurrencia = AgendaHelper::ocurrencia($regla, $k, $base);

            if ($ocurrencia->gte($hoy)) {

                return $ocurrencia->format('Y-m-d 00:00:00');
            }
        }
    }

    /**
     * Busca la tarea SCOPEADA por la cuenta. Hasta el 14/9/2026 update()/destroy() hacían
     * `find($id)` pelado y cualquier usuario autenticado podía editar o borrar pendientes de
     * otra cuenta con solo adivinar el id. Devuelve null (→ 404) si no es de la cuenta, sin
     * distinguir "no existe" de "no es tuya": para el que llama es lo mismo.
     *
     * @param  int  $id
     * @return \App\Models\Pending|null
     */
    protected function tarea_de_la_cuenta($id) {

        return Pending::where('id', $id)
                        ->where('user_id', $this->userId())
                        ->first();
    }

    /**
     * Validación mínima del body de store()/update() (contrato §2 del plan). Devuelve el array
     * de columnas listo para guardar, o un string con el mensaje del 422.
     *
     * Es a mano y no con el validador de Laravel a propósito: la SPA espera `{ message }` con un
     * texto en criollo, no el `{ errors: {...} }` del ValidationException, y los mensajes cortos
     * se leen mejor en el toast.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|string
     */
    protected function validar_tarea(Request $request) {

        $detalle = is_string($request->detalle) ? trim($request->detalle) : '';

        if ($detalle === '') {

            return 'Escribí qué hay que hacer.';
        }

        $fecha_realizacion = $this->parsear_fecha($request->fecha_realizacion);

        if (is_null($fecha_realizacion)) {

            return 'Indicá la fecha en que hay que hacerla.';
        }

        $es_recurrente = $request->boolean('es_recurrente');

        $unidad_frecuencia_id = null;
        $cantidad_frecuencia = null;
        $fecha_fin_recurrencia = null;

        if ($es_recurrente) {

            $unidad_frecuencia_id = (int) $request->unidad_frecuencia_id;

            if ($unidad_frecuencia_id <= 0 || !UnidadFrecuencia::where('id', $unidad_frecuencia_id)->exists()) {

                return 'Elegí cada cuánto se repite la tarea (día, semana, mes o año).';
            }

            if (!is_numeric($request->cantidad_frecuencia) || (int) $request->cantidad_frecuencia < 1 || (float) $request->cantidad_frecuencia != (int) $request->cantidad_frecuencia) {

                return 'La cantidad de la frecuencia tiene que ser un número entero mayor o igual a 1.';
            }

            $cantidad_frecuencia = (int) $request->cantidad_frecuencia;

            if (!is_null($request->fecha_fin_recurrencia) && $request->fecha_fin_recurrencia !== '') {

                $fin = $this->parsear_fecha($request->fecha_fin_recurrencia);

                if (is_null($fin)) {

                    return 'La fecha de fin de la recurrencia tiene que venir como AAAA-MM-DD.';
                }

                if ($fin->lt($fecha_realizacion)) {

                    return 'La fecha de fin de la recurrencia no puede ser anterior a la primera fecha.';
                }

                $fecha_fin_recurrencia = $fin->format('Y-m-d');
            }
        }

        // Un select sin elegir llega como 0, '' o null: los tres son "sin gasto".
        $expense_concept_id = (int) $request->expense_concept_id > 0 ? (int) $request->expense_concept_id : null;
        $expense_amount = null;

        // El concepto tiene que existir y ser de la cuenta: un id ajeno guardado acá terminaría
        // creando gastos con el concepto de otro comercio al marcar la tarea como hecha.
        if (!is_null($expense_concept_id)
            && !ExpenseConcept::where('id', $expense_concept_id)->where('user_id', $this->userId())->exists()) {

            return 'El concepto de gasto elegido no existe. Creá los conceptos en ABM → Gastos.';
        }

        if (!is_null($expense_concept_id)) {

            // Puede ser 0: "monto a definir al pagar". Vacío cuenta como 0 (la SPA vieja no
            // siempre lo manda).
            $monto = is_null($request->expense_amount) || $request->expense_amount === '' ? 0 : $request->expense_amount;

            if (!is_numeric($monto) || (float) $monto < 0) {

                return 'El monto estimado del gasto tiene que ser un número mayor o igual a 0.';
            }

            $expense_amount = (float) $monto;
        }

        return [
            'detalle'                => $detalle,
            'fecha_realizacion'      => $fecha_realizacion->format('Y-m-d 00:00:00'),
            'es_recurrente'          => $es_recurrente ? 1 : 0,
            'unidad_frecuencia_id'   => $unidad_frecuencia_id,
            'cantidad_frecuencia'    => $cantidad_frecuencia,
            'fecha_fin_recurrencia'  => $fecha_fin_recurrencia,
            'expense_concept_id'     => $expense_concept_id,
            'expense_amount'         => $expense_amount,
            'notas'                  => is_string($request->notas) && trim($request->notas) !== '' ? $request->notas : null,
        ];
    }

    /**
     * Parsea una fecha del request como día (sin hora ni zona). Acepta `Y-m-d` y también un
     * `Y-m-d H:i:s`/ISO, del que toma solo la fecha SIN convertir zona horaria: si la SPA manda
     * "2026-09-20" quiere decir el 20, y un ISO con "Z" convertido a la zona de la app podría
     * caer en el 19. Devuelve null si no es una fecha real (el 30/2 no lo es).
     *
     * @param  mixed  $valor
     * @return \Carbon\Carbon|null
     */
    protected function parsear_fecha($valor) {

        if (!is_string($valor) || !preg_match('/^(\d{4}-\d{2}-\d{2})/', $valor, $partes)) {

            return null;
        }

        try {

            $fecha = Carbon::createFromFormat('Y-m-d', $partes[1]);

        } catch (\Exception $e) {

            return null;
        }

        // createFromFormat no falla con el 30/2: lo desborda al 2/3. El ida y vuelta lo detecta.
        if ($fecha === false || $fecha->format('Y-m-d') !== $partes[1]) {

            return null;
        }

        return $fecha->startOfDay();
    }
}
