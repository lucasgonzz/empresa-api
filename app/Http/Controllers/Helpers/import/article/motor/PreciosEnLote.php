<?php

namespace App\Http\Controllers\Helpers\import\article\motor;

use App\Http\Controllers\Helpers\UserHelper;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Modo "lote" del cálculo de precios finales de la importación de artículos.
 *
 * Misión importacion-excel-motor-rapido (24/9/2026). ActualizarBBDD::set_precios_finales() lo
 * envuelve así:
 *
 *   PreciosEnLote::activar($user, $auth_user_id);
 *   try { ... ArticleHelper::setFinalPrice() por artículo ... } finally { PreciosEnLote::volcar(); }
 *
 * El problema que resuelve: setFinalPrice() se llama por artículo con $guardar_cambios = false y
 * aun así escribe. Por cada lista de precio, ArticlePricesHelper hacía price_types()->find() +
 * syncWithoutDetaching() + updateExistingPivot() (3 o 4 consultas por lista por artículo), y por
 * cada artículo cuyo precio cambió PriceChangeController::store() hacía un PriceChange::create()
 * más un attach() por lista. Con 100.000 filas y dos listas son millones de consultas.
 *
 * Con el modo lote encendido, esos helpers NO escriben: registran acá lo que hubieran escrito, y
 * volcar() lo escribe en bloque al final del lote, dejando en la base EXACTAMENTE lo mismo:
 *
 *  - article_price_type: los pares (artículo, lista) que no existen se INSERTAN en una consulta
 *    multi-fila (mismas columnas que dejaba syncWithoutDetaching() + updateExistingPivot(): las
 *    calculadas, y el resto con el default del esquema); los que ya existen se ACTUALIZAN con un
 *    UPDATE ... CASE ... WHERE (article_id, price_type_id) IN (...), el mismo patrón que ya usa
 *    ActualizarBBDD::asignar_price_types(). No se usa upsert: la tabla no tiene índice único
 *    sobre (article_id, price_type_id), y "existe o no" se resuelve con un SELECT por lote.
 *  - price_changes: un INSERT multi-fila con las mismas columnas y valores que PriceChange::create()
 *    (cost, price, final_price, employee_id, created_at/updated_at del momento en que se registró),
 *    y price_change_price_type con un INSERT multi-fila (price_change_id, price_type_id, final_price
 *    de la lista), igual que el attach() de hoy. Sin timestamps en el pivot, como hoy.
 *
 * Fuera del modo lote (ficha, masivas, rollback, cualquier otro llamador de setFinalPrice) nada
 * cambia: los helpers siguen escribiendo uno por uno, como siempre.
 *
 * El cálculo de los precios no vive acá y no se toca: sigue en ArticleHelper / ArticlePricesHelper.
 * Esta clase solo difiere la ESCRITURA.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class PreciosEnLote
{
    /**
     * Relaciones que ArticleHelper::setFinalPrice() y ArticlePricesHelper cargan por artículo.
     * ActualizarBBDD relee los artículos del lote con `with(self::RELACIONES_A_PRECARGAR)` para que
     * el cálculo no dispare una consulta por artículo. Relevadas leyendo setFinalPrice() y sus
     * helpers (24/9/2026):
     *
     *  - article_discounts / article_surchages: aplicar_descuentos() y aplicar_recargos().
     *  - provider: margen del proveedor, dólar del proveedor, price_from_cost_mas_iva.
     *  - provider_price_list: aplicar_margenes_de_proveedor_y_categoria().
     *  - category / sub_category: margen de la categoría y las listas por categoría
     *    (extensión lista_de_precios_por_categoria).
     *  - iva: aplicar_iva() y quitar_iva_y_sale_taxes(). Esos dos hacían load('iva') SIEMPRE;
     *    ahora usan la relación cargada cuando su id coincide con el iva_id del artículo.
     *  - price_types: la fila del pivot de cada lista (previus_final_price, setear_precio_final,
     *    percentage) y las listas del PriceChange.
     *  - sale_taxes: get_sale_taxes_para_articulo(), cuando hay un SaleTax que no aplica a todos.
     *  - article_discounts_blanco / article_surchages_blanco: set_precios_en_blanco()
     *    (extensión articulos_precios_en_blanco).
     *  - price_type_monedas: ArticlePriceTypeMonedaHelper (extensión ventas_en_dolares).
     *
     * 🔴 La relación `price_types` que se precarga tiene que ser la POSTERIOR a
     * asignar_price_types(): en modo lote se lee de ahí la fila del pivot (percentage del Excel,
     * setear_precio_final, final_price anterior). Una relación vieja daría precios viejos.
     *
     * @var array
     */
    const RELACIONES_A_PRECARGAR = [
        'article_discounts',
        'article_surchages',
        'provider',
        'provider_price_list',
        'category',
        'sub_category',
        'iva',
        'price_types',
        'sale_taxes',
        'article_discounts_blanco',
        'article_surchages_blanco',
        'price_type_monedas',
    ];

    /**
     * Columnas de article_price_type que los helpers pueden registrar. Es una lista blanca: el
     * nombre de la columna entra al SQL de volcar(), así que nada que no esté acá se acepta.
     *
     * @var array
     */
    const COLUMNAS_DEL_PIVOT = [
        'percentage',
        'price',
        'final_price',
        'previus_final_price',
        'precio_luego_de_recargos',
        'monto_ganancia',
        'incluir_en_excel_para_clientes',
        'setear_precio_final',
    ];

    /** Filas por INSERT multi-fila. */
    const FILAS_POR_INSERT = 500;

    /** Pares (artículo, lista) por UPDATE ... CASE. */
    const PARES_POR_UPDATE = 250;

    /** @var bool */
    protected static $activo = false;

    /**
     * Interruptor: con true, activar() no enciende nada y todo se escribe por artículo como
     * siempre. Lo usan los tests para comparar los dos modos sobre la misma base; también sirve
     * como salida de emergencia desde tinker o un comando.
     *
     * @var bool
     */
    protected static $deshabilitado = false;

    /** @var \App\Models\User|null */
    protected static $user = null;

    /** @var int|null */
    protected static $auth_user_id = null;

    /**
     * Modelos de los artículos que registraron algo, por id. Se guardan para poder leer su
     * relación price_types cargada en pivot_actual() y en registrar_cambio_de_precio().
     *
     * @var array<int, \App\Models\Article>
     */
    protected static $articulos = [];

    /**
     * Escrituras pendientes del pivot: [article_id => [price_type_id => [
     *     'columnas' => [columna => valor, ...],
     *     'insertar' => bool   (si el par no existe: true lo crea, false lo saltea)
     * ]]].
     *
     * @var array
     */
    protected static $pivots = [];

    /**
     * Cambios de precio pendientes, en el orden en que se registraron. Cada uno:
     * ['article_id', 'cost', 'price', 'final_price', 'employee_id', 'created_at',
     *  'listas' => [[price_type_id, final_price], ...]].
     *
     * @var array
     */
    protected static $cambios = [];

    /** @var int Cuántas veces volcar() escribió algo en este proceso (lo miran los tests). */
    protected static $volcados = 0;

    /** @var array|null Resumen del último volcar(). */
    protected static $ultimo_resumen = null;

    /**
     * Enciende el modo lote para el proceso actual. Descarta cualquier resto de un lote anterior
     * que no se haya volcado.
     *
     * @param  \App\Models\User|null $user
     * @param  int|null              $auth_user_id
     * @return void
     */
    public static function activar($user = null, $auth_user_id = null)
    {
        self::reiniciar();

        if (self::$deshabilitado) {
            return;
        }

        self::$activo       = true;
        self::$user         = $user;
        self::$auth_user_id = $auth_user_id;
    }

    /**
     * ¿Está encendido el modo lote?
     *
     * @return bool
     */
    public static function esta_activo()
    {
        return self::$activo;
    }

    /**
     * Con true, activar() deja de encender el modo lote (todo se escribe por artículo). Si el modo
     * estaba encendido, se descarta lo pendiente sin escribirlo.
     *
     * ⚠️ No es "volver a develop" al pie de la letra. ActualizarBBDD precarga las relaciones de los
     * artículos del lote (RELACIONES_A_PRECARGAR, incluida price_types) haga lo que haga este
     * interruptor; con el modo apagado, PriceChangeController::store() lee esa relación cargada
     * ANTES de que el helper de listas escribiera el pivot, y en price_change_price_type queda el
     * precio de lista viejo (o ninguna fila para un artículo recién atado). develop, sin precarga,
     * la releía fresca. El modo lote sí registra el precio recién calculado. Medido el 24/9/2026
     * en PreciosEnLoteTest::test_una_importacion_en_modo_lote_deja_la_misma_base_que_por_articulo.
     *
     * @param  bool $deshabilitado
     * @return void
     */
    public static function deshabilitar($deshabilitado = true)
    {
        self::$deshabilitado = (bool) $deshabilitado;

        if (self::$deshabilitado && self::$activo) {
            self::descartar();
        }
    }

    /**
     * Apaga el modo lote y tira lo recolectado SIN escribirlo.
     *
     * @return void
     */
    public static function descartar()
    {
        self::reiniciar();
    }

    /**
     * Escribe en bloque lo recolectado y apaga el modo lote. Idempotente: llamarlo sin nada
     * recolectado (o sin el modo encendido) no escribe nada. Pase lo que pase, al salir el modo
     * queda apagado y sin restos: lo que no se pudo escribir se pierde, y la excepción sube al
     * llamador (el job de la importación la registra como fallo, igual que un fallo de hoy a mitad
     * del lote).
     *
     * @return void
     */
    public static function volcar()
    {
        if (!self::$activo) {
            self::reiniciar();
            return;
        }

        try {
            $resumen = self::volcar_pivots();
            $resumen = array_merge($resumen, self::volcar_cambios_de_precio());

            self::$ultimo_resumen = $resumen;

            if ($resumen['pares'] > 0 || $resumen['cambios'] > 0) {
                self::$volcados++;
                Log::info('PreciosEnLote: volcado en bloque', $resumen);
            }

        } finally {
            self::reiniciar();
        }
    }

    /**
     * Cuántas veces volcar() escribió algo en este proceso. Lo usan los tests para saber si la
     * importación pasó de verdad por el modo lote.
     *
     * @return int
     */
    public static function volcados()
    {
        return self::$volcados;
    }

    /**
     * Resumen del último volcar(): pares, insertados, actualizados, cambios, filas_de_listas.
     *
     * @return array|null
     */
    public static function ultimo_resumen()
    {
        return self::$ultimo_resumen;
    }

    /**
     * Cuánto hay registrado y todavía sin escribir.
     *
     * @return array{pares:int,cambios:int}
     */
    public static function pendientes()
    {
        $pares = 0;

        foreach (self::$pivots as $por_lista) {
            $pares += count($por_lista);
        }

        return [
            'pares'   => $pares,
            'cambios' => count(self::$cambios),
        ];
    }

    /* ------------------------------------------------------------------------------------------
     * Lo que usan los helpers mientras el modo está encendido
     * ---------------------------------------------------------------------------------------- */

    /**
     * La fila del pivot de una lista para este artículo, tal como la vería hoy
     * `$article->price_types()->find($price_type_id)` DESPUÉS de las escrituras que este mismo
     * lote todavía tiene pendientes. Sale de la relación price_types cargada (se carga una sola
     * vez si no lo está) con las columnas pendientes por encima.
     *
     * Devuelve null si el par no existe y no hay un alta pendiente; si no, un objeto con `id` y
     * `pivot` (las columnas de la fila), que es lo único que los helpers leen de él.
     *
     * @param  \App\Models\Article $article
     * @param  int                 $price_type_id
     * @return object|null
     */
    public static function pivot_actual($article, $price_type_id)
    {
        self::asegurar_activo(__FUNCTION__);

        $article_id    = (int) $article->id;
        $price_type_id = (int) $price_type_id;

        $article->loadMissing('price_types');

        $relacion = null;

        foreach ($article->price_types as $price_type) {
            if ((int) $price_type->id === $price_type_id) {
                $relacion = $price_type;
                break;
            }
        }

        $pendiente = isset(self::$pivots[$article_id][$price_type_id])
                        ? self::$pivots[$article_id][$price_type_id]
                        : null;

        if (is_null($pendiente)) {
            return $relacion;
        }

        if (is_null($relacion) && !$pendiente['insertar']) {
            return null;
        }

        $base = is_null($relacion)
                    ? self::fila_del_pivot_por_defecto($article_id, $price_type_id)
                    : $relacion->pivot->getAttributes();

        return (object) [
            'id'    => $price_type_id,
            'pivot' => (object) array_merge($base, $pendiente['columnas']),
        ];
    }

    /**
     * Registra las columnas que un helper hubiera escrito en article_price_type para el par
     * (artículo, lista). Si el par ya tenía algo registrado en este lote, lo nuevo pisa columna
     * por columna, como lo harían dos escrituras seguidas.
     *
     * @param  \App\Models\Article $article
     * @param  int                 $price_type_id
     * @param  array               $columnas               [columna => valor], solo COLUMNAS_DEL_PIVOT
     * @param  bool                $insertar_si_no_existe  true: syncWithoutDetaching + update;
     *                                                     false: solo updateExistingPivot.
     * @return void
     */
    public static function registrar_pivot($article, $price_type_id, array $columnas, $insertar_si_no_existe = true)
    {
        self::asegurar_activo(__FUNCTION__);

        foreach (array_keys($columnas) as $columna) {
            if (!in_array($columna, self::COLUMNAS_DEL_PIVOT, true)) {
                throw new \InvalidArgumentException('PreciosEnLote: la columna "' . $columna . '" no es una columna registrable de article_price_type.');
            }
        }

        $article_id    = (int) $article->id;
        $price_type_id = (int) $price_type_id;

        self::$articulos[$article_id] = $article;

        if (!isset(self::$pivots[$article_id])) {
            self::$pivots[$article_id] = [];
        }

        if (isset(self::$pivots[$article_id][$price_type_id])) {

            $previo = self::$pivots[$article_id][$price_type_id];

            self::$pivots[$article_id][$price_type_id] = [
                'columnas' => array_merge($previo['columnas'], $columnas),
                'insertar' => $previo['insertar'] || (bool) $insertar_si_no_existe,
            ];

            return;
        }

        self::$pivots[$article_id][$price_type_id] = [
            'columnas' => $columnas,
            'insertar' => (bool) $insertar_si_no_existe,
        ];
    }

    /**
     * Registra lo que PriceChangeController::store() hubiera creado: la fila de price_changes y,
     * por cada lista del artículo, la fila de price_change_price_type con el final_price de esa
     * lista. Ese final_price es el que la lista TIENE DESPUÉS de las escrituras pendientes de
     * este lote (hoy store() lo lee de la base recién escrita), o el del pivot cargado si este
     * lote no la tocó.
     *
     * @param  \App\Models\Article $article
     * @param  int|null            $auth_user_id
     * @return void
     */
    public static function registrar_cambio_de_precio($article, $auth_user_id = null)
    {
        self::asegurar_activo(__FUNCTION__);

        if (is_null($auth_user_id)) {
            $auth_user_id = UserHelper::userId(false);
        }

        $article_id = (int) $article->id;

        $article->loadMissing('price_types');

        $pendientes = isset(self::$pivots[$article_id]) ? self::$pivots[$article_id] : [];

        $listas = [];
        $vistos = [];

        foreach ($article->price_types as $price_type) {

            $price_type_id = (int) $price_type->id;

            $vistos[$price_type_id] = true;

            $final_price = $price_type->pivot->final_price;

            if (
                isset($pendientes[$price_type_id])
                && array_key_exists('final_price', $pendientes[$price_type_id]['columnas'])
            ) {
                $final_price = $pendientes[$price_type_id]['columnas']['final_price'];
            }

            $listas[] = [$price_type_id, $final_price];
        }

        /* Las listas que este lote ata por primera vez todavía no están en la relación cargada. */
        foreach ($pendientes as $price_type_id => $pendiente) {

            if (isset($vistos[$price_type_id]) || !$pendiente['insertar']) {
                continue;
            }

            $listas[] = [
                $price_type_id,
                array_key_exists('final_price', $pendiente['columnas']) ? $pendiente['columnas']['final_price'] : null,
            ];
        }

        // El mismo instante que PriceChange::create() pondría en created_at/updated_at.
        $ahora = Carbon::now()->format('Y-m-d H:i:s');

        self::$cambios[] = [
            'article_id'  => $article_id,
            'cost'        => $article->cost,
            'price'       => $article->price,
            'final_price' => $article->final_price,
            'employee_id' => $auth_user_id,
            'created_at'  => $ahora,
            'listas'      => $listas,
        ];
    }

    /* ------------------------------------------------------------------------------------------
     * Escritura en bloque
     * ---------------------------------------------------------------------------------------- */

    /**
     * Pivots: INSERT multi-fila de los pares que no existen, UPDATE ... CASE de los que existen.
     *
     * @return array{pares:int,insertados:int,actualizados:int}
     */
    protected static function volcar_pivots()
    {
        $resumen = ['pares' => 0, 'insertados' => 0, 'actualizados' => 0];

        if (empty(self::$pivots)) {
            return $resumen;
        }

        $existentes = self::pares_existentes(array_keys(self::$pivots));

        $a_insertar   = [];
        $a_actualizar = [];

        foreach (self::$pivots as $article_id => $por_lista) {

            foreach ($por_lista as $price_type_id => $pendiente) {

                $resumen['pares']++;

                if (isset($existentes[$article_id][$price_type_id])) {
                    $a_actualizar[] = [$article_id, $price_type_id, $pendiente['columnas']];

                } else if ($pendiente['insertar']) {
                    $a_insertar[] = [$article_id, $price_type_id, $pendiente['columnas']];
                }

                /* Par inexistente y sin alta pendiente: updateExistingPivot() no hubiera escrito. */
            }
        }

        $resumen['insertados']   = self::insertar_pares($a_insertar);
        $resumen['actualizados'] = self::actualizar_pares($a_actualizar);

        return $resumen;
    }

    /**
     * Qué pares (artículo, lista) existen hoy en article_price_type, con un SELECT por cada 500
     * artículos. Se consulta la base y no la relación cargada a propósito: la tabla no tiene
     * índice único, así que una relación vieja (cargada antes de que asignar_price_types()
     * escribiera) produciría filas DUPLICADAS, que es lo peor que le puede pasar a este pivot.
     * Un SELECT cada 500 artículos no cuesta nada.
     *
     * @param  array $article_ids
     * @return array [article_id => [price_type_id => true]]
     */
    protected static function pares_existentes(array $article_ids)
    {
        $existentes = [];

        foreach (array_chunk($article_ids, self::FILAS_POR_INSERT) as $ids) {

            $filas = DB::table('article_price_type')
                        ->whereIn('article_id', $ids)
                        ->get(['article_id', 'price_type_id']);

            foreach ($filas as $fila) {
                $existentes[(int) $fila->article_id][(int) $fila->price_type_id] = true;
            }
        }

        return $existentes;
    }

    /**
     * INSERT multi-fila de los pares nuevos, agrupados por el conjunto de columnas que traen (así
     * cada fila lleva exactamente sus columnas y el resto queda con el default del esquema, como
     * con syncWithoutDetaching() + updateExistingPivot()). Sin created_at/updated_at: la relación
     * price_types() no tiene withTimestamps(), así que hoy tampoco se escriben.
     *
     * @param  array $pares  [[article_id, price_type_id, columnas], ...]
     * @return int
     */
    protected static function insertar_pares(array $pares)
    {
        if (empty($pares)) {
            return 0;
        }

        $grupos = [];

        foreach ($pares as $par) {

            $columnas = $par[2];
            ksort($columnas);

            $firma = implode(',', array_keys($columnas));

            $grupos[$firma][] = [$par[0], $par[1], $columnas];
        }

        $insertados = 0;

        foreach ($grupos as $firma => $filas) {

            $columnas_extra = $firma === '' ? [] : explode(',', $firma);
            $columnas_sql   = array_merge(['article_id', 'price_type_id'], $columnas_extra);

            $lista_de_columnas = [];
            foreach ($columnas_sql as $columna) {
                $lista_de_columnas[] = '`' . $columna . '`';
            }

            $tupla = '(' . implode(', ', array_fill(0, count($columnas_sql), '?')) . ')';

            foreach (array_chunk($filas, self::FILAS_POR_INSERT) as $tanda) {

                $tuplas   = [];
                $bindings = [];

                foreach ($tanda as $fila) {

                    $tuplas[]   = $tupla;
                    $bindings[] = $fila[0];
                    $bindings[] = $fila[1];

                    foreach ($columnas_extra as $columna) {
                        $bindings[] = $fila[2][$columna];
                    }
                }

                $sql = 'INSERT INTO `article_price_type` (' . implode(', ', $lista_de_columnas) . ') VALUES ' . implode(', ', $tuplas);

                DB::insert($sql, $bindings);

                $insertados += count($tanda);
            }
        }

        return $insertados;
    }

    /**
     * UPDATE ... CASE de los pares existentes, de a PARES_POR_UPDATE. Cada columna lleva un WHEN
     * por par que la trae y `ELSE columna` para los que no, y el WHERE acota a los pares de la
     * tanda: mismo patrón que ActualizarBBDD::asignar_price_types(). Los valores van como
     * bindings (los ids, validados como enteros, van en el SQL).
     *
     * @param  array $pares  [[article_id, price_type_id, columnas], ...]
     * @return int   Pares enviados a actualizar.
     */
    protected static function actualizar_pares(array $pares)
    {
        if (empty($pares)) {
            return 0;
        }

        $actualizados = 0;

        foreach (array_chunk($pares, self::PARES_POR_UPDATE) as $tanda) {

            $columnas = [];

            foreach ($tanda as $par) {
                foreach (array_keys($par[2]) as $columna) {
                    $columnas[$columna] = true;
                }
            }

            $sets     = [];
            $bindings = [];

            foreach (array_keys($columnas) as $columna) {

                $whens = [];

                foreach ($tanda as $par) {

                    if (!array_key_exists($columna, $par[2])) {
                        continue;
                    }

                    $whens[]    = 'WHEN `article_id` = ' . (int) $par[0] . ' AND `price_type_id` = ' . (int) $par[1] . ' THEN ?';
                    $bindings[] = $par[2][$columna];
                }

                $sets[] = '`' . $columna . '` = CASE ' . implode(' ', $whens) . ' ELSE `' . $columna . '` END';
            }

            $pares_sql = [];

            foreach ($tanda as $par) {
                $pares_sql[] = '(' . (int) $par[0] . ', ' . (int) $par[1] . ')';
            }

            $sql = 'UPDATE `article_price_type` SET ' . implode(', ', $sets)
                 . ' WHERE (`article_id`, `price_type_id`) IN (' . implode(', ', $pares_sql) . ')';

            DB::update($sql, $bindings);

            $actualizados += count($tanda);
        }

        return $actualizados;
    }

    /**
     * price_changes + price_change_price_type en bloque.
     *
     * @return array{cambios:int,filas_de_listas:int}
     */
    protected static function volcar_cambios_de_precio()
    {
        $resumen = ['cambios' => 0, 'filas_de_listas' => 0];

        if (empty(self::$cambios)) {
            return $resumen;
        }

        foreach (array_chunk(self::$cambios, self::FILAS_POR_INSERT) as $tanda) {

            $ids = self::insertar_cambios($tanda);

            $filas = [];

            foreach ($tanda as $i => $cambio) {
                foreach ($cambio['listas'] as $lista) {
                    $filas[] = [$ids[$i], $lista[0], $lista[1]];
                }
            }

            foreach (array_chunk($filas, self::FILAS_POR_INSERT) as $sub_tanda) {

                $tuplas   = [];
                $bindings = [];

                foreach ($sub_tanda as $fila) {
                    $tuplas[]   = '(?, ?, ?)';
                    $bindings[] = $fila[0];
                    $bindings[] = $fila[1];
                    $bindings[] = $fila[2];
                }

                DB::insert(
                    'INSERT INTO `price_change_price_type` (`price_change_id`, `price_type_id`, `final_price`) VALUES ' . implode(', ', $tuplas),
                    $bindings
                );
            }

            $resumen['cambios']         += count($tanda);
            $resumen['filas_de_listas'] += count($filas);
        }

        return $resumen;
    }

    /**
     * INSERT multi-fila en price_changes y resolución de los ids generados, en el mismo orden que
     * la tanda.
     *
     * Los ids de un INSERT multi-fila de InnoDB son consecutivos a partir de LAST_INSERT_ID()
     * para un "simple insert" (se conoce la cantidad de filas de antemano), también con
     * innodb_autoinc_lock_mode = 2. Igual se confirma contra la base, y si no coincidiera se
     * resuelven por article_id de ese id en adelante.
     *
     * @param  array $tanda
     * @return array  ids, paralelos a $tanda
     */
    protected static function insertar_cambios(array $tanda)
    {
        $tuplas   = [];
        $bindings = [];

        foreach ($tanda as $cambio) {
            $tuplas[]   = '(?, ?, ?, ?, ?, ?, ?)';
            $bindings[] = $cambio['article_id'];
            $bindings[] = $cambio['cost'];
            $bindings[] = $cambio['price'];
            $bindings[] = $cambio['final_price'];
            $bindings[] = $cambio['employee_id'];
            $bindings[] = $cambio['created_at'];
            $bindings[] = $cambio['created_at'];
        }

        DB::insert(
            'INSERT INTO `price_changes` (`article_id`, `cost`, `price`, `final_price`, `employee_id`, `created_at`, `updated_at`) VALUES ' . implode(', ', $tuplas),
            $bindings
        );

        $primer_id = (int) DB::getPdo()->lastInsertId();
        $cantidad  = count($tanda);
        $ultimo_id = $primer_id + $cantidad - 1;

        $filas = DB::table('price_changes')
                    ->whereBetween('id', [$primer_id, $ultimo_id])
                    ->orderBy('id')
                    ->get(['id', 'article_id']);

        $consecutivos = count($filas) === $cantidad;

        if ($consecutivos) {
            foreach ($filas as $i => $fila) {
                if ((int) $fila->id !== $primer_id + $i || (int) $fila->article_id !== (int) $tanda[$i]['article_id']) {
                    $consecutivos = false;
                    break;
                }
            }
        }

        if ($consecutivos) {
            return range($primer_id, $ultimo_id);
        }

        /* Los ids no salieron consecutivos: se resuelven por artículo, del primer id en adelante. */
        $filas = DB::table('price_changes')
                    ->where('id', '>=', $primer_id)
                    ->whereIn('article_id', array_column($tanda, 'article_id'))
                    ->orderBy('id')
                    ->get(['id', 'article_id']);

        $disponibles = [];

        foreach ($filas as $fila) {
            $disponibles[(int) $fila->article_id][] = (int) $fila->id;
        }

        $ids = [];

        foreach ($tanda as $cambio) {

            $article_id = (int) $cambio['article_id'];

            if (empty($disponibles[$article_id])) {
                throw new \RuntimeException('PreciosEnLote: no se pudo resolver el id del price_change del artículo ' . $article_id . '.');
            }

            $ids[] = array_shift($disponibles[$article_id]);
        }

        return $ids;
    }

    /* ------------------------------------------------------------------------------------------
     * Internos
     * ---------------------------------------------------------------------------------------- */

    /**
     * La fila que deja syncWithoutDetaching() para un par nuevo, antes de cualquier update: los
     * defaults del esquema de article_price_type (migración 2024_09_05_101805).
     *
     * @param  int $article_id
     * @param  int $price_type_id
     * @return array
     */
    protected static function fila_del_pivot_por_defecto($article_id, $price_type_id)
    {
        return [
            'article_id'                     => $article_id,
            'price_type_id'                  => $price_type_id,
            'percentage'                     => null,
            'price'                          => null,
            'final_price'                    => null,
            'previus_final_price'            => null,
            'incluir_en_excel_para_clientes' => 0,
            'setear_precio_final'            => 0,
            'precio_luego_de_recargos'       => null,
            'monto_ganancia'                 => null,
        ];
    }

    /**
     * @param  string $metodo
     * @return void
     */
    protected static function asegurar_activo($metodo)
    {
        if (!self::$activo) {
            throw new \LogicException('PreciosEnLote::' . $metodo . '() se llamó sin el modo lote encendido.');
        }
    }

    /**
     * Apaga el modo y vacía lo recolectado.
     *
     * @return void
     */
    protected static function reiniciar()
    {
        self::$activo       = false;
        self::$user         = null;
        self::$auth_user_id = null;
        self::$articulos    = [];
        self::$pivots       = [];
        self::$cambios      = [];
    }
}
