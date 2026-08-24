<?php

namespace Tests\Feature\Puntos;

use App\Models\MovimientoPunto;
use App\Models\Sale;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;

/**
 * Archivo 7 — el CANJE, que viaja adentro del POST de la venta.
 *
 * No hay endpoint de canje: los puntos se canjean en dos campos del request de `/api/sale`
 * (`puntos_canjeados` y `descuento_puntos`) porque el canje ES parte de la venta. Un endpoint
 * aparte permitiría canjear sin vender y dejaría el descuento y el movimiento en dos transacciones
 * distintas.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 LA AUTORIDAD ESTÁ EN EL SERVIDOR, NO EN VENDER
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  `sales.total` lo manda el front YA neteado por el canje, y un POST directo a `/api/sale` llega
 *  igual hasta el controller sin pasar por VENDER. Por eso el `descuento_puntos` del request no se
 *  guarda nunca: se recalcula como `puntos_canjeados * valor_punto` y, si el del request no
 *  coincide, se rechaza con 422 en vez de guardar una venta cuyo total no se puede explicar con sus
 *  propias columnas. Corregirlo en silencio sería peor: dejaría `sales.total` neteado con un número
 *  y `descuento_puntos` con otro.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  ⚠️ `sales.total_a_facturar` NO SE PUEDE MEDIR EN ESTA BASE
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  `SaleHelper::set_total_a_facturar()` solo corre si el request trae `afip_information_id`, y la
 *  base de testing del slot NO TIENE la tabla `afip_informations` (verificado el 21/8/2026). Lo que
 *  sí se puede afirmar, y se afirma acá, es lo que está aguas arriba: `sales.total` baja
 *  exactamente el descuento. `total_a_facturar` sale de `AfipHelper::getImportes()`, que lee
 *  `sale->total` (`AfipImportesCalculator.php:450`), o sea que no hay una segunda cuenta que pueda
 *  discrepar: baja porque bajó el total.
 */
class Canje_en_vender_Test extends PuntosTestCase
{
    /** El punto vale $100 en esta suite, para que los números del canje sean legibles. */
    const VALOR_PUNTO = 100;

    /** Mínimo de canje bajo a propósito: el default de 500 puntos exigiría ventas gigantes. */
    const MINIMO_CANJE = 10;

    /** Total bruto de la venta, antes del canje. */
    const TOTAL_BRUTO = 10000;

    /**
     * Programa + dos lotes de puntos de distinta antigüedad, para poder medir el FIFO.
     *
     * @return array  [\App\Models\Client, \App\Models\MovimientoPunto $viejo, \App\Models\MovimientoPunto $nuevo]
     */
    protected function escenario_con_saldo()
    {
        $this->dar_extencion();

        $sistema = $this->crear_programa([
            'valor_punto'     => self::VALOR_PUNTO,
            'minimo_canje'    => self::MINIMO_CANJE,
            'tope_porcentaje' => 20,
        ]);

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        /*
         * Los lotes se siembran a mano y con `created_at` explícito: el FIFO se ordena por esa
         * columna, y dos lotes creados por el test en el mismo segundo no tendrían un orden
         * comprobable.
         */
        $viejo = $this->sembrar_lote($cliente, $sistema, 8, Carbon::now()->addMonths(12), Carbon::now()->subDays(30));
        $nuevo = $this->sembrar_lote($cliente, $sistema, 20, Carbon::now()->addMonths(12), Carbon::now()->subDays(5));

        return [$cliente, $viejo, $nuevo];
    }

    /**
     * Payload de una venta de mostrador con canje.
     *
     * @param  int    $client_id
     * @param  float  $puntos
     * @param  float|null  $descuento  null = el que corresponde de verdad.
     * @param  float|null  $total      null = el bruto menos el descuento que corresponde.
     * @return array
     */
    protected function payload_con_canje($client_id, $puntos, $descuento = null, $total = null)
    {
        $descuento_real = is_null($descuento) ? $puntos * self::VALOR_PUNTO : $descuento;
        $total_real = is_null($total) ? self::TOTAL_BRUTO - ($puntos * self::VALOR_PUNTO) : $total;

        return $this->payload_venta($client_id, $total_real, [
            'omitir_en_cuenta_corriente' => 1,
            'puntos_canjeados'           => $puntos,
            'descuento_puntos'           => $descuento_real,
            'items'                      => [
                [
                    'is_article'   => true,
                    'id'           => $this->articulo_de_puntos()->id,
                    'price_vender' => self::TOTAL_BRUTO,
                    'amount'       => 1,
                ],
            ],
        ]);
    }

    /**
     * El canje feliz: la venta baja de total, se escriben las dos columnas, el movimiento negativo
     * y las filas de consumo en orden FIFO.
     *
     * @group puntos
     * @test
     */
    public function el_canje_baja_el_total_escribe_el_movimiento_y_consume_los_lotes_por_fifo()
    {
        list($cliente, $viejo, $nuevo) = $this->escenario_con_saldo();

        $saldo_previo = $this->saldo_de_puntos($cliente);

        $this->assertEqualsWithDelta(28, $saldo_previo, self::DELTA, 'El escenario tiene que arrancar con 28 puntos.');

        $venta = $this->crear_venta($this->payload_con_canje($cliente->id, 15));

        // 1. La venta bajó exactamente el descuento.
        $this->assertEqualsWithDelta(
            self::TOTAL_BRUTO - 1500,
            (float) $venta->total,
            self::DELTA,
            'sales.total tiene que ser el bruto menos puntos * valor_punto.'
        );
        $this->assertEqualsWithDelta(15, (float) $venta->puntos_canjeados, self::DELTA);
        $this->assertEqualsWithDelta(1500, (float) $venta->descuento_puntos, self::DELTA);

        // 2. El movimiento negativo del canje.
        $canjes = $this->movimientos_de_la_venta($venta, 'canjeados');

        $this->assertCount(1, $canjes, 'Tiene que haber UN movimiento canjeados por venta.');

        $canje = $canjes->first();

        $this->assertEqualsWithDelta(-15, (float) $canje->puntos, self::DELTA, 'El canje se guarda en NEGATIVO: el saldo es SUM(puntos).');
        $this->assertEquals(0, (int) $canje->price_type_id, 'El canje no se gasta por lista: va con el centinela 0.');
        $this->assertEquals('Canje en venta N° '.$venta->num, $canje->detalle);
        $this->assertEqualsWithDelta(1500, (float) $canje->monto_base, self::DELTA, 'El monto_base del canje son los pesos descontados.');

        // 3. El FIFO: primero el lote viejo, entero; después el nuevo, por la diferencia.
        $consumos = $this->consumos_de($canje);

        $this->assertCount(2, $consumos, 'Los 15 puntos tienen que salir de los dos lotes: 8 del viejo y 7 del nuevo.');

        $this->assertEquals(
            $viejo->id,
            (int) $consumos[0]->movimiento_origen_id,
            'El primer lote consumido tiene que ser el MÁS VIEJO: si no, el vencimiento le come puntos al cliente que compra seguido.'
        );
        $this->assertEqualsWithDelta(8, (float) $consumos[0]->puntos, self::DELTA);

        $this->assertEquals($nuevo->id, (int) $consumos[1]->movimiento_origen_id);
        $this->assertEqualsWithDelta(7, (float) $consumos[1]->puntos, self::DELTA);

        // 4. Los lotes quedaron con su `consumido` al día.
        $viejo->refresh();
        $nuevo->refresh();

        $this->assertEqualsWithDelta(8, (float) $viejo->consumido, self::DELTA, 'El lote viejo tiene que quedar consumido entero.');
        $this->assertEqualsWithDelta(7, (float) $nuevo->consumido, self::DELTA);

        // 5. Y el saldo bajó los 15 puntos, ni uno más.
        $this->assertEqualsWithDelta(
            $saldo_previo - 15 + $this->puntos_ganados_por($venta),
            $this->saldo_de_puntos($cliente),
            self::DELTA,
            'El saldo tiene que bajar los puntos canjeados (y subir los que la propia venta otorgó).'
        );
    }

    /**
     * 🔴 El canje consume los lotes que YA existían, no el que la propia venta acaba de generar.
     *
     * `PuntosCanjeHelper::aplicar()` corre ANTES de `attachProperies()` justamente por esto: si
     * corriera después, el FIFO podría llevarse el lote de la misma compra y el cliente estaría
     * pagando con los puntos que ganó en la compra que está pagando.
     *
     * @group puntos
     * @test
     */
    public function el_canje_no_consume_el_lote_que_genera_la_propia_venta()
    {
        list($cliente, $viejo, $nuevo) = $this->escenario_con_saldo();

        $venta = $this->crear_venta($this->payload_con_canje($cliente->id, 15));

        $lote_de_la_venta = $this->movimientos_de_la_venta($venta, 'ganados')->first();

        if (is_null($lote_de_la_venta)) {
            $this->fail('La venta tenía que otorgar sus propios puntos: sin eso este test no mide nada.');
        }

        $canje = $this->movimientos_de_la_venta($venta, 'canjeados')->first();

        foreach ($this->consumos_de($canje) as $consumo) {
            $this->assertNotEquals(
                $lote_de_la_venta->id,
                (int) $consumo->movimiento_origen_id,
                'El canje se llevó puntos del lote que generó la MISMA venta.'
            );
        }

        $lote_de_la_venta->refresh();

        $this->assertEqualsWithDelta(
            0,
            (float) $lote_de_la_venta->consumido,
            self::DELTA,
            'El lote de la propia venta tiene que quedar intacto.'
        );
    }

    /**
     * 422 por debajo del mínimo de canje, y sin tocar la base.
     *
     * @group puntos
     * @test
     */
    public function canjear_menos_del_minimo_devuelve_422_y_no_crea_la_venta()
    {
        list($cliente, $viejo, $nuevo) = $this->escenario_con_saldo();

        $ventas_antes = Sale::where('client_id', $cliente->id)->count();

        $response = $this->postear_venta($this->payload_con_canje($cliente->id, 5));

        $response->assertStatus(422);

        $cuerpo = json_decode($response->getContent(), true);

        $this->assertTrue($cuerpo['error_canje_puntos'], 'El 422 tiene que traer su bandera propia para que el front lo distinga.');
        $this->assertStringContainsString('al menos', $cuerpo['message']);

        $this->assertEquals(
            $ventas_antes,
            Sale::where('client_id', $cliente->id)->count(),
            'El rechazo corre ANTES de beginTransaction(): no puede crear una venta ni consumir número.'
        );

        $viejo->refresh();

        $this->assertEqualsWithDelta(0, (float) $viejo->consumido, self::DELTA, 'Un canje rechazado no puede tocar los lotes.');
    }

    /**
     * 422 por encima del saldo disponible.
     *
     * @group puntos
     * @test
     */
    public function canjear_mas_que_el_saldo_devuelve_422()
    {
        list($cliente, $viejo, $nuevo) = $this->escenario_con_saldo();

        $response = $this->postear_venta($this->payload_con_canje($cliente->id, 100, 10000, self::TOTAL_BRUTO));

        $response->assertStatus(422);

        $cuerpo = json_decode($response->getContent(), true);

        $this->assertTrue($cuerpo['error_canje_puntos']);
        $this->assertStringContainsString('disponibles', $cuerpo['message']);

        $this->assertEqualsWithDelta(
            28,
            $this->saldo_de_puntos($cliente),
            self::DELTA,
            'Un canje rechazado no puede mover el saldo.'
        );
    }

    /**
     * 🔴 422 por encima del tope de la compra.
     *
     * El tope se mide contra el total ANTES del canje (`total + descuento`), porque el front ya
     * mandó el total neteado. Medirlo contra el neteado haría que el mismo 20% deje pasar un canje
     * más grande cuanto más grande sea el canje.
     *
     * @group puntos
     * @test
     */
    public function canjear_por_encima_del_tope_de_la_compra_devuelve_422()
    {
        list($cliente, $viejo, $nuevo) = $this->escenario_con_saldo();

        // 28 puntos = $2.800, contra un tope del 20% de $10.000 = $2.000.
        $response = $this->postear_venta($this->payload_con_canje($cliente->id, 28));

        $response->assertStatus(422);

        $cuerpo = json_decode($response->getContent(), true);

        $this->assertTrue($cuerpo['error_canje_puntos']);
        $this->assertStringContainsString('20', $cuerpo['message'], 'El mensaje tiene que decirle al vendedor cuál es el tope.');
    }

    /**
     * 🔴 El `descuento_puntos` manipulado en el request se rechaza, no se corrige en silencio.
     *
     * Es el test del POST directo: alguien que no pasa por VENDER manda 15 puntos y un descuento de
     * $1 para llevarse la venta casi gratis. El servidor recalcula $1.500 y, como no coincide,
     * rechaza. Guardar el número del servidor y el total del front sería dejar una venta cuyo total
     * no cierra con sus propias columnas.
     *
     * @group puntos
     * @test
     */
    public function el_descuento_manipulado_en_el_request_se_rechaza()
    {
        list($cliente, $viejo, $nuevo) = $this->escenario_con_saldo();

        $ventas_antes = Sale::where('client_id', $cliente->id)->count();

        $response = $this->postear_venta($this->payload_con_canje($cliente->id, 15, 1, self::TOTAL_BRUTO - 1));

        $response->assertStatus(422);

        $cuerpo = json_decode($response->getContent(), true);

        $this->assertTrue($cuerpo['error_canje_puntos']);
        $this->assertStringContainsString('valor del punto', $cuerpo['message']);

        $this->assertEquals(
            $ventas_antes,
            Sale::where('client_id', $cliente->id)->count(),
            'Con el descuento manipulado no se puede guardar ninguna venta.'
        );
    }

    /**
     * Sin cliente no se puede canjear: los puntos son de alguien.
     *
     * @group puntos
     * @test
     */
    public function canjear_sin_cliente_devuelve_422()
    {
        $this->escenario_con_saldo();

        $response = $this->postear_venta($this->payload_con_canje(null, 15));

        $response->assertStatus(422);

        $cuerpo = json_decode($response->getContent(), true);

        $this->assertTrue($cuerpo['error_canje_puntos']);
        $this->assertStringContainsString('cliente', $cuerpo['message']);
    }

    /**
     * Con el programa apagado no se puede canjear, aunque el cliente tenga saldo de antes.
     *
     * @group puntos
     * @test
     */
    public function canjear_con_el_programa_apagado_devuelve_422()
    {
        list($cliente, $viejo, $nuevo) = $this->escenario_con_saldo();

        \App\Models\SistemaDePuntos::where('user_id', $this->comercio()->id)->update(['activo' => 0]);

        \App\Http\Controllers\Helpers\puntos\PuntosConfigHelper::limpiar_memo();

        $response = $this->postear_venta($this->payload_con_canje($cliente->id, 15));

        $response->assertStatus(422);

        $cuerpo = json_decode($response->getContent(), true);

        $this->assertTrue($cuerpo['error_canje_puntos']);
        $this->assertStringContainsString('no está activo', $cuerpo['message']);
    }

    /**
     * Una venta SIN canje sigue guardándose igual que siempre: la salida barata de la validación es
     * la primera línea y no cuesta ni una query.
     *
     * @group puntos
     * @test
     */
    public function una_venta_sin_canje_se_guarda_igual_que_siempre()
    {
        list($cliente, $viejo, $nuevo) = $this->escenario_con_saldo();

        $venta = $this->crear_venta($this->payload_venta($cliente->id, self::TOTAL_BRUTO, [
            'omitir_en_cuenta_corriente' => 1,
        ]));

        $this->assertNull($venta->puntos_canjeados);
        $this->assertNull($venta->descuento_puntos);
        $this->assertCount(0, $this->movimientos_de_la_venta($venta, 'canjeados'));

        $viejo->refresh();

        $this->assertEqualsWithDelta(0, (float) $viejo->consumido, self::DELTA, 'Una venta sin canje no toca los lotes.');
    }

    /**
     * Los puntos que otorgó una venta, o 0 si no otorgó ninguno.
     *
     * @param  \App\Models\Sale  $venta
     * @return float
     */
    protected function puntos_ganados_por($venta)
    {
        return round((float) MovimientoPunto::where('sale_id', $venta->id)
                                            ->where('tipo', 'ganados')
                                            ->sum('puntos'), 2);
    }
}
