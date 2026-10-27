<?php

namespace App\Services\MercadoLibre;

/**
 * La categoría de Mercado Libre elegida no sirve para publicar (no existe, está mal escrita o no
 * es la última del árbol). El mensaje ya es para el comercio: el controller lo devuelve en un 422
 * `{message}` (misión mercado-libre-integracion, 27/10/2026).
 */
class MercadoLibreCategoriaInvalidaException extends \RuntimeException
{
}
