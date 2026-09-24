<?php

namespace App\Http\Controllers\Helpers\import\article\motor;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Stock\StockMovementController;
use App\Models\Advise;
use App\Models\Article;
use App\Models\ConceptoStockMovement;
use App\Services\MercadoLibre\ProductService;
use App\Services\TiendaNube\TiendaNubeSyncArticleService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Stock de la importación de artículos, escrito EN LOTE.
 *
 * Misión `importacion-excel-motor-rapido` (24/9/2026), constructor B1. Reemplaza, para el stock
 * global y el stock por depósito SIN variante, la llamada por movimiento a
 * `StockMovementController::crear()` que hacía `ActualizarBBDD` (17–22 consultas por movimiento:
 * el concepto buscado por nombre dos veces, `addresses` cargadas tres veces, tres `save()` del
 * movimiento, `fresh()` por actualizado). Acá el lote entero se resuelve con un puñado de
 * sentencias que no crecen con la cantidad de artículos, y la base queda EXACTAMENTE igual que
 * con el camino viejo. Cualquier caso que esta clase no cubre (variantes, o un artículo que ya
 * tiene variantes) sigue yendo por `crear()`, uno por uno, como hasta hoy.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────
 * QUÉ DEJA CADA PASO DE `crear()` HOY, Y CÓMO SE REPRODUCE EN BLOQUE (punto por punto)
 * ─────────────────────────────────────────────────────────────────────────────────────────
 *
 *  1. `Article::find($data['model_id'])` — modelo fresco (sin soft-borrados); si no existe, no
 *     hace nada. → Un solo `Article::whereIn('id', …)->get(['id','stock','user_id'])` por lote
 *     (mismo scope de soft delete); un id que no aparece se saltea en silencio.
 *
 *  2. `SetConcepto::get_concepto()` — `ConceptoStockMovement::where('name','Importacion de excel')
 *     ->first()`: el id, o `null` con un `Log::warning` si el concepto no existe en la base del
 *     cliente (nunca un id de fallback). → Se resuelve UNA vez por lote (`resolver_concepto()`),
 *     con el mismo warning una sola vez.
 *
 *  3. `check_unidades_individuales()` — `ConceptoStockMovement::find($id)`; multiplica por
 *     `unidades_individuales` SOLO para 'Ingreso manual', 'Compra a proveedor', 'Act Compra a
 *     proveedor' y 'Eliminacion Compra a proveedor'. **Para 'Importacion de excel' NO multiplica**
 *     (verificado en el código: el concepto no está en esa lista), y con concepto `null` tampoco.
 *     → `amount = (float) $amount`, sin consulta.
 *
 *  4. `StockMovement::create([...])` — fila con `article_id`, `amount`, `sale_id`/`order_id`/
 *     `provider_order_id`/`deposit_movement_id`/`nota_credito_id` en null (`isset_dist_0` de
 *     claves que el import no manda), `provider_id` null (el import no manda proveedor),
 *     `from_address_id` null, `to_address_id` = el depósito (o null en el global),
 *     `article_variant_id` null, `observations` null, `employee_id` = `auth_user_id` (o
 *     `UserHelper::userId(false)`), `user_id` = `owner->id` (o `UserHelper::userId()`),
 *     `created_at` = `Carbon::now()` — el `$segundos_para_agregar` de `crear()` existe, pero el
 *     import nunca lo pasa (`crear($data, true, $user, $auth_user_id)`), así que no hay desfase:
 *     `created_at` es "ahora". La columna `concepto` (texto) queda null.
 *     → Un `INSERT` multi-fila en `stock_movements` (tandas de 500) con las mismas columnas y los
 *     valores FINALES de los pasos 5, 8 y 9 ya puestos; `created_at` = `updated_at` = el mismo
 *     "ahora" del lote (hoy cada movimiento toma su propio `now()`: difieren en segundos, nada
 *     más). El orden de las filas es el orden en que `ActualizarBBDD` pidió los movimientos, así
 *     los ids quedan crecientes igual que hoy.
 *
 *  5. `SetConcepto::set_concepto()` — otra vez el `where('name')->first()` y un `save()`:
 *     `concepto_stock_movement_id` = el id (o null). → Va en el `INSERT` del paso 4.
 *
 *  6. `SetArticleStock::set_article_stock()`, con `$set_updated_at = true`:
 *     6.a `CheckFromAddress` — sin `from_address_id`: no hace nada.
 *     6.b `CheckToAddress` — sin `to_address_id`: nada. Con `to_address_id` (y sin variante):
 *         si el artículo ya tiene ese depósito → `sumar_al_deposito()`:
 *         `UPDATE address_article SET amount = COALESCE(amount,0) + delta, updated_at = now()
 *         WHERE article_id AND address_id` (todas las filas del par, la tabla no tiene único);
 *         si no lo tiene → `puede_abrir_el_primer_deposito()`: sí cuando ya reparte por depósitos,
 *         o su stock es null/0, o el concepto está en `CONCEPTOS_QUE_REPARTEN` ('Importacion de
 *         excel' lo está; con concepto null da false) → `attach()` = `INSERT address_article
 *         (article_id, address_id, amount)` SIN timestamps (la relación no declara
 *         `withTimestamps`); si no puede abrirlo, `to_address_id` del movimiento pasa a null y
 *         el movimiento va al stock global.
 *         → Se simula en PHP con el mapa de depósitos del lote (un `SELECT` de `address_article`
 *         con join a `addresses`, que es lo que ve `$article->addresses`) y se escribe con UN
 *         `INSERT` multi-fila para los pares nuevos y UN `UPDATE … CASE` para los incrementos
 *         (con `updated_at = now`, como `sumar_al_deposito`). Un par que se abre y recibe otro
 *         movimiento en el mismo lote se inserta directamente con el acumulado (y `updated_at`
 *         puesto, porque hoy el segundo movimiento lo tocaba).
 *     6.c `CheckVariants` — sin `article_variant_id`: nada.
 *     6.d `CheckGlobalStock` — si `stock` es null: `stock = 0; save()` (toca `updated_at`). Si el
 *         artículo NO tiene depósitos NI variantes: `UPDATE articles SET stock = COALESCE(stock,0)
 *         + amount, updated_at = now`; si tiene depósitos, el monto NO se suma a `articles.stock`
 *         (lo recalcula 6.e desde los depósitos). → UN `UPDATE … CASE id` con el delta acumulado
 *         por artículo y `updated_at = now` (los artículos con stock null que no reciben
 *         incremento entran con delta 0, para que el `COALESCE` y el `updated_at` queden igual).
 *     6.e `ArticleHelper::setArticleStockFromAddresses($article, false)` — con depósitos:
 *         `UPDATE articles SET stock = (SELECT COALESCE(SUM(aa.amount),0) FROM address_article aa
 *         WHERE aa.article_id = X)` (sin tocar `updated_at`; la suma incluye TODAS las filas del
 *         pivot, tengan o no dirección viva) y relee `stock`. → La misma subconsulta, en UN
 *         `UPDATE … WHERE id IN (…)` para los artículos que terminan el lote con depósitos.
 *     6.f `ArticleHelper::checkAdvises($article, $stock_anterior)` — sólo si el stock cruzó de
 *         < 1 a ≥ 1 en ESE movimiento: `Advise::where('article_id')->get()` y el mail. → Se
 *         detecta la transición por movimiento con el stock corrido en PHP; UN `SELECT` de
 *         `advises` por lote filtra a los que tienen avisos y sólo para esos se llama al mismo
 *         `checkAdvises()` (mismo gate por cliente, mismo job).
 *     6.g `CheckCartAmount` — se saltea para 'Importacion de excel' (y con concepto null):
 *         nada que reproducir.
 *
 *  7. `SetStockResultante` — `SELECT stock FROM articles WHERE id` (después de los UPDATE) →
 *     `stock_resultante` = ese valor, `save()`; y `observations` = `$article->stock` (el mismo
 *     valor, como float) con otro `save()`. → `stock_resultante`/`observations` del último
 *     movimiento de cada artículo salen de UN `SELECT` de relectura por lote (exactamente lo que
 *     lee hoy); los movimientos intermedios de un mismo artículo (un depósito atrás de otro)
 *     llevan el stock corrido en PHP, redondeado a 2 decimales como la columna. `observations` va
 *     como float y PDO lo convierte igual que hoy ("25", "12.5").
 *
 *  8. `SetProvider` — el import no manda proveedor: `$stock_movement->provider` es null sin
 *     consulta. Nada.
 *
 *  9. `SetStockUpdatedAt` — con concepto y sin 'venta' en el nombre: `articles.stock_updated_at`
 *     = `created_at` del movimiento, con `timestamps = false` (no toca `updated_at`). Con
 *     concepto null NO se toca (decisión conservadora de esa clase). → UN `UPDATE articles SET
 *     stock_updated_at = <ahora del lote> WHERE id IN (…)`, sólo si hay concepto.
 *
 * 10. `ProductService::add_article_to_sync()` / `TiendaNubeSyncArticleService::add_article_to_sync()`
 *     — gateados por `env('USA_MERCADO_LIBRE')` / `env('USA_TIENDA_NUBE')`. → Con el env prendido
 *     se llaman igual, una vez por movimiento, con el modelo ya al día. Con el env apagado (todos
 *     los clientes salvo los que usan esas plataformas), nada.
 *
 * 11. Las reglas de `ActualizarBBDD` que envolvían a `crear()` se conservan tal cual:
 *     - `guardar_stock_movement_global()`: con `target_stock` (actualizados) el delta se recalcula
 *       contra el stock REAL del momento (`target - fresh()->stock`, prompt 04 grupo 229) y un
 *       delta 0 no genera movimiento; sin `target` (creados) tampoco (grupo 301 prompt 02). Acá el
 *       "stock real del momento" sale del `SELECT` del lote más los movimientos anteriores del
 *       mismo lote sobre ese artículo, que es lo que `fresh()` hubiera leído.
 *     - `guardar_stock_movement_addresses()`: un movimiento por depósito con `amount` no nulo
 *       (SIN guarda de cero, como hoy) y, aparte, `stock_min`/`stock_max`: `updateExistingPivot`
 *       si el par existe (sin timestamps) o `attach` con sólo min/max (amount null) si no.
 *
 * 12. Los `Log::info` incondicionales de `crear()`, `SetConcepto` y `CheckGlobalStock` (~8 por
 *     movimiento) se reemplazan por UN `Log::info` por lote.
 *
 * Lo que NO se cubre y sigue yendo por `crear()` (`procesar_a_la_vieja()`): un artículo que ya
 * tiene filas en `article_variants` (ahí `CheckGlobalStock` no suma y `setArticleStockFromAddresses`
 * recalcula desde las variantes). Los movimientos de variantes (`guardar_stock_movement_variant`)
 * nunca pasan por acá.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class StockEnLote
{
    /** Nombre del concepto con el que la importación registra sus movimientos. */
    const CONCEPTO = 'Importacion de excel';

    /** Tamaño de las tandas de los INSERT multi-fila. */
    const TANDA = 500;

    /** @var \App\Models\User|null Dueño de la cuenta (owner). */
    protected $user;

    /** @var int|null Usuario autenticado que corre la importación (employee_id del movimiento). */
    protected $auth_user_id;

    /** @var \App\Http\Controllers\Stock\StockMovementController Camino viejo, para los casos no cubiertos. */
    protected $stock_movement_ct;

    /**
     * Pedidos acumulados, en el orden en que llegaron. Cada uno:
     *   ['tipo' => 'global',   'article' => Article, 'amount' => float, 'target' => float|null]
     *   ['tipo' => 'deposito', 'article' => Article, 'address_id' => int, 'amount' => float|null,
     *                          'stock_min' => mixed, 'stock_max' => mixed]
     *
     * @var array
     */
    protected $pedidos = [];

    /**
     * @param \App\Models\User|null                                   $user
     * @param int|null                                                $auth_user_id
     * @param \App\Http\Controllers\Stock\StockMovementController|null $stock_movement_ct
     */
    public function __construct($user, $auth_user_id, $stock_movement_ct = null)
    {
        $this->user              = $user;
        $this->auth_user_id      = $auth_user_id;
        $this->stock_movement_ct = $stock_movement_ct ?: new StockMovementController(false);
    }

    /**
     * Pide un movimiento de stock GLOBAL para un artículo. Misma semántica que
     * `ActualizarBBDD::guardar_stock_movement_global()`:
     *
     *  - con `$target_stock` (artículos actualizados) el delta se recalcula al volcar contra el
     *    stock real de ese momento, y si da 0 no se registra nada;
     *  - sin `$target_stock` (artículos creados) el delta es `$amount` tal cual, y si es 0
     *    tampoco se registra nada.
     *
     * @param  \App\Models\Article $article
     * @param  float|int|string    $amount        delta a aplicar
     * @param  float|null          $target_stock  stock resultante esperado (sólo actualizados)
     * @return void
     */
    public function agregar_global($article, $amount, $target_stock = null)
    {
        // Sin objetivo, la guarda de delta cero se resuelve acá mismo (no hay nada que releer).
        if (is_null($target_stock) && (float) $amount == 0.0) {
            return;
        }

        $this->pedidos[] = [
            'tipo'    => 'global',
            'article' => $article,
            'amount'  => (float) $amount,
            'target'  => is_null($target_stock) ? null : (float) $target_stock,
        ];
    }

    /**
     * Pide los movimientos por DEPÓSITO de un artículo. Misma semántica que
     * `ActualizarBBDD::guardar_stock_movement_addresses()`: por cada depósito, un movimiento con
     * `to_address_id` si `amount` no es null, y aparte `stock_min`/`stock_max` sobre el pivot.
     *
     * @param  \App\Models\Article $article
     * @param  array               $addresses  [{address_id, amount, stock_min, stock_max}, …]
     * @return void
     */
    public function agregar_por_depositos($article, array $addresses)
    {
        foreach ($addresses as $address) {

            $this->pedidos[] = [
                'tipo'       => 'deposito',
                'article'    => $article,
                'address_id' => $address['address_id'],
                'amount'     => is_null($address['amount']) ? null : (float) $address['amount'],
                'stock_min'  => array_key_exists('stock_min', $address) ? $address['stock_min'] : null,
                'stock_max'  => array_key_exists('stock_max', $address) ? $address['stock_max'] : null,
            ];
        }
    }

    /**
     * Cantidad de pedidos acumulados y todavía no volcados.
     *
     * @return int
     */
    public function pendientes()
    {
        return count($this->pedidos);
    }

    /**
     * Escribe en la base todo lo acumulado y vacía la cola de pedidos.
     *
     * Devuelve un resumen: cantidad de movimientos escritos en bloque, cantidad de pedidos que
     * fueron por el camino viejo (`crear()`) y cuántos artículos tocó.
     *
     * @return array ['movimientos' => int, 'por_camino_viejo' => int, 'articulos' => int]
     */
    public function volcar()
    {
        $resumen = ['movimientos' => 0, 'por_camino_viejo' => 0, 'articulos' => 0];

        if (count($this->pedidos) === 0) {
            return $resumen;
        }

        $pedidos       = $this->pedidos;
        $this->pedidos = [];

        // Paso 2 del docblock: el concepto, una sola vez.
        $concepto_id = $this->resolver_concepto();

        // employee_id / user_id del movimiento, igual que en crear().
        $employee_id = !is_null($this->auth_user_id) ? $this->auth_user_id : UserHelper::userId(false);
        $user_id     = !is_null($this->user) ? $this->user->id : UserHelper::userId();

        // "Ahora" del lote: created_at de los movimientos y stock_updated_at de los artículos.
        $ahora = Carbon::now()->format('Y-m-d H:i:s');

        // Ids de los artículos involucrados, sin repetir.
        $ids = [];
        foreach ($pedidos as $pedido) {
            $ids[(int) $pedido['article']->id] = true;
        }
        $ids = array_keys($ids);

        // Paso 1: estado inicial de los artículos (un SELECT; los soft-borrados no vienen).
        $estado = $this->leer_estado_inicial($ids);

        // Artículos con variantes: van por el camino viejo.
        $con_variantes = $this->leer_articulos_con_variantes($ids);

        /*
         * Acumuladores de lo que se va a escribir. Todos se llenan en la simulación de abajo y se
         * ejecutan después, en el mismo orden en que crear() dejaba cada efecto.
         */
        $incremento_global   = [];   // article_id => delta acumulado sobre articles.stock
        $bump_updated_at     = [];   // article_id => true (CheckGlobalStock tocó updated_at)
        $pivots_nuevos       = [];   // article_id => address_id => [amount, stock_min, stock_max, updated_at]
        $pivots_sumar        = [];   // article_id => address_id => delta acumulado
        $pivots_min_max      = [];   // article_id => address_id => [stock_min, stock_max]
        $recalcular_desde_depositos = []; // article_id => true (setArticleStockFromAddresses)
        $movimientos         = [];   // filas a insertar en stock_movements, en orden
        $ultimo_movimiento_de = [];  // article_id => índice en $movimientos del último movimiento
        $stock_updated_at_ids = [];  // article_id => true
        $avisos              = [];   // [article, stock_anterior, stock_actual] por transición <1 -> >=1
        $a_sincronizar       = [];   // [article] por movimiento (TN / ML, gateado por env)
        $por_camino_viejo    = [];   // pedidos que se resuelven con crear()

        foreach ($pedidos as $pedido) {

            $article    = $pedido['article'];
            $article_id = (int) $article->id;

            // Paso 1: Article::find() no lo encuentra (borrado en el medio): crear() no hace nada.
            if (!isset($estado[$article_id])) {
                continue;
            }

            if (isset($con_variantes[$article_id])) {
                $por_camino_viejo[] = $pedido;
                continue;
            }

            $st = &$estado[$article_id];

            if ($pedido['tipo'] === 'global') {

                $amount = $pedido['amount'];

                // Regla 11: idempotencia contra el stock real del momento.
                if (!is_null($pedido['target'])) {

                    $stock_actual = (float) $st['stock'];
                    $amount       = $pedido['target'] - $stock_actual;

                    if ($amount == 0.0) {
                        unset($st);
                        continue;
                    }
                }

                if ((float) $amount == 0.0) {
                    unset($st);
                    continue;
                }

                $stock_anterior = is_null($st['stock']) ? 0.0 : (float) $st['stock'];

                // 6.d: stock null -> 0 con save() (toca updated_at).
                if (is_null($st['stock'])) {
                    $st['stock'] = 0.0;
                    $bump_updated_at[$article_id] = true;
                }

                if (!$st['tiene_depositos']) {

                    // 6.d: incremento en SQL + updated_at.
                    $incremento_global[$article_id] = (isset($incremento_global[$article_id]) ? $incremento_global[$article_id] : 0.0) + $amount;
                    $bump_updated_at[$article_id]   = true;
                    $st['stock'] = round($st['stock'] + $amount, 2);

                } else {

                    // 6.e: con depósitos el monto no se suma; el stock es la suma de los depósitos.
                    $st['stock'] = $this->suma_de_depositos($st);
                    $recalcular_desde_depositos[$article_id] = true;
                }

                $movimientos[] = $this->fila_de_movimiento($article_id, $amount, null, $st['stock'], $concepto_id, $employee_id, $user_id, $ahora);
                $ultimo_movimiento_de[$article_id] = count($movimientos) - 1;

                if (!is_null($concepto_id)) {
                    $stock_updated_at_ids[$article_id] = true;
                }

                if ($st['stock'] >= 1 && $stock_anterior < 1) {
                    $avisos[] = [$article, $stock_anterior, $st['stock']];
                }

                $a_sincronizar[] = $article;

                unset($st);
                continue;
            }

            // ── Pedido por depósito ──────────────────────────────────────────────────────────
            $address_id = (int) $pedido['address_id'];

            if (!is_null($pedido['amount'])) {

                $amount         = (float) $pedido['amount'];
                $stock_anterior = is_null($st['stock']) ? 0.0 : (float) $st['stock'];
                $to_address_id  = $address_id;

                // 6.b: CheckToAddress.
                if (isset($st['depositos'][$address_id])) {

                    // sumar_al_deposito(): todas las filas del par reciben el delta.
                    if (isset($pivots_nuevos[$article_id][$address_id])) {
                        // El par lo abrió este mismo lote: se inserta directamente con el acumulado.
                        $pivots_nuevos[$article_id][$address_id]['amount'] = (float) $pivots_nuevos[$article_id][$address_id]['amount'] + $amount;
                        $pivots_nuevos[$article_id][$address_id]['updated_at'] = $ahora;
                    } else {
                        $pivots_sumar[$article_id][$address_id] = (isset($pivots_sumar[$article_id][$address_id]) ? $pivots_sumar[$article_id][$address_id] : 0.0) + $amount;
                    }

                    $st['depositos'][$address_id]['suma'] += $amount * $st['depositos'][$address_id]['filas'];

                } else if ($this->puede_abrir_el_primer_deposito($st, $concepto_id)) {

                    // attach() con amount: nace la fila del par.
                    $pivots_nuevos[$article_id][$address_id] = [
                        'amount'     => $amount,
                        'stock_min'  => null,
                        'stock_max'  => null,
                        'updated_at' => null,
                    ];
                    $st['depositos'][$address_id] = ['filas' => 1, 'suma' => $amount];
                    $st['tiene_depositos'] = true;

                } else {

                    // No puede abrir el depósito: el movimiento va al stock global, sin to_address_id.
                    $to_address_id = null;
                }

                // 6.d: CheckGlobalStock.
                if (is_null($st['stock'])) {
                    $st['stock'] = 0.0;
                    $bump_updated_at[$article_id] = true;
                }

                if (!$st['tiene_depositos']) {
                    $incremento_global[$article_id] = (isset($incremento_global[$article_id]) ? $incremento_global[$article_id] : 0.0) + $amount;
                    $bump_updated_at[$article_id]   = true;
                    $st['stock'] = round($st['stock'] + $amount, 2);
                } else {
                    // 6.e: setArticleStockFromAddresses.
                    $st['stock'] = $this->suma_de_depositos($st);
                    $recalcular_desde_depositos[$article_id] = true;
                }

                $movimientos[] = $this->fila_de_movimiento($article_id, $amount, $to_address_id, $st['stock'], $concepto_id, $employee_id, $user_id, $ahora);
                $ultimo_movimiento_de[$article_id] = count($movimientos) - 1;

                if (!is_null($concepto_id)) {
                    $stock_updated_at_ids[$article_id] = true;
                }

                if ($st['stock'] >= 1 && $stock_anterior < 1) {
                    $avisos[] = [$article, $stock_anterior, $st['stock']];
                }

                $a_sincronizar[] = $article;
            }

            // Regla 11: stock_min / stock_max sobre el pivot, aparte del movimiento.
            if (!is_null($pedido['stock_min']) || !is_null($pedido['stock_max'])) {

                if (isset($st['depositos'][$address_id])) {

                    if (isset($pivots_nuevos[$article_id][$address_id])) {
                        // La fila nace en este lote: entra con min/max puestos (hoy: attach + updateExistingPivot).
                        $pivots_nuevos[$article_id][$address_id]['stock_min'] = $pedido['stock_min'];
                        $pivots_nuevos[$article_id][$address_id]['stock_max'] = $pedido['stock_max'];
                    } else {
                        $pivots_min_max[$article_id][$address_id] = [
                            'stock_min' => $pedido['stock_min'],
                            'stock_max' => $pedido['stock_max'],
                        ];
                    }

                } else {

                    // attach() sólo con min/max: la fila nace con amount null.
                    $pivots_nuevos[$article_id][$address_id] = [
                        'amount'     => null,
                        'stock_min'  => $pedido['stock_min'],
                        'stock_max'  => $pedido['stock_max'],
                        'updated_at' => null,
                    ];
                    $st['depositos'][$address_id] = ['filas' => 1, 'suma' => 0.0];
                    $st['tiene_depositos'] = true;
                }
            }

            unset($st);
        }

        // ── Los casos no cubiertos, por el camino viejo, en su orden ─────────────────────────
        foreach ($por_camino_viejo as $pedido) {
            $this->procesar_a_la_vieja($pedido);
        }
        $resumen['por_camino_viejo'] = count($por_camino_viejo);

        // ── Escrituras en bloque, en el orden en que crear() dejaba cada efecto ──────────────

        // 6.d: articles.stock += delta (y updated_at) para los que no reparten por depósitos.
        $this->escribir_incrementos_globales($incremento_global, $bump_updated_at, $ahora);

        // 6.b: pivots nuevos, incrementos y min/max.
        $this->escribir_pivots_nuevos($pivots_nuevos);
        $this->escribir_pivots_sumados($pivots_sumar, $ahora);
        $this->escribir_pivots_min_max($pivots_min_max);

        // 6.e: articles.stock = SUM(depósitos) para los que reparten.
        $this->escribir_stock_desde_depositos(array_keys($recalcular_desde_depositos));

        // 7: el stock resultante del último movimiento de cada artículo se relee de la base.
        if (count($movimientos) > 0) {

            $stocks_finales = $this->leer_stock_final(array_keys($ultimo_movimiento_de));

            foreach ($ultimo_movimiento_de as $article_id => $indice) {

                if (!array_key_exists($article_id, $stocks_finales)) {
                    continue;
                }

                $stock_final = $stocks_finales[$article_id];

                if (!is_null($stock_final)) {
                    $movimientos[$indice]['stock_resultante'] = (float) $stock_final;
                    $movimientos[$indice]['observations']     = (float) $stock_final;
                }
            }

            // El modelo en memoria queda al día y con el stock como ORIGINAL (como hace
            // SetStockResultante), así ningún save() posterior lo reescribe desde memoria.
            foreach ($pedidos as $pedido) {
                $article_id = (int) $pedido['article']->id;
                if (array_key_exists($article_id, $stocks_finales) && !is_null($stocks_finales[$article_id])) {
                    $pedido['article']->stock = (float) $stocks_finales[$article_id];
                    $pedido['article']->syncOriginalAttribute('stock');
                }
            }
        }

        // 9: stock_updated_at = created_at del movimiento (sólo con concepto).
        $this->escribir_stock_updated_at(array_keys($stock_updated_at_ids), $ahora);

        // 4/5/7: los movimientos, con todo puesto.
        $this->escribir_movimientos($movimientos);
        $resumen['movimientos'] = count($movimientos);
        $resumen['articulos']   = count($ultimo_movimiento_de);

        // 6.f: avisos de "volvió el stock".
        $this->disparar_avisos($avisos);

        // 10: Tienda Nube / Mercado Libre, sólo con el env prendido.
        $this->sincronizar_plataformas($a_sincronizar);

        Log::info('StockEnLote: '.$resumen['movimientos'].' movimientos de stock escritos en bloque para '.$resumen['articulos'].' artículos'
            .($resumen['por_camino_viejo'] > 0 ? ' ('.$resumen['por_camino_viejo'].' pedidos por el camino viejo)' : '')
            .(is_null($concepto_id) ? ' — SIN concepto "'.self::CONCEPTO.'": los movimientos quedan con concepto null y no se toca stock_updated_at' : ''));

        return $resumen;
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Lecturas por lote
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Paso 2 del docblock: id del concepto 'Importacion de excel', o null si no existe (con el
     * mismo warning que SetConcepto, una sola vez por lote).
     *
     * @return int|null
     */
    protected function resolver_concepto()
    {
        $concepto = ConceptoStockMovement::where('name', self::CONCEPTO)->first();

        if ($concepto) {
            return (int) $concepto->id;
        }

        Log::warning('StockEnLote: no se encontro el concepto de nombre "'.self::CONCEPTO.'". Los stock movements del lote van a quedar SIN concepto (concepto_stock_movement_id null).');

        return null;
    }

    /**
     * Estado inicial de cada artículo del lote: stock y depósitos (lo que ven `Article::find()`,
     * `$article->addresses` y `SUM(address_article)` al arrancar el lote).
     *
     * @param  array $ids
     * @return array article_id => ['stock' => float|null, 'depositos' => [address_id => ['filas' => int, 'suma' => float]], 'tiene_depositos' => bool]
     */
    protected function leer_estado_inicial(array $ids)
    {
        $estado = [];

        if (count($ids) === 0) {
            return $estado;
        }

        // Mismo scope que Article::find(): los soft-borrados no aparecen.
        $articulos = Article::whereIn('id', $ids)->get(['id', 'stock']);

        foreach ($articulos as $articulo) {
            $estado[(int) $articulo->id] = [
                'stock'           => is_null($articulo->stock) ? null : (float) $articulo->stock,
                'depositos'       => [],
                'tiene_depositos' => false,
            ];
        }

        /*
         * Depósitos como los ve la relación `addresses` (belongsToMany: sólo pivots cuya dirección
         * existe). Se cuentan las filas por par porque la tabla no tiene índice único y
         * sumar_al_deposito() incrementa TODAS las filas del par.
         */
        $pivots = DB::table('address_article')
                    ->join('addresses', 'addresses.id', '=', 'address_article.address_id')
                    ->whereIn('address_article.article_id', array_keys($estado))
                    ->orderBy('address_article.id')
                    ->get(['address_article.article_id', 'address_article.address_id', 'address_article.amount']);

        foreach ($pivots as $pivot) {

            $article_id = (int) $pivot->article_id;
            $address_id = (int) $pivot->address_id;

            if (!isset($estado[$article_id])) {
                continue;
            }

            if (!isset($estado[$article_id]['depositos'][$address_id])) {
                $estado[$article_id]['depositos'][$address_id] = ['filas' => 0, 'suma' => 0.0];
            }

            $estado[$article_id]['depositos'][$address_id]['filas'] += 1;
            $estado[$article_id]['depositos'][$address_id]['suma']  += is_null($pivot->amount) ? 0.0 : (float) $pivot->amount;
            $estado[$article_id]['tiene_depositos'] = true;
        }

        return $estado;
    }

    /**
     * Artículos del lote que tienen variantes (van por el camino viejo).
     *
     * @param  array $ids
     * @return array article_id => true
     */
    protected function leer_articulos_con_variantes(array $ids)
    {
        if (count($ids) === 0) {
            return [];
        }

        $con_variantes = DB::table('article_variants')
                            ->whereIn('article_id', $ids)
                            ->distinct()
                            ->pluck('article_id');

        $mapa = [];

        foreach ($con_variantes as $article_id) {
            $mapa[(int) $article_id] = true;
        }

        return $mapa;
    }

    /**
     * Paso 7: el stock que quedó en la base después de las escrituras.
     *
     * @param  array $ids
     * @return array article_id => string|null (tal cual lo devuelve la columna decimal)
     */
    protected function leer_stock_final(array $ids)
    {
        $stocks = [];

        if (count($ids) === 0) {
            return $stocks;
        }

        $filas = DB::table('articles')->whereIn('id', $ids)->get(['id', 'stock']);

        foreach ($filas as $fila) {
            $stocks[(int) $fila->id] = $fila->stock;
        }

        return $stocks;
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Reglas de la simulación
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Espejo de `CheckToAddress::puede_abrir_el_primer_deposito()`: ya reparte por depósitos, o
     * no tiene stock global que perder, o el concepto reparte a propósito ('Importacion de excel'
     * está en CONCEPTOS_QUE_REPARTEN; con concepto null la respuesta es no).
     *
     * @param  array    $st           estado del artículo
     * @param  int|null $concepto_id
     * @return bool
     */
    protected function puede_abrir_el_primer_deposito(array $st, $concepto_id)
    {
        if ($st['tiene_depositos']) {
            return true;
        }

        if (is_null($st['stock']) || (float) $st['stock'] == 0.0) {
            return true;
        }

        return !is_null($concepto_id);
    }

    /**
     * Suma de los depósitos del artículo, redondeada a los 2 decimales de la columna (lo que
     * calcula `SUM(aa.amount)` en la base).
     *
     * @param  array $st
     * @return float
     */
    protected function suma_de_depositos(array $st)
    {
        $suma = 0.0;

        foreach ($st['depositos'] as $deposito) {
            $suma += $deposito['suma'];
        }

        return round($suma, 2);
    }

    /**
     * Fila de `stock_movements` con las mismas columnas y valores que deja `crear()` (pasos 4, 5 y 7).
     *
     * @param  int      $article_id
     * @param  float    $amount
     * @param  int|null $to_address_id
     * @param  float    $stock_resultante
     * @param  int|null $concepto_id
     * @param  mixed    $employee_id
     * @param  mixed    $user_id
     * @param  string   $ahora
     * @return array
     */
    protected function fila_de_movimiento($article_id, $amount, $to_address_id, $stock_resultante, $concepto_id, $employee_id, $user_id, $ahora)
    {
        return [
            'concepto_stock_movement_id' => $concepto_id,
            'article_id'                 => $article_id,
            'from_address_id'            => null,
            'to_address_id'              => $to_address_id,
            'article_variant_id'         => null,
            'provider_id'                => null,
            'deposit_movement_id'        => null,
            'provider_order_id'          => null,
            'sale_id'                    => null,
            'nota_credito_id'            => null,
            'order_id'                   => null,
            'concepto'                   => null,
            'observations'               => (float) $stock_resultante,
            'amount'                     => (float) $amount,
            'stock_resultante'           => (float) $stock_resultante,
            'employee_id'                => $employee_id,
            'user_id'                    => $user_id,
            'created_at'                 => $ahora,
            'updated_at'                 => $ahora,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Escrituras en bloque
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * 6.d: `UPDATE articles SET stock = COALESCE(stock,0) + delta, updated_at = ahora` en UNA
     * sentencia. Los artículos que sólo necesitan el `updated_at` (stock null que pasó a 0 sin
     * incremento) entran con delta 0.
     *
     * @param  array  $incrementos     article_id => delta
     * @param  array  $bump_updated_at article_id => true
     * @param  string $ahora
     * @return void
     */
    protected function escribir_incrementos_globales(array $incrementos, array $bump_updated_at, $ahora)
    {
        $ids = array_keys($bump_updated_at + $incrementos);

        if (count($ids) === 0) {
            return;
        }

        foreach (array_chunk($ids, self::TANDA) as $tanda) {

            $case = 'CASE `id`';

            foreach ($tanda as $article_id) {
                $delta = isset($incrementos[$article_id]) ? (float) $incrementos[$article_id] : 0.0;
                $case .= ' WHEN '.(int) $article_id.' THEN ('.sprintf('%.4F', $delta).')';
            }

            $case .= ' ELSE 0 END';

            DB::table('articles')
                ->whereIn('id', $tanda)
                ->update([
                    'stock'      => DB::raw('COALESCE(`stock`, 0) + '.$case),
                    'updated_at' => $ahora,
                ]);
        }
    }

    /**
     * 6.b / regla 11: los pares (artículo, depósito) que nacen en este lote, en UN INSERT
     * multi-fila. Sin `created_at` (hoy `attach()` tampoco lo escribe: la relación no declara
     * `withTimestamps`).
     *
     * @param  array $pivots_nuevos article_id => address_id => [amount, stock_min, stock_max, updated_at]
     * @return void
     */
    protected function escribir_pivots_nuevos(array $pivots_nuevos)
    {
        $filas = [];

        foreach ($pivots_nuevos as $article_id => $por_address) {
            foreach ($por_address as $address_id => $datos) {
                $filas[] = [
                    'article_id' => (int) $article_id,
                    'address_id' => (int) $address_id,
                    'amount'     => $datos['amount'],
                    'stock_min'  => $datos['stock_min'],
                    'stock_max'  => $datos['stock_max'],
                    'created_at' => null,
                    'updated_at' => $datos['updated_at'],
                ];
            }
        }

        foreach (array_chunk($filas, self::TANDA) as $tanda) {
            DB::table('address_article')->insert($tanda);
        }
    }

    /**
     * 6.b: `sumar_al_deposito()` para todos los pares del lote en UNA sentencia:
     * `amount = COALESCE(amount,0) + delta`, `updated_at = now()`.
     *
     * @param  array  $pivots_sumar article_id => address_id => delta
     * @param  string $ahora
     * @return void
     */
    protected function escribir_pivots_sumados(array $pivots_sumar, $ahora)
    {
        $pares = [];

        foreach ($pivots_sumar as $article_id => $por_address) {
            foreach ($por_address as $address_id => $delta) {
                $pares[] = [(int) $article_id, (int) $address_id, (float) $delta];
            }
        }

        if (count($pares) === 0) {
            return;
        }

        foreach (array_chunk($pares, self::TANDA) as $tanda) {

            $case  = 'CASE';
            $where = [];

            foreach ($tanda as $par) {
                $case   .= ' WHEN `article_id` = '.$par[0].' AND `address_id` = '.$par[1].' THEN ('.sprintf('%.4F', $par[2]).')';
                $where[] = '('.$par[0].', '.$par[1].')';
            }

            $case .= ' ELSE 0 END';

            DB::statement(
                'UPDATE `address_article` SET `amount` = COALESCE(`amount`, 0) + '.$case.', `updated_at` = ? '
                .'WHERE (`article_id`, `address_id`) IN ('.implode(', ', $where).')',
                [$ahora]
            );
        }
    }

    /**
     * Regla 11: `updateExistingPivot(stock_min, stock_max)` para todos los pares existentes en UNA
     * sentencia, sin timestamps (como hoy).
     *
     * @param  array $pivots_min_max article_id => address_id => [stock_min, stock_max]
     * @return void
     */
    protected function escribir_pivots_min_max(array $pivots_min_max)
    {
        $pares = [];

        foreach ($pivots_min_max as $article_id => $por_address) {
            foreach ($por_address as $address_id => $datos) {
                $pares[] = [(int) $article_id, (int) $address_id, $datos['stock_min'], $datos['stock_max']];
            }
        }

        if (count($pares) === 0) {
            return;
        }

        foreach (array_chunk($pares, self::TANDA) as $tanda) {

            $case_min = 'CASE';
            $case_max = 'CASE';
            $where    = [];
            $bindings = [];

            foreach ($tanda as $par) {
                $condicion  = ' WHEN `article_id` = '.$par[0].' AND `address_id` = '.$par[1].' THEN ?';
                $case_min  .= $condicion;
                $where[]    = '('.$par[0].', '.$par[1].')';
                $bindings[] = $par[2];
            }

            foreach ($tanda as $par) {
                $case_max  .= ' WHEN `article_id` = '.$par[0].' AND `address_id` = '.$par[1].' THEN ?';
                $bindings[] = $par[3];
            }

            $case_min .= ' ELSE `stock_min` END';
            $case_max .= ' ELSE `stock_max` END';

            DB::statement(
                'UPDATE `address_article` SET `stock_min` = '.$case_min.', `stock_max` = '.$case_max.' '
                .'WHERE (`article_id`, `address_id`) IN ('.implode(', ', $where).')',
                $bindings
            );
        }
    }

    /**
     * 6.e: la misma subconsulta de `ArticleHelper::setArticleStockFromAddresses()`, para todos
     * los artículos del lote que reparten por depósitos, en UNA sentencia (sin `updated_at`).
     *
     * @param  array $ids
     * @return void
     */
    protected function escribir_stock_desde_depositos(array $ids)
    {
        if (count($ids) === 0) {
            return;
        }

        foreach (array_chunk($ids, self::TANDA) as $tanda) {
            DB::table('articles')
                ->whereIn('id', $tanda)
                ->update([
                    'stock' => DB::raw('(SELECT COALESCE(SUM(aa.amount), 0) FROM address_article aa WHERE aa.article_id = articles.id)'),
                ]);
        }
    }

    /**
     * 9: `stock_updated_at` = created_at del movimiento, sin tocar `updated_at`.
     *
     * @param  array  $ids
     * @param  string $ahora
     * @return void
     */
    protected function escribir_stock_updated_at(array $ids, $ahora)
    {
        if (count($ids) === 0) {
            return;
        }

        foreach (array_chunk($ids, self::TANDA) as $tanda) {
            DB::table('articles')->whereIn('id', $tanda)->update(['stock_updated_at' => $ahora]);
        }
    }

    /**
     * 4/5/7: las filas de `stock_movements`, en tandas y en orden.
     *
     * @param  array $movimientos
     * @return void
     */
    protected function escribir_movimientos(array $movimientos)
    {
        foreach (array_chunk($movimientos, self::TANDA) as $tanda) {
            DB::table('stock_movements')->insert($tanda);
        }
    }

    /**
     * 6.f: `ArticleHelper::checkAdvises()` para cada transición <1 -> >=1, pero sólo para los
     * artículos que tienen avisos cargados (UN SELECT por lote en vez de uno por movimiento).
     * checkAdvises() vuelve a leer los avisos y aplica el gate por cliente, como hoy.
     *
     * @param  array $avisos [[article, stock_anterior, stock_actual], …]
     * @return void
     */
    protected function disparar_avisos(array $avisos)
    {
        if (count($avisos) === 0) {
            return;
        }

        $ids = [];
        foreach ($avisos as $aviso) {
            $ids[(int) $aviso[0]->id] = true;
        }

        $con_avisos = Advise::whereIn('article_id', array_keys($ids))
                            ->distinct()
                            ->pluck('article_id')
                            ->all();

        $con_avisos = array_fill_keys(array_map('intval', $con_avisos), true);

        foreach ($avisos as $aviso) {

            $article = $aviso[0];

            if (!isset($con_avisos[(int) $article->id])) {
                continue;
            }

            // checkAdvises() lee el stock actual del modelo: se lo deja en el valor de ESE momento
            // (el stock corrido después del movimiento) y se restaura el final después.
            $stock_final = $article->stock;

            $article->stock = $aviso[2];
            ArticleHelper::checkAdvises($article, $aviso[1]);
            $article->stock = $stock_final;
            $article->syncOriginalAttribute('stock');
        }
    }

    /**
     * 10: Tienda Nube / Mercado Libre, una vez por movimiento, sólo con el env prendido (mismo
     * gate que tienen adentro los dos servicios; se chequea acá para no recorrer nada en vano).
     *
     * @param  array $articulos
     * @return void
     */
    protected function sincronizar_plataformas(array $articulos)
    {
        if (!env('USA_MERCADO_LIBRE', false) && !env('USA_TIENDA_NUBE', false)) {
            return;
        }

        foreach ($articulos as $article) {
            ProductService::add_article_to_sync($article);
            TiendaNubeSyncArticleService::add_article_to_sync($article);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Camino viejo, para lo que esta clase no cubre
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Resuelve un pedido exactamente como lo hacía `ActualizarBBDD` antes de esta clase:
     * `StockMovementController::crear()` por movimiento y `exists()` + `updateExistingPivot()` /
     * `attach()` para min/max.
     *
     * @param  array $pedido
     * @return void
     */
    protected function procesar_a_la_vieja(array $pedido)
    {
        $article = $pedido['article'];

        $data = [];
        $data['concepto_stock_movement_name'] = self::CONCEPTO;
        $data['model_id'] = $article->id;

        if ($pedido['tipo'] === 'global') {

            $amount = $pedido['amount'];

            if (!is_null($pedido['target'])) {

                $stock_actual = (float) $article->fresh()->stock;
                $amount       = $pedido['target'] - $stock_actual;

                if ($amount == 0.0) {
                    return;
                }
            }

            if ((float) $amount == 0.0) {
                return;
            }

            $data['amount'] = $amount;

            $this->stock_movement_ct->crear($data, true, $this->user, $this->auth_user_id);

            return;
        }

        if (!is_null($pedido['amount'])) {
            $data['to_address_id'] = $pedido['address_id'];
            $data['amount']        = $pedido['amount'];
            $this->stock_movement_ct->crear($data, true, $this->user, $this->auth_user_id);
        }

        if (!is_null($pedido['stock_min']) || !is_null($pedido['stock_max'])) {

            if ($article->addresses()->where('address_id', $pedido['address_id'])->exists()) {
                $article->addresses()->updateExistingPivot($pedido['address_id'], [
                    'stock_min' => $pedido['stock_min'],
                    'stock_max' => $pedido['stock_max'],
                ]);
            } else {
                $article->addresses()->attach($pedido['address_id'], [
                    'stock_min' => $pedido['stock_min'],
                    'stock_max' => $pedido['stock_max'],
                ]);
            }
        }
    }
}
