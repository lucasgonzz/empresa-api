<?php

namespace App\Exceptions;

/**
 * OpenAI rechazó la credencial de embeddings (401, 403 o 429 por cuota agotada, siempre con cuerpo
 * de error de OpenAI) o hay un corte abierto por un rechazo anterior (EmbeddingsCredencialCorte).
 *
 * Es un error PERMANENTE: reintentar no lo arregla hasta que alguien corrija la clave o el crédito.
 * Por eso GenerateArticleEmbeddingJob la atrapa aparte, sin re-lanzar ni re-marcar el artículo, en vez
 * de dejarla caer en el `catch (\Throwable)` que reintenta tres veces (y deja una fila en `failed_jobs`
 * por artículo, como en la ola de 27.800 fallidos de noviembre).
 *
 * Extiende \RuntimeException y conserva el texto "error HTTP <status> en OpenAI…" que ya tenía el error
 * de siempre, así cualquier código que atrape RuntimeException (la búsqueda del bot) sigue funcionando.
 */
class EmbeddingsCredencialRechazadaException extends \RuntimeException
{
}
