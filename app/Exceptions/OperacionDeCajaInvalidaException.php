<?php

namespace App\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * Conserva el motivo de caja tanto en la respuesta 422 como en los llamadores
 * históricos que hacen rollback y muestran getMessage() en su respuesta de error.
 */
class OperacionDeCajaInvalidaException extends ValidationException
{
    public function __construct($validator, $response = null, $errorBag = 'default')
    {
        parent::__construct($validator, $response, $errorBag);

        $this->message = $validator->errors()->first() ?: 'No se puede registrar la operación de caja.';
    }
}
