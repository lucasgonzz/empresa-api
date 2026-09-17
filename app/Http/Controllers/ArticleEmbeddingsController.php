<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\article\ArticleEmbeddingsEstadoHelper;

class ArticleEmbeddingsController extends Controller
{
    /**
     * Contadores de estado de los embeddings del catálogo del comercio, para el
     * whatsapp-dashboard: cuántos artículos nunca tuvieron un embedding generado, cuántos lo
     * tienen desactualizado (a la espera del próximo ciclo) y cuántos le faltan a la tanda de
     * generación en curso, si hay una viva. De sólo lectura: nunca encola nada (la generación la
     * dispara sola el scheduler, o un artículo nuevo/editado vía ArticleObserver).
     *
     * @return \Illuminate\Http\JsonResponse { sin_generar, pendiente, generandose }
     */
    function estado() {

        return response()->json(
            ArticleEmbeddingsEstadoHelper::estado($this->userId()),
            200
        );
    }
}
