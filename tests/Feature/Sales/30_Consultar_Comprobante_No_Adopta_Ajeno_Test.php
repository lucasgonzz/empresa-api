<?php

namespace Tests\Feature\Sales;

use App\Http\Controllers\Helpers\Afip\AfipWsfeHelper;
use App\Models\AfipError;
use App\Models\AfipTicket;
use App\Models\Article;
use App\Models\Client;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use ReflectionClass;
use Tests\EmpresaTestCase;

/**
 * Misión afip-recuperar-comprobante-validado (29/9/2026) —
 * `AfipWsfeHelper::consultar_comprobante()` adoptaba el comprobante que ARCA devolvía para
 * (punto de venta, tipo, número) con solo que el total coincidiera.
 *
 * ─── El defecto ───────────────────────────────────────────────────────────────────────────────
 *
 * En Ferretotal dos ventas de $147.500 quedaron con el mismo comprobante 0005-19099 y el mismo CAE:
 * la segunda "recuperó" por consulta el comprobante de la primera, porque coincidían punto de venta,
 * tipo e importe. Nadie validaba el receptor ni que el número ya estuviera tomado por otro ticket.
 *
 * ─── La invariante ────────────────────────────────────────────────────────────────────────────
 *
 * 🔴 Un comprobante recuperado por consulta se adopta SOLO si (a) el receptor que ARCA tiene
 * asentado es el de la venta y (b) ningún otro ticket vivo tiene ya ese número ni ese CAE. Si no,
 * no se escribe ni CAE ni resultado, y queda un `AfipError` visible.
 *
 * ─── Cómo se prueba ───────────────────────────────────────────────────────────────────────────
 *
 * Igual que `29_Consultar_Comprobante_Escribe_El_Iva_Test`: se llama al método real con la
 * respuesta de ARCA transcripta y el helper instanciado sin constructor (el constructor abre el
 * webservice). Contra el código anterior fallan los tests 1 (la ganancia no se recalculaba en la
 * consulta manual), 2 y 3 (adoptaba el comprobante ajeno); el 4 y el 5 son guards que dependen de la
 * propiedad `comprobante_recuperado_descartado`, que antes no existía.
 *
 * @group sales
 */
class Consultar_Comprobante_No_Adopta_Ajeno_Test extends EmpresaTestCase
{
    const DELTA = 0.01;

    /** Código de ARCA de la Factura A. */
    const CBTE_FACTURA_A = 1;

    const PUNTO_VENTA = 4;

    const CAE = '71279049124261';

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
     * Test 1 — Receptor que coincide y número libre: se adopta, con el IVA, y se recalcula la ganancia.
     *
     * La ganancia se deja a propósito en un valor imposible (999) antes de consultar: si la consulta
     * manual no la recalcula, el test lo denuncia. 121,00 − 100,00 − 21,00 = 0,00.
     *
     * @group sales
     * @test
     */
    public function con_el_mismo_receptor_y_numero_libre_adopta_y_recalcula_la_ganancia()
    {
        $venta = $this->crear_venta_con_cliente('20111111112');

        Sale::where('id', $venta->id)->update(['ganancia' => 999]);

        $ticket = $this->ticket_de($venta, '19099');

        $this->consultar($ticket, ['ImpIVA' => 21.00, 'DocTipo' => 80, 'DocNro' => 20111111112]);

        $guardado = AfipTicket::find($ticket->id);

        $this->assertEquals(self::CAE, $guardado->cae, 'Con receptor y número válidos el comprobante se adopta.');
        $this->assertEquals('A', $guardado->resultado);
        $this->assertEqualsWithDelta(21.00, (float) $guardado->importe_iva, self::DELTA);
        $this->assertEquals(0, AfipError::where('afip_ticket_id', $ticket->id)->count(), 'No debe quedar ningún error.');

        $ganancia = Sale::find($venta->id)->ganancia;

        $this->assertNotNull($ganancia);
        $this->assertEqualsWithDelta(
            0.00,
            (float) $ganancia,
            self::DELTA,
            'La consulta manual tiene que recalcular sales.ganancia al adoptar el comprobante. Encontrado: '
                .var_export($ganancia, true)
        );
    }

    /**
     * Test 2 — Mismo total pero otro receptor: NO se adopta y queda un `AfipError`.
     *
     * Es el caso de Ferretotal: ARCA devuelve el comprobante de otra persona con el mismo importe.
     *
     * @group sales
     * @test
     */
    public function con_el_mismo_total_pero_otro_receptor_no_adopta()
    {
        $venta = $this->crear_venta_con_cliente('20111111112');

        $ticket = $this->ticket_de($venta, '19099');

        $helper = $this->consultar($ticket, ['ImpIVA' => 21.00, 'DocTipo' => 80, 'DocNro' => 27222222223]);

        $guardado = AfipTicket::find($ticket->id);

        $this->assertNull($guardado->cae, 'El comprobante es de otro receptor: no se puede escribir su CAE.');
        $this->assertNull($guardado->resultado);
        $this->assertTrue($helper->comprobante_recuperado_descartado);
        $this->assertEquals(
            1,
            AfipError::where('afip_ticket_id', $ticket->id)->count(),
            'Tiene que quedar un AfipError visible explicando por qué no se asoció.'
        );
    }

    /**
     * Test 3 — Número y CAE ya tomados por otra venta viva: NO se adopta.
     *
     * Mismo receptor y mismo total (las dos ventas son idénticas para ARCA): lo único que las
     * distingue es que la otra ya tiene el comprobante.
     *
     * @group sales
     * @test
     */
    public function con_el_numero_ya_tomado_por_otra_venta_viva_no_adopta()
    {
        $venta_a = $this->crear_venta_con_cliente('20111111112');
        $venta_b = $this->crear_venta_con_cliente('20111111112');

        $ticket_a = $this->ticket_de($venta_a, '19099');
        $ticket_a->update(['cae' => self::CAE, 'resultado' => 'A']);

        $ticket_b = $this->ticket_de($venta_b, '19099');

        $helper = $this->consultar($ticket_b, ['DocTipo' => 80, 'DocNro' => 20111111112]);

        $this->assertNull(AfipTicket::find($ticket_b->id)->cae, 'El comprobante ya es de la otra venta.');
        $this->assertTrue($helper->comprobante_recuperado_descartado);
        $this->assertEquals(1, AfipError::where('afip_ticket_id', $ticket_b->id)->count());
        $this->assertEquals(self::CAE, AfipTicket::find($ticket_a->id)->cae, 'La primera venta conserva su comprobante.');
    }

    /**
     * Test 4 — Un ticket soft-deleted con ese número NO bloquea la adopción.
     *
     * El modelo usa SoftDeletes: un comprobante borrado no está "vivo" y no puede ser el motivo para
     * dejar afuera a la venta que sí lo tiene en ARCA.
     *
     * @group sales
     * @test
     */
    public function un_ticket_borrado_con_el_mismo_numero_no_bloquea()
    {
        $venta_a = $this->crear_venta_con_cliente('20111111112');
        $venta_b = $this->crear_venta_con_cliente('20111111112');

        $ticket_a = $this->ticket_de($venta_a, '19099');
        $ticket_a->update(['cae' => self::CAE, 'resultado' => 'A']);
        $ticket_a->delete();

        $ticket_b = $this->ticket_de($venta_b, '19099');

        $helper = $this->consultar($ticket_b, ['DocTipo' => 80, 'DocNro' => 20111111112]);

        $this->assertEquals(self::CAE, AfipTicket::find($ticket_b->id)->cae, 'El ticket borrado no cuenta como dueño del número.');
        $this->assertFalse($helper->comprobante_recuperado_descartado);
    }

    /**
     * Test 5 — GUARD: una respuesta sin `DocNro` se comporta como antes.
     *
     * Las respuestas transcriptas viejas (y el test 29) no traen el documento del receptor: sin ese
     * dato no hay con qué validar, y el comprobante se adopta como siempre.
     *
     * @group sales
     * @test
     */
    public function una_respuesta_sin_docnro_se_comporta_como_antes()
    {
        $venta = $this->crear_venta_con_cliente('20111111112');

        $ticket = $this->ticket_de($venta, '19099');

        $helper = $this->consultar($ticket, ['ImpIVA' => 21.00]);

        $this->assertEquals(self::CAE, AfipTicket::find($ticket->id)->cae);
        $this->assertFalse($helper->comprobante_recuperado_descartado);
    }

    /**
     * Test 6 — Cliente sin documento (NR, tipo 99) y ARCA devolviendo DocNro 0: SE ADOPTA.
     *
     * Es el consumidor final anónimo, el caso más común. "NR" no es un número: tiene que
     * normalizarse a 0 para compararse con lo que ARCA tiene asentado.
     *
     * @group sales
     * @test
     */
    public function un_cliente_sin_documento_con_docnro_cero_de_arca_se_adopta()
    {
        $venta = $this->crear_venta_con_cliente(null);

        $ticket = $this->ticket_de($venta, '19099');

        $helper = $this->consultar($ticket, ['ImpIVA' => 21.00, 'DocTipo' => 99, 'DocNro' => 0]);

        $this->assertEquals(self::CAE, AfipTicket::find($ticket->id)->cae);
        $this->assertFalse($helper->comprobante_recuperado_descartado);
    }

    /**
     * Test 7 — CUIT con guiones en el cliente contra DocNro numérico de ARCA (entero y float): SE ADOPTA.
     *
     * El sistema guarda el CUIT como texto, a veces con guiones; ARCA lo devuelve como número, y por
     * SOAP puede llegar como float. La comparación es por dígitos.
     *
     * @group sales
     * @test
     */
    public function un_cuit_con_guiones_se_compara_por_digitos_con_el_docnro_de_arca()
    {
        foreach ([20111111112, 20111111112.0] as $doc_nro) {

            $venta = $this->crear_venta_con_cliente('20-11111111-2');

            $ticket = $this->ticket_de($venta, '19099');

            $helper = $this->consultar($ticket, ['DocTipo' => 80, 'DocNro' => $doc_nro]);

            $this->assertEquals(self::CAE, AfipTicket::find($ticket->id)->cae, 'DocNro '.var_export($doc_nro, true));
            $this->assertFalse($helper->comprobante_recuperado_descartado);

            AfipTicket::where('id', $ticket->id)->forceDelete();
        }
    }

    /**
     * Test 8 — El mismo CAE ya tomado por otra venta viva, con OTRO número: NO se adopta.
     *
     * Un CAE identifica un solo comprobante; que aparezca en dos tickets es siempre un duplicado.
     *
     * @group sales
     * @test
     */
    public function con_el_mismo_cae_tomado_por_otra_venta_y_otro_numero_no_adopta()
    {
        $venta_a = $this->crear_venta_con_cliente('20111111112');
        $venta_b = $this->crear_venta_con_cliente('20111111112');

        $ticket_a = $this->ticket_de($venta_a, '19098');
        $ticket_a->update(['cae' => self::CAE, 'resultado' => 'A']);

        $ticket_b = $this->ticket_de($venta_b, '19099');

        $helper = $this->consultar($ticket_b, ['DocTipo' => 80, 'DocNro' => 20111111112]);

        $this->assertNull(AfipTicket::find($ticket_b->id)->cae);
        $this->assertTrue($helper->comprobante_recuperado_descartado);
        $this->assertEquals(1, AfipError::where('afip_ticket_id', $ticket_b->id)->count());
    }

    /**
     * Test 9 — Si se consultó después de un error de red al emitir, la consulta NO recalcula la
     * ganancia: eso lo hace `MakeAfipTicket` cuando vuelve `procesar()`, y no debe hacerse dos veces.
     *
     * @group sales
     * @test
     */
    public function despues_de_un_error_de_red_la_consulta_no_recalcula_la_ganancia()
    {
        $venta = $this->crear_venta_con_cliente('20111111112');

        Sale::where('id', $venta->id)->update(['ganancia' => 999]);

        $ticket = $this->ticket_de($venta, '19099');

        $this->consultar($ticket, ['ImpIVA' => 21.00], true);

        $this->assertEquals(self::CAE, AfipTicket::find($ticket->id)->cae, 'Se adopta igual.');
        $this->assertEqualsWithDelta(
            999.00,
            (float) Sale::find($venta->id)->ganancia,
            self::DELTA,
            'En el camino de error de red la ganancia la recalcula MakeAfipTicket, no consultar_comprobante().'
        );
    }

    /**
     * Test 10 — Venta consolidadora: al adoptar por consulta se recalculan también las consolidadas.
     *
     * Mismo criterio que `MakeAfipTicket::recalcular_ganancia_facturada()`.
     *
     * @group sales
     * @test
     */
    public function al_adoptar_una_consolidadora_se_recalculan_las_ventas_consolidadas()
    {
        $consolidadora = $this->crear_venta_con_cliente('20111111112');
        $consolidada   = $this->crear_venta_con_cliente('20111111112');

        Sale::where('id', $consolidadora->id)->update(['is_consolidacion_facturacion' => 1, 'ganancia' => 999]);
        Sale::where('id', $consolidada->id)->update(['consolidacion_facturacion_id' => $consolidadora->id, 'ganancia' => 999]);

        $ticket = $this->ticket_de($consolidadora, '19099');

        $this->consultar($ticket, ['ImpIVA' => 21.00]);

        $this->assertNotEquals(
            999.00,
            (float) Sale::find($consolidada->id)->ganancia,
            'La ganancia de la venta consolidada tenía que recalcularse junto con la de la consolidadora.'
        );
        $this->assertNotEquals(999.00, (float) Sale::find($consolidadora->id)->ganancia);
    }

    // =========================================================================================
    // Helpers del archivo
    // =========================================================================================

    /**
     * Corre `consultar_comprobante()` con la respuesta de ARCA transcripta.
     *
     * @param  \App\Models\AfipTicket $afip_ticket
     * @param  array $result_get Campos de `ResultGet` que este escenario quiere fijar.
     * @param  bool  $despues_de_error_de_red Simula que la consulta la dispara `solicitar_cae()`.
     * @return \App\Http\Controllers\Helpers\Afip\AfipWsfeHelper
     */
    protected function consultar($afip_ticket, $result_get, $despues_de_error_de_red = false)
    {
        $data = array_merge([
            'PtoVta'          => self::PUNTO_VENTA,
            'CbteTipo'        => self::CBTE_FACTURA_A,
            'ImpTotal'        => 121.00,
            'MonId'           => 'PES',
            'Resultado'       => 'A',
            'CodAutorizacion' => self::CAE,
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
        $helper->wsfe = new DobleDeWsfeQueContestaAjeno($respuesta);
        $helper->consulto_despues_de_error_en_emision = $despues_de_error_de_red;

        $helper->consultar_comprobante();

        return $helper;
    }

    /**
     * Ticket sin CAE con el número de comprobante ya resuelto, como queda tras un error de red.
     *
     * @param  \App\Models\Sale $venta
     * @param  string $numero
     * @return \App\Models\AfipTicket
     */
    protected function ticket_de($venta, $numero)
    {
        return AfipTicket::create([
            'sale_id'           => $venta->id,
            'cbte_tipo'         => self::CBTE_FACTURA_A,
            'cbte_numero'       => $numero,
            'punto_venta'       => self::PUNTO_VENTA,
            'cuit_negocio'      => '20000000000',
            'importe_iva'       => null,
            'imp_total_enviado' => 121.00,
        ]);
    }

    /**
     * @return int
     */
    protected function user_id()
    {
        return (int) User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->firstOrFail()->id;
    }

    /**
     * Crea una venta de $121 (costo 100) por el endpoint real y la asocia a un cliente con ese CUIT.
     *
     * @param  string|null $cuit CUIT del receptor (null: cliente sin documento).
     * @return \App\Models\Sale
     */
    protected function crear_venta_con_cliente($cuit)
    {
        $articulo = Article::create([
            'name'       => 'zz Test comprobante ajeno '.uniqid(),
            'user_id'    => $this->user_id(),
            'costo_real' => 100,
        ]);

        $this->articulos_creados[] = $articulo->id;

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
            'sub_total'                        => 121.00,
            'total'                            => 121.00,
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
                'price_vender' => 121.00,
                'amount'       => 1,
                'costo_real'   => 100,
            ]],
        ]);

        if ($response->getStatusCode() !== 201) {
            $this->fail('POST api/sale devolvió '.$response->getStatusCode().'. Cuerpo completo: '.$response->getContent());
        }

        $cliente = Client::create([
            'name'    => 'zz Cliente comprobante ajeno '.uniqid(),
            'user_id' => $this->user_id(),
            'cuit'    => $cuit,
        ]);

        Sale::where('id', $response->json('model.id'))->update(['client_id' => $cliente->id]);

        return Sale::find($response->json('model.id'));
    }
}

/**
 * Doble del webservice de ARCA: devuelve la respuesta que le pasaron y no abre ninguna conexión.
 *
 * Nombre propio (y no el del test 29) para que los dos archivos puedan cargarse en la misma corrida.
 */
class DobleDeWsfeQueContestaAjeno
{
    /** @var array */
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
