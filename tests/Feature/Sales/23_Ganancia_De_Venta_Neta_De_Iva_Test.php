<?php

namespace Tests\Feature\Sales;

use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\sale\ConsolidarFacturacionHelper;
use App\Models\AfipInformation;
use App\Models\AfipTicket;
use App\Models\Article;
use App\Models\Client;
use App\Models\Sale;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Artisan;
use Tests\EmpresaTestCase;

/**
 * Misión saneo-ganancia-ventas (17/9/2026) — la fórmula de `sales.ganancia`.
 *
 *     sales.ganancia = total − total_cost − IVA efectivamente declarado por esa venta
 *
 * Hasta esta misión la fórmula era `total − total_cost`, o sea precio CON IVA menos costo SIN IVA:
 * para un Responsable Inscripto que aplica el IVA después del margen, informaba como ganancia todo
 * el IVA débito (costo 100 + margen 40 % → informaba $69,40 donde la ganancia real es $40).
 *
 * 🔴 Lo que estos tests protegen, y es lo más importante del archivo: el IVA se descuenta del
 * COMPROBANTE, no de la condición fiscal del negocio. La "solución obvia" —dividir el precio por
 * (1 + alícuota) mirando las tildes de costeo— rompe justo el caso más frecuente, la venta SIN
 * comprobante: el 63 % de las ventas de ferretotal y el 51 % de las de golonorte (medido el
 * 17/9/2026). Ahí el IVA cobrado se lo queda el negocio y la fórmula vieja ya era correcta. El test
 * `venta_sin_comprobante_no_descuenta_nada()` es el que se pone rojo si alguien la reescribe así.
 *
 * Cómo se "factura" acá: el `AfipTicket` se crea directo con `AfipTicket::create()` sobre una venta
 * hecha por el endpoint real, y después se llama a `SaleHelper::set_sale_ganancia()` — que es
 * exactamente lo que hace en producción `MakeAfipTicket::recalcular_ganancia_facturada()` cuando
 * ARCA contesta. Es el mismo patrón que ya usan `2_Posicion_Fiscal_Test.php` y
 * `database/seeders/AfipTicketSeeder.php`: hablar con el webservice real de AFIP desde un test no
 * es una opción.
 *
 * @group sales
 */
class Ganancia_De_Venta_Neta_De_Iva_Test extends EmpresaTestCase
{
    /** Delta de tolerancia para comparar floats (mismo criterio que el resto de la suite). */
    const DELTA = 0.01;

    /**
     * Ids de los artículos creados por este archivo, para borrarlos en el tearDown.
     *
     * @var array<int,int>
     */
    protected $articulos_creados = [];

    /**
     * Borra los artículos que creó el test antes del rollback de `DatabaseTransactions` — red real
     * redundante, el mismo criterio que usa `EscenariosDePlata::limpiar_escenarios()`.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if (count($this->articulos_creados) >= 1) {
            Article::whereIn('id', $this->articulos_creados)->forceDelete();
        }

        parent::tearDown();
    }

    /**
     * Test 1 — Venta facturada: la ganancia descuenta el IVA que declaró el comprobante.
     *
     * Costo 100, margen 40 % (precio neto 140), IVA 21 % → la venta sale $169,40 y el comprobante
     * declara $29,40 de IVA. La ganancia real del negocio es $40: los $29,40 son de ARCA.
     *
     * Se assertea primero el número SIN comprobante ($69,40) para que el test pruebe el descuento y
     * no una coincidencia: si las dos aserciones dieran lo mismo, el IVA no se estaría descontando.
     *
     * @group sales
     * @test
     */
    public function venta_con_comprobante_autorizado_descuenta_el_iva_declarado()
    {
        $venta = $this->crear_venta_con_una_linea(100, 169.40, 1);

        $this->assertEqualsWithDelta(
            69.40,
            (float) $venta->ganancia,
            self::DELTA,
            'Antes de facturar, la ganancia tiene que ser total - total_cost, sin descontar nada.'
        );

        $this->facturar($venta, 29.40);

        $this->assertEqualsWithDelta(
            40.00,
            (float) Sale::find($venta->id)->ganancia,
            self::DELTA,
            'Después de facturar, la ganancia tiene que descontar el importe_iva del comprobante.'
        );
    }

    /**
     * Test 2 — Venta SIN comprobante: la ganancia no descuenta absolutamente nada.
     *
     * 🔴 Este es el test que rompe la solución equivocada. Un back-out de IVA por condición fiscal
     * le sacaría el 21 % a esta venta igual, y estaría mal: sin comprobante no hay débito fiscal, el
     * IVA cobrado queda en la casa y `total − total_cost` ya era la ganancia correcta. Es, además,
     * el caso mayoritario en los dos clientes medidos.
     *
     * @group sales
     * @test
     */
    public function venta_sin_comprobante_no_descuenta_nada()
    {
        $venta = $this->crear_venta_con_una_linea(100, 169.40, 1);

        $this->assertEqualsWithDelta(69.40, (float) $venta->ganancia, self::DELTA);

        // Recalcular no puede cambiar el número: no hay comprobante del que sacar IVA.
        SaleHelper::set_sale_ganancia(Sale::find($venta->id));

        $this->assertEqualsWithDelta(
            69.40,
            (float) Sale::find($venta->id)->ganancia,
            self::DELTA,
            'Una venta sin comprobante no declara IVA: la ganancia es total - total_cost y nada más.'
        );
    }

    /**
     * Test 3 — Comprobante NO autorizado (`resultado != 'A'`): no descuenta.
     *
     * Un rechazo de ARCA deja el `afip_ticket` en la base con su `importe_iva` calculado, pero ese
     * IVA nunca se declaró. Es el mismo criterio de `ContabilidadRepository::query_iva_debito()`.
     *
     * @group sales
     * @test
     */
    public function comprobante_rechazado_no_descuenta()
    {
        $venta = $this->crear_venta_con_una_linea(100, 169.40, 1);

        $this->facturar($venta, 29.40, 'R');

        $this->assertEqualsWithDelta(
            69.40,
            (float) Sale::find($venta->id)->ganancia,
            self::DELTA,
            'Un comprobante rechazado no declaró IVA: no tiene que descontar nada.'
        );
    }

    /**
     * Test 4 — Alícuotas mixtas en la misma venta (21 % y 10,5 %): se descuenta el IVA REAL del
     * comprobante, sin reconstruir alícuota por alícuota.
     *
     * Línea A: costo 100, neto 120 al 21 % → 145,20 (IVA 25,20).
     * Línea B: costo 200, neto 240 al 10,5 % → 265,20 (IVA 25,20).
     * Total 410,40 · costo 300 · IVA declarado 50,40 → ganancia 60,00.
     *
     * Que las dos líneas den el mismo IVA con alícuotas distintas es a propósito: si alguien
     * reemplazara el criterio del comprobante por un 21 % parejo, el número se caería.
     *
     * @group sales
     * @test
     */
    public function alicuotas_mixtas_descuentan_el_iva_del_comprobante()
    {
        $venta = $this->crear_venta([
            ['costo_real' => 100, 'price_vender' => 145.20, 'amount' => 1],
            ['costo_real' => 200, 'price_vender' => 265.20, 'amount' => 1],
        ]);

        $this->assertEqualsWithDelta(
            110.40,
            (float) $venta->ganancia,
            self::DELTA,
            'Sin comprobante, la ganancia de las dos líneas es 410,40 - 300.'
        );

        $this->facturar($venta, 50.40);

        $this->assertEqualsWithDelta(
            60.00,
            (float) Sale::find($venta->id)->ganancia,
            self::DELTA,
            'Con alícuotas mixtas el IVA a descontar es el total del comprobante (25,20 + 25,20).'
        );
    }

    /**
     * Test 5 — Comprobante anulado (soft delete de `afip_tickets`, migración `2026_04_15_120000`):
     * deja de descontar.
     *
     * `IvaDeVentaHelper` consulta por `DB::table()`, donde el scope global de `SoftDeletes` NO
     * aplica solo: sin el `whereNull('deleted_at')` escrito a mano, un comprobante anulado seguiría
     * descontando IVA para siempre. Este test es el que lo verifica.
     *
     * @group sales
     * @test
     */
    public function comprobante_anulado_deja_de_descontar()
    {
        $venta = $this->crear_venta_con_una_linea(100, 169.40, 1);

        $afip_ticket = $this->facturar($venta, 29.40);

        $this->assertEqualsWithDelta(40.00, (float) Sale::find($venta->id)->ganancia, self::DELTA);

        $afip_ticket->delete();

        SaleHelper::set_sale_ganancia(Sale::find($venta->id));

        $this->assertEqualsWithDelta(
            69.40,
            (float) Sale::find($venta->id)->ganancia,
            self::DELTA,
            'Un comprobante anulado (soft delete) no declara IVA: la ganancia vuelve a total - total_cost.'
        );
    }

    /**
     * Test 6 — Comprobante autorizado SIN `importe_iva` medido: la ganancia queda en NULL.
     *
     * 🔴 Lo que NO puede pasar es que se cuente como IVA 0: eso sería informar una venta facturada
     * como si hubiera sido en negro, con la ganancia inflada en todo el IVA y sin que nada lo avise.
     * Null ya significa "no se puede calcular" en esta columna, y el backfill lo cuenta aparte y
     * dice cómo saldarlo (`php artisan set_iva_debito`). Medido el 17/9/2026: 53 comprobantes así
     * en ferretotal y 8 en golonorte.
     *
     * @group sales
     * @test
     */
    public function comprobante_autorizado_sin_iva_medido_deja_la_ganancia_en_null()
    {
        $venta = $this->crear_venta_con_una_linea(100, 169.40, 1);

        $this->facturar($venta, null);

        $ganancia = Sale::find($venta->id)->ganancia;

        $this->assertNull(
            $ganancia,
            'Con un comprobante autorizado sin importe_iva medido, la ganancia tiene que quedar en null, '.
            'nunca en el número viejo ni en total - total_cost (eso sería contar una venta facturada '.
            'como si fuera en negro). Valor encontrado: '.var_export($ganancia, true)
        );
    }

    /**
     * Test 7 — El backfill (`php artisan set_sales_ganancia`) deja EXACTAMENTE el mismo número que
     * el guardado en vivo, en los tres casos que conviven en una base real: sin comprobante, con
     * comprobante autorizado y con comprobante sin IVA medido.
     *
     * Se ensucia la columna a propósito antes de correr el comando: si el comando no escribiera
     * nada, el test compararía la basura contra lo guardado en vivo y se pondría rojo.
     *
     * @group sales
     * @test
     */
    public function el_backfill_deja_el_mismo_numero_que_el_guardado_en_vivo()
    {
        $sin_comprobante = $this->crear_venta_con_una_linea(100, 169.40, 1);

        $facturada = $this->crear_venta_con_una_linea(100, 338.80, 1);
        $this->facturar($facturada, 58.80);

        $sin_iva_medido = $this->crear_venta_con_una_linea(100, 500.00, 1);
        $this->facturar($sin_iva_medido, null);

        $esperado = [
            $sin_comprobante->id => Sale::find($sin_comprobante->id)->ganancia,
            $facturada->id       => Sale::find($facturada->id)->ganancia,
            $sin_iva_medido->id  => Sale::find($sin_iva_medido->id)->ganancia,
        ];

        // Guard: si el escenario no quedó armado (por ejemplo, el costo no se persistió), el test no
        // estaría probando nada. Se para y se reporta, no se ajusta la aserción de abajo.
        if (is_null($esperado[$sin_comprobante->id]) || is_null($esperado[$facturada->id])) {
            $this->fail(
                'El escenario del backfill no quedó armado: las ventas sin comprobante y facturada '.
                'tienen que tener una ganancia calculada antes de correr el comando.'
            );
        }

        $ids = array_keys($esperado);

        Sale::whereIn('id', $ids)->update(['ganancia' => -999999]);

        Artisan::call('set_sales_ganancia', ['--chunk' => 2]);

        foreach ($esperado as $id => $ganancia_en_vivo) {

            $ganancia_backfill = Sale::find($id)->ganancia;

            if (is_null($ganancia_en_vivo)) {
                $this->assertNull(
                    $ganancia_backfill,
                    'La venta '.$id.' quedó en null en vivo y el backfill le escribió '.var_export($ganancia_backfill, true).'.'
                );
                continue;
            }

            $this->assertEqualsWithDelta(
                (float) $ganancia_en_vivo,
                (float) $ganancia_backfill,
                self::DELTA,
                'El backfill tiene que dejar el mismo número que el guardado en vivo para la venta '.$id.'.'
            );
        }
    }

    /**
     * Test 8 — Venta consolidada: recibe la parte proporcional del comprobante único que emitió su
     * consolidación.
     *
     * Cuando varias ventas se consolidan para facturar de una (`ConsolidarFacturacionHelper`), el
     * comprobante cuelga de la venta CONTENEDORA y las originales quedan sin comprobante propio. Sin
     * el prorrateo, cada original mediría IVA 0 y se contaría como si hubiera sido en negro — el
     * mismo error que el test 6 evita por el otro lado.
     *
     * Ventas de $121 y $242 (neto 100 y 200) → contenedora de $363 con IVA declarado $63. A la
     * primera le toca 1/3 ($21) y a la segunda 2/3 ($42).
     *
     * @group sales
     * @test
     */
    public function venta_consolidada_recibe_la_parte_proporcional_del_comprobante_de_su_consolidacion()
    {
        $cliente = Client::where('name', TestingFerreteriaSeeder::CLIENTE_CONTADO)->firstOrFail();

        $venta_chica = $this->crear_venta_con_una_linea(60, 121.00, 1, $cliente->id);
        $venta_grande = $this->crear_venta_con_una_linea(120, 242.00, 1, $cliente->id);

        $afip_information = AfipInformation::where('user_id', $this->user_id())->firstOrFail();

        // emitir_afip en false: el test no puede hablar con ARCA, el comprobante se crea abajo a mano.
        $consolidada = ConsolidarFacturacionHelper::consolidar(
            [$venta_chica->id, $venta_grande->id],
            $cliente->id,
            $this->user_id(),
            $afip_information->id,
            1,
            false,
            [],
            false
        );

        // Guard: el prorrateo es exacto sólo porque la contenedora se crea con la suma de los
        // totales. Si eso cambiara, este test estaría midiendo otra cosa.
        $this->assertEqualsWithDelta(
            363.00,
            (float) $consolidada->total,
            self::DELTA,
            'La consolidación tiene que valer la suma de las ventas que contiene.'
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

        // Lo que hace en producción MakeAfipTicket::recalcular_ganancia_facturada() al facturar una
        // consolidación: recalcular la contenedora y cada una de las ventas que contiene.
        SaleHelper::set_sale_ganancia(Sale::find($venta_chica->id));
        SaleHelper::set_sale_ganancia(Sale::find($venta_grande->id));

        $this->assertEqualsWithDelta(
            40.00,
            (float) Sale::find($venta_chica->id)->ganancia,
            self::DELTA,
            'A la venta de $121 le toca un tercio del IVA de la consolidación ($21): 121 - 60 - 21.'
        );

        $this->assertEqualsWithDelta(
            80.00,
            (float) Sale::find($venta_grande->id)->ganancia,
            self::DELTA,
            'A la venta de $242 le tocan dos tercios del IVA de la consolidación ($42): 242 - 120 - 42.'
        );
    }

    // =========================================================================================
    // Helpers del archivo
    // =========================================================================================

    /**
     * Id del usuario dueño del fixture de testing.
     *
     * @return int
     */
    protected function user_id()
    {
        return (int) \App\Models\User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->firstOrFail()->id;
    }

    /**
     * Crea una venta de una sola línea por el endpoint real y devuelve el modelo ya guardado.
     *
     * @param  float $costo_real Costo unitario del artículo.
     * @param  float $price_vender Precio unitario de venta (CON IVA, como lo manda Vender).
     * @param  int $amount Unidades.
     * @param  int|null $client_id
     * @return \App\Models\Sale
     */
    protected function crear_venta_con_una_linea($costo_real, $price_vender, $amount, $client_id = null)
    {
        return $this->crear_venta([
            ['costo_real' => $costo_real, 'price_vender' => $price_vender, 'amount' => $amount],
        ], $client_id);
    }

    /**
     * Crea una venta por `POST api/sale` con una línea por cada entrada de `$lineas`, cada una con
     * su propio artículo recién creado (así el costo de la línea es exactamente el que pide el test
     * y no depende del estado del fixture).
     *
     * @param  array<int,array{costo_real: float, price_vender: float, amount: int}> $lineas
     * @param  int|null $client_id
     * @return \App\Models\Sale
     */
    protected function crear_venta($lineas, $client_id = null)
    {
        $items = [];
        $total = 0.0;

        foreach ($lineas as $linea) {
            $articulo = Article::create([
                'name'       => 'zz Test ganancia neta de iva '.uniqid(),
                'user_id'    => $this->user_id(),
                'costo_real' => $linea['costo_real'],
            ]);

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
            $this->fail('POST api/sale devolvió '.$response->getStatusCode().'. Cuerpo completo: '.$response->getContent());
        }

        $venta = Sale::find($response->json('model.id'));

        // Guard del escenario: sin total_cost no hay ganancia que medir, y el test no estaría
        // probando la fórmula sino el null de arriba. Se para y se reporta.
        if (is_null($venta->total_cost) || (float) $venta->total_cost == 0.0) {
            $this->fail(
                'La venta recién creada quedó con total_cost '.var_export($venta->total_cost, true).
                '. El escenario necesita que el costo de la línea se haya persistido; sin eso este '.
                'test no prueba la fórmula de la ganancia.'
            );
        }

        return $venta;
    }

    /**
     * "Factura" una venta: crea su `AfipTicket` y recalcula la ganancia, que es lo que hace en
     * producción `MakeAfipTicket::recalcular_ganancia_facturada()` cuando ARCA contesta.
     *
     * @param  \App\Models\Sale $venta
     * @param  float|null $importe_iva IVA declarado; null simula el comprobante viejo sin medir.
     * @param  string $resultado 'A' autorizado, cualquier otra cosa es un rechazo.
     * @return \App\Models\AfipTicket
     */
    protected function facturar($venta, $importe_iva, $resultado = 'A')
    {
        $afip_ticket = AfipTicket::create([
            'sale_id'            => $venta->id,
            'resultado'          => $resultado,
            'importe_iva'        => $importe_iva,
            'importe_total'      => $venta->total,
            'afip_fecha_emision' => Carbon::now()->format('Y-m-d'),
            'cbte_numero'        => (string) $venta->id,
            'cbte_letra'         => 'A',
            'cbte_tipo'          => 1,
            'cuit_negocio'       => '20000000000',
            'cae'                => '00000000000000',
        ]);

        SaleHelper::set_sale_ganancia(Sale::find($venta->id));

        return $afip_ticket;
    }
}
