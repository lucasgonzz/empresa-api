<?php

namespace Tests\Feature\SugerenciasStock;

use App\Jobs\GenerateStockSuggestionChunksJob;
use App\Models\Address;
use App\Models\Article;
use App\Models\ArticlePurchase;
use App\Models\Sale;
use App\Models\StockSuggestion;
use App\Models\StockSuggestionArticle;
use App\Models\User;
use App\Services\StockSuggestion\CoberturaService;
use App\Services\StockSuggestion\StockSuggestionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Misión deposito-madre (2/10/2026): una sola sucursal madre por comercio, las
 * sugerencias salen primero de ahí, y el reparto prioriza a las sucursales que
 * más venden con un criterio configurable (ventas_sucursal | ventas_articulo).
 *
 * Protege las decisiones de Lucas de la Fase 2:
 *   - madre primero, después el respaldo (solo sucursales SIN déficit);
 *   - nunca se le saca stock a una sucursal en déficit (no hay escalón 4);
 *   - el madre nunca es destino;
 *   - "la que más vende en general" = plata facturada en pesos, 90 días;
 *   - el criterio también ordena las líneas guardadas (asignar_prioridades);
 *   - sin madre, todo exactamente como hoy.
 *
 * Copia el patrón de 3_Origen_y_objetivo_Test.php: un comercio propio por test,
 * sucursales y artículos sembrados a mano, DatabaseTransactions.
 */
class Deposito_madre_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User Comercio dueño de todo lo que siembra el test */
    protected $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        // El pipeline notifica al terminar; el test no depende de Pusher.
        Notification::fake();

        // Sin credencial de Anthropic el job del resumen no se despacha: con la
        // cola sync y la clave real del .env.testing saldría a la API de verdad.
        config(['services.anthropic.api_key' => null]);

        $this->comercio = User::create([
            'name'     => 'Comercio deposito madre',
            'email'    => 'deposito-madre-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);
    }

    /**
     * Una sucursal del comercio del test.
     *
     * @param string $nombre
     * @param bool $madre
     * @param bool $designada es_deposito_origen
     * @param int|null $user_id Dueño (default: el comercio del test)
     * @return Address
     */
    protected function sucursal($nombre, $madre = false, $designada = false, $user_id = null)
    {
        return Address::create([
            'street'             => $nombre,
            'user_id'            => $user_id !== null ? $user_id : $this->comercio->id,
            'es_deposito_origen' => $designada,
            'es_deposito_madre'  => $madre,
        ]);
    }

    /**
     * Un artículo del comercio con stock por sucursal.
     *
     * @param string $nombre
     * @param array $stocks [address_id => ['amount' =>, 'stock_min' =>, 'stock_max' =>]]
     * @return Article
     */
    protected function articulo_con_stock($nombre, array $stocks)
    {
        $article = Article::create([
            'name'    => $nombre,
            'user_id' => $this->comercio->id,
        ]);

        foreach ($stocks as $address_id => $pivot) {
            $article->addresses()->attach($address_id, $pivot);
        }

        return $article;
    }

    /**
     * Líneas calculadas en memoria para esos artículos.
     *
     * @param array $article_ids
     * @param string $limite_origen
     * @param string $origen
     * @return array
     */
    protected function calcular(array $article_ids, $limite_origen = 'minimo', $origen = 'absoluto')
    {
        $suggestion = StockSuggestion::create([
            'modo'          => 'minimo',
            'origen'        => $origen,
            'limite_origen' => $limite_origen,
            'status'        => 'pendiente',
            'user_id'       => $this->comercio->id,
        ]);

        return (new StockSuggestionService($suggestion))->getSuggestionsForArticles($article_ids)->all();
    }

    /**
     * Las líneas reducidas a [desde, hacia, cantidad], para comparar de una.
     *
     * @param array $lineas
     * @return array
     */
    protected function movimientos(array $lineas)
    {
        $resultado = [];

        foreach ($lineas as $linea) {
            $resultado[] = [(int) $linea['from_address_id'], (int) $linea['to_address_id'], (float) $linea['suggested_amount']];
        }

        return $resultado;
    }

    /**
     * Una venta del comercio con su total, en una sucursal y una fecha.
     *
     * @param int $address_id
     * @param float $total
     * @param \Carbon\Carbon $fecha
     * @param array $extra Columnas de la venta (moneda_id, is_consolidacion_facturacion...)
     * @return Sale
     */
    protected function venta($address_id, $total, $fecha, array $extra = [])
    {
        return Sale::create(array_merge([
            'user_id'    => $this->comercio->id,
            'address_id' => $address_id,
            'total'      => $total,
            'terminada'  => 1,
            'created_at' => $fecha,
            'updated_at' => $fecha,
        ], $extra));
    }

    /**
     * Una venta de un artículo en una sucursal (con su renglón de article_purchases):
     * es lo que mide la velocidad de venta de ESE artículo.
     *
     * @param int $article_id
     * @param int $address_id
     * @param float $cantidad
     * @param float $total
     * @param \Carbon\Carbon $fecha
     * @return void
     */
    protected function venta_de_articulo($article_id, $address_id, $cantidad, $total, $fecha)
    {
        $sale = $this->venta($address_id, $total, $fecha);

        ArticlePurchase::create([
            'sale_id'    => $sale->id,
            'article_id' => $article_id,
            'address_id' => $address_id,
            'amount'     => $cantidad,
            'created_at' => $fecha,
            'updated_at' => $fecha,
        ]);
    }

    /**
     * Configura el criterio de prioridad del comercio.
     *
     * @param string $criterio
     * @return void
     */
    protected function criterio($criterio)
    {
        $this->comercio->sugerencias_prioridad_destino = $criterio;
        $this->comercio->save();
    }

    /**
     * Payload base del PUT de sucursal (lo que manda el ABM sin los tildes).
     *
     * @param Address $sucursal
     * @return array
     */
    protected function payload_de($sucursal)
    {
        return [
            'street'        => $sucursal->street,
            'street_number' => $sucursal->street_number,
            'city'          => $sucursal->city,
            'province'      => $sucursal->province,
        ];
    }

    // ------------------------------------------------------------------
    // Unicidad del madre y endpoints
    // ------------------------------------------------------------------

    /**
     * @group sugerencias-stock
     * @test
     */
    public function marcar_otra_sucursal_como_madre_desmarca_la_anterior_por_el_put()
    {
        $this->actingAs($this->comercio, 'web');

        $a = $this->sucursal('Madre A', true);
        $b = $this->sucursal('Sucursal B');

        // El madre de OTRO comercio no se toca: la unicidad es por user_id.
        $otro = User::create([
            'name'     => 'Otro comercio madre',
            'email'    => 'deposito-madre-otro-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);
        $madre_ajena = $this->sucursal('Madre ajena', true, false, $otro->id);

        $this->putJson('api/address/' . $b->id, $this->payload_de($b) + ['es_deposito_madre' => true])
            ->assertStatus(200);

        $this->assertTrue((bool) $b->fresh()->es_deposito_madre, 'B tiene que quedar como madre.');
        $this->assertFalse((bool) $a->fresh()->es_deposito_madre, 'Marcar B como madre tiene que desmarcar a A.');
        $this->assertTrue((bool) $madre_ajena->fresh()->es_deposito_madre, 'El madre de otro comercio no se toca.');

        // Sin la clave (ABM sin la extensión): B sigue siendo madre.
        $this->putJson('api/address/' . $b->id, $this->payload_de($b))->assertStatus(200);
        $this->assertTrue((bool) $b->fresh()->es_deposito_madre, 'Un PUT sin la clave no puede desmarcar el madre.');

        // Con la clave en null (cliente viejo reenviando el modelo): tampoco.
        $this->putJson('api/address/' . $b->id, $this->payload_de($b) + ['es_deposito_madre' => null])->assertStatus(200);
        $this->assertTrue((bool) $b->fresh()->es_deposito_madre, 'Un PUT con null no puede desmarcar el madre.');

        // Y un PUT sin la clave sobre la VIEJA madre no la vuelve a prender.
        $this->putJson('api/address/' . $a->id, $this->payload_de($a))->assertStatus(200);
        $this->assertFalse((bool) $a->fresh()->es_deposito_madre);
        $this->assertTrue((bool) $b->fresh()->es_deposito_madre);

        // Con false explícito, sí se desmarca.
        $this->putJson('api/address/' . $b->id, $this->payload_de($b) + ['es_deposito_madre' => false])->assertStatus(200);
        $this->assertFalse((bool) $b->fresh()->es_deposito_madre);
    }

    /**
     * @group sugerencias-stock
     * @test
     */
    public function dar_de_alta_una_sucursal_como_madre_desmarca_la_anterior()
    {
        $this->actingAs($this->comercio, 'web');

        $a = $this->sucursal('Madre vieja', true);

        $respuesta = $this->postJson('api/address', [
            'street'            => 'Madre nueva',
            'es_deposito_madre' => true,
        ])->assertStatus(201);

        $nueva = Address::find($respuesta->json('model.id'));

        $this->assertTrue((bool) $nueva->es_deposito_madre);
        $this->assertFalse((bool) $a->fresh()->es_deposito_madre);

        // Un alta sin la clave nace en 0 y no le saca el lugar a nadie.
        $this->postJson('api/address', ['street' => 'Sucursal comun'])->assertStatus(201);
        $this->assertTrue((bool) $nueva->fresh()->es_deposito_madre);
    }

    /**
     * Un domicilio de comprador de la tienda (buyer_id) vive en la misma tabla con el
     * user_id del dueño, pero no es una sucursal: el modelo lo fuerza a 0 y no desmarca
     * al madre de verdad.
     *
     * @group sugerencias-stock
     * @test
     */
    public function un_domicilio_de_comprador_nunca_queda_como_madre()
    {
        $madre = $this->sucursal('Madre real', true);

        $domicilio = Address::create([
            'street'            => 'Casa de un comprador',
            'user_id'           => $this->comercio->id,
            'buyer_id'          => 999999,
            'es_deposito_madre' => 1,
        ]);

        $this->assertFalse((bool) $domicilio->fresh()->es_deposito_madre);
        $this->assertTrue((bool) $madre->fresh()->es_deposito_madre);
        $this->assertEquals($madre->id, Address::deposito_madre_de($this->comercio->id)->id);
    }

    /**
     * @group sugerencias-stock
     * @test
     */
    public function el_criterio_se_guarda_por_el_put_de_usuario_con_lista_blanca()
    {
        $this->actingAs($this->comercio, 'web');

        $this->assertEquals('ventas_sucursal', $this->comercio->fresh()->sugerencias_prioridad_destino, 'La columna nace en ventas_sucursal.');

        $payload = $this->comercio->fresh()->toArray();

        $this->putJson('api/user/' . $this->comercio->id, array_merge($payload, ['sugerencias_prioridad_destino' => 'ventas_articulo']))
            ->assertStatus(200);
        $this->assertEquals('ventas_articulo', $this->comercio->fresh()->sugerencias_prioridad_destino);

        // Fuera de la lista blanca: se ignora.
        $this->putJson('api/user/' . $this->comercio->id, array_merge($payload, ['sugerencias_prioridad_destino' => 'la_que_yo_quiera']))
            ->assertStatus(200);
        $this->assertEquals('ventas_articulo', $this->comercio->fresh()->sugerencias_prioridad_destino);

        // Sin la clave: no se pisa.
        $sin_la_clave = $payload;
        unset($sin_la_clave['sugerencias_prioridad_destino']);
        $this->putJson('api/user/' . $this->comercio->id, $sin_la_clave)->assertStatus(200);
        $this->assertEquals('ventas_articulo', $this->comercio->fresh()->sugerencias_prioridad_destino);

        // Con null: tampoco.
        $this->putJson('api/user/' . $this->comercio->id, array_merge($payload, ['sugerencias_prioridad_destino' => null]))
            ->assertStatus(200);
        $this->assertEquals('ventas_articulo', $this->comercio->fresh()->sugerencias_prioridad_destino);
    }

    // ------------------------------------------------------------------
    // El motor con madre
    // ------------------------------------------------------------------

    /**
     * El madre manda aunque otra sucursal tenga más stock, y aunque esa otra sea un
     * depósito de origen designado: con madre, el designado es solo respaldo.
     *
     * @group sugerencias-stock
     * @test
     */
    public function con_madre_el_origen_es_el_madre_aunque_otra_tenga_mas_stock()
    {
        $grande    = $this->sucursal('Deposito grande');
        $designado = $this->sucursal('Deposito designado', false, true);
        $madre     = $this->sucursal('Deposito madre', true);
        $chica     = $this->sucursal('Sucursal chica');

        $articulo = $this->articulo_con_stock('Tornillo madre', [
            $grande->id    => ['amount' => 100, 'stock_min' => 10, 'stock_max' => 20],
            $designado->id => ['amount' => 80,  'stock_min' => 10, 'stock_max' => 20],
            $madre->id     => ['amount' => 50,  'stock_min' => 10, 'stock_max' => 20],
            $chica->id     => ['amount' => 2,   'stock_min' => 10, 'stock_max' => 20],
        ]);

        $this->assertSame(
            [[$madre->id, $chica->id, 8.0]],
            $this->movimientos($this->calcular([$articulo->id])),
            'Con depósito madre el origen tiene que ser el madre.'
        );
    }

    /**
     * El madre no alcanza: lo que falta sale de una sucursal SIN déficit (respaldo), y
     * nunca de una que está en déficit, aunque esté designada y con stock disponible.
     * Con 'sin_limite' la sucursal en déficit tiene stock "disponible": sin madre (escalón 2)
     * la elegiría como origen; con madre no puede.
     *
     * @group sugerencias-stock
     * @test
     */
    public function con_madre_que_no_alcanza_completa_desde_el_respaldo_y_nunca_desde_una_en_deficit()
    {
        $madre     = $this->sucursal('Madre corta', true);
        $respaldo  = $this->sucursal('Sucursal sobrada');
        $destino   = $this->sucursal('Sucursal vacia');
        $en_deficit = $this->sucursal('Designada en deficit', false, true);

        $articulo = $this->articulo_con_stock('Clavo respaldo', [
            $madre->id      => ['amount' => 5,  'stock_min' => 10, 'stock_max' => 20],
            $respaldo->id   => ['amount' => 30, 'stock_min' => 10, 'stock_max' => 20],
            $destino->id    => ['amount' => 2,  'stock_min' => 10, 'stock_max' => 20],
            $en_deficit->id => ['amount' => 9,  'stock_min' => 10, 'stock_max' => 20],
        ]);

        $lineas = $this->calcular([$articulo->id], 'sin_limite');

        // Sin ventas las dos facturan 0: desempata el address_id (destino antes que en_deficit).
        // destino necesita 8: 5 del madre + 3 del respaldo. en_deficit necesita 1: del respaldo.
        $this->assertSame([
            [$madre->id, $destino->id, 5.0],
            [$respaldo->id, $destino->id, 3.0],
            [$respaldo->id, $en_deficit->id, 1.0],
        ], $this->movimientos($lineas));

        $origenes = array_column($lineas, 'from_address_id');
        $this->assertNotContains($en_deficit->id, $origenes, 'Una sucursal en déficit nunca es origen con madre.');
        $this->assertNotContains($madre->id, array_column($lineas, 'to_address_id'), 'El madre nunca es destino.');
    }

    /**
     * El madre no tiene fila del artículo: todo sale del respaldo, primero los designados
     * (aunque tengan menos stock) y un destino puede recibir de dos orígenes.
     *
     * @group sugerencias-stock
     * @test
     */
    public function con_madre_sin_fila_del_articulo_todo_sale_del_respaldo()
    {
        $this->sucursal('Madre sin el articulo', true);
        $designado = $this->sucursal('Respaldo designado', false, true);
        $comun     = $this->sucursal('Respaldo comun');
        $destino   = $this->sucursal('Sucursal que necesita');

        $articulo = $this->articulo_con_stock('Arandela sin madre', [
            $designado->id => ['amount' => 20,  'stock_min' => 10, 'stock_max' => 20],
            $comun->id     => ['amount' => 100, 'stock_min' => 10, 'stock_max' => 20],
            $destino->id   => ['amount' => 0,   'stock_min' => 15, 'stock_max' => 20],
        ]);

        // Necesita 15: el designado tiene 10 disponibles (20 - 10) y el resto sale del común.
        $this->assertSame([
            [$designado->id, $destino->id, 10.0],
            [$comun->id, $destino->id, 5.0],
        ], $this->movimientos($this->calcular([$articulo->id])));
    }

    /**
     * El madre en déficit no recibe: se repone por compras. Y como no hay escalón 4, si el
     * único con stock de sobra no existiera, no se movería nada.
     *
     * @group sugerencias-stock
     * @test
     */
    public function el_madre_nunca_aparece_como_destino()
    {
        $madre    = $this->sucursal('Madre vacia', true);
        $respaldo = $this->sucursal('Sucursal con stock');
        $destino  = $this->sucursal('Sucursal en deficit');

        $articulo = $this->articulo_con_stock('Bulon madre vacia', [
            $madre->id    => ['amount' => 0,   'stock_min' => 10, 'stock_max' => 20],
            $respaldo->id => ['amount' => 100, 'stock_min' => 10, 'stock_max' => 20],
            $destino->id  => ['amount' => 2,   'stock_min' => 10, 'stock_max' => 20],
        ]);

        $lineas = $this->calcular([$articulo->id]);

        $this->assertSame([[$respaldo->id, $destino->id, 8.0]], $this->movimientos($lineas));
        $this->assertNotContains($madre->id, array_column($lineas, 'to_address_id'));
    }

    /**
     * 'ventas_sucursal': con stock escaso en el madre se lo lleva la sucursal que más
     * facturó en 90 días. Las ventas viejas, borradas, las consolidaciones de
     * facturación, las ventas en dólares y las cargadas sin terminar no cuentan.
     *
     * @group sugerencias-stock
     * @test
     */
    public function ventas_sucursal_se_lo_lleva_la_que_mas_facturo_en_90_dias()
    {
        $madre = $this->sucursal('Madre escasa', true);
        $menos = $this->sucursal('Sucursal que factura menos');
        $mas   = $this->sucursal('Sucursal que factura mas');

        // La que "factura menos" tiene montos enormes que NO cuentan.
        $this->venta($menos->id, 500, now()->subDays(10));
        $this->venta($menos->id, 90000, now()->subDays(100));
        $borrada = $this->venta($menos->id, 90000, now()->subDays(5));
        Sale::where('id', $borrada->id)->update(['deleted_at' => now()]);
        $this->venta($menos->id, 90000, now()->subDays(5), ['is_consolidacion_facturacion' => 1]);
        $this->venta($menos->id, 90000, now()->subDays(5), ['moneda_id' => 2]);
        // Cargada y no terminada: no es plata facturada (mismo conjunto que Rendimiento).
        $this->venta($menos->id, 90000, now()->subDays(5), ['terminada' => 0]);

        $this->venta($mas->id, 1000, now()->subDays(20));
        // Una venta sin moneda es pesos (Sale::EXPRESION_EN_PESOS): suma.
        $sin_moneda = $this->venta($mas->id, 200, now()->subDays(3));
        DB::table('sales')->where('id', $sin_moneda->id)->update(['moneda_id' => null]);

        $facturacion = (new CoberturaService($this->comercio->id))->facturacion_por_sucursal();
        $this->assertEquals(500.0, $facturacion[$menos->id]);
        $this->assertEquals(1200.0, $facturacion[$mas->id]);
        $this->assertArrayNotHasKey($madre->id, $facturacion);

        // El madre tiene 5 disponibles (15 - mínimo 10); las dos necesitan 5.
        $articulo = $this->articulo_con_stock('Lija escasa', [
            $madre->id => ['amount' => 15, 'stock_min' => 10, 'stock_max' => 20],
            $menos->id => ['amount' => 5,  'stock_min' => 10, 'stock_max' => 20],
            $mas->id   => ['amount' => 5,  'stock_min' => 10, 'stock_max' => 20],
        ]);

        $this->assertSame(
            [[$madre->id, $mas->id, 5.0]],
            $this->movimientos($this->calcular([$articulo->id])),
            'Con stock escaso se lo tiene que llevar la sucursal que más facturó (en pesos, 90 días, ventas reales).'
        );
    }

    /**
     * 'ventas_articulo': se lo lleva la que más vendió ESE artículo, aunque la otra
     * facture mucho más en total.
     *
     * @group sugerencias-stock
     * @test
     */
    public function ventas_articulo_se_lo_lleva_la_que_mas_vendio_ese_articulo()
    {
        $this->criterio('ventas_articulo');

        $madre     = $this->sucursal('Madre escasa VA', true);
        $vende_mas = $this->sucursal('Vende mas este articulo');
        $factura   = $this->sucursal('Factura mas en general');

        $articulo = $this->articulo_con_stock('Pinza por articulo', [
            $madre->id     => ['amount' => 15, 'stock_min' => 10, 'stock_max' => 20],
            $vende_mas->id => ['amount' => 5,  'stock_min' => 10, 'stock_max' => 20],
            $factura->id   => ['amount' => 5,  'stock_min' => 10, 'stock_max' => 20],
        ]);

        $this->venta_de_articulo($articulo->id, $vende_mas->id, 45, 450, now()->subDays(10));
        $this->venta_de_articulo($articulo->id, $factura->id, 9, 90, now()->subDays(10));
        $this->venta($factura->id, 500000, now()->subDays(10));

        $this->assertSame(
            [[$madre->id, $vende_mas->id, 5.0]],
            $this->movimientos($this->calcular([$articulo->id])),
            'Con ventas_articulo se lo tiene que llevar la sucursal que más vende ESE artículo.'
        );

        // Y con el otro criterio, el mismo dato se lo da a la que más factura.
        $this->criterio('ventas_sucursal');

        $this->assertSame(
            [[$madre->id, $factura->id, 5.0]],
            $this->movimientos($this->calcular([$articulo->id]))
        );
    }

    // ------------------------------------------------------------------
    // Sin madre: como hoy
    // ------------------------------------------------------------------

    /**
     * Sin madre propio, todo como hoy, incluido el escalón 4 (todas en déficit: sale de la
     * que más tiene). Ni el madre de OTRO comercio ni un domicilio de comprador marcado a
     * mano en la base cuentan como madre de este.
     *
     * @group sugerencias-stock
     * @test
     */
    public function sin_madre_propio_el_resultado_es_el_historico()
    {
        $otro = User::create([
            'name'     => 'Otro comercio con madre',
            'email'    => 'deposito-madre-ajeno-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);
        $this->sucursal('Madre de otro comercio', true, false, $otro->id);

        $domicilio = Address::create([
            'street'   => 'Domicilio de comprador',
            'user_id'  => $this->comercio->id,
            'buyer_id' => 999999,
        ]);
        // Directo en la base, salteando el hook: aun así no puede contar como madre.
        DB::table('addresses')->where('id', $domicilio->id)->update(['es_deposito_madre' => 1]);

        $this->assertNull(Address::deposito_madre_de($this->comercio->id));

        $a = $this->sucursal('Sucursal con 8');
        $b = $this->sucursal('Sucursal con 2');

        $articulo = $this->articulo_con_stock('Tuerca escalon 4', [
            $a->id => ['amount' => 8, 'stock_min' => 10, 'stock_max' => 20],
            $b->id => ['amount' => 2, 'stock_min' => 10, 'stock_max' => 20],
        ]);

        // Escalón 4 histórico: las dos en déficit, origen = la de más stock (8, sin límite).
        // needed de A es 2 pero A es el origen; B necesita 8 → mueve 8.
        $this->assertSame(
            [[$a->id, $b->id, 8.0]],
            $this->movimientos($this->calcular([$articulo->id], 'sin_limite'))
        );

        // El mismo dato CON un madre propio (que no tiene el artículo) no mueve nada: las dos
        // sucursales están en déficit y con madre no hay escalón 4.
        $this->sucursal('Madre sin este articulo', true);

        $this->assertSame([], $this->movimientos($this->calcular([$articulo->id], 'sin_limite')));
    }

    // ------------------------------------------------------------------
    // Prioridad de las líneas guardadas
    // ------------------------------------------------------------------

    /**
     * Corre el pipeline completo (en sync) y devuelve la prioridad por artículo.
     *
     * @return array article_id => prioridad
     */
    protected function prioridades_del_pipeline()
    {
        $suggestion = StockSuggestion::create([
            'modo'          => 'minimo',
            'origen'        => 'absoluto',
            'limite_origen' => 'minimo',
            'status'        => 'pendiente',
            'user_id'       => $this->comercio->id,
        ]);

        (new GenerateStockSuggestionChunksJob($suggestion->id))->handle();

        $this->assertEquals('terminado', $suggestion->fresh()->status);

        return StockSuggestionArticle::where('stock_suggestion_id', $suggestion->id)
            ->pluck('prioridad', 'article_id')
            ->map(function ($prioridad) {
                return (int) $prioridad;
            })
            ->all();
    }

    /**
     * Sin madre manda la urgencia (cobertura). Con madre manda el criterio: con
     * 'ventas_sucursal' va primero el traslado a la que más factura aunque no sea urgente;
     * con 'ventas_articulo', el de mayor velocidad de venta del artículo en el destino.
     *
     * @group sugerencias-stock
     * @test
     */
    public function asignar_prioridades_con_madre_ordena_por_el_criterio()
    {
        $madre   = $this->sucursal('Madre pipeline', true);
        $chica   = $this->sucursal('Sucursal que factura poco');
        $grande  = $this->sucursal('Sucursal que factura mucho');

        // Urgente: en déficit en la chica, con ventas (velocidad 0,5, cobertura 4 días).
        $urgente = $this->articulo_con_stock('Urgente pipeline', [
            $madre->id => ['amount' => 100, 'stock_min' => 10, 'stock_max' => 20],
            $chica->id => ['amount' => 2,   'stock_min' => 10, 'stock_max' => 20],
        ]);
        $this->venta_de_articulo($urgente->id, $chica->id, 45, 450, now()->subDays(10));

        // Dormido: en déficit en la grande, sin ventas propias (cobertura infinita).
        $dormido = $this->articulo_con_stock('Dormido pipeline', [
            $madre->id  => ['amount' => 100, 'stock_min' => 10, 'stock_max' => 20],
            $grande->id => ['amount' => 2,   'stock_min' => 10, 'stock_max' => 20],
        ]);
        $this->venta($grande->id, 100000, now()->subDays(10));

        // ventas_sucursal (default): primero el traslado a la que más factura.
        $this->assertSame(
            [$urgente->id => 2, $dormido->id => 1],
            $this->ordenar_por_clave($this->prioridades_del_pipeline()),
            'Con madre y ventas_sucursal, la prioridad 1 es el traslado a la sucursal que más factura.'
        );

        // ventas_articulo: primero el de mayor velocidad en el destino.
        $this->criterio('ventas_articulo');

        $this->assertSame(
            [$urgente->id => 1, $dormido->id => 2],
            $this->ordenar_por_clave($this->prioridades_del_pipeline())
        );

        // Sin madre: la urgencia de siempre.
        $madre->es_deposito_madre = 0;
        $madre->save();
        $this->criterio('ventas_sucursal');

        $this->assertSame(
            [$urgente->id => 1, $dormido->id => 2],
            $this->ordenar_por_clave($this->prioridades_del_pipeline())
        );
    }

    /**
     * @param array $mapa
     * @return array El mismo mapa ordenado por clave (para un assertSame estable)
     */
    protected function ordenar_por_clave(array $mapa)
    {
        ksort($mapa);

        return $mapa;
    }
}
