<?php

namespace Tests\Feature\Stock;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\import\article\motor\StockEnLote;
use App\Http\Controllers\Stock\StockMovementController;
use App\Models\Address;
use App\Models\ArticleVariant;
use App\Models\DepositMovementStatus;
use Illuminate\Support\Facades\DB;

/**
 * Stock por depósito en cada movimiento de stock (misión stock-por-deposito-en-movimientos,
 * 29/9/2026).
 *
 * Cada movimiento guarda, además del `stock_resultante` global, el `stock_anterior` y la foto de
 * TODOS los depósitos del artículo antes y después (`stock_por_deposito`). Acá se custodia, por
 * endpoint real y con artículos propios:
 *
 *  - un artículo sin depósitos guarda solo `stock_anterior` (la foto queda NULL), y lo mismo uno
 *    que solo tiene filas de sucursales borradas;
 *  - una venta en una sucursal cambia ese depósito y deja el otro igual;
 *  - un traslado entre depósitos cambia origen y destino y deja el resto igual;
 *  - un ingreso que abre un depósito nuevo lo muestra con `anterior` 0;
 *  - un movimiento de variante con depósitos guarda además los depósitos de la variante;
 *  - la importación de Excel por el camino en lote (StockEnLote) deja las mismas columnas que
 *    `crear()`, byte a byte;
 *  - invariante: la suma de los `resultante` del artículo es el `stock_resultante` del movimiento.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class Stock_por_deposito_en_movimientos_Test extends AuditoriaStockTestCase
{
    /**
     * Una sucursal más, propia del test (la base ya da la principal y la segunda).
     *
     * @param string $nombre
     * @return \App\Models\Address
     */
    protected function otra_sucursal($nombre)
    {
        return Address::create([
            'street'          => $nombre,
            'user_id'         => $this->usuario()->id,
            'default_address' => 0,
        ]);
    }

    /**
     * Le carga stock a un depósito por el endpoint real, como el modal de crear depósitos.
     *
     * @param \App\Models\Article $articulo
     * @param \App\Models\Address $address
     * @param float $cantidad
     * @return void
     */
    protected function cargar_deposito($articulo, $address, $cantidad)
    {
        $this->postJson('api/stock-movement', [
            'model_id'                     => $articulo->id,
            'amount'                       => $cantidad,
            'to_address_id'                => $address->id,
            'concepto_stock_movement_name' => 'Creacion de deposito',
        ])->assertStatus(201);
    }

    /**
     * El renglón de un depósito dentro de una lista de la foto.
     *
     * @param array $lista
     * @param int $address_id
     * @return array|null
     */
    protected function renglon($lista, $address_id)
    {
        foreach ($lista as $renglon) {
            if ((int) $renglon['address_id'] === (int) $address_id) {
                return $renglon;
            }
        }

        return null;
    }

    /**
     * Invariante: con depósitos, la suma de los `resultante` del artículo es el stock resultante
     * del movimiento (los dos salen de las mismas filas de address_article).
     *
     * @param \App\Models\StockMovement $movimiento
     * @return void
     */
    protected function assert_invariante($movimiento)
    {
        $foto = $movimiento->stock_por_deposito;

        $this->assertIsArray($foto);

        $suma = 0.0;
        foreach ($foto['articulo'] as $renglon) {
            $suma += $renglon['resultante'];
        }

        $this->assertEquals((float) $movimiento->stock_resultante, round($suma, 2), 'La suma de los depósitos de la foto tiene que ser el stock resultante del movimiento.');

        $ids = array_map(function ($renglon) {
            return (int) $renglon['address_id'];
        }, $foto['articulo']);

        $ordenados = $ids;
        sort($ordenados);

        $this->assertSame($ordenados, $ids, 'Los depósitos de la foto van ordenados por address_id.');
    }

    /**
     * @group stock
     * @test
     */
    public function un_articulo_sin_depositos_guarda_solo_el_stock_anterior()
    {
        $articulo = $this->crear_articulo('zz Stock por deposito sin depositos', ['stock' => 10]);

        $this->postJson('api/stock-movement', ['model_id' => $articulo->id, 'amount' => 5])->assertStatus(201);

        $movimiento = $this->movimientos($articulo)->last();

        $this->assertEquals(10.0, (float) $movimiento->stock_anterior);
        $this->assertEquals(15.0, (float) $movimiento->stock_resultante);
        $this->assertNull($movimiento->stock_por_deposito, 'Un artículo que no reparte por depósitos no guarda foto.');
        $this->assertNull(DB::table('stock_movements')->where('id', $movimiento->id)->value('stock_por_deposito'));

        /* Sin stock cargado (articles.stock NULL): el anterior es NULL, no 0. */
        $sin_stock = $this->crear_articulo('zz Stock por deposito sin stock');

        $this->postJson('api/stock-movement', ['model_id' => $sin_stock->id, 'amount' => 3])->assertStatus(201);

        $movimiento = $this->movimientos($sin_stock)->last();

        $this->assertNull($movimiento->stock_anterior);
        $this->assertEquals(3.0, (float) $movimiento->stock_resultante);
        $this->assertNull($movimiento->stock_por_deposito);
    }

    /**
     * Un artículo que solo tiene filas de depósito de sucursales borradas lleva el stock global
     * (la relación `addresses` no ve esas filas, CheckGlobalStock le suma al global): no reparte
     * por depósitos y la foto queda NULL, porque no cuadraría con el stock resultante.
     *
     * @group stock
     * @test
     */
    public function un_articulo_con_solo_filas_huerfanas_no_guarda_foto()
    {
        $articulo = $this->crear_articulo('zz Stock por deposito solo huerfanas', ['stock' => 10]);

        $huerfana = (int) DB::table('addresses')->max('id') + 1000;

        DB::table('address_article')->insert([
            'article_id' => $articulo->id,
            'address_id' => $huerfana,
            'amount'     => 2,
        ]);

        $this->postJson('api/stock-movement', ['model_id' => $articulo->id, 'amount' => 5])->assertStatus(201);

        $movimiento = $this->movimientos($articulo)->last();

        $this->assertEquals(10.0, (float) $movimiento->stock_anterior);
        $this->assertEquals(15.0, (float) $movimiento->stock_resultante, 'Sin depósitos vivos el movimiento va al stock global.');
        $this->assertNull($movimiento->stock_por_deposito, 'Con solo filas huérfanas el artículo no reparte por depósitos: sin foto.');
    }

    /**
     * @group stock
     * @test
     */
    public function una_venta_en_una_sucursal_cambia_solo_ese_deposito()
    {
        $articulo = $this->crear_articulo('zz Stock por deposito venta');
        $sucursal = $this->sucursal();
        $segunda  = $this->segunda_sucursal();

        $this->cargar_deposito($articulo, $sucursal, 10);
        $this->cargar_deposito($articulo, $segunda, 6);

        $this->assertEquals(16.0, $this->stock($articulo));

        $venta = $this->crear_venta([
            ['article' => $articulo, 'amount' => 3, 'price' => 300],
        ]);

        $movimiento = $this->movimientos($articulo)->last();

        $this->assertEquals($venta->id, (int) $movimiento->sale_id);
        $this->assertEquals($sucursal->id, (int) $movimiento->from_address_id);
        $this->assertEquals(16.0, (float) $movimiento->stock_anterior);
        $this->assertEquals(13.0, (float) $movimiento->stock_resultante);

        $foto = $movimiento->stock_por_deposito;

        $this->assertCount(2, $foto['articulo']);
        $this->assertArrayNotHasKey('variante', $foto, 'Un movimiento sin variante no lleva el bloque de variante.');

        $de_la_venta = $this->renglon($foto['articulo'], $sucursal->id);
        $this->assertEquals(10.0, $de_la_venta['anterior']);
        $this->assertEquals(7.0, $de_la_venta['resultante']);
        $this->assertSame($sucursal->street, $de_la_venta['deposito']);

        $la_otra = $this->renglon($foto['articulo'], $segunda->id);
        $this->assertEquals(6.0, $la_otra['anterior']);
        $this->assertEquals(6.0, $la_otra['resultante']);
        $this->assertSame('zz Deposito auditoria', $la_otra['deposito']);

        $this->assert_invariante($movimiento);

        /* El JSON crudo guarda números, no strings. */
        $crudo = json_decode(DB::table('stock_movements')->where('id', $movimiento->id)->value('stock_por_deposito'), true);
        $this->assertTrue(is_float($crudo['articulo'][0]['anterior']) || is_int($crudo['articulo'][0]['anterior']));
    }

    /**
     * @group stock
     * @test
     */
    public function un_traslado_entre_depositos_cambia_origen_y_destino_y_deja_el_resto_igual()
    {
        $articulo = $this->crear_articulo('zz Stock por deposito traslado');
        $origen   = $this->sucursal();
        $destino  = $this->segunda_sucursal();
        $tercero  = $this->otra_sucursal('zz Deposito tercero');

        $this->cargar_deposito($articulo, $origen, 10);
        $this->cargar_deposito($articulo, $tercero, 5);

        $recibido = DepositMovementStatus::firstOrCreate(['name' => 'Recibido']);

        $this->postJson('api/deposit-movement', [
            'from_address_id'            => $origen->id,
            'to_address_id'              => $destino->id,
            'deposit_movement_status_id' => $recibido->id,
            'employee_id'                => null,
            'recibido_at'                => null,
            'notes'                      => 'traslado stock por deposito',
            'articles'                   => [
                ['id' => $articulo->id, 'pivot' => ['amount' => 4, 'article_variant_id' => null]],
            ],
        ])->assertStatus(201);

        $traslados = $this->movimientos($articulo, 'Mov entre depositos');
        $this->assertCount(1, $traslados);

        $movimiento = $traslados->first();

        $this->assertEquals(15.0, (float) $movimiento->stock_anterior);
        $this->assertEquals(15.0, (float) $movimiento->stock_resultante, 'Un traslado no cambia el stock global.');

        $foto = $movimiento->stock_por_deposito;

        $this->assertCount(3, $foto['articulo']);

        $sale = $this->renglon($foto['articulo'], $origen->id);
        $this->assertEquals(10.0, $sale['anterior']);
        $this->assertEquals(6.0, $sale['resultante']);

        $entra = $this->renglon($foto['articulo'], $destino->id);
        $this->assertEquals(0.0, $entra['anterior'], 'El destino no tenía fila: arranca en 0.');
        $this->assertEquals(4.0, $entra['resultante']);

        $quieto = $this->renglon($foto['articulo'], $tercero->id);
        $this->assertEquals(5.0, $quieto['anterior']);
        $this->assertEquals(5.0, $quieto['resultante']);

        $this->assert_invariante($movimiento);
    }

    /**
     * @group stock
     * @test
     */
    public function un_ingreso_que_abre_un_deposito_nuevo_lo_muestra_con_anterior_cero()
    {
        $articulo = $this->crear_articulo('zz Stock por deposito ingreso abre');
        $sucursal = $this->sucursal();
        $segunda  = $this->segunda_sucursal();

        $this->cargar_deposito($articulo, $sucursal, 10);

        /* El primer depósito también nace en la foto: antes no había ninguna fila. */
        $primero = $this->movimientos($articulo)->last();
        $this->assertEquals(0.0, $this->renglon($primero->stock_por_deposito['articulo'], $sucursal->id)['anterior']);
        $this->assertEquals(10.0, $this->renglon($primero->stock_por_deposito['articulo'], $sucursal->id)['resultante']);

        $this->postJson('api/stock-movement', [
            'model_id'      => $articulo->id,
            'amount'        => 3,
            'to_address_id' => $segunda->id,
        ])->assertStatus(201);

        $movimiento = $this->movimientos($articulo)->last();

        $this->assertEquals($segunda->id, (int) $movimiento->to_address_id);
        $this->assertEquals(10.0, (float) $movimiento->stock_anterior);
        $this->assertEquals(13.0, (float) $movimiento->stock_resultante);

        $foto = $movimiento->stock_por_deposito;

        $nuevo = $this->renglon($foto['articulo'], $segunda->id);
        $this->assertEquals(0.0, $nuevo['anterior']);
        $this->assertEquals(3.0, $nuevo['resultante']);

        $viejo = $this->renglon($foto['articulo'], $sucursal->id);
        $this->assertEquals(10.0, $viejo['anterior']);
        $this->assertEquals(10.0, $viejo['resultante']);

        $this->assert_invariante($movimiento);
    }

    /**
     * @group stock
     * @test
     */
    public function una_venta_de_variante_con_depositos_guarda_tambien_los_depositos_de_la_variante()
    {
        $user     = $this->usuario();
        $articulo = $this->crear_articulo('zz Stock por deposito variante');
        $sucursal = $this->sucursal();
        $segunda  = $this->segunda_sucursal();

        $m = ArticleVariant::create(['article_id' => $articulo->id, 'variant_description' => 'Talle M', 'stock' => 10]);
        $l = ArticleVariant::create(['article_id' => $articulo->id, 'variant_description' => 'Talle L', 'stock' => 0]);

        DB::table('address_article_variant')->insert([
            ['article_variant_id' => $m->id, 'address_id' => $sucursal->id, 'amount' => 6],
            ['article_variant_id' => $m->id, 'address_id' => $segunda->id, 'amount' => 4],
        ]);

        /* El estado de partida coherente: los depósitos del artículo son la suma de sus variantes. */
        ArticleHelper::setArticleStockFromAddresses($articulo->fresh(), false, $user->id);

        $this->assertEquals(10.0, $this->stock($articulo));

        $this->crear_venta([
            ['article' => $articulo, 'amount' => 2, 'price' => 300, 'article_variant_id' => $m->id],
        ]);

        $movimiento = $this->movimientos($articulo)->last();

        $this->assertEquals($m->id, (int) $movimiento->article_variant_id);
        $this->assertEquals(10.0, (float) $movimiento->stock_anterior);
        $this->assertEquals(8.0, (float) $movimiento->stock_resultante);

        $foto = $movimiento->stock_por_deposito;

        $this->assertArrayHasKey('variante', $foto);
        $this->assertCount(2, $foto['variante']);

        $variante_sucursal = $this->renglon($foto['variante'], $sucursal->id);
        $this->assertEquals(6.0, $variante_sucursal['anterior']);
        $this->assertEquals(4.0, $variante_sucursal['resultante']);

        $variante_segunda = $this->renglon($foto['variante'], $segunda->id);
        $this->assertEquals(4.0, $variante_segunda['anterior']);
        $this->assertEquals(4.0, $variante_segunda['resultante']);

        /* El artículo refleja lo mismo: su depósito es la suma de las variantes. */
        $articulo_sucursal = $this->renglon($foto['articulo'], $sucursal->id);
        $this->assertEquals(6.0, $articulo_sucursal['anterior']);
        $this->assertEquals(4.0, $articulo_sucursal['resultante']);

        $articulo_segunda = $this->renglon($foto['articulo'], $segunda->id);
        $this->assertEquals(4.0, $articulo_segunda['anterior']);
        $this->assertEquals(4.0, $articulo_segunda['resultante']);

        $this->assert_invariante($movimiento);
    }

    /**
     * La importación de Excel por el camino en lote deja EXACTAMENTE las mismas columnas nuevas
     * que `crear()`, sobre gemelos: mismo estado inicial, cada uno por un camino. Incluye una
     * fila de depósito huérfana (dirección borrada), que las dos fotos leen igual, y dos
     * movimientos seguidos sobre el mismo artículo (el anterior del segundo es el resultante del
     * primero).
     *
     * @group stock
     * @test
     */
    public function la_importacion_en_lote_guarda_lo_mismo_que_crear()
    {
        $user     = $this->usuario();
        $sucursal = $this->sucursal();
        $segunda  = $this->segunda_sucursal();

        $huerfana = (int) DB::table('addresses')->max('id') + 1000;

        $ct   = new StockMovementController(false);
        $lote = new StockEnLote($user, $user->id, $ct);

        $escenarios = [
            'global'            => ['stock' => 10, 'depositos' => []],
            'depositos'         => ['stock' => 3, 'depositos' => [$sucursal->id => 3]],
            'con fila huerfana' => ['stock' => 5, 'depositos' => [$sucursal->id => 3, $huerfana => 2]],
            'abre sobre cero'   => ['stock' => 0, 'depositos' => []],
            'solo fila huerfana' => ['stock' => 10, 'depositos' => [$huerfana => 2]],
        ];

        $viejos = [];
        $nuevos = [];

        foreach ($escenarios as $nombre => $escenario) {

            foreach (['viejo', 'nuevo'] as $camino) {

                $articulo = $this->crear_articulo('zz Stock por deposito lote '.$camino.' '.$nombre, ['stock' => $escenario['stock']]);

                foreach ($escenario['depositos'] as $address_id => $cantidad) {
                    DB::table('address_article')->insert([
                        'article_id' => $articulo->id,
                        'address_id' => $address_id,
                        'amount'     => $cantidad,
                    ]);
                }

                if ($camino === 'viejo') {
                    $viejos[$nombre] = $articulo->fresh();
                } else {
                    $nuevos[$nombre] = $articulo->fresh();
                }
            }

            $viejo = $viejos[$nombre];
            $nuevo = $nuevos[$nombre];

            // Movimiento global: el artículo sin depósitos, y el que solo tiene una fila huérfana.
            if ($nombre === 'global' || $nombre === 'solo fila huerfana') {

                $ct->crear(['model_id' => $viejo->id, 'amount' => 7, 'concepto_stock_movement_name' => 'Importacion de excel'], true, $user, $user->id);
                $lote->agregar_global($nuevo, 7);

                continue;
            }

            /* Dos depósitos seguidos: uno que ya existe (o se abre) y la segunda sucursal, que se abre. */
            $pedidos = [
                ['address_id' => $sucursal->id, 'amount' => 4, 'stock_min' => null, 'stock_max' => null],
                ['address_id' => $segunda->id, 'amount' => 5, 'stock_min' => null, 'stock_max' => null],
            ];

            foreach ($pedidos as $pedido) {
                $ct->crear([
                    'model_id'                     => $viejo->id,
                    'amount'                       => $pedido['amount'],
                    'to_address_id'                => $pedido['address_id'],
                    'concepto_stock_movement_name' => 'Importacion de excel',
                ], true, $user, $user->id);
            }

            $lote->agregar_por_depositos($nuevo, $pedidos);
        }

        $lote->volcar();

        foreach ($escenarios as $nombre => $escenario) {

            $columnas = ['stock_anterior', 'stock_por_deposito', 'stock_resultante', 'amount', 'to_address_id'];

            $del_viejo = DB::table('stock_movements')->where('article_id', $viejos[$nombre]->id)->orderBy('id')->get($columnas)->map(function ($fila) {
                return (array) $fila;
            })->all();

            $del_nuevo = DB::table('stock_movements')->where('article_id', $nuevos[$nombre]->id)->orderBy('id')->get($columnas)->map(function ($fila) {
                return (array) $fila;
            })->all();

            $this->assertNotEmpty($del_viejo, 'El escenario "'.$nombre.'" no generó movimientos: el test no prueba nada.');
            $this->assertSame($del_viejo, $del_nuevo, 'El escenario "'.$nombre.'" deja columnas distintas por el camino en lote.');
        }

        /* Guardas de valor, para que la igualdad no sea la de dos caminos igual de rotos. */
        $global = $this->movimientos($nuevos['global'])->last();
        $this->assertEquals(10.0, (float) $global->stock_anterior);
        $this->assertNull($global->stock_por_deposito);

        $depositos = $this->movimientos($nuevos['depositos']);
        $this->assertCount(2, $depositos);
        $this->assertEquals(3.0, (float) $depositos[0]->stock_anterior);
        $this->assertEquals(7.0, (float) $depositos[1]->stock_anterior, 'El anterior del segundo movimiento es el resultante del primero.');
        $this->assertEquals(0.0, $this->renglon($depositos[1]->stock_por_deposito['articulo'], $segunda->id)['anterior']);
        $this->assertEquals(5.0, $this->renglon($depositos[1]->stock_por_deposito['articulo'], $segunda->id)['resultante']);
        $this->assert_invariante($depositos[1]);

        $huerfanos = $this->movimientos($nuevos['con fila huerfana']);
        $renglon_huerfano = $this->renglon($huerfanos[0]->stock_por_deposito['articulo'], $huerfana);
        $this->assertNotNull($renglon_huerfano, 'La fila huérfana entra en la foto, como en la suma de articles.stock.');
        $this->assertNull($renglon_huerfano['deposito']);
        $this->assertEquals(2.0, $renglon_huerfano['anterior']);
        $this->assertEquals(2.0, $renglon_huerfano['resultante']);
        $this->assert_invariante($huerfanos[0]);
        $this->assert_invariante($huerfanos[1]);

        $solo_huerfana = $this->movimientos($nuevos['solo fila huerfana'])->last();
        $this->assertEquals(10.0, (float) $solo_huerfana->stock_anterior);
        $this->assertEquals(17.0, (float) $solo_huerfana->stock_resultante);
        $this->assertNull($solo_huerfana->stock_por_deposito, 'Sin depósitos vivos no hay foto, tampoco por el lote.');

        $abre = $this->movimientos($nuevos['abre sobre cero']);
        $this->assertEquals(0.0, (float) $abre[0]->stock_anterior);
        $this->assertCount(1, $abre[0]->stock_por_deposito['articulo']);
        $this->assertCount(2, $abre[1]->stock_por_deposito['articulo']);
    }
}
