<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * El reset de datos de negocio se negó o falló (misión reset-datos-cliente, 21/11/2026).
 *
 * Lleva todo lo que el controlador necesita para armar la respuesta del contrato C1: el código (`error`), el
 * estado HTTP, un mensaje para mostrar, un `detalle` opcional (tablas sin clasificar, cantidad de comprobantes,
 * plataformas conectadas...) y el id de la fila de registro cuando ya existe.
 *
 * El mensaje es para una persona y nunca incluye secretos (ni la clave de admin ni el token de simulación).
 */
class ResetDatosNegocioRechazadoException extends RuntimeException
{
    /** @var string Código del contrato (en_curso, varios_duenos, respaldo_fallido...). */
    protected $codigo;

    /** @var int Estado HTTP de la respuesta. */
    protected $http_status;

    /** @var array Datos extra para quien llama. */
    protected $detalle;

    /** @var int|null Id de la fila de `reset_datos_negocio_registros`, si ya se creó. */
    protected $registro_id;

    /**
     * @param string $codigo Código del contrato.
     * @param string $mensaje Mensaje para mostrar.
     * @param int $http_status Estado HTTP.
     * @param array $detalle Datos extra (puede ir vacío).
     */
    public function __construct($codigo, $mensaje, $http_status = 409, array $detalle = [])
    {
        parent::__construct($mensaje);

        $this->codigo = $codigo;
        $this->http_status = (int) $http_status;
        $this->detalle = $detalle;
        $this->registro_id = null;
    }

    /**
     * @return string Código del contrato.
     */
    public function codigo()
    {
        return $this->codigo;
    }

    /**
     * @return int Estado HTTP.
     */
    public function http_status()
    {
        return $this->http_status;
    }

    /**
     * @return array Detalle (vacío si no hay).
     */
    public function detalle()
    {
        return $this->detalle;
    }

    /**
     * @return int|null Id del registro, si ya existe.
     */
    public function registro_id()
    {
        return $this->registro_id;
    }

    /**
     * Anota el id de la fila de registro para devolverlo en la respuesta.
     *
     * @param int $registro_id Id de la fila.
     * @return $this
     */
    public function con_registro($registro_id)
    {
        $this->registro_id = (int) $registro_id;

        return $this;
    }

    /**
     * Agrega datos al detalle (por ejemplo la ruta del respaldo cuando el reset quedó incompleto).
     *
     * @param array $detalle Datos a sumar.
     * @return $this
     */
    public function con_detalle(array $detalle)
    {
        $this->detalle = array_merge($this->detalle, $detalle);

        return $this;
    }
}
