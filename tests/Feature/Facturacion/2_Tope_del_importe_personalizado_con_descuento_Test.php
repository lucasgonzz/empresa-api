<?php

namespace Tests\Feature\Facturacion;

use App\Http\Controllers\Helpers\Afip\MakeAfipTicket;
use App\Http\Controllers\Helpers\Afip\AfipWsHelper;
use App\Http\Controllers\Helpers\Afip\TransporteDeFacturaHelper;
use App\Http\Controllers\Helpers\AfipHelper;
use App\Models\AfipInformation;
use App\Models\AfipTicket;
use App\Models\AfipTipoComprobante;
use App\Models\IvaCondition;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Log;
use Tests\EmpresaTestCase;

/**
 * Archivo 2 — el tope del importe personalizado se valida TAMBIÉN en ventas con `descuento`.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 EL AGUJERO QUE ESTA SUITE CIERRA (tanda correctivos 24/8, item 21)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  `SaleController::makeAfipTicket()` valida que el importe personalizado no supere el total
 *  real de la venta (el 422 del archivo 1, test 8). Pero hasta esta tanda ese chequeo se
 *  SALTEABA por completo si la venta tenía `sales.descuento`, porque el campo se interpretaba
 *  de dos maneras incompatibles (monto en `SaleHelper::getTotalSale()`, porcentaje en
 *  `AfipItemCalculator`) y el techo no era un número confiable. Consecuencia: justo en las
 *  ventas con descuento se podía facturar CUALQUIER importe.
 *
 *  Lucas confirmó el 24/8/2026 que `sales.descuento` ES porcentaje, `getTotalSale()` quedó
 *  corregido (ver `tests/Feature/Sales/12_Descuento_de_venta_es_porcentaje_Test.php`), el tope
 *  volvió a ser determinable y el salteo se sacó PARA DESCUENTOS >= 0.
 *
 *  🔴 LA EXCEPCIÓN QUE QUEDÓ (hallazgo del chequeo independiente): con descuento NEGATIVO
 *  (recargo global) el salteo sigue vigente, porque `AfipItemCalculator` aplica
 *  `sales.descuento` solo con la guarda `> 0` — los recargos globales no llegan al comprobante
 *  de ARCA — y entonces el tope de `getImportes()` queda MENOR al total real que muestra la
 *  pantalla: validar rechazaría facturar el propio total de la venta. El test 3 blinda ese
 *  salteo. Si Lucas decide que los negativos deben llegar a ARCA, cae la guarda `> 0` del
 *  calculador Y este salteo juntos.
 *
 *  Cómo se ejercita SIN red, igual que el archivo 1 de esta carpeta: el 422 se devuelve ANTES
 *  de instanciar `MakeAfipTicket`, y `get_tope_en_pesos()` arma el `AfipTicket` en memoria.
 *  El test de recargo usa un tipo válido y reemplaza sólo el transporte: calcula los importes
 *  con el helper real, simula la autorización y verifica el comprobante y su saldo sin SOAP.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group facturacion
 */
class Tope_del_importe_personalizado_con_descuento_Test extends EmpresaTestCase
{
    /**
     * Delta para comparaciones de plata.
     */
    const DELTA = 0.01;

    /**
     * Crea una venta mínima del comercio del fixture.
     *
     * @param  array  $overrides  Campos a pisar (descuento, total, ...).
     * @return \App\Models\Sale
     */
    protected function crear_venta($overrides = [])
    {
        $user = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $this->assertNotNull($user, 'Falta el usuario del fixture.');

        return Sale::create(array_merge([
            'user_id'                    => $user->id,
            'client_id'                  => null,
            'omitir_en_cuenta_corriente' => 0,
            'save_current_acount'        => 0,
            'terminada'                  => 1,
            'is_cerrada'                 => 0,
            'sub_total'                  => 1000,
            'total'                      => 1000,
            'moneda_id'                  => 1,
            'descuento'                  => 0,
        ], $overrides));
    }

    /**
     * Engancha a la venta un renglón del artículo centinela: 10 unidades a $100 = $1.000
     * brutos, con la alícuota real del artículo en el pivot (mismo criterio que la suite de
     * puntos: así el resolvedor de IVA del calculador no puede discrepar con la relación).
     *
     * @param  \App\Models\Sale  $sale
     * @return \App\Models\Sale  La venta recargada.
     */
    protected function con_renglon_de_mil($sale)
    {
        $articulo = $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA);

        $this->assertNotNull($articulo, 'Falta el artículo centinela del fixture.');
        $this->assertNotNull($articulo->iva, 'El artículo centinela del fixture no tiene IVA.');

        $sale->articles()->attach($articulo->id, [
            'amount'         => 10,
            'price'          => 100.00,
            'iva_percentage' => $articulo->iva->percentage,
        ]);

        return $sale->fresh();
    }

    /**
     * Crea una configuración fiscal REAL en base (`get_tope_en_pesos()` la resuelve por id).
     *
     * @param  string  $condicion  Nombre de la `iva_condition`.
     * @return \App\Models\AfipInformation
     */
    protected function crear_afip_information($condicion = 'Monotributista')
    {
        $user = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
        $iva_condition = IvaCondition::where('name', $condicion)->first();

        $this->assertNotNull($iva_condition, 'Falta la iva_condition "'.$condicion.'" en la base de testing.');

        return AfipInformation::create([
            'user_id'          => $user->id,
            'iva_condition_id' => $iva_condition->id,
            'punto_venta'      => 1,
            'cuit'             => '20111111112',
            'razon_social'     => 'Fixture tope con descuento',
        ]);
    }

    /**
     * Test 1 - el caso del agujero: una venta CON descuento rechaza con 422 un importe
     * personalizado que supera su total real. Antes de esta tanda, este mismo POST se salteaba
     * la validación y seguía de largo hacia ARCA.
     *
     * La venta: renglones por $1.000 brutos, descuento 10 (porcentaje), total real $900 — el
     * que escribió el front. El emisor Monotributista toma el tope de `sales.total` (camino
     * no-RI del calculador), así que el techo es 900 y los $2.000 pedidos no pueden pasar.
     *
     * @group facturacion
     * @test
     */
    public function rechaza_con_422_un_importe_mayor_al_total_aunque_la_venta_tenga_descuento()
    {
        $sale = $this->con_renglon_de_mil($this->crear_venta([
            'descuento' => 10,
            'sub_total' => 1000,
            'total'     => 900,
        ]));

        $afip_information = $this->crear_afip_information();

        try {

            $response = $this->postJson('api/afip-ticket', [
                'sale_id'                    => $sale->id,
                'ventas_afip_information_id' => $afip_information->id,
                'afip_tipo_comprobante_id'   => 1,
                // El total real de la venta es 900: 2000 no puede facturarse.
                'monto_a_facturar'           => 2000,
            ]);

            $response->assertStatus(422);

            $this->assertStringContainsString(
                'no puede superar el total',
                $response->json('message'),
                'el 422 tiene que explicar que el importe supera el total de la venta'
            );

            $this->assertEquals(
                0,
                AfipTicket::where('sale_id', $sale->id)->count(),
                'un importe rechazado no puede haber creado ningun afip_ticket'
            );

        } finally {

            AfipInformation::where('id', $afip_information->id)->delete();
        }
    }

    /**
     * Test 3 - con descuento NEGATIVO (recargo global) el tope NO se valida: facturar el total
     * real de la venta no puede rebotar en 422.
     *
     * El escenario de la regresión que este test cierra: venta de $1.000 brutos con
     * `descuento = -10`. La pantalla y `sales.total` dicen $1.100 (la SPA aplica los negativos),
     * pero `AfipItemCalculator` los ignora (guarda `> 0`), así que el tope RI de
     * `getImportes()` da $1.000: con la validación activa, facturar los $1.100 que muestra la
     * propia pantalla devolvía 422. El salteo para negativos evita esa contradicción.
     *
     * El tipo B es válido para el emisor RI y el receptor de mostrador. El transporte simulado
     * usa el calculador real del pedido, sin instanciar SOAP: se exige autorización por $1.100,
     * un único comprobante y el mismo saldo facturado. El warning sigue identificando el salteo.
     *
     * @group facturacion
     * @test
     */
    public function con_descuento_negativo_el_tope_no_rebota_la_factura()
    {
        $sale = $this->con_renglon_de_mil($this->crear_venta([
            'descuento' => -10,
            'sub_total' => 1000,
            'total'     => 1100,
        ]));

        $afip_information = $this->crear_afip_information('Responsable inscripto');
        $tipo = AfipTipoComprobante::where('codigo', 6)->firstOrFail();
        $transporte = new TransporteDeRecargoSinRed();
        $this->app->instance(TransporteDeFacturaHelper::class, $transporte);

        Log::spy();

        try {

            $response = $this->postJson('api/afip-ticket', [
                'sale_id'                    => $sale->id,
                'ventas_afip_information_id' => $afip_information->id,
                'afip_tipo_comprobante_id'   => $tipo->id,
                // El total real de la venta con el recargo global es 1100: tiene que poder facturarse.
                'monto_a_facturar'           => 1100,
            ]);

            $response->assertStatus(201)->assertJson(['estado' => 'emitida']);
            $this->assertSame(1, $transporte->emisiones);
            $this->assertEqualsWithDelta(1100, $transporte->importe, self::DELTA);

            // La prueba positiva de que corrió el salteo (y no cualquier otra explosión): su warning distintivo.
            Log::shouldHaveReceived('warning')
                ->withArgs(function ($mensaje) {
                    return is_string($mensaje) && strpos($mensaje, 'descuento NEGATIVO') !== false;
                })
                ->once();

            $this->assertSame(1, AfipTicket::where('sale_id', $sale->id)->count());
            $ticket = AfipTicket::where('sale_id', $sale->id)->firstOrFail();
            $this->assertNotEmpty($ticket->cae);
            $this->assertEqualsWithDelta(1100, (float) $ticket->facturar_importe_personalizado, self::DELTA);
            $this->assertEqualsWithDelta(1100, (float) $ticket->importe_total, self::DELTA);
            $this->assertEqualsWithDelta(1100, (float) $sale->fresh()->total_facturado, self::DELTA);

        } finally {

            AfipInformation::where('id', $afip_information->id)->delete();
        }
    }

    /**
     * Test 2 - el tope de una venta con descuento es su total real bajo el criterio porcentaje,
     * también para un emisor Responsable Inscripto (el camino que reconstruye el total renglón
     * por renglón con `AfipItemCalculator`, que siempre aplicó el descuento como porcentaje).
     *
     * $1.000 brutos con descuento 10 → tope $900, calculado a mano. Si el tope diera $990 (el
     * criterio monto) o un número inflado, este assert lo denuncia.
     *
     * @group facturacion
     * @test
     */
    public function el_tope_de_una_venta_con_descuento_es_el_total_real_en_ri()
    {
        $sale = $this->con_renglon_de_mil($this->crear_venta([
            'descuento' => 10,
            'sub_total' => 1000,
            'total'     => 900,
        ]));

        $afip_information = $this->crear_afip_information('Responsable inscripto');

        try {

            $tope = MakeAfipTicket::get_tope_en_pesos($sale, (int) $afip_information->id, 1);

            $this->assertNotNull($tope, 'con configuracion fiscal valida el tope tiene que ser determinable');

            // 1000 - (1000 * 10 / 100) = 900. A mano, no contra getTotalSale (esta bajo prueba en Sales/12).
            $this->assertEqualsWithDelta(
                900.00,
                (float) $tope,
                self::DELTA,
                'el tope de una venta de $1.000 brutos con descuento 10 tiene que ser $900 (el -10%)'
            );

        } finally {

            AfipInformation::where('id', $afip_information->id)->delete();
        }
    }
}

/** Simula únicamente ARCA: los importes del pedido se calculan por el camino fiscal real. */
class TransporteDeRecargoSinRed extends TransporteDeFacturaHelper
{
    public $emisiones = 0;
    public $importe;

    public function emitir($ticket): void
    {
        $this->emisiones++;
        $importes = (new AfipHelper($ticket))->getImportes();
        $this->importe = (float) $importes['total'];
        $ticket->update([
            'cbte_numero' => 1, 'cbte_tipo' => $ticket->afip_tipo_comprobante->codigo,
            'imp_total_enviado' => $this->importe, 'importe_total' => $this->importe,
            'importe_iva' => $importes['iva'], 'moneda_id' => 'PES',
            'cae' => '70123456789012', 'resultado' => 'A',
        ]);
        AfipWsHelper::update_sale_total_facturado($ticket, $this->importe);
    }

    public function conciliar($ticket): void
    {
        throw new \LogicException('Este escenario no debe intentar una conciliación.');
    }
}
