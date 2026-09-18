<?php

namespace Tests\Feature\Pedidos;

use App\Http\Controllers\Helpers\Order\ComboEsquemaHelper;
use App\Models\Article;
use App\Models\Combo;
use App\Models\Order;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\PedidosDePrueba;
use Tests\EmpresaTestCase;

/**
 * Combos en el circuito de PEDIDOS (mision combos-y-rangos-de-precio, 16/9/2026).
 *
 * EL AGUJERO QUE FIJAN ESTOS TESTS, que es de PLATA Y DE STOCK: esta mision hizo que el ecommerce
 * pudiera vender combos —el comprador los agrega al carrito, el servidor de la tienda se los cobra,
 * `orders.total` los incluye y `order_combo` guarda la linea—, pero cuando el dueño confirmaba ese
 * pedido en el ERP la venta nacia SIN el combo. `CreateSaleOrderHelper` armaba `$request->items`
 * con `$order->articles` y con `$order->promocion_vinotecas`, y no nombraba la palabra "combo" ni
 * una vez; `Order` ni siquiera tenia relacion `combos()`.
 *
 * Las dos consecuencias eran silenciosas —nadie veia un error—:
 *
 *   1. la venta quedaba CORTA por el importe del combo;
 *   2. el stock de los articulos COMPONENTES no se descontaba.
 *
 * Los tres tests que valen mas que el resto:
 *
 *  - `confirmar_desde_el_formulario_no_le_borra_el_combo_al_total`: el otro medio agujero.
 *    `OrderController::update()` reescribe `orders.total` con `OrderHelper::get_total()` cada vez
 *    que la request trae `articles`, y el formulario del pedido —que es por donde se cambia el
 *    estado desde que se sacaron los botones del modal— los manda SIEMPRE. Sin los combos en esa
 *    cuenta, confirmar borraba el importe del combo del total del pedido ANTES de copiarlo a la
 *    venta, y el arreglo del renglon solo no alcanzaba.
 *  - `un_pedido_mixto_lleva_los_articulos_y_el_combo`: el unico que mide que las dos cosas
 *    convivan. Un arreglo que pise `$request->items` en vez de sumarle pasaria los otros.
 *  - `sin_la_tabla_order_combo_el_pedido_se_sigue_confirmando`: la guarda de esquema. Sin ella, un
 *    cliente que corra esta version antes de la migracion `2026_09_16_100200` queda sin poder
 *    confirmar NINGUN pedido de su tienda, que es mucho peor que el defecto que se arregla.
 *
 * Fixture: `EmpresaTestCase` (DatabaseTransactions + guards de entorno + ferreteria sembrada) y el
 * trait `PedidosDePrueba`, el mismo que usan los otros cinco archivos de esta carpeta, para que el
 * pedido se arme igual en todos.
 *
 * Los componentes de los combos se crean a mano y NO se toman del fixture: se necesita un stock
 * conocido y sin depositos asignados. Con depositos, `articles.stock` es un valor derivado que
 * `ArticleHelper::setArticleStockFromAddresses()` recalcula, y la cuenta del test mediria otra cosa.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promocion de constructor, readonly, enum ni #[...].
 */
class Combos_en_pedidos_Test extends EmpresaTestCase
{
    use PedidosDePrueba;

    /** Precio unitario del combo, tal como viaja en `order_combo.price`. */
    const PRECIO_COMBO = 500;

    /** Cantidad de combos del renglon del pedido. */
    const CANTIDAD_COMBO = 3;

    /** Stock con el que arranca cada articulo componente. */
    const STOCK_INICIAL = 40;

    /** Nombre al que se corre `order_combo` mientras corre el test de la guarda. */
    const TABLA_ESCONDIDA = 'order_combo_sin_guarda';

    /** Contador para que dos combos del mismo test no compartan `num`. */
    protected $num_de_combo = 9600;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurar_order_combo_si_quedo_escondida();

        ComboEsquemaHelper::olvidar();

        $this->sembrar_estados_de_pedido();
    }

    /**
     * 🔴 El memo de `ComboEsquemaHelper` es estatico: vive todo el proceso de PHPUnit. Si el test de
     * la guarda lo dejara en `false`, TODOS los tests que corren despues en el mismo proceso —de
     * este archivo y de cualquier otro— verian la tabla como inexistente y pasarian sin medir nada.
     * Se limpia en los dos extremos.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        ComboEsquemaHelper::olvidar();

        parent::tearDown();
    }

    /**
     * Articulo componente de un combo: stock conocido y sin depositos.
     *
     * El stock NO puede quedar en null: `sale\ComboHelper::usa_stock()` saltea los articulos sin
     * stock, asi que un componente en null haria pasar el test de descuento sin descontar nada.
     *
     * @param  float  $stock
     * @return \App\Models\Article
     */
    protected function componente($stock = null)
    {
        return Article::create([
            'name'       => 'zz Componente de combo de pedido '.uniqid(),
            'user_id'    => $this->user_id(),
            'costo_real' => 50,
            'stock'      => is_null($stock) ? Self::STOCK_INICIAL : $stock,
        ]);
    }

    /**
     * Combo armado con los componentes que se le pasen.
     *
     * @param  array<int,array<string,mixed>>  $componentes  `[['article' => Article, 'amount' => 2], ...]`
     * @return \App\Models\Combo
     */
    protected function combo_con($componentes)
    {
        $this->num_de_combo++;

        $combo = Combo::create([
            'num'     => $this->num_de_combo,
            'name'    => 'zz Combo de pedido '.uniqid(),
            'price'   => Self::PRECIO_COMBO,
            'cost'    => 200,
            'user_id' => $this->user_id(),
        ]);

        foreach ($componentes as $componente) {
            $combo->articles()->attach($componente['article']->id, ['amount' => $componente['amount']]);
        }

        return $combo;
    }

    /**
     * Pedido "Sin confirmar" SIN renglones de articulos, listo para colgarle combos.
     *
     * `crear_pedido()` del trait siempre adjunta los dos renglones de la ferreteria; para medir el
     * combo solo hace falta un pedido pelado. Se arma con el mismo molde (mismo comprador, mismo
     * deposito, mismo estado).
     *
     * @param  int|null  $client_id
     * @return \App\Models\Order
     */
    protected function pedido_vacio($client_id)
    {
        $comprador = $this->crear_comprador($client_id);

        return Order::create([
            'status'          => 'unconfirmed',
            'deliver'         => 0,
            'buyer_id'        => $comprador->id,
            'order_status_id' => $this->estado('Sin confirmar')->id,
            'user_id'         => $this->user_id(),
            'address_id'      => $this->deposito()->id,
            'total'           => 0,
        ]);
    }

    /**
     * Cuelga un combo del pedido, tal como lo deja el servidor de la tienda al confirmar la compra,
     * y le suma su importe a `orders.total`.
     *
     * @param  \App\Models\Order  $pedido
     * @param  \App\Models\Combo  $combo
     * @param  float  $amount
     * @param  float|null  $price
     * @return \App\Models\Order
     */
    protected function agregar_combo($pedido, $combo, $amount, $price = null)
    {
        $price = is_null($price) ? Self::PRECIO_COMBO : $price;

        $pedido->combos()->attach($combo->id, [
            'amount' => $amount,
            'price'  => $price,
            'cost'   => $combo->cost,
        ]);

        $pedido->total = (float) $pedido->total + ($price * $amount);
        $pedido->save();

        return $pedido->fresh();
    }

    /**
     * Stock del articulo leido de la base, nunca del modelo en memoria.
     *
     * @param  \App\Models\Article|int  $articulo
     * @return float|null
     */
    protected function stock_de($articulo)
    {
        $id = is_object($articulo) ? $articulo->id : $articulo;

        $stock = DB::table('articles')->where('id', $id)->value('stock');

        return is_null($stock) ? null : (float) $stock;
    }

    /**
     * Filas de `combo_sale` de una venta.
     *
     * Se lee con DB::table y no por la relacion: lo que interesa es lo que quedo escrito en el
     * pivote, no lo que Eloquent devuelve despues de pasarlo por el modelo.
     *
     * @param  int  $sale_id
     * @return \Illuminate\Support\Collection
     */
    protected function filas_combo_sale($sale_id)
    {
        return DB::table('combo_sale')->where('sale_id', $sale_id)->get();
    }

    /**
     * Movimientos de stock de un articulo generados por una venta.
     *
     * @param  \App\Models\Article  $articulo
     * @param  int  $sale_id
     * @return \Illuminate\Support\Collection
     */
    protected function movimientos_de($articulo, $sale_id)
    {
        return DB::table('stock_movements')
                    ->where('article_id', $articulo->id)
                    ->where('sale_id', $sale_id)
                    ->get();
    }

    /**
     * 1. 🔴 EL DEFECTO, medido: un pedido con un combo se confirma y el combo llega a la venta, con
     *    su importe en el total y con el stock de cada componente descontado.
     *
     * El pedido va SOLO con el combo a proposito: si tuviera tambien articulos, un total correcto
     * podria explicarse por ellos. Acá el unico numero posible es el del combo.
     *
     * Confirma con el payload MINIMO (solo `order_status_id`), que es el que no recalcula
     * `orders.total`: lo que se mide es que la VENTA arrastre el total del pedido y el renglon.
     *
     * @group pedidos
     * @group combos
     * @test
     */
    public function confirmar_un_pedido_con_combo_lleva_el_combo_a_la_venta()
    {
        $cliente = $this->cliente_cc();

        $primero = $this->componente();
        $segundo = $this->componente();

        $combo = $this->combo_con([
            ['article' => $primero, 'amount' => 2],
            ['article' => $segundo, 'amount' => 1],
        ]);

        $pedido = $this->agregar_combo($this->pedido_vacio($cliente->id), $combo, Self::CANTIDAD_COMBO);

        $total_del_pedido = Self::PRECIO_COMBO * Self::CANTIDAD_COMBO;

        $this->assertEquals($total_del_pedido, (float) $pedido->total, 'El fixture del pedido no quedo con el importe del combo.');

        $this->putJson('api/order/'.$pedido->id, $this->payload_de_estado('Confirmado'))
             ->assertStatus(200);

        $venta = Sale::where('order_id', $pedido->id)->first();

        $this->assertNotNull($venta, 'Confirmar el pedido no creo la venta.');

        /* 1. El renglon del combo. */
        $filas = $this->filas_combo_sale($venta->id);

        $this->assertCount(1, $filas, 'El combo del pedido no llego a combo_sale: la venta nacio sin el.');

        $fila = $filas->first();

        $this->assertEquals($combo->id, (int) $fila->combo_id);
        $this->assertEquals(Self::CANTIDAD_COMBO, (float) $fila->amount);
        $this->assertEquals(Self::PRECIO_COMBO, (float) $fila->price);

        /* 2. La PLATA: el total de la venta incluye el importe del combo. */
        $this->assertEquals(
            $total_del_pedido,
            (float) $venta->total,
            'La venta quedo corta por el importe del combo.'
        );

        /* 3. El STOCK: cada componente baja por `pivot.amount` x cantidad de combos. */
        foreach ([['article' => $primero, 'amount' => 2], ['article' => $segundo, 'amount' => 1]] as $componente) {

            $descontado = $componente['amount'] * Self::CANTIDAD_COMBO;

            $this->assertEquals(
                Self::STOCK_INICIAL - $descontado,
                $this->stock_de($componente['article']),
                'El componente "'.$componente['article']->name.'" no se descontó al confirmar el pedido con el combo.'
            );

            $movimientos = $this->movimientos_de($componente['article'], $venta->id);

            $this->assertCount(1, $movimientos, 'Tiene que haber UN movimiento de stock por componente.');
            $this->assertEquals(-$descontado, (float) $movimientos->first()->amount);
        }
    }

    /**
     * 2. 🔴 EL OTRO MEDIO AGUJERO: confirmar desde el FORMULARIO no le puede borrar el combo al
     *    total del pedido.
     *
     * `OrderController::update()` reescribe `orders.total` con `OrderHelper::get_total()` cada vez
     * que la request trae `articles`, y el formulario del pedido los manda siempre. Con esa cuenta
     * ciega a los combos, confirmar por el select dejaba `orders.total` sin el importe del combo, y
     * `CreateSaleOrderHelper::createSale()` copiaba ese total podado a la venta
     * (`'total' => $order->total`). El renglon del combo aparecia en la venta y el total no lo
     * incluia.
     *
     * El test 1 es ciego a esto porque confirma con el payload minimo, que no recalcula nada.
     *
     * @group pedidos
     * @group combos
     * @test
     */
    public function confirmar_desde_el_formulario_no_le_borra_el_combo_al_total()
    {
        $cliente = $this->cliente_cc();

        $componente = $this->componente();

        $combo = $this->combo_con([['article' => $componente, 'amount' => 2]]);

        /* Pedido CON articulos: es la unica forma de que la request traiga `articles` y se recalcule. */
        $pedido = $this->agregar_combo(
            $this->crear_pedido($cliente->id),
            $combo,
            Self::CANTIDAD_COMBO
        );

        $total_esperado = $this->total_esperado() + (Self::PRECIO_COMBO * Self::CANTIDAD_COMBO);

        $this->assertEquals($total_esperado, (float) $pedido->total);

        $this->putJson('api/order/'.$pedido->id, $this->payload_del_select($pedido, 'Confirmado'))
             ->assertStatus(200);

        $this->assertEquals(
            $total_esperado,
            (float) $pedido->fresh()->total,
            'Confirmar desde el formulario recalculo el total del pedido sin contar el combo.'
        );

        $venta = Sale::where('order_id', $pedido->id)->first();

        $this->assertNotNull($venta);

        $this->assertEquals(
            $total_esperado,
            (float) $venta->total,
            'La venta arrastro un total al que le falta el importe del combo.'
        );

        $this->assertCount(1, $this->filas_combo_sale($venta->id), 'El combo no llego a la venta por el camino del formulario.');
    }

    /**
     * 3. 🔴 Pedido MIXTO: los articulos y el combo viajan los dos, y ninguno pisa al otro.
     *
     * Es el unico test que mide que convivan. Un arreglo que REEMPLAZARA `$request->items` en vez de
     * sumarle el combo pasaria el test 1 (que va sin articulos) y romperia este.
     *
     * @group pedidos
     * @group combos
     * @test
     */
    public function un_pedido_mixto_lleva_los_articulos_y_el_combo()
    {
        $cliente = $this->cliente_cc();

        $componente = $this->componente();

        $combo = $this->combo_con([['article' => $componente, 'amount' => 2]]);

        $pedido = $this->crear_pedido($cliente->id);

        $stock_previo = $this->stock_actual();

        $pedido = $this->agregar_combo($pedido, $combo, Self::CANTIDAD_COMBO);

        $total_esperado = $this->total_esperado() + (Self::PRECIO_COMBO * Self::CANTIDAD_COMBO);

        $this->putJson('api/order/'.$pedido->id, $this->payload_de_estado('Confirmado'))
             ->assertStatus(200);

        $venta = Sale::where('order_id', $pedido->id)->first();

        $this->assertNotNull($venta);

        /* Los dos renglones de articulos siguen llegando. */
        $this->assertCount(
            count($this->renglones()),
            $venta->articles,
            'El combo le comio los renglones de articulos a la venta.'
        );

        /* Y el combo tambien. */
        $this->assertCount(1, $this->filas_combo_sale($venta->id), 'La venta del pedido mixto nacio sin el combo.');

        /* El total suma las dos cosas. */
        $this->assertEquals($total_esperado, (float) $venta->total, 'El total de la venta mixta no suma articulos + combo.');

        /* El stock se mueve de los dos lados. */
        foreach ($this->renglones() as $renglon) {
            $this->assertEquals(
                $stock_previo[$renglon['article']->id] - $renglon['amount'],
                $this->stock_de($renglon['article']),
                'El stock de "'.$renglon['article']->name.'" dejo de descontarse en un pedido con combo.'
            );
        }

        $this->assertEquals(
            Self::STOCK_INICIAL - (2 * Self::CANTIDAD_COMBO),
            $this->stock_de($componente),
            'El componente del combo no se descontó en el pedido mixto.'
        );
    }

    /**
     * 4. No regresion: un pedido SIN combos se convierte exactamente igual que antes.
     *
     * Va con los dos renglones del fixture a proposito. El pedido vacio no probaria gran cosa: lo
     * que interesa es que el camino de siempre —por donde pasa la enorme mayoria de los pedidos— no
     * haya cambiado de numero ni de cantidad de renglones.
     *
     * @group pedidos
     * @group combos
     * @test
     */
    public function un_pedido_sin_combos_se_confirma_exactamente_igual()
    {
        $cliente = $this->cliente_cc();

        $pedido = $this->crear_pedido($cliente->id);

        $stock_previo = $this->stock_actual();

        $this->putJson('api/order/'.$pedido->id, $this->payload_de_estado('Confirmado'))
             ->assertStatus(200);

        $venta = Sale::where('order_id', $pedido->id)->first();

        $this->assertNotNull($venta, 'Un pedido sin combos dejo de generar venta.');

        $this->assertCount(
            0,
            $this->filas_combo_sale($venta->id),
            'Un pedido sin combos escribio en combo_sale.'
        );

        $this->assertEquals(
            $this->total_esperado(),
            (float) $venta->total,
            'El total de la venta de un pedido sin combos cambio.'
        );

        $this->assertCount(count($this->renglones()), $venta->articles);

        foreach ($this->renglones() as $renglon) {
            $this->assertEquals(
                $stock_previo[$renglon['article']->id] - $renglon['amount'],
                $this->stock_de($renglon['article']),
                'El stock de "'.$renglon['article']->name.'" dejo de descontarse.'
            );
        }
    }

    /**
     * 5. 🔴 LA GUARDA DE ESQUEMA: sin la tabla `order_combo`, confirmar un pedido sigue andando.
     *
     * `order_combo` la crea una migracion de esta misma mision (`2026_09_16_100200`). Un cliente
     * puede estar corriendo este codigo antes de que esa migracion haya pasado por su base: el
     * deploy sube los archivos y despues migra, y entre las dos cosas hay una ventana. Sin la
     * guarda, en esa ventana el cliente queda sin poder confirmar NINGUN pedido de su tienda ni
     * abrir el listado de pedidos, que es mucho peor que el defecto que esta mision arregla.
     *
     * ⚠️ COMO SE SACA LA TABLA, y por que el test se da vuelta para hacerlo. Dos cosas se cruzan:
     *
     *   1. El DDL de MySQL hace COMMIT IMPLICITO de la sesion que lo ejecuta. Un `RENAME TABLE` por
     *      la conexion del test cerraria la transaccion de `DatabaseTransactions` y todo lo que
     *      escriba este test quedaria pegado en la base del slot. Por eso el rename va por una
     *      conexion PDO APARTE, con su propia sesion.
     *   2. Pero ademas, en MySQL 8 `information_schema` se sirve del diccionario de datos, que es
     *      InnoDB: adentro de una transaccion REPEATABLE READ ya abierta, `Schema::hasTable()`
     *      responde con el SNAPSHOT y sigue viendo la tabla aunque otra sesion ya la haya corrido.
     *      Medido acá el 16/9/2026: con el rename hecho, la guarda seguia devolviendo `true`.
     *
     * Por eso se cierra la transaccion del trait ANTES del rename y se abre una NUEVA despues: el
     * read view de esa transaccion nueva ya nace viendo la base sin `order_combo`. Al final se
     * revierte, se devuelve la tabla y se deja una transaccion abierta para que el `tearDown` del
     * trait tenga que cerrar. El aislamiento no se pierde en ningun momento: lo que el test escribe
     * lo escribe adentro de la transaccion nueva, y esa se revierte igual.
     *
     * Se RENOMBRA y no se borra: restaurar es un solo statement y, si algo se cortara a la mitad,
     * la tabla sigue entera bajo el otro nombre (y `setUp()` la devuelve sola en la corrida
     * siguiente). Y el `lock_wait_timeout` corto evita que un metadata lock cuelgue la suite: el
     * default de MySQL para MDL es un año.
     *
     * El pedido va SIN combos porque un cliente en esa ventana no puede tener ninguno: la tabla
     * donde se guardarian no existe.
     *
     * @group pedidos
     * @group combos
     * @test
     */
    public function sin_la_tabla_order_combo_el_pedido_se_sigue_confirmando()
    {
        DB::rollBack();

        $this->esconder_order_combo();

        DB::beginTransaction();

        try {

            $this->sembrar_estados_de_pedido();

            $this->assertFalse(
                ComboEsquemaHelper::hay_tabla(),
                'El escenario no se armo: la guarda sigue viendo la tabla order_combo.'
            );

            /*
                Control del escenario: sin este assert, el test pasaria igual con la tabla presente
                y no probaria nada. Tocar la relacion tiene que reventar de verdad — eso es
                exactamente lo que le pasa a un cliente que corre esta version antes de migrar.
            */
            $exploto = false;

            try {
                DB::table(ComboEsquemaHelper::TABLA)->count();
            } catch (\Illuminate\Database\QueryException $e) {
                $exploto = true;
            }

            $this->assertTrue($exploto, 'El escenario no se armo: consultar order_combo todavia funciona.');

            $cliente = $this->cliente_cc();

            $pedido = $this->crear_pedido($cliente->id);

            $stock_previo = $this->stock_actual();

            /* a. El listado de pedidos (scopeWithAll, que eager-loadea combos.articles). */
            $listado = Order::where('id', $pedido->id)->withAll()->first();

            $this->assertNotNull($listado, 'Sin order_combo, el listado de pedidos dejo de traer el pedido.');

            /* b. La confirmacion, que es lo que le da la venta al comercio. */
            $this->putJson('api/order/'.$pedido->id, $this->payload_de_estado('Confirmado'))
                 ->assertStatus(200);

            $venta = Sale::where('order_id', $pedido->id)->first();

            $this->assertNotNull($venta, 'Sin order_combo, confirmar el pedido dejo de crear la venta.');

            $this->assertEquals(
                $this->total_esperado(),
                (float) $venta->total,
                'Sin order_combo, el total de la venta cambio.'
            );

            $this->assertCount(count($this->renglones()), $venta->articles);

            foreach ($this->renglones() as $renglon) {
                $this->assertEquals(
                    $stock_previo[$renglon['article']->id] - $renglon['amount'],
                    $this->stock_de($renglon['article']),
                    'Sin order_combo, el stock de "'.$renglon['article']->name.'" dejo de descontarse.'
                );
            }

        } finally {

            DB::rollBack();

            $this->devolver_order_combo();

            /* Para que el rollback del tearDown del trait tenga una transaccion que cerrar. */
            DB::beginTransaction();
        }
    }

    /**
     * Conexion PDO aparte contra la MISMA base, para correr el DDL sin cerrar la transaccion del
     * test. Se arma con la config de Laravel, nunca con valores escritos a mano.
     *
     * @return \PDO
     */
    protected function conexion_aparte()
    {
        $config = config('database.connections.'.config('database.default'));

        $dsn = 'mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'];

        $pdo = new \PDO($dsn, $config['username'], $config['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);

        /* Sin esto, un metadata lock cuelga la suite: el default de MySQL para MDL es 1 año. */
        $pdo->exec('SET SESSION lock_wait_timeout = 10');

        return $pdo;
    }

    /**
     * Corre `order_combo` a un nombre que la guarda no busca.
     *
     * @return void
     */
    protected function esconder_order_combo()
    {
        $this->conexion_aparte()->exec(
            'RENAME TABLE `'.ComboEsquemaHelper::TABLA.'` TO `'.Self::TABLA_ESCONDIDA.'`'
        );

        ComboEsquemaHelper::olvidar();
    }

    /**
     * La devuelve a su nombre.
     *
     * @return void
     */
    protected function devolver_order_combo()
    {
        $this->conexion_aparte()->exec(
            'RENAME TABLE `'.Self::TABLA_ESCONDIDA.'` TO `'.ComboEsquemaHelper::TABLA.'`'
        );

        ComboEsquemaHelper::olvidar();
    }

    /**
     * Red de seguridad: si una corrida anterior se corto entre los dos renames, la tabla quedo con
     * el nombre escondido. Se devuelve sola antes de que ningun test la necesite.
     *
     * @return void
     */
    protected function restaurar_order_combo_si_quedo_escondida()
    {
        if (!Schema::hasTable(Self::TABLA_ESCONDIDA)) {
            return;
        }

        $this->devolver_order_combo();
    }
}
