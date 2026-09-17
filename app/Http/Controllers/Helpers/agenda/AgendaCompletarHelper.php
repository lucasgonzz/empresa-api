<?php

namespace App\Http\Controllers\Helpers\agenda;

use App\Http\Controllers\Helpers\expense\ExpenseHelper;
use App\Models\Pending;
use App\Models\PendingCompleted;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Marca una ocurrencia de la Agenda como hecha y, si la tarea tiene un gasto asociado, lo da de
 * alta (misión agenda-tareas-calendario, 14/9/2026). Reemplaza a PendingCompletedHelper (2024),
 * que hacía lo mismo en tres pasos sin transacción y armando un Request falso contra
 * ExpenseController::store(): si el gasto fallaba, la tarea ya había quedado marcada como hecha.
 *
 * Todo lo que escribe pasa por UNA transacción: o queda la tarea hecha con su gasto, o no queda
 * nada.
 */
class AgendaCompletarHelper {

    /**
     * Mensaje del 422 cuando la tarea tiene gasto y el pedido no trae ni el gasto ni `sin_gasto`.
     * Es texto de contrato (§2 del plan): la SPA lo muestra tal cual.
     */
    const MENSAJE_GASTO_REQUERIDO = 'Esta tarea tiene un gasto asociado: indicá cómo se pagó, o marcala como hecha sin registrar el gasto.';

    /**
     * Mensaje del 409 cuando la ocurrencia ya estaba marcada. Lo lee el usuario tal cual, así
     * que dice "tarea" y "fecha", no "ocurrencia" (jerga de este helper).
     */
    const MENSAJE_YA_COMPLETADA = 'Esta tarea ya estaba marcada como hecha para esa fecha.';

    /**
     * Mensaje del 422 cuando la fecha no es una ocurrencia de la tarea.
     */
    const MENSAJE_FECHA_INVALIDA = 'Esa fecha no corresponde a esta tarea.';

    /**
     * @param  \App\Models\Pending  $pending  La tarea, ya verificada como de la cuenta por el controller.
     * @param  \Carbon\Carbon  $fecha  La ocurrencia que se marca (solo importa el día).
     * @param  array  $datos  Claves: `notas` (string|null), `sin_gasto` (bool), `expense` (array con
     *                        `amount`, `moneda_id`, `importe_iva`, `observations`, `payment_methods`).
     * @param  int  $user_id  Dueño de la cuenta.
     * @param  callable  $num_expense_resolver  Devuelve el correlativo del gasto; se ejecuta
     *                                          adentro de la transacción (ver ExpenseHelper::crear()).
     * @return \App\Models\PendingCompleted  Con `expense` cargado (null si no se registró gasto).
     *
     * @throws AgendaYaCompletadaException  Si la ocurrencia ya estaba marcada (→ 409).
     * @throws AgendaGastoRequeridoException  Si la tarea tiene gasto y faltan los datos para registrarlo (→ 422).
     * @throws AgendaFechaInvalidaException  Si la fecha no es una ocurrencia de la tarea (→ 422).
     */
    static function completar($pending, Carbon $fecha, array $datos, $user_id, $num_expense_resolver) {

        $fecha = $fecha->copy()->startOfDay();

        return DB::transaction(function () use ($pending, $fecha, $datos, $user_id, $num_expense_resolver) {

            /*
             * Candado contra el segundo clic. Dos pedidos simultáneos de marcar la misma
             * ocurrencia (doble clic, dos pestañas) llegaban los dos a "no existe PendingCompleted
             * para esta fecha" y creaban dos filas y dos gastos. Con el lock sobre la fila de la
             * tarea, el segundo espera a que el primero haga commit, y recién ahí consulta las
             * completadas: ya ve la del primero y se va por el 409. Es la clase "la operación que
             * mueve stock o plata sin candado contra el segundo clic" de APRENDER_NO_PARCHEAR.md.
             */
            $pending = Pending::where('id', $pending->id)
                                ->lockForUpdate()
                                ->first();

            if (is_null($pending)) {

                throw new \RuntimeException('La tarea ya no existe.');
            }

            // El lock devolvió la fila sin relaciones: es_ocurrencia() necesita la unidad.
            $pending->load('unidad_frecuencia');

            if (!AgendaHelper::es_ocurrencia($pending, $fecha)) {

                throw new AgendaFechaInvalidaException(self::MENSAJE_FECHA_INVALIDA);
            }

            $ya_completada = PendingCompleted::where('pending_id', $pending->id)
                                                ->whereDate('fecha_realizacion', $fecha->format('Y-m-d'))
                                                ->exists();

            // Para una puntual, el flag `completado` también cuenta: la SPA vieja lo dejaba en 1
            // aunque hubiera borrado el PendingCompleted al deshacer.
            if ($ya_completada || (!$pending->es_recurrente && $pending->completado)) {

                throw new AgendaYaCompletadaException(self::MENSAJE_YA_COMPLETADA);
            }

            $registra_gasto = (int) $pending->expense_concept_id > 0 && empty($datos['sin_gasto']);

            $expense = null;

            if ($registra_gasto) {

                // Se valida ANTES de crear el PendingCompleted: si falta el gasto, el 422 no tiene
                // que dejar nada escrito (la transacción lo revertiría igual, pero así no se gasta
                // un id de autoincremento en cada intento fallido).
                $expense = self::datos_del_gasto($pending, $datos);
            }

            $notas = isset($datos['notas']) && is_string($datos['notas']) && trim($datos['notas']) !== ''
                        ? $datos['notas']
                        : $pending->notas;

            $completed = PendingCompleted::create([
                'pending_id'            => $pending->id,
                'detalle'               => $pending->detalle,
                'notas'                 => $notas,
                'fecha_realizacion'     => $fecha->format('Y-m-d 00:00:00'),
                'fecha_realizada'       => Carbon::now(),
                'expense_concept_id'    => $pending->expense_concept_id,
                'user_id'               => $user_id,
            ]);

            if ($registra_gasto) {

                $gasto = ExpenseHelper::crear($expense, $user_id, $num_expense_resolver);

                $completed->expense_id = $gasto->id;
                $completed->expense_amount = $gasto->amount;
                $completed->save();
            }

            if (!$pending->es_recurrente) {

                $pending->completado = 1;
                $pending->save();
            }

            $completed->load('expense');

            return $completed;
        });
    }

    /**
     * Arma el array para ExpenseHelper::crear() con lo que vino en `$datos['expense']`. Exige
     * monto > 0 y al menos un método de pago con monto e id: sin eso no hay forma de saber de qué
     * caja salió la plata, y un gasto sin desglose es justo lo que creaba el helper viejo.
     *
     * @param  \App\Models\Pending  $pending
     * @param  array  $datos
     * @return array
     *
     * @throws AgendaGastoRequeridoException
     */
    protected static function datos_del_gasto($pending, array $datos) {

        $expense = isset($datos['expense']) && is_array($datos['expense']) ? $datos['expense'] : [];

        $amount = isset($expense['amount']) ? $expense['amount'] : null;

        if (!is_numeric($amount) || (float) $amount <= 0) {

            throw new AgendaGastoRequeridoException(self::MENSAJE_GASTO_REQUERIDO);
        }

        $payment_methods = isset($expense['payment_methods']) && is_array($expense['payment_methods'])
                                ? $expense['payment_methods']
                                : [];

        if (!self::hay_metodo_de_pago_valido($payment_methods)) {

            throw new AgendaGastoRequeridoException(self::MENSAJE_GASTO_REQUERIDO);
        }

        $observations = 'Agenda: '.$pending->detalle;

        if (isset($expense['observations']) && is_string($expense['observations']) && trim($expense['observations']) !== '') {

            $observations .= ' — '.trim($expense['observations']);
        }

        return [
            'expense_concept_id'    => $pending->expense_concept_id,
            'amount'                => (float) $amount,
            'moneda_id'             => ExpenseHelper::normalizar_moneda_id(isset($expense['moneda_id']) ? $expense['moneda_id'] : null),
            'importe_iva'           => isset($expense['importe_iva']) && is_numeric($expense['importe_iva']) ? (float) $expense['importe_iva'] : 0,
            'observations'          => $observations,
            'created_at'            => Carbon::now()->format('Y-m-d H:i:s'),
            'payment_methods'       => $payment_methods,
        ];
    }

    /**
     * Al menos una fila con monto > 0 y `current_acount_payment_method_id`: es lo mínimo que
     * PaymentMethodHelper::attach_payment_methods() necesita para adjuntar algo (las filas sin id
     * las saltea con un warning, y sin ninguna adjunta el gasto no impacta en ninguna caja).
     *
     * @param  array  $payment_methods
     * @return bool
     */
    protected static function hay_metodo_de_pago_valido(array $payment_methods) {

        foreach ($payment_methods as $payment_method) {

            if (!is_array($payment_method)) {

                continue;
            }

            $amount = isset($payment_method['amount']) ? $payment_method['amount'] : null;

            if (!is_numeric($amount) || (float) $amount <= 0) {

                continue;
            }

            if (empty($payment_method['current_acount_payment_method_id'])) {

                continue;
            }

            return true;
        }

        return false;
    }
}
