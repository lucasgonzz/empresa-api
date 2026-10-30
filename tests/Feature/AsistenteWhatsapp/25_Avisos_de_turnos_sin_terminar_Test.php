<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Events\ChatIaMensajeActualizado;
use App\Http\Controllers\Helpers\asistente_ia\AvisosDelAsistenteHelper;
use App\Jobs\ResponderMensajeChatIaJob;
use App\Models\AiAvisoDelAsistente;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\AiMessageImagen;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Misión agente-mejoras-uso-real, ronda de arreglos de la revisión independiente (30/10/2026), arreglos
 * 1 y 4 — EL AVISO DE LAS FOTOS SIN CARGAR TIENE QUE LLEGAR POR WHATSAPP, y no puede mentir.
 *
 * 🔴 LO QUE FIJA:
 *   1. Con el mensaje del asistente en `error`, el admin manda su disculpa fija y NO lee el contenido:
 *      la frase «Me llegaron N fotos… escribime "seguí"» nunca le llegaba a Pablo. Ahora sale por el
 *      camino de avisos que el admin ya consulta (avisos-pendientes → avisos/avisado,
 *      `turno_sin_terminar:{uuid}`), una sola vez, y al sellarse queda en la conversación.
 *   4. Si en ese turno una carga SÍ quedó hecha (en "directo" una tarjeta se confirma sola), el aviso
 *      no dice «no llegué a cargarlas»: dice qué quedó hecho y que lo demás no.
 *
 * PHP 7.4: sin match, sin ?-> y sin str_contains.
 */
class Avisos_de_turnos_sin_terminar_Test extends AsistenteWhatsappTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->dar_extension(self::SLUG_ASISTENTE);

        Storage::fake('local');
        Event::fake([ChatIaMensajeActualizado::class]);

        config([
            'services.anthropic.api_key'        => 'clave-anthropic-de-prueba-e1r',
            'services.anthropic.model'          => 'claude-general-e1r',
            'services.anthropic.model_agil'     => 'claude-agil-e1r',
            'services.anthropic.model_profundo' => 'claude-profundo-e1r',
        ]);

        User::where('id', $this->comercio->id)->update(['agente_proveedor' => 'anthropic', 'agente_pensamiento' => 'agil', 'agente_confianza' => 'cauteloso']);

        /* El admin de este dueño anunció que consulta los avisos (lo hace en cada POST de mensajes). */
        AvisosDelAsistenteHelper::anunciar($this->comercio->id, '1', 'https://ferretotal.comerciocity.com');

        Http::fake(function ($request) {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });
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
     * El turno de CONV 15 (mensaje 91): el dueño manda fotos y el assistant pendiente.
     *
     * @param  int  $fotos
     * @param  string  $canal
     * @return array{0: \App\Models\AiConversation, 1: AiMessage, 2: AiMessage, 3: array}
     */
    protected function turno($fotos = 3, $canal = AiMessage::CANAL_WHATSAPP)
    {
        $conversation = $this->conversacion_whatsapp();

        $pedido = $this->mensaje($conversation, 'user', 'listo', ['contenido' => '', 'canal' => $canal]);

        $imagenes = [];

        for ($i = 1; $i <= $fotos; $i++) {
            $imagenes[] = $this->guardar_foto($pedido, $i);
        }

        $assistant = $this->mensaje($conversation, 'assistant', 'pendiente', ['acciones_habilitadas' => true, 'canal' => $canal]);

        return [$conversation, $pedido, $assistant, $imagenes];
    }

    /**
     * @return \Illuminate\Testing\TestResponse
     */
    protected function pendientes()
    {
        return $this->getJson('api/admin-sync/asistente/avisos-pendientes', $this->headers());
    }

    /**
     * 🔴 CONV 15: el turno se corta con tres fotos sin cargar → avisos-pendientes trae el aviso con la
     * frase, y avisos/avisado lo sella y lo deja en la conversación UNA vez.
     *
     * @test
     */
    public function las_fotos_sin_cargar_salen_por_el_camino_de_avisos_una_sola_vez()
    {
        list($conversation, , $assistant) = $this->turno(3);

        (new ResponderMensajeChatIaJob($assistant->id))->handle();

        $this->assertSame('error', $assistant->fresh()->estado);
        $this->assertSame(1, AiAvisoDelAsistente::where('ai_message_id', $assistant->id)->count());

        $avisos = $this->pendientes()->assertStatus(200)->json('avisos');

        $this->assertCount(1, $avisos);
        $this->assertStringStartsWith(AiAvisoDelAsistente::TIPO_TURNO_SIN_TERMINAR . ':', $avisos[0]['id']);
        $this->assertSame(AiAvisoDelAsistente::TIPO_TURNO_SIN_TERMINAR, $avisos[0]['tipo']);
        $this->assertSame((int) $conversation->id, (int) $avisos[0]['ai_conversation_id']);
        $this->assertStringContainsString('Me llegaron 3 fotos y quedaron guardadas', $avisos[0]['texto']);
        $this->assertStringContainsString('«seguí»', $avisos[0]['texto']);
        $this->assertNull($avisos[0]['url'], 'No hay pantalla que abrir: sale sin link.');

        $antes = AiMessage::where('ai_conversation_id', $conversation->id)->count();

        $this->postJson('api/admin-sync/asistente/avisos/avisado', ['id' => $avisos[0]['id'], 'whatsapp_message_id' => 'wamid.turno'], $this->headers())
            ->assertStatus(200)->assertExactJson(['ok' => true]);
        $this->postJson('api/admin-sync/asistente/avisos/avisado', ['id' => $avisos[0]['id']], $this->headers())->assertStatus(200);

        $this->assertSame($antes + 1, AiMessage::where('ai_conversation_id', $conversation->id)->count(), 'Un solo mensaje aunque el admin selle dos veces.');
        $this->assertSame([], $this->pendientes()->json('avisos'), 'Ya avisado, no vuelve a salir.');

        /* Y si el job se volviera a cerrar con error (el failed() de la cola), no se deja otro aviso. */
        (new ResponderMensajeChatIaJob($assistant->id))->failed(new \RuntimeException('worker muerto'));
        $this->assertSame(1, AiAvisoDelAsistente::where('ai_message_id', $assistant->id)->count());
    }

    /**
     * Sin fotos, sin el anuncio del admin o por el canal del sistema, no se deja ningún aviso: el
     * sistema ya muestra el texto, y un aviso que nadie consulta no se promete.
     *
     * @test
     */
    public function sin_fotos_sin_anuncio_o_fuera_de_whatsapp_no_hay_aviso()
    {
        list(, , $sin_fotos) = $this->turno(0);
        (new ResponderMensajeChatIaJob($sin_fotos->id))->handle();
        $this->assertSame(0, AiAvisoDelAsistente::where('ai_message_id', $sin_fotos->id)->count());

        list(, , $del_sistema) = $this->turno(2, AiMessage::CANAL_SISTEMA);
        (new ResponderMensajeChatIaJob($del_sistema->id))->handle();
        $this->assertSame(0, AiAvisoDelAsistente::where('ai_message_id', $del_sistema->id)->count());
        $this->assertStringContainsString('Me llegaron 2 fotos', (string) $del_sistema->fresh()->contenido, 'En el sistema el texto del error lo dice.');

        AvisosDelAsistenteHelper::anunciar($this->comercio->id, null, null);
        list(, , $sin_anuncio) = $this->turno(2);
        (new ResponderMensajeChatIaJob($sin_anuncio->id))->handle();
        $this->assertSame(0, AiAvisoDelAsistente::where('ai_message_id', $sin_anuncio->id)->count());
    }

    /**
     * 🔴 Arreglo 4: si en ese turno una carga quedó confirmada (en "directo"), el aviso no dice «no
     * llegué a cargarlas»: dice qué quedó hecho y que el resto no.
     *
     * @test
     */
    public function si_algo_quedo_hecho_en_el_turno_el_aviso_no_dice_que_no_se_cargo_nada()
    {
        list(, , $assistant, $fotos) = $this->turno(3);

        /* Una de las fotos se usó en un gasto que se confirmó solo en el turno (directo). */
        $fotos[0]->gestionada_at = now();
        $fotos[0]->save();

        AiMessageAction::create([
            'ai_conversation_id' => $assistant->ai_conversation_id,
            'ai_message_id'      => $assistant->id,
            'user_id'            => $this->comercio->id,
            'auth_user_id'       => $this->comercio->id,
            'tipo'               => AiMessageAction::TIPO_GASTO,
            'clave'              => 'gasto:e1r',
            'estado'             => AiMessageAction::ESTADO_CONFIRMADA,
            'datos'              => [],
            'presentacion'       => ['titulo' => 'Gasto', 'renglones' => [], 'aviso' => null],
            'resultado'          => ['texto' => 'Gasto N° 88 registrado por $ 207.500'],
            'resuelta_at'        => now(),
        ]);

        (new ResponderMensajeChatIaJob($assistant->id))->handle();

        $contenido = (string) $assistant->fresh()->contenido;
        $this->assertStringNotContainsString('no llegué a cargarlas', $contenido);
        $this->assertStringContainsString('Gasto N° 88 registrado', $contenido);
        $this->assertStringContainsString('las otras 2 fotos quedaron guardadas sin cargar', $contenido);

        $aviso = AiAvisoDelAsistente::where('ai_message_id', $assistant->id)->first();
        $this->assertNotNull($aviso);
        $this->assertStringContainsString('Gasto N° 88 registrado', $aviso->texto);
        $this->assertStringNotContainsString('no llegué a cargarlas', $aviso->texto);
    }
}
