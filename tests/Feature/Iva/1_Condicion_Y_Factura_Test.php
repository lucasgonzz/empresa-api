<?php

namespace Tests\Feature\Iva;

use App\Models\Iva;
use App\Models\ProviderOrder;
use App\Models\ProviderOrderAfipTicket;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\Feature\Compras\ComprasTestCase;

/**
 * Tests de condicion fiscal (configuracion) y composicion de la factura automatica segun esa
 * condicion, para la cuenta de prueba (Grupo 264, correctivo del 244/01).
 *
 * Cubre lo que NO cubre `--group compras`: persistencia de `condicion_iva_precios` via el
 * endpoint de configuracion, comportamiento de cuenta preexistente, y la composicion de la
 * factura automatica (MT solo total, RRII con neto+desglose, multi-alicuota, Exento). El costeo
 * en si (cuanto queda articles.cost/costo_real) ya esta cubierto por `Costeo_RRII_Test`/
 * `Costeo_MT_Test`: no se re-testea aca.
 *
 * Igual que el resto de la suite de compras, corre sobre una base MyISAM sin rollback real
 * (`DatabaseTransactions` es un no-op en ese motor): todo test que mute `condicion_iva_precios`
 * o el `iva_id` de un articulo del fixture lo restaura en un `finally`.
 *
 * @group iva-condicion
 */
class Condicion_Y_Factura_Test extends ComprasTestCase
{
    /**
     * Delta de tolerancia para comparaciones de plata (nunca assertSame sobre floats).
     */
    const DELTA = 0.01;

    /**
     * Arma el payload completo del perfil del usuario de prueba (todos los campos que lee
     * `UserController::update()`), con overrides puntuales.
     *
     * `UserController::update()` no es un PATCH parcial: casi todos sus campos se asignan
     * incondicionalmente desde el request (`$model->campo = $request->campo`, sin `has()`), asi
     * que un payload que solo trajera `condicion_iva_precios` pisaria con `null` decenas de otras
     * columnas del usuario de prueba — y esa escritura queda permanente (motor MyISAM, sin
     * rollback real). Por eso el payload arranca del estado actual completo del usuario
     * (`toArray()`) y solo pisa lo que el test necesita, igual que un guardado real del formulario
     * de propiedades del SPA.
     *
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    protected function full_profile_payload($overrides = [])
    {
        $user = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        return array_merge($user->toArray(), $overrides);
    }

    /**
     * Test 1 — guardar `condicion_iva_precios = 'MT'` por el endpoint de configuracion: responde
     * 2xx, la columna queda en 'MT', y una lectura posterior via el mismo endpoint (guardado
     * idempotente, sin cambios) sigue devolviendo 'MT' en el modelo de la respuesta.
     *
     * @group iva-condicion
     * @test
     */
    public function guardar_condicion_mt_por_el_endpoint_persiste_y_se_puede_releer()
    {
        $user = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
        $valor_original = $user->condicion_iva_precios;

        try {

            $response = $this->putJson('api/user/'.$user->id, $this->full_profile_payload([
                'condicion_iva_precios' => 'MT',
            ]));

            $response->assertStatus(200);
            $this->assertEquals(
                'MT',
                $response->json('model.condicion_iva_precios'),
                'el modelo devuelto por el PUT ya tiene que reflejar la condicion nueva'
            );

            $user->refresh();
            $this->assertEquals('MT', $user->condicion_iva_precios, 'la columna en base tiene que quedar en MT');

            // "Volver a leer el endpoint de configuracion": un segundo guardado idempotente (mismo
            // payload, sin cambios) tiene que seguir devolviendo 'MT' en el modelo de la respuesta.
            $response_releida = $this->putJson('api/user/'.$user->id, $this->full_profile_payload([
                'condicion_iva_precios' => 'MT',
            ]));

            $response_releida->assertStatus(200);
            $this->assertEquals(
                'MT',
                $response_releida->json('model.condicion_iva_precios'),
                'releer (guardar de nuevo sin cambios) tiene que seguir devolviendo MT'
            );

        } finally {

            $this->putJson('api/user/'.$user->id, $this->full_profile_payload([
                'condicion_iva_precios' => $valor_original,
            ]));
        }
    }

    /**
     * Test 2 — lo mismo que el Test 1, con 'RRII'.
     *
     * @group iva-condicion
     * @test
     */
    public function guardar_condicion_rrii_por_el_endpoint_persiste_y_se_puede_releer()
    {
        $user = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
        $valor_original = $user->condicion_iva_precios;

        try {

            $response = $this->putJson('api/user/'.$user->id, $this->full_profile_payload([
                'condicion_iva_precios' => 'RRII',
            ]));

            $response->assertStatus(200);
            $this->assertEquals('RRII', $response->json('model.condicion_iva_precios'));

            $user->refresh();
            $this->assertEquals('RRII', $user->condicion_iva_precios, 'la columna en base tiene que quedar en RRII');

            $response_releida = $this->putJson('api/user/'.$user->id, $this->full_profile_payload([
                'condicion_iva_precios' => 'RRII',
            ]));

            $response_releida->assertStatus(200);
            $this->assertEquals('RRII', $response_releida->json('model.condicion_iva_precios'));

        } finally {

            $this->putJson('api/user/'.$user->id, $this->full_profile_payload([
                'condicion_iva_precios' => $valor_original,
            ]));
        }
    }

    /**
     * Test 3 — cuenta preexistente = comportamiento RRII, verificado sobre el costeo real de una
     * compra (`POST api/provider-order`), no sobre el valor de la columna.
     *
     * La columna es `NOT NULL` con default `'RRII'` y la conexion corre en modo estricto: `null`
     * no se puede persistir, pero la cadena vacia (`''`) si, y es el estado que representa a la
     * cuenta preexistente (nunca migrada, sin condicion cargada). Se escribe `''` directo sobre el
     * modelo (no via el endpoint: no es un valor que el endpoint acepte, `UserController::update()`
     * lo rechazaria con 422 igual que 'XX' — ver Test 4) para simular ese estado real de una cuenta
     * vieja.
     *
     * @group iva-condicion
     * @test
     */
    public function cuenta_preexistente_sin_condicion_se_comporta_como_rrii_en_el_costeo_real()
    {
        $user = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
        $valor_original = $user->condicion_iva_precios;

        try {

            $user->condicion_iva_precios = '';
            $user->save();

            $this->quitar_bonificaciones_de_buenos_aires();

            // Misma compra que ya prueba el comportamiento RRII en Costeo_MT_Test (Pinza 21%,
            // costo tipeado 1210 CON IVA incluido, cantidad 10): si la cuenta preexistente (`''`)
            // se comportara como RRII, el costo base neto tiene que quedar en 1000 (back-out del
            // 21% sobre el tipeado), no en 1210 (que seria el comportamiento de un MT, que trata
            // el tipeado siempre como bruto sin importar el flag).
            $payload = $this->payload_compra([
                'precios_incluyen_iva' => 1,
                'articles' => [
                    $this->item('Pinza', 1210, 10),
                ],
            ]);

            $response = $this->postJson('api/provider-order', $payload);

            $response->assertStatus(201);

            $pinza = $this->articulo('Pinza');

            $this->assertEqualsWithDelta(
                1000,
                (float) $pinza->cost,
                self::DELTA,
                'cuenta preexistente (condicion_iva_precios = ""): el costeo real tiene que comportarse como RRII (costo neto 1000, back-out del IVA sobre el tipeado con IVA incluido), no como MT'
            );

            $provider_order = ProviderOrder::find($response->json('model.id'));

            $this->assertEqualsWithDelta(
                12100,
                (float) $provider_order->total,
                self::DELTA,
                'total de la compra en cuenta preexistente (mismo comportamiento que RRII)'
            );

        } finally {

            // Restauracion via update() directo (no dependiente de dirty-tracking de Eloquent),
            // mismo criterio que el resto de la clase.
            User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->update([
                'condicion_iva_precios' => $valor_original,
            ]);
        }
    }

    /**
     * Test 4 — valor invalido ('XX'): el endpoint rechaza con 422 y no pisa el valor anterior.
     *
     * No hace falta `finally`: `UserController::update()` devuelve el 422 ANTES de llamar a
     * `$model->save()` (ver el `if` de validacion), asi que no hay ninguna escritura que revertir.
     *
     * @group iva-condicion
     * @test
     */
    public function valor_invalido_de_condicion_es_rechazado_con_422_y_no_pisa_el_valor_anterior()
    {
        $user = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
        $valor_original = $user->condicion_iva_precios;

        $response = $this->putJson('api/user/'.$user->id, $this->full_profile_payload([
            'condicion_iva_precios' => 'XX',
        ]));

        $response->assertStatus(422);

        $user->refresh();
        $this->assertEquals(
            $valor_original,
            $user->condicion_iva_precios,
            'un valor invalido no tiene que pisar la condicion previa'
        );
    }

    /**
     * Test 5 — cuenta MT: la factura automatica expone solo el total. `ModoFacturacionHelper::
     * calcular_ticket_monotributista()` deja `total_iva` en NULL (no en 0) y no crea ninguna fila
     * de desglose de IVA (`provider_order_afip_ticket_ivas`) — leido directo del codigo, no hay
     * clave "neto" en el ticket de un MT (el modelo `ProviderOrderAfipTicket` no tiene esa
     * columna: el neto solo existe por alicuota, en las filas de desglose que un MT justamente no
     * genera).
     *
     * @group iva-condicion
     * @test
     */
    public function cuenta_mt_la_factura_automatica_expone_solo_el_total()
    {
        $user = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
        $valor_original = $user->condicion_iva_precios;

        try {

            $this->set_condicion_iva('MT');
            $this->quitar_bonificaciones_de_buenos_aires();

            // Pinza (21%), costo tipeado 500, cantidad 4. Un MT ignora precios_incluyen_iva (test 1
            // del 264, y test 1 de Costeo_MT_Test): el total de la factura es directo cost * cantidad,
            // sin descuentos ni IVA.
            $payload = $this->payload_compra([
                'precios_incluyen_iva' => 0,
                'articles' => [
                    $this->item('Pinza', 500, 4),
                ],
            ]);

            $response = $this->postJson('api/provider-order', $payload);

            $response->assertStatus(201);

            $ticket = ProviderOrderAfipTicket::where('provider_order_id', $response->json('model.id'))->first();

            $this->assertNotNull($ticket, 'se tiene que haber generado la factura automatica del MT');

            // Compra de 4 unidades a 500 (MT: el tipeado se toma tal cual, sin IVA ni descuentos):
            //   total = 4 * 500 = 2000.00
            $this->assertEqualsWithDelta(2000.00, (float) $ticket->total, self::DELTA, 'total de la factura automatica de la cuenta MT');

            $this->assertNull($ticket->total_iva, 'un MT no discrimina IVA en la factura: total_iva tiene que quedar en NULL, no en 0');

            $ticket->load('provider_order_afip_ticket_ivas');

            $this->assertCount(
                0,
                $ticket->provider_order_afip_ticket_ivas,
                'la factura de un MT no tiene que traer ninguna fila de desglose de IVA'
            );

        } finally {

            // Restauracion manual OBLIGATORIA: `set_condicion_iva()` escribe sobre OTRA instancia
            // de User (la que resuelve por email adentro del helper), asi que este `$user` local
            // nunca queda "dirty" respecto de `$valor_original` y un simple `$user->save()` no
            // emitiria ningun UPDATE. Se restaura con un update() directo, que no depende de
            // dirty-tracking, para que el restore sea real sin importar el motor de la tabla.
            User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->update([
                'condicion_iva_precios' => $valor_original,
            ]);
        }
    }

    /**
     * Test 6 — cuenta RRII: la factura automatica trae neto, tabla de IVA por alicuota y total,
     * con la identidad neto + IVA = total (asercion SECUNDARIA de coherencia interna: el helper la
     * cumple por construccion, no es lo que prueba este test — ver Correccion 2 del prompt).
     *
     * @group iva-condicion
     * @test
     */
    public function cuenta_rrii_la_factura_automatica_trae_neto_desglose_de_iva_y_total()
    {
        $user = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
        $valor_original = $user->condicion_iva_precios;

        try {

            $this->set_condicion_iva('RRII');
            $this->quitar_bonificaciones_de_buenos_aires();

            // Pinza (21%), costo tipeado 850, cantidad 4:
            //   neto  = 4 * 850             = 3400.00
            //   iva   = 3400 * 0.21         =  714.00
            //   total                       = 4114.00
            $payload = $this->payload_compra([
                'precios_incluyen_iva' => 0,
                'articles' => [
                    $this->item('Pinza', 850, 4),
                ],
            ]);

            $response = $this->postJson('api/provider-order', $payload);

            $response->assertStatus(201);

            $ticket = ProviderOrderAfipTicket::where('provider_order_id', $response->json('model.id'))->first();

            $this->assertNotNull($ticket, 'se tiene que haber generado la factura automatica de la cuenta RRII');

            $ticket->load('provider_order_afip_ticket_ivas');

            $this->assertCount(1, $ticket->provider_order_afip_ticket_ivas, 'una sola alicuota en juego: una sola fila de desglose');

            $fila = $ticket->provider_order_afip_ticket_ivas->first();

            $this->assertEqualsWithDelta(3400.00, (float) $fila->neto, self::DELTA, 'neto de la fila de IVA 21% (literal calculado a mano, no derivado de la respuesta)');
            $this->assertEqualsWithDelta(714.00, (float) $fila->iva_importe, self::DELTA, 'importe de IVA de la fila (21% de 3400)');
            $this->assertEqualsWithDelta(714.00, (float) $ticket->total_iva, self::DELTA, 'total_iva del ticket');
            $this->assertEqualsWithDelta(4114.00, (float) $ticket->total, self::DELTA, 'total del ticket (literal calculado a mano: neto + iva)');

        } finally {

            // Ver el comentario extenso en el finally del test 5: set_condicion_iva() escribe
            // sobre otra instancia de User, asi que un save() sobre este $user local no emitiria
            // UPDATE. Restore real via update() directo.
            User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->update([
                'condicion_iva_precios' => $valor_original,
            ]);
        }
    }

    /**
     * Test 7 — compra multi-alicuota (RRII): dos articulos con alicuotas distintas (21% y 10,5%)
     * generan dos filas de desglose, cada una con su propio neto/importe. Los importes se eligen a
     * proposito para que la fila de 10,5% no cierre exacto en el redondeo (99.855 -> 99.86), que es
     * justamente el punto de este test.
     *
     * @group iva-condicion
     * @test
     */
    public function multi_alicuota_en_la_misma_compra_rrii_con_redondeo_no_exacto()
    {
        $user = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
        $valor_original = $user->condicion_iva_precios;

        try {

            $this->set_condicion_iva('RRII');
            $this->quitar_bonificaciones_de_buenos_aires();

            // Pinza (21%): costo 333.34 x 3 -> neto 1000.02, iva = 1000.02 * 0.21 = 210.0042 -> 210.00.
            // Cuchilla (10,5%): costo 317.00 x 3 -> neto 951.00, iva = 951.00 * 0.105 = 99.855 -> 99.86
            //   (el redondeo de esta fila no cae exacto en el centavo: es el punto del test).
            $payload = $this->payload_compra([
                'precios_incluyen_iva' => 0,
                'articles' => [
                    $this->item('Pinza', 333.34, 3),
                    $this->item('Cuchilla', 317.00, 3),
                ],
            ]);

            $response = $this->postJson('api/provider-order', $payload);

            $response->assertStatus(201);

            $ticket = ProviderOrderAfipTicket::where('provider_order_id', $response->json('model.id'))->first();

            $this->assertNotNull($ticket, 'se tiene que haber generado la factura automatica');

            $ticket->load('provider_order_afip_ticket_ivas');

            $this->assertCount(2, $ticket->provider_order_afip_ticket_ivas, 'dos alicuotas distintas: dos filas de desglose');

            $iva_21   = Iva::where('percentage', '21')->first();
            $iva_10_5 = Iva::where('percentage', '10.5')->first();

            $fila_21   = $ticket->provider_order_afip_ticket_ivas->where('iva_id', $iva_21->id)->first();
            $fila_10_5 = $ticket->provider_order_afip_ticket_ivas->where('iva_id', $iva_10_5->id)->first();

            $this->assertNotNull($fila_21, 'tiene que existir la fila de desglose de la alicuota 21%');
            $this->assertNotNull($fila_10_5, 'tiene que existir la fila de desglose de la alicuota 10,5%');

            $this->assertEqualsWithDelta(1000.02, (float) $fila_21->neto, self::DELTA, 'neto de la fila 21% (333.34 x 3, literal)');
            $this->assertEqualsWithDelta(210.00, (float) $fila_21->iva_importe, self::DELTA, 'IVA de la fila 21% (1000.02 x 0.21 = 210.0042, redondeado a 210.00)');

            $this->assertEqualsWithDelta(951.00, (float) $fila_10_5->neto, self::DELTA, 'neto de la fila 10,5% (317.00 x 3, literal)');
            $this->assertEqualsWithDelta(99.86, (float) $fila_10_5->iva_importe, self::DELTA, 'IVA de la fila 10,5% (951.00 x 0.105 = 99.855, redondeado a 99.86: el punto del test)');

            $this->assertEqualsWithDelta(1951.02, (float) $fila_21->neto + (float) $fila_10_5->neto, self::DELTA, 'neto total (literal: 1000.02 + 951.00)');
            $this->assertEqualsWithDelta(309.86, (float) $ticket->total_iva, self::DELTA, 'IVA total del ticket (literal: 210.00 + 99.86)');
            $this->assertEqualsWithDelta(2260.88, (float) $ticket->total, self::DELTA, 'total del ticket (literal: 1951.02 + 309.86)');

        } finally {

            // Ver el comentario extenso en el finally del test 5: set_condicion_iva() escribe
            // sobre otra instancia de User, asi que un save() sobre este $user local no emitiria
            // UPDATE. Restore real via update() directo.
            User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->update([
                'condicion_iva_precios' => $valor_original,
            ]);
        }
    }

    /**
     * Test 8 — articulo con alicuota Exento en la compra: no rompe, no suma IVA, y el total sigue
     * cerrando. Leyendo el codigo (`ModoFacturacionHelper::get_ivas()`): no filtra por alicuota
     * cero, asi que Exento SI genera una fila de desglose (con `iva_importe = 0`), no queda
     * ausente — se assertea eso, lo que el codigo realmente hace, no una suposicion.
     *
     * @group iva-condicion
     * @test
     */
    public function articulo_exento_no_suma_iva_no_rompe_y_el_total_cierra()
    {
        $user = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
        $valor_original = $user->condicion_iva_precios;

        try {

            $this->set_condicion_iva('RRII');
            $this->quitar_bonificaciones_de_buenos_aires();

            // Cuchara (Exento), costo tipeado 80, cantidad 5:
            //   neto  = 5 * 80 = 400.00
            //   iva   = 0 (alicuota Exento -> 0)
            //   total = 400.00
            $payload = $this->payload_compra([
                'precios_incluyen_iva' => 0,
                'articles' => [
                    $this->item('Cuchara', 80, 5),
                ],
            ]);

            $response = $this->postJson('api/provider-order', $payload);

            $response->assertStatus(201);

            $ticket = ProviderOrderAfipTicket::where('provider_order_id', $response->json('model.id'))->first();

            $this->assertNotNull($ticket, 'se tiene que haber generado la factura automatica');

            $ticket->load('provider_order_afip_ticket_ivas');

            $iva_exento = Iva::where('percentage', 'Exento')->first();

            $fila_exento = $ticket->provider_order_afip_ticket_ivas->where('iva_id', $iva_exento->id)->first();

            $this->assertNotNull(
                $fila_exento,
                'leyendo el codigo (get_ivas() no filtra por alicuota 0), Exento SI genera una fila de desglose, con importe 0 — no queda ausente'
            );

            $this->assertEqualsWithDelta(400.00, (float) $fila_exento->neto, self::DELTA, 'neto de la fila Exento (literal: 80 x 5)');
            $this->assertEqualsWithDelta(0.00, (float) $fila_exento->iva_importe, self::DELTA, 'Exento no suma IVA: importe de la fila en 0');

            $this->assertEqualsWithDelta(0.00, (float) $ticket->total_iva, self::DELTA, 'IVA total del ticket en 0 (unico articulo es Exento)');
            $this->assertEqualsWithDelta(400.00, (float) $ticket->total, self::DELTA, 'total del ticket sigue cerrando (literal: 400.00 + 0)');

        } finally {

            // Ver el comentario extenso en el finally del test 5: set_condicion_iva() escribe
            // sobre otra instancia de User, asi que un save() sobre este $user local no emitiria
            // UPDATE. Restore real via update() directo.
            User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->update([
                'condicion_iva_precios' => $valor_original,
            ]);
        }
    }
}
