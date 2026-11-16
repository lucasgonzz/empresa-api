<?php

namespace Tests\Feature\Facturacion;

use App\Http\Controllers\Pdf\SaleTicketPdf;
use App\Models\AfipInformation;
use App\Models\AfipTicket;
use App\Models\IvaCondition;
use App\Models\Sale;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\EmpresaTestCase;

/**
 * Ticket 120: el comprobante autorizado manda al reimprimir. El caso conserva
 * quince renglones separados: el legado redondea sus buckets por renglón y
 * muestra IVA 2.496,12; el snapshot autorizado conserva IVA 2.496,06.
 * No solicita CAE ni conecta servicios externos.
 *
 * @group facturacion
 */
class Consolidada_Snapshot_Impreso_Test extends EmpresaTestCase
{
    /**
     * @test
     * @dataProvider escenarios_de_lectura
     */
    public function ticket_dos_cero_conserva_el_snapshot_solo_en_consolidadas($consolidada, $snapshot, $iva_esperado)
    {
        list($venta, $ticket) = $this->comprobante($consolidada, $snapshot);
        $respuesta = $this->getJson('/api/afip/get-importes/'.$venta->id);
        $respuesta->assertOk();
        $this->assertEqualsWithDelta(14382.05, $respuesta->json('importes.total'), 0.001);
        $this->assertEqualsWithDelta($iva_esperado, $respuesta->json('importes.ivas.21.Importe'), 0.001);
        $this->assertEqualsWithDelta($snapshot && $consolidada ? 11885.99 : 11885.93,
            $respuesta->json('importes.ivas.21.BaseImp'), 0.001);
        $this->assertNotEmpty($respuesta->json('afip_qr_link'));
        $this->assertSame($ticket->cae, $ticket->fresh()->cae);
    }

    /**
     * @test
     * @dataProvider escenarios_de_lectura
     */
    public function el_pdf_imprime_total_e_iva_autorizados_sin_reinterpretar_historicas($consolidada, $snapshot, $iva_esperado)
    {
        list($venta, $ticket) = $this->comprobante($consolidada, $snapshot);
        // El constructor de producción termina en exit. Se ejercita el bloque
        // real total()/iva_discriminado() sobre FPDF, sin su salida HTTP ni QR.
        $pdf = new class($venta, $ticket) extends SaleTicketPdf {
            public $binario;

            public function __construct($venta, $ticket)
            {
                $this->sale = $venta;
                $this->afip_ticket = $ticket;
                $this->x_incial = 4;
                $this->cell_ancho = 72;
                $this->b = 0;
                $this->total_sale = $venta->total;
                \FPDF::__construct('P', 'mm', [80, 100]);
                $this->SetCompression(false);
                $this->SetAutoPageBreak(false);
                $this->AddPage();
                $this->total();
                $this->binario = $this->Output('S');
            }

            public function Header() {}
            public function Footer() {}
        };
        $this->assertSame('%PDF', substr($pdf->binario, 0, 4));
        $this->assertStringContainsString('TOTAL: $14.382,05', $pdf->binario);
        $this->assertStringContainsString('Imp Neto Gravado: $11.885,99', $pdf->binario);
        $this->assertStringContainsString('IVA 21%: $'.number_format($iva_esperado, 2, ',', '.'), $pdf->binario);
    }

    public function escenarios_de_lectura(): array
    {
        return [
            'consolidada autorizada con snapshot' => [true, true, 2496.06],
            'historica autorizada sin snapshot conserva legado' => [true, false, 2496.12],
            'venta comun conserva comportamiento previo' => [false, true, 2496.12],
        ];
    }

    private function comprobante($consolidada, $snapshot): array
    {
        $usuario = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->firstOrFail();
        $informacion = AfipInformation::create([
            'user_id' => $usuario->id,
            'iva_condition_id' => IvaCondition::where('name', 'Responsable inscripto')->firstOrFail()->id,
            'cuit' => '20111111112', 'punto_venta' => 97,
            'razon_social' => 'Comercio sintetico', 'afip_ticket_production' => 0,
        ]);
        $venta = Sale::create([
            'user_id' => $usuario->id, 'total' => 14382.05, 'sub_total' => 14382.05,
            'moneda_id' => 1, 'descuento' => 0, 'terminada' => 1,
            'is_consolidacion_facturacion' => $consolidada,
            'discount_stock' => 0, 'save_current_acount' => 0, 'omitir_en_cuenta_corriente' => 1,
        ]);
        $articulo = $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA);
        $relacion = $venta->articles();
        for ($i = 0; $i < 15; $i++) {
            $relacion->attach($articulo->id, $relacion->conservar_precision([
                'price' => 102.008130081301, 'amount' => 1.23, 'discount' => 0, 'iva_percentage' => '21',
            ]));
        }
        $servicio = Service::create(['name' => 'Servicio sintetico', 'price' => 12500, 'user_id' => $usuario->id]);
        $venta->services()->attach($servicio->id, ['price' => 12500, 'amount' => 1, 'discount' => 0]);
        $datos = [
            'sale_id' => $venta->id, 'afip_information_id' => $informacion->id,
            'cbte_letra' => 'A', 'cbte_tipo' => 1, 'cae' => '70123456789012',
            'importe_total' => '14382.05', 'importe_iva' => 2496.06,
            'cuit_negocio' => '20111111112', 'punto_venta' => '97', 'cbte_numero' => '1',
        ];
        if ($snapshot) {
            $datos += [
                'imp_total_enviado' => 14382.05, 'imp_neto_enviado' => 11885.99,
                'imp_tot_conc_enviado' => 0, 'imp_op_ex_enviado' => 0, 'imp_iva_enviado' => 2496.06,
                'iva_detalle_enviado_json' => [['Id' => 5, 'BaseImp' => 11885.99, 'Importe' => 2496.06]],
            ];
        }
        $ticket = AfipTicket::create($datos)->fresh();
        return [$venta->fresh(), $ticket];
    }
}
