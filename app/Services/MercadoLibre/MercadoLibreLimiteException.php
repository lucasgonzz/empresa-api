<?php

namespace App\Services\MercadoLibre;

/**
 * Mercado Libre pidió esperar y los reintentos se agotaron (429 / 5xx / 409 seguidos, o un candado
 * de refresh que no se liberó a tiempo). Misión mercado-libre-integracion (27/10/2026).
 *
 * No es un error del artículo ni del comercio: quien la atrapa deja el trabajo para la próxima
 * corrida (la sincronización vuelve a `pendiente`) en vez de marcarlo fallido y notificar.
 */
class MercadoLibreLimiteException extends \RuntimeException
{
}
