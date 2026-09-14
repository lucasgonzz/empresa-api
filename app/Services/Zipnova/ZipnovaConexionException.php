<?php

namespace App\Services\Zipnova;

/**
 * Una regla de la conexión con Zipnova que no se cumplió (misión zipnova-envios, 14/9/2026):
 * credenciales válidas pero sin ninguna cuenta, la plataforma que falta en el catálogo, un
 * depósito que no está en la lista, el comercio que todavía no conectó.
 *
 * Es distinta de `ZipnovaException` a propósito: aquélla es "Zipnova respondió mal o no
 * respondió" (y el controller decide 422 o 502 según el código HTTP); ésta es "el pedido no
 * tiene sentido tal como vino" y siempre es un 422 con este mensaje, que ya está escrito para
 * el operador.
 *
 * Solo `empresa-api`: la tienda no conecta ni configura nada.
 */
class ZipnovaConexionException extends \RuntimeException
{
}
