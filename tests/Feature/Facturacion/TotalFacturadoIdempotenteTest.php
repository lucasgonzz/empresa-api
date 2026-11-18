<?php

namespace Tests\Feature\Facturacion;

use App\Http\Controllers\Helpers\Afip\AfipWsHelper;
use App\Http\Controllers\Helpers\Afip\AfipWsfeHelper;
use App\Http\Controllers\Helpers\Afip\AfipFexHelper;
use App\Http\Controllers\Helpers\Afip\TotalFacturadoDeVentaHelper as Saldo;
use App\Models\AfipTicket;
use App\Models\AfipInformation;
use App\Models\AfipTipoComprobante;
use App\Models\CurrentAcount;
use App\Models\IvaCondition;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\EmpresaTestCase;

class TotalFacturadoIdempotenteTest extends EmpresaTestCase
{
    protected function venta(array $datos = [])
    {
        return Sale::create($datos + ['user_id' => auth()->id(), 'num' => 145, 'total' => 11500,
            'sub_total' => 11500, 'total_facturado' => 0, 'moneda_id' => 1, 'discount_stock' => 0]);
    }

    protected function factura($venta, $importe = '11500.00', array $datos = [])
    {
        $codigo = $datos['cbte_tipo'] ?? 1;
        $tipo = AfipTipoComprobante::where('codigo', $codigo)->first();
        $ticket = AfipTicket::create($datos + ['sale_id' => $venta->id, 'cuit_negocio' => '20123456789',
            'cbte_tipo' => $codigo, 'afip_tipo_comprobante_id' => $tipo ? $tipo->id : null,
            'punto_venta' => '4', 'cbte_numero' => (string) (1000000 + AfipTicket::withTrashed()->max('id')),
            'importe_total' => $importe, 'moneda_id' => 'PES', 'resultado' => 'A', 'cae' => '71450000000000']);
        if (!array_key_exists('cae', $datos)) {
            $ticket->update(['cae' => str_pad((string) $ticket->id, 14, '7', STR_PAD_LEFT)]);
        }
        return $ticket;
    }

    protected function nota($venta, $factura, $importe, array $datos = [])
    {
        return $this->factura($venta, $importe, $datos + ['sale_id' => null,
            'sale_nota_credito_id' => $venta->id, 'sale_afip_ticket_id' => $factura->id,
            'cbte_tipo' => (int) $factura->cbte_tipo + 2, 'moneda_id' => $factura->moneda_id]);
    }

    /** @test */
    public function autorizar_y_repetir_el_asiento_no_duplica_ni_pisa_columnas_de_la_venta()
    {
        $venta = $this->venta();
        $ticket = $this->factura($venta);
        $ticket->setRelation('sale', $venta);
        // Una instancia vieja no puede sobrescribir cambios de otras áreas al guardar su relación.
        Sale::where('id', $venta->id)->update(['observations' => 'Conservar esta observación']);
        AfipWsHelper::update_sale_total_facturado($ticket, 99999, true);
        AfipWsHelper::update_sale_total_facturado($ticket, 99999, false);
        AfipWsHelper::update_sale_total_facturado($ticket, 99999, false);
        $this->assertSame('11500.00', $venta->fresh()->total_facturado);
        $this->assertSame('Conservar esta observación', $venta->fresh()->observations);
        $this->assertSame(1, AfipTicket::where('sale_id', $venta->id)->count());
    }

    /** @test */
    public function varias_facturas_parciales_y_notas_repetidas_conservan_el_neto_por_comprobante()
    {
        $venta = $this->venta();
        $primera = $this->factura($venta, '5000.10');
        AfipWsHelper::update_sale_total_facturado($primera, 5000.10, true);
        $segunda = $this->factura($venta, '6500.20');
        AfipWsHelper::update_sale_total_facturado($segunda, 6500.20, true);
        $nota = $this->nota($venta, $primera, '1000.05');
        AfipWsHelper::update_sale_total_facturado($nota, 1000.05, true);
        AfipWsHelper::update_sale_total_facturado($nota, 1000.05, false);
        $nota2 = $this->nota($venta, $segunda, '500.15');
        AfipWsHelper::update_sale_total_facturado($nota2, 500.15, true);
        $this->assertSame('10000.10', $venta->fresh()->total_facturado);
        $this->assertSame('10000.10', Saldo::analizar($venta)['total']);
        $this->assertSame(4, count(Saldo::analizar($venta)['comprobantes']));
    }

    /** @test */
    public function una_consulta_no_repara_a_escondidas_el_acumulado_historico()
    {
        $venta = $this->venta(['total_facturado' => 23000]);
        $ticket = $this->factura($venta);
        $analisis = AfipWsHelper::update_sale_total_facturado($ticket, 11500, false);
        $this->assertSame('revision', $analisis['estado']);
        $this->assertSame('23000.00', $venta->fresh()->total_facturado);
        $this->assertStringContainsString('soporte', Saldo::aviso($venta->fresh()));
        $this->assertSame('11500.00', Saldo::analizar($venta)['total'], 'La simulación operativa conserva el valor esperado verificable.');
    }

    /** @test */
    public function un_cae_nuevo_no_repara_corrupcion_anterior_ni_se_pierde_por_ella()
    {
        $venta = $this->venta(['total_facturado' => 23000]);
        $this->factura($venta);
        $nuevo = $this->factura($venta, '100.00');
        $resultado = AfipWsHelper::update_sale_total_facturado($nuevo, 100, true);
        $this->assertSame('revision', $resultado['estado']);
        $this->assertSame('23000.00', $venta->fresh()->total_facturado);
        $this->assertNotEmpty($nuevo->fresh()->cae);
        $this->assertSame('A', $nuevo->fresh()->resultado);
    }

    /** @test */
    public function una_consolidacion_solo_actualiza_el_acumulado_de_su_venta_fiscal()
    {
        $consolidacion = $this->venta(['is_consolidacion_facturacion' => 1]);
        $hija = $this->venta(['consolidacion_facturacion_id' => $consolidacion->id, 'total_facturado' => 0]);
        $ticket = $this->factura($consolidacion);
        AfipWsHelper::update_sale_total_facturado($ticket, 11500, true);
        $this->assertSame('11500.00', $consolidacion->fresh()->total_facturado);
        $this->assertEquals(0, $hija->fresh()->total_facturado);
        $this->assertEquals($consolidacion->id, $hija->fresh()->consolidacion_facturacion_id);
    }

    /** @test */
    public function moneda_fiscal_se_conserva_sin_convertir_por_una_cotizacion_actual()
    {
        $venta = $this->venta(['moneda_id' => 2, 'valor_dolar' => 1234]);
        $ticket = $this->factura($venta, '100.00', ['cbte_tipo' => 19, 'moneda_id' => null, 'moneda' => 'DOL']);
        AfipWsHelper::update_sale_total_facturado($ticket, 123400, true);
        $this->assertSame('100.00', $venta->fresh()->total_facturado);
        $this->assertSame('DOL', Saldo::analizar($venta)['moneda']);
        $venta->update(['moneda_id' => 1, 'valor_dolar' => 9999]);
        $this->assertSame('100.00', Saldo::analizar($venta)['total']);
        $this->assertSame('DOL', Saldo::analizar($venta)['moneda']);
    }

    /** @test @dataProvider anomalias */
    public function no_normaliza_a_ciegas_comprobantes_ambiguos($anomalia)
    {
        $venta = $this->venta(['total_facturado' => 11500]);
        $ticket = $this->factura($venta);
        if ($anomalia === 'eliminado') { $ticket->delete(); }
        if ($anomalia === 'duplicado') { $copia = $ticket->replicate(); $copia->sale_id = $this->venta()->id; $copia->save(); }
        if ($anomalia === 'moneda') { $ticket->update(['moneda' => 'DOL']); }
        if ($anomalia === 'identidad') { $ticket->update(['cbte_numero' => '1577x']); }
        if ($anomalia === 'importe') { $ticket->update(['importe_total' => '11500x']); }
        if ($anomalia === 'nc_huerfana') { $this->nota($venta, $ticket, '100.00', ['sale_afip_ticket_id' => null]); }
        if ($anomalia === 'nc_tipo') { $this->nota($venta, $ticket, '100.00', ['cbte_tipo' => 8]); }
        if ($anomalia === 'nc_excesiva') { $this->nota($venta, $ticket, '12000.00'); }
        if ($anomalia === 'pendiente') { $this->factura($venta, '100.00', ['resultado' => null, 'cae' => null]); }
        $analisis = Saldo::analizar($venta);
        $this->assertSame('revision', $analisis['estado']);
        $this->assertNull($analisis['total']);
        $this->assertNotEmpty($analisis['motivos']);
        $this->assertSame('11500.00', $venta->fresh()->total_facturado);
        $json = json_encode($analisis);
        $this->assertStringNotContainsString('71450000000000', $json);
        $this->assertStringNotContainsString('20123456789', $json);
    }

    public function anomalias(): array
    {
        return array_map(function ($caso) { return [$caso]; }, ['eliminado', 'duplicado', 'moneda', 'identidad', 'importe',
            'nc_huerfana', 'nc_tipo', 'nc_excesiva', 'pendiente']);
    }

    /** @test */
    public function dos_respuestas_precargadas_de_una_nc_fce_no_restan_dos_veces_ni_exigen_sale_id()
    {
        $venta = $this->venta(['total_facturado' => 11500]);
        $factura = $this->factura($venta, '11500.00', ['cbte_tipo' => 201]);
        $nota = $this->nota($venta, $factura, '100.00', ['cae' => null, 'resultado' => null]);
        $uno = $this->consulta($nota);
        $dos = $this->consulta($nota->fresh());
        $uno->consultar_comprobante();
        $dos->consultar_comprobante();
        $this->assertSame('11400.00', $venta->fresh()->total_facturado);
        $this->assertNotEmpty($nota->fresh()->cae);
        $this->assertSame('A', $nota->fresh()->cbte_letra);
        $this->assertSame(2, AfipTicket::where(function ($q) use ($venta) {
            $q->where('sale_id', $venta->id)->orWhere('sale_nota_credito_id', $venta->id);
        })->count());
    }

    /** @test */
    public function una_consulta_guarda_el_cae_antes_de_informar_revision_por_nota_huerfana()
    {
        $venta = $this->venta(['total_facturado' => 11500]);
        $factura = $this->factura($venta);
        $nota = $this->nota($venta, $factura, '100.00', ['sale_afip_ticket_id' => null, 'cae' => null, 'resultado' => null]);
        try {
            $this->consulta($nota)->consultar_comprobante();
            $this->fail('La NC huérfana requiere revisión con un mensaje útil.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertSame(422, $e->response->getStatusCode());
            $this->assertStringContainsString('No vuelvas a emitir', $e->response->getContent());
            $this->assertStringContainsString('soporte', $e->response->getContent());
        }
        $this->assertNotEmpty($nota->fresh()->cae);
        $this->assertSame('A', $nota->fresh()->resultado);
        $this->assertSame('11500.00', $venta->fresh()->total_facturado);
    }

    /** @test */
    public function otra_conexion_no_puede_asentar_el_saldo_mientras_la_venta_esta_en_curso()
    {
        $venta = $this->venta();
        $ticket = $this->factura($venta);
        config(['database.connections.conciliacion_concurrente' => config('database.connections.'.config('database.default'))]);
        $otra = DB::connection('conciliacion_concurrente');
        $clave = 'cc-fiscal:'.sha1(DB::connection()->getDatabaseName().':venta:'.$venta->id);
        $this->assertSame(1, (int) $otra->select('SELECT GET_LOCK(?, 0) AS tomado', [$clave])[0]->tomado);
        try {
            try {
                AfipWsHelper::update_sale_total_facturado($ticket, 11500, true);
                $this->fail('La segunda conexión debe respetar el candado de la operación fiscal.');
            } catch (\Illuminate\Validation\ValidationException $e) {
                $this->assertSame(422, $e->response->getStatusCode());
                $this->assertStringContainsString('en curso', $e->response->getContent());
            }
            $this->assertEquals(0, $venta->fresh()->total_facturado);
            $this->assertNotEmpty($ticket->fresh()->cae);
        } finally {
            $otra->select('SELECT RELEASE_LOCK(?)', [$clave]);
            DB::disconnect('conciliacion_concurrente');
        }
        AfipWsHelper::update_sale_total_facturado($ticket, 11500, true);
        AfipWsHelper::update_sale_total_facturado($ticket, 11500, false);
        $this->assertSame('11500.00', $venta->fresh()->total_facturado);
    }

    /** @test @dataProvider caminos_de_nota */
    public function un_saldo_corrupto_rechaza_la_nc_con_422_sin_emitir_ni_guardar_otra_devolucion($existente)
    {
        $venta = $this->venta(['total_facturado' => 23000]);
        $emisor = AfipInformation::create(['user_id' => auth()->id(), 'cuit' => '20123456789',
            'punto_venta' => 4, 'iva_condition_id' => IvaCondition::firstOrFail()->id, 'afip_ticket_production' => 0]);
        $factura = $this->factura($venta, '11500.00', ['afip_information_id' => $emisor->id]);
        // Sólo se reemplaza la frontera remota: init() y la validación nuevos son los reales.
        $this->app->bind(\App\Http\Controllers\Helpers\Afip\AfipNotaCreditoHelper::class, function ($app, $parametros) {
            return new class($parametros['afip_ticket'], $parametros['nota_credito']) extends \App\Http\Controllers\Helpers\Afip\AfipNotaCreditoHelper {
                public function notaCredito() { throw new \RuntimeException('La validación debe frenar antes de contactar ARCA.'); }
            };
        });
        $nota = $existente ? CurrentAcount::create(['sale_id' => $venta->id, 'user_id' => auth()->id(),
            'status' => 'nota_credito', 'haber' => 100, 'moneda_id' => 1]) : null;
        $antes = CurrentAcount::where('sale_id', $venta->id)->count();
        $respuesta = $existente
            ? $this->postJson('api/nota-credito/'.$nota->id.'/facturar', ['afip_ticket_id' => $factura->id])
            : $this->postJson('api/devoluciones', ['sale_id' => $venta->id, 'client_id' => null,
                'generar_current_acount' => false, 'total_devolucion' => 100, 'observaciones' => 'Prueba #145',
                'items' => [], 'descriptions' => [], 'discounts' => [], 'surchages' => [],
                'regresar_stock' => false, 'update_unidades_devueltas' => false, 'facturar_nota_credito' => $factura->id]);
        $respuesta->assertStatus(422);
        $this->assertStringContainsString('soporte', $respuesta->json('message'));
        $this->assertStringContainsString('acumulado', $respuesta->json('message'));
        $this->assertSame($antes, CurrentAcount::where('sale_id', $venta->id)->count());
        $this->assertSame(1, AfipTicket::where('sale_id', $venta->id)->orWhere('sale_nota_credito_id', $venta->id)->count());
        $this->assertSame('23000.00', $venta->fresh()->total_facturado);
    }

    public function caminos_de_nota(): array
    {
        return ['devolución nueva' => [false], 'nota existente' => [true]];
    }

    /** @test */
    public function recuperar_y_consultar_una_nc_de_exportacion_resta_una_vez_en_la_moneda_fiscal()
    {
        $venta = $this->venta(['total_facturado' => 100, 'moneda_id' => 2]);
        $padre = $this->factura($venta, '100.00', ['cbte_tipo' => 19, 'moneda_id' => 'DOL']);
        $nota = $this->nota($venta, $padre, '10.00', ['moneda_id' => null, 'moneda' => null, 'resultado' => null, 'cae' => null]);
        $helper = (new ReflectionClass(AfipFexHelper::class))->newInstanceWithoutConstructor();
        $helper->afip_ticket = $nota;
        $helper->sale = $nota->sale ?: $nota->sale_nota_credito;
        $helper->wsfex = new class($nota) {
            private $nota;
            public $error = false;
            public function __construct($nota) { $this->nota = $nota; }
            public function FEXGetCMP($params) {
                $resultado = $this->error
                    ? (object) ['FEXErr' => (object) ['ErrMsg' => 'Comprobante no encontrado', 'ErrCode' => 1600]]
                    : (object) ['FEXResultGet' => (object) ['Id' => 145, 'Cbte_tipo' => 21, 'Punto_vta' => 4,
                        'Cbte_nro' => $this->nota->cbte_numero, 'Imp_total' => 10, 'Moneda_Id' => 'DOL',
                        'Resultado' => 'A', 'Cae' => str_pad((string) $this->nota->id, 14, '7', STR_PAD_LEFT), 'Fch_venc_Cae' => '20261231']];
                return ['hubo_un_error' => false, 'result' => (object) ['FEXGetCMPResult' => $resultado]];
            }
        };
        $helper->consultar_comprobante();
        $helper->consultar_comprobante();
        $this->assertSame('90.00', $venta->fresh()->total_facturado);
        $this->assertSame('DOL', $nota->fresh()->moneda);
        $this->assertSame('E', $nota->fresh()->cbte_letra);
        $cae = $nota->fresh()->cae;
        $helper->wsfex->error = true;
        $helper->consultar_comprobante();
        $this->assertSame('90.00', $venta->fresh()->total_facturado);
        $this->assertSame($cae, $nota->fresh()->cae);
        $this->assertSame(1, $nota->afip_errors()->count());
    }

    protected function consulta($ticket)
    {
        $helper = (new ReflectionClass(AfipWsfeHelper::class))->newInstanceWithoutConstructor();
        $helper->afip_ticket = $ticket;
        $helper->wsfe = new class($ticket) {
            private $ticket;
            public function __construct($ticket) { $this->ticket = $ticket; }
            public function FECompConsultar($params) {
                return ['hubo_un_error' => false, 'request' => '<request/>', 'response' => '<response/>',
                    'result' => (object) ['FECompConsultarResult' => (object) ['ResultGet' => (object) [
                        'PtoVta' => 4, 'CbteTipo' => (int) $this->ticket->cbte_tipo, 'ImpTotal' => (float) $this->ticket->importe_total,
                        'ImpIVA' => 0, 'MonId' => 'PES', 'Resultado' => 'A', 'CodAutorizacion' => str_pad((string) $this->ticket->id, 14, '7', STR_PAD_LEFT),
                        'FchVto' => '20261231', 'DocTipo' => 99, 'DocNro' => 0,
                    ]]]];
            }
        };
        return $helper;
    }
}
