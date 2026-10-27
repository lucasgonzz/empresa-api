<?php

namespace Tests\Feature\MercadoPagoQr;

use App\Http\Controllers\Helpers\caja\CajaAperturaHelper;
use App\Models\AperturaCaja;
use App\Models\Article;
use App\Models\Caja;
use App\Models\MercadoPagoQrCobro;
use App\Models\MovimientoCaja;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * La venta que se guarda con `mercado_pago_qr_cobro_id` (misión cobro-qr-mercado-pago,
 * 26/10/2026): el último paso del cobro con QR, cuando el SPA vio el cobro pagado y guarda la
 * venta por el camino normal de `POST api/sale`.
 *
 * Lo que fija:
 * - Cobro pagado y total igual → venta creada, `cobro.sale_id` apuntando a ella y el pivote de
 *   método de pago con el método del QR (el SPA manda `current_acount_payment_method_id` igual al
 *   del QR), con su movimiento de caja.
 * - Cobro pendiente, total distinto, cobro ya usado o cobro de otro comercio → 422
 *   `cobro_qr_invalido` y NO se crea la venta.
 * - Un reintento de la misma venta (mismo `offline_uuid`) devuelve la venta ya creada, no un 422.
 * - Sin el campo, la venta se guarda igual que siempre.
 * - `SaleController@store` no habla con Mercado Pago: ningún pedido HTTP en todo el archivo.
 *
 * Payload calcado de tests/Feature/Vender/7_Varios_metodos_de_pago_y_caja_Test.php, con método
 * único (sin reparto) porque el QR siempre cobra el total con un solo método. La caja es propia
 * del test y se abre acá; el tearDown la limpia por nombre por si la transacción no revirtió.
 *
 * @group mercado_pago_qr
 */
class Venta_con_cobro_qr_Test extends MercadoPagoQrTestCase
{
    /** @var string Nombre de la caja propia, por el que el tearDown la busca. */
    const NOMBRE_CAJA = 'zz Caja venta con cobro QR';

    /** @var int Precio del artículo. */
    const PRECIO = 750;

    /** @var int Cantidad. Total: 1500, el total de los cobros de `cobro_en_base()`. */
    const CANTIDAD = 2;

    /** @var \App\Models\Article */
    protected $article;

    /** @var \App\Models\Caja */
    protected $caja;

    protected function setUp(): void
    {
        parent::setUp();

        // Cuenta sin listas de precio: acá se mide el cobro, no la lista (DatabaseTransactions lo revierte).
        DB::table('users')->where('id', self::USER_ID)->update(['listas_de_precio' => 0]);

        $this->article = Article::create([
            'name'        => 'zz Articulo venta con cobro QR',
            'user_id'     => self::USER_ID,
            'final_price' => self::PRECIO,
            'status'      => 'active',
        ]);

        $this->caja = Caja::create([
            'num'     => 900026,
            'name'    => self::NOMBRE_CAJA,
            'user_id' => self::USER_ID,
            'saldo'   => 0,
        ]);

        (new CajaAperturaHelper($this->caja->id))->abrir_caja();

        $this->caja = Caja::find($this->caja->id);

        // Todo lo de este archivo es local: si algo intentara hablar con MP, el freno lo marca.
        $this->fake_mp([]);
    }

    protected function tearDown(): void
    {
        $cajas = Caja::where('name', self::NOMBRE_CAJA)->get();

        foreach ($cajas as $caja) {
            MovimientoCaja::where('caja_id', $caja->id)->delete();
            AperturaCaja::where('caja_id', $caja->id)->delete();
            $caja->delete();
        }

        parent::tearDown();
    }

    /**
     * Payload de `POST api/sale` de una venta de mostrador cobrada con un solo método.
     *
     * @param array $overrides
     * @return array
     */
    protected function payload_venta(array $overrides = [])
    {
        $total = self::PRECIO * self::CANTIDAD;

        return array_merge([
            'client_id'                        => null,
            'address_id'                       => null,
            'save_current_acount'              => 0,
            'omitir_en_cuenta_corriente'       => 0,
            'to_check'                         => 0,
            'price_type_id'                    => null,
            'current_acount_payment_method_id' => self::METODO_MERCADO_PAGO,
            'selected_payment_methods'         => [],
            'discounts_in_services'            => 1,
            'surchages_in_services'            => 1,
            'employee_id'                      => null,
            'sub_total'                        => $total,
            'total'                            => $total,
            'terminada'                        => 1,
            'seller_id'                        => null,
            'cantidad_cuotas'                  => null,
            'cuota_descuento'                  => 0,
            'cuota_recargo'                    => 0,
            'caja_id'                          => $this->caja->id,
            'afip_tipo_comprobante_id'         => null,
            'descuento'                        => null,
            'moneda_id'                        => 1,
            'discount_stock'                   => 0,
            'discounts'                        => [],
            'surchages'                        => [],
            'items'                            => [
                [
                    'is_article'   => true,
                    'id'           => $this->article->id,
                    'name'         => $this->article->name,
                    'price_vender' => self::PRECIO,
                    'amount'       => self::CANTIDAD,
                ],
            ],
        ], $overrides);
    }

    /**
     * Un cobro pagado de 1500 del usuario 500.
     *
     * @param array $atributos
     * @return MercadoPagoQrCobro
     */
    protected function cobro_pagado(array $atributos = [])
    {
        return $this->cobro_en_base($this->qr_en_base(), array_merge([
            'estado'        => MercadoPagoQrCobro::ESTADO_PAGADO,
            'paid_at'       => Carbon::now(),
            'mp_payment_id' => 'PAY01JTEST',
        ], $atributos));
    }

    /**
     * @return int
     */
    protected function cantidad_de_ventas()
    {
        return Sale::where('user_id', self::USER_ID)->count();
    }

    /**
     * Cobro pagado y total igual: la venta se crea, el cobro queda atado y el pivote lleva el
     * método del QR por el total, con su movimiento de caja.
     *
     * @test
     */
    public function con_el_cobro_pagado_y_el_mismo_total_la_venta_se_crea_y_queda_atada()
    {
        $this->conectar_mp();
        $cobro = $this->cobro_pagado();

        $response = $this->postJson('api/sale', $this->payload_venta([
            'mercado_pago_qr_cobro_id' => $cobro->id,
        ]));

        $response->assertStatus(201);

        $sale = Sale::find($response->json('model.id'));

        $this->assertNotNull($sale);
        $this->assertSame((int) $sale->id, (int) $cobro->fresh()->sale_id, 'El cobro queda atado a la venta.');

        $pivotes = DB::table('current_acount_payment_method_sale')->where('sale_id', $sale->id)->get();

        $this->assertCount(1, $pivotes);
        $this->assertSame(self::METODO_MERCADO_PAGO, (int) $pivotes[0]->current_acount_payment_method_id);
        $this->assertEqualsWithDelta(1500, (float) $pivotes[0]->amount, 0.01);
        $this->assertSame((int) $this->caja->id, (int) $pivotes[0]->caja_id);

        $movimientos = MovimientoCaja::where('sale_id', $sale->id)->get();

        $this->assertCount(1, $movimientos, 'La venta cobrada por QR mueve la caja como cualquier venta de contado.');
        $this->assertEqualsWithDelta(1500, (float) $movimientos[0]->ingreso, 0.01);

        // El cobro ahora informa la venta (lo que muestra la tarjeta de configuración).
        $this->getJson('api/mercado-pago/qr/cobros/' . $cobro->id)
            ->assertStatus(200)
            ->assertJsonPath('cobro.sale_id', (int) $sale->id)
            ->assertJsonPath('cobro.sale_num', (int) $sale->num);

        Http::assertNothingSent();
    }

    /**
     * Cobro todavía pendiente: 422 `cobro_qr_invalido` y no se crea la venta.
     *
     * @test
     */
    public function con_el_cobro_pendiente_no_se_crea_la_venta()
    {
        $cobro = $this->cobro_pagado(['estado' => MercadoPagoQrCobro::ESTADO_PENDIENTE, 'paid_at' => null]);

        $antes = $this->cantidad_de_ventas();

        $this->postJson('api/sale', $this->payload_venta(['mercado_pago_qr_cobro_id' => $cobro->id]))
            ->assertStatus(422)
            ->assertJsonPath('cobro_qr_invalido', true);

        $this->assertSame($antes, $this->cantidad_de_ventas(), 'No se creó ninguna venta.');
        $this->assertNull($cobro->fresh()->sale_id);
    }

    /**
     * Total de la venta distinto del cobrado: 422 y no se crea la venta.
     *
     * @test
     */
    public function con_un_total_distinto_no_se_crea_la_venta()
    {
        $cobro = $this->cobro_pagado(['total' => 1499]);

        $antes = $this->cantidad_de_ventas();

        $this->postJson('api/sale', $this->payload_venta(['mercado_pago_qr_cobro_id' => $cobro->id]))
            ->assertStatus(422)
            ->assertJsonPath('cobro_qr_invalido', true);

        $this->assertSame($antes, $this->cantidad_de_ventas());
        $this->assertNull($cobro->fresh()->sale_id);
    }

    /**
     * Un centavo de diferencia por redondeo se tolera.
     *
     * @test
     */
    public function un_centavo_de_diferencia_se_tolera()
    {
        $cobro = $this->cobro_pagado(['total' => 1500.01]);

        $this->postJson('api/sale', $this->payload_venta(['mercado_pago_qr_cobro_id' => $cobro->id]))
            ->assertStatus(201);
    }

    /**
     * Cobro ya usado por otra venta: la segunda venta es 422 y no se crea.
     *
     * @test
     */
    public function un_cobro_ya_usado_no_sirve_para_otra_venta()
    {
        $cobro = $this->cobro_pagado();

        $primera = $this->postJson('api/sale', $this->payload_venta(['mercado_pago_qr_cobro_id' => $cobro->id]));
        $primera->assertStatus(201);

        $antes = $this->cantidad_de_ventas();

        $segunda = $this->postJson('api/sale', $this->payload_venta(['mercado_pago_qr_cobro_id' => $cobro->id]));

        $segunda->assertStatus(422);
        $segunda->assertJsonPath('cobro_qr_invalido', true);
        $this->assertStringContainsString('ya se usó', $segunda->json('message'));

        $this->assertSame($antes, $this->cantidad_de_ventas());
        $this->assertSame((int) $primera->json('model.id'), (int) $cobro->fresh()->sale_id, 'El cobro sigue atado a la primera venta.');
    }

    /**
     * Cobro de otro comercio: 422, aunque esté pagado y el total coincida.
     *
     * @test
     */
    public function un_cobro_de_otro_comercio_no_sirve()
    {
        $cobro = $this->cobro_en_base($this->qr_en_base(['user_id' => 999999]), [
            'user_id' => 999999,
            'estado'  => MercadoPagoQrCobro::ESTADO_PAGADO,
            'paid_at' => Carbon::now(),
        ]);

        $antes = $this->cantidad_de_ventas();

        $this->postJson('api/sale', $this->payload_venta(['mercado_pago_qr_cobro_id' => $cobro->id]))
            ->assertStatus(422)
            ->assertJsonPath('cobro_qr_invalido', true);

        $this->assertSame($antes, $this->cantidad_de_ventas());
        $this->assertNull($cobro->fresh()->sale_id);
    }

    /**
     * Un cobro que no existe: 422.
     *
     * @test
     */
    public function un_cobro_inexistente_no_sirve()
    {
        $antes = $this->cantidad_de_ventas();

        $this->postJson('api/sale', $this->payload_venta(['mercado_pago_qr_cobro_id' => 99999999]))
            ->assertStatus(422)
            ->assertJsonPath('cobro_qr_invalido', true);

        $this->assertSame($antes, $this->cantidad_de_ventas());
    }

    /**
     * 🔴 Reintento de la misma venta (mismo `offline_uuid`, la red se cortó después del commit):
     * no es "cobro ya usado". Se devuelve la venta ya creada, sin crear otra ni revincular.
     *
     * @test
     */
    public function un_reintento_con_el_mismo_offline_uuid_devuelve_la_venta_ya_creada()
    {
        $cobro = $this->cobro_pagado();
        $uuid  = (string) Str::uuid();

        $primera = $this->postJson('api/sale', $this->payload_venta([
            'mercado_pago_qr_cobro_id' => $cobro->id,
            'offline_uuid'             => $uuid,
        ]));

        $primera->assertStatus(201);

        $antes = $this->cantidad_de_ventas();

        $reintento = $this->postJson('api/sale', $this->payload_venta([
            'mercado_pago_qr_cobro_id' => $cobro->id,
            'offline_uuid'             => $uuid,
        ]));

        $reintento->assertStatus(201);

        $this->assertSame((int) $primera->json('model.id'), (int) $reintento->json('model.id'), 'Es la misma venta.');
        $this->assertSame($antes, $this->cantidad_de_ventas(), 'No se creó otra.');
        $this->assertSame((int) $primera->json('model.id'), (int) $cobro->fresh()->sale_id);
    }

    /**
     * Un `offline_uuid` distinto (otra venta) con el mismo cobro: 422.
     *
     * @test
     */
    public function otra_venta_con_otro_offline_uuid_y_el_mismo_cobro_es_rechazada()
    {
        $cobro = $this->cobro_pagado();

        $this->postJson('api/sale', $this->payload_venta([
            'mercado_pago_qr_cobro_id' => $cobro->id,
            'offline_uuid'             => (string) Str::uuid(),
        ]))->assertStatus(201);

        $this->postJson('api/sale', $this->payload_venta([
            'mercado_pago_qr_cobro_id' => $cobro->id,
            'offline_uuid'             => (string) Str::uuid(),
        ]))->assertStatus(422)->assertJsonPath('cobro_qr_invalido', true);
    }

    /**
     * Sin el campo: la venta se guarda igual que siempre y ningún cobro se toca.
     *
     * @test
     */
    public function sin_el_campo_la_venta_se_guarda_como_siempre()
    {
        $cobro = $this->cobro_pagado();

        $response = $this->postJson('api/sale', $this->payload_venta());

        $response->assertStatus(201);

        $sale = Sale::find($response->json('model.id'));

        $this->assertNotNull($sale);
        $this->assertNull($cobro->fresh()->sale_id);

        $pivotes = DB::table('current_acount_payment_method_sale')->where('sale_id', $sale->id)->get();

        $this->assertCount(1, $pivotes);
    }

    /**
     * Con el campo en 0 o null (el SPA lo limpia así): igual que sin el campo.
     *
     * @test
     */
    public function con_el_campo_en_cero_o_null_la_venta_se_guarda_como_siempre()
    {
        $this->postJson('api/sale', $this->payload_venta(['mercado_pago_qr_cobro_id' => null]))->assertStatus(201);

        // Otra venta distinta (otro total) para no caer en la guarda anti doble clic de 5 segundos.
        $this->postJson('api/sale', $this->payload_venta([
            'mercado_pago_qr_cobro_id' => 0,
            'items'                    => [[
                'is_article'   => true,
                'id'           => $this->article->id,
                'name'         => $this->article->name,
                'price_vender' => self::PRECIO,
                'amount'       => 1,
            ]],
            'sub_total'                => self::PRECIO,
            'total'                    => self::PRECIO,
        ]))->assertStatus(201);
    }
}
