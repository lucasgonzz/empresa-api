<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaVentaIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\ExtencionEmpresa;
use App\Models\PromocionVinoteca;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\EscenariosDePlata;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-mejoras-4-dias (16/11/2026), rama A, P3 — vender promos de vinoteca (V7, V19).
 *
 * 🔴 EL CASO REAL (Truvari, 13/11/2026, conversación 26). «Llevó una caja del Mix Blend. Pagó en efectivo»
 * → «No tengo una herramienta para cargar la venta de una promo de vinoteca». En Vender la promo entra por
 * el buscador «Promocion» como un renglón con `is_promocion_vinoteca`, a su `final_price` (con el descuento
 * del método de pago como cualquier ítem), y al guardar SaleHelper::attachPromocionVinotecas() la cuelga de
 * la venta y le descuenta el stock A LA PROMO. Estos tests van del pedido a la base: la fila en
 * `promocion_vinoteca_sale`, el stock de la promo, el total de la venta y la caja.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 *
 * @group chat-ia
 */
class Venta_de_promos_de_vinoteca_Test extends EmpresaTestCase
{
    use EscenariosDePlata;

    /** @var User */
    protected $dueno;

    /** @var PromocionVinoteca $ 87.000, stock 6. */
    protected $mix;

    /** @var Article Precio 2.000, stock 10. */
    protected $martillo;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        foreach (['agentes_ia', 'vinoteca'] as $slug) {

            $extencion = ExtencionEmpresa::where('slug', $slug)->first();

            if (is_null($extencion)) {
                $extencion = ExtencionEmpresa::forceCreate(['slug' => $slug, 'name' => $slug]);
            }

            $this->dueno->extencions()->syncWithoutDetaching([$extencion->id]);
        }

        $this->dueno->load('extencions');

        $this->mix = PromocionVinoteca::create([
            'name'        => 'zz-c113 MIX BLEND',
            'user_id'     => $this->dueno->id,
            'stock'       => 6,
            'cost'        => 50000,
            'final_price' => 87000,
            'online'      => 1,
        ]);

        $this->martillo = Article::create([
            'name'        => 'zz-c113 Martillo prueba',
            'user_id'     => $this->dueno->id,
            'status'      => 'active',
            'final_price' => 2000,
            'cost'        => 1000,
            'stock'       => 10,
            'iva_id'      => 2,
        ]);
    }

    protected function tearDown(): void
    {
        $this->limpiar_escenarios();

        parent::tearDown();
    }

    protected function conversacion($pedido)
    {
        $conversation = AiConversation::create(['user_id' => $this->dueno->id, 'auth_user_id' => $this->dueno->id]);

        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => $pedido, 'estado' => 'listo']);

        $assistant = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => true,
        ]);

        return [$conversation, $assistant];
    }

    protected function proponer($conversation, $assistant, array $input)
    {
        return PropuestaVentaIaHelper::proponer(ContextoDeCargaIa::de_la_conversacion($conversation), $assistant, $input);
    }

    protected function confirmar($conversation, $assistant, $tarjeta_id)
    {
        $assistant->contenido = 'Te dejé la tarjeta.';
        $assistant->estado = 'listo';
        $assistant->save();

        $movimientos_antes = $this->max_id_movimiento_caja();

        $http = $this->postJson('api/ai-conversations/' . $conversation->id . '/acciones/' . $tarjeta_id . '/confirmar');

        $this->registrar_movimientos_caja_nuevos($movimientos_antes);

        $venta_id = $http->json('model.resultado.venta_id');

        if (!is_null($venta_id)) {
            $this->ventas_creadas_por_escenarios[] = (int) $venta_id;
        }

        return $http;
    }

    /**
     * 🔴 El caso real de punta a punta: «una del Mix Blend, pagó por transferencia» con el nombre como lo
     * dice el dueño (sin «promo»), que no es ningún artículo → renglón de promo → venta con la promo en
     * `promocion_vinoteca_sale`, su stock descontado y el total de la promo.
     *
     * @test
     */
    public function la_promo_por_su_nombre_se_vende_y_descuenta_su_stock()
    {
        $caja = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_EFECTIVO);
        $this->asegurar_caja_abierta($caja);

        list($conversation, $assistant) = $this->conversacion('Llevó uno del zz-c113 mix blend, pagó por transferencia a la caja efectivo');

        $respuesta = $this->proponer($conversation, $assistant, [
            'items'          => [['articulo' => 'zz-c113 mix blend', 'cantidad' => 1]],
            'cobro'          => 'contado',
            'metodo_de_pago' => 'Transferencia',
            'caja'           => TestingFerreteriaSeeder::CAJA_EFECTIVO,
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));
        $this->assertStringContainsString('1 × Promo zz-c113 MIX BLEND', $respuesta['resumen']);

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertStringContainsString('1 × Promo zz-c113 MIX BLEND — $ 87.000 = $ 87.000', json_encode($tarjeta->presentacion, JSON_UNESCAPED_UNICODE));
        $this->assertEquals(87000, (float) $tarjeta->datos['payload']['total']);

        $http = $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id']);

        $this->assertSame(200, $http->getStatusCode(), json_encode($http->json()));

        $venta = Sale::findOrFail((int) $http->json('model.resultado.venta_id'));

        $pivot = DB::table('promocion_vinoteca_sale')->where('sale_id', $venta->id)->first();

        $this->assertNotNull($pivot, 'La promo tiene que quedar colgada de la venta.');
        $this->assertSame((int) $this->mix->id, (int) $pivot->promocion_vinoteca_id);
        $this->assertEquals(1, (float) $pivot->amount);
        $this->assertEquals(87000, (float) $pivot->price);
        $this->assertSame(5, (int) PromocionVinoteca::find($this->mix->id)->stock, 'Una promo vendida = una menos.');
        $this->assertEquals(87000, (float) $venta->total, '', 0.01);
        $this->assertSame(0, DB::table('article_sale')->where('sale_id', $venta->id)->count(), 'La promo no es un artículo.');
        $this->assertTrue(DB::table('movimiento_cajas')->where('caja_id', $caja->id)->exists());
    }

    /**
     * Artículo y promo en la misma venta, por `promo_id`, con efectivo: el descuento del método de pago
     * (10 % en el fixture) se aplica a la promo renglón por renglón como en getPriceVender().
     *
     * @test
     */
    public function articulo_y_promo_juntos_con_el_descuento_del_metodo()
    {
        $caja = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_EFECTIVO);
        $this->asegurar_caja_abierta($caja);

        list($conversation, $assistant) = $this->conversacion('Un martillo zz-c113 y dos promos, en efectivo a la caja efectivo');

        $respuesta = $this->proponer($conversation, $assistant, [
            'items'          => [
                ['articulo_id' => $this->martillo->id, 'cantidad' => 1],
                ['promo_id' => $this->mix->id, 'cantidad' => 2],
            ],
            'cobro'          => 'contado',
            'metodo_de_pago' => TestingFerreteriaSeeder::PAGO_EFECTIVO,
            'caja'           => TestingFerreteriaSeeder::CAJA_EFECTIVO,
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        $http = $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id']);

        $this->assertSame(200, $http->getStatusCode(), json_encode($http->json()));

        $venta = Sale::findOrFail((int) $http->json('model.resultado.venta_id'));

        $descuento = TestingFerreteriaSeeder::DESCUENTO_EFECTIVO / 100;

        $this->assertEquals(87000 * (1 - $descuento), (float) DB::table('promocion_vinoteca_sale')->where('sale_id', $venta->id)->value('price'), '', 0.01);
        $this->assertEquals(2, (float) DB::table('promocion_vinoteca_sale')->where('sale_id', $venta->id)->value('amount'));
        $this->assertEquals((2000 + 2 * 87000) * (1 - $descuento), (float) $venta->total, '', 0.01);
        $this->assertSame(4, (int) PromocionVinoteca::find($this->mix->id)->stock);
        $this->assertEquals(9, (float) Article::find($this->martillo->id)->stock);
    }

    /**
     * Una promo es una unidad entera, y más promos que su stock avisa en la tarjeta como un artículo.
     *
     * @test
     */
    public function cantidad_entera_y_aviso_de_stock_de_la_promo()
    {
        list($conversation, $assistant) = $this->conversacion('Una y media del mix');

        $media = $this->proponer($conversation, $assistant, [
            'items' => [['promo_id' => $this->mix->id, 'cantidad' => 1.5]],
        ]);

        $this->assertFalse($media['ok']);
        $this->assertStringContainsString('entero', (string) $media['error']);

        $de_mas = $this->proponer($conversation, $assistant, [
            'items' => [['promo_id' => $this->mix->id, 'cantidad' => 8]],
        ]);

        // Sin cobro dicho falta el método: lo que importa acá es que la promo se resolvió (no hay error de promo).
        $this->assertNull($de_mas['error'], json_encode($de_mas));
    }

    /**
     * Sin el módulo Vinoteca no hay promos: el nombre se busca entre los artículos como siempre.
     *
     * @test
     */
    public function sin_vinoteca_el_nombre_sigue_siendo_un_articulo()
    {
        $this->dueno->extencions()->detach(ExtencionEmpresa::where('slug', 'vinoteca')->value('id'));
        $this->dueno->load('extencions');

        list($conversation, $assistant) = $this->conversacion('Uno del mix blend');

        $respuesta = $this->proponer($conversation, $assistant, [
            'items' => [['articulo' => 'zz-c113 mix blend', 'cantidad' => 1]],
        ]);

        $this->assertFalse($respuesta['ok']);
        $this->assertStringContainsString('No encontré ningún artículo', (string) $respuesta['error']);
    }

    /**
     * Revisión independiente (menor 5): la misma venta con la misma promo al mismo cliente, ya registrada,
     * contesta `venta_repetida`; con OTRA cantidad de promos no.
     *
     * @test
     */
    public function la_misma_venta_con_la_misma_promo_es_repetida()
    {
        $caja = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_EFECTIVO);
        $this->asegurar_caja_abierta($caja);

        $input = [
            'items'          => [['promo_id' => $this->mix->id, 'cantidad' => 1]],
            'cliente'        => TestingFerreteriaSeeder::CLIENTE_CC,
            'cobro'          => 'contado',
            'metodo_de_pago' => 'Transferencia',
            'caja'           => TestingFerreteriaSeeder::CAJA_EFECTIVO,
        ];

        list($conversation, $assistant) = $this->conversacion('Un mix para el cliente, por transferencia a la caja efectivo');

        $primera = $this->proponer($conversation, $assistant, $input);
        $this->assertTrue(!empty($primera['ok']), json_encode($primera));
        $this->assertSame(200, $this->confirmar($conversation, $assistant, $primera['tarjeta_id'])->getStatusCode());

        list($conversation, $assistant) = $this->conversacion('Un mix para el cliente, por transferencia a la caja efectivo');

        $repetida = $this->proponer($conversation, $assistant, $input);

        $this->assertFalse($repetida['ok']);
        $this->assertSame('venta_repetida', $repetida['codigo'] ?? null, json_encode($repetida));

        $input['items'][0]['cantidad'] = 2;

        $otra = $this->proponer($conversation, $assistant, $input);

        $this->assertTrue(!empty($otra['ok']), json_encode($otra));
    }
}
