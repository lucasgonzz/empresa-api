<?php

namespace App\Services\Zipnova;

/**
 * Falla al hablar con la API de Zipnova (misión zipnova-envios, 14/9/2026).
 *
 * Lleva el código HTTP que devolvió Zipnova (0 si no hubo respuesta: timeout, DNS, SSL) y el body
 * decodificado, para que quien la atrapa pueda distinguir "credenciales inválidas" (401/403) de
 * "ubicación no reconocida" (400/422) de "Zipnova caído" (5xx / 0) sin parsear el mensaje.
 *
 * El mensaje ya viene en castellano y sin datos sensibles: nunca incluye el header de
 * autorización ni el token.
 */
class ZipnovaException extends \RuntimeException
{
    /** @var int Código HTTP de la respuesta de Zipnova, 0 si no hubo respuesta. */
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
     * Código HTTP de la respuesta de Zipnova (0 si no hubo respuesta).
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
     * True si Zipnova rechazó las credenciales (token/secret inválidos o sin permiso).
     *
     * @return bool
     */
    public function esDeCredenciales()
    {
        return $this->status === 401 || $this->status === 403;
    }

    /**
     * True si Zipnova no pudo resolver el destino (código postal / localidad no reconocidos).
     *
     * Zipnova responde 400 "ubicación no reconocida" o 422 de validación sobre `destination`; se
     * mira tanto el código como el texto porque la doc no fija un código de error propio.
     *
     * @return bool
     */
    public function esDeUbicacion()
    {
        if ($this->status !== 400 && $this->status !== 422) {
            return false;
        }

        $texto = strtolower($this->getMessage() . ' ' . json_encode($this->body));

        foreach (['destination', 'destino', 'ubicaci', 'location', 'zipcode', 'city', 'ciudad', 'localidad', 'state', 'provincia'] as $aguja) {
            if (strpos($texto, $aguja) !== false) {
                return true;
            }
        }

        return false;
    }
}
