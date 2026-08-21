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
 * IMPORTANTE (grupo 231, prompt 01): la condicion fiscal vive en `users.condicion_iva_precios`
 * (movida desde `user_configurations`). `ComprasTestCase::set_condicion_iva('MT')` sigue teniendo
 * un guard por `Schema::hasColumn` que hace `markTestSkipped()` solo si esa columna no existe
 * todavia en el entorno de test — red de seguridad, no el comportamiento esperado.
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
     * Test 1 — el costo que un MT carga en una compra se guarda TAL CUAL, y el flag
     * `precios_incluyen_iva` se sigue ignorando para el (pero ahora al reves que antes: no se
     * descompone nunca, en vez de descomponerse siempre).
     *
     * 🔴 CAMBIO DE ESPECIFICACION, decision de Lucas del 21/8/2026 (mision
     * `iva-fuera-del-costeo-monotributista`). Este test codificaba el prompt 615: "el MT ignora el
     * flag de la compra y trata el costo SIEMPRE como bruto". Lucas redefinio la regla: *"el
     * monotributista simplemente carga el costo que su proveedor le pasa"* y *"el IVA no tiene que
     * cambiar nada del precio en un monotributista"*. Los numeros de abajo salen de esa regla, NO se
     * ajustaron a lo que devuelve el sistema.
     *
     * @group compras
     * @test
     */
    public function el_costo_que_carga_un_mt_en_la_compra_se_guarda_tal_cual()
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
            1210,
            (float) $pinza->cost,
            self::DELTA,
            'costo base de Pinza en MT: lo que se cargo (1210) es lo que se guarda; para un MT no hay bruto ni neto'
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
     * IMPORTANTE (hallazgo real de este prompt, ver docblock de la clase): todas las tablas de
     * `empresa_testing` son MyISAM, no InnoDB, asi que `DatabaseTransactions` NO revierte nada de
     * verdad al terminar el test (BEGIN/ROLLBACK son no-ops en MyISAM). Este test apaga el
     * `aplicar_iva` de un articulo del fixture a proposito — sin restaurarlo a mano, esa mutacion
     * queda PERMANENTE en la base de testing y rompe silenciosamente los tests posteriores que
     * asumen Pinza en aplicar_iva=1 (su valor default del seeder). Por eso el cambio de `aplicar_iva`
     * y su restauracion van en un `try/finally`: pase o falle el test, el fixture tiene que volver
     * a quedar exactamente como lo dejo el seeder.
     *
     * @group compras
     * @test
     */
    public function el_costo_real_de_un_mt_es_el_costo_que_cargo()
    {
        $this->set_condicion_iva('MT');
        $this->quitar_bonificaciones_de_buenos_aires();

        // Se apaga aplicar_iva del articulo a proposito: para un MT no deberia importar.
        $pinza = $this->articulo('Pinza');

        /** aplicar_iva original del fixture (1, ver TestingFerreteriaSeeder::catalogo), para restaurarlo siempre al final. */
        $aplicar_iva_original = $pinza->aplicar_iva;

        try {

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
                1210,
                (float) $pinza->cost,
                self::DELTA,
                'costo base de Pinza en MT: lo cargado, tal cual, sin importar aplicar_iva'
            );

            $this->assertEqualsWithDelta(
                1210,
                (float) $pinza->costo_real,
                self::DELTA,
                'costo real de Pinza en MT: es el costo que cargo. El IVA no participa en ningun punto'
            );

        } finally {

            // Restauracion manual OBLIGATORIA (ver docblock del test): DatabaseTransactions no
            // revierte nada en esta base (todas las tablas son MyISAM), asi que sin este bloque
            // Pinza quedaria permanentemente en aplicar_iva=0 y rompe la suite en la proxima corrida.
            $pinza->aplicar_iva = $aplicar_iva_original;
            $pinza->save();

        }
    }

    /**
     * Test 5 — misma compra corrida primero en RRII y despues en MT: documenta en un solo lugar
     * que cambia realmente entre las dos condiciones.
     *
     * 🔴 Lo que cambio el 21/8/2026 (mision `iva-fuera-del-costeo-monotributista`): `articles.cost`
     * ya NO es igual en las dos condiciones. Sobre el mismo costo cargado de 1210, el RRII guarda el
     * neto (1000, porque su Factura A le discrimina el IVA y lo recupera como credito) y el MT
     * guarda 1210, que es lo que efectivamente paga. Es la diferencia de fondo entre las dos
     * condiciones, y ahora esta a la vista en la columna de costo en vez de escondida detras de un
     * ida y vuelta.
     *
     * Lo que NO cambio: el total de la compra es el mismo (12100), y el costo real del MT sigue
     * siendo mayor que el del RRII.
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
        // solo si la columna users.condicion_iva_precios todavia no existe (ver docblock de la clase).
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
            'costo base en RRII: el neto (1000), porque el IVA se lo recupera como credito fiscal'
        );

        $this->assertEqualsWithDelta(
            1210,
            $cost_mt,
            self::DELTA,
            'costo base en MT: lo que pago (1210). El RRII guarda el neto porque recupera el IVA; el MT no lo recupera, asi que su costo ES el bruto'
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
     * fixture tiene que volver a quedar exactamente como lo dejo el seeder. La condicion fiscal de
     * la cuenta (ver parrafo siguiente) se restaura por el mismo motivo.
     *
     * POR QUE ESTE ESCENARIO CORRE EN MT Y NO EN RRII (grupo 235, correctivo del fallo #40): estaba
     * escrito en RRII, de cuando `aplicar_iva_al_costo` (default 1) pisaba la condicion fiscal real y
     * hasta un Responsable Inscripto sumaba el IVA al costo — por eso ahi el costo real dependia de la
     * alicuota del articulo. Desde el grupo 231 esa pregunta la responde
     * `ArticlePricesHelper::iva_va_al_costo()` por condicion fiscal: en RRII el IVA es credito fiscal
     * recuperable, NO entra al costo, y el costo real deja de depender de la alicuota. En RRII la
     * deriva ya no existe y la asercion de abajo no tendria nada que observar. En MT si: el IVA es
     * costo, entra antes del margen y la alicuota del articulo participa del costo real. Mover el
     * escenario a MT no relaja la prueba — la lleva a la unica condicion donde el comportamiento que
     * documenta puede ocurrir.
     *
     * @group compras
     * @test
     */
    public function editar_la_alicuota_ya_no_mueve_el_costo_real_de_un_mt()
    {
        $this->set_condicion_iva('MT');
        $this->quitar_bonificaciones_de_buenos_aires();

        // Compra Pinza (21%), costo tipeado 1210, cantidad 10. Un MT trata el costo tipeado SIEMPRE
        // como bruto e ignora precios_incluyen_iva (ver test 1), asi que el costo base queda neto en
        // 1000 y el costo real en 1210 (1000 + 21%).
        $payload = $this->payload_compra([
            'precios_incluyen_iva' => 0,
            'articles' => [
                $this->item('Pinza', 1210, 10),
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

            /*
             * 🔴 ACA SE INVIRTIO LA ASERCION, y es la mejor noticia de la mision del 21/8/2026.
             *
             * La "deriva por alicuota editada" que este test documentaba **ya no existe en ninguna
             * condicion fiscal**. En RRII habia dejado de existir en el grupo 231 (el IVA es credito
             * fiscal y no entra al costo). En MT deja de existir ahora, porque el IVA salio del
             * pipeline: el costo real de un MT es el costo que cargo, y la alicuota del articulo no
             * participa.
             *
             * O sea que cambiarle el IVA a un articulo ya no le mueve el costo por la ventana. Se
             * deja el test, invertido, para que si alguien reintroduce esa dependencia salte acá.
             */
            $this->assertEqualsWithDelta(
                $costo_real_antes,
                (float) $pinza->costo_real,
                self::DELTA,
                'cambiarle la alicuota a un articulo NO le mueve el costo real a un MT: el IVA ya no participa del costeo'
            );

        } finally {

            // Restauracion manual OBLIGATORIA (ver docblock del test): DatabaseTransactions no
            // revierte nada en esta base (todas las tablas son MyISAM), asi que sin este bloque
            // Pinza quedaria permanentemente en 10,5% y la cuenta en MT, y rompe la suite de RRII en
            // la proxima corrida. La condicion fiscal se restaura ANTES del recalculo para que el
            // costo real que queda escrito en el fixture sea el de la condicion original (RRII, el
            // default del seeder) y no el de MT.
            $this->set_condicion_iva('RRII');

            $pinza->iva_id = $iva_id_original;
            $pinza->save();

            ArticleHelper::setFinalPrice($pinza);
        }
    }
}
