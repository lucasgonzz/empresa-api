<?php

namespace App\Http\Controllers\Helpers\article;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\ArticleProperty;
use App\Models\ArticlePropertyType;
use App\Models\PriceType;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ArticleVariantHelper {

    /**
     * No-op: anteriormente asignaba todas las ArticlePropertyType a artículos nuevos.
     * Cambio: ahora no asigna propiedades automáticamente. Las propiedades se deben crear
     * explícitamente desde el frontend cuando el usuario lo requiera, evitando ruido
     * de propiedades innecesarias en cada artículo.
     *
     * @param mixed $article Artículo para el cual ya no se crearán propiedades por defecto.
     */
    static function set_default_properties($article) {
        // No-op: las propiedades se crean bajo demanda desde el frontend, no automáticamente
    }

}