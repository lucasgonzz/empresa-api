<?php

namespace App\Services\Zipnova;

/**
 * Una precondición del envío que no se cumple (misión zipnova-envios, 14/9/2026): el comercio
 * no tiene Zipnova conectado, el pedido no tiene forma de envío elegida, faltan datos del
 * destinatario, ya hay un envío vivo, ninguno de los artículos viaja, el envío nunca se generó
 * y no hay nada que sincronizar o cancelar.
 *
 * No es un error de Zipnova (eso es `ZipnovaException`): acá ni siquiera se llamó a la API. El
 * controller responde 422 con este mensaje tal cual; `OrderController@update` la atrapa junto
 * con todo lo demás y la deja en el log, porque la confirmación del pedido nunca se revierte
 * por un envío que no se pudo generar.
 *
 * Solo `empresa-api`: la tienda no genera envíos.
 */
class EnvioNoGenerableException extends \RuntimeException
{
}
