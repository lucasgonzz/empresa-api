<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Http\Controllers\Helpers\asistente_ia\AvisosDelAsistenteHelper;
use App\Http\Controllers\Helpers\asistente_ia\AvisosDeTurnosIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ImagenPorWhatsappIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ImagenPrometidaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PedidosDeImagenIaHelper;
use App\Models\AiAvisoDelAsistente;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\IaImagenPedido;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Misión asistente-truvari-pedidos-fallidos (C1, 13/11/2026) — LA IMAGEN QUE SE PIDE POR WHATSAPP.
 *
 * Caso real (Truvari, conversación 4): «generá para ese producto una imagen de 6 botellas» por WhatsApp y el
 * asistente contestó «andá a ia.comerciocity.com». Lo que protege este archivo:
 *   1. Con la extensión, una persona que puede usar el asistente y el anuncio de avisos del admin, WhatsApp
 *      tiene pedir_imagen y el prompt dice «te la mando por acá»; sin el anuncio (o con un empleado sin
 *      acceso), lo de antes: la dirección de la app y sin la herramienta.
 *   2. El turno pide la imagen; cuando el admin la entrega, queda UN aviso que sale por avisos-pendientes con
 *      el link público (png), y el sello lo deja en la conversación una sola vez.
 *   3. Si no se pudo generar, el aviso dice que no se pudo (el turno había prometido mandarla).
 *   4. La guarda de la imagen prometida también corre por WhatsApp cuando el turno tiene la herramienta.
 *   5. Una imagen pedida desde la app no deja aviso, y la fila del aviso no sale además como turno sin terminar.
 *
 * 🔴 Nada sale a la red: el modelo y el admin están falsos.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class Imagen_por_whatsapp_Test extends AsistenteWhatsappTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->dar_extension(self::SLUG_ASISTENTE);

        Storage::fake('local');
        Storage::fake('public');

        config([
            'services.deepseek.api_key'        => 'clave-deepseek-de-prueba-c1',
            'services.deepseek.model'          => 'deepseek-general-c1',
            'services.deepseek.model_agil'     => 'deepseek-agil-c1',
            'services.deepseek.model_profundo' => 'deepseek-profundo-c1',
            'services.deepseek.model_vision'   => 'deepseek-vision-c1',
            'services.admin_api.url'           => 'https://admin.c1.test',
            'services.admin_api.inbound_key'   => 'clave-inbound-c1',
            'services.admin_api.client_uuid'   => 'uuid-del-cliente-c1',
            'app.APP_URL'                      => 'https://api-truvari.test',
            'app.VPS'                          => true,
        ]);

        User::where('id', $this->comercio->id)->update(['agente_proveedor' => 'deepseek', 'agente_pensamiento' => 'agil', 'agente_confianza' => 'cauteloso']);
    }

    /** El admin de este dueño anunció que consulta los avisos (lo hace en cada POST de mensajes). */
    protected function anunciar_avisos()
    {
        AvisosDelAsistenteHelper::anunciar($this->comercio->id, '1', 'https://truvari.comerciocity.com');
    }

    protected function end_turn($texto)
    {
        return [
            'stop_reason' => 'end_turn',
            'content'     => [['type' => 'text', 'text' => $texto]],
            'usage'       => ['input_tokens' => 10, 'output_tokens' => 5],
        ];
    }

    protected function pide_la_imagen()
    {
        return [
            'stop_reason' => 'tool_use',
            'content'     => [[
                'type'  => 'tool_use',
                'id'    => 'toolu_' . Str::random(8),
                'name'  => 'pedir_imagen',
                'input' => ['prompt' => 'Caja de 6 botellas de Aguma Carmenere sobre una mesa de madera, fondo claro', 'tamano' => 'cuadrada'],
            ]],
            'usage'       => ['input_tokens' => 10, 'output_tokens' => 5],
        ];
    }

    /**
     * Un turno de WhatsApp con el modelo contestando la secuencia dada.
     *
     * @param  string  $pedido
     * @param  array  $respuestas
     * @return AiMessage  El del asistente, ya contestado.
     */
    protected function turno($pedido, array $respuestas)
    {
        $secuencia = Http::sequence();

        foreach ($respuestas as $respuesta) {
            $secuencia->push($respuesta, 200);
        }

        Http::fake([
            'api.deepseek.com/*' => $secuencia,
            '*'                   => Http::response(['error' => 'host sin stub'], 500),
        ]);

        $conversation = $this->conversacion_whatsapp();
        $this->mensaje($conversation, 'user', 'listo', ['contenido' => $pedido]);
        $assistant = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        $servicio = new AsistenteIaService();
        $assistant->contenido = $servicio->responder($conversation, $assistant);
        $assistant->estado = 'listo';
        $bloques = $servicio->bloques();
        if (count($bloques)) {
            $assistant->bloques = $bloques;
        }
        $assistant->save();

        return $assistant->fresh();
    }

    /** Los cuerpos que se le mandaron al modelo. */
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

    /** Los nombres de las herramientas de un cuerpo. */
    protected function herramientas_de(array $cuerpo)
    {
        $nombres = [];

        foreach (isset($cuerpo['tools']) ? $cuerpo['tools'] : [] as $tool) {
            $nombres[] = isset($tool['name']) ? $tool['name'] : (isset($tool['function']['name']) ? $tool['function']['name'] : '');
        }

        return $nombres;
    }

    protected function png()
    {
        $recurso = imagecreatetruecolor(20, 20);
        ob_start();
        imagepng($recurso);

        return UploadedFile::fake()->createWithContent('imagen.png', ob_get_clean());
    }

    protected function avisos_pendientes()
    {
        $respuesta = $this->getJson('api/admin-sync/asistente/avisos-pendientes', $this->headers());

        $respuesta->assertStatus(200);

        return $respuesta->json('avisos');
    }

    // ------------------------------------------------------------------------------------ 1

    /**
     * 🔴 La condición: con todo, WhatsApp tiene pedir_imagen y el prompt dice «te la mando por acá»; sin el
     * anuncio del admin, la dirección de la app y sin la herramienta (la imagen no llegaría nunca).
     *
     * @group asistente-whatsapp
     * @test
     */
    public function por_whatsapp_tiene_pedir_imagen_solo_si_la_puede_mandar()
    {
        $servicio = new AsistenteIaService();
        $conversation = $this->conversacion_whatsapp();

        $sin_anuncio = array_column($servicio->build_tools(true, true, $this->comercio->fresh(), false, $conversation), 'name');
        $this->assertNotContains('pedir_imagen', $sin_anuncio);
        $this->assertStringContainsString('ia.comerciocity.com', $servicio->build_system_prompt($conversation, $this->comercio->fresh(), true, true));

        $this->anunciar_avisos();

        $tools = $servicio->build_tools(true, true, $this->comercio->fresh(), false, $conversation);
        $nombres = array_column($tools, 'name');
        $this->assertContains('pedir_imagen', $nombres);
        $this->assertNotContains('mostrar_grafico', $nombres, 'Por WhatsApp no se dibujan gráficos.');
        $this->assertNotContains('mostrar_tabla', $nombres);

        $pedir = $tools[array_search('pedir_imagen', $nombres, true)];
        $this->assertStringContainsString('te la mando por acá cuando esté lista', $pedir['description']);
        $this->assertStringNotContainsString('aparece acá', $pedir['description']);

        $prompt = $servicio->build_system_prompt($conversation, $this->comercio->fresh(), true, true);
        $this->assertStringContainsString('pedilas con pedir_imagen', $prompt);
        $this->assertStringContainsString('te la mando por acá cuando esté lista', $prompt);
        $this->assertStringNotContainsString('ia.comerciocity.com', $prompt);

        // El sistema (no WhatsApp) sigue igual: sin pedir_imagen y con la dirección de la app.
        $del_sistema = array_column($servicio->build_tools(true, false, $this->comercio->fresh(), false, $conversation), 'name');
        $this->assertNotContains('pedir_imagen', $del_sistema);
        $this->assertStringContainsString('ia.comerciocity.com', $servicio->build_system_prompt($conversation, $this->comercio->fresh(), true, false));

        // Un empleado sin acceso al asistente: lo de antes.
        $empleado = User::create([
            'name' => 'Empleado sin acceso', 'email' => 'wsp-c1-' . uniqid() . '@test.local', 'password' => Hash::make('x'),
            'owner_id' => $this->comercio->id, 'admin_access' => 0,
        ]);
        $del_empleado = AiConversation::create([
            'user_id' => $this->comercio->id, 'auth_user_id' => $empleado->id, 'origen' => AiConversation::ORIGEN_WHATSAPP, 'last_message_at' => now(),
        ]);
        $this->assertNotContains('pedir_imagen', array_column($servicio->build_tools(true, true, $this->comercio->fresh(), false, $del_empleado), 'name'));
        $this->assertStringContainsString('que se la pida a ellos', $servicio->build_system_prompt($del_empleado, $this->comercio->fresh(), true, true));
    }

    // ------------------------------------------------------------------------------------ 2

    /**
     * 🔴 F11: el turno pide la imagen, y cuando el admin la entrega sale UN aviso con el link público.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function la_imagen_pedida_por_whatsapp_vuelve_como_aviso_con_el_link()
    {
        $this->anunciar_avisos();

        $assistant = $this->turno('generá para ese producto una imagen de 6 botellas', [
            $this->pide_la_imagen(),
            $this->end_turn('La pedí; te la mando por acá cuando esté lista.'),
        ]);

        $this->assertContains('pedir_imagen', $this->herramientas_de($this->pedidos_al_modelo()[0]));
        $this->assertSame('La pedí; te la mando por acá cuando esté lista.', $assistant->contenido);

        $pedido = IaImagenPedido::where('ai_message_id', $assistant->id)->first();
        $this->assertNotNull($pedido, 'La imagen se tiene que pedir por WhatsApp.');
        $this->assertSame(IaImagenPedido::ESTADO_PENDIENTE, $pedido->estado);

        // Mientras no llegue, no hay aviso.
        $this->assertSame([], $this->avisos_pendientes());

        $this->post('api/admin-sync/ia-imagenes/' . $pedido->uuid . '/resultado', ['estado' => 'lista', 'imagen' => $this->png()], $this->headers())
            ->assertStatus(200);

        $avisos = $this->avisos_pendientes();
        $this->assertCount(1, $avisos, 'Un solo aviso: ni repetido como turno sin terminar.');
        $this->assertSame('imagen_por_whatsapp:' . $pedido->uuid, $avisos[0]['id']);
        $this->assertSame('imagen_por_whatsapp', $avisos[0]['tipo']);
        $this->assertSame((int) $assistant->ai_conversation_id, $avisos[0]['ai_conversation_id']);
        $this->assertStringContainsString('Ya está la imagen que me pediste', $avisos[0]['texto']);
        $this->assertSame('', $avisos[0]['ruta']);
        $this->assertStringStartsWith('https://api-truvari.test/storage/ia_imagenes_whatsapp/' . $this->comercio->id . '/', $avisos[0]['url']);
        $this->assertStringEndsWith('.png', $avisos[0]['url']);
        $this->assertStringNotContainsString((string) $pedido->uuid, $avisos[0]['url'], 'El link no se deduce del uuid.');

        $ruta = substr($avisos[0]['url'], strlen('https://api-truvari.test/storage/'));
        Storage::disk('public')->assertExists($ruta);

        // Un segundo resultado del admin (reintento) no deja otro aviso.
        $this->post('api/admin-sync/ia-imagenes/' . $pedido->uuid . '/resultado', ['estado' => 'lista', 'imagen' => $this->png()], $this->headers())
            ->assertStatus(200);
        $this->assertSame(1, AiAvisoDelAsistente::where('tipo', AiAvisoDelAsistente::TIPO_IMAGEN_POR_WHATSAPP)->where('uuid', $pedido->uuid)->count());

        // El sello deja el aviso con el link en la conversación, una sola vez.
        $sello = ['id' => $avisos[0]['id'], 'url' => $avisos[0]['url'], 'whatsapp_message_id' => 'wamid.IMAGEN1'];
        $this->postJson('api/admin-sync/asistente/avisos/avisado', $sello, $this->headers())->assertStatus(200);
        $this->postJson('api/admin-sync/asistente/avisos/avisado', $sello, $this->headers())->assertStatus(200);

        $dejados = AiMessage::where('ai_conversation_id', $assistant->ai_conversation_id)->where('whatsapp_message_id', 'wamid.IMAGEN1')->get();
        $this->assertCount(1, $dejados);
        $this->assertStringContainsString($avisos[0]['url'], $dejados[0]->contenido);

        $this->assertSame([], $this->avisos_pendientes());

        // El id de la imagen no sella un turno ni otro tipo: por otro prefijo da 404.
        $this->postJson('api/admin-sync/asistente/avisos/avisado', ['id' => 'turno_sin_terminar:' . $pedido->uuid], $this->headers())->assertStatus(404);
    }

    // ------------------------------------------------------------------------------------ 3

    /**
     * Si no se pudo generar, el aviso lo dice (el turno prometió mandarla), sin link.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function si_no_se_pudo_generar_el_aviso_lo_dice()
    {
        $this->anunciar_avisos();

        $assistant = $this->turno('haceme una imagen para Instagram del Aguma Carmenere', [
            $this->pide_la_imagen(),
            $this->end_turn('La pedí; te la mando por acá cuando esté lista.'),
        ]);

        $pedido = IaImagenPedido::where('ai_message_id', $assistant->id)->firstOrFail();

        PedidosDeImagenIaHelper::marcar_fallido($pedido, 'el admin informó un error', PedidosDeImagenIaHelper::TOPE_PARA_EL_DUENO);

        $avisos = $this->avisos_pendientes();
        $this->assertCount(1, $avisos);
        $this->assertStringContainsString('No pude generar la imagen que me pediste', $avisos[0]['texto']);
        $this->assertStringContainsString(PedidosDeImagenIaHelper::TOPE_PARA_EL_DUENO, $avisos[0]['texto']);
        $this->assertNull($avisos[0]['url']);
    }

    /**
     * 🔴 D6 de la revisión: si publicar la copia lanza (disco sin permiso), el aviso sale igual, sin link y sin
     * decir «tocá el link»; y si no se puede armar el link público (sin APP_URL), tampoco lo promete.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function si_no_se_puede_publicar_el_aviso_sale_sin_link()
    {
        $this->anunciar_avisos();

        $disco_roto = \Mockery::mock(\Illuminate\Contracts\Filesystem\Filesystem::class);
        $disco_roto->shouldReceive('exists')->andReturn(false);
        $disco_roto->shouldReceive('put')->andThrow(new \RuntimeException('Permission denied'));
        Storage::set('public', $disco_roto);

        $assistant = $this->turno('generá para ese producto una imagen de 6 botellas', [
            $this->pide_la_imagen(),
            $this->end_turn('La pedí; te la mando por acá cuando esté lista.'),
        ]);
        $pedido = IaImagenPedido::where('ai_message_id', $assistant->id)->firstOrFail();

        $this->post('api/admin-sync/ia-imagenes/' . $pedido->uuid . '/resultado', ['estado' => 'lista', 'imagen' => $this->png()], $this->headers())
            ->assertStatus(200);

        $avisos = $this->avisos_pendientes();
        $this->assertCount(1, $avisos, 'El aviso sale aunque no se haya podido publicar la copia.');
        $this->assertNull($avisos[0]['url']);
        $this->assertStringNotContainsString('Tocá el link', $avisos[0]['texto']);
        $this->assertStringContainsString('no pude prepararte el link', $avisos[0]['texto']);
    }

    /**
     * Sin APP_URL no hay link público que armar: el texto no dice «tocá el link».
     *
     * @group asistente-whatsapp
     * @test
     */
    public function sin_url_publica_el_texto_no_promete_el_link()
    {
        $this->anunciar_avisos();
        config(['app.APP_URL' => '', 'app.APP_ENV' => 'production']);

        $assistant = $this->turno('generá para ese producto una imagen de 6 botellas', [
            $this->pide_la_imagen(),
            $this->end_turn('La pedí; te la mando por acá cuando esté lista.'),
        ]);
        $pedido = IaImagenPedido::where('ai_message_id', $assistant->id)->firstOrFail();

        $this->post('api/admin-sync/ia-imagenes/' . $pedido->uuid . '/resultado', ['estado' => 'lista', 'imagen' => $this->png()], $this->headers())
            ->assertStatus(200);

        $avisos = $this->avisos_pendientes();
        $this->assertCount(1, $avisos);
        $this->assertNull($avisos[0]['url']);
        $this->assertStringNotContainsString('Tocá el link', $avisos[0]['texto']);
    }

    // ------------------------------------------------------------------------------------ 4

    /**
     * 🔴 La guarda de la imagen prometida también por WhatsApp: «te la genero y te la mando» sin llamar a
     * pedir_imagen → un reintento con la nota del canal, y si sigue, el texto honesto.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function la_guarda_de_la_imagen_prometida_corre_por_whatsapp()
    {
        $this->anunciar_avisos();

        $assistant = $this->turno('generá para ese producto una imagen de 6 botellas', [
            $this->end_turn('Listo, la imagen se está generando.'),
            $this->end_turn('La imagen ya está en marcha, en un rato la tenés.'),
        ]);

        $cuerpos = $this->pedidos_al_modelo();
        $this->assertCount(2, $cuerpos, 'Un solo reintento.');
        $nota = json_encode(end($cuerpos[1]['messages']), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('NO llamaste a pedir_imagen', $nota);
        $this->assertStringContainsString('se la mandás por acá cuando esté lista', $nota);

        $this->assertSame(ImagenPrometidaIaHelper::TEXTO_HONESTO, $assistant->contenido);
        $this->assertSame(0, IaImagenPedido::where('ai_message_id', $assistant->id)->count());
    }

    /**
     * Y si el reintento la pide, queda pedida con la frase del canal.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function el_reintento_por_whatsapp_termina_pidiendola()
    {
        $this->anunciar_avisos();

        $assistant = $this->turno('generá para ese producto una imagen de 6 botellas', [
            $this->end_turn('Andá a ia.comerciocity.com y pedila desde ahí.'),
            $this->pide_la_imagen(),
            $this->end_turn('La pedí; te la mando por acá cuando esté lista.'),
        ]);

        $this->assertCount(3, $this->pedidos_al_modelo());
        $this->assertSame('La pedí; te la mando por acá cuando esté lista.', $assistant->contenido);
        $this->assertSame(1, IaImagenPedido::where('ai_message_id', $assistant->id)->count());
    }

    /**
     * Sin la herramienta (el admin no anunció los avisos), la guarda no mira: no se le pide al modelo que
     * llame a algo que no tiene.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function sin_la_herramienta_la_guarda_no_mira()
    {
        $assistant = $this->turno('generá para ese producto una imagen de 6 botellas', [
            $this->end_turn('Las imágenes nuevas se hacen desde la app ComercioCity IA.'),
        ]);

        $this->assertCount(1, $this->pedidos_al_modelo());
        $this->assertNotContains('pedir_imagen', $this->herramientas_de($this->pedidos_al_modelo()[0]));
        $this->assertSame('Las imágenes nuevas se hacen desde la app ComercioCity IA.', $assistant->contenido);
    }

    // ------------------------------------------------------------------------------------ 5

    /**
     * Una imagen pedida desde la APP no deja aviso por WhatsApp (ahí aparece en el mensaje).
     *
     * @group asistente-whatsapp
     * @test
     */
    public function la_imagen_de_la_app_no_deja_aviso()
    {
        $this->anunciar_avisos();

        $conversation = AiConversation::create(['user_id' => $this->comercio->id, 'auth_user_id' => $this->comercio->id]);
        $assistant = $this->mensaje($conversation, 'assistant', 'listo', ['canal' => AiMessage::CANAL_APP]);

        $pedido = IaImagenPedido::create([
            'uuid' => (string) Str::uuid(), 'user_id' => $this->comercio->id, 'ai_message_id' => $assistant->id,
            'prompt' => 'Una placa', 'tamano' => 'cuadrada', 'estado' => IaImagenPedido::ESTADO_PENDIENTE,
        ]);

        PedidosDeImagenIaHelper::recibir_resultado($pedido, 'lista', $this->png(), null);

        $this->assertSame(IaImagenPedido::ESTADO_LISTA, $pedido->fresh()->estado);
        $this->assertSame(0, AiAvisoDelAsistente::where('tipo', AiAvisoDelAsistente::TIPO_IMAGEN_POR_WHATSAPP)->count());
        $this->assertNull(ImagenPorWhatsappIaHelper::avisar($pedido->fresh()));
    }

    /**
     * El aviso de un turno sin terminar sigue saliendo una sola vez y la fila de una imagen no lo tapa.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function el_turno_sin_terminar_y_la_imagen_salen_cada_uno_con_su_prefijo()
    {
        $this->anunciar_avisos();

        $conversation = $this->conversacion_whatsapp();
        $assistant = $this->mensaje($conversation, 'assistant', 'listo');

        AiAvisoDelAsistente::create([
            'uuid' => (string) Str::uuid(), 'owner_id' => $this->comercio->id, 'ai_conversation_id' => $conversation->id,
            'ai_message_id' => $assistant->id, 'tipo' => AiAvisoDelAsistente::TIPO_IMAGEN_POR_WHATSAPP, 'texto' => 'Ya está la imagen.',
        ]);

        $turno = AvisosDeTurnosIaHelper::dejar($assistant, 'No llegué a terminar.');
        $this->assertNotNull($turno, 'La imagen del mismo mensaje no cuenta como «ya hay un aviso del turno».');
        $this->assertSame(AiAvisoDelAsistente::TIPO_TURNO_SIN_TERMINAR, $turno->tipo);

        $ids = array_column($this->avisos_pendientes(), 'id');
        sort($ids);
        $this->assertCount(2, $ids);
        $this->assertStringStartsWith('imagen_por_whatsapp:', $ids[0]);
        $this->assertSame('turno_sin_terminar:' . $turno->uuid, $ids[1]);
    }
}
