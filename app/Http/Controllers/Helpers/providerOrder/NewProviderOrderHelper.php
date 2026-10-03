<?php

namespace App\Http\Controllers\Helpers\providerOrder;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\article\ArticleProviderDiscountHelper;
use App\Http\Controllers\Helpers\article\precios\RecalculoDePreciosEnLote;
use App\Models\ArticleDiscount;
use App\Http\Controllers\Helpers\article\ArticlePricesHelper;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\caja\MovimientoCajaHelper;
use App\Http\Controllers\Helpers\currentAcount\CuentaCorrienteLock;
use App\Http\Controllers\Stock\StockMovementController;
use App\Models\Article;
use App\Models\ArticleSurchage;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\Iva;
use App\Models\MovimientoCaja;
use App\Models\Provider;
use App\Models\ProviderOrderDiscount;
use App\Models\ProviderOrderExtraCost;
use App\Services\Compras\OfertasDeProveedorService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NewProviderOrderHelper {

    /** Artículos por UPDATE del historial de proveedores (los ids van en el IN del SQL). */
    const ARTICULOS_POR_UPDATE_DEL_HISTORIAL = 1000;

    /**
     * Artículos por consulta en las dos pasadas en bloque del recálculo diferido
     * (materializar_descuentos_en_bloque() y aplicar_recargos_en_bloque()): van en el IN de la
     * consulta de existencia y en el de la de recargos existentes.
     */
    const ARTICULOS_POR_CONSULTA_EN_BLOQUE = 1000;

    /**
     * Filas de article_surchages por sentencia en escribir_recargos_en_bloque(): por INSERT
     * multi-fila (7 columnas por fila, 3.500 placeholders, lejos del tope de 65.535 de MySQL) y por
     * UPDATE ... CASE (el SQL crece con cada WHEN). Mismo valor que
     * RecalculoDePreciosEnLote::FILAS_POR_UPDATE.
     */
    const RECARGOS_POR_SENTENCIA = 500;

    public $provider_order;
    public $new_articles;
    public $ultimos_articulos_recividos;

    /**
     * Interruptor del recálculo de precios de la compra (misión compras-precios-en-lote,
     * 29/9/2026). Con false (el default) el precio de los artículos se recalcula una sola vez, al
     * final de procesar_pedido(), con el motor en lote; con true, cada una de las cuatro llamadas
     * recalcula en el momento, artículo por artículo, como hasta esa misión. Ver
     * recalcular_por_articulo().
     *
     * @var bool
     */
    protected static $recalculo_por_articulo = false;

    /**
     * Artículos cuyo precio quedó pendiente de recalcular en esta compra: [article_id => article_id].
     * La clave evita repetidos: un artículo puede pasar por las cuatro llamadas y se recalcula una
     * sola vez. Lo llena recalcular_precio() y lo vacía recalcular_precios_pendientes().
     *
     * @var array<int, int>
     */
    protected $articulos_con_precio_pendiente = [];

    /**
     * Pares del historial de proveedores ("Precio Final" de article_provider) que SetProvider grabó
     * en esta compra mientras el recálculo estaba diferido, o sea con el precio de ANTES:
     * [article_id => provider_id]. Lo llena save_stock_movement() y lo resuelve
     * actualizar_historial_de_proveedores() después del motor.
     *
     * @var array<int, int>
     */
    protected $historial_de_proveedores_pendiente = [];

	function __construct($provider_order, $new_articles, $ya_se_actualizo_stock = false) {

        $this->provider_order           = $provider_order;
        $this->new_articles             = $new_articles;
        $this->ya_se_actualizo_stock    = $ya_se_actualizo_stock;
        // En un job de cola (importacion de Excel de compras) no hay sesion ni Auth: UserHelper::user() da null.
        // Se cae al dueno de la compra, de quien dependen iva_included, dollar y la condicion fiscal.
        $this->user                     = UserHelper::user() ?: \App\Models\User::find($provider_order->user_id);

        $this->set_credit_account();

        $this->set_ultimos_articulos_recividos();

        $this->set_ivas();
    }

    function procesar_pedido() {

        // $this->attach_articles();

        $this->set_totales();

        // Prompt 306: reemplaza el prorrateo horneado sobre articles.cost (método eliminado
        // aplicar_descuento_compra_a_costo_articulos, prompt 262) por la materialización
        // explícita de los descuentos vigentes de la orden como article_discounts tagueados con
        // el proveedor. Tiene que ir DESPUÉS de set_totales() porque necesita
        // provider_order_discounts ya cargados (set_totales() hace el load()).
        $this->materializar_descuentos_proveedor_en_articulos();

        // Prompt 264: los costos extra tipados de la compra (flete, seguro, arancel de
        // importación) se prorratean entre los artículos y se materializan como recargo
        // (article_surchage) de cada artículo. Corre después de set_totales() porque necesita
        // sub_total y provider_order_extra_costs ya cargados/calculados.
        $this->aplicar_costos_extra_a_recargos_articulos();

        /*
         * Misión compras-precios-en-lote (29/9/2026): el precio de venta de los artículos de la
         * compra se recalcula ACÁ, una sola vez por artículo y con el motor en lote, en vez de en
         * cada una de las cuatro llamadas (update_cost() y update_price() adentro de
         * attach_articles(), y los dos métodos de arriba), que ahora solo anotan el artículo (ver
         * recalcular_precio()).
         *
         * 🔴 El lugar no es arbitrario:
         *  - DESPUÉS de aplicar_costos_extra_a_recargos_articulos(), que era la última llamada:
         *    recién acá están escritos el costo, el precio, el IVA, el proveedor, el estado, los
         *    descuentos tagueados y los recargos de la compra, que es el estado con el que calculaba
         *    la última llamada de cada artículo hasta esta misión.
         *  - ANTES de set_current_acount(), igual que esa última llamada: la compra sigue
         *    escribiendo en el mismo orden de siempre (artículos y precios primero, la deuda con el
         *    proveedor al final).
         *
         * Un llamador que use attach_articles() con `update_prices` y NO pase por procesar_pedido()
         * tiene que llamar a recalcular_precios_pendientes() él mismo, o los costos quedan
         * guardados con los precios de venta sin recalcular, sin ningún error. Hoy no hay ninguno:
         * la sugerencia de compra (PurchaseSuggestionController) va con update_prices = 0 y no anota
         * nada.
         */
        $this->recalcular_precios_pendientes();

        $this->set_current_acount();
    }

    /**
     * Prende (o apaga) el recálculo de precios "de antes": cada una de las cuatro llamadas de la
     * compra (update_cost(), update_price(), materializar_descuentos_proveedor_en_articulos() y
     * aplicar_costos_extra_a_recargos_articulos()) llama a ArticleHelper::setFinalPrice() en el
     * momento, artículo por artículo, exactamente como hasta la misión compras-precios-en-lote
     * (29/9/2026). Con el interruptor prendido tampoco se toca el historial de proveedores: queda
     * lo que graba SetProvider, como antes (ver actualizar_historial_de_proveedores()).
     *
     * Lo usan los tests de equivalencia para correr el camino de antes y el nuevo sobre la misma
     * base y compararlos campo por campo. También sirve como salida de emergencia desde tinker o un
     * comando si el recálculo en lote diera algo raro en producción (el mismo espíritu que
     * PreciosEnLote::deshabilitar()).
     *
     * ⚠️ Es estático: vale para todo el proceso (el request, o el worker entero de la cola) hasta
     * que alguien lo vuelva a apagar. Quien lo prende lo apaga en un finally.
     *
     * @param  bool $prendido true = por artículo (el camino de antes); false = diferido, con el motor.
     * @return void
     */
    public static function recalcular_por_articulo($prendido = true)
    {
        self::$recalculo_por_articulo = (bool) $prendido;
    }

    /**
     * ¿El precio de esta compra se recalcula al final, una sola vez por artículo y con el motor en
     * lote (true), o en cada llamada, artículo por artículo, como hasta la misión
     * compras-precios-en-lote (false)?
     *
     * Se difiere siempre, salvo en dos casos:
     *
     *  - El interruptor recalcular_por_articulo() está prendido.
     *
     *  - 🔴 La compra NO tiene proveedor. Esta excepción no es un descuido y no se "simplifica".
     *    Diferir da el mismo precio que antes solo porque, con proveedor, la llamada #3
     *    (materializar_descuentos_proveedor_en_articulos()) vuelve a recalcular TODOS los artículos
     *    de la compra al final, con el estado completo. Sin proveedor esa llamada no corre: el
     *    precio que queda es el de update_cost()/update_price(), calculado ANTES de que
     *    update_article_provider() le ponga provider_id = null al artículo (o sea con el margen,
     *    el dólar y la lista del proveedor anterior), salvo en los artículos a los que
     *    aplicar_costos_extra_a_recargos_articulos() les prorratea un costo extra, que sí se
     *    recalculan al final. Es una inconsistencia preexistente (plan de la misión, §2), pero
     *    diferir la "arreglaría" en silencio, cambiando precios de venta que hoy quedan de otra
     *    forma, y ese cambio no se decidió. Sin proveedor todo sigue exactamente como antes.
     *
     * El gate es el mismo `is_null()` con el que materializar_descuentos_proveedor_en_articulos()
     * decide si corre, y las cuatro llamadas solo existen con `update_prices`, igual que la #3: todo
     * lo que se difiere es de una compra en la que la #3 recalculaba al final.
     *
     * @return bool
     */
    protected function se_difiere_el_recalculo()
    {
        if (self::$recalculo_por_articulo) {
            return false;
        }

        return !is_null($this->provider_order->provider_id);
    }

    /**
     * Punto único por el que pasan las cuatro llamadas al cálculo de precio de la compra:
     * update_cost() (#1), update_price() (#2), materializar_descuentos_proveedor_en_articulos()
     * (#3) y aplicar_costos_extra_a_recargos_articulos() (#4).
     *
     *  - Diferido (el caso normal, ver se_difiere_el_recalculo()): solo anota el id. El precio lo
     *    recalcula recalcular_precios_pendientes() una sola vez, al final de procesar_pedido().
     *  - Inmediato: ArticleHelper::setFinalPrice($article), exactamente como antes (el mismo
     *    objeto, sin argumentos extra: el dueño lo resuelve setFinalPrice() desde la sesión).
     *
     * 🔴 Por qué diferir no pierde nada: setFinalPrice() no acumula, recalcula el costo real y el
     * precio desde cero con lo que el artículo tiene en ese momento. Con proveedor, la última
     * llamada de cada artículo (la #3, que recorre todos los de la compra, o la #4 si le toca un
     * costo extra) ya calculaba con el estado final completo, y las anteriores solo dejaban cambios
     * de precio intermedios en el historial. El motor lee ese mismo estado final de la base, así
     * que el precio que queda es el mismo, con un solo cambio de precio por artículo y por compra
     * (decisión D1 del plan).
     *
     * ⚠️ Diferido, el objeto en memoria NO se toca: el $article que sigue su camino por
     * update_article() conserva el costo real, el precio final y el `price` de antes (en un
     * artículo con margen, setFinalPrice() le habría puesto `price` en null). No cambia el
     * resultado: los save() posteriores solo escriben lo que quedó sucio, y si update_price()
     * graba el precio del renglón en un artículo con margen, el motor lo vuelve a poner en null,
     * como hacía la llamada #3.
     *
     * @param  \App\Models\Article $article
     * @return void
     */
    protected function recalcular_precio($article)
    {
        if ($this->se_difiere_el_recalculo()) {

            $article_id = (int) $article->id;

            $this->articulos_con_precio_pendiente[$article_id] = $article_id;

            return;
        }

        ArticleHelper::setFinalPrice($article);
    }

    /**
     * Recalcula, una sola vez por artículo y con el motor en lote, el precio de todos los
     * artículos que las llamadas de esta compra dejaron anotados (ver recalcular_precio()), y
     * después deja el "Precio Final" del historial de proveedores con el precio recién calculado
     * (ver actualizar_historial_de_proveedores()).
     *
     * Lo llama procesar_pedido() (el comentario de ahí explica el lugar exacto). 🔴 Un llamador
     * que use attach_articles() con `update_prices` sin pasar por procesar_pedido() tiene que
     * llamarlo al terminar: si no, los costos quedan guardados y los precios de venta sin
     * recalcular, sin ningún error. Hoy no hay ninguno.
     *
     * - RecalculoDePreciosEnLote::recalcular() va con el dueño ($this->user, que el constructor
     *   resuelve con UserHelper::user()) y SIN auth_user_id: el motor resuelve el employee_id de
     *   los price_changes con UserHelper::userId(false), que es lo que hacía
     *   PriceChangeController::store() con null en el camino de antes. O sea, la persona logueada.
     * - Corre adentro de la transacción del llamador (un savepoint) y bloquea los artículos con
     *   lockForUpdate, en orden de id. Si tira, la excepción sube y el llamador revierte la compra
     *   entera, igual que con una falla de antes a mitad de un cálculo.
     * - La lista se vacía ANTES de llamar al motor: si alguien lo llamara dos veces, la segunda no
     *   recalcula de nuevo lo mismo.
     *
     * @return void
     */
    public function recalcular_precios_pendientes()
    {
        if (empty($this->articulos_con_precio_pendiente)) {

            /*
             * Sin precios pendientes no se recalculó ningún precio en esta compra (por ejemplo, con
             * update_prices apagado): lo que SetProvider grabó en el historial de proveedores ya es
             * el precio con el que queda el artículo, y no hay nada que corregir.
             */
            $this->historial_de_proveedores_pendiente = [];

            return;
        }

        $ids = array_values($this->articulos_con_precio_pendiente);

        $this->articulos_con_precio_pendiente = [];

        RecalculoDePreciosEnLote::recalcular($ids, $this->user);

        $this->actualizar_historial_de_proveedores();
    }

    /**
     * Decisión D2 del plan (misión compras-precios-en-lote, 29/9/2026): el "Precio Final" del
     * historial de proveedores (article_provider.price, la columna del modal "Historial de
     * Proveedores y Stock") queda con el precio con el que el artículo SALE de la compra.
     *
     * 🔴 Por qué existe: ese valor no lo escribe el cálculo de precios sino
     * SetProvider::set_provider(), adentro del movimiento de stock (update_stock(), en el medio de
     * attach_articles()), copiando articles.final_price de ESE momento. Antes de esta misión ahí
     * ya estaba el precio de la llamada #1 (update_cost()), que en la compra común es el precio
     * final. Con el recálculo diferido, cuando corre el movimiento el precio todavía no se movió y
     * SetProvider graba el de ANTES de la compra. Por eso, después del motor, se copia el
     * final_price recién calculado a esos mismos pares (artículo, proveedor), y solo a esos: los
     * que SetProvider escribió en esta compra (ver save_stock_movement()). Sin este método el
     * historial queda con precios viejos, sin ningún error.
     *
     * - Un UPDATE ... JOIN por proveedor, en tandas de ARTICULOS_POR_UPDATE_DEL_HISTORIAL ids. La
     *   base convierte el decimal de final_price a la columna int de article_provider igual que
     *   cuando SetProvider le manda el texto: redondeo al entero, mitad hacia afuera del cero
     *   (medido el 29/9/2026 en MySQL 8.3 con el sql_mode estricto de la conexión).
     * - No toca article_provider.updated_at: lo dejó puesto SetProvider en esta misma compra, como
     *   antes.
     * - Los ids van en el SQL como enteros validados; el proveedor va como binding.
     *
     * @return void
     */
    protected function actualizar_historial_de_proveedores()
    {
        if (empty($this->historial_de_proveedores_pendiente)) {
            return;
        }

        // [provider_id => [article_id, ...]]. Una compra tiene un solo proveedor, pero el
        // agrupado no lo da por sentado.
        $articulos_por_proveedor = [];

        foreach ($this->historial_de_proveedores_pendiente as $article_id => $provider_id) {

            $article_id  = (int) $article_id;
            $provider_id = (int) $provider_id;

            if ($article_id > 0 && $provider_id > 0) {
                $articulos_por_proveedor[$provider_id][] = $article_id;
            }
        }

        foreach ($articulos_por_proveedor as $provider_id => $article_ids) {

            foreach (array_chunk($article_ids, self::ARTICULOS_POR_UPDATE_DEL_HISTORIAL) as $tanda) {

                DB::update(
                    'UPDATE `article_provider` AS `ap`'
                    . ' INNER JOIN `articles` AS `a` ON `a`.`id` = `ap`.`article_id`'
                    . ' SET `ap`.`price` = `a`.`final_price`'
                    . ' WHERE `ap`.`provider_id` = ? AND `ap`.`article_id` IN (' . implode(', ', $tanda) . ')',
                    [$provider_id]
                );
            }
        }

        $this->historial_de_proveedores_pendiente = [];
    }

    /**
     * Prompt 262 - Tarea 1: pre-carga las bonificaciones del proveedor (provider_discounts,
     * dato maestro de la negociación con el proveedor) como descuentos EDITABLES de esta orden
     * de compra puntual (provider_order_discounts), al momento de crearla.
     *
     * Racional: desde el prompt 261, la bonificación del proveedor dejó de aplicarse
     * automáticamente al costo de catálogo (Capa 1). Ahora es solo un dato "sugerido" que
     * pre-completa el descuento de la orden de compra concreta, para que el usuario lo vea, lo
     * pueda editar o eliminar antes de confirmar la compra. Recién lo que quede cargado en
     * provider_order_discounts en el momento de confirmar es lo que impacta el costo real, vía
     * aplicar_descuento_compra_a_costo_articulos().
     *
     * No pisa nada si la orden ya trae descuentos propios (por ejemplo, si el usuario ya los
     * mandó explícitamente en el request al crear la orden) — solo pre-completa cuando la orden
     * todavía no tiene ningún provider_order_discount cargado.
     */
    function precargar_bonificaciones_proveedor() {

        // Si la orden ya tiene descuentos propios cargados, no se pisan con los del proveedor.
        if ($this->provider_order->provider_order_discounts()->count() > 0) {
            return;
        }

        $provider = Provider::find($this->provider_order->provider_id);

        if (is_null($provider)) {
            return;
        }

        foreach ($provider->provider_discounts as $provider_discount) {

            // Solo se pre-cargan bonificaciones porcentuales (dato maestro del proveedor,
            // ver ProviderDiscount): si no tiene porcentaje cargado, no hay nada que copiar.
            if (is_null($provider_discount->percentage) || $provider_discount->percentage == '') {
                continue;
            }

            ProviderOrderDiscount::create([
                'description'        => 'Bonificación de proveedor',
                'percentage'         => $provider_discount->percentage,
                'provider_order_id'  => $this->provider_order->id,
            ]);
        }

        // Se vuelve a cargar la relación para que set_totales() (que corre después) vea
        // los descuentos recién creados.
        $this->provider_order->load('provider_order_discounts');
    }

    /**
     * Prompt 306 — Tareas 2/3/6: reemplaza el prorrateo horneado sobre `articles.cost` (método
     * eliminado `aplicar_descuento_compra_a_costo_articulos`, prompt 262) por la materialización
     * explícita de los descuentos vigentes de la orden (`provider_order_discounts`, ya cargados o
     * editados por el usuario) como `article_discounts` tagueados con el proveedor de la orden.
     *
     * Racional: antes el descuento se aplicaba una sola vez, prorrateado, directo sobre
     * `articles.cost` — y el costo bruto original se perdía. Ahora `articles.cost` (seteado por
     * update_cost() con el costo bruto literal del pivot) NUNCA se toca acá; los descuentos
     * quedan como `article_discounts` explícitos, que el pipeline de precios
     * (ArticlePricesHelper::aplicar_descuentos, vía ArticleHelper::aplicar_descuentos_e_iva) aplica
     * UNA sola vez sobre ese costo bruto para dar el `costo_real`. Evita el doble descuento.
     *
     * A diferencia del prorrateo viejo (que ajustaba el costo unitario según el peso de cada
     * artículo en el total de la compra), acá se copian los MISMOS descuentos (porcentaje o
     * monto) tal cual están cargados en la orden a cada artículo — no se prorratean por peso,
     * porque el pipeline de precios ya los aplica individualmente sobre el costo bruto de cada
     * artículo.
     *
     * Semántica overwrite/último costo (delegada en ArticleProviderDiscountHelper): cada
     * confirmación de compra vuelve a pisar los descuentos tagueados del proveedor, no los
     * acumula.
     *
     * Gate: solo corre con `update_prices` ON (mismo criterio que usa update_cost()/update_price()
     * para decidir si la compra actualiza el costo de catálogo) y si la orden tiene proveedor
     * cargado (sin proveedor no hay a qué taguear el descuento).
     */
    function materializar_descuentos_proveedor_en_articulos() {

        if (!$this->provider_order->update_prices) {
            return;
        }

        $provider_id = $this->provider_order->provider_id;

        if (is_null($provider_id)) {
            return;
        }

        // Descuentos vigentes de la orden (ya cargados/editados por el usuario), sean
        // porcentuales o por monto fijo — se materializan tal cual en cada artículo de la compra.
        $discounts = $this->provider_order->provider_order_discounts;

        /*
         * Misión compras-precios-en-lote (§4.4 del plan, 29/9/2026): con el recálculo diferido, esta
         * pasada va en bloque (materializar_descuentos_en_bloque()): lo mismo que el foreach de abajo,
         * con tres consultas para toda la compra en vez de tres por artículo.
         *
         * 🔴 Solo en modo diferido, y el foreach sigue ahí a propósito: con el interruptor
         * recalcular_por_articulo() prendido la compra corre EXACTAMENTE el camino de antes, que es
         * la referencia de los tests de equivalencia. No se "unifica".
         */
        if ($this->se_difiere_el_recalculo()) {

            $this->materializar_descuentos_en_bloque($provider_id, $discounts);

            return;
        }

        foreach ($this->provider_order->articles as $article) {

            $articulo = Article::find($article->id);

            if (is_null($articulo)) {
                continue;
            }

            ArticleProviderDiscountHelper::sync_provider_discounts($articulo, $provider_id, $discounts, ArticleDiscount::ORIGEN_COMPRA);

            Log::info('materializar_descuentos_proveedor_en_articulos: descuentos del proveedor '.$provider_id.' materializados en '.$articulo->name);

            // Recalcula costo_real con el costo bruto (sin hornear) + los descuentos recién
            // materializados, aplicados una sola vez por el pipeline de precios. Acá la compra
            // siempre tiene proveedor, así que el recálculo se difiere al final de
            // procesar_pedido() (salvo con recalcular_por_articulo() prendido): ver
            // recalcular_precio().
            $this->recalcular_precio($articulo);
        }
    }

    /**
     * materializar_descuentos_proveedor_en_articulos() EN BLOQUE, para el recálculo diferido
     * (misión compras-precios-en-lote, §4.4 del plan, 29/9/2026).
     *
     * Hace lo mismo que el foreach por artículo de ese método —Article::find(),
     * ArticleProviderDiscountHelper::sync_provider_discounts() y el pedido de recálculo— con tres
     * consultas para toda la compra en vez de tres por artículo (medido con 1.000 artículos: 3.000
     * consultas): una para saber qué artículos existen, un DELETE de los descuentos tagueados y un
     * INSERT multi-fila con los de la compra. La equivalencia fila por fila (columnas, valores,
     * orden de los ids, artículos repetidos) vive en
     * ArticleProviderDiscountHelper::sync_provider_discounts_en_bloque(): ver su docblock.
     *
     * 🔴 SOLO en modo diferido, y no es un descuido que el foreach de siempre siga en el método de
     * arriba. Con el interruptor recalcular_por_articulo() prendido la compra tiene que correr
     * EXACTAMENTE el camino de antes: es la referencia contra la que comparan los tests de
     * equivalencia, y la salida de emergencia si el camino nuevo diera algo raro. En modo inmediato,
     * además, cada artículo recalcula su precio en el momento, apenas escritos sus descuentos, y ese
     * intercalado es parte del camino de referencia. Por eso este método se niega a correr en modo
     * inmediato.
     *
     * Los artículos se anotan para el recálculo con recalcular_precio(), igual que en el foreach:
     * todos los que existen, y ninguno más. En modo diferido ese método solo lee el id, así que
     * alcanza con el modelo de la relación y no hace falta el Article::find() de a uno.
     *
     * Corre adentro de la transacción del llamador, sin transacción propia: si algo tira, se
     * revierte con la compra, como antes.
     *
     * @param  int      $provider_id  Proveedor de la compra (no nulo: lo garantiza el llamador).
     * @param  iterable $discounts    provider_order_discounts de la compra, los mismos que recibía
     *                                sync_provider_discounts() en el foreach.
     * @return void
     */
    protected function materializar_descuentos_en_bloque($provider_id, $discounts)
    {
        if (!$this->se_difiere_el_recalculo()) {
            throw new \LogicException('NewProviderOrderHelper: la materialización de descuentos en bloque es solo para el recálculo diferido.');
        }

        /*
         * Los ids en el orden en que los recorre el foreach de siempre, CON repetidos si la compra
         * los tiene (article_provider_order no tiene índice único): cuál de las apariciones manda
         * lo decide el helper, igual que lo decidía el DELETE + INSERT por artículo.
         */
        $article_ids = [];

        foreach ($this->provider_order->articles as $article) {
            $article_ids[] = (int) $article->id;
        }

        $existentes = ArticleProviderDiscountHelper::sync_provider_discounts_en_bloque($article_ids, $provider_id, $discounts, ArticleDiscount::ORIGEN_COMPRA);

        // [article_id => posición]: para preguntar rápido si un renglón es de un artículo que existe.
        $existen = array_flip($existentes);

        foreach ($this->provider_order->articles as $article) {

            if (isset($existen[(int) $article->id])) {
                $this->recalcular_precio($article);
            }
        }

        // Un solo log para toda la pasada: el de siempre era uno por artículo, con su nombre.
        Log::info('materializar_descuentos_proveedor_en_articulos (en bloque): descuentos del proveedor '.$provider_id.' materializados en '.count($existentes).' artículo(s) de la compra '.$this->provider_order->id);
    }

    /**
     * Prompt 264 — Tarea 2: prorratea los costos extra "tipados" de la orden de compra
     * (provider_order_extra_costs con `tipo` transporte/seguro/arancel_importacion, ver
     * ProviderOrderExtraCost::TIPO_*) entre los artículos de la compra y materializa el monto
     * prorrateado de cada artículo como un `article_surchage` (App\Models\ArticleSurchage) de
     * ese mismo `tipo`, para que impacte el costo real (Capa 1, vía
     * ArticlePricesHelper::aplicar_recargos()) y participe del cálculo de precio como
     * cualquier recargo cargado a mano.
     *
     * Fórmula de prorrateo por ítem (mismo patrón que aplicar_descuento_compra_a_costo_articulos()
     * y que el prorrateo de bonificaciones del prompt 262):
     *   monto_item = valor_costo_extra * subtotal_item / total_articulos_compra
     * El recargo de artículo (ArticleSurchage->amount) es un valor UNITARIO — se suma directo
     * al precio por unidad en ArticlePricesHelper::aplicar_recargos() — por eso acá se divide el
     * monto prorrateado del ítem por la cantidad comprada (pivot->amount) antes de guardarlo.
     *
     * Criterio "último costo" (confirmado por Lucas, prompt 264): si el artículo YA tiene un
     * recargo del mismo `tipo`, se PISA su `amount` (no se acumula un segundo recargo del mismo
     * tipo). El costo del artículo siempre refleja el flete/seguro/arancel de la ÚLTIMA compra
     * que trajo un costo extra de ese tipo.
     *
     * Nota (decisión documentada, no automatizar): si en una edición de la orden se elimina un
     * extra cost tipado (o se le saca el tipo), el recargo de artículo ya materializado NO se
     * borra automáticamente acá — el criterio último costo asume que una próxima compra con
     * costo extra de ese tipo lo va a pisar, y mientras tanto el usuario lo puede borrar a mano
     * desde el modal del artículo (ArticleSurchageController::destroy).
     *
     * Nota (simetría con precios en blanco, Tarea 3): `article_surchage_blancos` sólo tiene
     * columna `percentage` (no `amount`), no existe un campo unitario equivalente para copiar
     * un recargo por monto fijo como este. Por eso este método NO crea/actualiza recargos en
     * blanco — no hay a dónde propagar un `amount` en esa tabla sin agregar una columna nueva,
     * fuera del alcance de este prompt.
     *
     * Mismo gate que aplicar_descuento_compra_a_costo_articulos(): solo corre si la orden está
     * marcada para actualizar costo/precio de catálogo.
     *
     * 🔴 Suma DENTRO de la compra vs. pisado ENTRE compras (misión
     * `costos-extra-mismo-tipo-se-pisan`, 24/8/2026). Son dos comportamientos distintos y sólo uno
     * era un bug:
     *
     *  - ENTRE compras se PISA, y es DELIBERADO: es el criterio "último costo" del párrafo de
     *    arriba. El recargo de tipo X que dejó una compra anterior lo REEMPLAZA la compra nueva; no
     *    se acumula compra tras compra. Esto no cambió.
     *  - DENTRO de una misma compra se SUMA: si la compra trae dos o más costos extra del mismo
     *    tipo (el caso real y hoy frecuente: "Flete" y "Seguro de carga", los dos con tipo
     *    `transporte`), los dos son costo real de ESA compra y tienen que llegar juntos al artículo.
     *
     * Hasta esta misión, el segundo costo extra del mismo tipo PISABA al primero, porque la
     * asignación `$surchage->amount = $monto_unitario` corría una vez por CADA costo extra: al costo
     * del artículo llegaba solamente el último, en silencio y sin ningún aviso. El caso era casi
     * inalcanzable mientras el default del formulario era `otro` (que no prorratea); dejó de serlo
     * el 22/8/2026, cuando la misión `compras-banderas-y-prorrateo` cambió ese default a
     * `transporte` y cargar dos costos extra sin tocar el tipo pasó a ser el camino natural.
     *
     * Por eso el método ahora PRE-AGREGA por tipo antes de tocar ningún artículo, y después hace UNA
     * sola asignación por (artículo, tipo).
     *
     * 🔴 Idempotencia, que es la trampa de este arreglo: la compra se reconfirma con cada
     * `PUT api/provider-order/{id}`. Lo que se guarda sigue siendo una ASIGNACIÓN, nunca un `+=`
     * sobre el `amount` que ya está en la base. El monto es siempre función de la orden, no del
     * estado previo del artículo, así que correr el método N veces seguidas da siempre el mismo
     * número. Si alguna vez alguien lo reescribe acumulando sobre `$surchage->amount`, la segunda
     * confirmación duplica el recargo.
     *
     * ⚠️ El back-out de IVA (bloque "Prompt 516") es POR COSTO EXTRA, no por tipo: dos costos extra
     * del mismo tipo pueden tener alícuotas distintas, o uno venir facturado y el otro no. Por eso
     * la agregación suma los valores DESPUÉS del back-out individual de cada uno, nunca antes.
     *
     * El recálculo del precio (`recalcular_precio()`) se pide UNA vez por artículo, después de
     * guardar todos sus recargos (antes era una vez por artículo por costo extra). Y con proveedor,
     * desde la misión `compras-precios-en-lote` (29/9/2026), ni siquiera se calcula acá: se anota y
     * lo hace recalcular_precios_pendientes() al final de procesar_pedido(), una vez por artículo
     * para toda la compra. Da lo mismo porque `ArticleHelper::setFinalPrice()` no acumula:
     * recalcula `costo_real`/`final_price` desde cero leyendo `articles.cost` + la relación
     * `article_surchages` completa, así que la última llamada subsume a todas las anteriores.
     */
    function aplicar_costos_extra_a_recargos_articulos() {

        if (!$this->provider_order->update_prices) {
            return;
        }

        // Tipos de costo extra que se materializan como recargo de artículo (mismo enum que
        // ArticleSurchage, prompt 260). NULL o TIPO_OTRO: comportamiento actual, solo suman al
        // total de la orden.
        $tipos_materializables = [
            ProviderOrderExtraCost::TIPO_TRANSPORTE,
            ProviderOrderExtraCost::TIPO_SEGURO,
            ProviderOrderExtraCost::TIPO_ARANCEL_IMPORTACION,
        ];

        // Total de artículos de la compra (ya calculado por set_totales(), corre antes en
        // procesar_pedido()), usado como base del prorrateo.
        $total_articulos = (float)$this->provider_order->sub_total;

        if ($total_articulos <= 0) {
            return;
        }

        /*
         * PASO 1 — agregación por tipo.
         *
         * Se recorren los costos extra una sola vez, se netea cada uno con SU propia alícuota (ver
         * el bloque "Prompt 516" más abajo) y recién ahí se acumula en el mapa
         * `tipo => monto a prorratear`. Sumar antes del back-out sería un error: dos costos extra
         * del mismo tipo pueden tener alícuotas distintas, o uno venir facturado y el otro no.
         *
         * Este paso NO toca la base: es una función pura de provider_order_extra_costs. Es lo que
         * permite que el paso 2 siga siendo una asignación única por (artículo, tipo) —y por lo
         * tanto idempotente— sin perder ningún costo extra por el camino.
         */
        $valor_por_tipo   = [];
        $detalle_por_tipo = [];

        foreach ($this->provider_order->provider_order_extra_costs as $extra_cost) {

            if (!in_array($extra_cost->tipo, $tipos_materializables)) {
                continue;
            }

            $valor_costo_extra = (float)$extra_cost->value;

            if ($valor_costo_extra <= 0) {
                continue;
            }

            /*
             * Prompt 516: consistencia neto/bruto al costo (Capa 1), mismo criterio que el back-out
             * de costo del prompt 514. Si el costo extra vino FACTURADO con alícuota propia
             * (`iva_id`) y la cuenta RECUPERA el IVA (Responsable Inscripto), el IVA de ese costo
             * extra es crédito fiscal y no costo: se prorratea el NETO, sacándole el IVA con SU
             * propia alícuota, no la de los artículos. Si la cuenta NO lo recupera
             * (Monotributista), o el costo extra no está facturado, se prorratea el monto entero.
             *
             * 🔴 La pregunta acá es SI la cuenta recupera el IVA, así que el predicado correcto es
             * `el_iva_participa_del_precio()`, NO `iva_va_al_costo()`.
             *
             * Hasta la misión `iva-fuera-del-costeo-monotributista` (21/8/2026) los dos daban lo
             * mismo y este call site usaba el segundo. Cuando esa misión hizo que
             * `iva_va_al_costo()` devolviera false para TODA cuenta migrada —porque pasó a
             * responder sólo DÓNDE se suma el IVA, no si se suma—, este `if` dejó de distinguir
             * nada y empezó a prorratear el neto también para el Monotributista.
             *
             * Medido por el checker antes de que llegara a `develop`: en una compra de un MT con un
             * flete facturado de $1.210 al 21%, al costo llegaban $1.000 en vez de $1.210 — el
             * costo unitario bajaba $21 y el precio final $29,40 (−1,87%). Esos $210 son IVA que el
             * monotributista pagó y no recupera: su costo es 1.210. Era la regla de la misión
             * aplicada a los artículos y no al flete de la misma compra.
             */
            if ($extra_cost->facturado && (int)$extra_cost->iva_id > 0 && ArticlePricesHelper::el_iva_participa_del_precio($this->user)) {

                $iva_costo_extra = $this->get_iva($extra_cost->iva_id);

                if (!is_null($iva_costo_extra)) {

                    // Prompt 609: alícuota numérica segura (is_numeric antes de castear), por si
                    // el costo extra viene facturado con un IVA 'Exento'/'No Gravado' (texto).
                    $alicuota_costo_extra = $this->get_alicuota_numerica($iva_costo_extra);

                    if ($alicuota_costo_extra > 0) {
                        // Back-out "final -> neto": neto = bruto / (1 + alicuota/100).
                        $valor_costo_extra = $valor_costo_extra / (1 + ($alicuota_costo_extra / 100));
                    }
                }
            }

            if (!isset($valor_por_tipo[$extra_cost->tipo])) {
                $valor_por_tipo[$extra_cost->tipo]   = 0;
                $detalle_por_tipo[$extra_cost->tipo] = [];
            }

            $valor_por_tipo[$extra_cost->tipo] += $valor_costo_extra;

            /*
             * El detalle lleva el neto Y el bruto a propósito. Esta línea es la traza de
             * diagnóstico de este método, y el valor que se prorratea es el NETO: quien la lea
             * mañana va a estar mirando en pantalla el bruto que cargó el usuario ($1.210), y si el
             * log dijera sólo "Flete (1000)" no le cerraría con nada.
             */
            $detalle_por_tipo[$extra_cost->tipo][] = $extra_cost->description.' (neto '.$valor_costo_extra.' de '.$extra_cost->value.')';
        }

        if (count($valor_por_tipo) == 0) {
            return;
        }

        // Una línea por tipo con el total agregado y de qué costos extra salió. Es la traza que
        // faltaba: hasta esta misión, dos costos extra del mismo tipo se pisaban y el log no lo
        // dejaba ver.
        foreach ($valor_por_tipo as $tipo => $valor_total_tipo) {

            Log::info('aplicar_costos_extra_a_recargos_articulos: tipo '.$tipo.' -> '.$valor_total_tipo.' a prorratear, suma de '.count($detalle_por_tipo[$tipo]).' costo(s) extra de esta compra: '.implode(' + ', $detalle_por_tipo[$tipo]));
        }

        /*
         * Misión compras-precios-en-lote (§4.4 del plan, 29/9/2026): con el recálculo diferido, el
         * PASO 2 va en bloque (aplicar_recargos_en_bloque()): las mismas cuentas y las mismas
         * escrituras que el foreach de abajo, con un puñado de consultas para toda la compra en vez
         * de cuatro por artículo. El PASO 1 (la agregación por tipo) es el mismo para los dos.
         *
         * 🔴 Solo en modo diferido, y el foreach de abajo sigue ahí a propósito: con el interruptor
         * recalcular_por_articulo() prendido, o en una compra sin proveedor, el PASO 2 corre
         * EXACTAMENTE como antes, porque es el camino de referencia de los tests de equivalencia y
         * porque ahí cada artículo recalcula su precio en el momento. No se "unifica".
         */
        if ($this->se_difiere_el_recalculo()) {

            $this->aplicar_recargos_en_bloque($valor_por_tipo, $total_articulos);

            return;
        }

        /*
         * PASO 2 — un solo pase por artículo, y adentro un pase por tipo.
         *
         * El subtotal y la cantidad del ítem se calculan UNA vez por artículo (antes se
         * recalculaban por cada costo extra), y el recálculo del precio se pide UNA vez por
         * artículo (recalcular_precio()), después de guardar todos sus recargos.
         */
        foreach ($this->provider_order->articles as $article) {

            // Subtotal de este ítem, calculado con la misma lógica que usa set_totales()
            // para sumar total_articulos (get_total_article), así el prorrateo es
            // consistente con la base usada.
            $res            = $this->get_total_article($article);
            $subtotal_item  = (float)$res['sub_total_article'];

            if ($subtotal_item <= 0) {
                continue;
            }

            // Cantidad comprada del ítem (Prompt 609: recibida si está completada -incluido
            // 0-, sino pedida), para llevar el monto prorrateado del ítem a un valor unitario.
            $cantidad = $this->get_cantidad_efectiva($article);

            if ($cantidad <= 0) {
                continue;
            }

            $articulo = Article::find($article->id);

            if (is_null($articulo)) {
                continue;
            }

            foreach ($valor_por_tipo as $tipo => $valor_total_tipo) {

                if ($valor_total_tipo <= 0) {
                    continue;
                }

                $monto_prorrateado_item = $valor_total_tipo * $subtotal_item / $total_articulos;
                $monto_unitario         = $monto_prorrateado_item / $cantidad;

                /*
                 * Criterio último costo (ENTRE compras): busca un recargo existente del mismo tipo
                 * en el artículo para pisar su amount; si no existe, lo crea.
                 *
                 * 🔴 Sigue siendo una ASIGNACIÓN y no un `+=`, y eso NO es un descuido: lo que hay
                 * que sumar (los costos extra del mismo tipo de ESTA compra) ya se sumó en el paso
                 * 1. Acumular acá sobre lo que hay en la base rompería las dos cosas a la vez — el
                 * pisado entre compras y la idempotencia frente a la reconfirmación del PUT.
                 */
                $surchage = ArticleSurchage::where('article_id', $articulo->id)
                                            ->where('tipo', $tipo)
                                            ->first();

                if (is_null($surchage)) {
                    $surchage                          = new ArticleSurchage();
                    $surchage->article_id              = $articulo->id;
                    $surchage->tipo                     = $tipo;
                    $surchage->luego_del_precio_final   = 0;
                }

                // Es un recargo por monto fijo (unitario), no por porcentaje.
                $surchage->amount      = $monto_unitario;
                $surchage->percentage  = null;
                $surchage->save();

                Log::info('aplicar_costos_extra_a_recargos_articulos: recargo '.$tipo.' de '.$articulo->name.' seteado en '.$monto_unitario.' (total del tipo en esta compra: '.$valor_total_tipo.')');
            }

            /*
             * Los recargos se acaban de crear/actualizar por query, después de que `Article::find()`
             * trajera el modelo: se refresca la relación explícitamente para que, cuando el
             * recálculo es inmediato (compra sin proveedor, o recalcular_por_articulo() prendido),
             * setFinalPrice() -> ArticlePricesHelper::aplicar_recargos() calcule con los recargos
             * nuevos y no con una relación cacheada. Diferido, el motor relee el artículo y sus
             * recargos de la base al final de procesar_pedido().
             *
             * Un solo pedido de recálculo por artículo alcanza aunque haya varios tipos:
             * setFinalPrice() no acumula, recalcula el costo real desde cero iterando TODOS los
             * article_surchages del artículo, así que la última llamada subsume a las que antes se
             * hacían por cada costo extra.
             */
            $articulo->load('article_surchages');

            $this->recalcular_precio($articulo);
        }
    }

    /**
     * El PASO 2 de aplicar_costos_extra_a_recargos_articulos() EN BLOQUE, para el recálculo diferido
     * (misión compras-precios-en-lote, §4.4 del plan, 29/9/2026).
     *
     * El PASO 2 por artículo hace, por cada renglón que pasa los saltos, un Article::find(); por
     * cada tipo, un ArticleSurchage::where(...)->first() y un save(); y al final un
     * load('article_surchages') y el pedido de recálculo. Medido con 1.000 artículos: 4.000
     * consultas. Acá, para toda la compra: una consulta de existencia, una de recargos existentes
     * por TIPO de la compra (ver recargos_existentes()), un INSERT multi-fila de los nuevos y un
     * UPDATE en bloque por conjunto de columnas de los que cambian, cada cosa en tandas acotadas.
     *
     * 🔴 EL INVARIANTE: article_surchages queda IDÉNTICA a como la deja el PASO 2 por artículo
     * (mismas filas, columnas y valores, created_at y updated_at incluidos) y se anotan para el
     * recálculo los mismos artículos. Para eso:
     *
     *  - Mismos saltos, en el mismo orden: subtotal <= 0 y cantidad <= 0 (con get_total_article() y
     *    get_cantidad_efectiva(), las mismas funciones), el artículo que no existe, y
     *    valor_total_tipo <= 0.
     *  - Mismas cuentas: el monto sale de la MISMA expresión, con las operaciones en el mismo orden.
     *    Es un float y tiene que dar bit a bit igual: no se "simplifica" ni se reordena.
     *  - 🔴 Qué se escribe lo decide EL MODELO, no una comparación a mano. Se asignan amount y
     *    percentage sobre los mismos modelos Eloquent —los existentes hidratados de la base con la
     *    misma consulta que first(), los nuevos con new ArticleSurchage() y los mismos tres atributos
     *    de siempre— y se le pregunta isDirty(), que es exactamente lo que decide save(). No es un
     *    detalle: amount es DOUBLE, vuelve de la base con los 14 dígitos que le mandó PDO, y Eloquent
     *    lo compara contra el float nuevo como texto con la precisión de PHP. Un recargo con el
     *    mismo monto NO queda sucio y save() no emite nada, así que su updated_at viejo tiene que
     *    quedar como está. Una comparación a mano (==, round(), un épsilon) daría otra respuesta en
     *    algún borde y tocaría updated_at de más o de menos.
     *      · nuevo → una fila del INSERT multi-fila: lo que manda save() al insertar, con
     *        created_at y updated_at;
     *      · existente con algo sucio → getDirty() más updated_at, en un UPDATE en bloque;
     *      · existente sin nada sucio → nada, como save().
     *  - Los nuevos se insertan en el orden en que los crea el PASO 2 por artículo (artículo por
     *    artículo y, adentro, en el orden de los tipos): los ids salen en el mismo orden relativo, y
     *    eso importa porque la relación article_surchages va por id y el cálculo aplica los recargos
     *    en ese orden.
     *  - Un artículo repetido en la compra (article_provider_order no tiene índice único) pasa dos
     *    veces, como en el foreach, sobre el MISMO modelo: después de cada "save" que escribiría se
     *    sincroniza el original (syncOriginal(), lo que hace save() al terminar), así la segunda
     *    pasada decide contra lo que dejó la primera, igual que hoy cuando vuelve a leer la fila.
     *  - Sin el load('article_surchages'): servía para que setFinalPrice() calculara en el momento
     *    con los recargos nuevos. En modo diferido el motor relee el artículo y sus recargos de la
     *    base al final de procesar_pedido().
     *  - Sin transacción propia: corre adentro de la del llamador, y si algo tira se revierte con la
     *    compra, como antes.
     *
     * 🔴 SOLO en modo diferido (se niega a correr en modo inmediato): el PASO 2 por artículo es la
     * referencia de los tests de equivalencia y el camino de la compra sin proveedor. Ver
     * materializar_descuentos_en_bloque().
     *
     * @param  array $valor_por_tipo   [tipo => monto neto a prorratear], el del PASO 1, en su orden.
     * @param  float $total_articulos  sub_total de la compra: la base del prorrateo (mayor a 0).
     * @return void
     */
    protected function aplicar_recargos_en_bloque(array $valor_por_tipo, $total_articulos)
    {
        if (!$this->se_difiere_el_recalculo()) {
            throw new \LogicException('NewProviderOrderHelper: los recargos en bloque son solo para el recálculo diferido.');
        }

        /*
         * 1. Los renglones que pasan los dos primeros saltos del PASO 2 por artículo, en su orden,
         *    con las mismas funciones. El subtotal y la cantidad quedan guardados para la cuenta.
         */
        $renglones  = [];
        $candidatos = [];

        foreach ($this->provider_order->articles as $article) {

            $res            = $this->get_total_article($article);
            $subtotal_item  = (float)$res['sub_total_article'];

            if ($subtotal_item <= 0) {
                continue;
            }

            $cantidad = $this->get_cantidad_efectiva($article);

            if ($cantidad <= 0) {
                continue;
            }

            $renglones[] = [
                'article'       => $article,
                'subtotal_item' => $subtotal_item,
                'cantidad'      => $cantidad,
            ];

            $candidatos[(int) $article->id] = (int) $article->id;
        }

        if (count($renglones) === 0) {
            return;
        }

        /* 2. El Article::find() de cada renglón, para todos juntos: cuáles existen. */
        $existen = $this->articulos_que_existen(array_values($candidatos));

        /* 3. Los tipos que se escriben: el mismo salto de valor_total_tipo <= 0. */
        $tipos = [];

        foreach ($valor_por_tipo as $tipo => $valor_total_tipo) {

            if ($valor_total_tipo > 0) {
                $tipos[] = $tipo;
            }
        }

        /* 4. Lo que devolvería cada ArticleSurchage::where(...)->first() del PASO 2 por artículo. */
        $existentes = $this->recargos_existentes(array_values($existen), $tipos);

        /*
         * 5. Las asignaciones del PASO 2 por artículo, sobre los mismos modelos y en el mismo orden.
         *
         *    $modelos:  [clave => ArticleSurchage], uno por (artículo, tipo).
         *    $nuevos:   claves de los que el foreach insertaría, en el orden en que los crearía.
         *    $sucios:   [clave => [columna => true]], lo que escribiría algún save() de un existente.
         *    $anotados: [article_id => true], los que se anotaron para el recálculo (para el log).
         */
        $modelos  = [];
        $nuevos   = [];
        $sucios   = [];
        $anotados = [];

        foreach ($renglones as $renglon) {

            $article    = $renglon['article'];
            $article_id = (int) $article->id;

            if (!isset($existen[$article_id])) {
                continue;
            }

            foreach ($valor_por_tipo as $tipo => $valor_total_tipo) {

                if ($valor_total_tipo <= 0) {
                    continue;
                }

                // 🔴 La MISMA expresión que el PASO 2 por artículo, con las operaciones en el mismo
                // orden: el float tiene que dar bit a bit igual.
                $monto_prorrateado_item = $valor_total_tipo * $renglon['subtotal_item'] / $total_articulos;
                $monto_unitario         = $monto_prorrateado_item / $renglon['cantidad'];

                $clave = $article_id.'|'.$tipo;

                if (!isset($modelos[$clave])) {

                    if (isset($existentes[$article_id][$tipo])) {

                        $modelos[$clave] = $existentes[$article_id][$tipo];

                    } else {

                        // Los mismos tres atributos con los que lo crea el PASO 2 por artículo.
                        $surchage                           = new ArticleSurchage();
                        $surchage->article_id               = $article_id;
                        $surchage->tipo                     = $tipo;
                        $surchage->luego_del_precio_final   = 0;

                        $modelos[$clave] = $surchage;
                        $nuevos[]        = $clave;
                    }
                }

                $surchage = $modelos[$clave];

                // Es un recargo por monto fijo (unitario), no por porcentaje: las mismas dos
                // asignaciones del PASO 2 por artículo (una ASIGNACIÓN, nunca un +=).
                $surchage->amount      = $monto_unitario;
                $surchage->percentage  = null;

                /*
                 * Lo que decidiría save() sobre un existente: con algo sucio, escribe esas columnas
                 * (más updated_at) y deja el original sincronizado; sin nada sucio, no hace nada.
                 * Sobre uno nuevo no hay nada que decidir todavía: se inserta al final con los
                 * valores que tenga (si el artículo se repite, los de su última pasada, que es lo que
                 * queda hoy después del INSERT y el UPDATE).
                 */
                if ($surchage->exists && $surchage->isDirty()) {

                    foreach (array_keys($surchage->getDirty()) as $columna) {
                        $sucios[$clave][$columna] = true;
                    }

                    $surchage->syncOriginal();
                }
            }

            // Donde el PASO 2 por artículo pide el recálculo: los renglones que pasaron los saltos.
            // En modo diferido recalcular_precio() solo anota el id.
            $this->recalcular_precio($article);

            $anotados[$article_id] = true;
        }

        /* 6. Las escrituras. */
        $escritos = $this->escribir_recargos_en_bloque($modelos, $nuevos, $sucios);

        // Un solo log para toda la pasada: el de siempre era uno por artículo y por tipo.
        Log::info('aplicar_costos_extra_a_recargos_articulos (en bloque): compra '.$this->provider_order->id.', '.count($anotados).' artículo(s), tipos '.implode(', ', $tipos).': '.$escritos['insertados'].' recargo(s) nuevo(s), '.$escritos['actualizados'].' actualizado(s), '.(count($modelos) - count($nuevos) - count($sucios)).' sin cambios');
    }

    /**
     * [article_id => article_id] de los artículos de $ids que existen: lo que decide, de a uno, el
     * Article::find() del PASO 2 por artículo, en una consulta cada ARTICULOS_POR_CONSULTA_EN_BLOQUE
     * ids. toBase() aplica los scopes globales, así que el borrado lógico (SoftDeletes) queda afuera,
     * igual que en Article::find().
     *
     * @param  int[] $ids
     * @return array
     */
    private function articulos_que_existen(array $ids)
    {
        $existen = [];

        foreach (array_chunk($ids, self::ARTICULOS_POR_CONSULTA_EN_BLOQUE) as $lote) {

            foreach (Article::whereIn('id', $lote)->toBase()->pluck('id') as $id) {
                $existen[(int) $id] = (int) $id;
            }
        }

        return $existen;
    }

    /**
     * Lo que devolvería, para cada (artículo, tipo), el ArticleSurchage::where('article_id', ...)
     * ->where('tipo', ...)->first() del PASO 2 por artículo: [article_id => [tipo => ArticleSurchage]],
     * modelos hidratados por Eloquent como los hidrata esa consulta.
     *
     * 🔴 El de MENOR id, y por eso el orderBy('id') y el "me quedo con el primero". first() no tiene
     * ORDER BY, pero va por el índice article_surchages_article_id_idx (EXPLAIN del 29/9/2026: type
     * ref, key article_surchages_article_id_idx), y en InnoDB las entradas de un índice secundario
     * con el mismo article_id están ordenadas por la clave primaria; si el optimizador eligiera
     * recorrer la tabla, también sería en orden de id. Si un artículo tiene dos recargos del mismo
     * tipo, se pisa el de menor id y el otro queda como está, igual que hoy.
     *
     * 🔴 UNA consulta por TIPO de la compra (de una a tres, nunca por artículo) y no un
     * whereIn('tipo', ...) para todos, a propósito. La columna tipo es utf8mb4_unicode_ci: el
     * where('tipo', ...) de hoy también encuentra un recargo cargado como 'Transporte' o
     * 'transporte ' (no distingue mayúsculas, acentos ni espacios al final). Con un solo whereIn
     * habría que decidir en PHP a qué tipo de la compra corresponde cada fila, que es reimplementar
     * la collation; con una consulta por tipo lo decide MySQL, con el mismo predicado que first().
     *
     * @param  int[]    $article_ids  Artículos que existen.
     * @param  string[] $tipos        Tipos de la compra con monto a prorratear.
     * @return array
     */
    private function recargos_existentes(array $article_ids, array $tipos)
    {
        $recargos = [];

        if (count($article_ids) === 0) {
            return $recargos;
        }

        foreach ($tipos as $tipo) {

            foreach (array_chunk($article_ids, self::ARTICULOS_POR_CONSULTA_EN_BLOQUE) as $lote) {

                $filas = ArticleSurchage::whereIn('article_id', $lote)
                                        ->where('tipo', $tipo)
                                        ->orderBy('id')
                                        ->get();

                foreach ($filas as $surchage) {

                    $article_id = (int) $surchage->article_id;

                    if (!isset($recargos[$article_id][$tipo])) {
                        $recargos[$article_id][$tipo] = $surchage;
                    }
                }
            }
        }

        return $recargos;
    }

    /**
     * Las escrituras de aplicar_recargos_en_bloque(), con los valores de los modelos.
     *
     * - INSERT de los nuevos: getAttributes() —exactamente las columnas que manda save() al insertar
     *   uno nuevo: article_id, tipo, luego_del_precio_final, amount y percentage— más created_at y
     *   updated_at, que save() le pone a todo modelo nuevo. En el orden de $nuevos (el de los ids),
     *   en tandas de RECARGOS_POR_SENTENCIA filas y agrupados por conjunto de columnas:
     *   Builder::insert() arma la lista de columnas con la primera fila, y aunque hoy son siempre las
     *   mismas, el agrupado hace que eso no se pueda romper en silencio.
     * - UPDATE de los existentes con algo sucio: las columnas que alguna pasada dejó sucias, con el
     *   valor final del modelo, más updated_at (lo que agrega save() al actualizar). Agrupado por
     *   conjunto de columnas, como RecalculoDePreciosEnLote::actualizar_articulos(): cada fila lleva
     *   exactamente las suyas, `col = CASE id WHEN ... THEN ? ... ELSE col END`, o `col = ?` si todas
     *   las filas del grupo llevan el mismo valor (updated_at, y percentage en null).
     * - Los valores van como bindings, igual que los manda save(): un float sale como texto con la
     *   precisión de PHP y la base guarda el mismo DOUBLE (medido el 29/9/2026 contra el UPDATE y el
     *   INSERT de a uno, con 310 valores: idénticos). Los ids van en el SQL como enteros; los nombres
     *   de columna salen de atributos que asigna este mismo código, y se validan igual.
     * - created_at / updated_at: un solo instante para toda la pasada, con freshTimestampString()
     *   del modelo (el formato de save()). Con el reloj congelado de los tests es el mismo valor que
     *   el de antes; en la vida real, save() tomaba uno por fila, segundos más o menos.
     *
     * @param  array $modelos  [clave => ArticleSurchage]
     * @param  array $nuevos   Claves de $modelos a insertar, en orden.
     * @param  array $sucios   [clave => [columna => true]] de los existentes a actualizar.
     * @return array ['insertados' => int, 'actualizados' => int]
     */
    private function escribir_recargos_en_bloque(array $modelos, array $nuevos, array $sucios)
    {
        $escritos = [
            'insertados'   => 0,
            'actualizados' => 0,
        ];

        if (count($nuevos) === 0 && count($sucios) === 0) {
            return $escritos;
        }

        $ahora = (new ArticleSurchage())->freshTimestampString();

        /* INSERT de los nuevos: [firma de columnas => filas], cada grupo en el orden de $nuevos. */
        $inserts = [];

        foreach ($nuevos as $clave) {

            $modelo = $modelos[$clave];

            $fila = $modelo->getAttributes();

            if ($modelo->usesTimestamps()) {
                $fila[$modelo->getCreatedAtColumn()] = $ahora;
                $fila[$modelo->getUpdatedAtColumn()] = $ahora;
            }

            ksort($fila);

            $inserts[implode(',', array_keys($fila))][] = $fila;
        }

        foreach ($inserts as $filas) {

            foreach (array_chunk($filas, self::RECARGOS_POR_SENTENCIA) as $lote) {

                DB::table('article_surchages')->insert($lote);

                $escritos['insertados'] += count($lote);
            }
        }

        /* UPDATE de los existentes con algo sucio: [firma de columnas => [id => [columna => valor]]]. */
        $grupos = [];

        foreach ($sucios as $clave => $columnas) {

            $modelo    = $modelos[$clave];
            $atributos = $modelo->getAttributes();
            $valores   = [];

            foreach (array_keys($columnas) as $columna) {
                $valores[$columna] = $atributos[$columna];
            }

            if ($modelo->usesTimestamps()) {
                $valores[$modelo->getUpdatedAtColumn()] = $ahora;
            }

            ksort($valores);

            $grupos[implode(',', array_keys($valores))][(int) $modelo->getKey()] = $valores;
        }

        foreach ($grupos as $firma => $filas_del_grupo) {

            $nombres = explode(',', $firma);

            foreach ($nombres as $columna) {

                // Van en el SQL: solo un identificador limpio.
                if (!preg_match('/^[A-Za-z0-9_]+$/', $columna)) {
                    throw new \RuntimeException('NewProviderOrderHelper: columna inválida para el UPDATE de recargos: '.$columna);
                }
            }

            foreach (array_chunk($filas_del_grupo, self::RECARGOS_POR_SENTENCIA, true) as $tanda) {

                $sets     = [];
                $bindings = [];

                foreach ($nombres as $columna) {

                    if ($this->mismo_valor_en_todas_las_filas($tanda, $columna)) {

                        $primera    = reset($tanda);
                        $sets[]     = '`'.$columna.'` = ?';
                        $bindings[] = $primera[$columna];

                        continue;
                    }

                    $whens = [];

                    foreach ($tanda as $id => $valores) {
                        $whens[]    = 'WHEN '.(int) $id.' THEN ?';
                        $bindings[] = $valores[$columna];
                    }

                    $sets[] = '`'.$columna.'` = CASE `id` '.implode(' ', $whens).' ELSE `'.$columna.'` END';
                }

                $ids_sql = [];

                foreach (array_keys($tanda) as $id) {
                    $ids_sql[] = (int) $id;
                }

                DB::update(
                    'UPDATE `article_surchages` SET '.implode(', ', $sets).' WHERE `id` IN ('.implode(', ', $ids_sql).')',
                    $bindings
                );

                $escritos['actualizados'] += count($tanda);
            }
        }

        return $escritos;
    }

    /**
     * ¿Todas las filas de la tanda traen el mismo valor (idéntico, ===) para esta columna? Entonces
     * va como `col = ?` en vez de un CASE (ver escribir_recargos_en_bloque()).
     *
     * @param  array  $tanda    [id => [columna => valor]]
     * @param  string $columna
     * @return bool
     */
    private function mismo_valor_en_todas_las_filas(array $tanda, $columna)
    {
        $primero = true;
        $valor   = null;

        foreach ($tanda as $valores) {

            if ($primero) {
                $valor   = $valores[$columna];
                $primero = false;
                continue;
            }

            if ($valores[$columna] !== $valor) {
                return false;
            }
        }

        return true;
    }

    function set_credit_account() {
        $this->credit_account = CreditAccount::where('model_name', 'provider')
                                                ->where('model_id', $this->provider_order->provider_id)
                                                ->where('moneda_id', $this->provider_order->moneda_id)
                                                ->first();
    }


    /*
        * Si total_from_provider_order_afip_tickets = TRUE
            1. Se calcula $total en base las provider_order_afip_ticket->total
            2. 


        * Si total_from_provider_order_afip_tickets = FALSE
            1. Se calculo $total_articulos en base a los articulos sin tener en cuenta el IVA de cada articulo.
            2. 

    */
    function set_totales() {

        $total_articulos = 0;
        $descuentos_individuales = 0;
        $descuentos_compra = 0;
        $total_descuento = 0;
        $total_costos_extra = 0;
        $total_iva = 0;
        $total = 0;

        $this->provider_order->load([
            'articles',
            'provider_order_afip_tickets',
            'provider_order_discounts',
            'provider_order_extra_costs',
            'provider', // porque en get_total_article lo usás para dólar
        ]);


        $des = [];


        $des[] = 'TOTAL ARTICULOS';
        foreach ($this->provider_order->articles as $article) {

            $res                = $this->get_total_article($article);
            $sub_total_article  = $res['sub_total_article'];
            // $total_article      = $res['total_article'];
            $article_descuento  = $res['total_descuento'];
            // $article_iva        = $res['article_iva']['importe_iva'];

            $total_articulos += $sub_total_article;
            $descuentos_individuales += $article_descuento;

            Log::info('Sumando '.$sub_total_article.' de '.$article->name);
            Log::info('Descuentos de articulo '.$article_descuento);

            $des[] = Numbers::price($sub_total_article, true).' x '.$article->pivot->amount.' u. de '.$article->name;
        }

        if ($total_articulos > 0) {
            if ($descuentos_individuales > 0) {

                $des[] = 'Total articulos (sin descuentos) = '.Numbers::price($total_articulos, true);
            } else {
                $des[] = 'Total articulos = '.Numbers::price($total_articulos, true);
            }
        }

        if ($descuentos_individuales > 0) {

            $des[] = Numbers::price($descuentos_individuales, true).' de descuentos individuales en articulos';  
        }


        if ($this->provider_order->total_from_provider_order_afip_tickets) {

            Log::info('Sumando total de las facturas');

            $des[] = 'CALCULANDO TOTAL EN BASE A FACTURAS';

            foreach ($this->provider_order->provider_order_afip_tickets as $afip_ticket) {
                $total      += $afip_ticket->total;
                $des[] = Numbers::price($afip_ticket->total, true).' de factura N° '.$afip_ticket->code;
            }

            $des[] = 'Total pedido = '.Numbers::price($total, true);
        } else {
            $total += $total_articulos;
        }



        /*
            Sumando IVA que va a venir siempre de los provider_order_afip_tickets
            Estos van a ser creados de forma manual o automatica en base a "modo_facturacion"
            De eso se encarga ModoFacturacionHelper
        */

        if (count($this->provider_order->provider_order_afip_tickets) >= 1) {
            $des[] = 'CALCULANDO IVA EN BASE A FACTURAS';
        }
        foreach ($this->provider_order->provider_order_afip_tickets as $afip_ticket) {

            $total_iva  += $afip_ticket->total_iva;
            $des[] = Numbers::price($afip_ticket->total_iva, true).' de IVA de factura N° '.$afip_ticket->code;
        }
        if ($total_iva > 0) {
            $des[] =  'Total IVA = '.Numbers::price($total_iva, true);
        }


        $this->provider_order->total_iva            = $total_iva;
        $this->provider_order->sub_total            = $total_articulos;


        // Descuentos del pedido

        $total_solo_con_descuentos_individuales = $total_articulos - $descuentos_individuales;

        if (count($this->provider_order->provider_order_discounts) >= 1) {
            $des[] = 'CALCULANDO DESCUENTOS DE COMPRA';
            $des[] = 'Total articulos = '.Numbers::price($total_articulos, true);

            if ($descuentos_individuales > 0) {
                $des[] = 'Descuentos individuales = '.Numbers::price($descuentos_individuales, true);
                $des[] = 'Total articulos con desc aplicado = '.Numbers::price($total_solo_con_descuentos_individuales, true);
            }
        }

        foreach ($this->provider_order->provider_order_discounts as $discount) {

            if (
                !is_null($discount->percentage)
                && $discount->percentage != ''
            ) {

                $monto_descuento = $total_solo_con_descuentos_individuales * (float)$discount->percentage / 100;
                $des[] = 'Menos el '.$discount->percentage.'% de '.Numbers::price($total_solo_con_descuentos_individuales, true).' = '.Numbers::price($monto_descuento, true);
            } else if (
                !is_null($discount->monto)
                && $discount->monto != ''
            ) {

                $monto_descuento = $discount->monto;
                $des[] = 'Menos '.Numbers::price($discount->monto, true).' = '.Numbers::price($monto_descuento, true);
                Log::info('menos $'.$discount->monto);
            }


            $descuentos_compra += $monto_descuento;
            $total_solo_con_descuentos_individuales -= $monto_descuento;

            $des[] = 'Descuentos Compra = '.Numbers::price($descuentos_compra, true);


            // Log::info('monto_descuento = '.$monto_descuento);
            // Log::info('total_descuento = '.$total_descuento);
        }

        // if ($total_descuento > 0) {
        //     $des[] = 'Total articulos con descuentos aplicados = '.Numbers::price($total_)
        // }

        $total_descuento = $descuentos_individuales + $descuentos_compra;
        
        $this->provider_order->descuentos_individuales    = $descuentos_individuales;
        $this->provider_order->descuentos_compra          = $descuentos_compra;
        $this->provider_order->total_descuento            = $total_descuento;

        if (!$this->provider_order->total_from_provider_order_afip_tickets) {

            $total_sin_descuento = $total;
            $total -= $total_descuento;

            $des[] = 'APLICANDO DESCUENTOS DE COMPRA';
            $des[] = 'Total articulos sin descuentos = '.Numbers::price($total_sin_descuento, true);
            $des[] = 'Descuento individuales = '.Numbers::price($descuentos_individuales, true);
            $des[] = 'Descuentos de compra = '.Numbers::price($descuentos_compra, true);
            $des[] = 'Total Descuentos = '.Numbers::price($total_descuento, true);
            $des[] = 'Total con descuentos aplicado = '.Numbers::price($total, true);
        }


        if (count($this->provider_order->provider_order_extra_costs) >= 1) {
            $des[] = 'CALCULANDO COSTOS EXTRAS';
        }
        foreach ($this->provider_order->provider_order_extra_costs as $extra_cost) {
            $total_costos_extra += (float)$extra_cost->value;
            $des[] = 'Sumando '.Numbers::price($extra_cost->value, true).' de '.$extra_cost->description;
            $des[] = 'Costos extras en '.Numbers::price($total_costos_extra, true);
        }

        if ($total_costos_extra > 0) {
            $total_sin_costo_extra = $total;
            $total += $total_costos_extra;

            $des[] = 'APLICANDO COSTOS EXTRAS';
            $des[] = 'Total en '.Numbers::price($total_sin_costo_extra, true).' mas '.Numbers::price($total_costos_extra, true).' de costos extra = '.Numbers::price($total, true);
        }

        $this->provider_order->total_costos_extra            = $total_costos_extra;


        // Prompt 609 - Tarea 5: un Monotributista no suma el IVA por encima del total (ya está
        // "adentro" de lo que tipeó/pagó en cada línea, no se recupera aparte como crédito fiscal).
        // La columna de IVA de la compra sigue calculándose y mostrándose (prompt 611), solo se deja
        // de sumar acá.
        //
        // Prompt 614: mismo razonamiento aplica para un Responsable Inscripto cuando la orden trae
        // `precios_incluyen_iva` ON: en ese caso `$total_articulos` (y por lo tanto `$total`, que
        // arranca en `$total_articulos`) YA es el precio final tipeado con IVA incluido — el IVA
        // no es un crédito fiscal a sumar aparte sobre ese número, ya está "adentro". Sumar
        // `$total_iva` encima duplicaba el IVA (bug real detectado al automatizar el costeo con
        // PHPUnit: una compra de 1210 x 10 con `precios_incluyen_iva=1` daba total 14200 en vez de
        // los 12100 que efectivamente cuestan esos artículos, la misma plata que tipeada neta). Con
        // el flag OFF (comportamiento de siempre) no cambia nada: el costo tipeado es neto y el IVA
        // sí hay que sumarlo aparte para llegar al total final.
        if ($this->provider_order->total_with_iva && $this->get_condicion_iva_precios() != 'MT' && !$this->provider_order->precios_incluyen_iva) {

            $total_sin_iva = $total;

            $total += $total_iva;

            $des[] = 'APLICANDO IVA';
            $des[] = 'Total en '.Numbers::price($total_sin_iva, true).' mas '.Numbers::price($total_iva, true).' de IVA = '.Numbers::price($total, true);
        }

        $des[] = 'TOTAL FINAL';
        $des[] = 'Total final = '.Numbers::price($total, true);


        $this->provider_order->total                = $total;
        $this->provider_order->price_description    = json_encode($des);

        $this->provider_order->save();



    }

    /**
     * Determina la cantidad real a usar para stock y total de la compra: usa `received`
     * (Cant Recibida) SOLO si fue completado manualmente -incluye 0 como dato real, "llegaron
     * 0 unidades"-. Si vino vacío o null, usa `amount` (Cantidad pedida).
     *
     * IMPORTANTE: no filtrar por `> 0` (bug detectado 20/7/2026, ver refactor_empresa/compras.md
     * en el repo de contexto) - eso ignoraba un 0 cargado a mano y caía a `amount`, sumando stock
     * que en realidad no llegó.
     *
     * @param mixed $amount   Cantidad pedida (pivot->amount), siempre debería venir cargada.
     * @param mixed $received Cantidad recibida (pivot->received): null, '' o un número (incluido 0).
     * @return mixed la cantidad real a usar para stock/total.
     */
    function interpretar_cantidad_real($amount, $received) {

        if (is_null($received) || $received === '') {
            return $amount;
        }

        return $received;
    }

    function get_total_article($article) {

        $cost = (float)($article->pivot->cost);
        $sub_total_article = 0;
        $total_article = 0;
        $total_descuento = 0;
        $article_iva = [
            'iva_id'        => 0,
            'importe_iva'   => 0,
        ];
        
        if (
            $article->pivot->cost_in_dollars
            && $this->provider_order->moneda_id == 1
        ) {

            $valor_dolar = $this->user->dollar;

            if (
                !is_null($this->provider_order->provider) 
                && !is_null($this->provider_order->provider->dolar) 
                && (float)$this->provider_order->provider->dolar > 0) {

                $valor_dolar = $this->provider_order->provider->dolar;

            }

            $cost *= $valor_dolar;
        }

        // Prompt 609 - Tarea 6: cantidad recibida si está completada (incluido 0), sino pedida.
        // get_cantidad_efectiva() delega en interpretar_cantidad_real() (misma semantica que develop).
        $cantidad_efectiva = $this->get_cantidad_efectiva($article);

        $total_article = $cost * $cantidad_efectiva;

        if (
            (
                $total_article == 0
                || is_null($total_article)
            )
            && $article->pivot->price
        ) {
            $total_article = (float)$article->pivot->price * $cantidad_efectiva;
        }

        if (!is_null($article->presentacion)) {
            $total_article *= $article->presentacion;
        }


        $sub_total_article = $total_article;

        if (!is_null($article->pivot->discount)) {

            $descuento = $total_article * (float)$article->pivot->discount / 100;
            
            $total_descuento += $descuento;

            $total_article -= $descuento;
        }


        if (
            !$this->user->iva_included
            && !is_null($article->pivot->iva_id)
            && $article->pivot->iva_id != 0) {

            $iva = $this->get_iva($article->pivot->iva_id);

            if (!is_null($iva)) {

                // Prompt 609: alícuota numérica segura (is_numeric antes de castear).
                $importe_iva = $total_article * $this->get_alicuota_numerica($iva) / 100;

                $article_iva['iva_id']      = $iva->id;
                $article_iva['neto']        = $total_article;
                $article_iva['importe_iva'] = $importe_iva;

            } else {
                Log::info('No se encontro el iva_id: '.$article->pivot->iva_id);
            }

        }

        return [
            'total_article'     => $total_article,
            'sub_total_article' => $sub_total_article,
            'article_iva'       => $article_iva,
            'total_descuento'   => $total_descuento,
        ];
    }

    function get_iva($iva_id) {

        $iva = null;

        foreach ($this->ivas as $_iva) {

            if ($_iva->id == $iva_id) {

                $iva = $_iva;
            }
        }

        return $iva;
    }

    /**
     * Prompt 609 — Tarea 1: devuelve la alícuota NUMÉRICA de un IVA, tratando explícitamente como 0
     * los valores no numéricos que puede tener `ivas.percentage` ('Exento', 'No Gravado', guardados
     * como texto). No confiar en el casteo implícito de PHP: `(float)'Exento'` da 0 "por casualidad",
     * pero no queremos depender de eso — se valida con is_numeric() antes de castear.
     *
     * @param  \App\Models\Iva|null $iva
     * @return float Alícuota numérica (0 si el IVA es null, no tiene percentage, o percentage no es
     *               numérico).
     */
    private function get_alicuota_numerica($iva) {

        if (is_null($iva) || !isset($iva->percentage)) {
            return 0;
        }

        if (!is_numeric($iva->percentage)) {
            // 'Exento' y 'No Gravado' se guardan como texto en la columna percentage.
            return 0;
        }

        return (float)$iva->percentage;
    }

    /**
     * Prompt 609 — Tarea 2: resuelve la condición de IVA para costeo (`'RRII'` o `'MT'`) de la
     * cuenta dueña de esta compra, leyendo `condicion_iva_precios` desde el usuario.
     *
     * Grupo 231, prompt 01: `condicion_iva_precios` se movió de `UserConfiguration` a `User`.
     * Si no hay usuario, se asume `'RRII'` — comportamiento actual del sistema (costos netos, IVA
     * sumado al final), opción conservadora. Este método sigue usando el literal `'MT'` (no la
     * constante `User::CONDICION_MT`), consistente con el resto del archivo.
     *
     * @return string 'RRII' o 'MT'.
     *
     * Nota (Prompt 610): pasa de `private` a `public` porque `ModoFacturacionHelper::calcular_iva()`
     * necesita la misma condición para decidir qué muestra la factura automática (neto+desglose de
     * IVA para RRII, solo total para MT) — se reusa este único punto de verdad en vez de duplicar
     * la lectura de `condicion_iva_precios` en otra clase.
     */
    function get_condicion_iva_precios() {

        if (is_null($this->user)) {
            return 'RRII';
        }

        if ($this->user->condicion_iva_precios == 'MT') {
            return 'MT';
        }

        return 'RRII';
    }

    /**
     * Prompt 609 — Tarea 6: cantidad efectiva a usar en los cálculos de costeo/totales de un
     * artículo de la compra.
     *
     * Usa la cantidad RECIBIDA siempre que esté completada (incluido el caso `0`: "pedí 10, recibí
     * 0"), y cae a la cantidad PEDIDA (`pivot->amount`) solo cuando la recibida esté vacía/null (el
     * usuario no la completó porque asumió que llegó todo). Se chequea con isset()/is_null(), NO con
     * un chequeo de falsy, porque `0` es falsy en PHP y confundirlo con "no cargada" es justo el bug
     * a evitar acá.
     *
     * @param  \App\Models\Article $article Artículo de la compra, con su pivot ya cargado.
     * @return float
     */
    private function get_cantidad_efectiva($article) {

        // Merge develop -> refractor: se delega en interpretar_cantidad_real() para que exista una
        // sola fuente de verdad. Ademas de is_null, esa funcion trata '' como "no recibido"
        // (caso que la version anterior de este metodo casteaba a 0).
        $received = isset($article->pivot->received) ? $article->pivot->received : null;

        return (float)$this->interpretar_cantidad_real($article->pivot->amount, $received);
    }

    function set_current_acount() {

        if ($this->provider_order->generate_current_acount) {

            /*
             * Candado de la cuenta corriente del proveedor (misión cuenta-corriente-carrera-y-velocidad,
             * 23/9/2026). El alta y la edición de la compra ya lo tomaron al abrir su transacción y acá
             * es gratis; va igual en el punto por el que pasa toda compra que escribe la cuenta, para
             * que un camino nuevo nazca cubierto. Ver CuentaCorrienteLock.
             */
            CuentaCorrienteLock::bloquear('provider', $this->provider_order->provider_id);

            $current_acount = CurrentAcount::where('provider_order_id', $this->provider_order->id)
                                            ->first();

            if (is_null($current_acount)) {

                $current_acount = $this->crear_current_acount();
            } else {

                $cambio_moneda = $this->check_cambio_moneda();

                if ($cambio_moneda) {

                    $current_acount = $this->crear_current_acount();
                    
                } else {

                    $current_acount = $this->actualizar_current_acount($current_acount);
                }

            }

            CurrentAcountHelper::check_saldos_y_pagos($this->credit_account->id);
        }

    }

    function actualizar_current_acount($current_acount) {


        $current_acount->debe = $this->provider_order->total;

        $saldo = CurrentAcountHelper::getSaldo($this->credit_account->id, $current_acount) + $this->provider_order->total;

        $current_acount->saldo = $saldo;

        $current_acount->detalle = $this->provider_order->detalle_current_acount();

        $current_acount->save();

        return $current_acount;
    }


    /*
        Si en este punto, filtrando ademas por credit_account_id, 
        no encuentra current_acount, es porque el current_acount que se encontro
        antes pertenece a otra credit_account.

        Entonces busco current_acount sin filtrar por credit_account y la elimino 
    */
    function check_cambio_moneda() {
            
        $current_acount = CurrentAcount::where('provider_order_id', $this->provider_order->id)
                                        ->where('credit_account_id', $this->credit_account->id)
                                        ->first();

        if (!$current_acount) {

            $current_acount = CurrentAcount::where('provider_order_id', $this->provider_order->id)
                                            ->first();

            $credit_account_id = $current_acount->credit_account_id;
            $current_acount->delete();

            CurrentAcountHelper::check_saldos_y_pagos($credit_account_id);

            return true;
        }

        return false;
    }

    function crear_current_acount() {

        $current_acount = CurrentAcount::create([
            'detalle'           => $this->provider_order->detalle_current_acount(),
            'debe'              => $this->provider_order->total,
            'status'            => 'sin_pagar',
            'user_id'           => UserHelper::userId(),
            'provider_id'       => $this->provider_order->provider_id,
            'provider_order_id' => $this->provider_order->id,
            'credit_account_id' => $this->credit_account->id,
        ]);

        $saldo = CurrentAcountHelper::getSaldo($this->credit_account->id, $current_acount) + $this->provider_order->total;

        $current_acount->saldo = $saldo;

        $current_acount->save();

        return $current_acount;
    }

    function set_ivas() {
        $this->ivas = Iva::all();
    }

    function attach_articles(bool $overwrite_articles = false) {

        $syncData = [];

        foreach ($this->new_articles as $new_article) {
            
            // Solo seteamos en la pivot las props cuyo valor venga distinto de `null`,
            // para evitar sobrescribir columnas con `null` cuando se actualizan artículos.
            $pivotData = [];
            $pivotKeys = [
                'cost',
                'amount',
                'received',
                'price',
                'discount',
                'notes',
                'iva_id',
                'cost_in_dollars',
                'amount_pedida',
                'update_provider',
            ];

            foreach ($pivotKeys as $pivotKey) {
                $value = GeneralHelper::getPivotValue($new_article, $pivotKey);

                // 'received' vacío (string '') significa "no se completó el campo" (o se borró en una
                // edición): siempre se graba como NULL explícito, nunca como 0 ni se deja el valor viejo
                // sin tocar. Ver bug 20/7/2026, refactor_empresa/compras.md.
                if ($pivotKey == 'received' && $value === '') {
                    $pivotData[$pivotKey] = null;
                    continue;
                }

                if (!is_null($value)) {
                    $pivotData[$pivotKey] = $value;
                }
            }

            $syncData[$new_article['id']] = $pivotData;
        }

        if ($overwrite_articles) {
            $this->provider_order->articles()->sync($syncData);
        } else {
            $this->provider_order->articles()->syncWithoutDetaching($syncData);
        }

        foreach ($this->new_articles as $new_article) {
            $this->update_article($new_article);
        }
    }

    function update_article($new_article) {

        $article = Article::find($new_article['id']);

        if (!is_null($article)) {

            $article = $this->update_iva($article, $new_article);
            
            if ($this->provider_order->update_prices) {

                Log::info('update_prices');

                $article = $this->update_cost($article, $new_article);

                $article = $this->update_price($article, $new_article);

                // Prompt 306 — Tarea 5: catalogar el costo bruto de este proveedor en
                // article_provider (dato para la futura recomendación de a qué proveedor conviene
                // comprar).
                $this->catalogar_costo_proveedor($article, $new_article);
            }

            // Si el articulo esta inacive, se actualiza la info de bar_code y demas
            $article = $this->update_article_data($article, $new_article);
            
            $article = $this->check_article_status($article, $new_article);

            if ($this->provider_order->update_stock) {

                $article = $this->update_stock($article, $new_article);
                
            }

            $this->update_article_provider($article, $new_article);

            $article->save();
        }
    }

    /**
     * Prompt 306 — Tarea 4: linkea el artículo al proveedor de la compra.
     *
     * Desde este prompt, cuando la orden tiene `update_prices` ON (el flujo principal,
     * "actualizar precios" tildado), el artículo pasa a pertenecer al proveedor de ESTA compra sin
     * importar el flag `update_provider` del pivot — con `update_prices` ON el artículo ya está
     * recibiendo el costo bruto (update_cost()) y los descuentos materializados (article_discounts
     * tagueados, ver materializar_descuentos_proveedor_en_articulos()) de este mismo proveedor, así
     * que su `provider_id` debe quedar consistente con esos datos.
     *
     * Con `update_prices` OFF se conserva el comportamiento previo (anterior a este prompt): el
     * proveedor solo se linkea si el usuario tildó explícitamente `update_provider` en el pivot de
     * este artículo puntual.
     */
    function update_article_provider($article, $new_article) {

        $tilda_update_provider = isset($new_article['pivot']['update_provider']) && (bool)$new_article['pivot']['update_provider'];

        if ($this->provider_order->update_prices || $tilda_update_provider) {

            $article->provider_id = $this->provider_order->provider_id;
            $article->timestamps = false;
            $article->save();
        }

    }

    /**
     * Prompt 306 — Tarea 5: catalogar el costo bruto del proveedor de la compra en
     * `article_provider` (relación Article::providers()), reusando el mismo patrón de upsert que
     * ProcessRow::update_provider_relation() (import de artículos) y SetProvider::set_provider()
     * (movimiento de stock).
     *
     * Alimenta la futura recomendación de "a qué proveedor conviene comprar": guarda el costo
     * NETO (mismo valor que setea update_cost() luego del back-out de IVA del prompt 514, sin
     * descuentos aplicados) más el `provider_code`, sin tocar la relación del artículo con otros
     * proveedores. Se normaliza acá también para que la comparación de costo entre proveedores sea
     * homogénea (todos netos), sin importar si cada proveedor factura con IVA incluido o no.
     *
     * Gate: solo se llama con `update_prices` ON (ver update_article()).
     */
    function catalogar_costo_proveedor($article, $new_article) {

        $provider_id = $this->provider_order->provider_id;

        if (is_null($provider_id)) {
            return;
        }

        // Costo bruto literal cargado en el pivot de esta compra (mismo valor que usa update_cost()
        // antes del back-out).
        $cost = (isset($new_article['pivot']['cost']) && $new_article['pivot']['cost'] != '')
            ? $new_article['pivot']['cost']
            : null;

        if (is_null($cost)) {
            return;
        }

        // Prompt 514: mismo back-out que update_cost() — si la orden trae precios con IVA incluido,
        // se guarda el costo NETO también en article_provider, para mantener la convención
        // "articles.cost / article_provider.cost siempre netos" y no inflar la comparación entre
        // proveedores.
        //
        /*
         * Misión `costo-bruto-por-condicion-fiscal` (20/8/2026): `precios_incluyen_iva` de la compra
         * es el equivalente exacto de los dos inputs del ABM — declara si los costos que la persona
         * está cargando traen el IVA adentro o ya vienen netos. Es la ÚNICA fuente de esa decisión.
         *
         * 🔴 Para Monotributista el flag NO se mira, y eso conserva el prompt 609 y el 615: todo lo
         * que carga un MT es bruto por definición, así que el flag ni se le muestra. Si el costeo
         * dependiera de él, un MT que no lo tilda guardaría su costo 21% abajo sin que nada lo
         * denuncie. El resolvedor único es el que sabe esto.
         */
        if (ArticlePricesHelper::el_costo_cargado_es_bruto($this->user, $this->provider_order->precios_incluyen_iva)) {
            $cost = ArticlePricesHelper::back_out_iva($article, $cost);
        }

        $pivot_data = [
            'cost' => $cost,
        ];

        // provider_code es opcional: solo se propaga si vino informado en el artículo de la compra.
        if (isset($new_article['provider_code']) && $new_article['provider_code'] != '') {
            $pivot_data['provider_code'] = $new_article['provider_code'];
        }

        $existe_relacion = $article->providers()
                                    ->where('provider_id', $provider_id)
                                    ->exists();

        if ($existe_relacion) {

            $article->providers()->updateExistingPivot($provider_id, $pivot_data);
        } else {

            $article->providers()->attach($provider_id, $pivot_data);
        }

        try {
            // Arreglo post-chequeo (A1): moneda REAL de este costo, para que el
            // histórico no lo asuma "en pesos" por default. $cost (arriba) es el
            // valor LITERAL tipeado en la línea -- nunca se convierte acá, se
            // sigue guardando tal cual en article_provider -- pero ese literal
            // puede estar en dólares. get_total_article() (~:608-611) muestra la
            // regla real del sistema: el flag cost_in_dollars del PIVOT DE LA
            // LÍNEA marca que ESE costo puntual se tipeó en dólares aunque el
            // resto de la orden esté en pesos, y ahí se convierte recién AL
            // TOTALIZAR (nunca antes). Y si la orden ENTERA está en dólares
            // (provider_order->moneda_id == 2), cualquier costo tipeado ya se
            // interpreta directo en esa moneda sin que haga falta tildar
            // cost_in_dollars por línea (el if de get_total_article() solo
            // convierte cuando moneda_id == 1: con moneda_id == 2 nunca entra,
            // porque ahí el costo ya está en la moneda de la orden). Sin este
            // dato, catalogar_costo_proveedor() nunca mandaba moneda_id y
            // registrar_lote() completaba con el default (1 = Peso): una compra
            // de USD 10 quedaba anotada como oferta de $10, y ese proveedor
            // ganaba "el más barato" por ~1000x en la corrida siguiente del
            // motor de sugerencias.
            $moneda_id_de_la_oferta = (
                !empty($new_article['pivot']['cost_in_dollars'])
                || (int) $this->provider_order->moneda_id === 2
            ) ? 2 : 1;

            // El costo de una compra REAL es el dato más confiable del histórico: ya viene
            // neto (back-out de IVA arriba) y con proveedor y fecha ciertos.
            OfertasDeProveedorService::registrar_lote(
                [$article->id => [$provider_id => [
                    'cost' => $cost,
                    'provider_code' => isset($pivot_data['provider_code']) ? $pivot_data['provider_code'] : null,
                    'moneda_id' => $moneda_id_de_la_oferta,
                ]]],
                (int) $this->provider_order->user_id,
                'compra',
                $this->provider_order->id
            );
        } catch (\Throwable $e) {
            // Igual que en la importación: el histórico de precios ofertados es un extra,
            // su falla nunca puede voltear la carga de la compra.
            Log::warning('NewProviderOrderHelper: no se pudo registrar el histórico de precios ofertados', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    function update_article_data($article, $new_article) {

        if ($article->status == 'inactive') {

            $article->bar_code          = $new_article['bar_code'];
            $article->provider_code     = $new_article['provider_code'];
            $article->save();

        }

        return $article;
    }

    function check_article_status($article, $new_article) {

        if (
            $article->status == 'inactive' 
            && $this->provider_order->update_stock
            && $new_article['pivot']['amount'] > 0
        ) {

            $article->status = 'active';
            $article->apply_provider_percentage_gain = 1;
            $article->created_at = Carbon::now();
        }

        return $article;
    }

    function update_stock($article, $new_article) {

        $article = Self::save_stock_movement($article, $new_article);

        return $article;
    }

    function save_stock_movement($article, $new_article) {

        // Cantidad real a mover en stock: `received` si fue completado manualmente (incluido 0),
        // si no `amount` (Cantidad pedida). Ver interpretar_cantidad_real().
        $received = isset($new_article['pivot']['received']) ? $new_article['pivot']['received'] : null;

        $amount = $this->interpretar_cantidad_real($new_article['pivot']['amount'], $received);

        if ($amount != '' 
            && !is_null($amount)
            && $amount > 0) {

            Log::info('*****************');
            Log::info('save_stock_movement para '.$article->name);
        
            if (is_null($article->stock)) {
                $article->stock = 0;
                $article->save();
            }

            $ct_stock_movement = new StockMovementController();

            Log::info('amount '.$amount);

            $se_esta_actualizando = false;

            if (isset($this->ultimos_articulos_recividos[$article->id])) {
                $se_esta_actualizando = true;
                Log::info('antes habia '.$this->ultimos_articulos_recividos[$article->id]);
                $amount -= $this->ultimos_articulos_recividos[$article->id];
                Log::info('amount quedo en: '.$amount);
            }

            if ($amount != 0) {

                $data = [];

                $data['model_id'] = $article->id;

                if (!is_null($this->provider_order->address_id)
                    && $this->provider_order->address_id != 0
                    && (
                        count($article->addresses) >= 1 
                        || $article->stock == 0
                        || is_null($article->stock)
                    )
                ) {

                    $data['to_address_id'] = $this->provider_order->address_id;
                } 

                if (!$new_article['pivot']['update_provider']) {

                    $data['not_save_provider'] = true;
                } 

                $data['amount'] = $amount;

                $data['provider_id'] = $this->provider_order->provider_id;

                $data['provider_order_id'] = $this->provider_order->id;

                if ($se_esta_actualizando) {

                    $data['concepto_stock_movement_name'] = 'Act Compra a proveedor';
                } else {

                    $data['concepto_stock_movement_name'] = 'Compra a proveedor';
                }
                

                $movimiento = $ct_stock_movement->crear($data);

                /*
                 * Decisión D2 (misión compras-precios-en-lote, 29/9/2026): SetProvider acaba de
                 * grabar en el historial de proveedores (article_provider.price) el final_price de
                 * ESTE momento, y con el recálculo diferido ese es el precio de ANTES de la compra.
                 * Se anota el par para que actualizar_historial_de_proveedores() le copie el precio
                 * final después del motor. La condición es la misma con la que SetProvider escribe
                 * el pivot (el movimiento tiene proveedor y ese proveedor existe): un par que
                 * SetProvider no tocó, no se toca.
                 */
                if (
                    $this->se_difiere_el_recalculo()
                    && !is_null($movimiento)
                    && !is_null($movimiento->provider_id)
                    && !is_null($movimiento->provider)
                ) {
                    $this->historial_de_proveedores_pendiente[(int) $article->id] = (int) $movimiento->provider_id;
                }
            }

        }

        return $article;
    }

    function update_iva($article, $new_article) {

        if (
            isset($new_article['pivot']['iva_id'])
            && !is_null($new_article['pivot']['iva_id']) 
            && $new_article['pivot']['iva_id'] != 0 
            && $article->iva_id != $new_article['pivot']['iva_id']
        ) {

            $article->iva_id = $new_article['pivot']['iva_id'];

            Log::info('update iva con: '.$article->iva_id);
        } else {
            Log::info('No se actualizo iva');

        }

        return $article;
    }

    function update_price($article, $new_article) {

        $price = null;

        if (isset($new_article['pivot']['price']) 
            && !is_null($new_article['pivot']['price'])) {

            $price = (float)$new_article['pivot']['price'];
        }

        if (!is_null($price)
            && $article->price != $price) {

            $article->price = $price;
            $article->save();

            Log::info('update_price');

            // Con proveedor se anota y se recalcula al final de procesar_pedido(): ver
            // recalcular_precio().
            $this->recalcular_precio($article);
        }

        return $article;
    }

    function update_cost($article, $new_article) {

        $cost = null;

        if (isset($new_article['pivot']['cost'])
            && $new_article['pivot']['cost'] != '') {

            $cost = $new_article['pivot']['cost'];
        }

        // Prompt 514: `articles.cost` es SIEMPRE neto (sin IVA), por convención del sistema. Si la
        // orden de compra tiene `precios_incluyen_iva` ON, el costo que llega en el pivot viene con
        // IVA incluido (precio de lista del proveedor) y hay que sacárselo ANTES de guardarlo, con
        // la alícuota propia del artículo (ArticlePricesHelper::back_out_iva). Con el flag OFF (o
        // sin cargar) el comportamiento queda idéntico al de siempre: se guarda el valor tal cual.
        //
        /*
         * Misión `costo-bruto-por-condicion-fiscal` (20/8/2026): mismo criterio, misma fuente única
         * que catalogar_costo_proveedor(). Que las dos escrituras de la compra usen exactamente la
         * misma expresión no es cosmético: si divergieran, `article_provider.cost` y `articles.cost`
         * quedarían con convenciones distintas para el mismo artículo.
         */
        if (!is_null($cost)
            && ArticlePricesHelper::el_costo_cargado_es_bruto($this->user, $this->provider_order->precios_incluyen_iva)
        ) {

            $cost = ArticlePricesHelper::back_out_iva($article, $cost);
        }

        $cost_cambio = !is_null($cost) && $article->cost != $cost;

        if ($cost_cambio) {

            $article->cost = $cost;

            if (
                isset($new_article['pivot'])
                && isset($new_article['pivot']['cost_in_dollars'])
            ) {

                $article->cost_in_dollars = $new_article['pivot']['cost_in_dollars'];
            }
        }

        if ($cost_cambio) {

            $article->save();
        }

        if ($cost_cambio) {

            Log::info('update_cost con '. $article->cost);

            // Con proveedor se anota y se recalcula al final de procesar_pedido(): ver
            // recalcular_precio().
            $this->recalcular_precio($article);
        }

        return $article;
    }
	
    function set_ultimos_articulos_recividos() {

        $this->ultimos_articulos_recividos = [];

        if ($this->ya_se_actualizo_stock) {

            foreach ($this->provider_order->articles as $article) {

                // Este método corre en el constructor, ANTES de attach_articles(): acá el pivot
                // todavía refleja el valor VIEJO (de un guardado anterior). Se usa el mismo
                // criterio (received completado -incluido 0- manda, si no amount) para calcular
                // correctamente la base del delta de stock en ediciones.
                $received_previo = isset($article->pivot->received) ? $article->pivot->received : null;
                $this->ultimos_articulos_recividos[$article->id] = $this->interpretar_cantidad_real(
                    $article->pivot->amount,
                    $received_previo
                );
            }
        }

    }
}