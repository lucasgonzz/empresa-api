<?php

namespace Tests\Feature\Sales;

use App\Models\Article;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 🔴 PARIDAD: la guarda de orden de `set_costo_ventas` cuenta EXACTAMENTE las líneas que
 * `sale:sanear-costo-de-linea` corrige (misión saneo-ganancia-ventas, defecto 2 del chequeo de
 * merge, 17/9/2026).
 *
 * ─── Por qué esto es un bloqueante y no una prolijidad ────────────────────────────────────────
 *
 * `set_costo_ventas` está publicado en el admin con `is_required=1` y `run_manually=0`: corre solo
 * en el upgrade de cualquier cliente que venga de la versión 1.0.1. Su guarda se niega a correr
 * mientras el cliente tenga histórico roto sin sanear, y le imprime la secuencia para destrabarse:
 * dry-run → revisar → `--aplicar` → volver a correr.
 *
 * Si la guarda frena por una línea que el saneo después **se niega a tocar**, esa secuencia NO
 * destraba nada. Al cliente le quedan dos salidas y las dos son malas: `--force`, que destruye el
 * histórico que todavía era reparable, o quedarse trabado para siempre.
 *
 * Hasta el 17/9/2026 el criterio estaba escrito DOS veces —en SQL adentro de la guarda y en PHP
 * adentro del saneo— y el PHPDoc afirmaba que eran "exactamente el mismo". Era falso: la guarda era
 * una condición **necesaria** y el saneo aplicaba descartes adicionales después. Cada uno de esos
 * descartes es un cliente trabado, y cada uno tiene su test acá:
 *
 * | Test | La línea | El criterio VIEJO de la guarda | El saneo |
 * |---|---|---|---|
 * | 1 | regalo (`price = 0`) | la contaba | descarta: `firma_del_comando_sin_precio_para_acotar_k` |
 * | 2 | artículo borrado en duro | la contaba (no joineaba `articles`) | ni la mira: INNER JOIN |
 * | 3 | corrió el comando 5+ veces (sin `k` coherente) | la contaba | descarta: `firma_del_comando_sin_k_coherente` |
 * | 4 | `article_sale.unidades_individuales` histórico distinto | la contaba | descarta: `pivot_con_unidades_...` |
 *
 * Cada uno de esos cuatro tests verifica las dos mitades: que la línea **sí** cumple el criterio
 * viejo —o sea que el test estaría rojo contra el código anterior, no pasando por casualidad— y que
 * la guarda de hoy **ya no** la cuenta.
 *
 * El test 5 es el guard del guard: una línea genuinamente rota SIGUE frenando el comando. Sin él,
 * los cuatro anteriores se podrían satisfacer rompiendo la guarda entera.
 *
 * Y el test 6 es el que el chequeo pidió y no existía: **corrido el saneo con `--aplicar`,
 * `set_costo_ventas` arranca**. El test que había (`la_guarda_no_frena_un_cliente_con_el_historico_sano`)
 * sólo probaba un histórico limpio, que es el caso fácil.
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing del slot está sembrada de antes y un
 * refresh la vaciaría.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 *
 * @group sales
 */
class Paridad_Guarda_Y_Saneo_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var \App\Models\User */
    protected $user;

    /** Costo unitario real del artículo de los escenarios. */
    const COSTO_UNITARIO = 850.0;

    /** Precio unitario de la línea. */
    const PRECIO_UNITARIO = 955.0;

    /** Cantidad vendida. Es el factor por el que la versión vieja del comando inflaba el costo. */
    const CANTIDAD = 15.0;

    /**
     * Ids de los artículos creados por este archivo, para borrarlos en el tearDown.
     *
     * @var array<int,int>
     */
    protected $articulos_creados = [];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::find(500);

        if (is_null($this->user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $this->actingAs($this->user, 'web');
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        if (count($this->articulos_creados) >= 1) {
            DB::table('articles')->whereIn('id', $this->articulos_creados)->delete();
        }

        parent::tearDown();
    }

    // =========================================================================================
    // Las cuatro divergencias
    // =========================================================================================

    /**
     * Test 1 — Una línea de REGALO (`price = 0`) no puede trabar el upgrade.
     *
     * La guarda vieja pedía `price` no null pero no `price > 0`. El saneo, en cambio, necesita el
     * precio para acotar el `k` y sin él descarta (`firma_del_comando_sin_precio_para_acotar_k`).
     * Un artículo regalado en una venta —que es un caso normal de mostrador— dejaba al cliente
     * trabado para siempre.
     *
     * @group sales
     * @test
     */
    public function una_linea_de_regalo_no_traba_el_upgrade()
    {
        $venta = $this->venta_con_una_linea_rota(array('price' => 0));

        $this->assertTrue(
            $this->cumple_el_criterio_viejo_de_la_guarda($venta),
            'Este escenario tiene que cumplir el criterio VIEJO; si no, el test no prueba nada porque '.
            'estaría pasando por casualidad.'
        );

        $this->assertEquals(
            0,
            $this->correr_set_costo_ventas($venta),
            'La guarda frenó por una línea de regalo, que el saneo descarta. Ese cliente no tiene cómo '.
            'destrabarse: la secuencia que el propio comando imprime no la corrige nunca.'
        );
    }

    /**
     * Test 2 — Una línea cuyo ARTÍCULO se borró en duro no puede trabar el upgrade.
     *
     * `article_sale` no tiene foreign keys, así que las líneas huérfanas existen de verdad. La
     * guarda vieja sólo joineaba `sales` y las contaba; el saneo joinea `articles` y ni las mira.
     *
     * @group sales
     * @test
     */
    public function una_linea_con_el_articulo_borrado_no_traba_el_upgrade()
    {
        $venta = $this->venta_con_una_linea_rota(array(), true);

        $this->assertTrue(
            $this->cumple_el_criterio_viejo_de_la_guarda($venta),
            'Este escenario tiene que cumplir el criterio VIEJO; si no, el test no prueba nada.'
        );

        $this->assertEquals(
            0,
            $this->correr_set_costo_ventas($venta),
            'La guarda frenó por una línea huérfana, que el saneo no puede ni ver.'
        );
    }

    /**
     * Test 3 — Una línea sobre la que el comando corrió MUCHAS veces no puede trabar el upgrade.
     *
     * El saneo busca el menor `k` tal que `cost / amount^k` entre en el rango coherente con el
     * precio, con `k_max = 4` por defecto. Un cliente que corrió el comando cinco veces o más deja
     * líneas sin `k` posible, y el saneo las descarta (`firma_del_comando_sin_k_coherente`) — con
     * razón: dividir más allá del tope sería adivinar. La guarda vieja las contaba igual.
     *
     * El escenario: `amount = 2`, `price = 10`, `cost = 400`. `400 / 2⁴ = 25`, todavía por encima
     * del techo de coherencia (`10 × 2 = 20`).
     *
     * @group sales
     * @test
     */
    public function una_linea_sin_k_coherente_no_traba_el_upgrade()
    {
        $venta = $this->venta_con_una_linea_rota(array(
            'amount'   => 2,
            'price'    => 10,
            'cost'     => 400,
            'ganancia' => 10 * 2 - 400,
        ));

        $this->assertTrue(
            $this->cumple_el_criterio_viejo_de_la_guarda($venta),
            'Este escenario tiene que cumplir el criterio VIEJO; si no, el test no prueba nada.'
        );

        $this->assertEquals(
            0,
            $this->correr_set_costo_ventas($venta),
            'La guarda frenó por una línea sin k coherente, que el saneo descarta a propósito.'
        );
    }

    /**
     * Test 4 — Una línea con `article_sale.unidades_individuales` histórico distinto del actual
     * no puede trabar el upgrade.
     *
     * Es el cuarto descarte, que el chequeo no había listado. Cuando el pivot trae su propio
     * `unidades_individuales` y no coincide con el del artículo de hoy, el saneo se niega a corregir
     * (`pivot_con_unidades_individuales_historicas_distintas`) porque estaría dividiendo con el
     * número equivocado.
     *
     * @group sales
     * @test
     */
    public function una_linea_con_unidades_historicas_distintas_no_traba_el_upgrade()
    {
        $venta = $this->venta_con_una_linea_rota(array('unidades_individuales' => 6));

        $this->assertTrue(
            $this->cumple_el_criterio_viejo_de_la_guarda($venta),
            'Este escenario tiene que cumplir el criterio VIEJO; si no, el test no prueba nada.'
        );

        $this->assertEquals(
            0,
            $this->correr_set_costo_ventas($venta),
            'La guarda frenó por una línea que el saneo se niega a tocar por sus unidades históricas.'
        );
    }

    // =========================================================================================
    // Los dos guards de la paridad
    // =========================================================================================

    /**
     * Test 5 — GUARD: una línea genuinamente rota SIGUE frenando el comando.
     *
     * 🔴 Sin este test, los cuatro de arriba se podrían "arreglar" rompiendo la guarda entera, que
     * es exactamente lo que no puede pasar: lo que la guarda protege es un histórico que, una vez
     * recalculada la ganancia con el costo roto, queda irreparable para siempre.
     *
     * @group sales
     * @test
     */
    public function una_linea_genuinamente_rota_sigue_frenando_el_comando()
    {
        $venta = $this->venta_con_una_linea_rota();

        $this->assertEquals(
            1,
            $this->correr_set_costo_ventas($venta),
            'La guarda dejó pasar una línea con la firma de la causa B: el upgrade le destruye el '.
            'histórico a ese cliente sin que nada lo avise.'
        );
    }

    /**
     * Test 6 — EL TEST DE PARIDAD: corrido el saneo con `--aplicar`, `set_costo_ventas` arranca.
     *
     * Es la propiedad que vuelve usable a la guarda, y la que no existía. La venta lleva las cinco
     * líneas a la vez —la rota de verdad y las cuatro que el saneo descarta— porque el caso real de
     * un cliente es justamente ése: tiene de todo mezclado, y le alcanza con que UNA sola línea
     * descartada quede contada para no poder destrabarse nunca.
     *
     * Se corre el saneo acotado a esa venta (`--sale_id`), no al cliente entero, para no tocar el
     * resto del fixture de la base del slot.
     *
     * @group sales
     * @test
     */
    public function corrido_el_saneo_el_comando_arranca()
    {
        $venta = $this->venta_con_las_cinco_lineas();

        $this->assertEquals(
            1,
            $this->correr_set_costo_ventas($venta),
            'Antes del saneo la guarda tiene que frenar; si no, este test no está midiendo nada.'
        );

        $salida = Artisan::call('sale:sanear-costo-de-linea', array(
            '--user_id'  => $this->user->id,
            '--sale_id'  => $venta->id,
            '--aplicar'  => true,
        ));

        $this->assertEquals(0, $salida, 'El saneo tiene que correr. Devolvió '.$salida.'.');

        $this->assertEquals(
            0,
            $this->correr_set_costo_ventas($venta),
            'Corrido el saneo con --aplicar, `set_costo_ventas` TIENE que arrancar. Si no arranca, la '.
            'secuencia que el propio comando imprime no destraba nada y al cliente sólo le queda '.
            '--force, que destruye el histórico que todavía era reparable.'
        );
    }

    // =========================================================================================
    // Helpers del archivo
    // =========================================================================================

    /**
     * El criterio VIEJO de la guarda, transcripto tal cual estaba en `paso_la_guarda_de_orden()`
     * antes del 17/9/2026: sin `price > 0`, sin join a `articles` y sin nada de lo que el saneo
     * decide en PHP.
     *
     * Existe para que los tests de arriba prueben algo: sin esto, una línea que ni siquiera cumple
     * el criterio viejo pasaría en verde sin haber medido ninguna divergencia.
     *
     * @param  \App\Models\Sale $venta
     * @return bool
     */
    protected function cumple_el_criterio_viejo_de_la_guarda($venta)
    {
        $cuantas = DB::table('article_sale')
            ->join('sales', 'sales.id', '=', 'article_sale.sale_id')
            ->whereNull('sales.deleted_at')
            ->where('sales.user_id', $this->user->id)
            ->where('sales.id', $venta->id)
            ->where('sales.to_check', 0)
            ->where('sales.checked', 0)
            ->whereNotNull('article_sale.price')
            ->whereNotNull('article_sale.ganancia')
            ->where('article_sale.cost', '>', 0)
            ->where('article_sale.amount', '>', 1)
            ->whereColumn('article_sale.cost', '>', 'article_sale.price')
            ->whereRaw('ABS(article_sale.ganancia - (article_sale.price * article_sale.amount - article_sale.cost)) <= 0.02 * GREATEST(1, ABS(article_sale.amount)) + 0.05')
            ->whereRaw('ABS(article_sale.ganancia - ((article_sale.price - article_sale.cost) * article_sale.amount)) > 0.02 * GREATEST(1, ABS(article_sale.amount)) + 0.05')
            ->count();

        return $cuantas >= 1;
    }

    /**
     * Corre `set_costo_ventas` acotado a la venta del test (el argumento es "desde este id en
     * adelante", y la venta recién creada es la última) y devuelve el código de salida.
     *
     * @param  \App\Models\Sale $venta
     * @return int
     */
    protected function correr_set_costo_ventas($venta)
    {
        return Artisan::call('set_costo_ventas', array(
            'user_id'      => $this->user->id,
            'from_sale_id' => $venta->id,
        ));
    }

    /**
     * Venta con UNA línea rota como la dejaba la versión vieja de `set_costo_ventas`: el costo TOTAL
     * en la columna unitaria y la ganancia con la firma que la delata.
     *
     * @param  array $pivot Campos del pivot que este escenario pisa.
     * @param  bool $borrar_el_articulo Si hay que dejar la línea huérfana.
     * @return \App\Models\Sale
     */
    protected function venta_con_una_linea_rota($pivot = array(), $borrar_el_articulo = false)
    {
        $venta = $this->crear_venta();

        $this->agregar_linea_rota($venta, $pivot, $borrar_el_articulo);

        return $venta;
    }

    /**
     * Venta con las cinco líneas del test de paridad: la rota de verdad, y las cuatro que el saneo
     * descarta por cada uno de sus cuatro motivos.
     *
     * @return \App\Models\Sale
     */
    protected function venta_con_las_cinco_lineas()
    {
        $venta = $this->crear_venta();

        // La única que el saneo corrige.
        $this->agregar_linea_rota($venta);

        // Regalo: sin precio no hay con qué acotar el k.
        $this->agregar_linea_rota($venta, array('price' => 0));

        // Huérfana: el artículo se borró en duro.
        $this->agregar_linea_rota($venta, array(), true);

        // Sin k coherente: el comando corrió más veces que el k_max.
        $this->agregar_linea_rota($venta, array(
            'amount'   => 2,
            'price'    => 10,
            'cost'     => 400,
            'ganancia' => 10 * 2 - 400,
        ));

        // Unidades individuales históricas distintas de las de hoy.
        $this->agregar_linea_rota($venta, array('unidades_individuales' => 6));

        return $venta;
    }

    /**
     * Venta vacía del usuario de prueba.
     *
     * @return \App\Models\Sale
     */
    protected function crear_venta()
    {
        $venta = new Sale();

        $venta->user_id = $this->user->id;
        $venta->total = self::PRECIO_UNITARIO * self::CANTIDAD;
        $venta->terminada = 1;
        $venta->save();

        return $venta;
    }

    /**
     * Le cuelga a la venta una línea con la firma de la causa B, con los valores por defecto del
     * escenario roto (costo unitario 850 guardado como 850 × 15) y lo que el caso pise encima.
     *
     * @param  \App\Models\Sale $venta
     * @param  array $pivot
     * @param  bool $borrar_el_articulo
     * @return void
     */
    protected function agregar_linea_rota($venta, $pivot = array(), $borrar_el_articulo = false)
    {
        $articulo = new Article();

        $articulo->user_id = $this->user->id;
        $articulo->name = 'ZZ Test paridad guarda '.uniqid();
        $articulo->status = 'active';
        $articulo->iva_id = 2;
        $articulo->costo_real = self::COSTO_UNITARIO;
        $articulo->save();

        $this->articulos_creados[] = $articulo->id;

        $roto = array(
            'amount'   => self::CANTIDAD,
            'price'    => self::PRECIO_UNITARIO,
            // El costo TOTAL metido en la columna unitaria: 850 × 15 = 12.750.
            'cost'     => self::COSTO_UNITARIO * self::CANTIDAD,
            // La firma del comando viejo: price × amount − cost = 955 × 15 − 12.750 = 1.575.
            'ganancia' => self::PRECIO_UNITARIO * self::CANTIDAD - self::COSTO_UNITARIO * self::CANTIDAD,
        );

        foreach ($pivot as $campo => $valor) {
            $roto[$campo] = $valor;
        }

        /*
         * Una linea de regalo (price = 0) conserva la firma: ganancia = 0 × amount − cost. Se
         * recalcula aca y no en el caso para que el escenario no dependa de una cuenta hecha a mano.
         */
        if (!array_key_exists('ganancia', $pivot)) {
            $roto['ganancia'] = $roto['price'] * $roto['amount'] - $roto['cost'];
        }

        $venta->articles()->attach($articulo->id, $roto);

        if ($borrar_el_articulo) {
            /*
             * Borrado EN DURO y por DB::table(): `article_sale` no tiene foreign keys, asi que la
             * linea sobrevive al articulo. Es el caso real que la guarda vieja contaba y el saneo
             * no puede ni mirar.
             */
            DB::table('articles')->where('id', $articulo->id)->delete();
        }
    }
}
