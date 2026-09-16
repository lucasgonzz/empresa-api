<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Jobs\InferirTituloConversacionIaJob;
use App\Jobs\ResponderMensajeChatIaJob;
use App\Models\AiConversation;
use App\Models\AiMessage;
use Illuminate\Support\Facades\Queue;

/**
 * Misión asistente-por-whatsapp — las rutas 1 y 2 del contrato (§7 del plan): el 202 del mensaje
 * entrante y el polling con el que el admin espera la respuesta.
 *
 * El contrato es la única pieza que ninguna misión de un solo slot puede ver, así que este archivo
 * verifica la FORMA de las dos respuestas campo por campo, y no solo que el flujo ande.
 */
class Mensajes_Test extends AsistenteWhatsappTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->dar_extension();

        Queue::fake();
    }

    /**
     * @group asistente-whatsapp
     * @test
     */
    public function el_post_devuelve_202_con_los_dos_ids_y_deja_el_assistant_pendiente()
    {
        $respuesta = $this->mandar(['texto' => '¿Cuánto vendí ayer?'])->assertStatus(202);

        $respuesta->assertJsonStructure(['ai_conversation_id', 'ai_message_id', 'estado']);
        $respuesta->assertJson(['estado' => 'pendiente']);

        $assistant = AiMessage::find($respuesta->json('ai_message_id'));

        $this->assertEquals('assistant', $assistant->rol);
        $this->assertEquals('pendiente', $assistant->estado);
        $this->assertNull($assistant->contenido);
        $this->assertEquals(AiMessage::CANAL_WHATSAPP, $assistant->canal);
        $this->assertTrue(
            (bool) $assistant->acciones_habilitadas,
            'Por WhatsApp el asistente siempre puede proponer cargas: no hay consumidor viejo al que dejarle una tarjeta que no pueda resolver.'
        );

        $user = AiMessage::where('ai_conversation_id', $respuesta->json('ai_conversation_id'))
            ->where('rol', 'user')
            ->first();

        $this->assertEquals('¿Cuánto vendí ayer?', $user->contenido);
        $this->assertEquals('listo', $user->estado);
        $this->assertEquals(AiMessage::CANAL_WHATSAPP, $user->canal);
    }

    /**
     * @group asistente-whatsapp
     * @test
     */
    public function el_post_despacha_el_mismo_job_de_respuesta_del_chat()
    {
        $respuesta = $this->mandar()->assertStatus(202);

        $id = (int) $respuesta->json('ai_message_id');

        Queue::assertPushed(ResponderMensajeChatIaJob::class);

        Queue::assertPushed(InferirTituloConversacionIaJob::class);

        $this->assertEquals('pendiente', AiMessage::find($id)->estado);
    }

    /**
     * El título se infiere una sola vez: en el primer mensaje del dueño de una conversación sin
     * título, igual que en la pantalla.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function el_titulo_se_infiere_solo_en_el_primer_mensaje()
    {
        $this->mandar()->assertStatus(202);

        Queue::assertPushed(InferirTituloConversacionIaJob::class, 1);

        $this->mandar(['texto' => 'Otra pregunta'])->assertStatus(202);

        Queue::assertPushed(InferirTituloConversacionIaJob::class, 1);
    }

    /**
     * @group asistente-whatsapp
     * @test
     */
    public function el_wamid_del_entrante_queda_guardado_en_el_mensaje_del_dueno()
    {
        $respuesta = $this->mandar(['whatsapp_message_id' => 'wamid.HBgNNTQ5MTEx'])->assertStatus(202);

        $user = AiMessage::where('ai_conversation_id', $respuesta->json('ai_conversation_id'))
            ->where('rol', 'user')
            ->first();

        $this->assertEquals('wamid.HBgNNTQ5MTEx', $user->whatsapp_message_id);
    }

    /**
     * 🔴 A diferencia del chat de la pantalla, acá NO hay 409 `respuesta_en_curso`: en WhatsApp el
     * mensaje ya se mandó y rebotarlo lo perdería sin que el dueño se entere.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function dos_mensajes_seguidos_no_dan_409_y_los_dos_quedan_guardados()
    {
        $primero = $this->mandar(['texto' => 'Primera'])->assertStatus(202);

        $segundo = $this->mandar(['texto' => 'Segunda'])->assertStatus(202);

        $this->assertEquals(
            (int) $primero->json('ai_conversation_id'),
            (int) $segundo->json('ai_conversation_id'),
            'Los dos caen en la misma conversación: pasaron segundos, no seis horas.'
        );

        $this->assertEquals(
            2,
            AiMessage::where('ai_conversation_id', $primero->json('ai_conversation_id'))
                ->where('rol', 'user')
                ->count(),
            'Ningún mensaje del dueño se puede perder.'
        );
    }

    /**
     * @group asistente-whatsapp
     * @test
     */
    public function el_polling_devuelve_pendiente_listo_y_error_con_la_forma_del_contrato()
    {
        $respuesta = $this->mandar()->assertStatus(202);

        $id = (int) $respuesta->json('ai_message_id');
        $conversacion_id = (int) $respuesta->json('ai_conversation_id');

        $pendiente = $this->getJson('api/admin-sync/asistente/mensajes/' . $id, $this->headers())->assertStatus(200);

        $pendiente->assertJson([
            'estado'             => 'pendiente',
            'contenido'          => null,
            'error_mensaje'      => null,
            'ai_conversation_id' => $conversacion_id,
        ]);

        $assistant = AiMessage::find($id);
        $assistant->estado = 'listo';
        $assistant->contenido = 'Ayer vendiste $ 120.000.';
        $assistant->save();

        $this->getJson('api/admin-sync/asistente/mensajes/' . $id, $this->headers())
            ->assertStatus(200)
            ->assertJson([
                'estado'    => 'listo',
                'contenido' => 'Ayer vendiste $ 120.000.',
            ]);

        $assistant->estado = 'error';
        $assistant->contenido = ResponderMensajeChatIaJob::CONTENIDO_ERROR_AMIGABLE;
        $assistant->error_mensaje = 'timeout de la API';
        $assistant->save();

        $this->getJson('api/admin-sync/asistente/mensajes/' . $id, $this->headers())
            ->assertStatus(200)
            ->assertJson([
                'estado'        => 'error',
                'contenido'     => ResponderMensajeChatIaJob::CONTENIDO_ERROR_AMIGABLE,
                'error_mensaje' => 'timeout de la API',
            ]);
    }

    /**
     * @group asistente-whatsapp
     * @test
     */
    public function un_mensaje_vacio_o_demasiado_largo_es_422()
    {
        $this->mandar(['texto' => '   '])->assertStatus(422);

        $this->mandar(['texto' => str_repeat('a', 4001)])->assertStatus(422);

        $this->assertEquals(
            0,
            AiConversation::where('user_id', $this->comercio->id)->count(),
            'Un 422 de validación no puede dejar una conversación abierta.'
        );
    }

    /**
     * @group asistente-whatsapp
     * @test
     */
    public function un_tipo_que_no_existe_es_422()
    {
        $this->mandar(['tipo' => 'video'])->assertStatus(422);
    }

    /**
     * Los audios llegan ya transcriptos de Kapso (nadie transcribe de este lado): lo único que
     * cambia respecto de un texto es el `tipo`.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function un_audio_transcripto_entra_como_cualquier_mensaje()
    {
        $respuesta = $this->mandar([
            'tipo'  => 'audio',
            'texto' => 'Che, ¿cuánto le debo a Distribuidora Sur?',
        ])->assertStatus(202);

        $user = AiMessage::where('ai_conversation_id', $respuesta->json('ai_conversation_id'))
            ->where('rol', 'user')
            ->first();

        $this->assertEquals('Che, ¿cuánto le debo a Distribuidora Sur?', $user->contenido);
    }
}
