<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Se negó un borrado total de la base porque la instancia ya tiene datos de negocio
 * (misión blindar-user-setup, 5/10/2026).
 *
 * La tira `BorradoTotalDeBaseHelper::exigir_base_sin_datos_o_autorizacion()`, que es lo
 * primero que corre `UserSetupHelper::run()`, o sea ANTES del `migrate:fresh`: cuando
 * esta excepción sale, no se tocó nada.
 *
 * Lleva el nombre de la base y el resumen (tabla => filas) para el log y para quien la
 * atrape, pero el MENSAJE es genérico a propósito: la ruta de admin-sync/user-setup es
 * pública hasta que se prenda la clave, y un mensaje que viaja en la respuesta no puede
 * decirle a un desconocido cómo se llama la base ni cuánto tiene adentro. Lo que se le
 * muestra a quien llama son solo las familias de tablas con datos (`con_datos()`), no
 * los conteos ni el nombre.
 */
class BaseConDatosException extends RuntimeException
{
    /**
     * Nombre de la base que se iba a vaciar (solo para el log: NO va en ninguna respuesta).
     *
     * @var string
     */
    protected $base;

    /**
     * Tablas de negocio que tienen filas, con su conteo: ['users' => 1, 'sales' => 102754].
     *
     * @var array<string, int>
     */
    protected $resumen;

    /**
     * @param  string             $base     Nombre de la base conectada.
     * @param  array<string, int> $resumen  Tabla => cantidad de filas, solo las que tienen datos.
     */
    public function __construct($base, array $resumen)
    {
        parent::__construct(
            'La base de esta instancia ya tiene datos de negocio: el setup la vaciaría entera, '
            . 'así que no se ejecutó y no se tocó nada.'
        );

        $this->base    = (string) $base;
        $this->resumen = $resumen;
    }

    /**
     * Nombre de la base que se iba a vaciar. Solo para el log del servidor.
     *
     * @return string
     */
    public function base()
    {
        return $this->base;
    }

    /**
     * Resumen completo con conteos. Solo para el log del servidor.
     *
     * @return array<string, int>
     */
    public function resumen()
    {
        return $this->resumen;
    }

    /**
     * Las tablas de negocio que tienen datos, sin conteos: es lo único que se le cuenta a
     * quien llamó (ver el comentario de la clase).
     *
     * @return string[]
     */
    public function con_datos()
    {
        return array_keys($this->resumen);
    }
}
