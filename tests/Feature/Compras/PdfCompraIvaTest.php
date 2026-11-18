<?php

namespace Tests\Feature\Compras;

use App\Http\Controllers\Pdf\ProviderOrderPdf;
use App\Models\Iva;
use App\Models\ProviderOrder;
use App\Models\ProviderOrderAfipTicket;
use App\Models\ProviderOrderAfipTicketIva;
use App\Models\ProviderOrderDiscount;
use App\Models\ProviderOrderExtraCost;
use App\Services\Compras\ResumenDeCompraPdf;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PdfCompraIvaTest extends ComprasTestCase
{
    private function compra(array $cambios = [], array $pivot = [])
    {
        $proveedor = $this->proveedor(\Database\Seeders\testing\TestingFerreteriaSeeder::PROVIDER_BSAS);
        $compra = ProviderOrder::create(array_merge([
            'user_id' => Auth::id(), 'provider_id' => $proveedor->id, 'num' => 421,
            'moneda_id' => 1, 'sub_total' => 17600000, 'total' => 21373440,
            'total_iva' => 3709440, 'total_descuento' => 0, 'total_costos_extra' => 0,
            'total_from_provider_order_afip_tickets' => 1, 'total_with_iva' => 0,
            'precios_incluyen_iva' => 0, 'modo_facturacion' => 'manual',
        ], $cambios));
        $articulo = \App\Models\Article::where('user_id', Auth::id())->firstOrFail();
        $compra->articles()->attach($articulo->id, array_merge([
            'amount' => 110, 'received' => null, 'cost' => null, 'received_cost' => null,
            'price' => 160000, 'discount' => null, 'iva_id' => Iva::where('percentage', '21')->firstOrFail()->id,
        ], $pivot));
        return $compra;
    }

    private function factura($compra, $total = 21373440, array $lineas = [[21, 17664000, 3709440]], array $datos = [])
    {
        $ticket = ProviderOrderAfipTicket::create(array_merge([
            'user_id' => Auth::id(),
            'provider_order_id' => $compra->id, 'total' => $total,
            'total_iva' => array_sum(array_column($lineas, 2)), 'code' => '507',
        ], $datos));
        foreach ($lineas as $linea) {
            $iva = Iva::where('percentage', $linea[0])->firstOrFail();
            ProviderOrderAfipTicketIva::create([
                'user_id' => Auth::id(), 'provider_order_afip_ticket_id' => $ticket->id,
                'iva_id' => $iva->id, 'neto' => $linea[1], 'iva_importe' => $linea[2],
            ]);
        }
        return $ticket;
    }

    public function test_oc421_imprime_factura_y_su_iva_sin_inventar_ajuste_de_64000()
    {
        $compra = $this->compra();
        $ticket = $this->factura($compra);
        $antes = DB::table('provider_orders')->where('id', $compra->id)->first();
        $resumen = ResumenDeCompraPdf::crear($compra);
        $this->assertSame(21373440.0, $resumen['total']);
        $this->assertContains(['Neto 21%', 17664000.0], $resumen['filas']);
        $this->assertContains(['IVA 21%', 3709440.0], $resumen['filas']);
        $this->assertEquals($antes, DB::table('provider_orders')->where('id', $compra->id)->first());
        $this->assertEquals(21373440, $ticket->fresh()->total);
        Auth::user()->update(['image_url' => null]);
        $pdf = new ProviderOrderPdf($compra->fresh()->load('articles', 'provider'), $resumen);
        $pdf->SetCompression(false);
        $contenido = $pdf->Output('S');
        $this->assertStringContainsString('17.664.000', $contenido);
        $this->assertStringContainsString('3.709.440', $contenido);
        $this->assertStringContainsString('21.373.440', $contenido);
        $this->assertStringNotContainsString('64.000', str_replace('17.664.000', '', $contenido));
        $this->guardar_qa('oc421.pdf', $contenido);
        $this->get('/provider-order/pdf/'.$compra->id)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    /** @dataProvider escenarios_articulos */
    public function test_respeta_importes_historicos_netos_brutos_y_parciales($subtotal, $descuento, $iva, $total, $bruto, $recibido)
    {
        $compra = $this->compra([
            'total_from_provider_order_afip_tickets' => 0, 'sub_total' => $subtotal,
            'total_descuento' => $descuento, 'total_iva' => $iva, 'total' => $total,
            'precios_incluyen_iva' => $bruto, 'total_with_iva' => 1,
        ], ['amount' => 10, 'received' => $recibido, 'cost' => 100, 'price' => null]);
        if ($iva > 0) {
            $this->factura($compra, 121, [[21, 100, $iva]]);
        }
        $compra->load('articles');
        $antes = $compra->articles->first()->pivot->getAttributes();
        $resumen = ResumenDeCompraPdf::crear($compra);
        $this->assertEquals($total, $resumen['total']);
        $this->assertSame($antes, $compra->fresh()->articles->first()->pivot->getAttributes());
    }

    public function escenarios_articulos()
    {
        return [
            'neto más IVA' => [100, 0, 21, 121, 0, 1],
            'bruto sin duplicar IVA' => [121, 0, 21, 121, 1, 1],
            'parcial con descuentos registrados' => [150, 50, 21, 121, 0, 1.5],
            'recibido cero' => [0, 0, 0, 0, 0, 0],
            'sin factura' => [1000, 0, 0, 1000, 0, null],
        ];
    }

    public function test_alicuotas_exento_percepciones_extras_y_descuentos_no_se_restan_dos_veces()
    {
        $compra = $this->compra(['total' => 323.5]);
        $this->factura($compra, 313.5, [[21, 100, 21], [10.5, 100, 10.5], ['Exento', 80, 0]], ['percepcion_iibb' => 2]);
        ProviderOrderExtraCost::create(['provider_order_id' => $compra->id, 'user_id' => Auth::id(), 'value' => 10, 'description' => 'Flete']);
        ProviderOrderDiscount::create(['provider_order_id' => $compra->id, 'user_id' => Auth::id(), 'percentage' => 10, 'description' => 'Ya incluido']);
        $resumen = ResumenDeCompraPdf::crear($compra);
        $this->assertSame(323.5, $resumen['total']);
        $this->assertContains(['Neto Exento', 80.0], $resumen['filas']);
        $this->assertContains(['Percepción IIBB', 2.0], $resumen['filas']);
        Auth::user()->update(['image_url' => null]);
        $compra->loadMissing('articles', 'provider');
        $lineas = collect();
        for ($indice = 1; $indice <= 55; $indice++) {
            $articulo = clone $compra->articles->first();
            $articulo->name = 'Artículo de prueba de paginación '.$indice;
            if ($indice === 9 || $indice === 18) {
                $articulo->name .= str_repeat(' nombre largo', 12);
                $pivot_largo = clone $articulo->pivot;
                $pivot_largo->notes = str_repeat('Nota extensa para revisar el salto al pie. ', 12);
                $articulo->setRelation('pivot', $pivot_largo);
            }
            $lineas->push($articulo);
        }
        $compra->setRelation('articles', $lineas);
        $pdf = new ProviderOrderPdf($compra, $resumen);
        $this->guardar_qa('varias-paginas.pdf', $pdf->Output('S'));
    }

    public function test_factura_sin_desglose_no_inventa_neto_ni_iva()
    {
        $compra = $this->compra(['total' => 150]);
        $this->factura($compra, 150, [], ['total_iva' => null]);
        $resumen = ResumenDeCompraPdf::crear($compra);
        $this->assertContains(['Importe sin desglose de IVA', 150.0], $resumen['filas']);
    }

    /** @dataProvider costos_automaticos */
    public function test_compra_automatica_real_concilia_pantalla_factura_y_pdf($costo, $bruto)
    {
        $this->set_condicion_iva('RRII');
        $payload = $this->payload_compra([
            'provider_id' => $this->proveedor(\Database\Seeders\testing\TestingFerreteriaSeeder::PROVIDER_OTRO)->id,
            'modo_facturacion' => 'automatico', 'update_prices' => 0, 'update_stock' => 0,
            'generate_current_acount' => 0, 'moneda_id' => 1,
            'precios_incluyen_iva' => $bruto, 'articles' => [$this->item('Pinza', $costo, 10)],
        ]);
        $respuesta = $this->postJson('/api/provider-order', $payload)->assertCreated();
        $compra = ProviderOrder::findOrFail($respuesta->json('model.id'));
        $this->assertEquals(12100, $compra->total);
        $this->assertEquals(12100, $compra->provider_order_afip_tickets->sum('total'));
        $resumen = ResumenDeCompraPdf::crear($compra);
        $this->assertSame(12100.0, $resumen['total']);
        Auth::user()->update(['image_url' => null]);
        $this->get('/provider-order/pdf/'.$compra->id)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function costos_automaticos()
    {
        return ['neto' => [1000, 0], 'bruto' => [1210, 1]];
    }

    public function test_linea_usd_no_se_suma_a_pesos_y_cambio_fiscal_no_recotiza_historia()
    {
        $compra = $this->compra(['total_from_provider_order_afip_tickets' => 0, 'sub_total' => 100000, 'total_descuento' => 0, 'total' => 121000, 'total_iva' => 21000], ['cost' => 100, 'cost_in_dollars' => 1, 'amount' => 1]);
        $this->factura($compra, 121000, [[21, 100000, 21000]]);
        Auth::user()->update(['condicion_iva_precios' => 'MT', 'dollar' => 9999]);
        $resumen = ResumenDeCompraPdf::crear($compra);
        $this->assertSame(121000.0, $resumen['total']);
        $compra->update(['moneda_id' => 2, 'sub_total' => 100, 'total' => 100, 'total_iva' => 0]);
        $resumen = ResumenDeCompraPdf::crear($compra->fresh());
        $this->assertSame('Dolar', $resumen['moneda']);
        $this->assertSame(100.0, $resumen['total']);
    }

    /** @dataProvider conflictos */
    public function test_endpoint_muestra_conflicto_recuperable_en_html_y_json($caso, $esperado)
    {
        $compra = $this->compra();
        $ticket = $this->factura($compra);
        if ($caso === 'sin_factura') { $ticket->delete(); }
        if ($caso === 'iva_duplicado') { $compra->update(['total' => 25082880]); }
        if ($caso === 'desglose') { $ticket->update(['total' => 1]); }
        if ($caso === 'moneda') { $compra->update(['moneda_id' => 999999]); }
        if ($caso === 'extra_negativo') { ProviderOrderExtraCost::create(['user_id' => Auth::id(), 'provider_order_id' => $compra->id, 'value' => -1]); }
        if ($caso === 'centavo') { $compra->update(['total' => 21373440.01]); }
        if ($caso === 'iva_sin_desglose') { $ticket->provider_order_afip_ticket_ivas()->delete(); }
        $respuesta = $this->get('/provider-order/pdf/'.$compra->id)->assertStatus(422)->assertSee($esperado)->assertDontSee('Server Error');
        $this->guardar_qa('error-'.$caso.'.html', $respuesta->getContent());
        $this->getJson('/provider-order/pdf/'.$compra->id)->assertStatus(422)->assertJsonValidationErrors('compra');
    }

    public function conflictos()
    {
        return [
            ['sin_factura', 'no hay comprobantes'], ['iva_duplicado', 'no coincide con la fuente'],
            ['desglose', 'no coinciden con el total'], ['moneda', 'moneda válida'],
            ['extra_negativo', 'importe negativo'],
            ['centavo', 'no coincide con la fuente'],
            ['iva_sin_desglose', 'falta su desglose'],
        ];
    }

    private function guardar_qa($nombre, $contenido)
    {
        $directorio = getenv('CC_PDF_QA_DIR');
        if ($directorio && is_dir($directorio)) {
            file_put_contents($directorio.DIRECTORY_SEPARATOR.$nombre, $contenido);
        }
    }
}
