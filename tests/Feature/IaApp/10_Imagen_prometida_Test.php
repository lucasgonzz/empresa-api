<?php

namespace Tests\Feature\IaApp;

use App\Http\Controllers\Helpers\asistente_ia\ImagenPrometidaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PedidosDeImagenIaHelper;
use App\Jobs\EnviarPedidoDeImagenAlAdminJob;
use App\Models\AiMessage;
use App\Models\IaImagenPedido;
use App\Services\AsistenteIa\AsistenteIaService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Misión app-ia-imagen-prometida (29/10/2026) — la imagen que el texto promete tiene que estar pedida.
 *
 * Caso real: demo3, conversación 13 (Rápido = DeepSeek Flash). A «Haceme una imagen cuadrada para
 * Instagram promocionando el chaleco de polar azul…» el asistente contestó «La imagen la puse en marcha…»
 * y armó una promoción por WhatsApp, sin llamar nunca a pedir_imagen. Lo que protege este archivo:
 *   1. Si el texto promete la imagen (o la persona la pidió) y el turno no llamó a pedir_imagen: UN
 *      reintento con la nota, y si el modelo sigue sin llamarla, el texto honesto.
 *   2. 🔴 Sin falsos positivos: «la foto del artículo» o «la imagen de la categoría» no disparan nada.
 *   3. La regla de no armar envíos que nadie pidió está en el prompt de la app y no en el del sistema ni
 *      el de WhatsApp; la descripción de pedir_imagen pide «la pedí; aparece acá cuando esté lista».
 *   4. Sin la conexión con el admin, la imagen queda no_disponible en el mismo turno y el texto lo dice;
 *      el job deja un warning claro.
 */
class Imagen_prometida_Test extends IaAppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        config([
            'services.deepseek.api_key'        => 'clave-deepseek-de-prueba',
            'services.deepseek.model'          => 'deepseek-general-test',
            'services.deepseek.model_agil'     => 'deepseek-flash-test',
            'services.deepseek.model_profundo' => 'deepseek-pro-test',
            'services.deepseek.model_vision'   => 'deepseek-vision-test',
        ]);
    }

    /**
     * @param  string  $texto
     * @return array
     */
    protected function end_turn($texto)
    {
        return [
            'stop_reason' => 'end_turn',
            'content'     => [['type' => 'text', 'text' => $texto]],
            'usage'       => ['input_tokens' => 10, 'output_tokens' => 5],
        ];
    }

    /**
     * @return array
     */
    protected function pide_la_imagen()
    {
        return [
            'stop_reason' => 'tool_use',
            'content'     => [[
                'type'  => 'tool_use',
                'id'    => 'toolu_' . Str::random(8),
                'name'  => 'pedir_imagen',
                'input' => ['prompt' => 'Placa cuadrada con el chaleco de polar azul, estilo moderno, fondo claro', 'tamano' => 'cuadrada'],
            ]],
            'usage'       => ['input_tokens' => 10, 'output_tokens' => 5],
        ];
    }

    /**
     * Los cuerpos de los pedidos al modelo.
     *
     * @return array<int, array>
     */
    protected function pedidos_al_modelo()
    {
        $cuerpos = [];

        foreach (Http::recorded() as $par) {
            if (strpos($par[0]->url(), 'api.deepseek.com') !== false) {
                $cuerpos[] = json_decode($par[0]->body(), true) ?: [];
            }
        }

        return $cuerpos;
    }

    /**
     * El mensaje de la persona por la app, con el modelo contestando la secuencia dada.
     *
     * @param  string  $pedido
     * @param  array  $respuestas
     * @return \App\Models\AiMessage  El del asistente, ya contestado.
     */
    protected function conversar($pedido, array $respuestas)
    {
        $secuencia = Http::sequence();

        foreach ($respuestas as $respuesta) {
            $secuencia->push($respuesta, 200);
        }

        Http::fake([
            'api.deepseek.com/*'  => $secuencia,
            'admin.ia-app.test/*' => Http::response(['ok' => true, 'estado' => 'pendiente'], 201),
        ]);

        $conversation = $this->conversacion(['titulo' => 'Imagen']);

        $respuesta = $this->postJson('api/ai-conversations/' . $conversation->id . '/messages', [
            'contenido' => $pedido,
            'modelo'    => 'rapido',
        ], $this->de_la_app())->assertStatus(201);

        return AiMessage::find($respuesta->json('assistant_message.id'));
    }

    public function test_si_promete_la_imagen_sin_pedirla_se_reintenta_y_la_pide()
    {
        $assistant = $this->conversar(
            'Haceme una imagen cuadrada para Instagram promocionando el chaleco de polar azul, con estilo moderno y fondo claro',
            [
                $this->end_turn('La imagen la puse en marcha con el chaleco a $27.680,42.'),
                $this->pide_la_imagen(),
                $this->end_turn('La pedí; aparece acá cuando esté lista.'),
            ]
        );

        $cuerpos = $this->pedidos_al_modelo();

        $this->assertCount(3, $cuerpos);
        $this->assertStringContainsString('NO llamaste a pedir_imagen', json_encode(end($cuerpos[1]['messages']), JSON_UNESCAPED_UNICODE));

        $this->assertSame('listo', $assistant->estado);
        $this->assertSame('La pedí; aparece acá cuando esté lista.', $assistant->contenido);
        $this->assertSame(1, IaImagenPedido::where('ai_message_id', $assistant->id)->count());
        $this->assertSame('imagen_generada', $assistant->bloques[0]['tipo']);
    }

    public function test_si_nunca_la_pide_el_texto_es_el_honesto()
    {
        $assistant = $this->conversar(
            'Haceme una imagen cuadrada para Instagram del chaleco',
            [
                $this->end_turn('La imagen la puse en marcha, en unos minutos la tenés.'),
                $this->end_turn('Listo, la imagen se está generando.'),
            ]
        );

        $this->assertCount(2, $this->pedidos_al_modelo(), 'Un solo reintento.');
        $this->assertSame(ImagenPrometidaIaHelper::TEXTO_HONESTO, $assistant->contenido);
        $this->assertSame(0, IaImagenPedido::where('ai_message_id', $assistant->id)->count());
        $this->assertNull($assistant->bloques);
    }

    public function test_si_la_persona_pidio_la_imagen_y_el_modelo_hizo_otra_cosa_se_reintenta()
    {
        $assistant = $this->conversar(
            'Generá una imagen cuadrada del chaleco de polar azul',
            [
                $this->end_turn('Te preparé una promoción por WhatsApp del chaleco para 25 clientes.'),
                $this->pide_la_imagen(),
                $this->end_turn('La pedí; aparece acá cuando esté lista.'),
            ]
        );

        $this->assertCount(3, $this->pedidos_al_modelo());
        $this->assertSame(1, IaImagenPedido::where('ai_message_id', $assistant->id)->count());
    }

    public function test_la_foto_del_articulo_o_la_imagen_de_la_categoria_no_disparan_nada()
    {
        $foto = $this->conversar('Mostrame la foto del artículo Tornillo 6mm', [
            $this->end_turn('Acá tenés la foto del artículo Tornillo 6mm.'),
        ]);

        $this->assertSame('Acá tenés la foto del artículo Tornillo 6mm.', $foto->contenido);
        $this->assertCount(1, $this->pedidos_al_modelo(), 'Sin reintento.');

        Http::swap(new \Tests\Fakes\HttpFactorySinSalida(app('events')));
        $this->como_otro_cliente();

        $categoria = $this->conversar('¿Cuál es la imagen de la categoría Pinturas?', [
            $this->end_turn('La imagen de la categoría Pinturas es la lata azul.'),
        ]);

        $this->assertSame('La imagen de la categoría Pinturas es la lata azul.', $categoria->contenido);
        $this->assertCount(1, $this->pedidos_al_modelo(), 'Sin reintento.');
    }

    public function test_la_regla_de_no_armar_envios_esta_en_la_app_y_no_en_el_sistema_ni_en_whatsapp()
    {
        $servicio = new AsistenteIaService();
        $conversation = $this->conversacion();

        $app      = $servicio->build_system_prompt($conversation, $this->dueno, true, false, true);
        $sistema  = $servicio->build_system_prompt($conversation, $this->dueno, true, false, false);
        $whatsapp = $servicio->build_system_prompt($conversation, $this->dueno, true, true, false);

        $regla = 'Pedir una imagen no autoriza a preparar promociones, envíos ni mensajes a clientes';

        $this->assertStringContainsString($regla, $app);
        $this->assertStringNotContainsString($regla, $sistema);
        $this->assertStringNotContainsString($regla, $whatsapp);

        $descripcion = collect($servicio->build_tools(true, false, $this->dueno, true))->firstWhere('name', 'pedir_imagen')['description'];

        $this->assertStringContainsString('NO autoriza a preparar promociones', $descripcion);
        $this->assertStringContainsString('la pedí; aparece acá cuando esté lista', $descripcion);
        $this->assertStringNotContainsString('unos minutos', $descripcion, 'Sin prometer tiempos.');
    }

    public function test_sin_conexion_con_el_admin_la_imagen_queda_no_disponible_y_el_texto_no_dice_que_se_genera()
    {
        config(['services.admin_api.url' => null]);

        Log::spy();

        $assistant = $this->conversar('Haceme una placa para Instagram del chaleco', [
            $this->pide_la_imagen(),
            $this->end_turn('Listo, la imagen se está generando.'),
        ]);

        $pedido = IaImagenPedido::where('ai_message_id', $assistant->id)->first();

        $this->assertSame('no_disponible', $pedido->estado);
        $this->assertSame('no_disponible', $assistant->bloques[0]['estado']);
        $this->assertSame(PedidosDeImagenIaHelper::NO_DISPONIBLE_PARA_EL_DUENO, $assistant->contenido, 'El texto dice lo que pasó, no que se está generando.');

        // A la herramienta se lo dijo en el mismo turno.
        $cuerpos = $this->pedidos_al_modelo();
        $this->assertStringContainsString('no está disponible en este sistema', json_encode(end($cuerpos[1]['messages']), JSON_UNESCAPED_UNICODE));

        Log::shouldHaveReceived('warning')->withArgs(function ($mensaje) {
            return strpos((string) $mensaje, 'ADMIN_API_URL / ADMIN_API_CLIENT_UUID') !== false;
        });
    }

    public function test_el_job_avisa_en_el_log_cuando_falta_la_conexion_con_el_admin()
    {
        $conversation = $this->conversacion(['titulo' => 'Job']);

        $mensaje = AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'assistant',
            'estado'             => 'listo',
            'contenido'          => 'La pedí.',
            'canal'              => 'app',
        ]);

        $pedido = IaImagenPedido::create([
            'uuid' => (string) Str::uuid(), 'user_id' => $this->dueno->id, 'ai_message_id' => $mensaje->id,
            'prompt' => 'Una placa', 'tamano' => 'cuadrada', 'estado' => 'pendiente',
        ]);

        config(['services.admin_api.client_uuid' => '']);

        Log::spy();
        Http::fake();

        (new EnviarPedidoDeImagenAlAdminJob($pedido->id))->handle();

        $this->assertSame('no_disponible', $pedido->fresh()->estado);
        Http::assertNothingSent();

        Log::shouldHaveReceived('warning')->withArgs(function ($mensaje) {
            return strpos((string) $mensaje, 'esta instalación no tiene la conexión con el admin: ADMIN_API_URL / ADMIN_API_CLIENT_UUID') !== false;
        });
    }
}
