<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\NombreParecidoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaVentaIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\Client;
use App\Models\ExtencionEmpresa;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\Concerns\EscenariosDePlata;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-mejoras-4-dias (16/11/2026), rama A, P8 (parte cliente) — V14.
 *
 * 🔴 EL CASO REAL (Truvari, 13/11/2026, por audio): «Justo Rubén» era RUBEN EDUARDO GIUSTO y «Nespeka Julio»
 * era NESPECA JULIO HUMBERTO. El LIKE no los encuentra. Ahora se buscan los que suenan parecido y se
 * PREGUNTAN —aunque haya uno solo—: nunca se elige solo entre parecidos.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 *
 * @group chat-ia
 */
class Cliente_dictado_en_la_venta_Test extends EmpresaTestCase
{
    use EscenariosDePlata;

    /** @var User */
    protected $dueno;

    /** @var Client */
    protected $giusto;

    /** @var Article */
    protected $vino;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $extencion = ExtencionEmpresa::where('slug', 'agentes_ia')->first() ?: ExtencionEmpresa::forceCreate(['slug' => 'agentes_ia', 'name' => 'agentes_ia']);
        $this->dueno->extencions()->syncWithoutDetaching([$extencion->id]);
        $this->dueno->load('extencions');

        $this->giusto = Client::create(['name' => 'zz-c116 RUBEN EDUARDO GIUSTO', 'user_id' => $this->dueno->id]);

        $this->vino = Article::create([
            'name' => 'zz-c116 Rutini Encuentro Malbec', 'user_id' => $this->dueno->id, 'status' => 'active',
            'final_price' => 84000, 'cost' => 50000, 'stock' => 9, 'iva_id' => 2,
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

        $assistant = AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'assistant', 'estado' => 'pendiente', 'acciones_habilitadas' => true]);

        return [$conversation, $assistant];
    }

    protected function proponer($conversation, $assistant, array $input)
    {
        return PropuestaVentaIaHelper::proponer(ContextoDeCargaIa::de_la_conversacion($conversation), $assistant, $input);
    }

    /**
     * El comparador con los pares del caso real (y los que no tienen que pasar).
     *
     * @test
     */
    public function el_comparador_de_nombres_dictados()
    {
        $this->assertTrue(NombreParecidoIaHelper::se_parece('Justo Rubén', 'RUBEN EDUARDO GIUSTO'));
        $this->assertTrue(NombreParecidoIaHelper::se_parece('Nespeka Julio', 'NESPECA JULIO HUMBERTO'));
        $this->assertTrue(NombreParecidoIaHelper::se_parece('Kilka Malbec', 'KILLKA MALBEC'));
        $this->assertTrue(NombreParecidoIaHelper::nombra_alguna_palabra('a la cuenta de vrobank first', 'BRUBANK FER', ['caja']));

        $this->assertFalse(NombreParecidoIaHelper::se_parece('Justo Rubén', 'RUBEN PEREZ'));
        $this->assertFalse(NombreParecidoIaHelper::se_parece('Pablo', 'Pedro'));
        $this->assertFalse(NombreParecidoIaHelper::nombra_alguna_palabra('pagó en efectivo', 'Caja Mercado Pago', ['caja', 'pago']));
    }

    /**
     * 🔴 «Justo Rubén»: no se carga a nadie; se pregunta si es RUBEN EDUARDO GIUSTO. Con el sí (su id) la
     * venta sale a su nombre de punta a punta.
     *
     * @test
     */
    public function un_cliente_dictado_se_propone_y_con_el_si_la_venta_sale_a_su_nombre()
    {
        $caja = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_EFECTIVO);
        $this->asegurar_caja_abierta($caja);

        list($conversation, $assistant) = $this->conversacion('Es para el cliente Justo Rubén, llevó un Rutini zz-c116. Pagó por transferencia a la caja efectivo');

        $input = [
            'items'          => [['articulo_id' => $this->vino->id, 'cantidad' => 1]],
            'cliente'        => 'Justo Rubén',
            'cobro'          => 'contado',
            'metodo_de_pago' => 'Transferencia',
            'caja'           => TestingFerreteriaSeeder::CAJA_EFECTIVO,
        ];

        $pregunta = $this->proponer($conversation, $assistant, $input);

        $this->assertFalse($pregunta['ok'], json_encode($pregunta));
        $this->assertStringContainsString('no elijo solo', implode(' ', $pregunta['faltan']));

        $ids = array_map(function ($opcion) {
            return (int) $opcion['cliente_id'];
        }, $pregunta['opciones']['clientes']);

        $this->assertContains((int) $this->giusto->id, $ids);
        $this->assertTrue($pregunta['opciones']['clientes'][0]['parecido']);
        $this->assertSame(0, AiMessageAction::where('ai_message_id', $assistant->id)->count());

        // La persona dice «sí, es ese»: el modelo vuelve con el id.
        $input['cliente'] = $this->giusto->id;

        $respuesta = $this->proponer($conversation, $assistant, $input);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        $assistant->contenido = 'Te dejé la tarjeta.';
        $assistant->estado = 'listo';
        $assistant->save();

        $movimientos_antes = $this->max_id_movimiento_caja();
        $http = $this->postJson('api/ai-conversations/' . $conversation->id . '/acciones/' . $respuesta['tarjeta_id'] . '/confirmar');
        $this->registrar_movimientos_caja_nuevos($movimientos_antes);

        $this->assertSame(200, $http->getStatusCode(), json_encode($http->json()));

        $venta_id = (int) $http->json('model.resultado.venta_id');
        $this->ventas_creadas_por_escenarios[] = $venta_id;

        $this->assertSame((int) $this->giusto->id, (int) Sale::findOrFail($venta_id)->client_id);
        $this->assertEquals(8, (float) Article::find($this->vino->id)->stock);
    }

    /**
     * Dos parecidos: se ofrecen los dos y no se elige ninguno.
     *
     * @test
     */
    public function con_dos_parecidos_se_ofrecen_los_dos()
    {
        $julio = Client::create(['name' => 'zz-c116 NESPECA JULIO HUMBERTO', 'user_id' => $this->dueno->id]);
        $julia = Client::create(['name' => 'zz-c116 NESPECA JULIA', 'user_id' => $this->dueno->id]);

        list($conversation, $assistant) = $this->conversacion('Una venta para Nespeka Julio');

        $respuesta = $this->proponer($conversation, $assistant, [
            'items'   => [['articulo_id' => $this->vino->id, 'cantidad' => 1]],
            'cliente' => 'Nespeka Juli',
        ]);

        $this->assertFalse($respuesta['ok']);

        $ids = array_map(function ($opcion) {
            return (int) $opcion['cliente_id'];
        }, $respuesta['opciones']['clientes']);

        $this->assertContains((int) $julio->id, $ids);
        $this->assertContains((int) $julia->id, $ids);
    }
}
