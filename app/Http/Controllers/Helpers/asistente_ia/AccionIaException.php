<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

/**
 * Resultado de negocio al confirmar o cancelar una tarjeta del asistente de IA (misión
 * asistente-ia-acciones, 15/9/2026): la tarjeta no existe (404), ya no está propuesta (409) o la
 * carga no se puede hacer (422).
 *
 * Es una excepción y no un valor de retorno a propósito: se tira ADENTRO de la transacción del
 * ejecutor, y tirarla es lo que revierte todo lo que la carga alcanzó a escribir. Lo que tiene que
 * sobrevivir al rollback (el `error_mensaje` de un 422, el paso a 'vencida' o 'descartada') lo
 * escribe el ejecutor en el catch, afuera de la transacción; por eso viaja `estado_a_persistir`.
 */
class AccionIaException extends \Exception
{
    /**
     * Código HTTP de la respuesta: 404, 409 o 422.
     *
     * @var int
     */
    public $status;

    /**
     * Estado que hay que dejar guardado después del rollback ('vencida' o 'descartada'), o null.
     *
     * @var string|null
     */
    public $estado_a_persistir;

    /**
     * @param  int  $status
     * @param  string  $mensaje  Texto para la persona.
     * @param  string|null  $estado_a_persistir
     */
    public function __construct($status, $mensaje, $estado_a_persistir = null)
    {
        parent::__construct($mensaje);

        $this->status = (int) $status;
        $this->estado_a_persistir = $estado_a_persistir;
    }
}
