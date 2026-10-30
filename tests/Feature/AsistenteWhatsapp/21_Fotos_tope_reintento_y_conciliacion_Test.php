<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Events\ChatIaMensajeActualizado;
use App\Http\Controllers\Helpers\asistente_ia\AsistenteImagenHelper;
use App\Jobs\ResponderMensajeChatIaJob;
use App\Models\AiMessage;
use App\Models\AiMessageImagen;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * Misión agente-mejoras-uso-real (E1, puntos 3, 4 y 5, 29/10/2026) — varias fotos por mensaje, el
 * corte por timeout y que una tarea con fotos nunca quede a medias sin aviso.
 *
 * 🔴 EL CASO REAL (Ferre Total, CONV 15, mensaje 91-92). Pablo mandó tres fotos más para los clavos
 * que faltaban y el turno se cortó: «cURL error 28: Operation timed out after 60000 milliseconds» contra
 * DeepSeek. Le llegó «Se me cortó la conexión con el servicio de IA» y nada más: no supo que las fotos
 * habían quedado guardadas ni que no se había cargado ninguna. Lo que fija este archivo:
 *
 *  - el empresa acepta hasta 10 fotos por mensaje (el admin sigue en 3 hasta después del release) y al
 *    modelo le viajan como mucho 6;
 *  - una llamada al proveedor que se cae por la red (o vuelve con un error transitorio) se reintenta
 *    UNA vez, y la segunda ya corta como siempre;
 *  - si el turno falla igual y el mensaje del dueño traía fotos sin usar, el texto de error lo dice
 *    (cuántas, que quedaron guardadas y que con «seguí» continúa), determinista, sin el modelo.
 *
 * PHP 7.4: sin match, sin ?-> y sin str_contains.
 */
class Fotos_tope_reintento_y_conciliacion_Test extends AsistenteWhatsappTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->dar_extension(self::SLUG_ASISTENTE);

        Storage::fake('local');

        config([
            'services.anthropic.api_key'        => 'clave-anthropic-de-prueba-e1',
            'services.anthropic.model'          => 'claude-general-e1',
            'services.anthropic.model_agil'     => 'claude-agil-e1',
            'services.anthropic.model_profundo' => 'claude-profundo-e1',
        ]);

        User::where('id', $this->comercio->id)->update(['agente_proveedor' => 'anthropic', 'agente_pensamiento' => 'agil']);
    }

    /**
     * @param  AiMessage  $mensaje
     * @param  int  $orden
     * @return AiMessageImagen
     */
    protected function guardar_foto(AiMessage $mensaje, $orden = 1)
    {
        $recurso = imagecreatetruecolor(30, 30);
        ob_start();
        imagepng($recurso);
        $binario = ob_get_clean();

        $path = 'asistente_imagenes/' . $this->comercio->id . '/' . $mensaje->id . '/' . $orden . '.webp';

        Storage::disk('local')->put($path, $binario);

        return AiMessageImagen::create([
            'ai_message_id' => $mensaje->id,
            'user_id'       => $this->comercio->id,
            'orden'         => $orden,
            'path'          => $path,
            'mime'          => 'image/webp',
            'bytes'         => strlen($binario),
        ]);
    }

    /**
     * @param  string  $texto
     * @return array
     */
    protected function end_turn($texto = 'Listo.')
    {
        return [
            'model'       => 'lo-que-diga-el-proveedor',
            'stop_reason' => 'end_turn',
            'content'     => [['type' => 'text', 'text' => $texto]],
            'usage'       => ['input_tokens' => 11, 'output_tokens' => 7],
        ];
    }

    /**
     * Una conversación con el mensaje del dueño (con `$fotos` fotos) y el assistant pendiente.
     *
     * @param  int  $fotos
     * @return array{0: \App\Models\AiConversation, 1: AiMessage, 2: array<int, AiMessageImagen>}
     */
    protected function turno($fotos = 0)
    {
        $conversation = $this->conversacion_whatsapp();

        $del_dueno = $this->mensaje($conversation, 'user', 'listo', ['contenido' => 'Estas son las que faltan']);

        $imagenes = [];

        for ($i = 1; $i <= $fotos; $i++) {
            $imagenes[] = $this->guardar_foto($del_dueno, $i);
        }

        $assistant = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        return [$conversation, $assistant, $imagenes];
    }

    // ---------------------------------------------------------------------------- 3 · el tope

    /**
     * Diez fotos en un mensaje entran (se guardan las diez); once siguen siendo 422 sin dejar nada.
     *
     * @test
     */
    public function el_empresa_acepta_hasta_diez_fotos_por_mensaje()
    {
        Queue::fake();

        $diez = [];
        for ($i = 1; $i <= 10; $i++) {
            $diez[] = UploadedFile::fake()->image('foto-' . $i . '.jpg', 400, 400);
        }

        $respuesta = $this->call('POST', 'api/admin-sync/asistente/mensajes', ['texto' => 'Las diez', 'tipo' => 'imagen'], [], ['imagenes' => $diez], $this->transformHeadersToServerVars($this->headers()));

        $respuesta->assertStatus(202);
        $this->assertSame(10, AiMessageImagen::where('user_id', $this->comercio->id)->count());

        $once = $diez;
        $once[] = UploadedFile::fake()->image('foto-11.jpg', 400, 400);

        $de_mas = $this->call('POST', 'api/admin-sync/asistente/mensajes', ['texto' => 'Once', 'tipo' => 'imagen'], [], ['imagenes' => $once], $this->transformHeadersToServerVars($this->headers()));

        $de_mas->assertStatus(422);
        $this->assertSame(10, AiMessageImagen::where('user_id', $this->comercio->id)->count(), 'El 422 no deja ninguna foto más.');
        $this->assertSame(6, AsistenteImagenHelper::MAX_FOTOS_AL_MODELO);
    }

    // ------------------------------------------------------------------------- 4 · el reintento

    /**
     * 🔴 CONV 15, mensaje 92: el cURL 28 de la primera llamada se reintenta una vez y la respuesta
     * llega igual.
     *
     * @test
     */
    public function un_timeout_del_proveedor_se_reintenta_una_vez()
    {
        $llamadas = 0;
        $fin = $this->end_turn('Me faltan 4.');

        Http::fake(function ($request) use (&$llamadas, $fin) {
            $llamadas++;

            if ($llamadas === 1) {
                throw new ConnectionException('cURL error 28: Operation timed out after 60000 milliseconds with 0 bytes received');
            }

            return Http::response($fin, 200);
        });

        list($conversation, $assistant) = $this->turno();

        $this->assertSame('Me faltan 4.', (new AsistenteIaService())->responder($conversation, $assistant));
        $this->assertSame(2, $llamadas, 'Una llamada que se cae, y UN reintento.');
    }

    /**
     * Un error transitorio (529 de Anthropic) también se reintenta una vez.
     *
     * @test
     */
    public function un_error_transitorio_se_reintenta_una_vez()
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push(['error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']], 529)
                ->push($this->end_turn('Listo, ya está.'), 200),
        ]);

        list($conversation, $assistant) = $this->turno();

        $this->assertSame('Listo, ya está.', (new AsistenteIaService())->responder($conversation, $assistant));
        Http::assertSentCount(2);
    }

    /**
     * Y es UNO solo: si la segunda también se cae, el turno corta como antes (sin una tercera).
     *
     * @test
     */
    public function el_reintento_es_uno_solo()
    {
        $llamadas = 0;

        Http::fake(function ($request) use (&$llamadas) {
            $llamadas++;

            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        list($conversation, $assistant) = $this->turno();

        try {
            (new AsistenteIaService())->responder($conversation, $assistant);
            $this->fail('Con dos cortes seguidos el turno tiene que cortar.');
        } catch (ConnectionException $e) {
            $this->assertSame(2, $llamadas);
        }
    }

    // ---------------------------------------------------------------------- 5 · la conciliación

    /**
     * 🔴 El turno se cae con tres fotos sin usar en el mensaje del dueño: el texto de error le dice que
     * llegaron, que quedaron guardadas y que con «seguí» continúa.
     *
     * @test
     */
    public function si_el_turno_falla_con_fotos_sin_usar_el_error_lo_dice()
    {
        Event::fake([ChatIaMensajeActualizado::class]);

        Http::fake(function ($request) {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        list($conversation, $assistant) = $this->turno(3);

        (new ResponderMensajeChatIaJob($assistant->id))->handle();

        $assistant->refresh();

        $this->assertSame('error', $assistant->estado);
        $this->assertStringStartsWith(ResponderMensajeChatIaJob::CONTENIDO_ERROR_AMIGABLE, (string) $assistant->contenido, 'El texto de siempre va primero.');
        $this->assertStringContainsString('Me llegaron 3 fotos y quedaron guardadas', (string) $assistant->contenido);
        $this->assertStringContainsString('«seguí»', (string) $assistant->contenido);
    }

    /**
     * Sin fotos (o con las fotos ya usadas) el texto de error es exactamente el de siempre.
     *
     * @test
     */
    public function sin_fotos_pendientes_el_error_es_el_de_siempre()
    {
        Event::fake([ChatIaMensajeActualizado::class]);

        Http::fake(function ($request) {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        list($conversation, $assistant) = $this->turno();

        (new ResponderMensajeChatIaJob($assistant->id))->handle();

        $this->assertSame(ResponderMensajeChatIaJob::CONTENIDO_ERROR_AMIGABLE, (string) $assistant->fresh()->contenido);

        list($otra, $con_usadas, $fotos) = $this->turno(2);

        foreach ($fotos as $foto) {
            $foto->gestionada_at = now();
            $foto->save();
        }

        (new ResponderMensajeChatIaJob($con_usadas->id))->handle();

        $this->assertSame(ResponderMensajeChatIaJob::CONTENIDO_ERROR_AMIGABLE, (string) $con_usadas->fresh()->contenido);
    }

    /**
     * Una sola foto se dice en singular.
     *
     * @test
     */
    public function una_sola_foto_va_en_singular()
    {
        Event::fake([ChatIaMensajeActualizado::class]);

        Http::fake(function ($request) {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        list($conversation, $assistant) = $this->turno(1);

        (new ResponderMensajeChatIaJob($assistant->id))->handle();

        $this->assertStringContainsString('Me llegó la foto y quedó guardada', (string) $assistant->fresh()->contenido);
    }

    /**
     * Las reglas del prompt: cerrar una tarea de varios ítems diciendo qué quedó y qué no, y usar las
     * fotos que llegan para una tarea en curso.
     *
     * @test
     */
    public function el_prompt_trae_la_regla_de_cerrar_las_tareas_de_varios_items()
    {
        $prompt = (new AsistenteIaService())->build_system_prompt($this->conversacion_whatsapp(), $this->comercio, true, true);

        $this->assertStringContainsString('cuáles quedaron hechos y cuáles no', $prompt);
        $this->assertStringContainsString('para una tarea que está en curso', $prompt);
    }
}
