<?php

namespace Tests\Feature\ConsolidarFacturacion;

use App\Http\Controllers\Helpers\Afip\MakeAfipTicket;
use App\Http\Controllers\Helpers\Afip\AfipWsfeHelper;
use App\Http\Controllers\Helpers\AfipHelper;
use App\Http\Controllers\Helpers\sale\ConsolidarFacturacionHelper;
use App\Models\AfipInformation;
use App\Models\AfipTicket;
use App\Models\Client;
use App\Models\CurrentAcount;
use App\Models\IvaCondition;
use App\Models\Sale;
use App\Models\Service;
use App\Models\Surchage;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/** @group consolidar_facturacion */
class Redondeo_por_venta_Test extends EmpresaTestCase
{
    /**
     * @test
     * @dataProvider agrupaciones
     */
    public function quince_aportes_redondeados_cierran_con_recargo_y_servicio_sin_recargo($agrupar)
    {
        $usuario = $this->usuario();
        $articulo = $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA);
        $recargo = Surchage::create(['name' => 'Dos por ciento', 'percentage' => 2, 'user_id' => $usuario->id]);
        $servicio = Service::create(['name' => 'Servicio sin recargo', 'price' => 12500, 'user_id' => $usuario->id]);
        $ids = [];
        for ($i = 0; $i < 15; $i++) {
            $venta = $this->venta($articulo->id, 100.01, 1.23, '21', 125.47 + ($i == 14 ? 12500 : 0));
            $venta->surchages()->attach($recargo->id, ['percentage' => 2]);
            if ($i == 14) {
                $venta->services()->attach($servicio->id, ['price' => 12500, 'amount' => 1, 'discount' => 0]);
            }
            $ids[] = $venta->id;
        }
        $preview = ConsolidarFacturacionHelper::vista_previa($ids, $this->cliente()->id, $usuario->id);
        $this->assertSame(14382.05, $preview['total']);
        $this->assertCount(0, $preview['avisos']);
        $stock = $articulo->stock;
        $movimientos_stock = DB::table('stock_movements')->count();
        $cuentas = CurrentAcount::count();
        $consolidada = $this->consolidar($ids, $agrupar)->fresh();
        $importes = $this->fiscal($consolidada)->getImportes();
        $this->assertSame(14382.05, $importes['total']);
        $this->assertEqualsWithDelta(11885.99, $importes['gravado'], 0.001);
        $this->assertEqualsWithDelta(2496.06, $importes['iva'], 0.001);
        $this->assertEqualsWithDelta($importes['gravado'], $importes['ivas']['21']['BaseImp'], 0.001);
        $this->assertEqualsWithDelta($importes['iva'], $importes['ivas']['21']['Importe'], 0.001);
        $this->assertEqualsWithDelta(12500, $consolidada->services->first()->pivot->price, 0.000001);
        $this->assertCount($agrupar ? 1 : 15, $consolidada->articles);
        $this->assertSame($stock, $articulo->fresh()->stock);
        $this->assertSame($movimientos_stock, DB::table('stock_movements')->count());
        $this->assertSame($cuentas, CurrentAcount::count());
        $this->assertEquals(15, Sale::whereIn('id', $ids)->where('consolidacion_facturacion_id', $consolidada->id)->count());
        foreach (Sale::whereIn('id', $ids)->get() as $original) {
            $this->assertSame('100.01', $original->articles->first()->pivot->price);
        }
        MakeAfipTicket::validar_total_consolidado($consolidada, $consolidada->afip_information_id, 1);
        $payload = $this->autorizar_localmente($consolidada);
        $this->assertSame(14382.05, $payload['ImpTotal']);
        $this->assertEqualsWithDelta(11885.99, $payload['ImpNeto'], 0.001);
        $this->assertEqualsWithDelta(2496.06, $payload['ImpIVA'], 0.001);
        $this->assertSame($movimientos_stock, DB::table('stock_movements')->count());
        $this->assertSame($cuentas, CurrentAcount::count());
    }

    /**
     * @test
     * @dataProvider agrupaciones
     */
    public function quince_ventas_grandes_conservan_los_4012315_53_al_autorizar($agrupar)
    {
        $articulo = $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA);
        $recargo = Surchage::create(['name' => 'Dos por ciento grande', 'percentage' => 2, 'user_id' => $this->usuario()->id]);
        $ids = [];
        for ($i = 0; $i < 15; $i++) {
            $ultima = $i == 14;
            $venta = $this->venta($articulo->id, $ultima ? 258792.07 : 212544.01,
                $ultima ? 1.01 : 1.23, '21', $ultima ? 279107.59 : 266657.71);
            $venta->surchages()->attach($recargo->id, ['percentage' => 2]);
            if ($ultima) {
                $servicio = Service::create(['name' => 'Servicio histórico', 'price' => 12500, 'user_id' => $this->usuario()->id]);
                $venta->services()->attach($servicio->id, ['price' => 12500, 'amount' => 1, 'discount' => 0]);
            }
            $ids[] = $venta->id;
        }
        $preview = ConsolidarFacturacionHelper::vista_previa($ids, $this->cliente()->id, $this->usuario()->id);
        $this->assertSame(4012315.53, $preview['total']);
        $this->assertCount(0, $preview['avisos']);
        $venta = $this->consolidar($ids, $agrupar)->fresh();
        $this->assertEqualsWithDelta(4012315.53, $venta->total, 0.001);
        $this->assertCount($agrupar ? 2 : 15, $venta->articles);
        MakeAfipTicket::validar_total_consolidado($venta, $venta->afip_information_id, 1);
        $payload = $this->autorizar_localmente($venta);
        $this->assertSame(4012315.53, $payload['ImpTotal']);
        $this->assertEqualsWithDelta(3315963.25, $payload['ImpNeto'], 0.001);
        $this->assertEqualsWithDelta(696352.28, $payload['ImpIVA'], 0.001);
    }

    /**
     * @test
     * @dataProvider residuos_con_tasa_cero
     */
    public function el_residuo_entre_alicuotas_no_inventa_iva_a_tasa_cero($bruto_gravado, $bruto_cero, $total, $base_cero)
    {
        $articulo = $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA);
        $venta = $this->venta($articulo->id, 121, 1, '21', $total);
        $venta->articles()->detach();
        $relacion = $venta->articles();
        $relacion->attach($articulo->id, $relacion->conservar_precision(['price' => $bruto_gravado, 'amount' => 1, 'iva_percentage' => '21']));
        $relacion->attach($articulo->id, $relacion->conservar_precision(['price' => $bruto_cero, 'amount' => 1, 'iva_percentage' => '0']));
        $venta->update(['is_consolidacion_facturacion' => 1]);
        $importes = $this->fiscal($venta->fresh())->getImportes();
        $this->assertSame($total, $importes['total']);
        $this->assertSame(0.0, $importes['ivas']['0']['Importe']);
        $this->assertSame($base_cero, $importes['ivas']['0']['BaseImp']);
        $this->assertGreaterThanOrEqual(0, $importes['iva']);
        $this->assertEqualsWithDelta($total, $importes['gravado'] + $importes['iva'], 0.001);
    }

    public function residuos_con_tasa_cero(): array
    {
        return [
            'residuo positivo' => [121.004, 1000.004, 1121.01, 1000.01],
            'residuo negativo' => [1.006, 100.006, 101.01, 100.0],
        ];
    }

    /**
     * @test
     * @dataProvider agrupaciones
     */
    public function descuentos_y_alicuotas_historicas_mixtas_cierran_sin_afectar_exentos($agrupar)
    {
        $articulo = $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA);
        $ids = [];
        foreach (['21', '10.5', '0', 'Exento', 'No Gravado'] as $alicuota) {
            // 100.01 x 1.23 x 0.9 = 110.71107; cada remito aporta 110.71.
            $ids[] = $this->venta($articulo->id, 100.01, 1.23, $alicuota, 110.71, 10)->id;
        }
        $venta = $this->consolidar($ids, $agrupar)->fresh();
        $importes = $this->fiscal($venta)->getImportes();
        $this->assertSame(553.55, $importes['total']);
        $this->assertEqualsWithDelta(110.71, $importes['exento'], 0.001);
        $this->assertEqualsWithDelta(110.71, $importes['neto_no_gravado'], 0.001);
        // 110.71/1.21 = 91.4958…; 110.71/1.105 = 100.1900…; más 110.71 al 0%.
        $this->assertEqualsWithDelta(302.40, $importes['gravado'], 0.001);
        $this->assertEqualsWithDelta(29.73, $importes['iva'], 0.001);
        $this->assertSame(0.0, $importes['ivas']['0']['Importe']);
        $this->assertCount(5, $venta->articles);
        MakeAfipTicket::validar_total_consolidado($venta, $venta->afip_information_id, 1);
    }

    /** @test */
    public function una_diferencia_material_individual_no_se_disimula_aunque_se_compense_en_la_suma()
    {
        $articulo = $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA);
        $a = $this->venta($articulo->id, 100, 1, '21', 100.01);
        $b = $this->venta($articulo->id, 100, 1, '21', 99.99);
        $preview = ConsolidarFacturacionHelper::vista_previa([$a->id, $b->id], $this->cliente()->id, $this->usuario()->id);
        $this->assertSame(200.0, $preview['total']);
        $this->assertCount(2, $preview['avisos']);
        $this->assertNull($a->fresh()->consolidacion_facturacion_id);
        $this->assertNull($b->fresh()->consolidacion_facturacion_id);

        $contenedoras = Sale::where('is_consolidacion_facturacion', 1)->count();
        $stock = DB::table('articles')->where('id', $articulo->id)->value('stock');
        $movimientos_stock = DB::table('stock_movements')->count();
        $cuentas = CurrentAcount::count();
        $comprobantes = AfipTicket::count();
        $numero = $a->num ?? $a->id;
        $mensaje = 'Los renglones de la venta N° '.$numero.' no llegan a su total con los ajustes disponibles. Revisá esa venta antes de consolidar.';
        foreach ([true, false] as $agrupar) {
            $rechazo = null;
            try {
                // Punto de venta 0: si se rompiera la guarda individual, la validación
                // fiscal siguiente rechaza otro mensaje antes de conectarse a ARCA.
                ConsolidarFacturacionHelper::consolidar([$a->id, $b->id], $this->cliente()->id,
                    $this->usuario()->id, 0, 1, $agrupar, [], true);
            } catch (\Exception $e) {
                $rechazo = $e;
            }
            $this->assertNotNull($rechazo, 'La emisión debe rechazar las diferencias individuales aunque se compensen.');
            $this->assertSame($mensaje, $rechazo->getMessage());
            $this->assertSame($contenedoras, Sale::where('is_consolidacion_facturacion', 1)->count());
            $this->assertNull($a->fresh()->consolidacion_facturacion_id);
            $this->assertNull($b->fresh()->consolidacion_facturacion_id);
            $this->assertSame($stock, DB::table('articles')->where('id', $articulo->id)->value('stock'));
            $this->assertSame($movimientos_stock, DB::table('stock_movements')->count());
            $this->assertSame($cuentas, CurrentAcount::count());
            $this->assertSame($comprobantes, AfipTicket::count());
        }
    }

    /** @test */
    public function el_redondeo_de_la_moneda_de_origen_no_se_amplifica_al_cotizar()
    {
        $articulo = $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA);
        $venta = $this->venta($articulo->id, 100.01, 1.23, '21', 125.47);
        $venta->update(['moneda_id' => 2, 'valor_dolar' => 1000]);
        $recargo = Surchage::create(['name' => 'Recargo en dólares', 'percentage' => 2, 'user_id' => $this->usuario()->id]);
        $venta->surchages()->attach($recargo->id, ['percentage' => 2]);
        $consolidada = $this->consolidar([$venta->id], true)->fresh();
        $importes = $this->fiscal($consolidada)->getImportes();
        $this->assertEqualsWithDelta(125.47, $consolidada->total, 0.001);
        $this->assertSame(125470.0, $importes['total']);
        MakeAfipTicket::validar_total_consolidado($consolidada, $consolidada->afip_information_id, 1);
    }

    /** @test */
    public function la_guarda_fiscal_conserva_el_rechazo_de_un_centavo_real()
    {
        $articulo = $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA);
        $a = $this->venta($articulo->id, 100, 1, '21', 100.01);
        $venta = $this->consolidar([$a->id], false);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        MakeAfipTicket::validar_total_consolidado($venta, $venta->afip_information_id, 1);
    }

    /** @test */
    public function doble_envio_rechaza_la_seleccion_ya_consolidada_sin_nueva_cuenta()
    {
        $articulo = $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA);
        $original = $this->venta($articulo->id, 100.01, 1.23, '21', 123.01);
        $venta = $this->consolidar([$original->id], true);
        $cantidad = Sale::where('is_consolidacion_facturacion', 1)->count();
        try {
            $this->consolidar([$original->id], true);
            $this->fail('La selección ya consolidada debe rechazarse.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('ya fue incluida', $e->getMessage());
        }
        $this->assertSame($cantidad, Sale::where('is_consolidacion_facturacion', 1)->count());
        $this->assertEquals($venta->id, $original->fresh()->consolidacion_facturacion_id);
    }

    private function autorizar_localmente(Sale $venta): array
    {
        $informacion = AfipInformation::where('user_id', $this->usuario()->id)->firstOrFail();
        $ticket = AfipTicket::create([
            'sale_id' => $venta->id, 'afip_information_id' => $informacion->id,
            'afip_tipo_comprobante_id' => 1, 'afip_fecha_emision' => date('Y-m-d'),
            'iva_negocio' => 'Responsable inscripto', 'punto_venta' => $informacion->punto_venta,
            'cuit_negocio' => $informacion->cuit,
        ]);
        $doble = new class {
            public $payload;
            public function FECompUltimoAutorizado($datos)
            {
                return ['hubo_un_error' => false, 'result' => (object) [
                    'FECompUltimoAutorizadoResult' => (object) ['CbteNro' => 900000],
                ]];
            }
            public function FECAESolicitar($payload)
            {
                $this->payload = $payload['FeCAEReq']['FeDetReq']['FECAEDetRequest'];
                return ['hubo_un_error' => false, 'request' => '<prueba-local/>', 'response' => '<autorizada-local/>',
                    'result' => (object) ['FECAESolicitarResult' => (object) [
                        'FeCabResp' => (object) ['Resultado' => 'A', 'CbteTipo' => 1],
                        'FeDetResp' => (object) ['FECAEDetResponse' => (object) [
                            'Concepto' => 1, 'DocNro' => $this->payload['DocNro'],
                            'CAE' => '71234567890123', 'CAEFchVto' => '20261231',
                        ]],
                    ]],
                ];
            }
        };
        $emisor = new class($ticket, $doble) extends AfipWsfeHelper {
            public function __construct($ticket, $doble)
            {
                $this->afip_ticket = $ticket;
                $this->wsfe = $doble;
            }
        };
        $emisor->solicitar_cae();
        $autorizado = $ticket->fresh();
        $this->assertSame('A', $autorizado->resultado);
        $this->assertSame('71234567890123', $autorizado->cae);
        $this->assertEqualsWithDelta($venta->total, $autorizado->importe_total, 0.001);
        $this->assertEqualsWithDelta($venta->total, $autorizado->imp_total_enviado, 0.001);
        $this->assertEqualsWithDelta($doble->payload['ImpNeto'], $autorizado->imp_neto_enviado, 0.001);
        $this->assertEqualsWithDelta($doble->payload['ImpIVA'], $autorizado->imp_iva_enviado, 0.001);
        $this->assertEqualsWithDelta($doble->payload['ImpNeto'], array_sum(array_column($doble->payload['Iva'], 'BaseImp')), 0.001);
        $this->assertEqualsWithDelta($doble->payload['ImpIVA'], array_sum(array_column($doble->payload['Iva'], 'Importe')), 0.001);
        return $doble->payload;
    }

    public function agrupaciones(): array
    {
        return ['agrupando' => [true], 'sin agrupar' => [false]];
    }

    private function usuario()
    {
        return User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->firstOrFail();
    }

    private function cliente()
    {
        return Client::where('name', TestingFerreteriaSeeder::CLIENTE_CC)->firstOrFail();
    }

    private function venta($article_id, $precio, $cantidad, $iva, $total, $descuento = 0)
    {
        $venta = Sale::create([
            'user_id' => $this->usuario()->id, 'client_id' => $this->cliente()->id,
            'total' => $total, 'sub_total' => $precio * $cantidad,
            'moneda_id' => 1, 'descuento' => 0, 'terminada' => 1,
            'omitir_en_cuenta_corriente' => 1, 'save_current_acount' => 0,
            'surchages_in_services' => 0, 'discount_stock' => 0,
        ]);
        $venta->articles()->attach($article_id, [
            'price' => $precio, 'amount' => $cantidad, 'iva_percentage' => $iva,
            'discount' => $descuento,
        ]);
        return $venta;
    }

    private function consolidar(array $ids, $agrupar)
    {
        $informacion = AfipInformation::where('user_id', $this->usuario()->id)->firstOrFail();
        return ConsolidarFacturacionHelper::consolidar($ids, $this->cliente()->id, $this->usuario()->id,
            $informacion->id, 1, $agrupar, [], false);
    }

    private function fiscal($venta)
    {
        $ticket = new AfipTicket(['cbte_letra' => 'A', 'cbte_tipo' => 1]);
        $informacion = new AfipInformation();
        $informacion->setRelation('iva_condition', IvaCondition::where('name', 'Responsable inscripto')->firstOrFail());
        $ticket->setRelation('afip_information', $informacion);
        return new AfipHelper($ticket, null, null, $this->usuario(), $venta);
    }
}
