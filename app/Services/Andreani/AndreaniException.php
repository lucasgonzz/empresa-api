<?php

namespace App\Services\Andreani;

/**
 * Falla al hablar con la API pública de Andreani (misión envios-andreani, 25/11/2026).
 *
 * Lleva el código HTTP que devolvió Andreani (0 si no hubo respuesta: timeout, DNS, SSL) y el body
 * decodificado, para que quien la atrapa distinga "Andreani no pudo dar la tarifa" (400: contrato o número de
 * cliente que no reconoce, o un código postal que no existe; el cuerpo no deja distinguirlos) de "Andreani está
 * caído" (5xx o sin respuesta) sin parsear el mensaje.
 *
 * El mensaje ya viene en castellano y nunca incluye datos del comercio.
 *
 * 🔴 ARCHIVO ESPEJO ENTRE `empresa-api` Y `tienda-api` (solo cambia el `namespace`/`use`). Se compara con `diff`.
 */
class AndreaniException extends \RuntimeException
{
    /** @var int Código HTTP de la respuesta de Andreani, 0 si no hubo respuesta. */
    protected $status;

    /** @var array Body decodificado de la respuesta (vacío si no era JSON). */
    protected $body;

    /**
     * @param string $message Mensaje legible.
     * @param int $status Código HTTP (0 si no hubo respuesta).
     * @param array $body Body decodificado.
     * @param \Throwable|null $previous Excepción original, si la hubo.
     */
    public function __construct($message, $status = 0, array $body = [], $previous = null)
    {
        parent::__construct($message, (int) $status, $previous);

        $this->status = (int) $status;
        $this->body = $body;
    }

    /**
     * Código HTTP de la respuesta de Andreani (0 si no hubo respuesta).
     *
     * @return int
     */
    public function getStatus()
    {
        return $this->status;
    }

    /**
     * Body decodificado de la respuesta.
     *
     * @return array
     */
    public function getBody()
    {
        return $this->body;
    }

    /**
     * True si Andreani rechazó la consulta por sus datos (400, 404 o 422): número de cliente o contrato que no
     * reconoce, o código postal inexistente. No es una caída: reintentar igual no sirve.
     *
     * @return bool
     */
    public function esDeValidacion()
    {
        return in_array($this->status, [400, 404, 422], true);
    }
}
