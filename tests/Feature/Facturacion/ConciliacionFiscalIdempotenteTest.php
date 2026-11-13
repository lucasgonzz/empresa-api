<?php

namespace Tests\Feature\Facturacion;

use App\Http\Controllers\Helpers\Afip\AfipFexHelper;
use App\Http\Controllers\Helpers\Afip\AfipWsfeHelper;
use App\Models\AfipTicket;
use App\Models\AfipTipoComprobante;
use App\Models\Article;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\EmpresaTestCase;

/** Ticket #121: consultar otra vez actualiza datos fiscales sin volver a sumar la misma factura. */
class ConciliacionFiscalIdempotenteTest extends EmpresaTestCase
{
    /** @test */
    public function dos_consultas_wsfe_con_instancias_precargadas_suman_una_sola_vez()
    {
        list($venta, $ticket) = $this->escenario(1);
        $primera = $this->wsfe($ticket);
        $segunda = $this->wsfe(AfipTicket::find($ticket->id));
        // Las dos instancias están preparadas ANTES de que la primera escriba CAE/saldo.
        $this->assertNull($segunda->afip_ticket->cae, 'La segunda instancia conserva la lectura anterior a la primera consulta.');
        $primera->consultar_comprobante();
        $segunda->consultar_comprobante();
        $this->assertEqualsWithDelta(121, (float) $venta->fresh()->total_facturado, 0.01);
        $this->assertEquals('A', $ticket->fresh()->resultado);
        $this->assertNotEmpty($ticket->fresh()->cae);
        $this->assertSame(1, AfipTicket::where('sale_id', $venta->id)->count());
        $this->assertEqualsWithDelta(21, (float) $ticket->fresh()->importe_iva, 0.01);
    }

    /** @test */
    public function consultar_wsfe_con_cae_existente_repara_iva_y_ganancia_sin_incrementar_el_saldo()
    {
        list($venta, $ticket) = $this->escenario(1);
        $ticket->update(['cae' => $this->cae($ticket), 'resultado' => 'A', 'importe_iva' => null]);
        $venta->update(['total_facturado' => 121, 'ganancia' => 999]);
        $this->wsfe($ticket->fresh())->consultar_comprobante();
        $this->wsfe($ticket->fresh())->consultar_comprobante();
        $this->assertEqualsWithDelta(121, (float) $venta->fresh()->total_facturado, 0.01);
        $this->assertEqualsWithDelta(21, (float) $ticket->fresh()->importe_iva, 0.01);
        $this->assertNotNull($venta->fresh()->ganancia);
        $this->assertEqualsWithDelta(0, (float) $venta->fresh()->ganancia, 0.01);
        $this->assertSame(1, AfipTicket::where('sale_id', $venta->id)->count());
    }

    /** @test */
    public function dos_consultas_fex_de_intento_identificado_suman_una_sola_vez_y_conservan_el_cae()
    {
        list($venta, $ticket) = $this->escenario(19);
        $primera = $this->fex($ticket);
        $primera->consultar_comprobante();
        $primera->consultar_comprobante();
        $this->assertEqualsWithDelta(121, (float) $venta->fresh()->total_facturado, 0.01);
        $this->assertSame($this->cae($ticket), $ticket->fresh()->cae);
        $this->assertEquals('A', $ticket->fresh()->resultado);
        $this->assertSame(1, AfipTicket::where('sale_id', $venta->id)->count());
        $this->assertEqualsWithDelta(0, (float) $ticket->fresh()->importe_iva, 0.01);
    }

    /** @test */
    public function fex_con_cae_existente_repara_el_iva_nulo_sin_sumar_nuevamente()
    {
        list($venta, $ticket) = $this->escenario(19);
        $ticket->update(['cae' => $this->cae($ticket), 'resultado' => 'A', 'importe_iva' => null]);
        $venta->update(['total_facturado' => 121]);
        $this->fex($ticket->fresh())->consultar_comprobante();
        $this->fex($ticket->fresh())->consultar_comprobante();
        $this->assertEqualsWithDelta(121, (float) $venta->fresh()->total_facturado, 0.01);
        $this->assertSame($this->cae($ticket), $ticket->fresh()->cae);
        $this->assertNotNull($ticket->fresh()->importe_iva);
        $this->assertEqualsWithDelta(0, (float) $ticket->fresh()->importe_iva, 0.01);
    }

    /** @test */
    public function repetir_la_consulta_de_una_nota_de_credito_no_suma_el_importe_a_la_venta()
    {
        list($venta, $ticket) = $this->escenario(1);
        $ticket->update(['sale_id' => null, 'sale_nota_credito_id' => $venta->id, 'cbte_tipo' => 3]);
        $venta->update(['total_facturado' => 121]);
        $helper = $this->wsfe($ticket->fresh());
        $helper->consultar_comprobante();
        $helper->consultar_comprobante();
        $this->assertEqualsWithDelta(121, (float) $venta->fresh()->total_facturado, 0.01);
        $this->assertNotEmpty($ticket->fresh()->cae);
        $this->assertEqualsWithDelta(21, (float) $ticket->fresh()->importe_iva, 0.01);
        $this->assertSame(1, AfipTicket::where('sale_nota_credito_id', $venta->id)->count());
    }

    /**
     * @test
     * @dataProvider respuestas_incompatibles_con_cae_previo
     */
    public function un_cae_previo_no_se_reemplaza_ni_se_borra_al_consultar($codigo, $cae_respuesta)
    {
        list($venta, $ticket) = $this->escenario($codigo);
        $cae_previo = $this->cae($ticket);
        $ticket->update(['cae' => $cae_previo, 'resultado' => 'A', 'importe_iva' => 7]);
        $venta->update(['total_facturado' => 121]);
        $helper = $codigo === 19
            ? $this->fex($ticket->fresh(), ['Cae' => $cae_respuesta])
            : $this->wsfe($ticket->fresh(), ['CodAutorizacion' => $cae_respuesta]);
        try {
            $helper->consultar_comprobante();
            $this->fail('Una respuesta con otro CAE o sin CAE debe rechazarse.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('CAE ya registrado', implode(' ', $e->errors()['facturacion']));
        }
        $this->assertSame($cae_previo, $ticket->fresh()->cae);
        $this->assertEquals('A', $ticket->fresh()->resultado);
        $this->assertEqualsWithDelta(7, (float) $ticket->fresh()->importe_iva, 0.01);
        $this->assertEqualsWithDelta(121, (float) $venta->fresh()->total_facturado, 0.01);
    }

    public function respuestas_incompatibles_con_cae_previo(): array
    {
        return [
            'WSFE otro CAE' => [1, '12345678901234'],
            'WSFE sin CAE' => [1, ''],
            'FEX otro CAE' => [19, '12345678901234'],
            'FEX sin CAE' => [19, ''],
        ];
    }

    protected function escenario($codigo)
    {
        $articulo = Article::create(['name' => 'Prueba conciliación fiscal '.uniqid(), 'user_id' => auth()->id(), 'costo_real' => 100]);
        $venta = Sale::create([
            'user_id' => auth()->id(), 'num' => 1219101, 'total' => 121, 'sub_total' => 121, 'total_cost' => 100,
            'moneda_id' => 1, 'total_facturado' => 0, 'iva_aplicado' => 1, 'discount_stock' => 0,
        ]);
        $venta->articles()->attach($articulo->id, ['amount' => 1, 'price' => 121, 'cost' => 100]);
        $tipo = AfipTipoComprobante::where('codigo', $codigo)->first();
        if (!$tipo) {
            $tipo = AfipTipoComprobante::create(['name' => $codigo === 19 ? 'Factura E' : 'Factura A', 'codigo' => $codigo]);
        }
        $ticket = AfipTicket::create([
            'sale_id' => $venta->id, 'afip_tipo_comprobante_id' => $tipo->id,
            'cbte_tipo' => $codigo, 'cbte_numero' => '910000'.$venta->id, 'punto_venta' => 4,
            'cuit_negocio' => '20000000000', 'importe_iva' => null, 'imp_total_enviado' => 121,
            'afip_id' => '121'.$venta->id,
        ]);
        return [$venta, $ticket];
    }

    protected function cae($ticket)
    {
        return str_pad((string) $ticket->id, 14, '8', STR_PAD_LEFT);
    }

    protected function wsfe($ticket, array $cambios = [])
    {
        $respuesta = [
            'hubo_un_error' => false, 'request' => '<request/>', 'response' => '<response/>',
            'result' => (object) ['FECompConsultarResult' => (object) ['ResultGet' => (object) [
                'PtoVta' => 4, 'CbteTipo' => (int) $ticket->cbte_tipo, 'ImpTotal' => 121, 'ImpIVA' => 21,
                'MonId' => 'PES', 'Resultado' => 'A', 'CodAutorizacion' => $this->cae($ticket),
                'FchVto' => '20261231', 'DocTipo' => 99, 'DocNro' => 0,
            ]]],
        ];
        foreach ($cambios as $campo => $valor) {
            $respuesta['result']->FECompConsultarResult->ResultGet->{$campo} = $valor;
        }
        $helper = (new ReflectionClass(AfipWsfeHelper::class))->newInstanceWithoutConstructor();
        $helper->afip_ticket = $ticket;
        $helper->wsfe = new class($respuesta) {
            private $respuesta;
            public function __construct($respuesta) { $this->respuesta = $respuesta; }
            public function FECompConsultar($params) { return $this->respuesta; }
        };
        return $helper;
    }

    protected function fex($ticket, array $cambios = [])
    {
        $respuesta = [
            'hubo_un_error' => false, 'request' => '<request/>', 'response' => '<response/>',
            'result' => (object) ['FEXGetCMPResult' => (object) ['FEXResultGet' => (object) [
                'Id' => $ticket->afip_id, 'Cbte_tipo' => 19, 'Punto_vta' => 4, 'Cbte_nro' => $ticket->cbte_numero,
                'Imp_total' => 121, 'Cae' => $this->cae($ticket), 'Fch_venc_Cae' => '20261231', 'Resultado' => 'A',
            ]]],
        ];
        foreach ($cambios as $campo => $valor) {
            $respuesta['result']->FEXGetCMPResult->FEXResultGet->{$campo} = $valor;
        }
        $helper = (new ReflectionClass(AfipFexHelper::class))->newInstanceWithoutConstructor();
        $helper->afip_ticket = $ticket;
        $helper->sale = $ticket->sale;
        $helper->exigir_identidad_de_intento = true;
        $helper->wsfex = new class($respuesta) {
            private $respuesta;
            public function __construct($respuesta) { $this->respuesta = $respuesta; }
            public function FEXGetCMP($params) { return $this->respuesta; }
        };
        return $helper;
    }
}
