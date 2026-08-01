<?php

namespace App\Http\Controllers\Helpers\import\article;

/**
 * Marcador que devuelve find_with_index cuando un identificador del Excel
 * resuelve a mas de un articulo y la configuracion no permite repetidos.
 *
 * No es un Article ni una Collection: quien lo recibe tiene que registrar
 * el conflicto y saltear la fila, nunca crear ni actualizar.
 */
class AmbiguousMatch
{
    /** @var string */
    public $campo;

    /** @var string */
    public $valor;

    /** @var array */
    public $article_ids;

    public function __construct($campo, $valor, array $article_ids)
    {
        $this->campo       = $campo;
        $this->valor       = $valor;
        $this->article_ids = $article_ids;
    }
}
