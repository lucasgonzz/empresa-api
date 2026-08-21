<?php

namespace App\Exceptions;

use Exception;

/**
 * Excepción controlada del recordatorio de cobro por WhatsApp (misión
 * recordatorio-cobro-whatsapp). Copia estructural de `SaleWhatsappSendException`, y por el
 * mismo motivo: lo que la distingue de un `\Throwable` cualquiera es tener un `error_code()`
 * ESTABLE, que los tres callers leen de forma distinta.
 *
 * - `RecordatorioCobroController@preview` lo traduce en un 422 con `code`, para que el modal
 *   de previsualización muestre el motivo en criollo en vez de "error de servidor".
 * - `RecordatorioCobroController@enviar` / `@enviar_masivo` lo usan para armar la lista de
 *   `salteados` del 202: un cliente sin teléfono o que ya recibió el recordatorio hoy NO es
 *   un error de la request, es un renglón que el operador tiene que ver.
 * - `SendRecordatorioCobroJob` lo loguea partido en dos: `CODE_ENVIO_NO_CONFIRMADO` va como
 *   `Log::error` (el recordatorio tenía que salir y no salió: hay algo que arreglar) y el
 *   resto como `Log::info` (condición esperable del negocio). Es la misma partición que ya
 *   documenta `SendSaleWhatsappJob`, y se respeta a propósito: si todo fuera error, el canal
 *   se llena de ruido y el fallo real deja de verse.
 */
class RecordatorioCobroException extends Exception
{
    /**
     * Código estable del motivo del fallo (ej: 'sin_telefono', 'ya_recibio_hoy').
     *
     * @var string
     */
    protected $error_code;

    /**
     * @param  string  $message     Mensaje legible (se puede mostrar directo al usuario).
     * @param  string  $error_code  Código estable para que el front decida cómo mostrarlo.
     */
    public function __construct($message, $error_code)
    {
        parent::__construct($message);

        $this->error_code = $error_code;
    }

    /**
     * Código estable del motivo del fallo.
     *
     * @return string
     */
    public function error_code()
    {
        return $this->error_code;
    }
}
