<?php

namespace Tests\Feature\Precios;

use App\Http\Controllers\Helpers\article\ArticlePricesHelper;
use App\Models\Article;
use App\Models\PriceType;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Tarea 7 — las cuatro ramas de `ArticlePricesHelper::resolver_precio_de_venta()`.
 *
 * Lo que protege: en una cuenta con listas de precio, `articles.final_price` es un numero que NO se
 * le cobra a nadie —setFinalPrice() le pasa a las listas la base de calcular_base_antes_de_listas()
 * y recien despues aplica el margen del proveedor, el de la categoria y el percentage_gain, que van
 * solo al final_price unico—, asi que mostrarlo como "el precio" es mostrar algo que nadie paga.
 *
 * El mismo articulo tiene un numero distinto por rama, a proposito: asi se ve que cada rama elige
 * otra cosa y no que todas caen al mismo lado por casualidad.
 *
 * La rama 4 (lista por defecto) merece su propio parrafo, porque es una decision de producto: en
 * `price_types` no existe ninguna columna de "lista por defecto". El criterio si existe en el
 * sistema, en el front: `empresa-spa/src/mixins/vender/price_types.js` usa la de POSITION MAS ALTA
 * cuando no hay presupuesto ni cliente con lista. El resolvedor usa el mismo criterio, y este test
 * lo fija: si alguien lo cambia de un solo lado, el front y el back empiezan a mostrar precios
 * distintos para el mismo articulo.
 *
 * DatabaseTransactions sobre la base ya sembrada, y markTestSkipped si falta el usuario 500 —
 * mismo patron que `tests/Feature/Costeo/CascadaDePreciosTest.php`.
 *
 * @group precios-por-lista
 */
class Resolver_Precio_De_Venta_Test extends TestCase
{
    use DatabaseTransactions;

    /** Precio final unico del articulo: el numero que hoy se muestra en todos lados. */
    const PRECIO_FINAL_UNICO = 1000;

    /** Pivote de la lista de position BAJA. */
    const PRECIO_LISTA_MOSTRADOR = 1500;

    /** Pivote de la lista de position ALTA: la que el sistema usa por defecto. */
    const PRECIO_LISTA_MAYORISTA = 1200;

    /** @var \App\Models\User */
    protected $user;

    /** @var \App\Models\Article */
    protected $article;

    /** @var \App\Models\PriceType */
    protected $mostrador;

    /** @var \App\Models\PriceType */
    protected $mayorista;

    /** @var int|null Valor original del flag de listas del usuario, para dejarlo como estaba. */
    protected $listas_original = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::find(500);

        if (is_null($this->user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $this->listas_original = $this->user->listas_de_precio;

        /**
         * Position baja y position alta, con precios distintos, para que se note cual eligio la
         * rama por defecto. Mostrador tiene el precio MAS ALTO y la position MAS BAJA a proposito:
         * si el resolvedor eligiera "el mayor precio" o "la primera lista" en vez de la position
         * mas alta, este test se pondria rojo.
         */
        $this->mostrador = PriceType::create([
            'name' => 'zz Mostrador',
            'user_id' => 500,
            'position' => 1,
        ]);

        $this->mayorista = PriceType::create([
            'name' => 'zz Mayorista',
            'user_id' => 500,
            'position' => 9,
        ]);

        $this->article = Article::create([
            'name' => 'zz Articulo de la tarea 7',
            'user_id' => 500,
            'final_price' => self::PRECIO_FINAL_UNICO,
            'status' => 'active',
        ]);

        $this->article->price_types()->attach($this->mostrador->id, [
            'final_price' => self::PRECIO_LISTA_MOSTRADOR,
        ]);

        $this->article->price_types()->attach($this->mayorista->id, [
            'final_price' => self::PRECIO_LISTA_MAYORISTA,
        ]);

        $this->article = Article::with('price_types')->find($this->article->id);
    }

    protected function tearDown(): void
    {
        if (!is_null($this->user) && !is_null($this->listas_original)) {
            $this->user->listas_de_precio = $this->listas_original;
            $this->user->save();
        }

        parent::tearDown();
    }

    /**
     * Prende o apaga las listas de precio de la cuenta y devuelve el usuario recargado, porque
     * el resolvedor lee el flag del modelo que le pasan.
     *
     * @param int $usa 1 o 0
     * @return \App\Models\User
     */
    protected function cuenta_con_listas($usa)
    {
        $this->user->listas_de_precio = $usa;
        $this->user->save();

        return User::find(500);
    }

    /**
     * Rama 1 — la mayoria de las cuentas. Sin listas no hay nada que resolver y el resolvedor tiene
     * que ser transparente: el mismo numero de siempre. Es la no-regresion que mas importa de toda
     * la tarea 7.
     *
     * @group precios-por-lista
     * @test
     */
    public function una_cuenta_sin_listas_sigue_usando_el_precio_final_unico()
    {
        $user = $this->cuenta_con_listas(0);

        $resuelto = ArticlePricesHelper::resolver_precio_de_venta($this->article, $user, null);

        $this->assertEquals(self::PRECIO_FINAL_UNICO, $resuelto['final_price']);
        $this->assertNull($resuelto['price_type_id']);
        $this->assertEquals('final_price', $resuelto['origen']);
    }

    /**
     * Rama 1, y el caso que la hace importar: aunque el articulo TENGA pivotes cargados, si la
     * cuenta no usa listas el precio sigue siendo el final_price. Un articulo puede quedar con
     * pivotes viejos de cuando las listas estuvieron activas.
     *
     * @group precios-por-lista
     * @test
     */
    public function sin_listas_no_mira_los_pivotes_aunque_existan()
    {
        $user = $this->cuenta_con_listas(0);

        $resuelto = ArticlePricesHelper::resolver_precio_de_venta(
            $this->article,
            $user,
            $this->mostrador->id
        );

        $this->assertEquals(self::PRECIO_FINAL_UNICO, $resuelto['final_price']);
        $this->assertNull($resuelto['price_type_id']);
    }

    /**
     * Ramas 2 y 3 — lista explicita. La rama 3 (la lista del cliente) llega hasta aca con el
     * price_type_id ya resuelto por el llamador: el resolvedor no conoce clientes a proposito,
     * para no atarse a Vender.
     *
     * @group precios-por-lista
     * @test
     */
    public function con_una_lista_explicita_devuelve_el_precio_de_esa_lista()
    {
        $user = $this->cuenta_con_listas(1);

        $resuelto = ArticlePricesHelper::resolver_precio_de_venta(
            $this->article,
            $user,
            $this->mostrador->id
        );

        $this->assertEquals(self::PRECIO_LISTA_MOSTRADOR, $resuelto['final_price']);
        $this->assertEquals($this->mostrador->id, $resuelto['price_type_id']);
        $this->assertEquals('zz Mostrador', $resuelto['price_type_name']);
        $this->assertEquals('lista', $resuelto['origen']);
    }

    /**
     * Rama 4 — sin lista explicita, la por defecto: la de position mas alta, igual que el front.
     *
     * @group precios-por-lista
     * @test
     */
    public function sin_lista_explicita_usa_la_de_position_mas_alta()
    {
        $user = $this->cuenta_con_listas(1);

        $resuelto = ArticlePricesHelper::resolver_precio_de_venta($this->article, $user, null);

        $this->assertEquals(self::PRECIO_LISTA_MAYORISTA, $resuelto['final_price']);
        $this->assertEquals($this->mayorista->id, $resuelto['price_type_id']);
        $this->assertEquals('lista_por_defecto', $resuelto['origen']);
    }

    /**
     * Cada rama tiene que elegir un numero DISTINTO sobre el mismo articulo. Sin esta comparacion,
     * los tests de arriba podrian estar pasando porque todos caen al mismo lado.
     *
     * @group precios-por-lista
     * @test
     */
    public function las_cuatro_ramas_devuelven_numeros_distintos_sobre_el_mismo_articulo()
    {
        $sin_listas = ArticlePricesHelper::resolver_precio_de_venta(
            $this->article,
            $this->cuenta_con_listas(0),
            null
        );

        $user_con_listas = $this->cuenta_con_listas(1);

        $explicita = ArticlePricesHelper::resolver_precio_de_venta(
            $this->article,
            $user_con_listas,
            $this->mostrador->id
        );

        $por_defecto = ArticlePricesHelper::resolver_precio_de_venta(
            $this->article,
            $user_con_listas,
            null
        );

        $this->assertNotEquals($sin_listas['final_price'], $explicita['final_price']);
        $this->assertNotEquals($explicita['final_price'], $por_defecto['final_price']);
        $this->assertNotEquals($sin_listas['final_price'], $por_defecto['final_price']);
    }

    /**
     * Bordes que en produccion existen de verdad: un articulo sin pivotes (creado antes de activar
     * las listas, o mientras el recalculo global todavia no llego) y una lista cuyo pivote no tiene
     * precio calculado. En los dos casos cae al final_price en vez de devolver null: un precio
     * vacio en una pantalla o en un Excel es peor que un precio viejo.
     *
     * @group precios-por-lista
     * @test
     */
    public function un_articulo_sin_pivotes_cae_al_precio_final_unico()
    {
        $user = $this->cuenta_con_listas(1);

        $sin_pivotes = Article::create([
            'name' => 'zz Articulo sin pivotes de la tarea 7',
            'user_id' => 500,
            'final_price' => 777,
            'status' => 'active',
        ]);

        $sin_pivotes = Article::with('price_types')->find($sin_pivotes->id);

        $resuelto = ArticlePricesHelper::resolver_precio_de_venta($sin_pivotes, $user, null);

        $this->assertEquals(777, $resuelto['final_price']);
        $this->assertEquals('final_price', $resuelto['origen']);
    }

    /**
     * Y el borde de la lista sin precio: el pivote existe pero su final_price es null.
     *
     * @group precios-por-lista
     * @test
     */
    public function una_lista_con_el_pivote_sin_precio_cae_al_precio_final_unico()
    {
        $user = $this->cuenta_con_listas(1);

        $sin_precio = PriceType::create([
            'name' => 'zz Lista sin precio',
            'user_id' => 500,
            'position' => 99,
        ]);

        $this->article->price_types()->attach($sin_precio->id, [
            'final_price' => null,
        ]);

        $recargado = Article::with('price_types')->find($this->article->id);

        /**
         * position 99 la convierte en la lista por defecto, asi que el resolvedor la elige y se
         * encuentra con el pivote vacio.
         */
        $resuelto = ArticlePricesHelper::resolver_precio_de_venta($recargado, $user, null);

        $this->assertEquals(self::PRECIO_FINAL_UNICO, $resuelto['final_price']);
        $this->assertEquals('final_price', $resuelto['origen']);
    }
}
