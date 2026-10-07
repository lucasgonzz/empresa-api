<?php

namespace Tests\Fakes;

use RuntimeException;

/**
 * Lo que lanza el freno de internet de los tests (`HttpFactorySinSalida` para la fachada `Http`,
 * `StreamHttpSinSalida` para `file_get_contents('http://…')` y compañía) cuando un test intenta un
 * pedido HTTP que nadie falseó.
 *
 * Es una clase propia (y no un `RuntimeException` pelado) para que se la pueda reconocer: el código
 * de la aplicación suele atrapar `\Throwable` y seguir, y un test que quiera afirmar "esto intentó
 * salir y se frenó" necesita distinguir esta excepción de cualquier otro error.
 */
class PedidoHttpRealBloqueado extends RuntimeException
{
}
