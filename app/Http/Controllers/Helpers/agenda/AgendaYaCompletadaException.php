<?php

namespace App\Http\Controllers\Helpers\agenda;

/**
 * La ocurrencia que se quiere marcar como hecha ya tiene su PendingCompleted (o la tarea puntual
 * ya está `completado`).
 *
 * Se lanza desde adentro de la transacción de AgendaCompletarHelper::completar(), después del
 * candado sobre la tarea, para que el segundo clic (o la segunda pestaña) reciba un 409 y no
 * deje un segundo PendingCompleted con un segundo gasto. Misma estructura que
 * DevolucionExcedidaException: lo que la distingue de un \Exception cualquiera es que el
 * controller sabe traducirla a un código HTTP con sentido.
 */
class AgendaYaCompletadaException extends \Exception {

}
