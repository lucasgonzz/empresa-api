<?php

namespace Tests\Feature\Sales;

use App\Http\Controllers\Helpers\Afip\AfipFexHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\contabilidad\ContabilidadRepository;
use App\Models\AfipTicket;
use App\Models\Article;
use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Artisan;
use ReflectionClass;
use Tests\EmpresaTestCase;

/**
 * Misión saneo-ganancia-ventas, hallazgo 2 (17/9/2026) — la Factura E (exportación) dejaba la
 * ganancia en NULL para siempre.
 *
 * `AfipFexHelper::update_afip_ticket()` escribía `resultado` y NO `importe_iva`, que quedaba en
 * NULL. Encadenado: `IvaDeVentaHelper` lo contaba como `sin_medir`, `SaleHelper::calcular_ganancia()`
 * devolvía null y `MakeAfipTicket::recalcular_ganancia_facturada()` —que corre inmediatamente
 * después— **perdía la ganancia en el mismo request que emitía la factura**. Sin salida, porque el
 * comando que mediría ese IVA (`set_iva_debito`) está roto en `develop`.
 *
 * 🔴 Pero una exportación NO TIENE IVA: es 0, no "sin medir". WSFEX ni siquiera tiene campo para
 * declararlo, a diferencia de WSFE. El camino normal (`AfipWsfeHelper::update_afip_ticket()`)
 * escribe `resultado` e `importe_iva` en el MISMO `update()`, y por eso ahí nunca hubo ventana; el
 * de exportación rompía esa invariante.
 *
 * Se arregló en los dos lados, y los dos están testeados acá:
 *
 * | Dónde | Qué arregla | Test |
 * |---|---|---|
 * | `IvaDeVentaHelper` (lectura) | los comprobantes YA emitidos, que hoy tienen la ganancia en null | 1, 3 y 4 |
 * | `AfipFexHelper` (emisión) | que la columna deje de nacer en NULL | 2 |
 *
 * Y el test 5 es el guard de que la regla NO se derramó: una Factura A sin `importe_iva` medido
 * tiene que seguir dejando la ganancia en null, porque ahí el dato de verdad falta.
 *
 * @group sales
 */
class Factura_E_No_Deja_La_Ganancia_En_Null_Test extends EmpresaTestCase
{
    /** Delta de tolerancia para comparar floats (mismo criterio que el resto de la suite). */
    const DELTA = 0.01;

    /** Código de ARCA de la Factura de Exportación. */
    const CBTE_FACTURA_E = 19;

    /** Código de ARCA de la Factura A. */
    const CBTE_FACTURA_A = 1;

    /**
     * Ids de los artículos creados por este archivo, para borrarlos en el tearDown.
     *
     * @var array<int,int>
     */
    protected $articulos_creados = [];

    /**
     * Borra los artículos que creó el test antes del rollback de `DatabaseTransactions`.
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
     * Test 1 — Una Factura E YA EMITIDA, con `importe_iva` en NULL, no deja la ganancia en null.
     *
     * Es el caso que hoy está en producción y que ningún comando puede saldar: el comprobante ya
     * salió, la columna quedó vacía y no hay forma de "medirle" un IVA que nunca existió. Se lee
     * como 0 porque es 0.
     *
     *     169,40 − 100 − 0 = 69,40
     *
     * Antes de este arreglo, acá había un null.
     *
     * @group sales
     * @test
     */
    public function factura_e_ya_emitida_sin_importe_iva_calcula_la_ganancia()
    {
        $venta = $this->crear_venta_con_una_linea(100, 169.40, 1);

        $this->facturar($venta, null, self::CBTE_FACTURA_E);

        $ganancia = Sale::find($venta->id)->ganancia;

        $this->assertNotNull(
            $ganancia,
            'Una exportación no tiene IVA: es 0, no un dato que falte. La ganancia tiene que ser '.
            'calculable. Encontrado: '.var_export($ganancia, true)
        );

        $this->assertEqualsWithDelta(
            69.40,
            (float) $ganancia,
            self::DELTA,
            'Factura E: sin IVA que descontar, la ganancia es 169,40 - 100 = 69,40.'
        );
    }

    /**
     * Test 2 — Al EMITIR una Factura E se persiste `importe_iva = 0` en el mismo `update()` que
     * escribe `resultado`.
     *
     * Reproduce la respuesta de WSFEX tal cual la devuelve ARCA (la estructura está transcripta en
     * un comentario del propio `AfipFexHelper`) y llama al método real. El helper se instancia sin
     * constructor porque el constructor abre el webservice y lee el ticket de acceso del disco:
     * hablar con ARCA desde un test no es una opción, y lo que este test mide es lo que el helper
     * ESCRIBE, no cómo se conecta.
     *
     * 🔴 La aserción importante es la de `resultado` + `importe_iva` juntos: la invariante que este
     * arreglo restituye es que los dos se persisten en la misma escritura, como ya hacía el camino
     * de WSFE. Sin eso hay una ventana en la que la venta está facturada y su IVA "no se midió".
     *
     * @group sales
     * @test
     */
    public function al_emitir_una_factura_e_se_persiste_importe_iva_en_cero()
    {
        $venta = $this->crear_venta_con_una_linea(100, 169.40, 1);

        $afip_ticket = AfipTicket::create([
            'sale_id'      => $venta->id,
            'cbte_tipo'    => self::CBTE_FACTURA_E,
            'cbte_numero'  => (string) $venta->id,
            'cuit_negocio' => '20000000000',
        ]);

        /** Respuesta de WSFEX, con la forma exacta que documenta AfipFexHelper. */
        $respuesta = [
            'result' => (object) [
                'FEXAuthorizeResult' => (object) [
                    'FEXResultAuth' => (object) [
                        'Id'           => 62,
                        'Cuit'         => 30716582899,
                        'Cbte_tipo'    => self::CBTE_FACTURA_E,
                        'Punto_vta'    => 4,
                        'Cbte_nro'     => 58,
                        'Cae'          => '71279049124261',
                        'Fch_venc_Cae' => '20261231',
                        'Fch_cbte'     => '20260917',
                        'Resultado'    => 'A',
                        'Reproceso'    => 'N',
                        'Motivos_Obs'  => '',
                    ],
                ],
            ],
        ];

        $helper = (new ReflectionClass(AfipFexHelper::class))->newInstanceWithoutConstructor();
        $helper->afip_ticket = $afip_ticket;
        $helper->sale = Sale::find($venta->id);

        $helper->update_afip_ticket($respuesta, 'PES', 1);

        $guardado = AfipTicket::find($afip_ticket->id);

        $this->assertEquals(
            'A',
            $guardado->resultado,
            'El comprobante tiene que haber quedado autorizado; si no, este test no está midiendo nada.'
        );

        $this->assertNotNull(
            $guardado->importe_iva,
            'Al emitir una Factura E, importe_iva NO puede quedar en NULL: una exportación no tiene '.
            'IVA y el 0 es el dato, no un relleno. Encontrado: '.var_export($guardado->importe_iva, true)
        );

        $this->assertEqualsWithDelta(
            0.00,
            (float) $guardado->importe_iva,
            self::DELTA,
            'El importe_iva de una exportación es 0.'
        );
    }

    /**
     * Test 3 — Y la venta de ese comprobante recién emitido tiene ganancia, no null.
     *
     * Es el encadenado completo: lo que hace en producción
     * `MakeAfipTicket::recalcular_ganancia_facturada()` justo después de `AfipWsController::init()`.
     *
     * @group sales
     * @test
     */
    public function despues_de_emitir_la_factura_e_la_venta_conserva_su_ganancia()
    {
        $venta = $this->crear_venta_con_una_linea(100, 169.40, 1);

        $this->assertEqualsWithDelta(
            69.40,
            (float) Sale::find($venta->id)->ganancia,
            self::DELTA,
            'Antes de facturar la ganancia ya estaba bien; lo que este test verifica es que facturar no la rompa.'
        );

        $this->facturar($venta, 0.00, self::CBTE_FACTURA_E);

        $this->assertEqualsWithDelta(
            69.40,
            (float) Sale::find($venta->id)->ganancia,
            self::DELTA,
            'Emitir la Factura E no puede cambiar la ganancia: la exportación no declara IVA.'
        );
    }

    /**
     * Test 4 — El backfill también la calcula (la misma regla vive en el SQL de
     * `IvaDeVentaHelper::subquery_por_venta()`, que es el camino del lote).
     *
     * Si la regla se hubiera escrito sólo en PHP, este test se pondría rojo: el backfill mide el
     * lote entero en SQL y no pasa por `medir_venta()`.
     *
     * @group sales
     * @test
     */
    public function el_backfill_calcula_la_ganancia_de_una_venta_exportada()
    {
        $venta = $this->crear_venta_con_una_linea(100, 169.40, 1);

        $this->facturar($venta, null, self::CBTE_FACTURA_E);

        Sale::where('id', $venta->id)->update(['ganancia' => -999999]);

        $salida = Artisan::call('set_sales_ganancia', ['--chunk' => 10]);

        $this->assertEquals(0, $salida, 'El backfill tiene que correr. Devolvió '.$salida.'.');

        $this->assertEqualsWithDelta(
            69.40,
            (float) Sale::find($venta->id)->ganancia,
            self::DELTA,
            'El backfill tiene que aplicar la misma regla de exportación que el guardado en vivo.'
        );
    }

    /**
     * Test 5 — GUARD: una Factura A sin `importe_iva` medido SIGUE dejando la ganancia en null.
     *
     * 🔴 Es el test que impide que el arreglo de la exportación se derrame. Ahí el dato realmente
     * falta, y contarlo como 0 sería informar una venta facturada como si hubiera sido en negro —
     * el error que `IvaDeVentaHelper` existe para no cometer. La diferencia con la exportación no es
     * de grado: en una el 0 se sabe, en la otra se asumiría.
     *
     * @group sales
     * @test
     */
    public function factura_a_sin_iva_medido_sigue_dejando_la_ganancia_en_null()
    {
        $venta = $this->crear_venta_con_una_linea(100, 169.40, 1);

        $this->facturar($venta, null, self::CBTE_FACTURA_A);

        $this->assertNull(
            Sale::find($venta->id)->ganancia,
            'Una Factura A sin importe_iva medido tiene que seguir dejando la ganancia en null: ahí el '.
            'dato falta de verdad.'
        );
    }

    /**
     * Test 6 — El aviso del Estado de Resultados tampoco cuenta la exportación.
     *
     * `ContabilidadRepository::ventas_con_iva_sin_medir()` lee `afip_tickets.importe_iva` derecho
     * (no pasa por `IvaDeVentaHelper`), así que sin la misma exclusión cada exportación aparecería
     * como un renglón "sin medir" imposible de saldar.
     *
     * Se compara contra la medición del propio escenario en vez de contra un absoluto, para no
     * depender de lo que haya sembrado el fixture en el día de hoy: la Factura E no tiene que mover
     * el contador y la Factura A sí, y las dos cosas se verifican en la misma corrida.
     *
     * ⚠️ Las dos ventas llevan totales distintos a propósito: `SaleController::store()` descarta una
     * venta idéntica de los últimos 5 segundos (candado antiduplicados, 5/9/2026) y devolvería un
     * 200 sin cuerpo en vez de crear la segunda.
     *
     * @group sales
     * @test
     */
    public function el_aviso_de_iva_sin_medir_no_cuenta_la_exportacion()
    {
        $hoy = Carbon::now()->format('Y-m-d');

        $antes = ContabilidadRepository::ventas_con_iva_sin_medir($this->user_id(), $hoy, $hoy);

        $exportada = $this->crear_venta_con_una_linea(100, 169.40, 1);
        $this->facturar($exportada, null, self::CBTE_FACTURA_E);

        $this->assertEquals(
            $antes,
            ContabilidadRepository::ventas_con_iva_sin_medir($this->user_id(), $hoy, $hoy),
            'Una Factura E sin importe_iva no es un dato que falte: no tiene que encender el aviso.'
        );

        $interna = $this->crear_venta_con_una_linea(100, 254.10, 1);
        $this->facturar($interna, null, self::CBTE_FACTURA_A);

        $this->assertEquals(
            $antes + 1,
            ContabilidadRepository::ventas_con_iva_sin_medir($this->user_id(), $hoy, $hoy),
            'Una Factura A sin importe_iva SÍ tiene que encender el aviso; si no, el test anterior no '.
            'prueba nada (podría estar contando 0 por otro motivo).'
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
        return (int) User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->firstOrFail()->id;
    }

    /**
     * Crea una venta de una sola línea por el endpoint real y devuelve el modelo ya guardado.
     *
     * @param  float $costo_real Costo unitario del artículo.
     * @param  float $price_vender Precio unitario de venta.
     * @param  int $amount Unidades.
     * @return \App\Models\Sale
     */
    protected function crear_venta_con_una_linea($costo_real, $price_vender, $amount)
    {
        $articulo = Article::create([
            'name'       => 'zz Test factura e '.uniqid(),
            'user_id'    => $this->user_id(),
            'costo_real' => $costo_real,
        ]);

        $this->articulos_creados[] = $articulo->id;

        $total = round((float) $price_vender * (int) $amount, 2);

        $response = $this->postJson('api/sale', [
            'client_id'                        => null,
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
            'items'                            => [[
                'is_article'   => true,
                'id'           => $articulo->id,
                'price_vender' => $price_vender,
                'amount'       => $amount,
                'costo_real'   => $costo_real,
            ]],
        ]);

        if ($response->getStatusCode() !== 201) {
            $this->fail('POST api/sale devolvió '.$response->getStatusCode().'. Cuerpo completo: '.$response->getContent());
        }

        $venta = Sale::find($response->json('model.id'));

        if (is_null($venta->total_cost) || (float) $venta->total_cost == 0.0) {
            $this->fail(
                'La venta recién creada quedó con total_cost '.var_export($venta->total_cost, true).
                '. Sin costo persistido este test no prueba nada.'
            );
        }

        return $venta;
    }

    /**
     * "Factura" una venta: crea su `AfipTicket` autorizado y recalcula la ganancia, que es lo que
     * hace en producción `MakeAfipTicket::recalcular_ganancia_facturada()` cuando ARCA contesta.
     *
     * @param  \App\Models\Sale $venta
     * @param  float|null $importe_iva IVA declarado; null es el comprobante que nunca lo tuvo.
     * @param  int $cbte_tipo Código de ARCA del comprobante.
     * @return \App\Models\AfipTicket
     */
    protected function facturar($venta, $importe_iva, $cbte_tipo)
    {
        $afip_ticket = AfipTicket::create([
            'sale_id'            => $venta->id,
            'resultado'          => 'A',
            'importe_iva'        => $importe_iva,
            'importe_total'      => $venta->total,
            'afip_fecha_emision' => Carbon::now()->format('Y-m-d'),
            'cbte_numero'        => (string) $venta->id,
            'cbte_letra'         => $cbte_tipo == self::CBTE_FACTURA_E ? 'E' : 'A',
            'cbte_tipo'          => $cbte_tipo,
            'cuit_negocio'       => '20000000000',
            'cae'                => '00000000000000',
        ]);

        SaleHelper::set_sale_ganancia(Sale::find($venta->id));

        return $afip_ticket;
    }
}
