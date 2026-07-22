<?php

namespace Tests\Feature\Compras;

use App\Models\ProviderDiscount;
use App\Models\ProviderOrder;
use App\Models\ProviderOrderAfipTicket;
use Database\Seeders\testing\TestingFerreteriaSeeder;

/**
 * Tests de costeo RRII (Responsable Inscripto) del modulo de compras (Grupo 184, Prompt 614).
 *
 * Cubre la regla de negocio definida en el Grupo 183: `articles.cost` guarda SIEMPRE el costo
 * base neto, sin importar si el costo se tipeo neto o con IVA incluido (`precios_incluyen_iva`).
 * Un solo concepto por test: si un test falla, su nombre alcanza para saber que se rompio, sin
 * leer el cuerpo (ver README/prompt 614).
 *
 * IMPORTANTE: los numeros esperados de estos tests son la especificacion (definida en el prompt
 * 614), no una sugerencia. Estan prohibido ajustar el valor esperado para que coincida con lo que
 * devuelve el sistema — si un test queda en rojo, hay que corregir el codigo de costeo, no el test.
 */
class Costeo_RRII_Test extends ComprasTestCase
{
    /**
     * Delta de tolerancia para comparaciones de plata (nunca `assertSame` sobre floats).
     */
    const DELTA = 0.01;

    /**
     * Neutraliza, solo para el test en curso, las bonificaciones de catalogo del proveedor Buenos
     * Aires (10% y 5%, ver `TestingFerreteriaSeeder::seed_bonificaciones`).
     *
     * Los numeros de la especificacion de este prompt (12100, 13000, 15730, etc.) son "compra
     * limpia": ningun descuento de compra en juego, solo costo x cantidad + IVA. Pero
     * `ProviderOrderController::store()` llama a `precargar_bonificaciones_proveedor()`, que
     * copia AUTOMATICAMENTE las bonificaciones del proveedor como `provider_order_discounts` de
     * la orden apenas se crea (si la orden no trae descuentos propios) — y todos los articulos de
     * este prompt (Pinza, Alicate, Cuchilla) son de Buenos Aires, el proveedor que el fixture del
     * Prompt 613 dejo con bonificaciones a proposito (para los prompts de descuentos, 615/616).
     * Sin este helper, los totales de esta suite quedarian contaminados por un descuento que la
     * especificacion no pidio. Se borra la fila maestra de `provider_discounts` (no la orden ni
     * sus `provider_order_discounts`, que en el momento de llamar a este helper todavia no
     * existen): al no haber nada que copiar, `precargar_bonificaciones_proveedor()` no crea nada.
     * `DatabaseTransactions` revierte el borrado al terminar cada test.
     *
     * @return void
     */
    protected function quitar_bonificaciones_de_buenos_aires()
    {
        $proveedor_bsas = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_BSAS);

        ProviderDiscount::where('provider_id', $proveedor_bsas->id)->delete();
    }

    /**
     * Test 1 — precios NO incluyen IVA (`precios_incluyen_iva = 0`): el costo tipeado ya es neto,
     * se guarda tal cual en `articles.cost`, y la factura automatica suma el IVA de la alicuota
     * del articulo por encima del subtotal para dar el total final.
     *
     * @group compras
     * @test
     */
    public function precios_no_incluyen_iva_el_costo_se_guarda_neto()
    {
        $this->set_condicion_iva('RRII');
        $this->quitar_bonificaciones_de_buenos_aires();

        // Compra a Buenos Aires, un solo item: Pinza (IVA 21%), costo tipeado NETO 1000, cantidad 10.
        $payload = $this->payload_compra([
            'precios_incluyen_iva' => 0,
            'articles' => [
                $this->item('Pinza', 1000, 10),
            ],
        ]);

        $response = $this->postJson('api/provider-order', $payload);

        $response->assertStatus(201);

        $pinza = $this->articulo('Pinza');

        $this->assertEqualsWithDelta(
            1000,
            (float) $pinza->cost,
            self::DELTA,
            'costo base de Pinza (precios_incluyen_iva=0, debe quedar igual al tipeado)'
        );

        $provider_order = ProviderOrder::find($response->json('model.id'));

        $this->assertEqualsWithDelta(
            10000,
            (float) $provider_order->sub_total,
            self::DELTA,
            'sub_total de la compra (1000 x 10, sin IVA)'
        );

        $this->assertEqualsWithDelta(
            12100,
            (float) $provider_order->total,
            self::DELTA,
            'total de la compra con el IVA de la factura automatica (10000 + 21%)'
        );
    }

    /**
     * Test 2 — precios SI incluyen IVA (`precios_incluyen_iva = 1`): el costo tipeado viene con
     * IVA incluido, hay que sacarselo con la alicuota del articulo ANTES de guardarlo en
     * `articles.cost`. El total de la compra no cambia respecto del Test 1: es la misma plata,
     * tipeada de otra forma.
     *
     * @group compras
     * @test
     */
    public function precios_incluyen_iva_se_le_saca_el_iva_antes_de_guardar()
    {
        $this->set_condicion_iva('RRII');
        $this->quitar_bonificaciones_de_buenos_aires();

        // Misma compra que el Test 1, pero el costo tipeado (1210) ya trae el 21% de IVA incluido.
        $payload = $this->payload_compra([
            'precios_incluyen_iva' => 1,
            'articles' => [
                $this->item('Pinza', 1210, 10),
            ],
        ]);

        $response = $this->postJson('api/provider-order', $payload);

        $response->assertStatus(201);

        $pinza = $this->articulo('Pinza');

        $this->assertEqualsWithDelta(
            1000,
            (float) $pinza->cost,
            self::DELTA,
            'costo base de Pinza (precios_incluyen_iva=1, debe quedar en el neto, no en el 1210 tipeado)'
        );

        $provider_order = ProviderOrder::find($response->json('model.id'));

        $this->assertEqualsWithDelta(
            12100,
            (float) $provider_order->total,
            self::DELTA,
            'total de la compra (misma plata que el Test 1, tipeada con IVA incluido)'
        );
    }

    /**
     * Test 3 — la factura automatica (`modo_facturacion = automatico`) desglosa neto + IVA + total
     * cuando hay una sola alicuota en juego (dos articulos, ambos 21%, precios sin IVA incluido).
     *
     * @group compras
     * @test
     */
    public function la_factura_automatica_desglosa_neto_iva_y_total()
    {
        $this->set_condicion_iva('RRII');
        $this->quitar_bonificaciones_de_buenos_aires();

        // Pinza 1000 x 10 + Alicate 300 x 10, misma alicuota (21%), precios sin IVA incluido.
        $payload = $this->payload_compra([
            'modo_facturacion'      => 'automatico',
            'precios_incluyen_iva'  => 0,
            'articles' => [
                $this->item('Pinza', 1000, 10),
                $this->item('Alicate', 300, 10),
            ],
        ]);

        $response = $this->postJson('api/provider-order', $payload);

        $response->assertStatus(201);

        $provider_order_id = $response->json('model.id');

        $tickets = ProviderOrderAfipTicket::where('provider_order_id', $provider_order_id)->get();

        $this->assertCount(
            1,
            $tickets,
            'se tiene que haber generado una sola factura automatica'
        );

        $ticket = $tickets->first();

        $ticket->load('provider_order_afip_ticket_ivas');

        $this->assertCount(
            1,
            $ticket->provider_order_afip_ticket_ivas,
            'una sola fila de desglose de IVA (una sola alicuota en juego, 21%)'
        );

        $iva_row = $ticket->provider_order_afip_ticket_ivas->first();

        $this->assertEqualsWithDelta(
            13000,
            (float) $iva_row->neto,
            self::DELTA,
            'neto de la factura (1000x10 + 300x10)'
        );

        $this->assertEqualsWithDelta(
            2730,
            (float) $iva_row->iva_importe,
            self::DELTA,
            'IVA de la factura (21% de 13000)'
        );

        $this->assertEqualsWithDelta(
            15730,
            (float) $ticket->total,
            self::DELTA,
            'total de la factura (neto + IVA)'
        );
    }

    /**
     * Test 4 — el costo base guardado en `articles.cost` no depende de la cantidad comprada:
     * misma compra que el Test 1 pero con cantidad 1 en lugar de 10, mismo costo base esperado.
     * Ataja la clase de bug clasica de multiplicar/dividir el costo por la cantidad en el pipeline.
     *
     * @group compras
     * @test
     */
    public function el_costo_base_no_depende_de_la_cantidad()
    {
        $this->set_condicion_iva('RRII');

        $payload = $this->payload_compra([
            'precios_incluyen_iva' => 0,
            'articles' => [
                $this->item('Pinza', 1000, 1),
            ],
        ]);

        $response = $this->postJson('api/provider-order', $payload);

        $response->assertStatus(201);

        $pinza = $this->articulo('Pinza');

        $this->assertEqualsWithDelta(
            1000,
            (float) $pinza->cost,
            self::DELTA,
            'costo base de Pinza (no debe depender de la cantidad comprada)'
        );
    }

    /**
     * Test 5 — precision con una alicuota que no da redondo (10,5%): la vuelta atras del IVA usa
     * la alicuota DEL ARTICULO, no un 21% asumido. Cuchilla tiene IVA 10,5%, costo tipeado 1105
     * (con IVA incluido) tiene que volver a un costo base de 1000.
     *
     * @group compras
     * @test
     */
    public function precision_con_alicuota_que_no_da_redondo()
    {
        $this->set_condicion_iva('RRII');

        $payload = $this->payload_compra([
            'precios_incluyen_iva' => 1,
            'articles' => [
                $this->item('Cuchilla', 1105, 10),
            ],
        ]);

        $response = $this->postJson('api/provider-order', $payload);

        $response->assertStatus(201);

        $cuchilla = $this->articulo('Cuchilla');

        $this->assertEqualsWithDelta(
            1000,
            (float) $cuchilla->cost,
            self::DELTA,
            'costo base de Cuchilla (IVA 10,5%, back-out con la alicuota propia del articulo)'
        );
    }
}
