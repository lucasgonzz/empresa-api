<?php

namespace App\Services\MercadoLibre;

/**
 * La instalación no tiene las credenciales de la app de ComercioCity en Mercado Libre (o la
 * `redirect_uri`), así que no se puede conectar ninguna cuenta. El controller la traduce a un 422
 * con `{message}` (misión mercado-libre-integracion, 27/10/2026).
 */
class MercadoLibreNoConfiguradoException extends \RuntimeException
{
}
