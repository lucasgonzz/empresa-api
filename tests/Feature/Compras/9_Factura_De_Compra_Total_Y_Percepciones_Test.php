<?php

namespace Tests\Feature\Compras;

use App\Models\CurrentAcount;
use App\Models\Iva;
use App\Models\ProviderOrder;
use App\Models\ProviderOrderAfipTicket;
use App\Models\ProviderOrderAfipTicketIva;
use App\Models\ProviderOrderExtraCost;
use Database\Seeders\testing\TestingFerreteriaSeeder;

/**
 * Misión `compras-factura-manual-alicuotas` (17/9/2026) — la factura de compra manual: su total, sus
 * percepciones y el recálculo de la compra que cuelga de ella.
 *
 * Lo que cubre, en el orden del plan:
 *
 *   A3  El `total` de la factura lo calcula el SERVIDOR (Σ neto + iva_importe de sus alícuotas, más
 *       las percepciones) y el que mande el cliente en el request se ignora. Hasta esta misión se
 *       guardaba `$request->total` a ciegas.
 *   B   Con `total_from_provider_order_afip_tickets` prendido, guardar una factura con percepción
 *       actualiza `provider_orders.total` Y `current_acounts.debe` del proveedor — el hueco que
 *       cerraba el circuito: antes ningún método del controller de facturas instanciaba
 *       `NewProviderOrderHelper`, así que la percepción sumaba en la factura y no en la deuda.
 *   A4  Con la compra en modo de facturación automático, tocar una alícuota devuelve 422: esa
 *       factura la calcula el sistema desde los artículos.
 *   🔴  La guarda de retroactividad: el recálculo corre SOLO sobre la compra de la factura que se
 *       guardó. Ninguna compra vieja se toca sola.
 *
 * Todas las compras van con `update_prices = 0` y `update_stock = 0`: son tests de la factura y de
 * la plata de la compra, no del motor de costeo, y así no hay que restaurar ningún artículo del
 * fixture compartido. Proveedor Rosario (sin bonificaciones de catálogo) por el mismo motivo de
 * simplicidad que las otras suites de persistencia.
 *
 * 🔴 `total_with_iva` va en 0 en las compras que suman por facturas, a propósito. Con las dos
 * banderas prendidas a la vez (`total_from_provider_order_afip_tickets` + `total_with_iva`),
 * `NewProviderOrderHelper::set_totales()` vuelve a sumar el IVA que el total de la factura ya trae
 * adentro — es un doble conteo preexistente, está levantado como hallazgo fuera de alcance del plan
 * y no se arregla en esta misión. Dejarlo prendido acá haría que estos tests midieran ese bug en
 * vez de lo que vienen a medir.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 *
 * @group compras
 */
class Factura_De_Compra_Total_Y_Percepciones_Test extends ComprasTestCase
{
    /** Tolerancia de las comparaciones de plata (las columnas son decimal(x,2)). */
    const DELTA = 0.01;

    /**
     * Ids de las compras creadas por el test en curso, para borrarlas (con sus facturas, alícuotas
     * y movimientos de cuenta corriente) en el `finally` de cada uno.
     *
     * @var array<int,int>
     */
    protected $compras_creadas = [];

    /* ------------------------------------------------------------------ */
    /* Helpers del escenario                                               */
    /* ------------------------------------------------------------------ */

    /**
     * Payload de compra del escenario de esta suite: proveedor Rosario (sin bonificaciones de
     * catálogo), sin tocar precios ni stock, y un solo artículo de alícuota 21%.
     *
     * @param  array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    protected function payload_del_escenario($overrides = [])
    {
        $defaults = [
            'provider_id'             => $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO)->id,
            'modo_facturacion'        => 'manual',
            'update_prices'           => 0,
            'update_stock'            => 0,
            'total_with_iva'          => 0,
            'generate_current_acount' => 0,
            'articles'                => [
                $this->item('Marco para cama', 1000, 1),
            ],
        ];

        return $this->payload_compra(array_merge($defaults, $overrides));
    }

    /**
     * Crea una compra por el endpoint real y la registra para la limpieza.
     *
     * @param  array<string,mixed> $overrides Overrides del payload (ver `payload_compra`).
     * @return \App\Models\ProviderOrder
     */
    protected function crear_compra($overrides = [])
    {
        $response = $this->postJson('api/provider-order', $this->payload_del_escenario($overrides));

        $response->assertStatus(201);

        $compra_id = $response->json('model.id');

        $this->compras_creadas[] = $compra_id;

        return ProviderOrder::find($compra_id);
    }

    /**
     * Vuelve a guardar una compra ya creada con el mismo payload, por el endpoint real.
     *
     * El controller de compras no es un PATCH parcial: hay que remandar el payload entero (mismo
     * criterio que `Persistencia_Iva_Y_Costos_Extra_Test::actualizar_precios_incluyen_iva()`).
     *
     * @param  \App\Models\ProviderOrder  $compra
     * @param  array<string,mixed>        $overrides
     * @return \Illuminate\Testing\TestResponse
     */
    protected function volver_a_guardar_la_compra($compra, $overrides = [])
    {
        return $this->putJson('api/provider-order/'.$compra->id, $this->payload_del_escenario($overrides));
    }

    /**
     * La factura principal que el modo automático le calculó a la compra (nunca un comprobante
     * "aparte" de un costo extra, que tiene su propio ciclo de vida).
     *
     * @param  \App\Models\ProviderOrder  $compra
     * @return \App\Models\ProviderOrderAfipTicket|null
     */
    protected function factura_automatica_de($compra)
    {
        return ProviderOrderAfipTicket::where('provider_order_id', $compra->id)
                                        ->whereNull('provider_order_extra_cost_id')
                                        ->first();
    }

    /**
     * Crea una factura colgada de una compra, por el endpoint real.
     *
     * `total` viaja en el payload A PROPÓSITO en todos los llamados: es justo el número que el
     * servidor tiene que ignorar.
     *
     * @param  int                  $provider_order_id
     * @param  array<string,mixed>  $overrides  Campos del request de la factura.
     * @return \App\Models\ProviderOrderAfipTicket
     */
    protected function crear_factura($provider_order_id, $overrides = [])
    {
        $defaults = [
            'model_id'        => $provider_order_id,
            'code'            => '0001-00000001',
            'issued_at'       => '2026-09-17',
            'percepcion_iibb' => null,
            'percepcion_iva'  => null,
            'total'           => 999999,
        ];

        $response = $this->postJson('api/provider-order-afip-ticket', array_merge($defaults, $overrides));

        $response->assertStatus(201);

        return ProviderOrderAfipTicket::find($response->json('model.id'));
    }

    /**
     * Agrega una alícuota a una factura, por el endpoint real.
     *
     * @param  int    $provider_order_afip_ticket_id
     * @param  float  $neto
     * @param  float  $iva_importe
     * @param  string $porcentaje  Porcentaje de la alícuota, como lo siembra `IvaSeeder` ('21', '10.5'...).
     * @return \Illuminate\Testing\TestResponse
     */
    protected function agregar_alicuota($provider_order_afip_ticket_id, $neto, $iva_importe, $porcentaje = '21')
    {
        return $this->postJson('api/provider-order-afip-ticket-iva', [
            'model_id'    => $provider_order_afip_ticket_id,
            'iva_id'      => $this->iva($porcentaje)->id,
            'neto'        => $neto,
            'iva_importe' => $iva_importe,
        ]);
    }

    /**
     * Alícuota del catálogo por su porcentaje (nunca por id hardcodeado).
     *
     * @param  string $porcentaje
     * @return \App\Models\Iva
     */
    protected function iva($porcentaje)
    {
        $iva = Iva::where('percentage', $porcentaje)->first();

        $this->assertNotNull($iva, 'La base de testing tiene que tener sembrada la alícuota "'.$porcentaje.'".');

        return $iva;
    }

    /**
     * El movimiento de cuenta corriente que la compra le generó al proveedor.
     *
     * @param  int $provider_order_id
     * @return \App\Models\CurrentAcount|null
     */
    protected function current_acount_de($provider_order_id)
    {
        return CurrentAcount::where('provider_order_id', $provider_order_id)->first();
    }

    /**
     * Borra todo lo que crearon los tests de esta clase: facturas, alícuotas, movimientos de cuenta
     * corriente y compras.
     *
     * No alcanza con confiar en `DatabaseTransactions` (ver el docblock de `ComprasTestCase` sobre
     * la deuda de MyISAM): esta suite escribe saldos de cuenta corriente, que son acumulativos, y
     * un resto de un test contamina al siguiente sin que nada lo denuncie.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->compras_creadas as $compra_id) {

            $tickets = ProviderOrderAfipTicket::where('provider_order_id', $compra_id)->get();

            foreach ($tickets as $ticket) {
                ProviderOrderAfipTicketIva::where('provider_order_afip_ticket_id', $ticket->id)->delete();
                $ticket->delete();
            }

            CurrentAcount::where('provider_order_id', $compra_id)->delete();

            ProviderOrder::where('id', $compra_id)->delete();
        }

        $this->compras_creadas = [];

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /* A3 — El total lo calcula el servidor                                */
    /* ------------------------------------------------------------------ */

    /**
     * Test 1 — El `total` que manda el cliente se ignora: la factura queda con la suma de sus
     * alícuotas, no con el número del request.
     *
     * Es el corazón de A3. Antes de esta misión el controller guardaba `$request->total` tal cual,
     * así que un cliente viejo o un POST directo podían dejar una factura con cualquier total.
     *
     * @group compras
     * @test
     */
    public function el_total_de_la_factura_lo_calcula_el_servidor_e_ignora_el_del_request()
    {
        $compra = $this->crear_compra();

        // El alta manda total = 999999 y la factura todavía no tiene ninguna alícuota: el total
        // calculado es 0, y es el que tiene que quedar.
        $factura = $this->crear_factura($compra->id);

        $this->assertEqualsWithDelta(
            0,
            (float) $factura->total,
            self::DELTA,
            'Una factura sin alícuotas ni percepciones tiene que quedar en 0, no en el 999999 que mandó el request.'
        );

        // Se le agrega una alícuota de 21%: 100000 de neto + 21000 de IVA.
        $this->agregar_alicuota($factura->id, 100000, 21000)->assertStatus(201);

        $factura->refresh();

        $this->assertEqualsWithDelta(
            121000,
            (float) $factura->total,
            self::DELTA,
            'El total tiene que ser la suma de neto + iva_importe de las alícuotas (100000 + 21000).'
        );

        $this->assertEqualsWithDelta(
            21000,
            (float) $factura->total_iva,
            self::DELTA,
            'total_iva sigue siendo la suma de los iva_importe.'
        );

        // Y editar la factura tampoco deja entrar el total del request: se remanda 999999.
        $this->putJson('api/provider-order-afip-ticket/'.$factura->id, [
            'code'            => '0001-00000002',
            'issued_at'       => '2026-09-17',
            'percepcion_iibb' => null,
            'percepcion_iva'  => null,
            'total'           => 999999,
            'model_id'        => $compra->id,
        ])->assertStatus(200);

        $factura->refresh();

        $this->assertEqualsWithDelta(
            121000,
            (float) $factura->total,
            self::DELTA,
            'El update tampoco puede tomar el total del request: se recalcula igual que el store.'
        );
    }

    /**
     * Test 2 — Las percepciones suman al total de la factura, y NO al total de IVA.
     *
     * Es el audio del cliente (17/9/2026, 11:58): *"después no te lo suma al total de la factura,
     * que debería estar sumado"*. La contracara es igual de importante: una percepción de IVA no es
     * crédito fiscal de IVA compras, así que `total_iva` no la puede incluir o le cambiaría la
     * posición fiscal del período.
     *
     * @group compras
     * @test
     */
    public function el_total_de_la_factura_incluye_las_percepciones_y_el_total_iva_no()
    {
        $compra = $this->crear_compra();

        $factura = $this->crear_factura($compra->id, [
            'percepcion_iibb' => 2500,
            'percepcion_iva'  => 800,
        ]);

        $this->agregar_alicuota($factura->id, 100000, 21000)->assertStatus(201);

        $factura->refresh();

        $this->assertEqualsWithDelta(
            124300,
            (float) $factura->total,
            self::DELTA,
            'Total = 100000 de neto + 21000 de IVA + 2500 de percepción de IIBB + 800 de percepción de IVA.'
        );

        $this->assertEqualsWithDelta(
            21000,
            (float) $factura->total_iva,
            self::DELTA,
            'Las percepciones NO son crédito fiscal de IVA: total_iva sigue siendo solo la suma de iva_importe.'
        );
    }

    /**
     * Test 3 — Dos alícuotas (21% y 10,5%) más las dos percepciones: el total las suma todas, y
     * borrar una alícuota lo vuelve a bajar.
     *
     * @group compras
     * @test
     */
    public function el_total_suma_todas_las_alicuotas_y_se_rehace_al_borrar_una()
    {
        $compra = $this->crear_compra();

        $factura = $this->crear_factura($compra->id, ['percepcion_iibb' => 1000]);

        $this->agregar_alicuota($factura->id, 100000, 21000, '21')->assertStatus(201);

        $respuesta_105 = $this->agregar_alicuota($factura->id, 50000, 5250, '10.5');

        $respuesta_105->assertStatus(201);

        $factura->refresh();

        $this->assertEqualsWithDelta(
            177250,
            (float) $factura->total,
            self::DELTA,
            'Total = (100000 + 21000) + (50000 + 5250) + 1000 de percepción.'
        );

        $this->assertEqualsWithDelta(
            26250,
            (float) $factura->total_iva,
            self::DELTA,
            'total_iva = 21000 + 5250.'
        );

        $this->deleteJson('api/provider-order-afip-ticket-iva/'.$respuesta_105->json('model.id'))
                ->assertStatus(200);

        $factura->refresh();

        $this->assertEqualsWithDelta(
            122000,
            (float) $factura->total,
            self::DELTA,
            'Borrar la alícuota de 10,5% tiene que bajar el total a (100000 + 21000) + 1000.'
        );
    }

    /* ------------------------------------------------------------------ */
    /* B — La percepción llega al total de la compra y a la deuda           */
    /* ------------------------------------------------------------------ */

    /**
     * Test 4 — 🔴 El que cierra el circuito. Con `total_from_provider_order_afip_tickets` prendido,
     * guardar una factura con percepción deja al día `provider_orders.total` Y `current_acounts.debe`
     * del proveedor.
     *
     * Antes de esta misión no pasaba: el controller de facturas no instanciaba
     * `NewProviderOrderHelper` en ningún método y no hay Observer para el modelo, así que los dos
     * números se quedaban con el valor viejo hasta que alguien volviera a guardar la compra entera
     * a mano.
     *
     * @group compras
     * @test
     */
    public function guardar_una_factura_con_percepcion_actualiza_el_total_de_la_compra_y_la_deuda()
    {
        $compra = $this->crear_compra([
            'total_from_provider_order_afip_tickets' => 1,
            'generate_current_acount'                => 1,
        ]);

        $factura = $this->crear_factura($compra->id);

        $this->agregar_alicuota($factura->id, 100000, 21000)->assertStatus(201);

        $compra->refresh();

        $this->assertEqualsWithDelta(
            121000,
            (float) $compra->total,
            self::DELTA,
            'El total de la compra sale del total de sus facturas.'
        );

        $current_acount = $this->current_acount_de($compra->id);

        $this->assertNotNull($current_acount, 'La compra con generate_current_acount tiene que tener su movimiento de cuenta corriente.');

        $this->assertEqualsWithDelta(
            121000,
            (float) $current_acount->debe,
            self::DELTA,
            'La deuda con el proveedor arranca igual al total de la compra.'
        );

        // Y ahora la percepción, que es el caso del audio: se carga editando la factura.
        $this->putJson('api/provider-order-afip-ticket/'.$factura->id, [
            'code'            => '0001-00000001',
            'issued_at'       => '2026-09-17',
            'percepcion_iibb' => 2500,
            'percepcion_iva'  => 800,
            'total'           => 999999,
            'model_id'        => $compra->id,
        ])->assertStatus(200);

        $factura->refresh();
        $compra->refresh();

        $this->assertEqualsWithDelta(
            124300,
            (float) $factura->total,
            self::DELTA,
            'La factura suma las dos percepciones a su total.'
        );

        $this->assertEqualsWithDelta(
            124300,
            (float) $compra->total,
            self::DELTA,
            '🔴 El total de la compra tiene que subir con la percepción, sin volver a guardar la compra.'
        );

        $current_acount = $this->current_acount_de($compra->id);

        $this->assertEqualsWithDelta(
            124300,
            (float) $current_acount->debe,
            self::DELTA,
            '🔴 Y la deuda con el proveedor también: la percepción es plata que se le paga a él.'
        );
    }

    /**
     * Test 5 — Borrar la factura deja la compra (y la deuda) sin ese total.
     *
     * @group compras
     * @test
     */
    public function borrar_la_factura_baja_el_total_de_la_compra_y_la_deuda()
    {
        $compra = $this->crear_compra([
            'total_from_provider_order_afip_tickets' => 1,
            'generate_current_acount'                => 1,
        ]);

        $factura = $this->crear_factura($compra->id, ['percepcion_iibb' => 2500]);

        $this->agregar_alicuota($factura->id, 100000, 21000)->assertStatus(201);

        $compra->refresh();

        $this->assertEqualsWithDelta(123500, (float) $compra->total, self::DELTA, 'Punto de partida: 121000 + 2500.');

        $this->deleteJson('api/provider-order-afip-ticket/'.$factura->id)->assertStatus(200);

        $compra->refresh();

        $this->assertEqualsWithDelta(
            0,
            (float) $compra->total,
            self::DELTA,
            'Sin facturas, una compra que totaliza por facturas queda en 0.'
        );

        $this->assertEqualsWithDelta(
            0,
            (float) $this->current_acount_de($compra->id)->debe,
            self::DELTA,
            'Y la deuda con el proveedor acompaña.'
        );
    }

    /* ------------------------------------------------------------------ */
    /* 🔴 La guarda de retroactividad                                      */
    /* ------------------------------------------------------------------ */

    /**
     * Test 6 — 🔴 Guardar la factura de UNA compra no recalcula ninguna otra.
     *
     * Decisión explícita de Lucas (17/9/2026): el cambio vale de acá en adelante, y ninguna compra
     * vieja cambia de total ni mueve un saldo sola. El test lo prueba por el camino más directo:
     * se le escribe a mano un total inconsistente a la compra vieja (simulando una cargada antes de
     * esta misión, con la percepción afuera del total), se guarda la factura de OTRA compra, y la
     * vieja tiene que seguir exactamente igual.
     *
     * Si algún día alguien agrega un barrido —un comando, un observer global, una migración que
     * recorra compras—, este test se pone rojo.
     *
     * @group compras
     * @test
     */
    public function una_compra_vieja_no_se_recalcula_sola()
    {
        $compra_vieja = $this->crear_compra([
            'total_from_provider_order_afip_tickets' => 1,
            'generate_current_acount'                => 1,
        ]);

        $factura_vieja = $this->crear_factura($compra_vieja->id, ['percepcion_iibb' => 2500]);

        $this->agregar_alicuota($factura_vieja->id, 100000, 21000)->assertStatus(201);

        // Se la deja como estaría una compra cargada ANTES de esta misión: el total de la compra sin
        // la percepción adentro (121000 en vez de 123500), y la deuda igual de vieja.
        $compra_vieja->total = 121000;
        $compra_vieja->save();

        $current_acount_vieja = $this->current_acount_de($compra_vieja->id);
        $current_acount_vieja->debe = 121000;
        $current_acount_vieja->save();

        // Ahora se trabaja sobre OTRA compra: se crea, se le cuelga una factura y se la edita.
        $compra_nueva = $this->crear_compra([
            'total_from_provider_order_afip_tickets' => 1,
            'generate_current_acount'                => 1,
        ]);

        $factura_nueva = $this->crear_factura($compra_nueva->id);

        $this->agregar_alicuota($factura_nueva->id, 10000, 2100)->assertStatus(201);

        $this->putJson('api/provider-order-afip-ticket/'.$factura_nueva->id, [
            'code'            => '0001-00000009',
            'issued_at'       => '2026-09-17',
            'percepcion_iibb' => 500,
            'percepcion_iva'  => null,
            'total'           => 999999,
            'model_id'        => $compra_nueva->id,
        ])->assertStatus(200);

        // La compra nueva sí quedó al día...
        $compra_nueva->refresh();

        $this->assertEqualsWithDelta(
            12600,
            (float) $compra_nueva->total,
            self::DELTA,
            'La compra de la factura que se guardó tiene que estar al día: 10000 + 2100 + 500.'
        );

        // ...y la vieja no se tocó.
        $compra_vieja->refresh();

        $this->assertEqualsWithDelta(
            121000,
            (float) $compra_vieja->total,
            self::DELTA,
            '🔴 La compra vieja NO se recalcula sola: sigue con el total que tenía, percepción afuera.'
        );

        $this->assertEqualsWithDelta(
            121000,
            (float) $this->current_acount_de($compra_vieja->id)->debe,
            self::DELTA,
            '🔴 Y su deuda con el proveedor tampoco se mueve.'
        );

        // Recién cuando se guarda SU factura, la compra vieja se pone al día. Es la contracara del
        // mismo criterio: el recálculo existe, pero corre solo sobre lo que se guarda.
        $this->putJson('api/provider-order-afip-ticket/'.$factura_vieja->id, [
            'code'            => '0001-00000001',
            'issued_at'       => '2026-09-17',
            'percepcion_iibb' => 2500,
            'percepcion_iva'  => null,
            'total'           => 999999,
            'model_id'        => $compra_vieja->id,
        ])->assertStatus(200);

        $compra_vieja->refresh();

        $this->assertEqualsWithDelta(
            123500,
            (float) $compra_vieja->total,
            self::DELTA,
            'Guardando SU factura, la compra vieja sí se pone al día (121000 + 2500).'
        );
    }

    /* ------------------------------------------------------------------ */
    /* A4 — Modo de facturación automático                                 */
    /* ------------------------------------------------------------------ */

    /**
     * Test 7 — Con la compra en modo automático, agregar una alícuota devuelve 422 y no escribe
     * nada.
     *
     * En ese modo la factura entera la calcula `ModoFacturacionHelper` desde los artículos: lo que
     * se escribiera a mano se perdería en el próximo guardado de la compra, en silencio. El mensaje
     * tiene que decir cómo salir (pasar la compra a Manual).
     *
     * @group compras
     * @test
     */
    public function en_modo_automatico_agregar_una_alicuota_devuelve_422()
    {
        $compra = $this->crear_compra(['modo_facturacion' => 'automatico']);

        $factura = $this->factura_automatica_de($compra);

        $this->assertNotNull($factura, 'El modo automático tiene que haber creado la factura de la compra.');

        $alicuotas_antes = ProviderOrderAfipTicketIva::where('provider_order_afip_ticket_id', $factura->id)->count();

        $respuesta = $this->agregar_alicuota($factura->id, 100000, 21000);

        $respuesta->assertStatus(422);

        $this->assertStringContainsString(
            'Manual',
            (string) $respuesta->json('message'),
            'El mensaje tiene que decir cómo salir: pasar el modo de facturación de la compra a Manual.'
        );

        $this->assertEquals(
            $alicuotas_antes,
            ProviderOrderAfipTicketIva::where('provider_order_afip_ticket_id', $factura->id)->count(),
            'El 422 no puede haber creado nada.'
        );
    }

    /**
     * Test 8 — Con la compra en modo automático, editar o borrar una alícuota también devuelve 422,
     * y la fila queda intacta.
     *
     * @group compras
     * @test
     */
    public function en_modo_automatico_editar_o_borrar_una_alicuota_devuelve_422()
    {
        $compra = $this->crear_compra(['modo_facturacion' => 'automatico']);

        $factura = $this->factura_automatica_de($compra);

        $this->assertNotNull($factura, 'El modo automático tiene que haber creado la factura de la compra.');

        $alicuota = ProviderOrderAfipTicketIva::where('provider_order_afip_ticket_id', $factura->id)->first();

        $this->assertNotNull(
            $alicuota,
            'El modo automático tiene que haber calculado el desglose de IVA de la compra (artículo con alícuota 21%).'
        );

        $neto_original = (float) $alicuota->neto;

        $this->putJson('api/provider-order-afip-ticket-iva/'.$alicuota->id, [
            'provider_order_afip_ticket_id' => $factura->id,
            'iva_id'                        => $alicuota->iva_id,
            'neto'                          => 999999,
            'iva_importe'                   => 999999,
        ])->assertStatus(422);

        $alicuota->refresh();

        $this->assertEqualsWithDelta(
            $neto_original,
            (float) $alicuota->neto,
            self::DELTA,
            'El 422 del update no puede haber escrito nada.'
        );

        $this->deleteJson('api/provider-order-afip-ticket-iva/'.$alicuota->id)->assertStatus(422);

        $this->assertNotNull(
            ProviderOrderAfipTicketIva::find($alicuota->id),
            'El 422 del destroy no puede haber borrado la fila.'
        );
    }

    /**
     * Test 9 — El bloqueo es por el modo de la compra, no por la pantalla: con la MISMA factura, la
     * compra en manual deja tocar las alícuotas.
     *
     * Es la prueba de que la salida que dice el mensaje existe y funciona — si el 422 saliera
     * siempre, el test 7 pasaría igual y el circuito quedaría trabado.
     *
     * @group compras
     * @test
     */
    public function en_modo_manual_la_misma_factura_deja_tocar_las_alicuotas()
    {
        $compra = $this->crear_compra(['modo_facturacion' => 'manual']);

        $factura = $this->crear_factura($compra->id);

        $this->agregar_alicuota($factura->id, 100000, 21000)->assertStatus(201);

        $this->assertEquals(
            1,
            ProviderOrderAfipTicketIva::where('provider_order_afip_ticket_id', $factura->id)->count(),
            'En modo manual la alícuota se tiene que poder cargar.'
        );
    }

    /**
     * Test 10 — 🔴 En modo automático la percepción sobrevive a volver a guardar la compra.
     *
     * Es el caso que más se usa y el que se rompía en silencio. En modo automático la percepción
     * ES editable (junto con la fecha y el número es lo único que queda a mano), pero
     * `ModoFacturacionHelper` rehace la factura desde los artículos en cada guardado de la compra
     * y escribía `total = neto + IVA` sin las percepciones. O sea: la cargabas, el total subía
     * bien, y al guardar la compra por cualquier otro motivo volvía a bajar — llevándose puestos
     * también `provider_orders.total` y `current_acounts.debe`, sin un solo error ni aviso.
     *
     * @group compras
     * @test
     */
    public function en_modo_automatico_la_percepcion_sobrevive_a_volver_a_guardar_la_compra()
    {
        $overrides = [
            'modo_facturacion'                       => 'automatico',
            'total_from_provider_order_afip_tickets' => 1,
            'generate_current_acount'                => 1,
        ];

        $compra = $this->crear_compra($overrides);

        $factura = $this->factura_automatica_de($compra);

        $this->assertNotNull($factura, 'El modo automático tiene que haber creado la factura de la compra.');

        // Un artículo de 1000 al 21%: neto 1000 + IVA 210.
        $this->assertEqualsWithDelta(
            1210,
            (float) $factura->total,
            self::DELTA,
            'Punto de partida: la factura automática vale neto + IVA del único artículo.'
        );

        // El usuario carga la percepción que le vino en el papel.
        $this->putJson('api/provider-order-afip-ticket/'.$factura->id, [
            'code'            => 'A 0001-00000123',
            'issued_at'       => '2026-09-17',
            'percepcion_iibb' => 500,
            'percepcion_iva'  => null,
            'total'           => 999999,
            'model_id'        => $compra->id,
        ])->assertStatus(200);

        $factura->refresh();
        $compra->refresh();

        $this->assertEqualsWithDelta(1710, (float) $factura->total, self::DELTA, 'La factura suma la percepción: 1210 + 500.');
        $this->assertEqualsWithDelta(1710, (float) $compra->total, self::DELTA, 'Y la compra también.');

        // 🔴 Y acá estaba el agujero: se vuelve a guardar la compra, por cualquier motivo.
        $this->volver_a_guardar_la_compra($compra, $overrides)->assertStatus(200);

        $factura->refresh();
        $compra->refresh();

        $this->assertEqualsWithDelta(
            1710,
            (float) $factura->total,
            self::DELTA,
            '🔴 Volver a guardar la compra no puede borrar la percepción del total de la factura.'
        );

        $this->assertEqualsWithDelta(
            210,
            (float) $factura->total_iva,
            self::DELTA,
            'El IVA de la factura no incluye la percepción: sigue siendo solo el de la alícuota.'
        );

        $this->assertEqualsWithDelta(
            1710,
            (float) $compra->total,
            self::DELTA,
            '🔴 Ni el total de la compra.'
        );

        $this->assertEqualsWithDelta(
            1710,
            (float) $this->current_acount_de($compra->id)->debe,
            self::DELTA,
            '🔴 Ni la deuda con el proveedor.'
        );
    }

    /**
     * Test 11 — Lo mismo para una cuenta Monotributista, que no discrimina IVA.
     *
     * Ahí la factura automática no tiene desglose de alícuotas y `total_iva` queda en `null` a
     * propósito (para un MT el IVA "no aplica", que es distinto de "el IVA da 0"). Eso no cambia:
     * lo único que se agrega es que la percepción sume al total, que es plata que igual le paga al
     * proveedor.
     *
     * @group compras
     * @test
     */
    public function en_modo_automatico_monotributista_la_percepcion_tambien_sobrevive()
    {
        $this->set_condicion_iva('MT');

        try {

            $overrides = [
                'modo_facturacion'                       => 'automatico',
                'total_from_provider_order_afip_tickets' => 1,
                'generate_current_acount'                => 1,
            ];

            $compra = $this->crear_compra($overrides);

            $factura = $this->factura_automatica_de($compra);

            $this->assertNotNull($factura, 'El modo automático tiene que haber creado la factura de la compra.');

            // Un MT no le saca IVA a nada: la factura vale lo que costaron las líneas.
            $this->assertEqualsWithDelta(
                1000,
                (float) $factura->total,
                self::DELTA,
                'Punto de partida: en MT la factura automática vale el total de las líneas, sin IVA por encima.'
            );

            $this->putJson('api/provider-order-afip-ticket/'.$factura->id, [
                'code'            => 'C 0001-00000123',
                'issued_at'       => '2026-09-17',
                'percepcion_iibb' => 500,
                'percepcion_iva'  => null,
                'total'           => 999999,
                'model_id'        => $compra->id,
            ])->assertStatus(200);

            /*
             * 🔴 El assert que faltaba, y es justo donde se rompía. Una factura de MT no tiene
             * filas de desglose de IVA (un MT no discrimina), así que Σ sobre cero filas daba 0 y
             * el comprobante quedaba valiendo los 500 de la percepción en vez de 1500: mil pesos
             * de deuda con el proveedor perdonados, sin error y sin log. El total de un
             * comprobante sin desglose no es derivable — lo único que se le puede mover es la
             * percepción.
             */
            $factura->refresh();

            $this->assertEqualsWithDelta(
                1500,
                (float) $factura->total,
                self::DELTA,
                '🔴 Sin desglose de IVA el total no se deriva: se conserva el base (1000) y se le suma la percepción (500).'
            );

            $this->assertEqualsWithDelta(
                1500,
                (float) $compra->refresh()->total,
                self::DELTA,
                'Y la compra sigue al total de su factura, no a 500.'
            );

            $this->volver_a_guardar_la_compra($compra, $overrides)->assertStatus(200);

            $factura->refresh();
            $compra->refresh();

            $this->assertEqualsWithDelta(
                1500,
                (float) $factura->total,
                self::DELTA,
                '🔴 En MT la percepción también tiene que sobrevivir al re-guardado: 1000 + 500.'
            );

            $this->assertNull(
                $factura->total_iva,
                'Lo que NO cambia: para un MT el IVA no aplica, y total_iva queda en null (no en 0).'
            );

            $this->assertEqualsWithDelta(1500, (float) $compra->total, self::DELTA, 'Y el total de la compra acompaña.');

        } finally {
            // El fixture es compartido: la condición vuelve a RRII pase lo que pase.
            $this->set_condicion_iva('RRII');
        }
    }

    /* ------------------------------------------------------------------ */
    /* C5 — Las retenciones se fueron de la factura de compra              */
    /* ------------------------------------------------------------------ */

    /**
     * Test 12 — El controller ya no guarda retenciones en la factura de compra: aunque el request
     * las mande (un cliente viejo), las columnas quedan sin tocar.
     *
     * Las columnas siguen existiendo en la tabla a propósito (hay datos cargados que se migran
     * aparte); lo que se corta es la entrada nueva por esta puerta. Quien retiene es tu cliente
     * cuando te paga, no el proveedor cuando te factura.
     *
     * @group compras
     * @test
     */
    public function la_factura_de_compra_ya_no_guarda_retenciones()
    {
        $compra = $this->crear_compra();

        $factura = $this->crear_factura($compra->id, [
            'retencion_iibb'      => 1111,
            'retencion_iva'       => 2222,
            'retencion_ganancias' => 3333,
        ]);

        $this->assertNull($factura->retencion_iibb, 'La factura de compra no guarda retención de IIBB.');
        $this->assertNull($factura->retencion_iva, 'La factura de compra no guarda retención de IVA.');
        $this->assertNull($factura->retencion_ganancias, 'La factura de compra no guarda retención de Ganancias.');

        $this->putJson('api/provider-order-afip-ticket/'.$factura->id, [
            'code'                => '0001-00000001',
            'issued_at'           => '2026-09-17',
            'percepcion_iibb'     => null,
            'percepcion_iva'      => null,
            'total'               => 999999,
            'model_id'            => $compra->id,
            'retencion_iibb'      => 1111,
            'retencion_iva'       => 2222,
            'retencion_ganancias' => 3333,
        ])->assertStatus(200);

        $factura->refresh();

        $this->assertNull($factura->retencion_iibb, 'El update tampoco guarda retención de IIBB.');
        $this->assertNull($factura->retencion_iva, 'El update tampoco guarda retención de IVA.');
        $this->assertNull($factura->retencion_ganancias, 'El update tampoco guarda retención de Ganancias.');
    }

    /* ------------------------------------------------------------------ */
    /* 🔴 Comprobantes SIN desglose de IVA: el total no es derivable        */
    /* ------------------------------------------------------------------ */

    /**
     * Test 13 — 🔴 El comprobante aparte de un costo extra sin alícuota conserva su total cuando se
     * lo edita por el endpoint.
     *
     * Es el mismo agujero que el del Monotributista pero en una cuenta **Responsable Inscripto**:
     * un costo extra facturado aparte sin `iva_id` cargado no se puede desglosar, así que
     * `ModoFacturacionHelper` le guarda el bruto directo como total y le borra las filas de IVA. Si
     * después el usuario le carga el número del comprobante o una percepción, Σ sobre cero filas
     * daría 0 y el flete de $3.000 desaparecería de la deuda con el proveedor.
     *
     * @group compras
     * @test
     */
    public function un_comprobante_sin_alicuotas_conserva_su_total_al_editarlo()
    {
        $compra = $this->crear_compra([
            'modo_facturacion'                       => 'automatico',
            'total_from_provider_order_afip_tickets' => 1,
            'generate_current_acount'                => 1,
        ]);

        // Un flete de $3.000 facturado por otro emisor y SIN alícuota cargada: no hay con qué
        // desglosarlo, así que su comprobante aparte nace con el bruto como total y sin filas.
        $costo_extra = ProviderOrderExtraCost::create([
            'provider_order_id'   => $compra->id,
            'description'         => 'Flete tercerizado',
            'value'               => 3000,
            'tipo'                => ProviderOrderExtraCost::TIPO_TRANSPORTE,
            'facturado'           => true,
            'en_factura_compra'   => false,
            'iva_id'              => null,
            'emisor_razon_social' => 'Transportes del Litoral',
        ]);

        $this->volver_a_guardar_la_compra($compra, [
            'modo_facturacion'                       => 'automatico',
            'total_from_provider_order_afip_tickets' => 1,
            'generate_current_acount'                => 1,
        ])->assertStatus(200);

        $ticket_aparte = ProviderOrderAfipTicket::where('provider_order_extra_cost_id', $costo_extra->id)->first();

        $this->assertNotNull($ticket_aparte, 'Tiene que existir el comprobante aparte del costo extra.');

        $this->assertCount(
            0,
            ProviderOrderAfipTicketIva::where('provider_order_afip_ticket_id', $ticket_aparte->id)->get(),
            'Sin iva_id cargado el comprobante aparte no tiene desglose; si lo tuviera, el test no estaría midiendo esto.'
        );

        $this->assertEqualsWithDelta(3000, (float) $ticket_aparte->total, self::DELTA, 'Punto de partida: el bruto del flete.');

        // El usuario le completa el número del comprobante y le carga la percepción del papel.
        $this->putJson('api/provider-order-afip-ticket/'.$ticket_aparte->id, [
            'code'            => 'B 0005-00000077',
            'issued_at'       => '2026-09-17',
            'percepcion_iibb' => 200,
            'percepcion_iva'  => null,
            'total'           => 999999,
            'model_id'        => $compra->id,
        ])->assertStatus(200);

        $ticket_aparte->refresh();

        $this->assertEqualsWithDelta(
            3200,
            (float) $ticket_aparte->total,
            self::DELTA,
            '🔴 El total del flete se conserva y solo se le suma la percepción: 3000 + 200, nunca 0 + 200.'
        );
    }

    /**
     * Test 14 — Alícuota 0 / Exento / No Gravado: el neto entra entero al total y el IVA es 0.
     *
     * Es la Factura C cargada a mano, y el caso que más fácil se rompe con una cuenta que multiplica
     * por una alícuota que no existe. Acá el desglose SÍ existe (hay una fila, con `iva_importe` en
     * 0), así que el total se deriva normal — a diferencia del comprobante sin ninguna fila.
     *
     * @group compras
     * @test
     */
    public function una_alicuota_exenta_suma_su_neto_entero_y_no_aporta_iva()
    {
        $compra = $this->crear_compra([
            'total_from_provider_order_afip_tickets' => 1,
            'generate_current_acount'                => 1,
        ]);

        $factura = $this->crear_factura($compra->id, ['percepcion_iibb' => 300]);

        // Exento: el importe de IVA es 0 y el neto es todo lo que vale el renglón.
        $this->agregar_alicuota($factura->id, 50000, 0, 'Exento')->assertStatus(201);

        // Y una de No Gravado, para que convivan las dos sin IVA.
        $this->agregar_alicuota($factura->id, 20000, 0, 'No Gravado')->assertStatus(201);

        $factura->refresh();

        $this->assertEqualsWithDelta(
            70300,
            (float) $factura->total,
            self::DELTA,
            'Total = 50000 de Exento + 20000 de No Gravado + 300 de percepción. El neto entra entero.'
        );

        $this->assertEqualsWithDelta(
            0,
            (float) $factura->total_iva,
            self::DELTA,
            'Una alícuota exenta no aporta crédito fiscal: total_iva da 0 (que NO es lo mismo que null).'
        );

        $this->assertEqualsWithDelta(
            70300,
            (float) $compra->refresh()->total,
            self::DELTA,
            'Y el total de la compra sale de ahí.'
        );
    }

    /**
     * Test 15 — Mover una factura de una compra a otra recalcula LAS DOS.
     *
     * El formulario permite reasignar la factura, y recalcular solo la compra que la recibe dejaba
     * a la que la pierde con un total que incluye un comprobante que ya no le cuelga — el mismo
     * agujero que el `destroy()`, por la puerta de al lado.
     *
     * @group compras
     * @test
     */
    public function mover_una_factura_de_compra_recalcula_las_dos()
    {
        $overrides = [
            'total_from_provider_order_afip_tickets' => 1,
            'generate_current_acount'                => 1,
        ];

        $compra_origen  = $this->crear_compra($overrides);
        $compra_destino = $this->crear_compra($overrides);

        $factura = $this->crear_factura($compra_origen->id);

        $this->agregar_alicuota($factura->id, 100000, 21000)->assertStatus(201);

        $this->assertEqualsWithDelta(121000, (float) $compra_origen->refresh()->total, self::DELTA, 'Punto de partida: la factura cuelga del origen.');
        $this->assertEqualsWithDelta(0, (float) $compra_destino->refresh()->total, self::DELTA, 'Y el destino todavía no tiene ninguna.');

        // Se la mueve al destino.
        $this->putJson('api/provider-order-afip-ticket/'.$factura->id, [
            'code'            => '0001-00000001',
            'issued_at'       => '2026-09-17',
            'percepcion_iibb' => null,
            'percepcion_iva'  => null,
            'total'           => 999999,
            'model_id'        => $compra_destino->id,
        ])->assertStatus(200);

        $this->assertEqualsWithDelta(
            121000,
            (float) $compra_destino->refresh()->total,
            self::DELTA,
            'La compra que recibe la factura queda con su total.'
        );

        $this->assertEqualsWithDelta(
            0,
            (float) $compra_origen->refresh()->total,
            self::DELTA,
            '🔴 Y la que la pierde también se recalcula: si no, se queda con el total de una factura que ya no le cuelga.'
        );

        $this->assertEqualsWithDelta(
            0,
            (float) $this->current_acount_de($compra_origen->id)->debe,
            self::DELTA,
            'Con su deuda, que es lo que se le sigue reclamando al proveedor.'
        );
    }

    /* ------------------------------------------------------------------ */
    /* La bandera apagada                                                  */
    /* ------------------------------------------------------------------ */

    /**
     * Test 16 — Con `total_from_provider_order_afip_tickets` APAGADO, la factura no mueve el total
     * de la compra.
     *
     * Es la contracara de todo lo anterior y vale la pena anclarla: el recálculo corre igual (la
     * factura se guarda, sus totales se calculan), pero `set_totales()` arma el total de la compra
     * desde los artículos, así que una percepción cargada en la factura no puede cambiarlo. Si
     * alguna vez alguien hace que el total de la factura entre por otro lado, este test lo denuncia.
     *
     * @group compras
     * @test
     */
    public function con_la_bandera_apagada_la_factura_no_mueve_el_total_de_la_compra()
    {
        $compra = $this->crear_compra([
            'total_from_provider_order_afip_tickets' => 0,
            'generate_current_acount'                => 1,
        ]);

        // Un artículo de 1000, sin IVA por encima (`total_with_iva = 0`): la compra vale 1000.
        $this->assertEqualsWithDelta(
            1000,
            (float) $compra->total,
            self::DELTA,
            'Con la bandera apagada el total de la compra sale de sus artículos.'
        );

        $factura = $this->crear_factura($compra->id, ['percepcion_iibb' => 2500]);

        $this->agregar_alicuota($factura->id, 100000, 21000)->assertStatus(201);

        $factura->refresh();
        $compra->refresh();

        $this->assertEqualsWithDelta(
            123500,
            (float) $factura->total,
            self::DELTA,
            'La factura calcula su propio total igual, esté la bandera como esté.'
        );

        $this->assertEqualsWithDelta(
            1000,
            (float) $compra->total,
            self::DELTA,
            'Pero con la bandera apagada el total de la compra no se entera: sigue saliendo de los artículos.'
        );

        $this->assertEqualsWithDelta(
            1000,
            (float) $this->current_acount_de($compra->id)->debe,
            self::DELTA,
            'Y la deuda con el proveedor tampoco se mueve.'
        );
    }
}
