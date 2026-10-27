<?php

namespace App\Services\MercadoPago;

/**
 * Rechazo "esperado" del cobro con QR de Mercado Pago (misión cobro-qr-mercado-pago,
 * 26/10/2026): no hay cuenta conectada, no hay QR para la caja, MP rechazó la orden, el cliente
 * está pagando justo cuando se quiso cancelar, etc.
 *
 * No es un error del programa: es un estado posible que el cajero tiene que leer. Por eso trae
 * armado lo que el controller devuelve tal cual — el status HTTP y el cuerpo JSON del contrato con
 * el SPA (`{message, sin_qr: true}`, `{message, pagando: true}`, ...) — y el `message` es SIEMPRE
 * un texto legible en castellano, nunca SQL, una traza ni la respuesta cruda de Mercado Pago.
 */
class MercadoPagoQrException extends \RuntimeException
{
    /** @var int Status HTTP con el que el controller responde. */
    protected $http_status;

    /** @var array Cuerpo JSON de la respuesta (siempre con `message`). */
    protected $payload;

    /**
     * @param string $message Texto legible para el cajero.
     * @param int $http_status Status HTTP de la respuesta (422 por defecto, 409 para "pagando").
     * @param array $extra Banderas del contrato que acompañan al mensaje (`sin_qr`, `pagando`, ...).
     */
    public function __construct($message, $http_status = 422, array $extra = [])
    {
        parent::__construct($message);

        $this->http_status = (int) $http_status;
        $this->payload     = array_merge(['message' => $message], $extra);
    }

    /**
     * @return int
     */
    public function http_status()
    {
        return $this->http_status;
    }

    /**
     * @return array
     */
    public function payload()
    {
        return $this->payload;
    }
}
