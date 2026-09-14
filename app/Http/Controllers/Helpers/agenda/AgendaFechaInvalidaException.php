<?php

namespace App\Http\Controllers\Helpers\agenda;

/**
 * La fecha que se quiere marcar como hecha no es una ocurrencia de la tarea: una puntual solo
 * tiene su fecha, y una recurrente las que genera su regla (ver AgendaHelper::es_ocurrencia()).
 *
 * La SPA nunca manda una fecha así (marca desde una ocurrencia que la API le devolvió); es
 * defensa contra un llamador directo, para no dejar un PendingCompleted colgado de una fecha que
 * ningún cálculo va a mirar. El controller la traduce a 422.
 */
class AgendaFechaInvalidaException extends \Exception {

}
