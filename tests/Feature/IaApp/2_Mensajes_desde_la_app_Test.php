<?php

namespace Tests\Feature\IaApp;

use App\Http\Controllers\AdminSync\AsistenteController;
use App\Models\AiMessage;
use App\Models\AiMessageImagen;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Misión app-ia-y-memoria — el mensaje que manda la app (contrato C5, §4 E1.3 y E1.4 del plan).
 *
 * Lo que protege este archivo:
 *   1. Con un token `ia-app`, los dos mensajes del turno nacen con canal `app`; con la cookie del
 *      sistema, con canal `sistema` como siempre.
 *   2. El selector de modelo de la app manda SOLO en ese turno: `rapido` → DeepSeek ágil y
 *      `pensamiento` → DeepSeek profundo, aunque el dueño tenga otra cosa configurada; sin clave de
 *      DeepSeek, lo de siempre.
 *   3. Fotos por el MISMO camino que WhatsApp (ai_message_imagenes, webp); PDF, Excel y CSV con su texto
 *      adentro del turno del modelo; audio transcripto por OpenAI ANTES de llamar al modelo, y si la
 *      transcripción falla, la respuesta fija de WhatsApp sin gastar una llamada a la IA.
 *   4. Las validaciones con `code`, el contenido vacío guardado como '' (nunca null) y los binarios de
 *      audio y archivos con la tenencia de siempre.
 *
 * 🔴 Ningún test sale a la red: DeepSeek y OpenAI con Http::fake, claves de prueba.
 */
class Mensajes_desde_la_app_Test extends IaAppTestCase
{
    /** El endpoint compatible con Anthropic de DeepSeek. */
    const URL_DEEPSEEK = 'https://api.deepseek.com/anthropic/v1/messages';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /**
     * Claves y modelos de prueba (ids inventados: las aserciones prueban el cableado, no el default).
     *
     * @param  bool  $con_deepseek
     * @return void
     */
    protected function claves($con_deepseek = true)
    {
        config([
            'services.anthropic.api_key'        => 'clave-anthropic-de-prueba',
            'services.anthropic.model'          => 'claude-general-test',
            'services.anthropic.model_agil'     => 'claude-agil-test',
            'services.anthropic.model_profundo' => 'claude-profundo-test',
            'services.deepseek.api_key'         => $con_deepseek ? 'clave-deepseek-de-prueba' : null,
            'services.deepseek.model'           => 'deepseek-general-test',
            'services.deepseek.model_agil'      => 'deepseek-flash-test',
            'services.deepseek.model_profundo'  => 'deepseek-pro-test',
            'services.deepseek.model_vision'    => 'deepseek-vision-test',
            'services.openai.api_key'           => 'clave-openai-de-prueba',
        ]);
    }

    /**
     * Los dos proveedores contestan end_turn; OpenAI contesta la transcripción dada (o un 500).
     *
     * @param  string|null  $transcripcion  null = OpenAI falla.
     * @return void
     */
    protected function fakes($transcripcion = 'cuánto vendí hoy')
    {
        $end_turn = [
            'stop_reason' => 'end_turn',
            'content'     => [['type' => 'text', 'text' => 'Hoy vendiste 120.000 pesos.']],
            'usage'       => ['input_tokens' => 11, 'output_tokens' => 7],
        ];

        Http::fake([
            'api.deepseek.com/*'  => Http::response($end_turn, 200),
            'api.anthropic.com/*' => Http::response($end_turn, 200),
            'api.openai.com/*'    => is_null($transcripcion)
                ? Http::response(['error' => ['message' => 'caído']], 500)
                : Http::response(['text' => $transcripcion, 'usage' => ['input_tokens' => 5, 'output_tokens' => 4]], 200),
        ]);
    }

    /**
     * Los cuerpos de los pedidos que se mandaron a un host.
     *
     * @param  string  $host
     * @return array<int, array{url: string, body: array}>
     */
    protected function pedidos_a($host)
    {
        $pedidos = [];

        foreach (Http::recorded() as $par) {

            $request = $par[0];

            if (strpos($request->url(), $host) === false) {
                continue;
            }

            $pedidos[] = ['url' => $request->url(), 'body' => json_decode($request->body(), true) ?: []];
        }

        return $pedidos;
    }

    /**
     * El texto de todo lo que viajó en `messages` de un pedido al modelo.
     *
     * @param  array  $body
     * @return string
     */
    protected function texto_de_los_mensajes(array $body)
    {
        $texto = '';

        foreach ($body['messages'] ?? [] as $mensaje) {

            if (is_string($mensaje['content'])) {
                $texto .= "\n" . $mensaje['content'];
                continue;
            }

            foreach ($mensaje['content'] as $bloque) {
                if (($bloque['type'] ?? '') === 'text') {
                    $texto .= "\n" . $bloque['text'];
                }
            }
        }

        return $texto;
    }

    /**
     * POST del mensaje como la app (multipart si hay archivos).
     *
     * @param  int  $conversation_id
     * @param  array  $datos
     * @param  string|null  $token
     * @return \Illuminate\Testing\TestResponse
     */
    protected function mandar($conversation_id, array $datos, $token = null)
    {
        return $this->post('api/ai-conversations/' . $conversation_id . '/messages', $datos, $this->de_la_app($token));
    }

    /**
     * El contenido de una respuesta de archivo (BinaryFileResponse no es un stream para TestResponse).
     *
     * @param  \Illuminate\Testing\TestResponse  $respuesta
     * @return string
     */
    protected function binario_de($respuesta)
    {
        return (string) file_get_contents($respuesta->baseResponse->getFile()->getPathname());
    }

    public function test_con_el_token_de_la_app_los_dos_mensajes_son_del_canal_app()
    {
        $this->claves();
        $this->fakes();

        $conversation = $this->conversacion(['titulo' => 'Ventas']);

        $respuesta = $this->postJson('api/ai-conversations/' . $conversation->id . '/messages', [
            'contenido' => '¿Cuánto vendí hoy?',
            'acciones'  => true,
        ], $this->de_la_app());

        $respuesta->assertStatus(201)
            ->assertJsonPath('user_message.canal', 'app')
            ->assertJsonPath('assistant_message.canal', 'app');

        foreach (['bloques', 'progreso', 'modelo_pedido', 'audio', 'archivos'] as $campo) {
            $this->assertArrayHasKey($campo, $respuesta->json('assistant_message'), 'El mensaje trae ' . $campo . ' (aunque sea null).');
        }

        $assistant = AiMessage::find($respuesta->json('assistant_message.id'));

        $this->assertSame('listo', $assistant->estado);
        $this->assertSame('Hoy vendiste 120.000 pesos.', $assistant->contenido);
        $this->assertNull($assistant->progreso);
    }

    public function test_con_la_cookie_del_sistema_el_canal_sigue_siendo_sistema()
    {
        $this->claves();
        $this->fakes();

        $this->actingAs($this->dueno, 'web');

        $conversation = $this->conversacion(['titulo' => 'Ventas']);

        $this->postJson('api/ai-conversations/' . $conversation->id . '/messages', ['contenido' => 'hola'])
            ->assertStatus(201)
            ->assertJsonPath('user_message.canal', 'sistema')
            ->assertJsonPath('assistant_message.canal', 'sistema');
    }

    public function test_pensamiento_va_a_deepseek_profundo_aunque_el_dueno_tenga_claude_agil()
    {
        $this->claves();
        $this->fakes();

        $this->dueno->agente_proveedor   = 'anthropic';
        $this->dueno->agente_pensamiento = 'agil';
        $this->dueno->save();

        $conversation = $this->conversacion(['titulo' => 'Ventas']);

        $respuesta = $this->postJson('api/ai-conversations/' . $conversation->id . '/messages', [
            'contenido' => 'Analizame las ventas del mes',
            'modelo'    => 'pensamiento',
        ], $this->de_la_app());

        $respuesta->assertStatus(201)->assertJsonPath('assistant_message.modelo_pedido', 'pensamiento');

        $pedidos = $this->pedidos_a('api.deepseek.com');

        $this->assertCount(1, $pedidos, 'El turno fue a DeepSeek, no a Claude.');
        $this->assertSame('deepseek-pro-test', $pedidos[0]['body']['model']);
        $this->assertCount(0, $this->pedidos_a('api.anthropic.com'));

        // Solo ESE turno: la configuración del dueño no se tocó.
        $this->assertSame('anthropic', User::find($this->dueno->id)->agente_proveedor);
    }

    public function test_rapido_va_a_deepseek_agil_aunque_el_dueno_tenga_profundo()
    {
        $this->claves();
        $this->fakes();

        $this->dueno->agente_proveedor   = 'deepseek';
        $this->dueno->agente_pensamiento = 'profundo';
        $this->dueno->save();

        $conversation = $this->conversacion(['titulo' => 'Ventas']);

        $this->postJson('api/ai-conversations/' . $conversation->id . '/messages', [
            'contenido' => '¿cuánto vendí hoy?',
            'modelo'    => 'rapido',
        ], $this->de_la_app())->assertStatus(201);

        $pedidos = $this->pedidos_a('api.deepseek.com');

        $this->assertCount(1, $pedidos);
        $this->assertSame('deepseek-flash-test', $pedidos[0]['body']['model']);
    }

    public function test_sin_clave_de_deepseek_el_modelo_pedido_cae_a_la_configuracion_del_dueno()
    {
        $this->claves(false);
        $this->fakes();

        $this->dueno->agente_proveedor   = 'anthropic';
        $this->dueno->agente_pensamiento = 'agil';
        $this->dueno->save();

        $conversation = $this->conversacion(['titulo' => 'Ventas']);

        $this->postJson('api/ai-conversations/' . $conversation->id . '/messages', [
            'contenido' => 'Analizame las ventas',
            'modelo'    => 'pensamiento',
        ], $this->de_la_app())->assertStatus(201);

        $this->assertCount(0, $this->pedidos_a('api.deepseek.com'));

        $pedidos = $this->pedidos_a('api.anthropic.com');

        $this->assertCount(1, $pedidos);
        $this->assertSame('claude-agil-test', $pedidos[0]['body']['model']);
    }

    public function test_un_modelo_que_no_existe_da_422_con_code()
    {
        $conversation = $this->conversacion();

        $this->postJson('api/ai-conversations/' . $conversation->id . '/messages', [
            'contenido' => 'hola',
            'modelo'    => 'turbo',
        ], $this->de_la_app())
            ->assertStatus(422)
            ->assertJsonPath('code', 'validacion');

        $this->assertSame(0, AiMessage::where('ai_conversation_id', $conversation->id)->count());
    }

    public function test_un_mensaje_sin_nada_da_422_y_uno_con_solo_una_foto_se_guarda_con_contenido_vacio()
    {
        $this->claves();
        $this->fakes();

        $conversation = $this->conversacion(['titulo' => 'Fotos']);

        $this->postJson('api/ai-conversations/' . $conversation->id . '/messages', ['contenido' => ''], $this->de_la_app())
            ->assertStatus(422)
            ->assertJsonPath('code', 'mensaje_vacio');

        $respuesta = $this->mandar($conversation->id, [
            'imagenes' => [UploadedFile::fake()->image('factura.png', 60, 40)],
        ]);

        $respuesta->assertStatus(201);

        $user = AiMessage::find($respuesta->json('user_message.id'));

        $this->assertSame('', $user->contenido, 'Vacío, nunca null: la SPA vieja hace .length sobre el contenido.');
        $this->assertSame('app', $user->canal);
        $this->assertSame('imagen', $user->tipo);

        // El MISMO camino que WhatsApp: una fila en ai_message_imagenes con el webp en el disco local.
        $imagen = AiMessageImagen::where('ai_message_id', $user->id)->first();

        $this->assertNotNull($imagen);
        $this->assertSame('image/webp', $imagen->mime);
        $this->assertSame('asistente_imagenes/' . $this->dueno->id . '/' . $user->id . '/1.webp', $imagen->path);
        Storage::disk('local')->assertExists($imagen->path);

        $this->assertCount(1, $respuesta->json('user_message.imagenes'));

        // La foto le llegó al modelo con visión.
        $pedidos = $this->pedidos_a('api.deepseek.com');
        $this->assertNotEmpty($pedidos);
        $this->assertStringContainsString('"type":"image"', json_encode($pedidos[0]['body']['messages']));
    }

    public function test_mas_de_tres_fotos_o_un_archivo_que_no_se_lee_dan_422_sin_crear_nada()
    {
        $conversation = $this->conversacion();

        $this->mandar($conversation->id, [
            'contenido' => 'mirá',
            'imagenes'  => [
                UploadedFile::fake()->image('1.png'), UploadedFile::fake()->image('2.png'),
                UploadedFile::fake()->image('3.png'), UploadedFile::fake()->image('4.png'),
            ],
        ])->assertStatus(422)->assertJsonPath('code', 'adjunto_invalido');

        $this->mandar($conversation->id, [
            'contenido' => 'mirá',
            'archivos'  => [UploadedFile::fake()->createWithContent('programa.exe', 'MZ binario')],
        ])->assertStatus(422)->assertJsonPath('code', 'adjunto_invalido');

        $this->mandar($conversation->id, [
            'audio' => UploadedFile::fake()->createWithContent('nota.txt', 'no es audio'),
        ])->assertStatus(422)->assertJsonPath('code', 'adjunto_invalido');

        $this->assertSame(0, AiMessage::where('ai_conversation_id', $conversation->id)->count());
    }

    public function test_un_csv_entra_al_turno_del_modelo_con_su_contenido()
    {
        $this->claves();
        $this->fakes();

        $conversation = $this->conversacion(['titulo' => 'Precios']);

        $csv = "codigo;articulo;precio\nA1;Tornillo 6mm;120,50\nA2;Tuerca 8mm;80\n";

        $respuesta = $this->mandar($conversation->id, [
            'contenido' => '¿Qué te parecen estos precios?',
            'archivos'  => [UploadedFile::fake()->createWithContent('precios.csv', $csv)],
        ]);

        $respuesta->assertStatus(201)
            ->assertJsonPath('user_message.archivos.0.nombre', 'precios.csv')
            ->assertJsonPath('user_message.archivos.0.tipo', 'csv')
            ->assertJsonPath('user_message.archivos.0.orden', 1);

        $this->assertArrayNotHasKey('path', $respuesta->json('user_message.archivos.0'), 'La ruta del disco no sale nunca.');
        $this->assertArrayNotHasKey('texto', $respuesta->json('user_message.archivos.0'));

        $texto = $this->texto_de_los_mensajes($this->pedidos_a('api.deepseek.com')[0]['body']);

        $this->assertStringContainsString('Archivo adjunto: precios.csv — contenido:', $texto);
        $this->assertStringContainsString('A1 | Tornillo 6mm | 120,50', $texto);
        $this->assertStringContainsString('¿Qué te parecen estos precios?', $texto);
    }

    public function test_un_excel_entra_con_sus_valores_y_una_formula_con_el_valor_guardado()
    {
        $this->claves();
        $this->fakes();

        $planilla = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $hoja = $planilla->getActiveSheet();
        $hoja->setTitle('Stock');
        $hoja->fromArray([['Artículo', 'Cantidad'], ['Martillo', 4], ['Pinza', 6], ['Total', '=SUM(B2:B3)']]);

        $ruta = tempnam(sys_get_temp_dir(), 'xlsx');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($planilla))->save($ruta);

        $conversation = $this->conversacion(['titulo' => 'Stock']);

        $this->mandar($conversation->id, [
            'contenido' => 'Revisame esto',
            'archivos'  => [new UploadedFile($ruta, 'stock.xlsx', null, null, true)],
        ])->assertStatus(201);

        $texto = $this->texto_de_los_mensajes($this->pedidos_a('api.deepseek.com')[0]['body']);

        $this->assertStringContainsString('Archivo adjunto: stock.xlsx — contenido:', $texto);
        $this->assertStringContainsString('Hoja «Stock»', $texto);
        $this->assertStringContainsString('Martillo | 4', $texto);
        $this->assertStringContainsString('Total | 10', $texto);

        @unlink($ruta);
    }

    public function test_un_pdf_entra_con_su_texto()
    {
        if (!class_exists('Smalot\\PdfParser\\Parser')) {
            $this->markTestSkipped('smalot/pdfparser no está instalado en este checkout.');
        }

        $this->claves();
        $this->fakes();

        $pdf = new \TCPDF();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Write(0, 'Lista de precios Distribuidora Sur');
        $binario = $pdf->Output('', 'S');

        $conversation = $this->conversacion(['titulo' => 'Lista']);

        $this->mandar($conversation->id, [
            'contenido' => 'Leeme esta lista',
            'archivos'  => [UploadedFile::fake()->createWithContent('lista.pdf', $binario)],
        ])->assertStatus(201);

        $texto = $this->texto_de_los_mensajes($this->pedidos_a('api.deepseek.com')[0]['body']);

        $this->assertStringContainsString('Archivo adjunto: lista.pdf — contenido:', $texto);
        $this->assertStringContainsString('Distribuidora Sur', $texto);
    }

    public function test_un_pdf_roto_no_voltea_la_respuesta_y_el_modelo_sabe_que_no_se_pudo_leer()
    {
        $this->claves();
        $this->fakes();

        $conversation = $this->conversacion(['titulo' => 'Lista']);

        $respuesta = $this->mandar($conversation->id, [
            'contenido' => 'Leeme esta lista',
            'archivos'  => [UploadedFile::fake()->createWithContent('roto.pdf', '%PDF-1.4 esto no es un pdf')],
        ]);

        $respuesta->assertStatus(201);

        $this->assertSame('listo', AiMessage::find($respuesta->json('assistant_message.id'))->estado);

        $texto = $this->texto_de_los_mensajes($this->pedidos_a('api.deepseek.com')[0]['body']);

        $this->assertStringContainsString('[Archivo adjunto: roto.pdf — no lo pude leer:', $texto);
    }

    public function test_un_audio_se_transcribe_antes_de_llamar_al_modelo()
    {
        $this->claves();
        $this->fakes('cuánto vendí hoy');

        $conversation = $this->conversacion(['titulo' => 'Audio']);

        $respuesta = $this->mandar($conversation->id, [
            'audio'        => UploadedFile::fake()->createWithContent('nota.webm', 'bytes-de-un-webm'),
            'duracion_seg' => '4.2',
        ]);

        $respuesta->assertStatus(201)
            ->assertJsonPath('user_message.tipo', 'audio')
            ->assertJsonPath('user_message.contenido', '')
            ->assertJsonPath('user_message.audio.transcripcion', 'pendiente');

        $user = AiMessage::find($respuesta->json('user_message.id'));

        $this->assertSame('cuánto vendí hoy', $user->contenido);
        $this->assertSame('lista', $user->audio['transcripcion']);
        Storage::disk('local')->assertExists('asistente_audios/' . $this->dueno->id . '/' . $user->id . '.webm');

        // La transcripción fue a OpenAI con el modelo configurado y en español.
        Http::assertSent(function ($request) {
            if (strpos($request->url(), 'api.openai.com/v1/audio/transcriptions') === false) {
                return false;
            }

            $cuerpo = $request->body();

            return strpos($cuerpo, 'gpt-4o-mini-transcribe') !== false && strpos($cuerpo, 'name="language"') !== false;
        });

        $this->assertStringContainsString('cuánto vendí hoy', $this->texto_de_los_mensajes($this->pedidos_a('api.deepseek.com')[0]['body']));

        $this->como_otro_cliente();

        $this->getJson('api/ai-conversations/' . $conversation->id . '/messages/' . $user->id, $this->de_la_app())
            ->assertStatus(200)
            ->assertJsonPath('model.audio.transcripcion', 'lista')
            ->assertJsonPath('model.audio.duracion_seg', 4.2);
    }

    public function test_si_la_transcripcion_falla_contesta_lo_mismo_que_whatsapp_sin_llamar_al_modelo()
    {
        $this->claves();
        $this->fakes(null);

        $conversation = $this->conversacion(['titulo' => 'Audio']);

        $respuesta = $this->mandar($conversation->id, [
            'audio' => UploadedFile::fake()->createWithContent('nota.ogg', 'bytes-de-un-ogg'),
        ]);

        $respuesta->assertStatus(201);

        $user      = AiMessage::find($respuesta->json('user_message.id'));
        $assistant = AiMessage::find($respuesta->json('assistant_message.id'));

        $this->assertSame(AsistenteController::AUDIO_SIN_TRANSCRIPCION, $user->contenido);
        $this->assertSame('fallida', $user->audio['transcripcion']);
        $this->assertSame('listo', $assistant->estado);
        $this->assertSame(AsistenteController::RESPUESTA_AUDIO_SIN_TRANSCRIPCION, $assistant->contenido);

        $this->assertCount(0, $this->pedidos_a('api.deepseek.com'), 'Un audio sin transcribir no gasta una llamada al modelo.');
    }

    public function test_los_binarios_de_audio_y_archivo_con_la_tenencia_de_siempre()
    {
        $this->claves();
        $this->fakes();

        $conversation = $this->conversacion(['titulo' => 'Binarios']);

        $respuesta = $this->mandar($conversation->id, [
            'contenido' => 'te mando las dos cosas',
            'audio'     => UploadedFile::fake()->createWithContent('nota.webm', 'bytes-del-audio'),
            'archivos'  => [UploadedFile::fake()->createWithContent('precios.csv', "a;b\n1;2\n")],
        ]);

        $respuesta->assertStatus(201);

        $id = $respuesta->json('user_message.id');

        $this->como_otro_cliente();

        $audio = $this->get('api/ai-mensajes/' . $id . '/audio', $this->de_la_app());
        $audio->assertStatus(200);
        $this->assertSame('bytes-del-audio', $this->binario_de($audio));
        $this->assertStringContainsString('private', (string) $audio->headers->get('Cache-Control'));

        $this->como_otro_cliente();

        $archivo = $this->get('api/ai-mensajes/' . $id . '/archivo/1', $this->de_la_app());
        $archivo->assertStatus(200);
        $this->assertSame("a;b\n1;2\n", $this->binario_de($archivo));
        $this->assertStringContainsString('precios.csv', (string) $archivo->headers->get('Content-Disposition'));

        $this->como_otro_cliente();
        $this->get('api/ai-mensajes/' . $id . '/archivo/2', $this->de_la_app())->assertStatus(404);

        // Otro dueño, con su propia app, no ve el audio de este.
        $otro = User::create([
            'name'         => 'Otro dueño',
            'company_name' => 'Otro negocio',
            'email'        => 'ia-app-otro-' . uniqid() . '@test.local',
            'password'     => Hash::make('x'),
        ]);
        $this->dar_extension($otro);
        $token_del_otro = $otro->createToken('ia-app:otro', ['ia-app'])->plainTextToken;

        $this->como_otro_cliente();
        $this->get('api/ai-mensajes/' . $id . '/audio', $this->de_la_app($token_del_otro))->assertStatus(404);
    }

    public function test_el_dictado_del_cuestionario()
    {
        $this->claves();
        $this->fakes('vendemos herramientas y bulonería');

        $this->post('api/ia-app/transcribir', [
            'audio' => UploadedFile::fake()->createWithContent('dictado.webm', 'bytes'),
        ], $this->de_la_app())
            ->assertStatus(200)
            ->assertExactJson(['texto' => 'vendemos herramientas y bulonería']);

        $this->como_otro_cliente();

        $this->post('api/ia-app/transcribir', [
            'audio' => UploadedFile::fake()->createWithContent('dictado.txt', 'no'),
        ], $this->de_la_app())
            ->assertStatus(422)
            ->assertJsonPath('code', 'audio_invalido');

        config(['services.openai.api_key' => '']);

        $this->como_otro_cliente();

        $this->post('api/ia-app/transcribir', [
            'audio' => UploadedFile::fake()->createWithContent('dictado.webm', 'bytes'),
        ], $this->de_la_app())
            ->assertStatus(503)
            ->assertJsonPath('code', 'sin_transcripcion');
    }
}
