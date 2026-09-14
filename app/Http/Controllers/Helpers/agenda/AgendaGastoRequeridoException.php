<?php

namespace App\Http\Controllers\Helpers\agenda;

/**
 * La tarea tiene un concepto de gasto asociado y el pedido de marcarla como hecha no trajo ni el
 * gasto (monto y métodos de pago) ni el `sin_gasto: true` explícito.
 *
 * Se lanza desde adentro de la transacción de AgendaCompletarHelper::completar(), ANTES de crear
 * el PendingCompleted (datos_del_gasto() corre primero a propósito): el 422 que responde el
 * controller no deja nada escrito ni gasta un id de autoincremento en cada intento fallido. El
 * helper viejo hacía lo contrario: dejaba la tarea marcada como hecha y el gasto sin crear.
 */
class AgendaGastoRequeridoException extends \Exception {

}
