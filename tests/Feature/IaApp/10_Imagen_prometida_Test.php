<?php

namespace Tests\Feature\IaApp;

use App\Http\Controllers\Helpers\asistente_ia\ImagenPrometidaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PedidosDeImagenIaHelper;
use App\Jobs\EnviarPedidoDeImagenAlAdminJob;
use App\Models\AiConversation;
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
 *
 * Corrección del revisor (NO APTO a c49fb2e5), lo que se agrega acá (las frases sueltas están en
 * 11_Imagen_prometida_frases_Test):
 *   5. Un ofrecimiento («¿querés que te arme una placa?») o una pregunta por lo que falta no disparan
 *      nada; un gráfico, un diseño de PDF, un presupuesto o una historia de compras tampoco.
 *   6. Se cambian SOLO las oraciones que prometen la imagen: los datos de la respuesta quedan.
 *   7. «La pedí; aparece acá» cuenta si la persona pidió la imagen en este turno o en el anterior, y
 *      no cuenta si la vuelta anterior ya la había pedido de verdad.
 *   8. El mismo texto por el sistema o por WhatsApp queda intacto (la guarda es solo de la app).
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
        return $this->conversar_en($this->conversacion(['titulo' => 'Imagen']), $pedido, $respuestas);
    }

    /**
     * Como conversar(), en una conversación que ya tiene historia.
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  string  $pedido
     * @param  array  $respuestas
     * @return \App\Models\AiMessage
     */
    protected function conversar_en(AiConversation $conversation, $pedido, array $respuestas)
    {
        $secuencia = Http::sequence();

        foreach ($respuestas as $respuesta) {
            $secuencia->push($respuesta, 200);
        }

        Http::fake([
            'api.deepseek.com/*'  => $secuencia,
            'admin.ia-app.test/*' => Http::response(['ok' => true, 'estado' => 'pendiente'], 201),
        ]);

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

    /**
     * Lo que el revisor encontró disparando en c49fb2e5: ofrecimientos, gráficos, diseños de PDF,
     * presupuestos e historias de compras. Ninguno reintenta ni cambia el texto.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public function respuestas_que_no_se_tocan()
    {
        return [
            'ofrecimiento con datos'  => ['¿Cuánto vendí hoy?', 'Vendiste $80.000 hoy. ¿Querés que te arme una placa para Instagram con el chaleco?'],
            'ofrecimiento con si'     => ['¿Vendí mucho hoy?', 'Hoy vendiste $120.000. Si querés, te genero una imagen para Instagram con el chaleco.'],
            'gráfica de ventas'       => ['haceme una gráfica de las ventas del mes', 'Te armé la gráfica de ventas del mes: subieron un 20%.'],
            'diseño de factura'       => ['necesito un diseño de factura con mi logo', 'Te preparé el diseño nuevo de la factura con tu logo: confirmalo en la tarjeta.'],
            'presupuesto con imagen'  => ['¿me hacés el presupuesto con la imagen?', 'Te armé el presupuesto con la imagen del chaleco.'],
            'historia de compras'     => ['me hacés la historia de compras de Juan?', 'Te hice la historia de compras de Juan: compró 4 veces este año.'],
            'venta con el comprobante' => ['haceme una venta a Juan y mandale la imagen del comprobante', 'Necesito saber qué artículos lleva Juan.'],
        ];
    }

    /**
     * @dataProvider respuestas_que_no_se_tocan
     */
    public function test_ofrecimientos_graficos_disenos_y_presupuestos_no_disparan_nada($pedido, $texto)
    {
        $assistant = $this->conversar($pedido, [$this->end_turn($texto)]);

        $this->assertCount(1, $this->pedidos_al_modelo(), 'Sin reintento.');
        $this->assertSame($texto, $assistant->contenido);
    }

    public function test_si_pide_la_imagen_y_el_modelo_pregunta_lo_que_falta_no_se_reintenta()
    {
        $texto = 'Dale. ¿La querés cuadrada o vertical, y qué producto tiene que mostrar?';

        $assistant = $this->conversar('Haceme una imagen para Instagram', [$this->end_turn($texto)]);

        $this->assertCount(1, $this->pedidos_al_modelo(), 'Preguntar lo que falta es lo correcto.');
        $this->assertSame($texto, $assistant->contenido);
        $this->assertSame(0, IaImagenPedido::where('ai_message_id', $assistant->id)->count());
    }

    public function test_una_respuesta_con_datos_y_la_imagen_falsa_conserva_los_datos()
    {
        $assistant = $this->conversar('Haceme una imagen para Instagram del chaleco', [
            $this->end_turn('Vendiste $50.000 hoy. La imagen la estoy generando.'),
            $this->end_turn('Vendiste $50.000 hoy. La imagen se está generando.'),
        ]);

        $this->assertCount(2, $this->pedidos_al_modelo(), 'Un solo reintento.');
        $this->assertSame("Vendiste \$50.000 hoy.\n\n" . ImagenPrometidaIaHelper::TEXTO_HONESTO, $assistant->contenido);
    }

    public function test_la_pedi_cuenta_si_la_imagen_se_pidio_en_el_turno_anterior()
    {
        $conversation = $this->conversacion(['titulo' => 'Imagen en dos vueltas']);

        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'estado' => 'listo', 'canal' => 'app',
            'contenido' => 'Haceme una imagen para Instagram del chaleco']);
        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'assistant', 'estado' => 'listo', 'canal' => 'app',
            'contenido' => '¿La querés cuadrada o vertical?']);

        $assistant = $this->conversar_en($conversation, 'cuadrada', [
            $this->end_turn('La pedí; aparece acá cuando esté lista.'),
            $this->pide_la_imagen(),
            $this->end_turn('La pedí; aparece acá cuando esté lista.'),
        ]);

        $this->assertCount(3, $this->pedidos_al_modelo(), 'Sin la herramienta, «la pedí» no era verdad: reintento.');
        $this->assertSame(1, IaImagenPedido::where('ai_message_id', $assistant->id)->count());
        $this->assertSame('La pedí; aparece acá cuando esté lista.', $assistant->contenido);
    }

    public function test_si_la_vuelta_anterior_ya_la_pidio_lo_que_se_diga_de_la_imagen_no_se_toca()
    {
        $conversation = $this->conversacion(['titulo' => 'Imagen ya pedida']);

        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'estado' => 'listo', 'canal' => 'app',
            'contenido' => 'Haceme una imagen para Instagram del chaleco']);
        $anterior = AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'assistant', 'estado' => 'listo', 'canal' => 'app',
            'contenido' => 'La pedí; aparece acá cuando esté lista.']);
        IaImagenPedido::create([
            'uuid' => (string) Str::uuid(), 'user_id' => $this->dueno->id, 'ai_message_id' => $anterior->id,
            'prompt' => 'Placa del chaleco', 'tamano' => 'cuadrada', 'estado' => 'pendiente',
        ]);

        $texto = 'Vendiste $50.000 hoy. La imagen se está generando.';

        $assistant = $this->conversar_en($conversation, '¿Cuánto vendí hoy?', [$this->end_turn($texto)]);

        $this->assertCount(1, $this->pedidos_al_modelo(), 'Habla de la imagen que ya se pidió.');
        $this->assertSame($texto, $assistant->contenido);
    }

    public function test_sin_conexion_la_pedi_se_corrige_con_el_bloque_y_los_datos_quedan()
    {
        config(['services.admin_api.url' => null]);

        $assistant = $this->conversar('Haceme una placa para Instagram del chaleco', [
            $this->pide_la_imagen(),
            $this->end_turn('Vendiste $50.000 hoy. La pedí; aparece acá cuando esté lista.'),
        ]);

        $this->assertSame('no_disponible', $assistant->bloques[0]['estado']);
        $this->assertSame("Vendiste \$50.000 hoy.\n\n" . PedidosDeImagenIaHelper::NO_DISPONIBLE_PARA_EL_DUENO, $assistant->contenido);
    }

    public function test_si_usa_un_grafico_la_guarda_no_reintenta()
    {
        $grafico = [
            'stop_reason' => 'tool_use',
            'content'     => [[
                'type'  => 'tool_use',
                'id'    => 'toolu_' . Str::random(8),
                'name'  => 'mostrar_grafico',
                'input' => [
                    'tipo'       => 'barras',
                    'titulo'     => 'Ventas de la semana',
                    'categorias' => ['Lun', 'Mar', 'Mié'],
                    'series'     => [['nombre' => 'Ventas', 'datos' => [1200.5, 980, 300]]],
                ],
            ]],
            'usage'       => ['input_tokens' => 10, 'output_tokens' => 5],
        ];

        $assistant = $this->conversar('Haceme una imagen con las ventas de la semana', [
            $grafico,
            $this->end_turn('Listo, te armé la imagen de las ventas de la semana.'),
        ]);

        $this->assertCount(2, $this->pedidos_al_modelo(), 'Usó el gráfico: sin reintento.');
        $this->assertSame('Listo, te armé la imagen de las ventas de la semana.', $assistant->contenido);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function canales_sin_la_guarda()
    {
        return ['sistema' => ['sistema'], 'whatsapp' => ['whatsapp']];
    }

    /**
     * 🔴 La guarda es solo de la app (es el único canal con pedir_imagen): el mismo pedido y el mismo
     * texto por el sistema o por WhatsApp quedan tal cual, en una sola vuelta.
     *
     * @dataProvider canales_sin_la_guarda
     */
    public function test_el_mismo_texto_por_el_sistema_o_por_whatsapp_queda_intacto($canal)
    {
        $this->dueno->agente_proveedor   = 'deepseek';
        $this->dueno->agente_pensamiento = 'agil';
        $this->dueno->save();

        $texto = 'La imagen la puse en marcha con el chaleco a $27.680,42.';

        Http::fake([
            'api.deepseek.com/*' => Http::sequence()->push($this->end_turn($texto), 200)->push($this->end_turn('Otra cosa.'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        $conversation = $this->conversacion(['titulo' => 'Por ' . $canal]);

        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'estado' => 'listo', 'canal' => $canal,
            'contenido' => 'Haceme una imagen cuadrada para Instagram promocionando el chaleco de polar azul']);
        $assistant = AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'assistant', 'estado' => 'pendiente', 'canal' => $canal]);

        $respuesta = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame($texto, $respuesta);
        $this->assertCount(1, $this->pedidos_al_modelo(), 'Sin reintento fuera de la app.');
    }
}
