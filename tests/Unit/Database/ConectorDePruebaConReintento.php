<?php

namespace Tests\Unit\Database;

use App\Database\Connectors\RetryingMySqlConnector;
use Throwable;

/**
 * Doble de prueba de RetryingMySqlConnector: reemplaza intentar_conexion() (que en producción
 * llama a parent::connect(), o sea abre una conexión real) por una secuencia de resultados o
 * excepciones predefinida en $comportamiento, y dormir() por un simple registro — así los tests
 * prueban la ORQUESTACIÓN del reintento (cuántas veces, cuándo corta, qué propaga) sin necesitar
 * una base de MySQL real ni dormir de verdad.
 */
class ConectorDePruebaConReintento extends RetryingMySqlConnector
{
    /** @var int Cuántas veces se llamó intentar_conexion(). */
    public $intentos = 0;

    /** @var int[] El número de intento (1-based) en cada llamada real a dormir(). */
    public $intentos_de_espera = [];

    /**
     * Cola de resultados: cada llamada a intentar_conexion() saca el primer elemento. Si es un
     * Throwable, lo tira; si no, lo devuelve como si fuera el PDO conectado.
     *
     * @var array
     */
    public $comportamiento = [];

    protected function intentar_conexion(array $config)
    {
        $this->intentos++;

        $siguiente = array_shift($this->comportamiento);

        if ($siguiente instanceof Throwable) {
            throw $siguiente;
        }

        return $siguiente;
    }

    protected function dormir($intento)
    {
        $this->intentos_de_espera[] = $intento;
    }
}
