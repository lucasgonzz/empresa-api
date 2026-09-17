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

    /** @var array<string,\App\Models\Article> Indice precargado por bar_code, con la clave normalizada por clave_de_indice(). */
    protected $articulos_por_bar_code = [];

    /** @var array<string,\App\Models\Article> Indice precargado por provider_code, con la clave normalizada por clave_de_indice(). */
    protected $articulos_por_provider_code = [];

    /** @var array<string,\App\Models\Article> Indice precargado por name, con la clave normalizada por clave_de_indice(). */
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
            $this->articulos_por_bar_code = $this->indexar_por(
                Article::where('user_id', $this->user->id)
                    ->whereIn('bar_code', array_unique($bar_codes))
                    ->get(),
                'bar_code'
            );
        }

        if (count($provider_codes) > 0) {
            $this->articulos_por_provider_code = $this->indexar_por(
                Article::where('user_id', $this->user->id)
                    ->whereIn('provider_code', array_unique($provider_codes))
                    ->get(),
                'provider_code'
            );
        }

        if (count($names) > 0) {
            $this->articulos_por_name = $this->indexar_por(
                Article::where('user_id', $this->user->id)
                    ->whereIn('name', array_unique($names))
                    ->get(),
                'name'
            );
        }
    }

    /**
     * Arma un índice en memoria de artículos por uno de sus identificadores, con la clave pasada
     * SIEMPRE por clave_de_indice().
     *
     * 🔴 NO reemplazar por `->keyBy('<campo>')->all()`, que es lo que hacía hasta el 17/9/2026.
     * El porqué está entero en clave_de_indice(): la clave cruda deja el índice sensible a
     * mayúsculas y a ceros a la izquierda, y con `import_type = 'pedido'` una fila que no matchea
     * no falla — CREA un artículo duplicado, en silencio.
     *
     * ⚠️ Si dos artículos distintos del catálogo colapsan en la misma clave normalizada (existen
     * `Martillo` y `MARTILLO` como dos filas separadas), este índice se queda con UNO solo — el
     * último de la colección. Es aceptable a propósito: replica exactamente lo que hacía el
     * `Article::where('name', $name)->first()` de antes de la precarga, que con la collation `_ci`
     * de MySQL veía las dos filas y devolvía una sola.
     *
     * @param \Illuminate\Support\Collection $articulos
     * @param string $campo 'bar_code' | 'provider_code' | 'name'
     * @return array<string,\App\Models\Article>
     */
    protected function indexar_por(Collection $articulos, $campo)
    {
        $indice = [];

        foreach ($articulos as $articulo) {
            $indice[$this->clave_de_indice($articulo->getAttribute($campo))] = $articulo;
        }

        return $indice;
    }

    /**
     * Normaliza un identificador (código de barras, código de proveedor o nombre) para usarlo como
     * CLAVE del índice precargado de artículos.
     *
     * 🔴 ACÁ ES DONDE ALGUIEN VA A QUERER "SIMPLIFICAR" Y VOLVER A LA CLAVE CRUDA. No se hace, y
     * este es el motivo, medido el 17/9/2026:
     *
     * Hasta la precarga en memoria, el artículo se buscaba con `Article::where('name', $name)
     * ->first()`, o sea lo resolvía MySQL — cuya collation es `_ci`: INSENSIBLE a mayúsculas. La
     * precarga cambió eso por un lookup de clave de array de PHP, que es EXACTO y sensible a
     * mayúsculas. El Excel de un proveedor que escribe `MARTILLO` contra un catálogo que tiene
     * `Martillo` dejaba de matchear — y como en `import_type = 'pedido'` un artículo no encontrado
     * se CREA, cada fila no matcheada duplicaba el artículo. Con 700 filas de un proveedor que
     * escribe en mayúsculas eso duplica el catálogo entero sin un solo error en pantalla.
     *
     * Con los códigos hay además el caso de los ceros a la izquierda: `'0123'` contra `'123'`. Por
     * eso, cuando el valor es todo dígitos, se le sacan los ceros adelante antes de usarlo como
     * clave. Lo que ESO cubre es la colisión DENTRO del mismo archivo (una fila crea el artículo
     * con `'123'` y tres filas más abajo el mismo código viene escrito `'0123'`: sin esto se
     * creaba un segundo artículo).
     *
     * ⚠️ Lo que NO cubre, y conviene saberlo antes de confiarse: si el cero a la izquierda está en
     * la BASE (`bar_code = '0123'`) y el Excel trae `'123'`, la precarga ni siquiera trae esa fila
     * —`whereIn` compara string contra string y no matchea—, así que el índice no la tiene y la
     * fila termina creando un duplicado igual. Eso NO es culpa de la precarga: el
     * `Article::where('bar_code', $bar_code)->first()` de antes (con `$bar_code` ya casteado a
     * string por `ImportHelper::getColumnValue()`) tampoco matcheaba. Arreglarlo de verdad implica
     * ensanchar la query, y eso se decide aparte.
     *
     * ⚠️ Esta función tiene que usarse en LOS DOS lados —al indexar y al buscar— y por eso vive
     * acá y no inline: si un lado normalizara distinto del otro, el índice nunca matchearía y
     * volvería el duplicado silencioso.
     *
     * ⚠️ Es solo para el ÍNDICE de búsqueda. Lo que se ESCRIBE en la base (el `name`, el
     * `bar_code` y el `provider_code` del `Article::create()` de get_article()) sigue siendo el
     * valor ORIGINAL del Excel, nunca el normalizado.
     *
     * 🔴 Los acentos TAMBIÉN se pliegan, y no es una decisión de gusto: es fidelidad al
     * comportamiento que había antes de la precarga. Las tres columnas de búsqueda son
     * `utf8mb4_unicode_ci` (medido el 17/9/2026 sobre `articles`), y esa collation pliega tanto la
     * caja como los diacríticos. Medido en MySQL, no deducido del manual:
     *
     *     SELECT 'Martillo' COLLATE utf8mb4_unicode_ci = 'MARTÍLLO';  -- 1
     *     SELECT 'Pina'     COLLATE utf8mb4_unicode_ci = 'Piña';      -- 1
     *
     * O sea que el `where('name', $name)->first()` viejo YA matcheaba `CAÑERIA` (como lo escribe un
     * proveedor que manda la lista en mayúsculas y sin acentos) contra `Cañería` del catálogo. Si
     * acá sólo bajáramos la caja, ese caso dejaría de matchear y crearía un duplicado — la misma
     * falla silenciosa que esta función vino a cerrar, sólo que más angosta. Plegar la `ñ` a `n`
     * hace que `Piña` y `Pina` colapsen en la misma clave: es exactamente lo que MySQL hacía, así
     * que no es un riesgo nuevo que estemos introduciendo.
     *
     * @param mixed $valor Valor crudo del Excel o del modelo.
     * @return string Clave normalizada.
     */
    protected function clave_de_indice($valor)
    {
        // mb_strtolower y no strtolower: strtolower es byte a byte y en UTF-8 deja los acentos
        // sin bajar (y puede partir un carácter multibyte).
        $clave = mb_strtolower(trim((string) $valor), 'UTF-8');

        /*
         * Plegado de diacríticos, para replicar la collation utf8mb4_unicode_ci (ver docblock).
         * Mapa explícito en vez de iconv('ASCII//TRANSLIT'), que depende del locale del sistema y
         * en Windows devuelve '?' o directamente el string sin tocar. Todas las claves van en
         * minúscula porque esto corre DESPUÉS del mb_strtolower de arriba.
         */
        $clave = strtr($clave, [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a',
            'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
            'ñ' => 'n', 'ç' => 'c',
        ]);

        /*
         * Ceros a la izquierda de un código numérico. Ojo además con que PHP castea a entero toda
         * clave de array que parezca un entero (`'123'` se guarda como `123`): no es un problema
         * mientras las dos puntas pasen por acá, porque el casteo lo aplica igual al indexar y al
         * buscar.
         */
        if (preg_match('/^\d+$/', $clave) === 1) {
            $clave = ltrim($clave, '0');

            if ($clave === '') {
                $clave = '0';
            }
        }

        return $clave;
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

        /*
         * 🔴 La búsqueda pasa SIEMPRE por clave_de_indice(), la MISMA función con la que se armó el
         * índice en indexar_por(). No buscar acá con el valor crudo del Excel: el índice está
         * normalizado y un lookup crudo no matchearía nunca contra un catálogo escrito con otra
         * caja. Y no matchear no es un error visible — abajo, en modo 'pedido', crea el artículo.
         */
        if (!is_null($bar_code)) {
            $article = $this->articulos_por_bar_code[$this->clave_de_indice($bar_code)] ?? null;
        } else if (!is_null($provider_code)) {
            $article = $this->articulos_por_provider_code[$this->clave_de_indice($provider_code)] ?? null;
        } else if (!is_null($name)) {
            $article = $this->articulos_por_name[$this->clave_de_indice($name)] ?? null;
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

            /*
             * Se guarda el valor ORIGINAL del Excel, no el normalizado: la normalización es solo
             * para la clave del índice de búsqueda (ver clave_de_indice()). Si acá se guardara la
             * clave, el catálogo del cliente quedaría en minúsculas y sin los ceros de sus códigos.
             */
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
            // precargado no lo conoce y la fila siguiente crearía un duplicado. Con la clave
            // normalizada, igual que en indexar_por(): si acá se indexara crudo, una fila que
            // repite el identificador con otra caja (`MARTILLO` y después `Martillo`) volvería a
            // crear el duplicado dentro del mismo archivo.
            if (!is_null($bar_code)) {
                $this->articulos_por_bar_code[$this->clave_de_indice($bar_code)] = $article;
            } else if (!is_null($provider_code)) {
                $this->articulos_por_provider_code[$this->clave_de_indice($provider_code)] = $article;
            } else if (!is_null($name)) {
                $this->articulos_por_name[$this->clave_de_indice($name)] = $article;
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
