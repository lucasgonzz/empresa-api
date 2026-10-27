<?php

namespace App\Services\DimensionSearch;

use App\Services\BusquedaPorCodigoDeBarrasService;

/**
 * Descarga páginas web con la guarda anti-SSRF de la búsqueda por código de barras (misión
 * medidas-masivas-catalogo, 27/10/2026).
 *
 * BusquedaPorCodigoDeBarrasService::descargar() es el descargador completo: DNS público, IP fijada con
 * CURLOPT_RESOLVE, redirecciones validadas salto por salto, tope de bytes y solo `text/html`. Es
 * protected; ProductFactsResearchService lo reusa heredando. Acá se hace lo mismo y se expone UNA
 * operación pública, `leer()`, sin tocar la clase original.
 *
 * Se resuelve por el contenedor (`app()->makeWith(DimensionPageReader::class, ['owner' => $owner])`)
 * para que los tests lo reemplacen por uno con DNS de prueba: nada sale a internet.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin operador nullsafe.
 */
class DimensionPageReader extends BusquedaPorCodigoDeBarrasService
{
    /** Segundos máximos para bajar UNA página (se recorta a lo que quede del presupuesto). */
    const SEGUNDOS_POR_PAGINA = 10;

    /** Presupuesto de tiempo de TODAS las páginas de un artículo, en segundos. */
    const PRESUPUESTO_POR_ARTICULO = 90;

    /** @var string|null La cabecera Content-Type de la última página descargada. */
    protected $content_type = null;

    /**
     * @param  \App\Models\User|null $owner
     */
    public function __construct($owner)
    {
        parent::__construct($owner);

        $this->reiniciar_presupuesto();
    }

    /**
     * Vuelve a empezar el reloj: un lector sirve para varios artículos seguidos y cada uno tiene su
     * propio presupuesto.
     *
     * @param  int|null $segundos  null = PRESUPUESTO_POR_ARTICULO.
     * @return $this
     */
    public function reiniciar_presupuesto($segundos = null)
    {
        $this->vence_en   = microtime(true) + (is_null($segundos) ? self::PRESUPUESTO_POR_ARTICULO : (int) $segundos);
        $this->descartes  = [];

        return $this;
    }

    /**
     * El HTML de una página, o null si no se pudo (destino no permitido, no es HTML, pesa de más,
     * error, sin presupuesto de tiempo).
     *
     * @param  string $url
     * @return string|null
     */
    public function leer($url)
    {
        $this->content_type = null;

        $html = $this->descargar((string) $url, 'pagina', self::SEGUNDOS_POR_PAGINA);

        if (is_null($html)) {
            return null;
        }

        // Siempre UTF-8: una página en ISO-8859-1 / Windows-1252 no tiene que quedar ilegible.
        return DimensionPageText::a_utf8($html, $this->content_type);
    }

    /**
     * Antes de leer el cuerpo, anota la cabecera `Content-Type` de la respuesta final (la que trae el
     * juego de caracteres), y sigue con la lectura de siempre.
     *
     * @param  \Illuminate\Http\Client\Response $respuesta
     * @param  int    $max_bytes
     * @param  string $url
     * @return string|null
     */
    protected function leer_con_tope($respuesta, $max_bytes, $url)
    {
        $this->content_type = (string) $respuesta->header('Content-Type');

        return parent::leer_con_tope($respuesta, $max_bytes, $url);
    }

    /**
     * Por qué no se pudo bajar la última página, en una frase legible (el último descarte de la guarda).
     *
     * @return string
     */
    public function ultimo_descarte()
    {
        $descartes = $this->descartes();

        return empty($descartes) ? 'No se pudo descargar la página.' : (string) end($descartes);
    }
}
