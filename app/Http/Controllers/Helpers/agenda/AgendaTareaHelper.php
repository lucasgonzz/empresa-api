<?php

namespace App\Http\Controllers\Helpers\agenda;

use App\Models\ExpenseConcept;
use App\Models\Pending;
use App\Models\UnidadFrecuencia;
use Carbon\Carbon;

/**
 * Validación y reglas de guardado de una tarea de la Agenda, extraídas de PendingController en la
 * misión asistente-ia-acciones (15/9/2026).
 *
 * Existen aparte del controller porque ahora hay DOS llamadores que dan de alta y editan tareas: la
 * pantalla (`POST/PUT api/pending`) y el asistente de IA, que propone la tarea en un job sin sesión
 * y la guarda cuando la persona confirma la tarjeta. Si el asistente armara un `Request` falso para
 * pegarle al controller, caería en la clase "el store() que lee un subconjunto del request y el
 * llamador que le manda el resto" (APRENDER_NO_PARCHEAR.md, 5/9/2026): acá recibe un array con las
 * claves del body de la pantalla y cada llamador arma exactamente eso.
 *
 * 🔴 Los mensajes de error son texto de contrato: la SPA los muestra tal cual en el toast y el
 * asistente se los cuenta a la persona. No se cambian desde un solo llamador.
 */
class AgendaTareaHelper {

    /**
     * Validación mínima del body de alta/edición de una tarea (contrato §2 de la misión de la
     * agenda). Devuelve el array de columnas listo para guardar, o un string con el mensaje del 422.
     *
     * Es a mano y no con el validador de Laravel a propósito: la SPA espera `{ message }` con un
     * texto en criollo, no el `{ errors: {...} }` del ValidationException, y los mensajes cortos se
     * leen mejor en el toast.
     *
     * Lee las claves con la MISMA semántica que tenía `$request->clave` en el controller: una clave
     * que no vino vale null, y `es_recurrente` pasa por FILTER_VALIDATE_BOOLEAN igual que
     * `$request->boolean()`. Por eso el controller le pasa `$request->all()` y el comportamiento de
     * la pantalla no cambia.
     *
     * @param  array  $datos     Claves del body: detalle, fecha_realizacion, es_recurrente,
     *                           unidad_frecuencia_id, cantidad_frecuencia, fecha_fin_recurrencia,
     *                           expense_concept_id, expense_amount, notas.
     * @param  int    $owner_id  Dueño de la cuenta: el concepto de gasto tiene que ser suyo.
     * @return array|string
     */
    static function validar(array $datos, $owner_id) {

        $detalle = is_string(self::valor($datos, 'detalle')) ? trim(self::valor($datos, 'detalle')) : '';

        if ($detalle === '') {

            return 'Escribí qué hay que hacer.';
        }

        $fecha_realizacion = self::parsear_fecha(self::valor($datos, 'fecha_realizacion'));

        if (is_null($fecha_realizacion)) {

            return 'Indicá la fecha en que hay que hacerla.';
        }

        $es_recurrente = self::booleano($datos, 'es_recurrente');

        $unidad_frecuencia_id = null;
        $cantidad_frecuencia = null;
        $fecha_fin_recurrencia = null;

        if ($es_recurrente) {

            $unidad_frecuencia_id = (int) self::valor($datos, 'unidad_frecuencia_id');

            if ($unidad_frecuencia_id <= 0 || !UnidadFrecuencia::where('id', $unidad_frecuencia_id)->exists()) {

                return 'Elegí cada cuánto se repite la tarea (día, semana, mes o año).';
            }

            $cantidad = self::valor($datos, 'cantidad_frecuencia');

            if (!is_numeric($cantidad) || (int) $cantidad < 1 || (float) $cantidad != (int) $cantidad) {

                return 'La cantidad de la frecuencia tiene que ser un número entero mayor o igual a 1.';
            }

            $cantidad_frecuencia = (int) $cantidad;

            $fin_pedido = self::valor($datos, 'fecha_fin_recurrencia');

            if (!is_null($fin_pedido) && $fin_pedido !== '') {

                $fin = self::parsear_fecha($fin_pedido);

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
        $expense_concept_id = (int) self::valor($datos, 'expense_concept_id') > 0 ? (int) self::valor($datos, 'expense_concept_id') : null;
        $expense_amount = null;

        // El concepto tiene que existir y ser de la cuenta: un id ajeno guardado acá terminaría
        // creando gastos con el concepto de otro comercio al marcar la tarea como hecha.
        if (!is_null($expense_concept_id)
            && !ExpenseConcept::where('id', $expense_concept_id)->where('user_id', $owner_id)->exists()) {

            return 'La subcategoría de gasto elegida no existe. Creá las subcategorías en ABM → Gastos.';
        }

        if (!is_null($expense_concept_id)) {

            // Puede ser 0: "monto a definir al pagar". Vacío cuenta como 0 (la SPA vieja no
            // siempre lo manda).
            $monto_pedido = self::valor($datos, 'expense_amount');
            $monto = is_null($monto_pedido) || $monto_pedido === '' ? 0 : $monto_pedido;

            if (!is_numeric($monto) || (float) $monto < 0) {

                return 'El monto estimado del gasto tiene que ser un número mayor o igual a 0.';
            }

            $expense_amount = (float) $monto;
        }

        $notas = self::valor($datos, 'notas');

        return [
            'detalle'                => $detalle,
            'fecha_realizacion'      => $fecha_realizacion->format('Y-m-d 00:00:00'),
            'es_recurrente'          => $es_recurrente ? 1 : 0,
            'unidad_frecuencia_id'   => $unidad_frecuencia_id,
            'cantidad_frecuencia'    => $cantidad_frecuencia,
            'fecha_fin_recurrencia'  => $fecha_fin_recurrencia,
            'expense_concept_id'     => $expense_concept_id,
            'expense_amount'         => $expense_amount,
            'notas'                  => is_string($notas) && trim($notas) !== '' ? $notas : null,
        ];
    }

    /**
     * Fecha base que corresponde guardar al editar. Si la tarea sigue (o pasa a ser) recurrente y
     * cambió alguno de los tres datos de la regla, y la primera fecha pedida es anterior a hoy,
     * devuelve la primera ocurrencia de la regla nueva que cae en hoy o después. En cualquier
     * otro caso devuelve la fecha tal como vino.
     *
     * Por qué: sin esto, las ocurrencias ya hechas bajo la regla vieja reaparecían como vencidas.
     * Una mensual del 5 con tres realizadas, editada al 10, devolvía 10/6, 10/7 y 10/8 en rojo,
     * porque la expansión arranca siempre en la base con la regla actual y las PendingCompleted
     * viejas quedan colgadas de otras fechas. La edición de una regla es "de acá en adelante"; lo
     * ya hecho queda en Realizadas con su fecha. Una edición que no toca la regla (detalle, notas,
     * monto, gasto) no mueve nada. Lo encontró el chequeo independiente del 14/9/2026.
     *
     * @param  \App\Models\Pending  $model  La tarea como está guardada hoy.
     * @param  array  $datos  Lo validado por validar().
     * @return string  `Y-m-d 00:00:00`
     */
    static function reanclar_si_cambio_la_regla($model, array $datos) {

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
     * Parsea una fecha como día (sin hora ni zona). Acepta `Y-m-d` y también un `Y-m-d H:i:s`/ISO,
     * del que toma solo la fecha SIN convertir zona horaria: si la SPA manda "2026-09-20" quiere
     * decir el 20, y un ISO con "Z" convertido a la zona de la app podría caer en el 19. Devuelve
     * null si no es una fecha real (el 30/2 no lo es).
     *
     * @param  mixed  $valor
     * @return \Carbon\Carbon|null
     */
    static function parsear_fecha($valor) {

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

    /**
     * Valor de una clave del body, null si no vino. Es la semántica de `$request->clave`.
     *
     * @param  array  $datos
     * @param  string  $clave
     * @return mixed
     */
    protected static function valor(array $datos, $clave) {

        return array_key_exists($clave, $datos) ? $datos[$clave] : null;
    }

    /**
     * Booleano de una clave del body con la semántica de `$request->boolean()`: una clave que no
     * vino es false, y el valor pasa por FILTER_VALIDATE_BOOLEAN ("1", "true", "on", true → true).
     *
     * @param  array  $datos
     * @param  string  $clave
     * @return bool
     */
    protected static function booleano(array $datos, $clave) {

        return filter_var(array_key_exists($clave, $datos) ? $datos[$clave] : false, FILTER_VALIDATE_BOOLEAN);
    }
}
