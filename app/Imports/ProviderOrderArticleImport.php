<?php

namespace App\Imports;

use App\Http\Controllers\CommonLaravel\Helpers\ImportHelper;
use InvalidArgumentException;
use App\Http\Controllers\Helpers\providerOrder\ModoFacturacionHelper;
use App\Http\Controllers\Helpers\providerOrder\NewProviderOrderHelper;
use App\Models\Article;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class ProviderOrderArticleImport implements ToCollection, WithMultipleSheets
{

    /**
     * Cada cuántas filas se invoca $on_progress. Con 700 filas son 14 avisos: suficiente
     * granularidad para la barra de progreso sin recargar de updates la base (misión
     * `import-excel-compras-chunks`, 14/9/2026).
     */
    const FILAS_POR_AVISO_DE_PROGRESO = 50;

    /**
     * Indice 0-based de la hoja a importar. Default 0 = primera hoja.
     *
     * @var int
     */
    public $hoja = 0;

    /**
     * Nombre de la hoja elegida, cuando el cliente lo manda. Gana sobre el indice.
     *
     * @var string|null
     */
    public $hoja_nombre = null;

    public $columns;
    public $start_row;
    public $finish_row;
    public $user;
    public $provider_order;
    public $import_type;
    public $overwrite_articles;
    public $articles;
    public $trabajo_terminado;
    public $current_pivot_by_article_id;

    /**
     * Diff pedido/recibido de esta compra, calculado al final de collection() cuando
     * import_type === 'recibido'. Queda en null hasta que collection() corre; en [] si el modo
     * de importación no es 'recibido'.
     *
     * @var array|null
     */
    public $diff = null;

    /** @var int Cuántas filas resolvieron en un Article::create() nuevo. */
    public $creados = 0;

    /** @var int Cuántas filas resolvieron en un artículo ya existente. */
    public $actualizados = 0;

    /**
     * Callback opcional, invocado cada FILAS_POR_AVISO_DE_PROGRESO filas con
     * (int $filas_de_este_lote, int $num_row_actual). Lo usa
     * App\Jobs\ProcessProviderOrderArticleImport para reportar avance en ImportStatus/
     * ImportHistory sin que este importador conozca esos modelos.
     *
     * @var callable|null
     */
    protected $on_progress;

    /** @var array<string,\App\Models\Article> Indice precargado por bar_code. */
    protected $articulos_por_bar_code = [];

    /** @var array<string,\App\Models\Article> Indice precargado por provider_code. */
    protected $articulos_por_provider_code = [];

    /** @var array<string,\App\Models\Article> Indice precargado por name. */
    protected $articulos_por_name = [];

    /**
     * @param array       $columns
     * @param int         $start_row
     * @param int|null    $finish_row
     * @param \App\Models\User $user
     * @param \App\Models\ProviderOrder $provider_order
     * @param string      $import_type
     * @param bool        $overwrite_articles
     * @param int         $hoja         Indice 0-based de hoja. OPCIONAL: default 0.
     * @param string|null $hoja_nombre  Nombre de la hoja elegida. OPCIONAL: default null.
     * @param callable|null $on_progress OPCIONAL: ver arriba.
     */
    public function __construct($columns, $start_row, $finish_row, $user, $provider_order, $import_type = 'pedido', $overwrite_articles = false, $hoja = 0, $hoja_nombre = null, $on_progress = null) {

        /*
         * Los dos anteultimos parametros son OPCIONALES y con default a proposito: el job que
         * los despacha puede haber quedado desactualizado (paso a paso el historial del proyecto),
         * y un job viejo serializado con menos argumentos tiene que poder seguir andando.
         */
        $this->hoja = (is_numeric($hoja) && (int) $hoja >= 0) ? (int) $hoja : 0;
        $this->hoja_nombre = (is_string($hoja_nombre) && trim($hoja_nombre) !== '')
                                ? trim($hoja_nombre)
                                : null;

        $this->columns            = $columns;
        $this->start_row          = $start_row;
        $this->finish_row         = $finish_row;
        $this->user               = $user;
        $this->provider_order     = $provider_order;
        $this->import_type        = $import_type;
        $this->overwrite_articles = $overwrite_articles;
        $this->on_progress        = is_callable($on_progress) ? $on_progress : null;

        $this->articles           = [];
        $this->trabajo_terminado  = false;
        $this->current_pivot_by_article_id = [];

        $this->load_current_pivots();
    }

    /**
     * Hoja (una sola) que Maatwebsite tiene que recorrer.
     *
     * 🔴 SIN este metodo, Maatwebsite NO importa la primera hoja: importa TODAS.
     * `Reader::loadSpreadsheet()` hace
     * `if (!$import instanceof WithMultipleSheets) { $this->sheetImports = array_fill(0, $this->spreadsheet->getSheetCount(), $import); }`,
     * o sea llama `collection()` una vez por hoja del libro.
     *
     * Y aca eso pegaba MAS FUERTE que en ClientImport y ProviderImport: el final de
     * `collection()` no solo guarda filas, sino que llama `attach_articles()`,
     * `check_modo_facturacion()` y `procesar_pedido()`. Con un libro de tres hojas la
     * compra se procesaba TRES VECES, acumulando en `$this->articles` lo que hubiera
     * leido de las hojas anteriores.
     *
     * ⚠️ CAMBIO DE COMPORTAMIENTO VISIBLE: antes se recorrian todas las hojas del libro,
     * ahora se recorre una sola (la 0 si nadie elige).
     *
     * El NOMBRE le gana al indice cuando viene, por la misma razon que en los otros dos
     * importadores: el indice lo calcula el navegador y quien lee despues es otra libreria.
     *
     * @return array
     */
    public function sheets(): array
    {
        if (!is_null($this->hoja_nombre)) {
            return [$this->hoja_nombre => $this];
        }

        return [$this->hoja => $this];
    }

    /**
     * Cantidad de "chunks lógicos" (avisos de progreso) que va a emitir collection() para un
     * rango de filas dado. La usa el controller para poblar total_chunks en ImportStatus/
     * ImportHistory ANTES de despachar el job, con la misma fórmula que collection() usa para
     * decidir cuándo avisar — si divergieran, la barra de progreso nunca llegaría a 100% o se
     * pasaría de largo.
     *
     * @param int $start_row
     * @param int $finish_row
     * @return int Nunca menor a 1, para no dividir por cero en el cálculo de porcentaje del front.
     */
    public static function calcular_total_chunks($start_row, $finish_row)
    {
        $total_filas = max(0, (int) $finish_row - (int) $start_row + 1);

        return (int) max(1, ceil($total_filas / self::FILAS_POR_AVISO_DE_PROGRESO));
    }

    public function collection(Collection $rows)
    {
        $this->precargar_indice_de_articulos($rows);

        $num_row = 1;
        $filas_desde_ultimo_aviso = 0;

        foreach ($rows as $row) {

            if (
                $num_row >= $this->start_row
                && $num_row <= $this->finish_row
            ) {
                $article = $this->get_article($row);

                if (!is_null($article)) {
                    $this->add_article($article, $row, $num_row);
                }

                $filas_desde_ultimo_aviso++;

                if (!is_null($this->on_progress) && $filas_desde_ultimo_aviso >= self::FILAS_POR_AVISO_DE_PROGRESO) {
                    call_user_func($this->on_progress, $filas_desde_ultimo_aviso, $num_row);
                    $filas_desde_ultimo_aviso = 0;
                }
            }

            $num_row++;
        }

        // Aviso final por lo que quedó sin cerrar un lote completo de FILAS_POR_AVISO_DE_PROGRESO.
        if ($filas_desde_ultimo_aviso > 0 && !is_null($this->on_progress)) {
            call_user_func($this->on_progress, $filas_desde_ultimo_aviso, $num_row - 1);
        }

        $ya_se_actualizo_stock = $this->provider_order->update_stock;

        $helper = new NewProviderOrderHelper($this->provider_order, $this->articles, $ya_se_actualizo_stock);

        $helper->attach_articles($this->overwrite_articles);

        ModoFacturacionHelper::check_modo_facturacion($this->provider_order, $helper);

        $helper->procesar_pedido();

        // El diff pedido/recibido necesita los pivots YA actualizados por attach_articles() de
        // arriba, por eso se calcula acá y no antes.
        $this->diff = $this->import_type === 'recibido'
            ? $this->calculate_received_diff()
            : [];
    }

    /**
     * Precarga en memoria los artículos candidatos de TODO el rango en hasta 3 queries (una por
     * criterio de búsqueda), en vez de la query por fila que hacía get_article() antes de esta
     * misión — con 700 filas eso eran hasta 700 roundtrips secuenciales a la base durante el
     * mismo proceso. Sigue sin poder precargar lo que todavía no existe: crear un artículo nuevo
     * sigue pasando fila por fila en get_article().
     *
     * @param Collection $rows
     * @return void
     */
    protected function precargar_indice_de_articulos(Collection $rows)
    {
        $bar_codes      = [];
        $provider_codes = [];
        $names          = [];

        $num_row = 1;

        foreach ($rows as $row) {

            if ($num_row >= $this->start_row && $num_row <= $this->finish_row) {

                $bar_code      = ImportHelper::getColumnValue($row, 'codigo_de_barras', $this->columns);
                $provider_code = ImportHelper::getColumnValue($row, 'codigo_de_proveedor', $this->columns);
                $name          = ImportHelper::getColumnValue($row, 'nombre', $this->columns);

                // Mismo orden de prioridad que get_article(): una fila consulta UN solo criterio,
                // el primero de los tres que venga cargado.
                if (!is_null($bar_code)) {
                    $bar_codes[] = $bar_code;
                } else if (!is_null($provider_code)) {
                    $provider_codes[] = $provider_code;
                } else if (!is_null($name)) {
                    $names[] = $name;
                }
            }

            $num_row++;
        }

        if (count($bar_codes) > 0) {
            $this->articulos_por_bar_code = Article::where('user_id', $this->user->id)
                ->whereIn('bar_code', array_unique($bar_codes))
                ->get()
                ->keyBy('bar_code')
                ->all();
        }

        if (count($provider_codes) > 0) {
            $this->articulos_por_provider_code = Article::where('user_id', $this->user->id)
                ->whereIn('provider_code', array_unique($provider_codes))
                ->get()
                ->keyBy('provider_code')
                ->all();
        }

        if (count($names) > 0) {
            $this->articulos_por_name = Article::where('user_id', $this->user->id)
                ->whereIn('name', array_unique($names))
                ->get()
                ->keyBy('name')
                ->all();
        }
    }

    function load_current_pivots() {
        $this->provider_order->load('articles');

        foreach ($this->provider_order->articles as $article) {
            $this->current_pivot_by_article_id[$article->id] = [
                'amount'          => $article->pivot->amount,
                'received'        => $article->pivot->received,
                'cost'            => $article->pivot->cost,
                'notes'           => $article->pivot->notes,
                'price'           => $article->pivot->price,
                'discount'        => $article->pivot->discount,
                'iva_id'          => $article->pivot->iva_id,
                'cost_in_dollars' => $article->pivot->cost_in_dollars,
                'amount_pedida'   => $article->pivot->amount_pedida,
                'update_provider' => $article->pivot->update_provider,
            ];
        }
    }

    /**
     * Agrega un artículo al lote de importación normalizando cantidades y costos numéricos.
     *
     * @param \App\Models\Article $article Artículo encontrado o creado para la fila.
     * @param mixed $row Fila del Excel en procesamiento.
     * @param int $row_number Número de fila del Excel (1-based) para mensajes de error.
     * @return void
     * @throws InvalidArgumentException Si algún valor numérico no puede parsearse.
     */
    function add_article($article, $row, $row_number) {

        $amount_raw   = ImportHelper::getColumnValue($row, 'cantidad', $this->columns);
        $received_raw = ImportHelper::getColumnValue($row, 'cantidad_recibida', $this->columns);
        $cost_raw     = ImportHelper::getColumnValue($row, 'costo', $this->columns);
        $notes        = ImportHelper::getColumnValue($row, 'notas', $this->columns);

        // Normaliza valores numéricos tolerando "$ 37468,24" y formatos locales similares.
        $amount   = ImportHelper::parseNumericValue($amount_raw, 'cantidad', $row_number);
        $received = ImportHelper::parseNumericValue($received_raw, 'cantidad recibida', $row_number);
        $cost     = ImportHelper::parseNumericValue($cost_raw, 'costo', $row_number);

        $this->articles[] = [
            'id'           => $article->id,
            'status'       => $article->status,
            'bar_code'     => $article->bar_code,
            'provider_code' => $article->provider_code,
            'pivot' => [
                'amount'          => $amount,
                'received'        => $received,
                'cost'            => $cost,
                'notes'           => $notes,
                'price'           => $article->price,
                'iva_id'          => $article->iva_id,
                'cost_in_dollars' => $article->cost_in_dollars,
                'update_provider' => 0,
            ],
        ];
    }

    function get_model_defaults($article, $current) {
        return [
            'price' => $this->get_model_value_or_fallback($article, 'price', $current['price']),
            'discount' => $this->get_model_value_or_fallback($article, 'discount', $current['discount']),
            'iva_id' => $this->get_model_value_or_fallback($article, 'iva_id', $current['iva_id']),
            'cost_in_dollars' => $this->get_model_value_or_fallback($article, 'cost_in_dollars', $current['cost_in_dollars']),
        ];
    }

    function get_model_value_or_fallback($article, $attribute, $fallback) {
        $value = $article->getAttribute($attribute);
        return is_null($value) ? $fallback : $value;
    }

    function get_current_pivot($article_id) {
        return $this->current_pivot_by_article_id[$article_id] ?? [
            'amount'          => null,
            'received'        => null,
            'cost'            => null,
            'notes'           => null,
            'price'           => null,
            'discount'        => null,
            'iva_id'          => null,
            'cost_in_dollars' => null,
            'amount_pedida'   => null,
            'update_provider' => 1,
        ];
    }

    function get_article($row) {

        $bar_code     = ImportHelper::getColumnValue($row, 'codigo_de_barras', $this->columns);
        $provider_code = ImportHelper::getColumnValue($row, 'codigo_de_proveedor', $this->columns);
        $name         = ImportHelper::getColumnValue($row, 'nombre', $this->columns);

        $article = null;

        if (!is_null($bar_code)) {
            $article = $this->articulos_por_bar_code[$bar_code] ?? null;
        } else if (!is_null($provider_code)) {
            $article = $this->articulos_por_provider_code[$provider_code] ?? null;
        } else if (!is_null($name)) {
            $article = $this->articulos_por_name[$name] ?? null;
        } else {
            /*
             * Fila sin ningún identificador: comportamiento preexistente (no introducido por esta
             * misión), que esta query replica tal cual — Article::where('user_id', ...)->first()
             * sin ningún otro filtro. No se precarga porque no hay con qué indexarlo.
             */
            $article = Article::where('user_id', $this->user->id)->first();
        }

        if (is_null($article)) {

            if ($this->import_type === 'recibido') {
                return null;
            }

            $article = Article::create([
                'bar_code'     => $bar_code,
                'provider_code' => $provider_code,
                'name'         => $name,
                'provider_id'  => $this->provider_order->provider_id,
                'status'       => 'inactive',
                'user_id'      => $this->user->id,
            ]);

            $this->creados++;

            // Un artículo recién creado por esta fila puede volver a matchear en una fila
            // posterior del MISMO archivo (mismo identificador repetido): sin esto, el índice
            // precargado no lo conoce y la fila siguiente crearía un duplicado.
            if (!is_null($bar_code)) {
                $this->articulos_por_bar_code[$bar_code] = $article;
            } else if (!is_null($provider_code)) {
                $this->articulos_por_provider_code[$provider_code] = $article;
            } else if (!is_null($name)) {
                $this->articulos_por_name[$name] = $article;
            }
        } else {
            $this->actualizados++;
        }

        return $article;
    }

    /**
     * Diff pedido/recibido de esta compra: cuánto se pidió de cada artículo contra cuánto se
     * marcó como recibido, con los pivots YA actualizados por attach_articles(). Vivía en
     * ProviderOrderController::calculate_received_diff() hasta la misión
     * `import-excel-compras-chunks` (14/9/2026); se movió acá porque el job asíncrono ya no
     * tiene una respuesta HTTP donde devolverlo — lo calcula acá y lo deja en $this->diff para
     * que el job lo persista donde el frontend lo pueda pedir después.
     *
     * @return array
     */
    public function calculate_received_diff() {

        $this->provider_order->load('articles');

        return $this->provider_order->articles->map(function ($article) {
            $amount   = $article->pivot->amount;
            $received = $article->pivot->received;
            $diff     = $received - $amount;

            if ($received == $amount) {
                $status = 'completo';
            } elseif ($received == 0) {
                $status = 'no_recibido';
            } elseif ($received > $amount) {
                $status = 'exceso';
            } else {
                $status = 'parcial';
            }

            return [
                'id'       => $article->id,
                'name'     => $article->name,
                'pedida'   => $amount,
                'recibida' => $received,
                'diff'     => $diff,
                'status'   => $status,
            ];
        })->values()->toArray();
    }
}
