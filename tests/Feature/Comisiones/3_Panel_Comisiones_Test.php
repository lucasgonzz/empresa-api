<?php

namespace Tests\Feature\Comisiones;

use App\Http\Controllers\Helpers\comisiones\ComisionesHelper;
use App\Models\Client;
use App\Models\Sale;
use App\Models\Seller;
use App\Models\SellerCommission;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\EmpresaTestCase;

/**
 * Archivo 3 — panel "Comisiones de vendedor" (mision comisiones-vendedor-tablas, 24/9/2026).
 *
 * Prueba los tres endpoints nuevos (`seller-commission-panel/{seller}/{moneda}/resumen`,
 * `/liquidadas` y `/pendientes`) contra un ledger armado a mano con fechas fijas:
 *
 *  Ledger en pesos del vendedor (filas `active`), en orden de CREACION (= orden de id):
 *
 *   id  fila  efecto   created_at         liquidada_at       fecha_mov
 *   #1  A     +1000    2026-01-05 10:00   2026-01-05 10:00   2026-01-05 10:00  (saldo inicial)
 *   #2  B     +200     2026-02-01 09:00   2026-04-10 12:00   2026-04-10 12:00  (venta VIEJA saldada tarde)
 *   #3  C     +300     2026-03-01 10:00   2026-03-05 10:00   2026-03-05 10:00  (venta)
 *   #4  D     -500     2026-03-20 15:00   2026-03-20 15:00   2026-03-20 15:00  (pago)
 *   #5  E     +150     2026-04-01 11:00   2026-04-02 11:00   2026-04-02 11:00
 *   #6  F     +50      2026-04-15 08:00   NULL               2026-04-15 08:00  (moneda NULL, sin liquidada_at)
 *   #7  G     -100     2026-05-01 09:00   2026-05-01 09:00   2026-05-01 09:00  (pago)
 *   #8  H     +70      2026-03-25 10:00   2026-04-02 11:00   2026-04-02 11:00  (EMPATE de fecha con E)
 *
 *  Saldo calculado en orden (fecha_mov ASC, id ASC) — lo que tiene que devolver el endpoint:
 *
 *   A 1000 → C 1000+300=1300 → D 1300-500=800 → E 800+150=950 → H 950+70=1020 (empata con E,
 *   va despues por id) → B 1020+200=1220 → F 1220+50=1270 → G 1270-100=1170.
 *
 *  Saldo GUARDADO por recalcular_saldos (orden de id, NO se usa en el panel):
 *
 *   A 1000 → B 1200 → C 1500 → D 1000 → E 1150 → F 1200 → G 1100 → H 1170.
 *
 *  B es el caso clave: su venta es vieja (id bajo) pero entro al ledger el 10/4, DESPUES del pago D
 *  del 20/3. Guardado dice 1200; calculado dice 1220. El saldo final es 1170 en los dos ordenes
 *  (es una suma): coincide con el saldo guardado de la ultima fila por id (H).
 *
 *  Pendientes (`inactive`) en pesos: P1 400 (venta del 2026-04-20), P2 250 (moneda NULL, venta
 *  del 2026-05-10). Ruido que NO tiene que aparecer: dolares (999 activa + 77 pendiente), otro
 *  vendedor del mismo comercio, y filas del mismo seller_id pero de OTRO user_id.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promocion de constructor, readonly, enum ni #[...].
 *
 * @group comisiones
 */
class Panel_Comisiones_Test extends EmpresaTestCase
{
    const DELTA = 0.001;

    const RUTA = 'api/seller-commission-panel/';

    /**
     * Id del user "ajeno" para probar el aislamiento por user_id (existe en la base de testing:
     * tenant de los tests de importacion). seller_commissions no tiene FK sobre user_id.
     */
    const OTRO_USER_ID = 900;

    /**
     * @var \App\Models\User
     */
    protected $user;

    /**
     * @var \App\Models\Seller
     */
    protected $seller;

    /**
     * Filas del fixture por letra (A..H, P1, P2).
     *
     * @var array
     */
    protected $filas = [];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
        $this->assertNotNull($this->user, 'Falta el usuario del fixture.');
    }

    /**
     * Crea un vendedor del comercio de prueba.
     *
     * @param int $sufijo
     * @return \App\Models\Seller
     */
    protected function crear_seller($sufijo)
    {
        return Seller::create([
            'num'                       => 960000 + $sufijo,
            'name'                      => 'Vendedor test panel comisiones '.$sufijo,
            'commission_after_pay_sale' => 1,
            'percentage_commission'     => 10,
            'user_id'                   => $this->user->id,
        ]);
    }

    /**
     * Crea una venta minima (sin renglones: el panel solo necesita que la relacion `sale` viaje).
     *
     * @param \App\Models\Seller $seller
     * @param float $total
     * @return \App\Models\Sale
     */
    protected function crear_venta($seller, $total)
    {
        $client = Client::where('name', TestingFerreteriaSeeder::CLIENTE_CONTADO)->first();
        $this->assertNotNull($client, 'Falta el cliente del fixture.');

        return Sale::create([
            'user_id'                          => $this->user->id,
            'client_id'                        => $client->id,
            'seller_id'                        => $seller->id,
            'omitir_en_cuenta_corriente'       => 0,
            'save_current_acount'              => 0,
            'terminada'                        => 1,
            'is_cerrada'                       => 0,
            'sub_total'                        => $total,
            'total'                            => $total,
            'moneda_id'                        => 1,
            'descuento'                        => 0,
            'aplicar_recargos_directo_a_items' => 0,
        ]);
    }

    /**
     * Crea una fila de seller_commissions con fechas explicitas.
     *
     * @param array $datos seller_id, status, debe, haber, moneda_id, created_at, liquidada_at,
     *                     sale_id, user_id (opcional: por defecto el del fixture).
     * @return \App\Models\SellerCommission
     */
    protected function fila($datos)
    {
        $defaults = [
            'user_id'      => $this->user->id,
            'status'       => 'active',
            'debe'         => null,
            'haber'        => null,
            'moneda_id'    => 1,
            'liquidada_at' => null,
            'sale_id'      => null,
        ];

        return SellerCommission::create(array_merge($defaults, $datos));
    }

    /**
     * Arma el ledger descripto en el PHPDoc de la clase.
     *
     * @return void
     */
    protected function armar_ledger()
    {
        $this->seller = $this->crear_seller(1);
        $s = $this->seller->id;

        $venta_b  = $this->crear_venta($this->seller, 2000);
        $venta_c  = $this->crear_venta($this->seller, 3000);
        $venta_p2 = $this->crear_venta($this->seller, 2500);

        // El orden de estas llamadas fija el orden de id (A=#1 ... H=#8 relativo).
        $this->filas['A'] = $this->fila(['seller_id' => $s, 'debe' => 1000, 'created_at' => '2026-01-05 10:00:00', 'liquidada_at' => '2026-01-05 10:00:00']);
        $this->filas['B'] = $this->fila(['seller_id' => $s, 'debe' => 200, 'sale_id' => $venta_b->id, 'created_at' => '2026-02-01 09:00:00', 'liquidada_at' => '2026-04-10 12:00:00']);
        $this->filas['C'] = $this->fila(['seller_id' => $s, 'debe' => 300, 'sale_id' => $venta_c->id, 'created_at' => '2026-03-01 10:00:00', 'liquidada_at' => '2026-03-05 10:00:00']);
        $this->filas['D'] = $this->fila(['seller_id' => $s, 'haber' => 500, 'created_at' => '2026-03-20 15:00:00', 'liquidada_at' => '2026-03-20 15:00:00']);
        $this->filas['E'] = $this->fila(['seller_id' => $s, 'debe' => 150, 'created_at' => '2026-04-01 11:00:00', 'liquidada_at' => '2026-04-02 11:00:00']);
        $this->filas['F'] = $this->fila(['seller_id' => $s, 'debe' => 50, 'moneda_id' => null, 'created_at' => '2026-04-15 08:00:00', 'liquidada_at' => null]);
        $this->filas['G'] = $this->fila(['seller_id' => $s, 'haber' => 100, 'created_at' => '2026-05-01 09:00:00', 'liquidada_at' => '2026-05-01 09:00:00']);
        $this->filas['H'] = $this->fila(['seller_id' => $s, 'debe' => 70, 'created_at' => '2026-03-25 10:00:00', 'liquidada_at' => '2026-04-02 11:00:00']);

        // Pendientes en pesos.
        $this->filas['P1'] = $this->fila(['seller_id' => $s, 'status' => 'inactive', 'debe' => 400, 'created_at' => '2026-04-20 10:00:00']);
        $this->filas['P2'] = $this->fila(['seller_id' => $s, 'status' => 'inactive', 'debe' => 250, 'moneda_id' => null, 'sale_id' => $venta_p2->id, 'created_at' => '2026-05-10 10:00:00']);

        // Ruido en dolares: no puede aparecer en pesos.
        $this->fila(['seller_id' => $s, 'debe' => 999, 'moneda_id' => 2, 'created_at' => '2026-03-10 10:00:00', 'liquidada_at' => '2026-03-10 10:00:00']);
        $this->fila(['seller_id' => $s, 'status' => 'inactive', 'debe' => 77, 'moneda_id' => 2, 'created_at' => '2026-03-10 10:00:00']);

        // Ruido de otro vendedor del mismo comercio.
        $otro_seller = $this->crear_seller(2);
        $this->fila(['seller_id' => $otro_seller->id, 'debe' => 8000, 'created_at' => '2026-04-04 10:00:00', 'liquidada_at' => '2026-04-04 10:00:00']);
        $this->fila(['seller_id' => $otro_seller->id, 'status' => 'inactive', 'debe' => 6000, 'created_at' => '2026-04-04 10:00:00']);

        // Saldos guardados (orden de id) — recalcular_saldos no filtra por user_id, por eso se
        // corre ANTES de sembrar las filas del otro user.
        ComisionesHelper::recalcular_saldos($s, 1);
        ComisionesHelper::recalcular_saldos($s, 2);

        // Ruido de OTRO user_id con el mismo seller_id: no puede aparecer ni sumar.
        $this->fila(['seller_id' => $s, 'user_id' => self::OTRO_USER_ID, 'debe' => 5000, 'created_at' => '2026-04-03 10:00:00', 'liquidada_at' => '2026-04-03 10:00:00']);
        $this->fila(['seller_id' => $s, 'user_id' => self::OTRO_USER_ID, 'status' => 'inactive', 'debe' => 3000, 'created_at' => '2026-04-21 10:00:00']);
    }

    /**
     * GET de un endpoint del panel del vendedor armado.
     *
     * @param string $endpoint resumen|liquidadas|pendientes
     * @param string $query Query string sin el '?'.
     * @param int $moneda_id
     * @param int|null $seller_id
     * @return array JSON decodificado.
     */
    protected function pedir($endpoint, $query = '', $moneda_id = 1, $seller_id = null)
    {
        $seller_id = is_null($seller_id) ? $this->seller->id : $seller_id;

        $response = $this->getJson(self::RUTA.$seller_id.'/'.$moneda_id.'/'.$endpoint.($query !== '' ? '?'.$query : ''));
        $response->assertStatus(200);

        return $response->json();
    }

    /**
     * Compara la pagina de liquidadas contra [letra => saldo esperado] en el orden dado.
     *
     * @param array $esperado Lista de [letra, saldo] en el orden en que tiene que venir.
     * @param array $data `data` del paginador.
     * @return void
     */
    protected function assert_filas_y_saldos($esperado, $data)
    {
        $this->assertCount(count($esperado), $data, 'Cantidad de filas distinta.');

        foreach ($esperado as $i => $par) {
            list($letra, $saldo) = $par;
            $this->assertEquals($this->filas[$letra]->id, $data[$i]['id'], 'La fila '.$i.' tenia que ser '.$letra.'.');
            $this->assertArrayHasKey('saldo_calculado', $data[$i]);
            $this->assertEqualsWithDelta($saldo, (float) $data[$i]['saldo_calculado'], self::DELTA, 'Saldo de la fila '.$letra.'.');
        }
    }

    /**
     * Tarjetas sin rango: historico completo. Saldo 1000+200+300-500+150+50-100+70 = 1170;
     * pendiente 400+250 = 650 (P2 con moneda NULL cuenta como pesos); pagado 500+100 = 600.
     * Ni los dolares, ni el otro vendedor, ni el otro user_id suman.
     *
     * @test
     */
    public function resumen_historico_sin_rango()
    {
        $this->armar_ledger();

        $r = $this->pedir('resumen');

        $this->assertEqualsWithDelta(1170, $r['totales']['saldo'], self::DELTA);
        $this->assertEqualsWithDelta(650, $r['totales']['total_pendiente'], self::DELTA);
        $this->assertEqualsWithDelta(600, $r['totales']['total_pagado'], self::DELTA);
        $this->assertNull($r['rango']['desde']);
        $this->assertNull($r['rango']['hasta']);

        // El saldo historico es el mismo que el saldo GUARDADO de la ultima fila por id (H = 1170):
        // mismo total, distinto orden.
        $this->assertEqualsWithDelta((float) $this->filas['H']->fresh()->saldo, $r['totales']['saldo'], self::DELTA);
    }

    /**
     * Con rango 2026-03-01 a 2026-04-30:
     *  - saldo al 30/4: todas las active con fecha_mov <= 30/4 = A,C,D,E,H,B,F =
     *    1000+300-500+150+70+200+50 = 1270 (G, del 1/5, queda afuera; `desde` NO recorta el saldo).
     *  - pendiente: P1 (venta del 20/4) = 400; P2 (10/5) queda afuera.
     *  - pagado en el periodo: D (20/3) = 500; G (1/5) afuera.
     *
     * @test
     */
    public function resumen_con_rango_completo()
    {
        $this->armar_ledger();

        $r = $this->pedir('resumen', 'desde=2026-03-01&hasta=2026-04-30');

        $this->assertEqualsWithDelta(1270, $r['totales']['saldo'], self::DELTA);
        $this->assertEqualsWithDelta(400, $r['totales']['total_pendiente'], self::DELTA);
        $this->assertEqualsWithDelta(500, $r['totales']['total_pagado'], self::DELTA);
        $this->assertEquals('2026-03-01', $r['rango']['desde']);
        $this->assertEquals('2026-04-30', $r['rango']['hasta']);
    }

    /**
     * `hasta` inclusive por DIA completo: hasta=2026-04-02 incluye E y H (las dos del 2/4 a las
     * 11:00). Saldo = A,C,D,E,H = 1000+300-500+150+70 = 1020. Pagado = D = 500. Pendiente = 0
     * (P1 y P2 son posteriores).
     *
     * Solo `desde`=2026-04-01: el saldo NO cambia (1170, es acumulado); pagado = G (1/5) = 100;
     * pendiente = P1 + P2 = 650.
     *
     * @test
     */
    public function resumen_con_un_solo_extremo_del_rango()
    {
        $this->armar_ledger();

        $r = $this->pedir('resumen', 'hasta=2026-04-02');
        $this->assertEqualsWithDelta(1020, $r['totales']['saldo'], self::DELTA);
        $this->assertEqualsWithDelta(500, $r['totales']['total_pagado'], self::DELTA);
        $this->assertEqualsWithDelta(0, $r['totales']['total_pendiente'], self::DELTA);

        $r = $this->pedir('resumen', 'desde=2026-04-01');
        $this->assertEqualsWithDelta(1170, $r['totales']['saldo'], self::DELTA);
        $this->assertEqualsWithDelta(100, $r['totales']['total_pagado'], self::DELTA);
        $this->assertEqualsWithDelta(650, $r['totales']['total_pendiente'], self::DELTA);
    }

    /**
     * Fechas mal formadas se ignoran (quedan como "sin rango"): 30/2 no existe y 'basura' no es
     * Y-m-d. El resultado es el historico completo.
     *
     * @test
     */
    public function resumen_ignora_fechas_invalidas()
    {
        $this->armar_ledger();

        $r = $this->pedir('resumen', 'desde=2026-02-30&hasta=basura');

        $this->assertNull($r['rango']['desde']);
        $this->assertNull($r['rango']['hasta']);
        $this->assertEqualsWithDelta(1170, $r['totales']['saldo'], self::DELTA);
        $this->assertEqualsWithDelta(650, $r['totales']['total_pendiente'], self::DELTA);
        $this->assertEqualsWithDelta(600, $r['totales']['total_pagado'], self::DELTA);
    }

    /**
     * En dolares solo cuentan las filas con moneda_id = 2: saldo 999, pendiente 77, pagado 0. Y la
     * tabla de liquidadas en dolares trae solo esa fila, con saldo 999.
     *
     * @test
     */
    public function aislamiento_por_moneda()
    {
        $this->armar_ledger();

        $r = $this->pedir('resumen', '', 2);
        $this->assertEqualsWithDelta(999, $r['totales']['saldo'], self::DELTA);
        $this->assertEqualsWithDelta(77, $r['totales']['total_pendiente'], self::DELTA);
        $this->assertEqualsWithDelta(0, $r['totales']['total_pagado'], self::DELTA);

        $l = $this->pedir('liquidadas', '', 2);
        $this->assertEquals(1, $l['total']);
        $this->assertEqualsWithDelta(999, (float) $l['data'][0]['debe'], self::DELTA);
        $this->assertEqualsWithDelta(999, (float) $l['data'][0]['saldo_calculado'], self::DELTA);

        $p = $this->pedir('pendientes', '', 2);
        $this->assertEquals(1, $p['total']);
        $this->assertEqualsWithDelta(77, (float) $p['data'][0]['debe'], self::DELTA);
    }

    /**
     * Tabla de liquidadas sin rango, tipo todos: orden (fecha_mov DESC, id DESC) = G, F, B, H, E,
     * D, C, A (H antes que E: misma fecha, id mayor). Saldos calculados a mano en el PHPDoc de la
     * clase. El caso clave es B: saldo_calculado 1220 aunque el saldo GUARDADO (orden de id) sea
     * 1200.
     *
     * Tambien verifica que viajan `sale` (B tiene venta; A, el saldo inicial, no) y
     * `payment_methods`, y que las filas del otro user_id no aparecen (total = 8).
     *
     * @test
     */
    public function liquidadas_orden_y_saldo_calculado_fila_por_fila()
    {
        $this->armar_ledger();

        $l = $this->pedir('liquidadas');

        $this->assertEquals(8, $l['total']);
        $this->assertEquals(1, $l['current_page']);
        $this->assertEquals(1, $l['last_page']);
        $this->assertEquals(15, $l['per_page']);

        $this->assert_filas_y_saldos([
            ['G', 1170],
            ['F', 1270],
            ['B', 1220],
            ['H', 1020],
            ['E', 950],
            ['D', 800],
            ['C', 1300],
            ['A', 1000],
        ], $l['data']);

        // Caso clave: la comision de la venta vieja (id bajo) que entro al ledger despues del pago
        // D. El saldo guardado sigue el orden de id (1200) y NO es el que muestra el panel (1220).
        $this->assertEqualsWithDelta(1200, (float) $this->filas['B']->fresh()->saldo, self::DELTA);
        $this->assertTrue($this->filas['B']->id < $this->filas['D']->id, 'B tiene que ser mas vieja por id que el pago D.');

        // La fila mas nueva del ledger lleva el saldo total, igual al guardado de la ultima por id.
        $this->assertEqualsWithDelta((float) $this->filas['H']->fresh()->saldo, (float) $l['data'][0]['saldo_calculado'], self::DELTA);

        // Relaciones que usa el modal.
        $fila_b = $l['data'][2];
        $this->assertNotNull($fila_b['sale']);
        $this->assertEquals($this->filas['B']->sale_id, $fila_b['sale']['id']);
        $this->assertArrayHasKey('payment_methods', $fila_b);
        $this->assertNull($l['data'][7]['sale'], 'El saldo inicial no tiene venta.');

        // fecha_mov: F no tiene liquidada_at, cae en created_at.
        $this->assertEquals('2026-04-15 08:00:00', $l['data'][1]['fecha_mov']);
    }

    /**
     * Con rango 2026-03-01 a 2026-04-30 la tabla muestra F, B, H, E, D, C (A es de enero y G de
     * mayo). El saldo de cada fila sigue siendo el del LEDGER COMPLETO (A cuenta aunque no se
     * muestre): mismos valores que sin rango.
     *
     * @test
     */
    public function liquidadas_con_rango()
    {
        $this->armar_ledger();

        $l = $this->pedir('liquidadas', 'desde=2026-03-01&hasta=2026-04-30');

        $this->assertEquals(6, $l['total']);
        $this->assert_filas_y_saldos([
            ['F', 1270],
            ['B', 1220],
            ['H', 1020],
            ['E', 950],
            ['D', 800],
            ['C', 1300],
        ], $l['data']);
    }

    /**
     * Filtro de tipo. Pagos = G, D con sus saldos del ledger completo (1170 y 800: las comisiones
     * escondidas en el medio siguen moviendo el saldo). Comisiones = F, B, H, E, C, A. Pagos con
     * rango 03-01..04-30 = solo D (800). Un tipo desconocido cae en 'todos' (8 filas).
     *
     * @test
     */
    public function liquidadas_filtro_de_tipo()
    {
        $this->armar_ledger();

        $l = $this->pedir('liquidadas', 'tipo=pagos');
        $this->assertEquals(2, $l['total']);
        $this->assert_filas_y_saldos([
            ['G', 1170],
            ['D', 800],
        ], $l['data']);

        $l = $this->pedir('liquidadas', 'tipo=comisiones');
        $this->assertEquals(6, $l['total']);
        $this->assert_filas_y_saldos([
            ['F', 1270],
            ['B', 1220],
            ['H', 1020],
            ['E', 950],
            ['C', 1300],
            ['A', 1000],
        ], $l['data']);

        $l = $this->pedir('liquidadas', 'tipo=pagos&desde=2026-03-01&hasta=2026-04-30');
        $this->assert_filas_y_saldos([
            ['D', 800],
        ], $l['data']);

        $l = $this->pedir('liquidadas', 'tipo=cualquiera');
        $this->assertEquals(8, $l['total']);
    }

    /**
     * Pendientes: orden created_at DESC = P2 (10/5), P1 (20/4). Sin saldo_calculado. P2 trae su
     * venta. Con rango 03-01..04-30 solo P1. El otro user_id (3000), el otro vendedor (6000) y los
     * dolares (77) no aparecen.
     *
     * @test
     */
    public function pendientes_orden_rango_y_aislamiento()
    {
        $this->armar_ledger();

        $p = $this->pedir('pendientes');
        $this->assertEquals(2, $p['total']);
        $this->assertEquals($this->filas['P2']->id, $p['data'][0]['id']);
        $this->assertEquals($this->filas['P1']->id, $p['data'][1]['id']);
        $this->assertArrayNotHasKey('saldo_calculado', $p['data'][0]);
        $this->assertNotNull($p['data'][0]['sale']);
        $this->assertArrayHasKey('payment_methods', $p['data'][0]);

        $p = $this->pedir('pendientes', 'desde=2026-03-01&hasta=2026-04-30');
        $this->assertEquals(1, $p['total']);
        $this->assertEquals($this->filas['P1']->id, $p['data'][0]['id']);
    }

    /**
     * Paginacion: 20 comisiones de $10 (una por dia desde el 1/6) + 20 pendientes. Liquidadas:
     * pagina 1 = 15 filas (la mas nueva con saldo 20*10 = 200, la numero 15 con 200-14*10 = 60),
     * pagina 2 = 5 filas (50, 40, 30, 20, 10). El saldo sigue siendo continuo entre paginas. Con
     * tipo=comisiones (camino del tramo del ledger con huecos) la pagina 2 da lo mismo. Pendientes: 15 + 5.
     *
     * @test
     */
    public function paginacion_de_a_quince()
    {
        $this->seller = $this->crear_seller(3);
        $s = $this->seller->id;

        for ($i = 1; $i <= 20; $i++) {
            $dia = sprintf('2026-06-%02d 10:00:00', $i);
            $this->fila(['seller_id' => $s, 'debe' => 10, 'created_at' => $dia, 'liquidada_at' => $dia]);
            $this->fila(['seller_id' => $s, 'status' => 'inactive', 'debe' => 5, 'created_at' => $dia]);
        }

        $p1 = $this->pedir('liquidadas');
        $this->assertEquals(20, $p1['total']);
        $this->assertEquals(2, $p1['last_page']);
        $this->assertEquals(15, $p1['per_page']);
        $this->assertCount(15, $p1['data']);
        $this->assertEquals('2026-06-20 10:00:00', $p1['data'][0]['fecha_mov']);
        $this->assertEqualsWithDelta(200, (float) $p1['data'][0]['saldo_calculado'], self::DELTA);
        $this->assertEqualsWithDelta(60, (float) $p1['data'][14]['saldo_calculado'], self::DELTA);

        $p2 = $this->pedir('liquidadas', 'page=2');
        $this->assertEquals(2, $p2['current_page']);
        $this->assertCount(5, $p2['data']);
        $saldos = [];
        foreach ($p2['data'] as $fila) {
            $saldos[] = (float) $fila['saldo_calculado'];
        }
        $this->assertEquals([50.0, 40.0, 30.0, 20.0, 10.0], $saldos);
        $this->assertEquals('2026-06-01 10:00:00', $p2['data'][4]['fecha_mov']);

        $p2c = $this->pedir('liquidadas', 'page=2&tipo=comisiones');
        $saldos = [];
        foreach ($p2c['data'] as $fila) {
            $saldos[] = (float) $fila['saldo_calculado'];
        }
        $this->assertEquals([50.0, 40.0, 30.0, 20.0, 10.0], $saldos);

        $pp1 = $this->pedir('pendientes');
        $this->assertEquals(20, $pp1['total']);
        $this->assertEquals(2, $pp1['last_page']);
        $this->assertCount(15, $pp1['data']);

        $pp2 = $this->pedir('pendientes', 'page=2');
        $this->assertCount(5, $pp2['data']);

        // Resumen del mismo vendedor: saldo 200, pendiente 20*5 = 100, pagado 0.
        $r = $this->pedir('resumen');
        $this->assertEqualsWithDelta(200, $r['totales']['saldo'], self::DELTA);
        $this->assertEqualsWithDelta(100, $r['totales']['total_pendiente'], self::DELTA);
        $this->assertEqualsWithDelta(0, $r['totales']['total_pagado'], self::DELTA);
    }
}
