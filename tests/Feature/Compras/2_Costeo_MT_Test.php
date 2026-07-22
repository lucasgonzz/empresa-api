<?php

namespace Tests\Feature\Compras;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Models\Iva;
use App\Models\ProviderOrder;
use App\Models\ProviderOrderAfipTicket;
use Database\Seeders\testing\TestingFerreteriaSeeder;

/**
 * Tests de costeo Monotributista (MT) y casos borde de alicuota del modulo de compras
 * (Grupo 184, Prompt 615).
 *
 * Regla de negocio (Grupo 183): un MT ignora `precios_incluyen_iva` (el costo tipeado se trata
 * SIEMPRE como bruto), su costo real SIEMPRE incorpora el IVA (no lo recupera como credito
 * fiscal, sin importar `articles.aplicar_iva`), pero el TOTAL de la compra no suma IVA por
 * encima (ya esta "adentro" de lo que tipeo/pago). La factura automatica de un MT muestra solo
 * el total, sin desglose de IVA.
 *
 * IMPORTANTE (documentado en el prompt, no ocultar): la columna
 * `user_configurations.condicion_iva_precios` (Prompt 608) todavia NO existe en este entorno.
 * `ComprasTestCase::set_condicion_iva('MT')` hace `markTestSkipped()` en ese caso — todos los
 * tests de esta clase que necesitan forzar la condicion MT quedan SKIPPED hasta que el Prompt 608
 * aterrice esa columna. Es el comportamiento esperado, no un fallo.
 *
 * Los numeros esperados de estos tests son la especificacion (definida en el prompt 615), no una
 * sugerencia. Esta prohibido ajustar el valor esperado para que coincida con lo que devuelve el
 * sistema — si un test queda en rojo (y no es por la limitacion de la columna faltante), hay que
 * corregir el codigo de costeo, no el test.
 */
class Costeo_MT_Test extends ComprasTestCase
{
    /**
     * Delta de tolerancia para comparaciones de plata (nunca `assertSame` sobre floats).
     */
    const DELTA = 0.01;

    /**
     * Test 1 — un MT trata el costo tipeado como bruto AUNQUE `precios_incluyen_iva` venga en 0
     * (deliberadamente apagado): el flag de la compra se ignora por completo para un MT, que
     * siempre le saca el IVA al costo tipeado antes de guardarlo en `articles.cost`.
     *
     * @group compras
     * @test
     */
    public function mt_ignora_precios_incluyen_iva_y_trata_el_costo_como_bruto()
    {
        $this->set_condicion_iva('MT');
        $this->quitar_bonificaciones_de_buenos_aires();

        // Compra a Buenos Aires, Pinza (IVA 21%), costo tipeado 1210, cantidad 10.
        // `precios_incluyen_iva` en 0 a proposito: para un MT no deberia importar.
        $payload = $this->payload_compra([
            'precios_incluyen_iva' => 0,
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
            'costo base de Pinza en MT: se ignora precios_incluyen_iva=0, el costo tipeado (1210) se trata igual como bruto y se guarda neto (1000)'
        );

        $provider_order = ProviderOrder::find($response->json('model.id'));

        $this->assertEqualsWithDelta(
            12100,
            (float) $provider_order->total,
            self::DELTA,
            'total de la compra de un MT (1210 x 10, el costo tipeado ya trae el IVA adentro)'
        );
    }

    /**
     * Test 2 — el total de un MT no suma IVA por encima del total ya cargado. Es un test
     * redundante a proposito respecto del Test 1: es el bug mas caro (inflar el total de un
     * monotributista con un IVA que no recupera) y merece un test con nombre propio, con un
     * mensaje de falla explicito.
     *
     * @group compras
     * @test
     */
    public function el_total_de_un_mt_no_suma_iva_por_encima()
    {
        $this->set_condicion_iva('MT');
        $this->quitar_bonificaciones_de_buenos_aires();

        // Misma compra del Test 1: Pinza (21%), costo tipeado 1210, cantidad 10.
        $payload = $this->payload_compra([
            'precios_incluyen_iva' => 0,
            'articles' => [
                $this->item('Pinza', 1210, 10),
            ],
        ]);

        $response = $this->postJson('api/provider-order', $payload);

        $response->assertStatus(201);

        $provider_order = ProviderOrder::find($response->json('model.id'));

        $total = (float) $provider_order->total;

        $this->assertTrue(
            abs($total - 14641) > self::DELTA,
            'BUG: el total de un monotributista no lleva IVA agregado por encima (1210 x 10 = 12100, no 14641 = 12100 + 21%)'
        );

        $this->assertEqualsWithDelta(
            12100,
            $total,
            self::DELTA,
            'total de la compra de un MT (sin IVA adicional por encima)'
        );
    }

    /**
     * Test 3 — la factura automatica (`modo_facturacion = automatico`) de un MT muestra SOLO el
     * total: sin filas de desglose de IVA (`provider_order_afip_ticket_ivas` vacio) y con
     * `total_iva` en NULL (no en 0: para un MT el IVA "no aplica", ver
     * `ModoFacturacionHelper::calcular_ticket_monotributista`).
     *
     * @group compras
     * @test
     */
    public function la_factura_automatica_de_un_mt_muestra_solo_el_total()
    {
        $this->set_condicion_iva('MT');
        $this->quitar_bonificaciones_de_buenos_aires();

        $payload = $this->payload_compra([
            'modo_facturacion'      => 'automatico',
            'precios_incluyen_iva'  => 0,
            'articles' => [
                $this->item('Pinza', 1210, 10),
            ],
        ]);

        $response = $this->postJson('api/provider-order', $payload);

        $response->assertStatus(201);

        $provider_order_id = $response->json('model.id');

        $ticket = ProviderOrderAfipTicket::where('provider_order_id', $provider_order_id)->first();

        $this->assertNotNull($ticket, 'se tiene que haber generado la factura automatica del MT');

        $this->assertEqualsWithDelta(
            12100,
            (float) $ticket->total,
            self::DELTA,
            'total de la factura automatica de un MT'
        );

        $this->assertNull(
            $ticket->total_iva,
            'un MT no discrimina IVA en la factura: total_iva tiene que quedar en NULL, no en 0'
        );

        $ticket->load('provider_order_afip_ticket_ivas');

        $this->assertCount(
            0,
            $ticket->provider_order_afip_ticket_ivas,
            'la factura de un MT no tiene que traer ninguna fila de desglose de IVA'
        );
    }

    /**
     * Test 4 — el costo real de un MT (el que calcula el pipeline de precios, via
     * `ArticleHelper::setFinalPrice`) incorpora el IVA AUNQUE el articulo tenga `aplicar_iva` en
     * 0: un monotributista no recupera credito fiscal, asi que ese IVA es costo siempre. El
     * costo base (`articles.cost`) sigue en el neto (1000), separado del costo real.
     *
     * @group compras
     * @test
     */
    public function el_costo_real_de_un_mt_incorpora_el_iva_aunque_aplicar_iva_este_apagado()
    {
        $this->set_condicion_iva('MT');
        $this->quitar_bonificaciones_de_buenos_aires();

        // Se apaga aplicar_iva del articulo a proposito: para un MT no deberia importar.
        $pinza = $this->articulo('Pinza');
        $pinza->aplicar_iva = 0;
        $pinza->save();

        $payload = $this->payload_compra([
            'precios_incluyen_iva' => 0,
            'articles' => [
                $this->item('Pinza', 1210, 10),
            ],
        ]);

        $response = $this->postJson('api/provider-order', $payload);

        $response->assertStatus(201);

        $pinza->refresh();

        $this->assertEqualsWithDelta(
            1000,
            (float) $pinza->cost,
            self::DELTA,
            'costo base de Pinza (siempre neto, sin importar aplicar_iva)'
        );

        $this->assertEqualsWithDelta(
            1210,
            (float) $pinza->costo_real,
            self::DELTA,
            'costo real de Pinza en MT: incorpora el IVA (1000 + 21%) aunque aplicar_iva este en 0'
        );
    }

    /**
     * Test 5 — misma compra corrida primero en RRII y despues en MT: documenta en un solo lugar
     * que cambia realmente entre las dos condiciones.
     *
     * Invariante que NO se rompe nunca: `articles.cost` (costo base) y el total de la compra son
     * iguales en las dos condiciones. Lo que difiere es el costo real: el del MT es mayor, porque
     * incorpora el IVA que un MT no recupera.
     *
     * @group compras
     * @test
     */
    public function misma_compra_en_rrii_y_en_mt_donde_coinciden_y_donde_no()
    {
        $this->set_condicion_iva('RRII');
        $this->quitar_bonificaciones_de_buenos_aires();

        // Corrida 1: RRII, costo tipeado 1210 (con IVA incluido), cantidad 10.
        $payload_rrii = $this->payload_compra([
            'precios_incluyen_iva' => 1,
            'articles' => [
                $this->item('Pinza', 1210, 10),
            ],
        ]);

        $response_rrii = $this->postJson('api/provider-order', $payload_rrii);

        $response_rrii->assertStatus(201);

        $pinza = $this->articulo('Pinza');

        $cost_rrii          = (float) $pinza->cost;
        $costo_real_rrii    = (float) $pinza->costo_real;
        $total_rrii         = (float) ProviderOrder::find($response_rrii->json('model.id'))->total;

        // Corrida 2: misma compra, ahora en MT. `set_condicion_iva('MT')` hace markTestSkipped()
        // si la columna condicion_iva_precios todavia no existe (ver docblock de la clase).
        $this->set_condicion_iva('MT');

        $payload_mt = $this->payload_compra([
            'precios_incluyen_iva' => 1,
            'articles' => [
                $this->item('Pinza', 1210, 10),
            ],
        ]);

        $response_mt = $this->postJson('api/provider-order', $payload_mt);

        $response_mt->assertStatus(201);

        $pinza->refresh();

        $cost_mt        = (float) $pinza->cost;
        $costo_real_mt  = (float) $pinza->costo_real;
        $total_mt       = (float) ProviderOrder::find($response_mt->json('model.id'))->total;

        $this->assertEqualsWithDelta(
            1000,
            $cost_rrii,
            self::DELTA,
            'costo base en RRII (invariante que no cambia entre condiciones)'
        );

        $this->assertEqualsWithDelta(
            1000,
            $cost_mt,
            self::DELTA,
            'costo base en MT (invariante que no cambia entre condiciones)'
        );

        $this->assertEqualsWithDelta(
            12100,
            $total_rrii,
            self::DELTA,
            'total de la compra en RRII (invariante que no cambia entre condiciones)'
        );

        $this->assertEqualsWithDelta(
            12100,
            $total_mt,
            self::DELTA,
            'total de la compra en MT (invariante que no cambia entre condiciones)'
        );

        $this->assertTrue(
            $costo_real_mt > $costo_real_rrii,
            'el costo real difiere entre condiciones: el del MT ('.$costo_real_mt.') tiene que ser mayor que el de RRII ('.$costo_real_rrii.'), porque incorpora el IVA que un MT no recupera'
        );
    }

    /**
     * Test 6 — multi-alicuota en la misma compra (RRII): dos articulos con alicuotas distintas
     * (21% y 10,5%) tienen que generar dos filas de desglose de IVA en la factura automatica, cada
     * una con su propio neto/importe, y el total de la orden tiene que sumar ambas.
     *
     * @group compras
     * @test
     */
    public function multi_alicuota_en_la_misma_compra_rrii()
    {
        $this->set_condicion_iva('RRII');
        $this->quitar_bonificaciones_de_buenos_aires();

        // Pinza (21%): costo 1000 x 10 -> neto 10000, IVA 2100.
        // Cuchilla (10,5%): costo 500 x 10 -> neto 5000, IVA 525.
        $payload = $this->payload_compra([
            'precios_incluyen_iva' => 0,
            'articles' => [
                $this->item('Pinza', 1000, 10),
                $this->item('Cuchilla', 500, 10),
            ],
        ]);

        $response = $this->postJson('api/provider-order', $payload);

        $response->assertStatus(201);

        $provider_order = ProviderOrder::find($response->json('model.id'));

        $this->assertEqualsWithDelta(
            15000,
            (float) $provider_order->sub_total,
            self::DELTA,
            'neto total de la compra (10000 de Pinza + 5000 de Cuchilla)'
        );

        $this->assertEqualsWithDelta(
            2625,
            (float) $provider_order->total_iva,
            self::DELTA,
            'IVA total de la compra (2100 de Pinza + 525 de Cuchilla)'
        );

        $this->assertEqualsWithDelta(
            17625,
            (float) $provider_order->total,
            self::DELTA,
            'total de la compra (neto + IVA)'
        );

        $ticket = ProviderOrderAfipTicket::where('provider_order_id', $provider_order->id)->first();

        $this->assertNotNull($ticket, 'se tiene que haber generado la factura automatica');

        $ticket->load('provider_order_afip_ticket_ivas');

        $this->assertCount(
            2,
            $ticket->provider_order_afip_ticket_ivas,
            'el desglose tiene que tener dos filas, una por alicuota (21% y 10,5%)'
        );

        $iva_21     = Iva::where('percentage', '21')->first();
        $iva_10_5   = Iva::where('percentage', '10.5')->first();

        $fila_21    = $ticket->provider_order_afip_ticket_ivas->where('iva_id', $iva_21->id)->first();
        $fila_10_5  = $ticket->provider_order_afip_ticket_ivas->where('iva_id', $iva_10_5->id)->first();

        $this->assertNotNull($fila_21, 'tiene que existir la fila de desglose de la alicuota 21%');
        $this->assertNotNull($fila_10_5, 'tiene que existir la fila de desglose de la alicuota 10,5%');

        $this->assertEqualsWithDelta(
            2100,
            (float) $fila_21->iva_importe,
            self::DELTA,
            'importe de IVA de la fila 21% (21% de 10000)'
        );

        $this->assertEqualsWithDelta(
            525,
            (float) $fila_10_5->iva_importe,
            self::DELTA,
            'importe de IVA de la fila 10,5% (10,5% de 5000)'
        );
    }

    /**
     * Test 7 — `Exento` y `No Gravado` valen 0 y no rompen la compra, ni en RRII ni en MT (con
     * `precios_incluyen_iva = 1`, donde el back-out con alicuota 0 tiene que devolver el mismo
     * costo tipeado, NO cero ni una division por cero).
     *
     * @group compras
     * @test
     */
    public function exento_y_no_gravado_valen_cero_y_no_rompen()
    {
        $this->set_condicion_iva('RRII');
        $this->quitar_bonificaciones_de_buenos_aires();

        // Cuchara (Exento, Buenos Aires) y Pintura para cama (No Gravado, Rosario).
        $payload_rrii = $this->payload_compra([
            'precios_incluyen_iva' => 0,
            'articles' => [
                $this->item('Cuchara', 100, 10),
                $this->item('Pintura para cama', 50, 10),
            ],
        ]);

        $response_rrii = $this->postJson('api/provider-order', $payload_rrii);

        $response_rrii->assertStatus(201);

        $provider_order_rrii = ProviderOrder::find($response_rrii->json('model.id'));

        $this->assertEqualsWithDelta(
            0,
            (float) $provider_order_rrii->total_iva,
            self::DELTA,
            'IVA total de una compra con solo alicuotas Exento/No Gravado tiene que ser 0'
        );

        $this->assertEqualsWithDelta(
            1500,
            (float) $provider_order_rrii->total,
            self::DELTA,
            'total de la compra (100 x 10 + 50 x 10, sin IVA)'
        );

        $cuchara = $this->articulo('Cuchara');
        $pintura = $this->articulo('Pintura para cama');

        $this->assertEqualsWithDelta(
            100,
            (float) $cuchara->cost,
            self::DELTA,
            'costo base de Cuchara (Exento, sin IVA que sacar, queda igual al tipeado)'
        );

        $this->assertEqualsWithDelta(
            50,
            (float) $pintura->cost,
            self::DELTA,
            'costo base de Pintura para cama (No Gravado, sin IVA que sacar, queda igual al tipeado)'
        );

        // Mismo caso en MT, con precios_incluyen_iva = 1: el back-out con alicuota 0 tiene que
        // devolver el mismo costo tipeado (dividir por 1 + 0), no cero ni una division por cero.
        // `set_condicion_iva('MT')` hace markTestSkipped() si la columna todavia no existe.
        $this->set_condicion_iva('MT');

        $payload_mt = $this->payload_compra([
            'precios_incluyen_iva' => 1,
            'articles' => [
                $this->item('Cuchara', 100, 10),
                $this->item('Pintura para cama', 50, 10),
            ],
        ]);

        $response_mt = $this->postJson('api/provider-order', $payload_mt);

        $response_mt->assertStatus(201);

        $cuchara->refresh();
        $pintura->refresh();

        $this->assertEqualsWithDelta(
            100,
            (float) $cuchara->cost,
            self::DELTA,
            'costo base de Cuchara en MT (alicuota 0: el back-out no tiene que dividir por cero ni dar 0)'
        );

        $this->assertEqualsWithDelta(
            50,
            (float) $pintura->cost,
            self::DELTA,
            'costo base de Pintura para cama en MT (alicuota 0: el back-out no tiene que dividir por cero ni dar 0)'
        );
    }

    /**
     * Test 8 — deriva por alicuota editada (comportamiento documentado, NO un bug): si se cambia
     * el `iva_id` de un articulo despues de una compra, sin hacer una compra nueva, el costo base
     * (`articles.cost`) no se mueve, pero el costo real SI se recalcula con la alicuota nueva
     * apenas se corre `ArticleHelper::setFinalPrice()` (por ejemplo, al recalcular precios desde
     * el modal del articulo). Lucas decidio no cambiar la arquitectura por esto: el costo base se
     * corrige solo en la proxima compra. Este test existe para que el comportamiento sea VISIBLE
     * y salte si alguien lo cambia sin querer sin darse cuenta.
     *
     * IMPORTANTE (hallazgo real de este prompt, ver docblock de la clase): todas las tablas de
     * `empresa_testing` son MyISAM, no InnoDB, asi que `DatabaseTransactions` NO revierte nada de
     * verdad al terminar el test (BEGIN/ROLLBACK son no-ops en MyISAM). Este test es el unico de
     * toda la suite de compras que deja el `iva_id` de un articulo del fixture en un valor
     * DISTINTO al que trae `TestingFerreteriaSeeder` (Pinza pasa de 21% a 10,5%) — sin
     * restaurarlo a mano, esa mutacion queda PERMANENTE en la base de testing y rompe silenciosamente
     * los tests de `Costeo_RRII_Test` (que asumen Pinza en 21%) en la proxima corrida. Por eso el
     * cambio de `iva_id` y su restauracion van en un `try/finally`: pase o falle el test, el
     * fixture tiene que volver a quedar exactamente como lo dejo el seeder.
     *
     * @group compras
     * @test
     */
    public function deriva_por_alicuota_editada_es_comportamiento_documentado_no_bug()
    {
        $this->set_condicion_iva('RRII');
        $this->quitar_bonificaciones_de_buenos_aires();

        // Compra Pinza (21%), costo tipeado neto 1000, cantidad 10.
        $payload = $this->payload_compra([
            'precios_incluyen_iva' => 0,
            'articles' => [
                $this->item('Pinza', 1000, 10),
            ],
        ]);

        $response = $this->postJson('api/provider-order', $payload);

        $response->assertStatus(201);

        $pinza = $this->articulo('Pinza');

        /** iva_id original del fixture (21%, ver TestingFerreteriaSeeder::catalogo), para restaurarlo siempre al final. */
        $iva_id_original = $pinza->iva_id;

        $cost_antes         = (float) $pinza->cost;
        $costo_real_antes   = (float) $pinza->costo_real;

        try {

            // Se cambia el iva_id del articulo (de 21% a 10,5%) SIN hacer una compra nueva, y se
            // recalculan los precios directamente con la logica central del sistema.
            $iva_10_5 = Iva::where('percentage', '10.5')->first();

            $pinza->iva_id = $iva_10_5->id;
            $pinza->save();

            ArticleHelper::setFinalPrice($pinza);

            $pinza->refresh();

            $this->assertEqualsWithDelta(
                $cost_antes,
                (float) $pinza->cost,
                self::DELTA,
                'costo base (articles.cost) NO se mueve al cambiar el iva_id sin una compra nueva: se corrige solo en la proxima compra (decision documentada, no un bug)'
            );

            $this->assertNotEqualsWithDelta(
                $costo_real_antes,
                (float) $pinza->costo_real,
                self::DELTA,
                'costo real SI se recalcula con la alicuota nueva apenas corre ArticleHelper::setFinalPrice(), aunque el costo base no se haya movido'
            );

        } finally {

            // Restauracion manual OBLIGATORIA (ver docblock del test): DatabaseTransactions no
            // revierte nada en esta base (todas las tablas son MyISAM), asi que sin este bloque
            // Pinza quedaria permanentemente en 10,5% y rompe la suite de RRII en la proxima corrida.
            $pinza->iva_id = $iva_id_original;
            $pinza->save();

            ArticleHelper::setFinalPrice($pinza);
        }
    }
}
