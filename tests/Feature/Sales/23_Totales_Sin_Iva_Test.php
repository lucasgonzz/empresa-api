<?php

namespace Tests\Feature\Sales;

use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\contabilidad\ContabilidadRepository;
use App\Http\Controllers\Helpers\sale\ConsolidarFacturacionHelper;
use App\Models\AfipInformation;
use App\Models\AfipTicket;
use App\Models\Article;
use App\Models\Client;
use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\EmpresaTestCase;

/**
 * Misión ventas-desglose-iva-en-totales (24/9/2026) — los totales en pesos SIN IVA del panel de
 * Ventas (`totales.pesos.total_sin_iva`, `costos_sin_iva` y `ventas_con_iva_sin_medir`).
 *
 * Existen para que el módulo de Ventas cierre la misma cuenta que el Estado de Resultados: `Total` y
 * `Costos` del panel son brutos (con el IVA débito y el IVA de compra adentro), la `Ganancia` ya es
 * sin IVA, y el reporte muestra Ventas netas y Costo netos. El criterio de IVA NO se reescribe en el
 * listado: sale de `IvaDeVentaHelper` y `CostoDeVentaHelper`, los mismos que usa el reporte, y el
 * test (e) es el candado de que no se separen.
 *
 * Todas las ventas de este archivo se llevan a un día lejano (2037-08-20) para no mezclarse con lo
 * que tenga sembrada la base del slot.
 *
 * @group sales
 */
class Totales_Sin_Iva_Test extends EmpresaTestCase
{
    /** Tolerancia para comparar floats (mismo criterio que el resto de la suite de ventas). */
    const DELTA = 0.01;

    /** Día fijo y lejano de todas las ventas del archivo. */
    public $dia = '2037-08-20';

    /**
     * Ids de los artículos creados, para borrarlos en el tearDown (antes del rollback).
     *
     * @var array<int,int>
     */
    protected $articulos_creados = [];

    protected function tearDown(): void
    {
        if (count($this->articulos_creados) >= 1) {
            Article::whereIn('id', $this->articulos_creados)->forceDelete();
        }

        parent::tearDown();
    }

    /**
     * (a) Una venta sin comprobante no declaró IVA: entra entera. Y en una cuenta con el costo neto,
     * `costos_sin_iva` es exactamente `costos`. Las cuatro claves de siempre siguen con su valor.
     *
     * @group sales
     * @test
     */
    public function venta_sin_comprobante_total_sin_iva_es_el_total()
    {
        $this->crear_venta([['costo_real' => 100, 'price_vender' => 121.00, 'amount' => 1]]);

        $pesos = $this->panel()['pesos'];

        $this->assertEqualsWithDelta(121.00, $pesos['total'], self::DELTA);
        $this->assertEqualsWithDelta(100.00, $pesos['costos'], self::DELTA);
        $this->assertEqualsWithDelta(121.00, $pesos['total_sin_iva'], self::DELTA,
            'Sin comprobante no hay débito fiscal: el total entra entero.');
        $this->assertEqualsWithDelta(100.00, $pesos['costos_sin_iva'], self::DELTA,
            'Cuenta con costo neto: no hay crédito fiscal que sacar, costos_sin_iva es costos.');
        $this->assertSame(0, $pesos['ventas_con_iva_sin_medir']);
    }

    /**
     * (b) Venta facturada: `total_sin_iva = total − importe_iva`. Y las reglas del PHPDoc de
     * `IvaDeVentaHelper` que el listado tiene que heredar sin reescribirlas:
     *
     *   - comprobante RECHAZADO (`resultado != 'A'`): no descuenta;
     *   - comprobante ANULADO (soft delete): no descuenta (la consulta va por `DB::table()`, el scope
     *     global no aplica solo);
     *   - autorizado SIN `importe_iva`: NO es un cero, es un dato que falta: entra entero y se cuenta;
     *   - Factura E (exportación) sin `importe_iva`: es IVA 0 por definición, no se cuenta como faltante.
     *
     * @group sales
     * @test
     */
    public function venta_facturada_descuenta_el_iva_de_su_comprobante_y_respeta_las_reglas_del_helper()
    {
        $facturada = $this->crear_venta([['costo_real' => 100, 'price_vender' => 169.40, 'amount' => 1]]);
        $this->facturar($facturada, 29.40);

        $rechazada = $this->crear_venta([['costo_real' => 100, 'price_vender' => 121.00, 'amount' => 1]]);
        $this->facturar($rechazada, 21.00, 'R');

        $anulada = $this->crear_venta([['costo_real' => 100, 'price_vender' => 121.00, 'amount' => 1]]);
        $this->facturar($anulada, 21.00)->delete();

        $sin_medir = $this->crear_venta([['costo_real' => 100, 'price_vender' => 121.00, 'amount' => 1]]);
        $this->facturar($sin_medir, null);

        $exportacion = $this->crear_venta([['costo_real' => 100, 'price_vender' => 121.00, 'amount' => 1]]);
        $this->facturar($exportacion, null, 'A', 19);

        $pesos = $this->panel()['pesos'];

        $this->assertEqualsWithDelta(169.40 + 4 * 121.00, $pesos['total'], self::DELTA);

        // 140 (facturada, netea 29,40) + 121 (rechazada) + 121 (anulada) + 121 (sin medir) + 121 (exportación).
        $this->assertEqualsWithDelta(140.00 + 4 * 121.00, $pesos['total_sin_iva'], self::DELTA,
            'Solo la facturada con importe_iva medido y vigente se netea.');

        $this->assertSame(1, $pesos['ventas_con_iva_sin_medir'],
            'Solo el autorizado sin importe_iva cuenta como "sin medir": la exportación es IVA 0 y la anulada/rechazada no existen.');
    }

    /**
     * (c) Crédito fiscal del costo, por línea: en una cuenta LEGACY con la tilde prendida el costo se
     * guarda bruto y `costos_sin_iva` le saca el IVA de compra; una línea de un artículo con
     * `aplicar_iva` apagado tiene el costo neto y no se toca. En una cuenta migrada y en un
     * monotributista (que no recupera ese IVA) los dos números son iguales.
     *
     * @group sales
     * @test
     */
    public function costo_bruto_de_una_cuenta_legacy_se_netea_y_el_neto_o_el_monotributista_no()
    {
        $this->cuenta_legacy_con_la_tilde_prendida();

        $this->crear_venta([['costo_real' => 121, 'price_vender' => 169.40, 'amount' => 1]]);
        $this->crear_venta([['costo_real' => 100, 'price_vender' => 140.00, 'amount' => 1, 'aplicar_iva' => 0]]);

        $pesos = $this->panel()['pesos'];

        $this->assertEqualsWithDelta(221.00, $pesos['costos'], self::DELTA);
        $this->assertEqualsWithDelta(200.00, $pesos['costos_sin_iva'], self::DELTA,
            'La línea bruta (121) pierde su IVA de compra (21); la de aplicar_iva apagado (100) queda igual.');
        $this->assertLessThan($pesos['costos'], $pesos['costos_sin_iva']);

        // Monotributista migrado: costo bruto pero SIN crédito fiscal, no se netea nada.
        User::where('id', $this->user_id())->update([
            'usar_condicion_fiscal_en_costeo' => 1,
            'condicion_iva_precios'           => User::CONDICION_MT,
            'aplicar_iva_al_costo'            => 0,
        ]);

        $pesos = $this->panel()['pesos'];

        $this->assertEqualsWithDelta($pesos['costos'], $pesos['costos_sin_iva'], self::DELTA,
            'El monotributista no recupera el IVA de sus compras: su costo bruto ES su costo.');

        // Cuenta migrada (costo neto): iguales.
        User::where('id', $this->user_id())->update([
            'usar_condicion_fiscal_en_costeo' => 1,
            'condicion_iva_precios'           => User::CONDICION_RRII,
            'aplicar_iva_al_costo'            => 0,
        ]);

        $pesos = $this->panel()['pesos'];

        $this->assertEqualsWithDelta($pesos['costos'], $pesos['costos_sin_iva'], self::DELTA,
            'Cuenta con costo neto: nada que sacar.');
    }

    /**
     * (d) Compatibilidad hacia atrás: sin `per_page` la respuesta es la de siempre, sin `totales`;
     * y con `per_page` las claves de siempre están y las nuevas viven SOLO en `pesos` (los dólares no
     * se tocan: el IVA de ARCA es en pesos).
     *
     * @group sales
     * @test
     */
    public function sin_per_page_la_respuesta_no_cambia_y_las_claves_nuevas_viven_solo_en_pesos()
    {
        $this->crear_venta([['costo_real' => 100, 'price_vender' => 121.00, 'amount' => 1]]);

        $sin_paginar = $this->getJson('api/sale/from-date/ventas/' . $this->dia);
        $sin_paginar->assertStatus(200);
        $this->assertSame(['models'], array_keys($sin_paginar->json()),
            'Sin per_page la respuesta es { models: [...] } y nada más.');

        $paginado = $this->panel();

        $this->assertSame(
            ['total', 'costos', 'ganancia', 'cuenta_corriente', 'total_sin_iva', 'costos_sin_iva', 'ventas_con_iva_sin_medir'],
            array_keys($paginado['pesos']),
            'Las cuatro claves de siempre primero y las tres nuevas al final.'
        );

        $this->assertSame(
            ['total', 'costos', 'ganancia', 'cuenta_corriente'],
            array_keys($paginado['dolares']),
            'Dólares no lleva claves nuevas.'
        );
    }

    /**
     * Las claves nuevas siguen al conjunto FILTRADO del panel, no al día entero: con "solo con
     * factura" el `total_sin_iva` es el de las facturadas, y una venta en dólares nunca entra.
     *
     * @group sales
     * @test
     */
    public function los_totales_sin_iva_siguen_los_filtros_de_pantalla_y_solo_miran_pesos()
    {
        $facturada = $this->crear_venta([['costo_real' => 100, 'price_vender' => 121.00, 'amount' => 1]]);
        $this->facturar($facturada, 21.00);

        $this->crear_venta([['costo_real' => 100, 'price_vender' => 121.00, 'amount' => 1]]);

        $en_dolares = $this->crear_venta([['costo_real' => 10, 'price_vender' => 12.10, 'amount' => 1]]);
        Sale::where('id', $en_dolares->id)->update(['moneda_id' => 2]);
        $this->facturar($en_dolares, 2.10);

        $todo = $this->panel()['pesos'];
        $this->assertEqualsWithDelta(100.00 + 121.00, $todo['total_sin_iva'], self::DELTA);

        $solo_facturadas = $this->panel('&afip_ticket_show_option=solo-con-factura');
        $this->assertEqualsWithDelta(121.00, $solo_facturadas['pesos']['total'], self::DELTA);
        $this->assertEqualsWithDelta(100.00, $solo_facturadas['pesos']['total_sin_iva'], self::DELTA);

        $solo_sin_factura = $this->panel('&afip_ticket_show_option=solo-sin-factura');
        $this->assertEqualsWithDelta(121.00, $solo_sin_factura['pesos']['total_sin_iva'], self::DELTA);
    }

    /**
     * Con la preferencia "fechar por día de entrega" el listado incluye ventas cuyo `created_at` cae
     * FUERA del día pedido. Si el rango de comprobantes se derivara del pedido, esa venta quedaría
     * medida con IVA 0 (o sea, como si fuera en negro). El rango sale del conjunto.
     *
     * @group sales
     * @test
     */
    public function con_fecha_de_entrega_la_venta_creada_otro_dia_igual_netea_su_iva()
    {
        User::where('id', $this->user_id())->update(['fechar_ventas_por_fecha_de_entrega' => 1]);

        $venta = $this->crear_venta([['costo_real' => 100, 'price_vender' => 121.00, 'amount' => 1]]);
        $this->facturar($venta, 21.00);

        // Creada un mes antes, entregada el día pedido: el listado la muestra en $this->dia.
        Sale::where('id', $venta->id)->update([
            'created_at'    => '2037-07-10 12:00:00',
            'fecha_entrega' => $this->dia . ' 12:00:00',
        ]);

        $panel = $this->panel();

        $this->assertSame(1, $panel['cantidad'], 'Guard: la venta tiene que estar en el listado del día.');
        $this->assertEqualsWithDelta(121.00, $panel['pesos']['total'], self::DELTA);
        $this->assertEqualsWithDelta(100.00, $panel['pesos']['total_sin_iva'], self::DELTA,
            'Si da 121, el rango de los comprobantes se acotó al día pedido y no al conjunto.');
    }

    /**
     * (e) PARIDAD con el Estado de Resultados sobre el mismo día: `total_sin_iva` es exactamente
     * `ContabilidadRepository::ventas_brutas()` y `costos_sin_iva` es exactamente
     * `costo_mercaderia_vendida()`, en una cuenta legacy (costo bruto) y con una venta facturada, una
     * sin comprobante y dos consolidadas bajo un mismo comprobante. Y la cuenta cierra con la
     * ganancia persistida: `total_sin_iva − costos_sin_iva = ganancia`.
     *
     * Si este test se pone rojo, el listado y el reporte volvieron a divergir.
     *
     * @group sales
     * @test
     */
    public function paridad_con_las_ventas_brutas_y_el_costo_del_estado_de_resultados()
    {
        $this->cuenta_legacy_con_la_tilde_prendida();

        $facturada = $this->crear_venta([['costo_real' => 121, 'price_vender' => 169.40, 'amount' => 1]]);
        $this->facturar($facturada, 29.40);

        $this->crear_venta([['costo_real' => 121, 'price_vender' => 169.40, 'amount' => 2]]);

        $cliente = Client::where('name', TestingFerreteriaSeeder::CLIENTE_CONTADO)->firstOrFail();
        $chica = $this->crear_venta([['costo_real' => 60, 'price_vender' => 121.00, 'amount' => 1]], $cliente->id);
        $grande = $this->crear_venta([['costo_real' => 120, 'price_vender' => 242.00, 'amount' => 1]], $cliente->id);

        $afip_information = AfipInformation::where('user_id', $this->user_id())->firstOrFail();

        $consolidada = ConsolidarFacturacionHelper::consolidar(
            [$chica->id, $grande->id],
            $cliente->id,
            $this->user_id(),
            $afip_information->id,
            1,
            false,
            [],
            false
        );

        AfipTicket::create([
            'sale_id'            => $consolidada->id,
            'resultado'          => 'A',
            'importe_iva'        => 63.00,
            'importe_total'      => $consolidada->total,
            'afip_fecha_emision' => Carbon::now()->format('Y-m-d'),
            'cbte_numero'        => (string) $consolidada->id,
            'cbte_letra'         => 'A',
            'cbte_tipo'          => 1,
            'cuit_negocio'       => '20000000000',
            'cae'                => '00000000000000',
        ]);

        // La contenedora se crea "ahora": el reporte y el panel la dejan afuera (soloVentasReales) y el
        // día de la consolidación NO tiene por qué ser el de las ventas que contiene.
        SaleHelper::set_sale_ganancia(Sale::find($chica->id));
        SaleHelper::set_sale_ganancia(Sale::find($grande->id));

        $pesos = $this->panel()['pesos'];

        $ventas_brutas = ContabilidadRepository::ventas_brutas($this->user_id(), $this->dia, $this->dia);
        $costo = ContabilidadRepository::costo_mercaderia_vendida($this->user_id(), $this->dia, $this->dia);

        $this->assertEqualsWithDelta($ventas_brutas, $pesos['total_sin_iva'], self::DELTA,
            'total_sin_iva tiene que ser el renglón "Ventas netas" del Estado de Resultados.');
        $this->assertEqualsWithDelta($costo, $pesos['costos_sin_iva'], self::DELTA,
            'costos_sin_iva tiene que ser el costo de mercadería vendida (neto) del Estado de Resultados.');

        // 169,40 + 2*169,40 + 121 + 242 de total; IVA declarado: 29,40 + 63 (prorrateado entre las dos).
        $this->assertEqualsWithDelta(169.40 + 338.80 + 121.00 + 242.00 - 29.40 - 63.00, $pesos['total_sin_iva'], self::DELTA);

        $this->assertEqualsWithDelta(
            $pesos['ganancia'],
            $pesos['total_sin_iva'] - $pesos['costos_sin_iva'],
            self::DELTA,
            'La cuenta que el panel muestra a la vista tiene que cerrar con la ganancia persistida.'
        );
    }

    // =========================================================================================
    // Helpers del archivo
    // =========================================================================================

    /**
     * Pide el listado paginado del día y devuelve `totales`.
     *
     * @param  string $extra Parámetros de query adicionales, con `&` adelante.
     * @return array
     */
    protected function panel($extra = '')
    {
        $response = $this->getJson('api/sale/from-date/ventas/' . $this->dia . '?per_page=25' . $extra);
        $response->assertStatus(200);

        return $response->json('totales');
    }

    /**
     * Cuenta LEGACY con la tilde vieja prendida: el costo se guarda BRUTO (configuración de ferretotal).
     *
     * @return void
     */
    protected function cuenta_legacy_con_la_tilde_prendida()
    {
        User::where('id', $this->user_id())->update([
            'usar_condicion_fiscal_en_costeo' => 0,
            'aplicar_iva_al_costo'            => 1,
            'condicion_iva_precios'           => User::CONDICION_RRII,
        ]);
    }

    /**
     * Id del usuario dueño del fixture.
     *
     * @return int
     */
    protected function user_id()
    {
        return (int) User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->firstOrFail()->id;
    }

    /**
     * Crea una venta por `POST api/sale` (así `total_cost`, `ganancia` y el pivot salen del pipeline
     * real) y la lleva al día del archivo.
     *
     * @param  array<int,array> $lineas `costo_real`, `price_vender`, `amount` y, opcional, `aplicar_iva`.
     * @param  int|null $client_id
     * @return \App\Models\Sale
     */
    protected function crear_venta($lineas, $client_id = null)
    {
        $items = [];
        $total = 0.0;

        foreach ($lineas as $linea) {

            $atributos = [
                'name'       => 'zz Test totales sin iva ' . uniqid(),
                'user_id'    => $this->user_id(),
                'costo_real' => $linea['costo_real'],
            ];

            if (isset($linea['aplicar_iva'])) {
                $atributos['aplicar_iva'] = $linea['aplicar_iva'];
            }

            $articulo = Article::create($atributos);

            $this->articulos_creados[] = $articulo->id;

            $items[] = [
                'is_article'   => true,
                'id'           => $articulo->id,
                'price_vender' => $linea['price_vender'],
                'amount'       => $linea['amount'],
                'costo_real'   => $linea['costo_real'],
            ];

            $total += (float) $linea['price_vender'] * (int) $linea['amount'];
        }

        $total = round($total, 2);

        $response = $this->postJson('api/sale', [
            'client_id'                        => $client_id,
            'address_id'                       => null,
            'save_current_acount'              => 0,
            'omitir_en_cuenta_corriente'       => 1,
            'to_check'                         => 0,
            'current_acount_payment_method_id' => null,
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
            'caja_id'                          => null,
            'afip_tipo_comprobante_id'         => null,
            'descuento'                        => null,
            'discounts'                        => [],
            'surchages'                        => [],
            'items'                            => $items,
        ]);

        if ($response->getStatusCode() !== 201) {
            $this->fail('POST api/sale devolvió ' . $response->getStatusCode() . '. Cuerpo completo: ' . $response->getContent());
        }

        $venta = Sale::find($response->json('model.id'));

        if (is_null($venta->total_cost) || (float) $venta->total_cost == 0.0) {
            $this->fail('La venta recién creada quedó con total_cost ' . var_export($venta->total_cost, true) . ': el escenario no prueba nada.');
        }

        Sale::where('id', $venta->id)->update([
            'created_at'   => $this->dia . ' 12:00:00',
            'terminada_at' => $this->dia . ' 12:00:00',
        ]);

        return Sale::find($venta->id);
    }

    /**
     * "Factura" una venta: crea su `AfipTicket` y recalcula la ganancia, que es lo que hace en
     * producción `MakeAfipTicket::recalcular_ganancia_facturada()` cuando ARCA contesta.
     *
     * @param  \App\Models\Sale $venta
     * @param  float|null $importe_iva IVA declarado (null = autorizado sin medir).
     * @param  string $resultado 'A' autorizado, 'R' rechazado.
     * @param  int $cbte_tipo Código de ARCA (1 Factura A, 19 Factura E).
     * @return \App\Models\AfipTicket
     */
    protected function facturar($venta, $importe_iva, $resultado = 'A', $cbte_tipo = 1)
    {
        $afip_ticket = AfipTicket::create([
            'sale_id'            => $venta->id,
            'resultado'          => $resultado,
            'importe_iva'        => $importe_iva,
            'importe_total'      => $venta->total,
            'afip_fecha_emision' => Carbon::now()->format('Y-m-d'),
            'cbte_numero'        => (string) $venta->id,
            'cbte_letra'         => 'A',
            'cbte_tipo'          => $cbte_tipo,
            'cuit_negocio'       => '20000000000',
            'cae'                => '00000000000000',
        ]);

        SaleHelper::set_sale_ganancia(Sale::find($venta->id));

        return $afip_ticket;
    }
}
