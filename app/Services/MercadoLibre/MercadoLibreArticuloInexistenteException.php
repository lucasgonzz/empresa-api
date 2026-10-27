<?php

namespace App\Services\MercadoLibre;

/**
 * La sincronización apunta a un artículo que ya no existe (borrado o en la papelera). Se registra
 * como error de esa sincronización, sin notificar: no hay nada que el comercio pueda hacer (misión
 * mercado-libre-integracion, 27/10/2026).
 */
class MercadoLibreArticuloInexistenteException extends \RuntimeException
{
}
