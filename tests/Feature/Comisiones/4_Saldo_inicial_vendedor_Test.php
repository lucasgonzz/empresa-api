<?php

namespace Tests\Feature\Comisiones;

use App\Models\Seller;
use App\Models\SellerCommission;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\EmpresaTestCase;

/**
 * Archivo 4 — saldo inicial del vendedor (mision saldo-inicial-vendedor, 10/10/2026).
 *
 * `POST seller-commission/saldo-inicial` (SellerCommissionController@saldoInicial). Hasta esta
 * mision la API no frenaba nada: se podia cargar el saldo inicial dos veces y con el debe y el
 * haber vacios quedaba una fila sin importe. Ahora:
 *
 *  - Exito: 201 `{model: Seller}` con `seller_commissions_count` ya contando la fila nueva (es lo
 *    que el SPA usa para esconder el boton "Saldo inicial"). La fila se guarda con la descripcion
 *    "Saldo inicial" (antes decia "Pago a vendedor" aun en el debe) y con el saldo recalculado.
 *  - 422 `{error, message}` sin crear nada si ni el debe ni el haber traen un importe mayor a cero.
 *  - 422 `{error, message}` sin crear nada si el vendedor ya tiene cualquier movimiento en esa
 *    moneda (liquidado o pendiente; `moneda_id` nulo cuenta como pesos). En otra moneda, si.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promocion de constructor, readonly, enum ni #[...].
 *
 * @group comisiones
 */
class Saldo_inicial_vendedor_Test extends EmpresaTestCase
{
    const DELTA = 0.001;

    const RUTA = 'api/seller-commission/saldo-inicial';

    const MENSAJE_SIN_IMPORTE = 'Ingresá el saldo inicial en el debe o en el haber.';

    const MENSAJE_YA_TIENE_MOVIMIENTOS = 'Este vendedor ya tiene movimientos: el saldo inicial se carga una sola vez, antes del primer movimiento.';

    /**
     * @var \App\Models\User
     */
    protected $user;

    /**
     * @var \App\Models\Seller
     */
    protected $seller;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
        $this->assertNotNull($this->user, 'Falta el usuario del fixture.');

        $this->seller = Seller::create([
            'num'                       => 970001,
            'name'                      => 'Vendedor test saldo inicial',
            'commission_after_pay_sale' => 1,
            'percentage_commission'     => 10,
            'user_id'                   => $this->user->id,
        ]);
    }

    /**
     * Carga un saldo inicial del vendedor del test.
     *
     * @param array $datos debe, haber, moneda_id (seller_id lo pone el helper).
     * @return \Illuminate\Testing\TestResponse
     */
    protected function cargar_saldo_inicial($datos)
    {
        return $this->postJson(self::RUTA, array_merge(['seller_id' => $this->seller->id], $datos));
    }

    /**
     * Filas de seller_commissions del vendedor del test, en orden de id.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function filas()
    {
        return SellerCommission::where('seller_id', $this->seller->id)->orderBy('id')->get();
    }

    /**
     * Crea a mano un movimiento previo del vendedor.
     *
     * @param array $datos
     * @return \App\Models\SellerCommission
     */
    protected function movimiento_previo($datos)
    {
        $defaults = [
            'user_id'      => $this->user->id,
            'seller_id'    => $this->seller->id,
            'status'       => 'active',
            'debe'         => 100,
            'haber'        => null,
            'moneda_id'    => 1,
            'liquidada_at' => now(),
        ];

        return SellerCommission::create(array_merge($defaults, $datos));
    }

    /**
     * Saldo inicial en el debe: 201, una fila con la descripcion "Saldo inicial", el debe, sin
     * haber, liquidada, en pesos y con el saldo ya recalculado. La respuesta trae el vendedor con
     * `seller_commissions_count` en 1, que es lo que esconde el boton en el SPA.
     *
     * @test
     */
    public function saldo_inicial_en_el_debe()
    {
        $response = $this->cargar_saldo_inicial(['debe' => 50000, 'haber' => '', 'moneda_id' => 1]);

        $response->assertStatus(201);
        $this->assertEquals($this->seller->id, $response->json('model.id'));
        $this->assertEquals(1, $response->json('model.seller_commissions_count'));

        $filas = $this->filas();
        $this->assertCount(1, $filas);

        $fila = $filas[0];
        $this->assertEquals('Saldo inicial', $fila->description);
        $this->assertEquals('active', $fila->status);
        $this->assertEquals(1, $fila->moneda_id);
        $this->assertNull($fila->sale_id);
        $this->assertNull($fila->haber);
        $this->assertNotNull($fila->liquidada_at);
        $this->assertEquals($this->user->id, $fila->user_id);
        $this->assertEqualsWithDelta(50000, (float) $fila->debe, self::DELTA);
        $this->assertEqualsWithDelta(50000, (float) $fila->saldo, self::DELTA);
    }

    /**
     * Saldo inicial en el haber (lo que ya se le pago): 201, la fila lleva el haber, el debe queda
     * null aunque venga en 0 (un 0 no es un importe), la descripcion es "Saldo inicial" y el saldo
     * queda negativo. Sin `moneda_id` en el pedido se toma pesos.
     *
     * @test
     */
    public function saldo_inicial_en_el_haber()
    {
        $response = $this->cargar_saldo_inicial(['debe' => 0, 'haber' => 30000]);

        $response->assertStatus(201);
        $this->assertEquals(1, $response->json('model.seller_commissions_count'));

        $filas = $this->filas();
        $this->assertCount(1, $filas);

        $fila = $filas[0];
        $this->assertEquals('Saldo inicial', $fila->description);
        $this->assertEquals(1, $fila->moneda_id);
        $this->assertNull($fila->debe);
        $this->assertEqualsWithDelta(30000, (float) $fila->haber, self::DELTA);
        $this->assertEqualsWithDelta(-30000, (float) $fila->saldo, self::DELTA);
    }

    /**
     * El defecto que se filmo en demo2: un segundo saldo inicial en la misma moneda. Ahora es 422
     * con el mensaje en voz de comerciante, y sigue habiendo UNA sola fila con el saldo del
     * primero.
     *
     * @test
     */
    public function segundo_saldo_inicial_en_la_misma_moneda_responde_422()
    {
        $this->cargar_saldo_inicial(['debe' => 50000, 'moneda_id' => 1])->assertStatus(201);

        $response = $this->cargar_saldo_inicial(['debe' => 20000, 'moneda_id' => 1]);

        $response->assertStatus(422);
        $response->assertJson([
            'error'   => true,
            'message' => self::MENSAJE_YA_TIENE_MOVIMIENTOS,
        ]);

        $filas = $this->filas();
        $this->assertCount(1, $filas);
        $this->assertEqualsWithDelta(50000, (float) $filas[0]->debe, self::DELTA);
        $this->assertEqualsWithDelta(50000, (float) $filas[0]->saldo, self::DELTA);
    }

    /**
     * Sin importe: los dos vacios, los dos en cero, o sin mandarlos. 422 con el mismo texto que
     * muestra el SPA y ninguna fila (antes quedaba una fila sin importe que escondia el boton
     * para siempre).
     *
     * @test
     */
    public function sin_importe_responde_422_y_no_crea_nada()
    {
        $casos = [
            ['debe' => '', 'haber' => ''],
            ['debe' => 0, 'haber' => 0],
            ['debe' => '0', 'haber' => null],
            [],
        ];

        foreach ($casos as $i => $caso) {
            $response = $this->cargar_saldo_inicial($caso);

            $response->assertStatus(422);
            $response->assertJson([
                'error'   => true,
                'message' => self::MENSAJE_SIN_IMPORTE,
            ]);

            $this->assertCount(0, $this->filas(), 'El caso '.$i.' no tenia que crear ninguna fila.');
        }
    }

    /**
     * Una comision PENDIENTE (inactive, sin saldo) ya es un movimiento del vendedor: el saldo
     * inicial va antes de todo, asi que tambien frena.
     *
     * @test
     */
    public function con_una_comision_pendiente_responde_422()
    {
        $this->movimiento_previo(['status' => 'inactive', 'debe' => 400, 'liquidada_at' => null]);

        $response = $this->cargar_saldo_inicial(['debe' => 50000, 'moneda_id' => 1]);

        $response->assertStatus(422);
        $response->assertJson([
            'error'   => true,
            'message' => self::MENSAJE_YA_TIENE_MOVIMIENTOS,
        ]);

        $filas = $this->filas();
        $this->assertCount(1, $filas);
        $this->assertEquals('inactive', $filas[0]->status);
    }

    /**
     * Una fila historica con `moneda_id` nulo es pesos (mismo criterio que recalcular_saldos):
     * frena un saldo inicial en pesos aunque el pedido no mande la moneda.
     *
     * @test
     */
    public function un_movimiento_con_moneda_nula_cuenta_como_pesos()
    {
        $this->movimiento_previo(['moneda_id' => null]);

        $response = $this->cargar_saldo_inicial(['debe' => 50000]);

        $response->assertStatus(422);
        $response->assertJson(['message' => self::MENSAJE_YA_TIENE_MOVIMIENTOS]);
        $this->assertCount(1, $this->filas());
    }

    /**
     * Cada moneda tiene su propio ledger: con movimientos solo en pesos, el saldo inicial en
     * dolares se carga (201), con su saldo en dolares, sin tocar el ledger en pesos.
     *
     * @test
     */
    public function en_otra_moneda_con_movimientos_solo_en_pesos_se_carga()
    {
        $previo = $this->movimiento_previo(['debe' => 100, 'moneda_id' => 1]);

        $response = $this->cargar_saldo_inicial(['debe' => 500, 'moneda_id' => 2]);

        $response->assertStatus(201);
        $this->assertEquals(2, $response->json('model.seller_commissions_count'));

        $en_dolares = SellerCommission::where('seller_id', $this->seller->id)
                                        ->where('moneda_id', 2)
                                        ->get();
        $this->assertCount(1, $en_dolares);
        $this->assertEquals('Saldo inicial', $en_dolares[0]->description);
        $this->assertEqualsWithDelta(500, (float) $en_dolares[0]->debe, self::DELTA);
        $this->assertEqualsWithDelta(500, (float) $en_dolares[0]->saldo, self::DELTA);

        // El ledger en pesos no se toco.
        $previo->refresh();
        $this->assertEqualsWithDelta(100, (float) $previo->debe, self::DELTA);
        $this->assertCount(2, $this->filas());
    }
}
