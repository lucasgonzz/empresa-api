<?php

namespace App\Exceptions;

use Exception;

/**
 * Excepción controlada para fallos esperables al enviar el comprobante de una venta por el
 * agente de WhatsApp (grupo 137, Prompt 05): sin cliente/teléfono, sin configuración activa
 * del bot, plantilla `cc_cli_comprobante` todavía no aprobada, etc.
 *
 * La distingue de un error inesperado (\Throwable genérico) el hecho de tener un
 * `error_code` estable: el endpoint manual (`SaleController@send_whatsapp_agent`) lo usa
 * para responder 422 con un código que el front puede mostrar como mensaje claro, y el job
 * automático (`SendSaleWhatsappJob`) lo loguea como condición esperada (no como fallo real)
 * sin relanzarla.
 */
class SaleWhatsappSendException extends Exception
{
    /**
     * Código estable del motivo del fallo (ej: 'sin_telefono', 'plantilla_no_aprobada').
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
