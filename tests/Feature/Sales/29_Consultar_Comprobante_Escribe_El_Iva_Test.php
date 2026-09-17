<?php

namespace Tests\Feature\Sales;

use App\Http\Controllers\Helpers\Afip\AfipWsfeHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Models\AfipTicket;
use App\Models\Article;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use ReflectionClass;
use Tests\EmpresaTestCase;

/**
 * Misión saneo-ganancia-ventas, defecto 1 del chequeo de merge (17/9/2026) —
 * `AfipWsfeHelper::consultar_comprobante()` escribía `resultado` y NO `importe_iva`.
 *
 * ─── La invariante ────────────────────────────────────────────────────────────────────────────
 *
 * 🔴 **Todo lugar que escriba `afip_tickets.resultado` escribe `importe_iva` en el MISMO
 * `update()`.** Un `resultado = 'A'` con `importe_iva` en NULL hace que `IvaDeVentaHelper` cuente
 * ese comprobante como `sin_medir`, `SaleHelper::calcular_ganancia()` devuelva null y `sales.ganancia`
 * quede en NULL **para siempre**. Antes de esta misión eso era inocuo; ahora es una venta que
 * desaparece del chip de Ganancia sin que nada lo denuncie.
 *
 * ─── Por qué este camino y no otro ────────────────────────────────────────────────────────────
 *
 * `AfipWsfeHelper` escribe `resultado` en TRES lugares, no en uno. `update_afip_ticket()` (la
 * emisión normal) siempre escribió las dos columnas juntas, `saveAfipTicket()` es código muerto, y
 * `consultar_comprobante()` —que es el que se arregla acá— escribía sólo `resultado`.
 *
 * Y no se llega ahí por un camino raro: ante un error de RED al emitir, `solicitar_cae()` dispara
 * `consultar_comprobante()` solo para recuperar el CAE que puede haber quedado autorizado en ARCA,
 * y si lo recupera hace `return` tratando la emisión como exitosa. También se llega a mano desde
 * `AfipTicketController::consultar_comprobante()`. Es el camino de cualquier Responsable Inscripto:
 * exactamente donde el IVA no es cero.
 *
 * ─── Cómo se prueba ───────────────────────────────────────────────────────────────────────────
 *
 * Llamando al método real con la respuesta de ARCA transcripta, y con el helper instanciado sin
 * constructor: el constructor abre el webservice y lee el ticket de acceso del disco, y hablar con
 * ARCA desde un test no es una opción. Lo que estos tests miden es lo que el helper ESCRIBE, no
 * cómo se conecta. Es el mismo patrón que usa `26_Factura_E_No_Deja_La_Ganancia_En_Null_Test`.
 *
 * Contra el código anterior fallan los tests 1, 2 y 3 (el 4 es el guard, y pasa en las dos
 * versiones).
 *
 * @group sales
 */
class Consultar_Comprobante_Escribe_El_Iva_Test extends EmpresaTestCase
{
    /** Delta de tolerancia para comparar floats (mismo criterio que el resto de la suite). */
    const DELTA = 0.01;

    /** Código de ARCA de la Factura A, que es la que discrimina IVA. */
    const CBTE_FACTURA_A = 1;

    /** Punto de venta del comprobante de los escenarios. */
    const PUNTO_VENTA = 4;

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
     * Test 1 — Consultar un comprobante escribe `importe_iva` en el mismo `update()` que `resultado`,
     * con el `ImpIVA` que devuelve ARCA.
     *
     * Es la invariante en su forma más directa. La fuente es la mejor posible: no es lo que nosotros
     * calculamos, es lo que el organismo dice que tiene asentado ese comprobante.
     *
     * @group sales
     * @test
     */
    public function consultar_un_comprobante_escribe_el_importe_iva_que_informa_arca()
    {
        $venta = $this->crear_venta_con_una_linea(100, 121.00, 1);

        $afip_ticket = $this->ticket_sin_iva_medido($venta, 121.00);

        $this->consultar($afip_ticket, ['ImpIVA' => 21.00, 'ImpTotal' => 121.00]);

        $guardado = AfipTicket::find($afip_ticket->id);

        $this->assertEquals(
            'A',
            $guardado->resultado,
            'El comprobante tiene que haber quedado autorizado; si no, este test no está midiendo nada.'
        );

        $this->assertNotNull(
            $guardado->importe_iva,
            'consultar_comprobante() escribió resultado = A y dejó importe_iva en NULL: esa venta '.
            'queda con la ganancia en NULL para siempre. Encontrado: '.var_export($guardado->importe_iva, true)
        );

        $this->assertEqualsWithDelta(
            21.00,
            (float) $guardado->importe_iva,
            self::DELTA,
            'El importe_iva tiene que ser el ImpIVA que informó ARCA, no un número reconstruido.'
        );
    }

    /**
     * Test 2 — Y la venta de ese comprobante conserva su ganancia.
     *
     * Es el encadenado completo, que es lo que realmente duele: recuperar el CAE por consulta
     * después de un error de red dejaba la venta afuera del chip de Ganancia.
     *
     *     121,00 − 100,00 − 21,00 = 0,00
     *
     * Se asserta `assertNotNull` antes que el valor a propósito: el defecto no era un número
     * equivocado, era un null.
     *
     * @group sales
     * @test
     */
    public function despues_de_consultar_el_comprobante_la_venta_tiene_ganancia_calculable()
    {
        $venta = $this->crear_venta_con_una_linea(100, 121.00, 1);

        $afip_ticket = $this->ticket_sin_iva_medido($venta, 121.00);

        $this->consultar($afip_ticket, ['ImpIVA' => 21.00, 'ImpTotal' => 121.00]);

        SaleHelper::set_sale_ganancia(Sale::find($venta->id));

        $ganancia = Sale::find($venta->id)->ganancia;

        $this->assertNotNull(
            $ganancia,
            'Con el IVA ya medido la ganancia tiene que ser calculable. Encontrado: '.var_export($ganancia, true)
        );

        $this->assertEqualsWithDelta(
            0.00,
            (float) $ganancia,
            self::DELTA,
            'Ganancia = 121,00 − 100,00 de costo − 21,00 de IVA declarado = 0,00.'
        );
    }

    /**
     * Test 3 — Si ARCA no devuelve `ImpIVA`, se usa el snapshot `afip_tickets.imp_iva_enviado`.
     *
     * 🔴 Es el dato que ya está persistido en la MISMA fila y que hace recuperable el caso que
     * dispara este camino: `persist_importes_enviados()` escribe `imp_iva_enviado` ANTES del
     * `FECAESolicitar` que puede fallar por red. O sea que en el camino "error de red al emitir" el
     * IVA exacto que se le mandó a ARCA ya está guardado antes de que la llamada pueda romperse.
     *
     * @group sales
     * @test
     */
    public function sin_impiva_de_arca_se_usa_el_importe_enviado_en_la_emision()
    {
        $venta = $this->crear_venta_con_una_linea(100, 121.00, 1);

        $afip_ticket = $this->ticket_sin_iva_medido($venta, 121.00);

        $afip_ticket->imp_iva_enviado = 21.00;
        $afip_ticket->save();

        $this->consultar($afip_ticket, ['ImpTotal' => 121.00]);

        $guardado = AfipTicket::find($afip_ticket->id);

        $this->assertNotNull(
            $guardado->importe_iva,
            'El IVA enviado en la emisión estaba persistido en la misma fila: no hay motivo para dejar '.
            'la columna en NULL. Encontrado: '.var_export($guardado->importe_iva, true)
        );

        $this->assertEqualsWithDelta(
            21.00,
            (float) $guardado->importe_iva,
            self::DELTA,
            'El fallback es imp_iva_enviado, que es el importe exacto que viajó en FECAESolicitar.'
        );
    }

    /**
     * Test 4 — GUARD: sin `ImpIVA` y sin `imp_iva_enviado`, la columna NO se toca.
     *
     * 🔴 Es el límite del arreglo, y es tan importante como el arreglo. `imp_iva_enviado` existe
     * recién desde la migración `2026_04_06_130000`: un ticket anterior a esa fecha lo tiene en NULL,
     * y ahí no hay valor confiable. Lo que no vale es escribir NULL **pudiendo** saberlo; inventar un
     * número, o pisar con NULL un `importe_iva` que ya estaba medido, sería peor que el defecto que
     * este archivo cierra.
     *
     * @group sales
     * @test
     */
    public function sin_ningun_valor_confiable_no_se_pisa_el_importe_iva_guardado()
    {
        $venta = $this->crear_venta_con_una_linea(100, 121.00, 1);

        $afip_ticket = $this->ticket_sin_iva_medido($venta, 121.00);

        $afip_ticket->importe_iva = 21.00;
        $afip_ticket->save();

        $this->consultar($afip_ticket, ['ImpTotal' => 121.00]);

        $guardado = AfipTicket::find($afip_ticket->id);

        $this->assertNotNull(
            $guardado->importe_iva,
            'Una consulta sin ImpIVA no puede BORRAR un importe_iva que ya estaba medido.'
        );

        $this->assertEqualsWithDelta(
            21.00,
            (float) $guardado->importe_iva,
            self::DELTA,
            'El valor guardado tiene que haber quedado intacto: no había ningún dato nuevo que escribir.'
        );
    }

    // =========================================================================================
    // Helpers del archivo
    // =========================================================================================

    /**
     * Corre `consultar_comprobante()` sobre un ticket, con la respuesta de ARCA transcripta.
     *
     * El `wsfe` se reemplaza por un doble que devuelve esa respuesta: el helper se instancia sin
     * constructor, así que no abre ninguna conexión.
     *
     * @param  \App\Models\AfipTicket $afip_ticket
     * @param  array $result_get Campos de `ResultGet` que este escenario quiere fijar.
     * @return void
     */
    protected function consultar($afip_ticket, $result_get)
    {
        $data = array_merge([
            'PtoVta'          => self::PUNTO_VENTA,
            'CbteTipo'        => self::CBTE_FACTURA_A,
            'ImpTotal'        => 121.00,
            'MonId'           => 'PES',
            'Resultado'       => 'A',
            'CodAutorizacion' => '71279049124261',
            'FchVto'          => '20261231',
        ], $result_get);

        $respuesta = [
            'hubo_un_error' => false,
            'request'       => '<request/>',
            'response'      => '<response/>',
            'result'        => (object) [
                'FECompConsultarResult' => (object) [
                    'ResultGet' => (object) $data,
                ],
            ],
        ];

        $helper = (new ReflectionClass(AfipWsfeHelper::class))->newInstanceWithoutConstructor();
        $helper->afip_ticket = AfipTicket::find($afip_ticket->id);
        $helper->wsfe = new DobleDeWsfeQueContesta($respuesta);

        $helper->consultar_comprobante();
    }

    /**
     * Crea el `AfipTicket` tal como queda cuando la emisión no pudo persistir su resultado: con los
     * datos del comprobante ya resueltos (`consultar_comprobante()` no hace nada sin ellos) y con
     * `importe_iva` en NULL.
     *
     * @param  \App\Models\Sale $venta
     * @param  float $total
     * @return \App\Models\AfipTicket
     */
    protected function ticket_sin_iva_medido($venta, $total)
    {
        return AfipTicket::create([
            'sale_id'           => $venta->id,
            'cbte_tipo'         => self::CBTE_FACTURA_A,
            'cbte_numero'       => (string) $venta->id,
            'punto_venta'       => self::PUNTO_VENTA,
            'cuit_negocio'      => '20000000000',
            'importe_iva'       => null,
            'imp_total_enviado' => $total,
        ]);
    }

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
            'name'       => 'zz Test consultar comprobante '.uniqid(),
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
}

/**
 * Doble del webservice de ARCA: devuelve la respuesta que le pasaron y no abre ninguna conexión.
 *
 * Vive en este archivo a propósito: no tiene ningún uso fuera de estos cuatro tests.
 */
class DobleDeWsfeQueContesta
{
    /**
     * Respuesta a devolver.
     *
     * @var array
     */
    private $respuesta;

    /**
     * @param  array $respuesta
     */
    public function __construct($respuesta)
    {
        $this->respuesta = $respuesta;
    }

    /**
     * @param  array $invoice Payload de `FeCompConsReq`, que el doble ignora.
     * @return array
     */
    public function FECompConsultar($invoice)
    {
        return $this->respuesta;
    }
}
