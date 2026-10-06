<?php

namespace App\Http\Controllers\Helpers\address;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Stock\SetStockPorDeposito;
use App\Http\Controllers\Stock\SetStockResultante;
use App\Models\Article;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Criterio ÚNICO de "¿qué es una fila fantasma de sucursal borrada, qué stock queda al sacarla y
 * cómo se sanea un artículo?" (misión sanear-stock-de-sucursales-borradas, 6/10/2026).
 *
 * Lo usan los tres: `stock:sanear-sucursales-borradas --ver`, `--aplicar` y los tests. Es a
 * propósito UN solo código: lo que `--ver` le promete a quien lo corre es, por construcción, lo que
 * `--aplicar` hace (misma función `analizar()` para medir y para escribir), igual que
 * `CostoDeLineaDeVentaHelper` hace con el saneo de costo de línea.
 *
 * ─── Qué es una fila fantasma ─────────────────────────────────────────────────────────────────
 *
 * Cuando se borra una sucursal (`addresses`) y después entra una venta, una anulación o una nota de
 * crédito con el `address_id` viejo (la cookie de la SPA de tres años, `users.address_id` colgado),
 * el motor de stock REABRE una fila de pivot para la sucursal muerta: `CheckFromAddress`,
 * `CheckToAddress` y `CheckVariants` hacen `attach()` cuando no encuentran la fila. Esa fila es el
 * fantasma. Cada venta contra la sucursal muerta abre una fila NUEVA del mismo par (`attach` no ve
 * la anterior y la tabla no tiene índice único), así que varias filas del mismo par son lo normal.
 *
 * Una fila de `address_article` / `address_article_variant` es fantasma cuando su `address_id` NO
 * existe en `addresses` (el id 0 cuenta como inexistente: nunca hubo una sucursal 0). Nada más:
 *
 *  - 🔴 un DOMICILIO DE COMPRADOR (`addresses.buyer_id` no nulo) EXISTE, así que no es fantasma. Los
 *    pedidos de la tienda con envío le abren filas desde siempre y "Poner stock en 0" las limpia
 *    por otro camino. Tocarlas rompería el stock de los pedidos de la tienda.
 *  - una fila en una sucursal que existe pero es de OTRO dueño tampoco es fantasma.
 *
 * ─── Por qué NO se detecta con `Article::addresses()` ─────────────────────────────────────────
 *
 * 🔴 `Article::addresses()` y `ArticleVariant::addresses()` son `belongsToMany`, o sea un INNER
 * JOIN a `addresses`: justamente NO ven las filas cuya sucursal ya no existe. Detectar con la
 * relación (o con `count($article->addresses)`) daría siempre "no hay fantasmas". Y es esa misma
 * ceguera la que causa el daño: `ArticleHelper::setArticleStockFromAddresses()` escribe
 * `articles.stock = SUM(address_article.amount)` CRUDO (sin join) y sí suma los fantasmas, así que
 * el stock global queda distinto de la suma de las sucursales que el usuario ve. Por eso acá se
 * detecta con un LEFT JOIN + `IS NULL` contra `addresses.id`.
 *
 * ─── Las tres clases de artículo (D5 del plan) ────────────────────────────────────────────────
 *
 *  - `recalcular`: tiene variantes, o al menos una fila de `address_article` en una sucursal que
 *    existe. Se borran los fantasmas y se llama a `ArticleHelper::setArticleStockFromAddresses()`
 *    —la función del sistema, no una fórmula copiada—, que deja `articles.stock` como lo dejaría
 *    el próximo movimiento. Si el stock global cambia, queda UN movimiento que lo explica.
 *  - `solo_fantasmas`: sin variantes y sin ninguna fila viva. Se borran las filas pero
 *    `articles.stock` NO se toca: un artículo sin sucursales vivas lleva el stock global
 *    (`CheckGlobalStock` solo mueve `articles.stock` cuando no hay sucursales ni variantes, y
 *    `SetStockPorDeposito::armar()` lo trata igual), así que ese número no es la suma de nada.
 *  - `no_recalculable`: tiene variantes y alguna fila viva de variante en una sucursal que NO es del
 *    dueño. `setArticleStockFromAddresses()` hace `$addresses[$address_id] += ...` sobre un arreglo
 *    que solo trae las sucursales del dueño (`get_addresses()`), y ahí tira `Undefined index`. No se
 *    toca NADA del artículo (ni sus fantasmas) y se informa: forzarlo sería inventar un stock.
 *    (Por la misma razón, y solo por si el esquema cambia, un artículo sin dueño —`user_id` NULL—
 *    también es `no_recalculable`: sin dueño la función del sistema caería al usuario de la sesión.)
 *
 * ─── Concurrencia ─────────────────────────────────────────────────────────────────────────────
 *
 * El saneo corre con el sistema en vivo (ventas entrando). Por eso:
 *
 *  - 🔴 la unidad de trabajo es UN artículo y su transacción dura milisegundos. Una transacción
 *    gigante mantendría candados sobre miles de filas y frenaría las ventas del cliente; además un
 *    corte a mitad dejaría todo o nada. Con una por artículo, una corrida cortada deja unos
 *    artículos terminados y los demás intactos (todavía detectables): volver a correr la continúa.
 *  - 🔴 el ORDEN DE BLOQUEO es el del motor: `address_article_variant` → `article_variants` →
 *    `address_article` → `articles`. Un movimiento de variante toca los pivots de variante, luego
 *    la variante, luego el pivot del artículo y por último `articles`; uno de artículo sin variantes
 *    toca `address_article` y `articles`. Si el saneo tomara los candados en otro orden, un
 *    movimiento y el saneo podrían esperarse mutuamente (deadlock). Cada lectura con candado es una
 *    consulta simple por tabla, NUNCA un join: un join bloquea las tablas en el orden que elija el
 *    optimizador, no en el nuestro.
 *  - 🔴 la PRIMERA sentencia de la transacción es una lectura con candado. En REPEATABLE READ la
 *    foto de lectura (snapshot) nace en la primera lectura SIN candado; si naciera antes de los
 *    candados, las lecturas siguientes (`SetStockPorDeposito::foto()`, las de la función del
 *    sistema) verían datos anteriores a lo que los candados protegen. Por eso los ids de las
 *    variantes se leen FUERA de la transacción (ver `sanear_articulo()`).
 *  - dentro de la transacción se vuelve a leer todo: si otra corrida o un movimiento ya limpió el
 *    artículo, se saltea (`ya_limpio`). Eso da la idempotencia.
 *
 * ─── Por qué el dueño sale de `articles.user_id` ──────────────────────────────────────────────
 *
 * 🔴 NUNCA de `config('app.USER_ID')` ni de `UserHelper::userId()`. En una base compartida (varios
 * comercios en la misma base) o con un `config` apuntando a otro, el artículo de un dueño se
 * recalcularía con las sucursales de otro: `get_addresses()` devolvería las equivocadas y un
 * artículo con variantes reventaría con `Undefined index`. La función del sistema recibe el
 * `$user_id` explícito, el del artículo.
 *
 * ─── Por qué el movimiento NO se crea con `StockMovementController::crear()` ──────────────────
 *
 * 🔴 `crear()` es el camino de un movimiento REAL: aplica `check_unidades_individuales`, mueve el
 * stock de una sucursal (`SetArticleStock`), toma el usuario de la sesión (`UserHelper::userId()`,
 * que en una consola devuelve `config('app.USER_ID')`: el dueño equivocado) y encola la
 * sincronización con Tienda Nube y Mercado Libre. Acá no hay nada que mover —el pivot ya quedó bien—
 * y un barrido masivo encolaría cientos de sincronizaciones. El movimiento se crea con el modelo y
 * se completa con los MISMOS helpers que usa el motor (`SetStockPorDeposito` y `SetStockResultante`),
 * para que la fila quede idéntica en forma a una del motor.
 *
 * ─── Lo que este criterio NO hace (límites declarados) ────────────────────────────────────────
 *
 *  - No corrige el reuso de ids: si una sucursal NUEVA reutilizara el id de una borrada, sus filas
 *    ya no serían fantasma (MySQL 8 y MariaDB 10.2+ no reutilizan ids tras un reinicio).
 *  - No repara los otros huérfanos de una sucursal borrada (`article_ubications`,
 *    `stock_suggestion_articles`, ...).
 *  - Con variantes, `address_article` del artículo se RECONSTRUYE (como hace el motor en cada
 *    movimiento de variante), así que se pierden los `stock_min`/`stock_max` por sucursal de esas
 *    filas. El respaldo los guarda.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class FilasFantasmaDeSucursalHelper
{
    /** Tiene variantes o alguna fila viva: se borran los fantasmas y se recalcula `articles.stock`. */
    const CLASE_RECALCULAR = 'recalcular';

    /** Sin variantes y sin filas vivas: se borran los fantasmas, el stock global no se toca. */
    const CLASE_SOLO_FANTASMAS = 'solo_fantasmas';

    /** El motor tiraría `Undefined index`: no se toca nada del artículo. */
    const CLASE_NO_RECALCULABLE = 'no_recalculable';

    /** Motivo de `no_recalculable`: una variante tiene una fila viva en una sucursal que no es del dueño. */
    const MOTIVO_VARIANTE_EN_SUCURSAL_AJENA = 'variante_con_fila_en_sucursal_ajena';

    /** Motivo de `no_recalculable` si el artículo no tiene dueño (`articles.user_id` NULL): no hay con qué sucursales recalcular. */
    const MOTIVO_ARTICULO_SIN_DUENO = 'articulo_sin_dueno';

    /** Motivo de `saltado` cuando el artículo desapareció entre la medición y el saneo. */
    const MOTIVO_ARTICULO_INEXISTENTE = 'articulo_inexistente';

    /** Resultado de `sanear_articulo()`: se borraron los fantasmas (y se recalculó si correspondía). */
    const RESULTADO_SANEADO = 'saneado';

    /** Resultado de `sanear_articulo()`: bajo el candado ya no había fantasmas (otro proceso los sacó). */
    const RESULTADO_YA_LIMPIO = 'ya_limpio';

    /** Resultado de `sanear_articulo()`: no se tocó nada (ver `motivo`). */
    const RESULTADO_SALTADO = 'saltado';

    /**
     * Concepto con el que se etiqueta el movimiento. Se resuelve POR NOMBRE, como hace
     * `SetConcepto` en todo el sistema: el id cambia de una base a otra (es el 11 en la mayoría y
     * en 3DTisk, pero no hay garantía).
     */
    const CONCEPTO = 'Actualizacion de deposito';

    /** Observación del movimiento, igual a la de la limpieza manual de 3DTisk del 5/10/2026. */
    const OBSERVACION = 'Baja de sucursal eliminada';

    /** Máximo de elementos por `IN (...)`: por encima de esto las consultas se parten en tandas. */
    const TOPE_IN = 1000;

    /**
     * Diferencia mínima de stock (en unidades) para considerarla un cambio. `articles.stock` es
     * `decimal(12,2)`: dos stocks distintos difieren en al menos 0,01, así que 0,005 separa "cambió"
     * de "es el mismo número con ruido de coma flotante".
     */
    const TOLERANCIA = 0.005;

    /** Intentos de la transacción de un artículo ante un deadlock (`DB::transaction`). */
    const INTENTOS = 3;

    // ═════════════════════════════════════════════════════════════════════════════════════════
    //  DETECCIÓN (solo lectura)
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * Ids de los artículos que tienen al menos una fila fantasma, en cualquiera de los dos pivots.
     *
     * Devuelve SOLO ids (memoria trivial aunque haya cientos de miles de filas): el análisis de
     * cada artículo se hace después, por tandas, con `analizar()`.
     *
     * El INNER JOIN con `articles` deja afuera los pivots de artículos que ya no existen, y el
     * `user_id` no nulo los de un artículo sin dueño (esas filas no tienen dueño: se cuentan aparte
     * en `filas_sin_dueno()` y no se tocan). Incluye los artículos de la papelera: es SQL crudo, sin
     * el scope de SoftDeletes, a propósito.
     *
     * @param  int|null  $user_id      Dueño (`articles.user_id`) al que se acota, o null para todos.
     * @param  int|null  $articulo_id  Artículo al que se acota, o null para todos.
     * @return int[]  Ids ordenados de menor a mayor, sin repetir.
     */
    public static function ids_de_articulos_afectados($user_id = null, $articulo_id = null)
    {
        // Mapa id => true: junta los dos pivots sin repetir sin pagar un array_unique() sobre
        // cientos de miles de elementos.
        $ids = [];

        // Pivot del artículo. LEFT JOIN + IS NULL contra `addresses.id` (anti-join): ver la nota
        // "Por qué NO se detecta con Article::addresses()" del encabezado.
        $query = DB::table('address_article as aa')
            ->join('articles as a', 'a.id', '=', 'aa.article_id')
            ->leftJoin('addresses as ad', 'ad.id', '=', 'aa.address_id')
            ->whereNull('ad.id')
            ->whereNotNull('a.user_id');

        self::acotar($query, 'a.user_id', 'aa.article_id', $user_id, $articulo_id);

        foreach ($query->distinct()->pluck('aa.article_id') as $id) {
            $ids[(int) $id] = true;
        }

        // Pivot de la variante: el artículo se llega por `article_variants`. Una fila de una
        // variante que ya no existe no tiene artículo ni dueño y queda afuera (ver filas_sin_dueno).
        $query = DB::table('address_article_variant as aav')
            ->join('article_variants as av', 'av.id', '=', 'aav.article_variant_id')
            ->join('articles as a', 'a.id', '=', 'av.article_id')
            ->leftJoin('addresses as ad', 'ad.id', '=', 'aav.address_id')
            ->whereNull('ad.id')
            ->whereNotNull('a.user_id');

        self::acotar($query, 'a.user_id', 'av.article_id', $user_id, $articulo_id);

        foreach ($query->distinct()->pluck('av.article_id') as $id) {
            $ids[(int) $id] = true;
        }

        $ids = array_keys($ids);

        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /**
     * Filas fantasma que NO tienen dueño: el artículo (o la variante) al que pertenecen ya no
     * existe. No hay con qué sucursales ni con qué dueño recalcular nada, así que NO se tocan: solo
     * se cuentan para que el informe no las esconda.
     *
     * Solo tiene sentido sin acotar por dueño ni por artículo: una fila sin dueño no pertenece a
     * ningún dueño, y el comando no la muestra cuando la corrida está acotada.
     *
     * @return array ['articulo' => ['filas' => int, 'unidades' => float], 'variante' => ['filas' => int, 'unidades' => float]]
     */
    public static function filas_sin_dueno()
    {
        // address_article: el artículo no existe (LEFT JOIN sin match) o, por un dato roto, no
        // tiene user_id. `articles.user_id` es NOT NULL en el esquema actual: la segunda condición
        // es defensiva y no cuesta nada.
        $articulo = DB::table('address_article as aa')
            ->leftJoin('addresses as ad', 'ad.id', '=', 'aa.address_id')
            ->leftJoin('articles as a', 'a.id', '=', 'aa.article_id')
            ->whereNull('ad.id')
            ->where(function ($q) {
                $q->whereNull('a.id')->orWhereNull('a.user_id');
            })
            ->selectRaw('COUNT(*) as filas, COALESCE(SUM(aa.amount), 0) as unidades')
            ->first();

        // address_article_variant: la variante no existe, o su artículo no existe.
        $variante = DB::table('address_article_variant as aav')
            ->leftJoin('addresses as ad', 'ad.id', '=', 'aav.address_id')
            ->leftJoin('article_variants as av', 'av.id', '=', 'aav.article_variant_id')
            ->leftJoin('articles as a', 'a.id', '=', 'av.article_id')
            ->whereNull('ad.id')
            ->where(function ($q) {
                $q->whereNull('a.id')->orWhereNull('a.user_id');
            })
            ->selectRaw('COUNT(*) as filas, COALESCE(SUM(aav.amount), 0) as unidades')
            ->first();

        return [
            'articulo' => ['filas' => (int) $articulo->filas, 'unidades' => round((float) $articulo->unidades, 2)],
            'variante' => ['filas' => (int) $variante->filas, 'unidades' => round((float) $variante->unidades, 2)],
        ];
    }

    /**
     * Filas en DOMICILIOS DE COMPRADOR (`addresses.buyer_id` no nulo). Existen, no son fantasma y
     * no se tocan: se informan para que quien lee el reporte vea que el comando las conoce y las
     * deja en paz (ver "Qué es una fila fantasma" en el encabezado).
     *
     * Devuelve null si la tabla `addresses` no tiene la columna `buyer_id` (base muy vieja): sin la
     * columna no hay domicilios de comprador que informar.
     *
     * @param  int|null  $user_id  Dueño (`articles.user_id`) al que se acota, o null para todos.
     * @return array|null  ['articulo' => ['filas', 'unidades'], 'variante' => ['filas', 'unidades']]
     */
    public static function filas_en_domicilios_de_comprador($user_id = null)
    {
        if (!Schema::hasColumn('addresses', 'buyer_id')) {
            return null;
        }

        // Pivot del artículo.
        $articulo = DB::table('address_article as aa')
            ->join('addresses as ad', 'ad.id', '=', 'aa.address_id')
            ->whereNotNull('ad.buyer_id');

        // Pivot de la variante.
        $variante = DB::table('address_article_variant as aav')
            ->join('addresses as ad', 'ad.id', '=', 'aav.address_id')
            ->whereNotNull('ad.buyer_id');

        if (!is_null($user_id)) {
            $articulo->join('articles as a', 'a.id', '=', 'aa.article_id')->where('a.user_id', (int) $user_id);

            $variante->join('article_variants as av', 'av.id', '=', 'aav.article_variant_id')
                ->join('articles as a', 'a.id', '=', 'av.article_id')
                ->where('a.user_id', (int) $user_id);
        }

        $articulo = $articulo->selectRaw('COUNT(*) as filas, COALESCE(SUM(aa.amount), 0) as unidades')->first();
        $variante = $variante->selectRaw('COUNT(*) as filas, COALESCE(SUM(aav.amount), 0) as unidades')->first();

        return [
            'articulo' => ['filas' => (int) $articulo->filas, 'unidades' => round((float) $articulo->unidades, 2)],
            'variante' => ['filas' => (int) $variante->filas, 'unidades' => round((float) $variante->unidades, 2)],
        ];
    }

    /**
     * Usuarios cuyo `users.address_id` apunta a una sucursal que ya no existe (la "cookie de tres
     * años": cada venta que ese usuario hace en Vender manda el id muerto y el motor reabre el
     * fantasma). SOLO se reporta: el comando NUNCA escribe en `users`.
     *
     * 🔴 Por qué no se toca: corregirlo es una decisión de producto (¿a qué sucursal lo mandás?), y
     * el dueño o el empleado lo resuelve en su perfil. Lo que sí hace el saneo es avisar quiénes
     * son, porque mientras el `address_id` siga colgado los fantasmas vuelven a nacer.
     *
     * El `address_id` 0 y NULL no cuentan: son "sin sucursal elegida", no un puntero roto.
     *
     * @param  int|null  $user_id  Dueño (`COALESCE(owner_id, id)`) al que se acota, o null para todos.
     * @return array  dueño => ['usuarios' => int[], 'sucursales' => int[]] (ids ordenados, sin repetir)
     */
    public static function usuarios_con_sucursal_colgada($user_id = null)
    {
        $query = DB::table('users as u')
            ->leftJoin('addresses as ad', 'ad.id', '=', 'u.address_id')
            ->whereNotNull('u.address_id')
            ->where('u.address_id', '<>', 0)
            ->whereNull('ad.id');

        // El dueño de un empleado es su owner_id; el de un dueño, él mismo. Es el mismo criterio
        // que `UserHelper::user()` y que `articles.user_id`.
        if (!is_null($user_id)) {
            $query->whereRaw('COALESCE(u.owner_id, u.id) = ?', [(int) $user_id]);
        }

        $filas = $query->orderBy('u.id')->get(['u.id', 'u.owner_id', 'u.address_id']);

        // dueño => ['usuarios' => [ids], 'sucursales' => [address_id => true]]
        $por_dueno = [];

        foreach ($filas as $fila) {
            $dueno = is_null($fila->owner_id) ? (int) $fila->id : (int) $fila->owner_id;

            if (!isset($por_dueno[$dueno])) {
                $por_dueno[$dueno] = ['usuarios' => [], 'sucursales' => []];
            }

            $por_dueno[$dueno]['usuarios'][] = (int) $fila->id;
            $por_dueno[$dueno]['sucursales'][(int) $fila->address_id] = true;
        }

        ksort($por_dueno);

        foreach ($por_dueno as $dueno => $datos) {
            $sucursales = array_keys($datos['sucursales']);

            sort($sucursales, SORT_NUMERIC);

            $por_dueno[$dueno]['sucursales'] = $sucursales;
        }

        return $por_dueno;
    }

    /**
     * Ids de las variantes de unos artículos (lectura simple, sin candado).
     *
     * `sanear_articulo()` lo llama FUERA de su transacción: ver "la PRIMERA sentencia de la
     * transacción es una lectura con candado" en el encabezado.
     *
     * @param  int[]  $article_ids
     * @return int[]
     */
    public static function ids_de_variantes(array $article_ids)
    {
        $ids = [];

        foreach (array_chunk(array_values($article_ids), self::TOPE_IN) as $tanda) {
            foreach (DB::table('article_variants')->whereIn('article_id', $tanda)->pluck('id') as $id) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    //  ANÁLISIS DE UNA TANDA DE ARTÍCULOS
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * Analiza artículos: qué filas fantasma tiene cada uno, de qué clase es (ver encabezado), qué
     * stock quedaría al sacarlas y cuánto es el desfase con el que está hoy.
     *
     * Es la ÚNICA función que mira el estado: `--ver` la llama por tandas sin candados;
     * `sanear_articulo()` la llama de a un artículo, con candados y dentro de su transacción.
     *
     * Devuelve una entrada por cada artículo que EXISTE (los ids inexistentes no aparecen). Un
     * artículo sin fantasmas aparece con `clase` null: así quien escribe puede distinguir "ya está
     * limpio" de "desapareció".
     *
     * Cada entrada trae:
     *  - `article_id`, `user_id` (dueño = `articles.user_id`), `en_papelera`.
     *  - `stock_actual` (float|null) y `stock_crudo` (el valor tal cual lo devolvió la base).
     *  - `tiene_variantes`, `filas_vivas_articulo`.
     *  - `clase` (null | recalcular | solo_fantasmas | no_recalculable) y `motivo` (solo no_recalculable).
     *  - `filas_articulo` (TODAS las filas del pivot, para el respaldo), `filas_fantasma_articulo` y
     *    `filas_fantasma_variante` (las filas completas que se borran).
     *  - `unidades_fantasma_articulo` / `unidades_fantasma_variante`: suma CON SIGNO de los `amount`
     *    de esas filas (NULL cuenta 0).
     *  - `sucursales_muertas`: address_id => ['filas' => n, 'unidades' => x], de las dos tablas.
     *  - `stock_proyectado`: lo que dejaría `setArticleStockFromAddresses()`, calculado SIN escribir
     *    (null si la clase no recalcula).
     *  - `desfase`: `stock_actual` (NULL→0) − `stock_proyectado`. Es lo que sobra (o falta) hoy.
     *  - `variantes`: id => ['id', 'stock', 'updated_at', 'filas_vivas', 'suma_viva', 'aporte'].
     *
     * @param  int[]      $ids                Ids de artículos. Se parten internamente en tandas de TOPE_IN.
     * @param  bool       $bloquear           true: las lecturas llevan candado (`FOR UPDATE`) en el orden del
     *                                        motor. Solo tiene sentido dentro de una transacción.
     * @param  int[]|null $variantes_previas  Con `$bloquear`: ids de las variantes leídos ANTES de abrir la
     *                                        transacción. null = leerlos acá (válido solo si el llamador no
     *                                        le importa el snapshot, como los tests).
     * @return array  article_id => análisis
     */
    public static function analizar(array $ids, $bloquear = false, $variantes_previas = null)
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        // article_id => análisis. El operador + (y no array_merge) conserva los ids como claves.
        $resultado = [];

        foreach (array_chunk($ids, self::TOPE_IN) as $tanda) {
            $resultado = $resultado + self::analizar_tanda($tanda, (bool) $bloquear, $variantes_previas);
        }

        return $resultado;
    }

    /**
     * Lee el estado de una tanda de artículos (≤ TOPE_IN) y arma el análisis de cada uno.
     *
     * El orden de las lecturas CON candado es el del motor (D4): pivots de variante → variantes →
     * pivot del artículo → artículos. La existencia de las sucursales se consulta al final y SIN
     * candado: no hay nada que proteger ahí (un id de sucursal no se reutiliza).
     *
     * @param  int[]       $ids
     * @param  bool        $bloquear
     * @param  int[]|null  $variantes_previas
     * @return array
     */
    private static function analizar_tanda(array $ids, $bloquear, $variantes_previas)
    {
        // Variantes de los artículos de la tanda y todas las filas de sus pivots.
        if ($bloquear) {
            // Ids de variante que el llamador leyó antes de abrir la transacción (o que se leen
            // acá, sin la garantía del snapshot, si no los pasó).
            $ids_previos = is_array($variantes_previas)
                ? array_map('intval', $variantes_previas)
                : self::ids_de_variantes($ids);

            // 1° candado: los pivots de variante.
            $filas_variante = self::leer_filas_de_variante($ids_previos, true);

            // 2° candado: las variantes. Se leen por artículo (no por id) para ver también una
            // variante creada después de la lectura previa.
            $variantes = DB::table('article_variants')
                ->whereIn('article_id', $ids)
                ->lockForUpdate()
                ->get(['id', 'article_id', 'stock', 'updated_at']);

            // Variante nacida entre la lectura previa y el candado: no puede tener todavía filas de
            // pivot propias, pero se las lee igual (con candado) para no dar un análisis incompleto.
            $conocidas = array_flip($ids_previos);
            $nuevas = [];

            foreach ($variantes as $variante) {
                if (!isset($conocidas[(int) $variante->id])) {
                    $nuevas[] = (int) $variante->id;
                }
            }

            if (count($nuevas) > 0) {
                $filas_variante = array_merge($filas_variante, self::leer_filas_de_variante($nuevas, true));
            }
        } else {
            $variantes = DB::table('article_variants')
                ->whereIn('article_id', $ids)
                ->get(['id', 'article_id', 'stock', 'updated_at']);

            $ids_de_variantes = [];

            foreach ($variantes as $variante) {
                $ids_de_variantes[] = (int) $variante->id;
            }

            $filas_variante = self::leer_filas_de_variante($ids_de_variantes, false);
        }

        // 3° candado: el pivot del artículo.
        $query = DB::table('address_article')->whereIn('article_id', $ids);

        if ($bloquear) {
            $query->lockForUpdate();
        }

        $filas_articulo = $query->get(['id', 'article_id', 'address_id', 'amount', 'created_at', 'updated_at', 'stock_min', 'stock_max']);

        // 4° candado: los artículos. SQL crudo, sin el scope de SoftDeletes: la papelera cuenta.
        $query = DB::table('articles')->whereIn('id', $ids);

        if ($bloquear) {
            $query->lockForUpdate();
        }

        $articulos = $query->get(['id', 'user_id', 'stock', 'deleted_at']);

        // Sucursales que existen entre todas las que nombran las filas leídas: address_id => user_id|null.
        $address_ids = [];

        foreach ($filas_articulo as $fila) {
            $address_ids[(int) $fila->address_id] = true;
        }

        foreach ($filas_variante as $fila) {
            $address_ids[(int) $fila->address_id] = true;
        }

        $sucursales = self::sucursales_existentes(array_keys($address_ids));

        // Agrupados por artículo / variante para armar cada análisis sin volver a la base.
        $filas_por_articulo = [];

        foreach ($filas_articulo as $fila) {
            $filas_por_articulo[(int) $fila->article_id][] = (array) $fila;
        }

        $variantes_por_articulo = [];

        foreach ($variantes as $variante) {
            $variantes_por_articulo[(int) $variante->article_id][(int) $variante->id] = [
                'id' => (int) $variante->id,
                'stock' => $variante->stock,
                'updated_at' => $variante->updated_at,
            ];
        }

        $filas_por_variante = [];

        foreach ($filas_variante as $fila) {
            $filas_por_variante[(int) $fila->article_variant_id][] = (array) $fila;
        }

        $resultado = [];

        foreach ($articulos as $articulo) {
            $id = (int) $articulo->id;

            $resultado[$id] = self::analizar_articulo(
                $articulo,
                isset($filas_por_articulo[$id]) ? $filas_por_articulo[$id] : [],
                isset($variantes_por_articulo[$id]) ? $variantes_por_articulo[$id] : [],
                $filas_por_variante,
                $sucursales
            );
        }

        return $resultado;
    }

    /**
     * Todas las filas de `address_article_variant` de unas variantes.
     *
     * @param  int[]  $ids_de_variantes
     * @param  bool   $bloquear  true: con `FOR UPDATE`.
     * @return array  Lista de filas (stdClass).
     */
    private static function leer_filas_de_variante(array $ids_de_variantes, $bloquear)
    {
        $filas = [];

        foreach (array_chunk($ids_de_variantes, self::TOPE_IN) as $tanda) {
            $query = DB::table('address_article_variant')->whereIn('article_variant_id', $tanda);

            if ($bloquear) {
                $query->lockForUpdate();
            }

            foreach ($query->get(['id', 'address_id', 'article_variant_id', 'amount', 'on_display', 'created_at', 'updated_at']) as $fila) {
                $filas[] = $fila;
            }
        }

        return $filas;
    }

    /**
     * De unos `address_id`, cuáles existen en `addresses` y de quién son.
     *
     * Es la ÚNICA definición de "existe" del criterio: una sucursal existe si hay una fila en
     * `addresses` con ese id, sea de un dueño, de otro o de un comprador (`buyer_id`). El que no
     * está en el mapa devuelto es un fantasma.
     *
     * @param  int[]  $address_ids
     * @return array  address_id => user_id|null (int|null). Que la clave exista es lo que dice "existe".
     */
    private static function sucursales_existentes(array $address_ids)
    {
        $existentes = [];

        foreach (array_chunk($address_ids, self::TOPE_IN) as $tanda) {
            foreach (DB::table('addresses')->whereIn('id', $tanda)->get(['id', 'user_id']) as $sucursal) {
                $existentes[(int) $sucursal->id] = is_null($sucursal->user_id) ? null : (int) $sucursal->user_id;
            }
        }

        return $existentes;
    }

    /**
     * El análisis de UN artículo a partir de las filas ya leídas (no toca la base).
     *
     * @param  object  $articulo               Fila de `articles` (id, user_id, stock, deleted_at).
     * @param  array   $filas                  Filas de `address_article` del artículo (arreglos).
     * @param  array   $variantes              id => ['id', 'stock', 'updated_at'] de sus variantes.
     * @param  array   $filas_por_variante     article_variant_id => filas de `address_article_variant`.
     * @param  array   $sucursales             address_id => user_id|null de las sucursales que existen.
     * @return array   Ver `analizar()`.
     */
    private static function analizar_articulo($articulo, array $filas, array $variantes, array $filas_por_variante, array $sucursales)
    {
        $id = (int) $articulo->id;

        // Dueño = articles.user_id (ver "Por qué el dueño sale de articles.user_id").
        $dueno = is_null($articulo->user_id) ? null : (int) $articulo->user_id;

        $stock_actual = is_null($articulo->stock) ? null : (float) $articulo->stock;

        // Filas del pivot del artículo, partidas en vivas (la sucursal existe) y fantasma.
        $vivas = [];
        $fantasmas = [];
        $unidades_articulo = 0.0;

        // address_id muerto => ['filas' => n, 'unidades' => x], sumando las dos tablas.
        $muertas = [];

        foreach ($filas as $fila) {
            $address_id = (int) $fila['address_id'];

            if (array_key_exists($address_id, $sucursales)) {
                $vivas[] = $fila;

                continue;
            }

            $fantasmas[] = $fila;
            $unidades_articulo += self::numero($fila['amount']);
            self::anotar_sucursal_muerta($muertas, $address_id, $fila['amount']);
        }

        // Variantes: fantasmas propios, y el aporte de cada una al stock proyectado.
        $fantasmas_variante = [];
        $unidades_variante = 0.0;
        $info_variantes = [];

        // Suma de los aportes de las variantes: lo que `setArticleStockFromAddresses()` deja en
        // `articles.stock` de un artículo con variantes.
        $suma_de_variantes = 0.0;

        // true si alguna variante tiene una fila VIVA en una sucursal que no es del dueño.
        $fila_en_sucursal_ajena = false;

        foreach ($variantes as $variant_id => $variante) {
            $filas_de_la_variante = isset($filas_por_variante[$variant_id]) ? $filas_por_variante[$variant_id] : [];

            // Filas vivas de la variante y su suma: lo que ve `$article_variant->addresses` (INNER JOIN).
            $cantidad_viva = 0;
            $suma_viva = 0.0;

            foreach ($filas_de_la_variante as $fila) {
                $address_id = (int) $fila['address_id'];

                if (array_key_exists($address_id, $sucursales)) {
                    $cantidad_viva++;
                    $suma_viva += self::numero($fila['amount']);

                    // Misma pregunta que le hace `get_addresses($user_id)` al motor: ¿la sucursal es
                    // del dueño? Si no, `$addresses[$address_id] += ...` tira `Undefined index`.
                    if (is_null($dueno) || $sucursales[$address_id] !== $dueno) {
                        $fila_en_sucursal_ajena = true;
                    }

                    continue;
                }

                $fantasmas_variante[] = $fila;
                $unidades_variante += self::numero($fila['amount']);
                self::anotar_sucursal_muerta($muertas, $address_id, $fila['amount']);
            }

            // Como el motor: una variante con filas vivas aporta la suma de esas filas; una sin
            // filas aporta su propio `article_variants.stock` (NULL cuenta 0).
            $aporte = $cantidad_viva >= 1 ? $suma_viva : self::numero($variante['stock']);

            $suma_de_variantes += $aporte;

            $info_variantes[$variant_id] = [
                'id' => $variant_id,
                'stock' => $variante['stock'],
                'updated_at' => $variante['updated_at'],
                'filas_vivas' => $cantidad_viva,
                'suma_viva' => round($suma_viva, 2),
                'aporte' => round($aporte, 2),
            ];
        }

        $tiene_variantes = count($variantes) >= 1;

        // Clase, motivo y stock proyectado (D5 y D6). Sin fantasmas no hay clase: no hay qué sanear.
        $clase = null;
        $motivo = null;
        $stock_proyectado = null;

        if (count($fantasmas) + count($fantasmas_variante) >= 1) {

            if (is_null($dueno)) {

                // 🔴 Sin dueño NUNCA se llama a la función del sistema: con `$user_id` null cae a
                // `UserHelper::userId()` (config('app.USER_ID')) y recalcularía con las sucursales de
                // otro comercio. Hoy `articles.user_id` es NOT NULL y esto no pasa; está por si cambia.
                $clase = self::CLASE_NO_RECALCULABLE;
                $motivo = self::MOTIVO_ARTICULO_SIN_DUENO;

            } elseif ($tiene_variantes) {

                if ($fila_en_sucursal_ajena) {
                    $clase = self::CLASE_NO_RECALCULABLE;
                    $motivo = self::MOTIVO_VARIANTE_EN_SUCURSAL_AJENA;
                } else {
                    $clase = self::CLASE_RECALCULAR;
                    $stock_proyectado = round($suma_de_variantes, 2);
                }

            } elseif (count($vivas) >= 1) {

                $clase = self::CLASE_RECALCULAR;

                // Σ amount de las filas con sucursal existente: lo que hace el SQL del motor
                // (`SUM(address_article.amount)`) una vez que los fantasmas ya no están.
                $suma = 0.0;

                foreach ($vivas as $fila) {
                    $suma += self::numero($fila['amount']);
                }

                $stock_proyectado = round($suma, 2);

            } else {

                $clase = self::CLASE_SOLO_FANTASMAS;
            }
        }

        $desfase = is_null($stock_proyectado)
            ? 0.0
            : round((is_null($stock_actual) ? 0.0 : $stock_actual) - $stock_proyectado, 2);

        return [
            'article_id' => $id,
            'user_id' => $dueno,
            'en_papelera' => !is_null($articulo->deleted_at),
            'stock_actual' => $stock_actual,
            'stock_crudo' => $articulo->stock,
            'tiene_variantes' => $tiene_variantes,
            'filas_vivas_articulo' => count($vivas),
            'clase' => $clase,
            'motivo' => $motivo,
            'filas_articulo' => $filas,
            'filas_fantasma_articulo' => $fantasmas,
            'filas_fantasma_variante' => $fantasmas_variante,
            'unidades_fantasma_articulo' => round($unidades_articulo, 2),
            'unidades_fantasma_variante' => round($unidades_variante, 2),
            'sucursales_muertas' => $muertas,
            'stock_proyectado' => $stock_proyectado,
            'desfase' => $desfase,
            'variantes' => $info_variantes,
        ];
    }

    /**
     * Suma una fila fantasma al conteo por sucursal muerta.
     *
     * @param  array       $muertas     address_id => ['filas', 'unidades'] (por referencia).
     * @param  int         $address_id
     * @param  string|null $amount
     * @return void
     */
    private static function anotar_sucursal_muerta(array &$muertas, $address_id, $amount)
    {
        if (!isset($muertas[$address_id])) {
            $muertas[$address_id] = ['filas' => 0, 'unidades' => 0.0];
        }

        $muertas[$address_id]['filas']++;
        $muertas[$address_id]['unidades'] = round($muertas[$address_id]['unidades'] + self::numero($amount), 2);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    //  SANEO DE UN ARTÍCULO
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * Sanea UN artículo, atómicamente: borra sus filas fantasma, recalcula `articles.stock` con la
     * función del sistema y, si el stock global cambió, deja UN movimiento que lo explica.
     *
     * Los dos callbacks son del llamador (el comando escribe el respaldo y el SQL de reversión):
     *
     *  - `$antes_de_escribir($estado)` se llama ADENTRO de la transacción, con los candados ya
     *    tomados y ANTES de borrar nada (write-ahead). `$estado` es un arreglo serializable con todo
     *    lo que se va a tocar. Si lanza una excepción no se escribe nada y la transacción se revierte:
     *    así "sin respaldo no se escribe una fila".
     *  - `$antes_de_confirmar($sql, $resumen)` se llama ADENTRO, al terminar las escrituras y
     *    ANTES del commit. `$sql` es el bloque de reversión del artículo (ver armar_sql_de_reversion).
     *    Si lanza una excepción, la transacción se revierte. Va antes del commit y no después para
     *    que una caída justo tras el commit no deje un artículo modificado sin su reversión.
     *
     * Ante un deadlock `DB::transaction` reintenta (INTENTOS): el write-ahead se vuelve a llamar con
     * `intento` mayor, y quien lo lea sabe que la línea anterior era de un intento descartado.
     *
     * @param  int            $article_id
     * @param  int            $concepto_id          Id del concepto CONCEPTO (lo resuelve el llamador una vez).
     * @param  callable|null  $antes_de_escribir
     * @param  callable|null  $antes_de_confirmar
     * @return array  Resumen: `resultado` (saneado | ya_limpio | saltado), `motivo`, `article_id`, y, si se
     *                saneó: `user_id`, `clase`, `en_papelera`, `filas_borradas_articulo`,
     *                `filas_borradas_variante`, `unidades_fantasma_articulo`, `unidades_fantasma_variante`,
     *                `stock_antes`, `stock_despues`, `stock_proyectado`, `proyeccion_coincide`,
     *                `movimiento_id`, `movimiento_amount`, `pivot_reconstruido`, `variantes_actualizadas`.
     * @throws \Throwable Cualquier error de la base o de un callback (la transacción ya se revirtió).
     */
    public static function sanear_articulo($article_id, $concepto_id, $antes_de_escribir = null, $antes_de_confirmar = null)
    {
        $article_id = (int) $article_id;

        // 🔴 FUERA de la transacción, a propósito. Para bloquear primero los pivots de variante
        // (orden del motor) hay que saber los ids de las variantes, y leerlos con una consulta sin
        // candado DENTRO de la transacción haría nacer el snapshot de REPEATABLE READ antes de los
        // candados. Leídos acá (autocommit), la primera sentencia de la transacción es un `FOR UPDATE`.
        $variantes_previas = self::ids_de_variantes([$article_id]);

        // Cuenta los intentos de la transacción (un reintento por deadlock vuelve a ejecutar el closure).
        $intento = 0;

        return DB::transaction(function () use ($article_id, $concepto_id, $antes_de_escribir, $antes_de_confirmar, $variantes_previas, &$intento) {

            $intento++;

            // Se vuelve a leer TODO adentro y bajo candado: lo medido antes (en --ver o en el recorrido
            // previo) pudo cambiar. Es lo que da la idempotencia y la seguridad ante dos corridas.
            $analisis = self::analizar([$article_id], true, $variantes_previas);

            if (!isset($analisis[$article_id])) {
                return [
                    'resultado' => self::RESULTADO_SALTADO,
                    'motivo' => self::MOTIVO_ARTICULO_INEXISTENTE,
                    'article_id' => $article_id,
                ];
            }

            // El análisis de este artículo bajo candado.
            $a = $analisis[$article_id];

            if (is_null($a['clase'])) {
                return [
                    'resultado' => self::RESULTADO_YA_LIMPIO,
                    'motivo' => null,
                    'article_id' => $article_id,
                ];
            }

            if ($a['clase'] === self::CLASE_NO_RECALCULABLE) {
                return [
                    'resultado' => self::RESULTADO_SALTADO,
                    'motivo' => $a['motivo'],
                    'article_id' => $article_id,
                    'user_id' => $a['user_id'],
                    'clase' => $a['clase'],
                ];
            }

            $dueno = $a['user_id'];

            // Foto de los depósitos ANTES de tocar nada (la necesita el movimiento). Solo si va a
            // haber recálculo: un artículo "solo fantasmas" no genera movimiento.
            $foto_antes = null;

            if ($a['clase'] === self::CLASE_RECALCULAR) {
                $foto_antes = SetStockPorDeposito::foto($article_id);
            }

            // Write-ahead: el respaldo con todo lo que se va a tocar, ANTES de la primera escritura.
            if (is_callable($antes_de_escribir)) {
                call_user_func($antes_de_escribir, self::estado_previo($a, $intento));
            }

            // Ids de las filas fantasma leídas BAJO el candado: se borra exactamente esas, por id.
            // Nunca "todas las del par" ni "las que no tengan sucursal" releídas ahora: una fila que
            // apareció después del candado no la vio nadie y no se respalda.
            $ids_articulo = self::ids_de_filas($a['filas_fantasma_articulo']);
            $ids_variante = self::ids_de_filas($a['filas_fantasma_variante']);

            $borradas_articulo = self::borrar_por_ids('address_article', $ids_articulo);
            $borradas_variante = self::borrar_por_ids('address_article_variant', $ids_variante);

            // `articles.stock` tal cual estaba (string|null), para la reversión.
            $stock_antes_crudo = $a['stock_crudo'];

            if ($a['clase'] === self::CLASE_RECALCULAR) {
                // La función del sistema, con el dueño del ARTÍCULO y sin `$check_linkage` (como hace
                // `SetArticleStock`): el aviso de agotado/ingreso es del movimiento real, no de un saneo.
                // Con la papelera: `find()` a secas no devuelve un artículo borrado.
                ArticleHelper::setArticleStockFromAddresses(Article::withTrashed()->find($article_id), false, $dueno);
            }

            // El stock que quedó, leído de la base (la función escribe con SQL y el modelo queda atrasado).
            $stock_despues_crudo = DB::table('articles')->where('id', $article_id)->value('stock');

            // NULL cuenta 0: así un artículo que nunca tuvo stock cargado y queda en 0 no genera un
            // movimiento de cantidad cero.
            $stock_viejo = is_null($a['stock_actual']) ? 0.0 : $a['stock_actual'];
            $stock_nuevo = is_null($stock_despues_crudo) ? 0.0 : (float) $stock_despues_crudo;

            // Solo se deja movimiento si el stock global CAMBIA (como en la limpieza de 3DTisk: de 45
            // artículos, 14 no necesitaron movimiento).
            $movimiento_id = null;
            $movimiento_amount = 0.0;

            if ($a['clase'] === self::CLASE_RECALCULAR && abs($stock_nuevo - $stock_viejo) >= self::TOLERANCIA) {
                $movimiento_amount = round($stock_nuevo - $stock_viejo, 2);

                $movimiento_id = self::crear_movimiento($article_id, $dueno, $movimiento_amount, $concepto_id, $foto_antes);
            }

            // ¿Lo que dejó la función coincide con lo que se proyectó? Si no, no se aborta (el stock
            // que quedó ES el del sistema), pero se anota: es una señal de que el criterio y el
            // motor discrepan o de que alguien tocó el artículo en el medio.
            $proyeccion_coincide = null;

            if (!is_null($a['stock_proyectado'])) {
                $proyeccion_coincide = abs($stock_nuevo - $a['stock_proyectado']) < self::TOLERANCIA;

                if (!$proyeccion_coincide) {
                    Log::warning('FilasFantasmaDeSucursalHelper: el artículo ' . $article_id . ' quedó con stock ' . $stock_nuevo . ' y se había proyectado ' . $a['stock_proyectado'] . '.');
                }
            }

            // Lo que cambió fuera de las filas que se borraron a mano: el pivot del artículo (la
            // función lo reconstruye si alguna variante reparte) y el stock de las variantes. Se MIDE,
            // no se predice: así la reversión es exacta aunque el motor cambie.
            $pivot_reconstruido = self::pivot_fue_reconstruido($article_id, $a['filas_articulo'], $ids_articulo);
            $variantes_cambiadas = self::variantes_que_cambiaron($article_id, $a['variantes']);

            $resumen = [
                'resultado' => self::RESULTADO_SANEADO,
                'motivo' => null,
                'article_id' => $article_id,
                'user_id' => $dueno,
                'clase' => $a['clase'],
                'en_papelera' => $a['en_papelera'],
                'filas_borradas_articulo' => $borradas_articulo,
                'filas_borradas_variante' => $borradas_variante,
                'unidades_fantasma_articulo' => $a['unidades_fantasma_articulo'],
                'unidades_fantasma_variante' => $a['unidades_fantasma_variante'],
                'stock_antes' => $a['stock_actual'],
                'stock_despues' => is_null($stock_despues_crudo) ? null : (float) $stock_despues_crudo,
                'stock_proyectado' => $a['stock_proyectado'],
                'proyeccion_coincide' => $proyeccion_coincide,
                'movimiento_id' => $movimiento_id,
                'movimiento_amount' => $movimiento_amount,
                'pivot_reconstruido' => $pivot_reconstruido,
                'variantes_actualizadas' => count($variantes_cambiadas),
            ];

            // Reversión: va ANTES del commit (ver el docblock de este método).
            $sql = self::armar_sql_de_reversion([
                'article_id' => $article_id,
                'user_id' => $dueno,
                'clase' => $a['clase'],
                'filas_articulo_borradas' => $a['filas_fantasma_articulo'],
                'filas_variante_borradas' => $a['filas_fantasma_variante'],
                'pivot_reconstruido' => $pivot_reconstruido,
                'filas_articulo_previas' => $a['filas_articulo'],
                'stock_antes' => $stock_antes_crudo,
                'stock_despues' => $stock_despues_crudo,
                'variantes_cambiadas' => $variantes_cambiadas,
                'movimiento_id' => $movimiento_id,
            ]);

            if (is_callable($antes_de_confirmar)) {
                call_user_func($antes_de_confirmar, $sql, $resumen);
            }

            return $resumen;
        }, self::INTENTOS);
    }

    /**
     * Crea el movimiento de stock que explica la corrección, con el modelo y completado con los
     * mismos helpers que usa el motor (ver "Por qué el movimiento NO se crea con crear()").
     *
     * `stock_anterior`, `stock_por_deposito` (el fantasma figura con su monto en "anterior" y 0 en
     * "resultante") y `stock_resultante` salen de `SetStockPorDeposito` y `SetStockResultante`, así
     * que la fila queda con la misma forma que una de `StockMovementController::crear()`.
     * `SetStockResultante` además agrega ` - <stock>` a las observaciones, como en el motor.
     *
     * @param  int        $article_id
     * @param  int|null   $dueno         `articles.user_id`.
     * @param  float      $amount        Stock nuevo − stock viejo (con signo).
     * @param  int        $concepto_id
     * @param  array      $foto_antes    `SetStockPorDeposito::foto()` tomada antes de borrar.
     * @return int  Id del movimiento.
     */
    private static function crear_movimiento($article_id, $dueno, $amount, $concepto_id, $foto_antes)
    {
        $movimiento = StockMovement::create([
            'article_id' => $article_id,
            'amount' => $amount,
            'concepto_stock_movement_id' => $concepto_id,
            'observations' => self::OBSERVACION,
            'user_id' => $dueno,
            'employee_id' => null,
        ]);

        // La foto de DESPUÉS, sin releer `articles.stock` (lo lee SetStockResultante).
        $foto_despues = SetStockPorDeposito::foto($article_id, null, false);

        SetStockPorDeposito::guardar($movimiento, $foto_antes, $foto_despues);

        SetStockResultante::set_stock_resultante($movimiento, Article::withTrashed()->find($article_id));

        return (int) $movimiento->id;
    }

    /**
     * El arreglo del write-ahead: todo lo que el saneo va a tocar de este artículo, tal cual está.
     *
     * Las filas de `address_article` van TODAS (las vivas también) con la marca `fantasma`: con
     * variantes el pivot entero se reconstruye, y sin las vivas no habría con qué volver atrás.
     *
     * @param  array  $a        Análisis del artículo (ver analizar()).
     * @param  int    $intento  Número de intento de la transacción.
     * @return array
     */
    private static function estado_previo(array $a, $intento)
    {
        // Ids de las filas fantasma del pivot del artículo, para marcarlas dentro de la lista completa.
        $ids_fantasma = array_flip(self::ids_de_filas($a['filas_fantasma_articulo']));

        $filas_articulo = [];

        foreach ($a['filas_articulo'] as $fila) {
            $fila['fantasma'] = isset($ids_fantasma[(int) $fila['id']]);
            $filas_articulo[] = $fila;
        }

        return [
            'evento' => 'antes',
            'article_id' => $a['article_id'],
            'user_id' => $a['user_id'],
            'en_papelera' => $a['en_papelera'],
            'clase' => $a['clase'],
            'intento' => $intento,
            'articles_stock' => $a['stock_crudo'],
            'stock_proyectado' => $a['stock_proyectado'],
            'desfase' => $a['desfase'],
            'address_article' => $filas_articulo,
            'address_article_variant_fantasma' => $a['filas_fantasma_variante'],
            'variantes' => array_values($a['variantes']),
        ];
    }

    /**
     * Ids de una lista de filas (arreglos con clave `id`).
     *
     * @param  array  $filas
     * @return int[]
     */
    private static function ids_de_filas(array $filas)
    {
        $ids = [];

        foreach ($filas as $fila) {
            $ids[] = (int) $fila['id'];
        }

        return $ids;
    }

    /**
     * Borra filas por id y verifica que se hayan borrado todas.
     *
     * Si borra menos de las esperadas lanza una excepción: bajo el candado nadie más puede haber
     * tocado esas filas, así que un faltante significa que algo del supuesto está roto y es mejor
     * revertir el artículo que seguir con un respaldo que no coincide con lo que pasó.
     *
     * @param  string  $tabla
     * @param  int[]   $ids
     * @return int  Filas borradas.
     * @throws \RuntimeException
     */
    private static function borrar_por_ids($tabla, array $ids)
    {
        $borradas = 0;

        foreach (array_chunk($ids, self::TOPE_IN) as $tanda) {
            $borradas += DB::table($tabla)->whereIn('id', $tanda)->delete();
        }

        if ($borradas !== count($ids)) {
            throw new \RuntimeException('Se esperaba borrar ' . count($ids) . ' filas de ' . $tabla . ' y se borraron ' . $borradas . '.');
        }

        return $borradas;
    }

    /**
     * ¿La función del sistema reconstruyó el pivot del artículo (`sync([])` + un `attach` por
     * sucursal del dueño)? Se compara el conjunto de ids de fila que quedó contra el que tenía que
     * quedar si solo se hubieran borrado los fantasmas: la reconstrucción crea filas con ids nuevos.
     *
     * @param  int    $article_id
     * @param  array  $filas_previas   Todas las filas del pivot antes de borrar.
     * @param  int[]  $ids_borrados    Ids de los fantasmas borrados a mano.
     * @return bool
     */
    private static function pivot_fue_reconstruido($article_id, array $filas_previas, array $ids_borrados)
    {
        // Ids que habría si solo se hubieran borrado los fantasmas.
        $esperados = array_values(array_diff(self::ids_de_filas($filas_previas), $ids_borrados));

        sort($esperados, SORT_NUMERIC);

        // Ids que hay ahora.
        $actuales = [];

        foreach (DB::table('address_article')->where('article_id', $article_id)->pluck('id') as $id) {
            $actuales[] = (int) $id;
        }

        sort($actuales, SORT_NUMERIC);

        return $actuales !== $esperados;
    }

    /**
     * Variantes del artículo cuyo `stock` cambió durante el saneo (la función del sistema pone
     * `article_variants.stock` = suma de sus filas vivas y hace `save()`, que también mueve
     * `updated_at`).
     *
     * @param  int    $article_id
     * @param  array  $variantes_previas  id => ['stock', 'updated_at', ...] tal cual estaban.
     * @return array  Lista de ['id', 'stock_antes', 'updated_at_antes', 'stock_despues'].
     */
    private static function variantes_que_cambiaron($article_id, array $variantes_previas)
    {
        $cambiadas = [];

        if (count($variantes_previas) === 0) {
            return $cambiadas;
        }

        foreach (DB::table('article_variants')->where('article_id', $article_id)->get(['id', 'stock']) as $variante) {
            $id = (int) $variante->id;

            if (!isset($variantes_previas[$id])) {
                continue;
            }

            $antes = $variantes_previas[$id];

            if (self::stock_cambio($antes['stock'], $variante->stock)) {
                $cambiadas[] = [
                    'id' => $id,
                    'stock_antes' => $antes['stock'],
                    'updated_at_antes' => $antes['updated_at'],
                    'stock_despues' => $variante->stock,
                ];
            }
        }

        return $cambiadas;
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    //  REVERSIÓN
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * El bloque de SQL que devuelve UN artículo a como estaba antes del saneo.
     *
     * Cada bloque es una transacción completa (`START TRANSACTION; ... COMMIT;`) y cada sentencia
     * va en UNA línea: así el archivo es válido y ejecutable aunque la corrida se haya cortado a la
     * mitad (un `START` global sin su `COMMIT` final haría que `mysql < archivo.sql` no confirme
     * nada, en silencio).
     *
     *  - `INSERT IGNORE` de las filas borradas CON su id original (si alguna ya está, no pisa nada).
     *  - Si el pivot del artículo se reconstruyó: `DELETE FROM address_article WHERE article_id = X`
     *    y se reinsertan TODAS las filas de antes (vivas y fantasma), con sus ids. ⚠ Este bloque
     *    pisa las filas actuales del artículo: solo corresponde si el artículo no tuvo movimientos
     *    desde la corrida.
     *  - `UPDATE articles SET stock = <viejo> WHERE id = X AND stock = <nuevo>`: GUARDADO con el valor
     *    que dejó el saneo, para no pisar un movimiento posterior. Lo mismo para las variantes.
     *  - `DELETE FROM stock_movements WHERE id = <id del movimiento>`.
     *
     * @param  array  $d  article_id, user_id, clase, filas_articulo_borradas, filas_variante_borradas,
     *                    pivot_reconstruido, filas_articulo_previas, stock_antes, stock_despues,
     *                    variantes_cambiadas, movimiento_id.
     * @return string  El bloque, terminado en una línea en blanco.
     */
    public static function armar_sql_de_reversion(array $d)
    {
        $lineas = [];

        $lineas[] = '-- Artículo ' . $d['article_id'] . ' (dueño ' . $d['user_id'] . ', clase ' . $d['clase'] . ')'
            . (is_null($d['movimiento_id']) ? '' : ' · movimiento ' . $d['movimiento_id']);

        $lineas[] = 'START TRANSACTION;';

        if (!is_null($d['movimiento_id'])) {
            $lineas[] = 'DELETE FROM stock_movements WHERE id = ' . (int) $d['movimiento_id'] . ' AND article_id = ' . (int) $d['article_id'] . ';';
        }

        if ($d['pivot_reconstruido']) {
            $lineas[] = '-- ⚠ El pivot de este artículo se reconstruyó (tiene variantes con depósitos): se pisan sus filas actuales con las de antes.';
            $lineas[] = 'DELETE FROM address_article WHERE article_id = ' . (int) $d['article_id'] . ';';

            $filas_articulo = $d['filas_articulo_previas'];
        } else {
            $filas_articulo = $d['filas_articulo_borradas'];
        }

        foreach (self::inserts_de('address_article', ['id', 'article_id', 'address_id', 'amount', 'created_at', 'updated_at', 'stock_min', 'stock_max'], $filas_articulo) as $linea) {
            $lineas[] = $linea;
        }

        foreach (self::inserts_de('address_article_variant', ['id', 'address_id', 'article_variant_id', 'amount', 'on_display', 'created_at', 'updated_at'], $d['filas_variante_borradas']) as $linea) {
            $lineas[] = $linea;
        }

        foreach ($d['variantes_cambiadas'] as $variante) {
            $lineas[] = 'UPDATE article_variants SET stock = ' . self::literal_sql($variante['stock_antes'])
                . ', updated_at = ' . self::literal_sql($variante['updated_at_antes'])
                . ' WHERE id = ' . (int) $variante['id']
                . ' AND ' . self::comparar_con($variante['stock_despues']) . ';';
        }

        if (self::stock_cambio($d['stock_antes'], $d['stock_despues'])) {
            $lineas[] = 'UPDATE articles SET stock = ' . self::literal_sql($d['stock_antes'])
                . ' WHERE id = ' . (int) $d['article_id']
                . ' AND ' . self::comparar_con($d['stock_despues']) . ';';
        }

        $lineas[] = 'COMMIT;';

        return implode("\n", $lineas) . "\n\n";
    }

    /**
     * Sentencias `INSERT IGNORE` para volver a insertar unas filas con sus ids originales, en
     * tandas de 100 filas por sentencia (una sentencia por línea).
     *
     * @param  string  $tabla
     * @param  array   $columnas
     * @param  array   $filas     Arreglos columna => valor, tal cual salieron de la base.
     * @return string[]
     */
    private static function inserts_de($tabla, array $columnas, array $filas)
    {
        $sentencias = [];

        foreach (array_chunk($filas, 100) as $tanda) {
            $valores = [];

            foreach ($tanda as $fila) {
                $celdas = [];

                foreach ($columnas as $columna) {
                    $celdas[] = self::literal_sql(array_key_exists($columna, $fila) ? $fila[$columna] : null);
                }

                $valores[] = '(' . implode(', ', $celdas) . ')';
            }

            $sentencias[] = 'INSERT IGNORE INTO ' . $tabla . ' (' . implode(', ', $columnas) . ') VALUES ' . implode(', ', $valores) . ';';
        }

        return $sentencias;
    }

    /**
     * Literal de SQL para un valor que salió de la base: NULL, un número tal cual (conserva todos
     * los decimales) o un texto entre comillas escapado por el driver (las fechas).
     *
     * @param  mixed  $valor
     * @return string
     */
    private static function literal_sql($valor)
    {
        if (is_null($valor)) {
            return 'NULL';
        }

        if (is_int($valor) || is_float($valor)) {
            return (string) $valor;
        }

        if (is_bool($valor)) {
            return $valor ? '1' : '0';
        }

        // Número que la base devolvió como texto (los decimal): se escribe sin comillas.
        if (preg_match('/^-?\d+(\.\d+)?$/', (string) $valor) === 1) {
            return (string) $valor;
        }

        return DB::connection()->getPdo()->quote((string) $valor);
    }

    /**
     * Condición `stock = <valor>` para el guardado de un UPDATE de reversión (con NULL no vale `=`).
     *
     * @param  mixed  $valor
     * @return string
     */
    private static function comparar_con($valor)
    {
        return is_null($valor) ? 'stock IS NULL' : 'stock = ' . self::literal_sql($valor);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    //  UTILIDADES
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ¿Cambió un stock? Cuenta el pasaje de NULL a un número y viceversa (aunque el número sea 0)
     * y una diferencia de al menos TOLERANCIA entre dos números.
     *
     * @param  mixed  $antes
     * @param  mixed  $despues
     * @return bool
     */
    private static function stock_cambio($antes, $despues)
    {
        if (is_null($antes) || is_null($despues)) {
            return !(is_null($antes) && is_null($despues));
        }

        return abs((float) $antes - (float) $despues) >= self::TOLERANCIA;
    }

    /**
     * Un valor de la base como float; NULL y vacío cuentan 0 (igual que `COALESCE(amount, 0)`).
     *
     * @param  mixed  $valor
     * @return float
     */
    private static function numero($valor)
    {
        if (is_null($valor) || $valor === '') {
            return 0.0;
        }

        return (float) $valor;
    }

    /**
     * Acota una consulta por dueño y/o por artículo.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  string    $columna_dueno     Ej.: 'a.user_id'.
     * @param  string    $columna_articulo  Ej.: 'aa.article_id'.
     * @param  int|null  $user_id
     * @param  int|null  $articulo_id
     * @return void
     */
    private static function acotar($query, $columna_dueno, $columna_articulo, $user_id, $articulo_id)
    {
        if (!is_null($user_id)) {
            $query->where($columna_dueno, (int) $user_id);
        }

        if (!is_null($articulo_id)) {
            $query->where($columna_articulo, (int) $articulo_id);
        }
    }
}
