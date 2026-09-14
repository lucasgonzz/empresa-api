<?php

namespace App\Http\Controllers\Helpers\agenda;

/**
 * La tarea tiene un concepto de gasto asociado y el pedido de marcarla como hecha no trajo ni el
 * gasto (monto y métodos de pago) ni el `sin_gasto: true` explícito.
 *
 * Se lanza desde adentro de la transacción de AgendaCompletarHelper::completar(): el
 * PendingCompleted ya está creado en ese punto y la excepción hace que se revierta, así el 422
 * que responde el controller no deja nada escrito (el helper viejo dejaba la tarea marcada como
 * hecha y el gasto sin crear).
 */
class AgendaGastoRequeridoException extends \Exception {

}
