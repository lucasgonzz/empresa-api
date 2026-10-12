<?php

namespace Tests\Feature\Reportes;

use App\Models\CurrentAcount;
use App\Models\Iva;
use App\Models\ProviderOrder;
use App\Models\ProviderOrderAfipTicket;
use App\Models\ProviderOrderAfipTicketIva;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\Feature\Compras\ComprasTestCase;

/**
 * Misión `factura-compra-tres-defectos` (9/10/2026), Defecto 1 — la Posición Fiscal no suma las
 * facturas de compra huérfanas.
 *
 * El reclamo: se borra una compra y su factura sigue sumando IVA crédito y percepciones en la
 * Posición Fiscal. `ContabilidadRepository` leía las facturas por `user_id` + `issued_at` sin
 * cruzar con `provider_orders`, así que sumaba dos clases de factura que no corresponden:
 *
 *   · la de una compra que ya no existe (lo que dejaba el destroy viejo de la compra);
 *   · la que tiene `provider_order_id` NULL (cargada adentro de una compra nueva que después no se
 *     guardó).
 *
 * Todo se mide por el endpoint real (`GET api/reportes/posicion-fiscal` y `GET api/reportes/detalle`),
 * en el total y en el detalle, para IVA crédito y para las percepciones de IVA y de IIBB.
 *
 * Extiende `ComprasTestCase` y no `EmpresaTestCase` porque necesita crear compras por el endpoint
 * (`payload_compra()`, `item()`).
 *
 * 🔴 Meses EXCLUSIVOS de este archivo: marzo y abril de 2012. Ningún otro test de la suite usa 2012
 * (relevado el 9/10/2026); compartir mes con otro test contamina los totales.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 *
 * @group reportes
 */
class Posicion_Fiscal_Sin_Facturas_Huerfanas_Test extends ComprasTestCase
{
    /** Delta de tolerancia para comparar floats (mismo criterio que el resto de la suite). */
    const DELTA = 0.01;

    /** @var array<int,int> Compras creadas por el test, para la limpieza. */
    protected $compras_creadas = [];

    /** @var array<int,int> Facturas sembradas a mano (huérfanas), para la limpieza. */
    protected $facturas_sembradas = [];

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->compras_creadas as $compra_id) {

            $ids = ProviderOrderAfipTicket::where('provider_order_id', $compra_id)->pluck('id')->all();

            ProviderOrderAfipTicketIva::whereIn('provider_order_afip_ticket_id', $ids)->delete();
            ProviderOrderAfipTicket::whereIn('id', $ids)->delete();
            CurrentAcount::where('provider_order_id', $compra_id)->delete();
            ProviderOrder::where('id', $compra_id)->delete();
        }

        ProviderOrderAfipTicketIva::whereIn('provider_order_afip_ticket_id', $this->facturas_sembradas)->delete();
        ProviderOrderAfipTicket::whereIn('id', $this->facturas_sembradas)->delete();

        $this->compras_creadas    = [];
        $this->facturas_sembradas = [];

        parent::tearDown();
    }

    /**
     * Crea una compra manual por el endpoint real, con una factura de 1000 + 210 de IVA y las
     * percepciones dadas, emitida en `$issued_at`.
     *
     * @param  string  $issued_at
     * @param  float   $percepcion_iva
     * @param  float   $percepcion_iibb
     * @return array{compra: \App\Models\ProviderOrder, factura: \App\Models\ProviderOrderAfipTicket}
     */
    protected function crear_compra_con_factura($issued_at, $percepcion_iva, $percepcion_iibb)
    {
        $response = $this->postJson('api/provider-order', $this->payload_compra([
            'provider_id'             => $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO)->id,
            'modo_facturacion'        => 'manual',
            'update_prices'           => 0,
            'update_stock'            => 0,
            'total_with_iva'          => 0,
            'generate_current_acount' => 0,
            'articles'                => [
                $this->item('Marco para cama', 1000, 1),
            ],
        ]));

        $response->assertStatus(201);

        $compra_id = $response->json('model.id');

        $this->compras_creadas[] = $compra_id;

        $factura = $this->postJson('api/provider-order-afip-ticket', [
            'model_id'        => $compra_id,
            'code'            => '0001-00001234',
            'issued_at'       => $issued_at,
            'percepcion_iva'  => $percepcion_iva,
            'percepcion_iibb' => $percepcion_iibb,
        ]);

        $factura->assertStatus(201);

        $iva_21 = Iva::where('percentage', '21')->first();

        $this->assertNotNull($iva_21, 'La base de testing tiene que tener sembrada la alícuota de 21%.');

        $this->postJson('api/provider-order-afip-ticket-iva', [
            'model_id'    => $factura->json('model.id'),
            'iva_id'      => $iva_21->id,
            'neto'        => 1000,
            'iva_importe' => 210,
        ])->assertStatus(201);

        return [
            'compra'  => ProviderOrder::find($compra_id),
            'factura' => ProviderOrderAfipTicket::find($factura->json('model.id')),
        ];
    }

    /**
     * Siembra una factura huérfana directo en la tabla, como las que dejaba el código viejo: con
     * dueño, fecha de emisión, IVA y percepciones, y una compra que no existe (o ninguna).
     *
     * @param  int|null  $provider_order_id
     * @param  string    $issued_at
     * @return \App\Models\ProviderOrderAfipTicket
     */
    protected function sembrar_factura_huerfana($provider_order_id, $issued_at)
    {
        $factura = ProviderOrderAfipTicket::create([
            'provider_order_id' => $provider_order_id,
            'user_id'           => User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first()->id,
            'code'              => '0001-99999999',
            'issued_at'         => $issued_at,
            'total_iva'         => 999,
            'total'             => 5000,
            'percepcion_iva'    => 77,
            'percepcion_iibb'   => 88,
        ]);

        $this->facturas_sembradas[] = $factura->id;

        return $factura;
    }

    /**
     * @param  string $desde
     * @param  string $hasta
     * @return array
     */
    protected function pedir_posicion_fiscal($desde, $hasta)
    {
        $response = $this->getJson('api/reportes/posicion-fiscal?desde='.$desde.'&hasta='.$hasta);

        if ($response->getStatusCode() !== 200) {
            $this->fail('GET api/reportes/posicion-fiscal devolvió '.$response->getStatusCode().'. Cuerpo completo: '.$response->getContent());
        }

        return json_decode($response->getContent(), true)['posicion_fiscal'];
    }

    /**
     * @param  string $concepto
     * @param  string $desde
     * @param  string $hasta
     * @return array
     */
    protected function pedir_detalle($concepto, $desde, $hasta)
    {
        $response = $this->getJson('api/reportes/detalle?'.http_build_query([
            'concepto' => $concepto,
            'desde'    => $desde,
            'hasta'    => $hasta,
        ]));

        if ($response->getStatusCode() !== 200) {
            $this->fail('GET api/reportes/detalle devolvió '.$response->getStatusCode().'. Cuerpo completo: '.$response->getContent());
        }

        return json_decode($response->getContent(), true);
    }

    /**
     * Los ids de factura que lista un detalle (los de percepciones vienen como "<id>" o "<id>-iva").
     *
     * @param  array $detalle
     * @return array<int,string>
     */
    protected function ids_del_detalle($detalle)
    {
        $this->assertArrayHasKey('registros', $detalle, 'El detalle tiene que traer la clave "registros". Cuerpo: '.json_encode($detalle));

        $ids = [];

        foreach ($detalle['registros'] as $registro) {
            $ids[] = (string) $registro['id'];
        }

        return $ids;
    }

    /**
     * Test 1 — Una factura con compra inexistente y una con `provider_order_id` NULL no suman ni
     * en el IVA crédito ni en las percepciones de la Posición Fiscal, ni aparecen en sus detalles.
     * La factura de una compra que existe, en el mismo mes, sí.
     *
     * @group reportes
     * @test
     */
    public function la_posicion_fiscal_no_suma_facturas_sin_compra_ni_de_compras_borradas()
    {
        $valida = $this->crear_compra_con_factura('2012-03-10', 50, 30);

        $compra_inexistente = (int) ProviderOrder::max('id') + 100000;

        $de_compra_borrada = $this->sembrar_factura_huerfana($compra_inexistente, '2012-03-12 00:00:00');
        $sin_compra        = $this->sembrar_factura_huerfana(null, '2012-03-14 00:00:00');

        $posicion = $this->pedir_posicion_fiscal('2012-03-01', '2012-03-31');

        $this->assertEqualsWithDelta(210, (float) $posicion['posicion_iva']['iva_credito'], self::DELTA, 'El IVA crédito tiene que ser solo el de la factura con compra (210), no sumar los 999 de cada huérfana.');
        $this->assertEqualsWithDelta(50, (float) $posicion['posicion_iva']['percepcion_iva_sufrida'], self::DELTA, 'La percepción de IVA tiene que ser solo la de la factura con compra (50).');
        $this->assertEqualsWithDelta(30, (float) $posicion['posicion_iibb']['percepcion_iibb_sufrida'], self::DELTA, 'La percepción de IIBB tiene que ser solo la de la factura con compra (30).');

        $huerfanas = [(string) $de_compra_borrada->id, (string) $sin_compra->id];

        // Detalle del IVA crédito: la union de facturas de compra y gastos.
        $detalle_iva = $this->pedir_detalle('iva_credito', '2012-03-01', '2012-03-31');
        $ids_iva     = $this->ids_del_detalle($detalle_iva);

        $this->assertContains((string) $valida['factura']->id, $ids_iva, 'La factura con compra tiene que estar en el detalle del IVA crédito.');
        $this->assertCount(0, array_intersect($huerfanas, $ids_iva), 'Ninguna huérfana puede aparecer en el detalle del IVA crédito.');
        $this->assertEqualsWithDelta(210, (float) $detalle_iva['total'], self::DELTA, 'El total del drill-down del IVA crédito tiene que cerrar con el renglón.');

        // Detalles de percepciones, por impuesto.
        $detalle_perc_iva = $this->pedir_detalle('percepciones_iva', '2012-03-01', '2012-03-31');
        $this->assertSame([(string) $valida['factura']->id], $this->ids_del_detalle($detalle_perc_iva), 'El detalle de percepciones de IVA lista solo la factura con compra.');
        $this->assertEqualsWithDelta(50, (float) $detalle_perc_iva['total'], self::DELTA);

        $detalle_perc_iibb = $this->pedir_detalle('percepciones_iibb', '2012-03-01', '2012-03-31');
        $this->assertSame([(string) $valida['factura']->id], $this->ids_del_detalle($detalle_perc_iibb), 'El detalle de percepciones de IIBB lista solo la factura con compra.');
        $this->assertEqualsWithDelta(30, (float) $detalle_perc_iibb['total'], self::DELTA);
    }

    /**
     * Test 2 — El reclamo de punta a punta: una compra con factura suma en la Posición Fiscal, se
     * borra la compra por su endpoint, y la Posición Fiscal del mes vuelve a cero.
     *
     * @group reportes
     * @test
     */
    public function borrar_la_compra_saca_su_factura_de_la_posicion_fiscal()
    {
        $escenario = $this->crear_compra_con_factura('2012-04-10', 40, 25);

        $antes = $this->pedir_posicion_fiscal('2012-04-01', '2012-04-30');

        $this->assertEqualsWithDelta(210, (float) $antes['posicion_iva']['iva_credito'], self::DELTA, 'Ancla: con la compra viva su factura suma.');
        $this->assertEqualsWithDelta(40, (float) $antes['posicion_iva']['percepcion_iva_sufrida'], self::DELTA);
        $this->assertEqualsWithDelta(25, (float) $antes['posicion_iibb']['percepcion_iibb_sufrida'], self::DELTA);

        $this->deleteJson('api/provider-order/'.$escenario['compra']->id)->assertStatus(200);

        $despues = $this->pedir_posicion_fiscal('2012-04-01', '2012-04-30');

        $this->assertEqualsWithDelta(0, (float) $despues['posicion_iva']['iva_credito'], self::DELTA, 'Borrada la compra, su factura no puede seguir sumando IVA crédito.');
        $this->assertEqualsWithDelta(0, (float) $despues['posicion_iva']['percepcion_iva_sufrida'], self::DELTA, 'Ni percepción de IVA.');
        $this->assertEqualsWithDelta(0, (float) $despues['posicion_iibb']['percepcion_iibb_sufrida'], self::DELTA, 'Ni percepción de IIBB.');
    }
}
