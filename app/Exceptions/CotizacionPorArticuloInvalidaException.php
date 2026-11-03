<?php

namespace App\Exceptions;

use Illuminate\Validation\ValidationException;

/** Permite responder 422 por este contrato sin cambiar errores ajenos del presupuesto. */
class CotizacionPorArticuloInvalidaException extends ValidationException
{
}
