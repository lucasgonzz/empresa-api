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

    /** El prefijo «HH:MM · » con el que la hora va escrita adelante del detalle (ver hora_del_detalle()). */
    const PATRON_HORA_DEL_DETALLE = '/^(\d{2}):(\d{2}) · /u';

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

        /*
         * 🔴 LA HORA DEL RECORDATORIO SALE DEL TEXTO, NO DE UN CAMPO APARTE (misión
         * recordatorios-whatsapp, 16/10/2026). La hora vive escrita adelante del detalle («10:00 ·
         * Llamar a…») porque es lo que el dueño ve y edita en la Agenda, que no tiene un campo de
         * hora (decisión de Lucas). Si el recordatorio leyera un campo propio, el dueño que en la
         * pantalla cambia «10:00» por «11:00» —o borra la hora— seguiría recibiendo el WhatsApp a la
         * hora vieja. Derivándola acá la siguen a la vez la pantalla (`POST/PUT api/pending`), la
         * tarea nueva del asistente y sus cambios, sin tocar ningún llamador.
         *
         * Lo que NO se devuelve: `avisar_por_whatsapp` ni `avisado_at`. Los pone solo el asistente
         * (cuando confirma el dueño) y el endpoint del admin: un update desde la pantalla no tiene
         * que prender ni apagar el aviso.
         */
        return [
            'hora_aviso'             => self::hora_del_detalle($detalle),
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
     * La hora del prefijo «HH:MM · » del detalle como "HH:MM:00" (lo que se guarda en `hora_aviso`),
     * o null si el detalle no empieza así o la hora no es del día (25:00, 10:75).
     *
     * El prefijo es exactamente el que escribe el asistente (PropuestaTareaIaHelper::proponer_tarea):
     * dos dígitos, dos puntos, dos dígitos, espacio, punto medio, espacio. Lo que no tiene esa forma
     * («a las 10 llamar…», «9:00 · …») es texto de la persona y no se interpreta.
     *
     * @param  string  $detalle
     * @return string|null
     */
    static function hora_del_detalle($detalle) {

        if (!is_string($detalle) || !preg_match(self::PATRON_HORA_DEL_DETALLE, $detalle, $partes)) {

            return null;
        }

        $horas = (int) $partes[1];
        $minutos = (int) $partes[2];

        if ($horas > 23 || $minutos > 59) {

            return null;
        }

        return sprintf('%02d:%02d:00', $horas, $minutos);
    }

    /**
     * Recalcula `avisado_at` cuando a una tarea EXISTENTE le cambia lo que decide su recordatorio: la
     * hora, la fecha o el pedido de aviso. Lo llama el evento `updating` de Pending, antes de guardar.
     *
     * - Si el momento NUEVO de hoy ya pasó y hoy le toca → `avisado_at = ahora`: la hora que ya pasó
     *   no dispara un WhatsApp al toque. Sin esto, editar a las 10:30 una tarea de hoy «a las 10:15»
     *   (o prenderle el aviso a esa hora) le mandaba al dueño un «te recuerdo» de algo que acababa de
     *   tocar.
     * - Si no (la hora nueva todavía no llegó, o hoy no le toca) → `avisado_at = null`.
     *
     * 🔴 POR QUÉ SE BORRA LA MARCA DE HOY. La marca es por día: sin esto, «mejor a las 11» después
     * del recordatorio de las 10 no volvía a salir ese día, y el asistente ya le había dicho al dueño
     * que a las 11 le llegaba (lo encontró el chequeo independiente del 16/10/2026).
     *
     * No toca nada si ninguno de los tres campos cambió: la marca que pone el sello del admin se
     * guarda sin cambiar ninguno de ellos.
     *
     * @param  \App\Models\Pending  $pending  La tarea, con los valores nuevos todavía sin guardar.
     * @return void
     */
    static function recalcular_marca_del_recordatorio($pending) {

        if (!$pending->exists || !$pending->isDirty(['hora_aviso', 'fecha_realizacion', 'avisar_por_whatsapp'])) {

            return;
        }

        if (empty($pending->hora_aviso)) {

            $pending->avisado_at = null;

            return;
        }

        $ahora = Carbon::now();
        $hoy = $ahora->copy()->startOfDay();

        $momento = $hoy->copy()->setTimeFromTimeString((string) $pending->hora_aviso);

        $pending->avisado_at = $momento->lte($ahora) && self::le_toca_el($pending, $hoy) ? $ahora : null;
    }

    /**
     * ¿Ese día es una ocurrencia de la tarea, con los valores que tiene en memoria? Si la unidad de
     * repetición cambió en el mismo guardado, la relación cargada es la vieja: se recarga la nueva.
     *
     * @param  \App\Models\Pending  $pending
     * @param  \Carbon\Carbon  $dia
     * @return bool
     */
    protected static function le_toca_el($pending, Carbon $dia) {

        if ($pending->es_recurrente) {

            $unidad = $pending->relationLoaded('unidad_frecuencia') ? $pending->unidad_frecuencia : null;

            if (is_null($unidad) || (int) $unidad->id !== (int) $pending->unidad_frecuencia_id) {

                $pending->setRelation('unidad_frecuencia', UnidadFrecuencia::find($pending->unidad_frecuencia_id));
            }
        }

        return AgendaHelper::es_ocurrencia($pending, $dia);
    }

    /**
     * El detalle sin el prefijo «HH:MM · » (si lo tiene), recortado.
     *
     * @param  string  $detalle
     * @return string
     */
    static function detalle_sin_hora($detalle) {

        return trim((string) preg_replace(self::PATRON_HORA_DEL_DETALLE, '', (string) $detalle));
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
