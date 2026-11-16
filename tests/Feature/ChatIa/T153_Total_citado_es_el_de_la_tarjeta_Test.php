<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\FormatoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaVentaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\VentaConDatosInventadosIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\EscenariosDePlata;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-diario-20261116, rama A, 31/10 #8 — el total que se le dice al dueño es el de la tarjeta.
 *
 * 🔴 EL CASO REAL (Ferre Total, 31/10/2026, conv. 33): «5 kg de cloro con 10 % de descuento, en efectivo».
 * El asistente dijo $30.516,79 (su cuenta) y la venta se armó por $31.470,43, sin explicar la diferencia.
 *
 * Acá: Efectivo lleva 10 % de descuento del método (fixture de plata) y la venta lleva además el
 * descuento de venta del 15 %. La cuenta ingenua del modelo (precio de lista × cantidad − 15 %) no es la
 * de la tarjeta; la guarda la detecta y la cambia por el total de la tarjeta con el porqué.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 *
 * @group chat-ia
 */
class T153_Total_citado_es_el_de_la_tarjeta_Test extends EmpresaTestCase
{
    use EscenariosDePlata;

    /** @var User */
    protected $dueno;

    /** @var Article Precio 2.000. */
    protected $cloro;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $extencion = ExtencionEmpresa::where('slug', 'agentes_ia')->first();

        if (is_null($extencion)) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => 'agentes_ia', 'name' => 'agentes_ia']);
        }

        $this->dueno->extencions()->syncWithoutDetaching([$extencion->id]);
        $this->dueno->load('extencions');

        $this->cloro = Article::create(['name' => 'zz-t153 Cloro kg', 'user_id' => $this->dueno->id, 'status' => 'active', 'final_price' => 2000, 'cost' => 900, 'stock' => 100, 'iva_id' => 2]);

        $this->asegurar_caja_abierta($this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_EFECTIVO));
    }

    protected function tearDown(): void
    {
        $this->limpiar_escenarios();

        parent::tearDown();
    }

    protected function input()
    {
        return [
            'items'                => [['articulo_id' => $this->cloro->id, 'cantidad' => 5]],
            'cobro'                => 'contado',
            'metodo_de_pago'       => TestingFerreteriaSeeder::PAGO_EFECTIVO,
            'caja'                 => TestingFerreteriaSeeder::CAJA_EFECTIVO,
            'descuento_porcentaje' => TestingFerreteriaSeeder::DESCUENTO_VENTA_PORCENTAJE,
        ];
    }

    /**
     * El total de la tarjeta: 5 × (2.000 − 10 % de Efectivo) − 15 % de la venta.
     *
     * @return float
     */
    protected function total_de_la_tarjeta()
    {
        $precio = 2000 - 2000 * TestingFerreteriaSeeder::DESCUENTO_EFECTIVO / 100;
        $sub_total = round($precio * 5, 2);

        return round($sub_total - round($sub_total * TestingFerreteriaSeeder::DESCUENTO_VENTA_PORCENTAJE / 100, 2), 2);
    }

    /** La cuenta del modelo en el caso real: precio de lista × cantidad − el descuento, sin el del método. */
    protected function total_ingenuo()
    {
        return round(2000 * 5 * (1 - TestingFerreteriaSeeder::DESCUENTO_VENTA_PORCENTAJE / 100), 2);
    }

    /**
     * Una conversación con el pedido y el assistant del turno, y la tarjeta ya armada.
     *
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function turno_con_tarjeta()
    {
        $conversation = AiConversation::create(['user_id' => $this->dueno->id, 'auth_user_id' => $this->dueno->id]);
        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => '5 kg de cloro zz-t153 con 15 % de descuento, en efectivo a la caja efectivo', 'estado' => 'listo']);
        $assistant = AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'assistant', 'estado' => 'pendiente', 'acciones_habilitadas' => true]);

        $respuesta = PropuestaVentaIaHelper::proponer(ContextoDeCargaIa::de_la_conversacion($conversation), $assistant, $this->input());

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        return [$conversation, $assistant];
    }

    /**
     * 🔴 El detector: el total ingenuo dispara; el de la tarjeta y los otros números de la tarjeta, no.
     *
     * @test
     */
    public function un_total_que_no_es_el_de_la_tarjeta_se_detecta()
    {
        list(, $assistant) = $this->turno_con_tarjeta();

        $tarjeta = AiMessageAction::where('ai_message_id', $assistant->id)->firstOrFail();
        $this->assertEquals($this->total_de_la_tarjeta(), $tarjeta->datos['resumen']['total']);
        $this->assertEquals(TestingFerreteriaSeeder::DESCUENTO_EFECTIVO, $tarjeta->datos['resumen']['porcentaje_del_metodo']);

        $ingenuo = FormatoIaHelper::monto($this->total_ingenuo());
        $bueno = FormatoIaHelper::monto($this->total_de_la_tarjeta());

        $this->assertNotEquals($this->total_ingenuo(), $this->total_de_la_tarjeta(), 'El caso tiene que tener dos totales distintos.');

        $this->assertTrue(VentaConDatosInventadosIaHelper::afirma_otro_total('Te armé la venta. El total queda en ' . $ingenuo . ' con el 15 % de descuento.', $assistant));
        $this->assertTrue(VentaConDatosInventadosIaHelper::afirma_otro_total('Total: $8500', $assistant), 'Sin separador de miles también.');

        $this->assertFalse(VentaConDatosInventadosIaHelper::afirma_otro_total('Te armé la venta. Total ' . $bueno . '.', $assistant));
        $this->assertFalse(VentaConDatosInventadosIaHelper::afirma_otro_total('Subtotal $ 9.000, total ' . $bueno . '.', $assistant), 'El subtotal es un número de la tarjeta.');
        $this->assertFalse(VentaConDatosInventadosIaHelper::afirma_otro_total('El precio por kg es $ 1.800.', $assistant), 'Sin hablar del total no dispara.');

        // Sin tarjeta de venta en el turno, nada.
        $otro = AiMessage::create(['ai_conversation_id' => $assistant->ai_conversation_id, 'rol' => 'assistant', 'estado' => 'pendiente']);
        $this->assertFalse(VentaConDatosInventadosIaHelper::afirma_otro_total('El total queda en ' . $ingenuo . '.', $otro));
    }

    /**
     * El texto honesto: la oración con el total ingenuo se cambia por el de la tarjeta y el porqué.
     *
     * @test
     */
    public function el_texto_honesto_dice_el_total_de_la_tarjeta_y_por_que()
    {
        list(, $assistant) = $this->turno_con_tarjeta();

        $ingenuo = FormatoIaHelper::monto($this->total_ingenuo());

        $honesto = VentaConDatosInventadosIaHelper::texto_honesto('Te dejé la tarjeta del cloro. El total queda en ' . $ingenuo . ' con el 15 % de descuento.', $assistant);

        $this->assertStringNotContainsString($ingenuo, $honesto);
        $this->assertStringContainsString('Te dejé la tarjeta del cloro.', $honesto, 'El resto de la respuesta queda.');
        $this->assertStringContainsString(VentaConDatosInventadosIaHelper::AVISO_TOTAL, $honesto);
        $this->assertStringContainsString(FormatoIaHelper::monto($this->total_de_la_tarjeta()), $honesto);
        $this->assertStringContainsString('10 % de ' . TestingFerreteriaSeeder::PAGO_EFECTIVO, $honesto);
        $this->assertStringContainsString('descuento de la venta (15 %)', $honesto);
    }

    /**
     * Los montos en formato argentino y con punto decimal.
     *
     * @test
     */
    public function lee_los_montos_citados()
    {
        $this->assertEquals([31470.43], VentaConDatosInventadosIaHelper::montos_citados('Total $31.470,43.'));
        $this->assertEquals([30516.79], VentaConDatosInventadosIaHelper::montos_citados('Total $30516.79'));
        $this->assertEquals([1202047.0, 70.5], VentaConDatosInventadosIaHelper::montos_citados('$ 1.202.047 y $70,5'));
        $this->assertSame([], VentaConDatosInventadosIaHelper::montos_citados('70 ventas'));
    }

    /**
     * 🔴 El turno entero por el servicio: el modelo arma la tarjeta y dice su cuenta; la guarda reintenta
     * con la nota que dice que el total es el de la tarjeta y, si insiste, la persona lee el aviso honesto.
     *
     * @test
     */
    public function por_el_servicio_el_total_inventado_reintenta_y_no_llega()
    {
        config([
            'services.deepseek.api_key'        => 'clave-deepseek-de-prueba-t153',
            'services.deepseek.model'          => 'deepseek-general-t153',
            'services.deepseek.model_agil'     => 'deepseek-flash-t153',
            'services.deepseek.model_profundo' => 'deepseek-pro-t153',
            'broadcasting.default'             => 'null',
        ]);

        $this->dueno->agente_proveedor   = 'deepseek';
        $this->dueno->agente_pensamiento = 'agil';
        $this->dueno->save();

        $ingenuo = FormatoIaHelper::monto($this->total_ingenuo());
        $mentira = 'Listo, te dejé la tarjeta. El total queda en ' . $ingenuo . '.';

        $respuesta = function ($texto) {
            return ['model' => 'x', 'stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => $texto]], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]];
        };

        $herramienta = ['model' => 'x', 'stop_reason' => 'tool_use', 'content' => [[
            'type' => 'tool_use', 'id' => 'toolu_t153', 'name' => 'proponer_venta', 'input' => $this->input(),
        ]], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]];

        $secuencia = Http::sequence()
            ->push($herramienta, 200)
            ->push($respuesta($mentira), 200)
            ->push($respuesta($mentira), 200)
            ->whenEmpty(Http::response(['error' => ['type' => 'api_error', 'message' => 'agotada t153']], 500));

        Http::fake(['api.deepseek.com/*' => $secuencia, '*' => Http::response(['error' => 'sin stub'], 500)]);

        $conversation = AiConversation::create(['user_id' => $this->dueno->id, 'auth_user_id' => $this->dueno->id]);
        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => '5 kg de cloro zz-t153 con 15 % de descuento, en efectivo a la caja efectivo', 'estado' => 'listo', 'canal' => AiMessage::CANAL_WHATSAPP]);
        $assistant = AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'assistant', 'estado' => 'pendiente', 'acciones_habilitadas' => true, 'canal' => AiMessage::CANAL_WHATSAPP]);

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame(1, AiMessageAction::where('ai_message_id', $assistant->id)->where('tipo', AiMessageAction::TIPO_VENTA)->count(), 'La tarjeta se armó.');
        $this->assertStringNotContainsString($ingenuo, $texto);
        $this->assertStringContainsString(FormatoIaHelper::monto($this->total_de_la_tarjeta()), $texto);

        $bodies = [];
        foreach (Http::recorded() as $par) {
            if (strpos($par[0]->url(), 'api.deepseek.com') !== false) {
                $bodies[] = $par[0]->body();
            }
        }

        $this->assertCount(3, $bodies, 'La herramienta, la respuesta y un solo reintento.');
        $this->assertStringContainsString('no lo recalcules', $bodies[2], 'La nota del reintento dice que el total es el de la tarjeta.');
    }
}
