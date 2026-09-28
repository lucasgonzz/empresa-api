<?php

namespace App\Http\Controllers\Helpers\Excel\Article;

use App\Exports\ArticleExport;
use App\Http\Controllers\Helpers\Excel\XlsxStreamWriter;
use App\Models\Article;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Shared\StringHelper;

/**
 * Escribe el Excel de artículos fila por fila, por lotes, sin tener nunca el catálogo entero en
 * memoria.
 *
 * Por qué existe (28/9/2026): la exportación de Servian (750.320 artículos activos) reventaba los
 * 4 GB del worker al minuto de arrancar. ArticleExport es un FromCollection de maatwebsite: traía
 * el catálogo entero con sus relaciones en un solo ->get(), PhpSpreadsheet armaba el libro entero
 * en memoria y el job, para contar, volvía a armar el catálogo desde cero. Nunca había terminado.
 *
 * Qué cambia y qué no:
 * - Las columnas, su orden y su contenido siguen saliendo de ArticleExport::headings() y ::map():
 *   este archivo solo cambia CÓMO se recorre el catálogo y CÓMO se escribe el archivo.
 * - Se recorre por lotes de LOTE artículos, por id descendente (el mismo orden de antes).
 * - Se escribe con XlsxStreamWriter, que vuelca cada fila al disco apenas la recibe (ahí está por
 *   qué no OpenSpout).
 * - El tipado de cada celda replica el de maatwebsite + PhpSpreadsheet (ver celda()), para que
 *   exportar y volver a importar el mismo archivo siga dando exactamente lo mismo.
 */
class ArticleExportStreamer
{
    /**
     * Artículos por lote. Con las relaciones del Excel cargadas, mil artículos ocupan unas decenas
     * de MB: el pico de memoria queda fijo sin importar el tamaño del catálogo.
     */
    const LOTE = 1000;

    /**
     * Mismo nombre de hoja que ponía PhpSpreadsheet.
     */
    const NOMBRE_HOJA = 'Worksheet';

    /**
     * @var int
     */
    protected $owner_user_id;

    /**
     * Ids pedidos (selección o filtro del listado); null exporta todos los artículos activos.
     *
     * @var array|null
     */
    protected $article_ids;

    /**
     * Tamaño de lote vigente (LOTE salvo en los tests, que lo achican para cruzar varios lotes).
     *
     * @var int
     */
    protected $lote = self::LOTE;

    /**
     * @param int        $owner_user_id
     * @param array|null $article_ids
     */
    public function __construct($owner_user_id, $article_ids = null)
    {
        $this->owner_user_id = (int) $owner_user_id;

        if (is_array($article_ids) && count($article_ids)) {
            $ids = array_values(array_unique(array_map('intval', $article_ids)));
            // Mismo orden que el recorrido del catálogo completo: id descendente.
            rsort($ids);
            $this->article_ids = $ids;
        } else {
            $this->article_ids = null;
        }
    }

    /**
     * Cambia el tamaño de lote. Existe para que los tests crucen varios lotes con pocos artículos.
     *
     * @param int $lote
     * @return $this
     */
    public function con_lote($lote)
    {
        $this->lote = max(1, (int) $lote);

        return $this;
    }

    /**
     * Cuántos artículos se van a exportar, para la barra de avance. En una selección es la
     * cantidad de ids pedidos (puede haber alguno ya borrado: la barra igual termina en completar).
     *
     * @return int
     */
    public function total()
    {
        if (!is_null($this->article_ids)) {
            return count($this->article_ids);
        }

        return Article::where('user_id', $this->owner_user_id)
                    ->where('status', 'active')
                    ->count();
    }

    /**
     * Genera el Excel y lo deja en $relative_path del disco por defecto (el mismo lugar donde lo
     * dejaba Excel::store()).
     *
     * @param string        $relative_path Ej: exported-files/comerciocity-articulos_....xlsx
     * @param callable|null $al_avanzar    Recibe la cantidad de artículos escritos hasta el momento,
     *                                     una vez por lote.
     * @return int Cantidad de artículos escritos.
     */
    public function guardar($relative_path, callable $al_avanzar = null)
    {
        $temporal = $this->ruta_temporal();

        try {
            $escritos = $this->escribir($temporal, $al_avanzar);

            // Se sube por stream: el archivo no pasa por memoria aunque pese decenas de MB.
            $stream = fopen($temporal, 'r');
            try {
                Storage::put($relative_path, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            return $escritos;
        } finally {
            if (file_exists($temporal)) {
                @unlink($temporal);
            }
        }
    }

    /**
     * Escribe el Excel completo en $ruta_absoluta.
     *
     * @param string        $ruta_absoluta
     * @param callable|null $al_avanzar
     * @return int Cantidad de artículos escritos.
     */
    public function escribir($ruta_absoluta, callable $al_avanzar = null)
    {
        // Un job de cola no registra consultas, pero si alguna vez se prende el log de consultas,
        // con cientos de lotes se come la memoria que este archivo ahorra.
        DB::connection()->disableQueryLog();

        /*
         * ArticleExport fija el contexto de ExportHelper (dueño, listas de precio, depósitos,
         * extensiones) en el constructor y lo limpia en el destructor: tiene que vivir hasta
         * cerrar el archivo.
         */
        $export = new ArticleExport(null, $this->owner_user_id);

        $writer = new XlsxStreamWriter($ruta_absoluta, $this->carpeta_temporal(), self::NOMBRE_HOJA);

        $escritos = 0;

        try {
            $writer->agregar_fila($this->fila($export->headings()));

            $this->recorrer_lotes($export, function ($articles) use ($writer, $export, &$escritos, $al_avanzar) {

                foreach ($export->preparar_filas($articles) as $row) {
                    $writer->agregar_fila($this->fila($export->map($row)));
                }

                $escritos += $articles->count();

                if (!is_null($al_avanzar)) {
                    call_user_func($al_avanzar, $escritos);
                }
            });
        } catch (\Throwable $e) {
            $writer->descartar();
            throw $e;
        }

        $writer->cerrar();

        return $escritos;
    }

    /**
     * Recorre los artículos a exportar de a LOTE, con las relaciones del Excel ya cargadas.
     *
     * Catálogo completo: paginado por clave (id < último id del lote anterior), que sobre el
     * índice de user_id no se degrada como un OFFSET a medida que avanza.
     * Selección: los ids pedidos en tramos de LOTE, sin filtrar por estado (como antes).
     *
     * @param ArticleExport $export
     * @param callable      $procesar Recibe una Collection de artículos por lote.
     * @return void
     */
    protected function recorrer_lotes(ArticleExport $export, callable $procesar)
    {
        if (!is_null($this->article_ids)) {

            foreach (array_chunk($this->article_ids, $this->lote) as $ids) {

                $articles = $export->consulta_articulos(false)
                                ->whereIn('articles.id', $ids)
                                ->orderBy('articles.id', 'DESC')
                                ->get();

                if ($articles->count()) {
                    $procesar($articles);
                }

                unset($articles);
            }

            return;
        }

        $ultimo_id = null;

        do {
            $query = $export->consulta_articulos(true)
                        ->orderBy('articles.id', 'DESC')
                        ->limit($this->lote);

            if (!is_null($ultimo_id)) {
                $query->where('articles.id', '<', $ultimo_id);
            }

            $articles = $query->get();
            $cantidad = $articles->count();

            if ($cantidad) {
                $ultimo_id = $articles->last()->id;
                $procesar($articles);
            }

            unset($articles, $query);

        } while ($cantidad == $this->lote);
    }

    /**
     * Convierte un array de valores en las celdas tipadas de una fila.
     *
     * @param array $valores
     * @return array
     */
    protected function fila(array $valores)
    {
        $celdas = [];

        foreach ($valores as $valor) {
            $celdas[] = $this->celda($valor);
        }

        return $celdas;
    }

    /**
     * Una celda con el mismo tipo que le daba maatwebsite + PhpSpreadsheet a ese valor.
     *
     * - Worksheet::fromArray() compara con `!= null` (maatwebsite no usa comparación estricta):
     *   null, false, 0, 0.0, '' y [] quedan como celda VACÍA, no como cero. El importador
     *   distingue vacío de cero, así que esto se respeta tal cual.
     * - Fechas: 'Y-m-d H:i:s' como texto. Arrays: JSON. Otros objetos: (string).
     * - Texto que parece número (el regex de DefaultValueBinder) se escribe como número, salvo con
     *   ceros a la izquierda ("00123") o enteros más grandes que PHP_INT_MAX, que siguen como texto.
     *   Los decimales de MySQL llegan como string: sin esto el costo y el precio saldrían como texto.
     * - Única diferencia a propósito: un texto que empieza con "=" PhpSpreadsheet lo escribía como
     *   fórmula (y Excel mostraba #¿NOMBRE?); acá queda como texto.
     *
     * @param mixed $valor
     * @return array|null [tipo, valor] de XlsxStreamWriter, o null si la celda va vacía.
     */
    protected function celda($valor)
    {
        if ($valor == null) {
            return null;
        }

        if (is_array($valor)) {
            $valor = json_encode($valor);
        } else if ($valor instanceof DateTimeInterface) {
            $valor = $valor->format('Y-m-d H:i:s');
        } else if (is_object($valor)) {
            $valor = (string) $valor;
        }

        if (is_bool($valor)) {
            return [XlsxStreamWriter::TIPO_BOOLEANO, $valor];
        }

        if (is_int($valor) || is_float($valor)) {
            return [XlsxStreamWriter::TIPO_NUMERO, $valor];
        }

        $valor = $this->utf8_valido((string) $valor);

        $numero = $this->como_numero($valor);
        if (!is_null($numero)) {
            return [XlsxStreamWriter::TIPO_NUMERO, $numero];
        }

        return [XlsxStreamWriter::TIPO_TEXTO, $valor];
    }

    /**
     * Devuelve el int/float equivalente si PhpSpreadsheet trataba ese texto como número, o null.
     * Copia de DefaultValueBinder::dataTypeForValue() (phpoffice/phpspreadsheet 1.30).
     *
     * @param string $valor
     * @return int|float|null
     */
    protected function como_numero($valor)
    {
        if (!preg_match('/^[\+\-]?(\d+\.?\d*|\d*\.?\d+)([Ee][\-\+]?[0-2]?\d{1,3})?$/', $valor)) {
            return null;
        }

        $sin_signo = ltrim($valor, '+-');

        if (strlen($sin_signo) > 1 && $sin_signo[0] === '0' && $sin_signo[1] !== '.') {
            return null;
        }

        if (strpos($valor, '.') === false && $valor > PHP_INT_MAX) {
            return null;
        }

        if (!is_numeric($valor)) {
            return null;
        }

        if (strpos($valor, '.') === false && stripos($valor, 'e') === false) {
            return (int) $valor;
        }

        return (float) $valor;
    }

    /**
     * Misma limpieza de UTF-8 que hacía PhpSpreadsheet, pero solo cuando hace falta: un byte
     * inválido rompería el XML del archivo entero.
     *
     * @param string $valor
     * @return string
     */
    protected function utf8_valido($valor)
    {
        if (
            !mb_check_encoding($valor, 'UTF-8')
            || strpos($valor, "\xef\xbf\xbe") !== false
            || strpos($valor, "\xef\xbf\xbf") !== false
        ) {
            return StringHelper::sanitizeUTF8($valor);
        }

        return $valor;
    }

    /**
     * Archivo donde se escribe antes de subirlo al disco.
     *
     * @return string
     */
    protected function ruta_temporal()
    {
        return $this->carpeta_temporal() . DIRECTORY_SEPARATOR . 'export-articulos-' . uniqid('', true) . '.xlsx';
    }

    /**
     * Carpeta de trabajo: ahí se arma el XML de la hoja y el .xlsx antes de subirlo.
     * Dentro de storage y no en /tmp: en el hosting compartido el /tmp es chico y compartido.
     *
     * @return string
     */
    protected function carpeta_temporal()
    {
        $carpeta = storage_path('app' . DIRECTORY_SEPARATOR . 'tmp-exportaciones');

        if (!is_dir($carpeta)) {
            @mkdir($carpeta, 0775, true);
        }

        return $carpeta;
    }
}
