<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Http\Controllers\Helpers\asistente_ia\TopeDeTokensHelper;
use App\Jobs\ResponderMensajeChatIaJob;
use App\Models\AiMessage;
use App\Models\AiTokenUsage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;

/**
 * Misión foto-sucursal-y-asistente-configurable — el plan que empuja el admin y el corte por tope en
 * el canal de WhatsApp.
 *
 * Protege: el receptor PUT admin-sync/plan-ia guarda los tres campos del contrato en el dueño (y un
 * tope 0 se guarda como "sin tope"), exige la clave cuando está configurada, y el canal de WhatsApp
 * contesta con el texto de límite sin despachar el job cuando el dueño superó su tope.
 *
 * 🔴 Misión agente-memoria-y-sin-techo (30/10/2026), CONTRATO CAMBIADO A PROPÓSITO: el tope diario se
 * cuenta por RESPUESTAS del agente (no por filas `chat_mensaje`) y su corte dice «mensajes de hoy»
 * (MENSAJE_LIMITE_DIARIO), no «de este mes». El caso real fue justamente por WhatsApp: Pablo (Ferre
 * Total) leyó «de este mes» el 29/10 a las 23:51 y al otro día le andaba.
 *
 * 🔴 Ronda de arreglos de la revisión independiente (R3, 30/10/2026), CONTRATO CAMBIADO A PROPÓSITO: cada
 * respuesta se cuenta por sus filas `chat_mensaje` con su mensaje en `referencia_id` (una fila sin mensaje,
 * de antes del cambio, cuenta una): las respuestas sembradas llevan esas filas (respuesta_con_vueltas()), y
 * borrar la conversación no baja el contador.
 */
class Plan_ia_y_corte_Test extends AsistenteWhatsappTestCase
{
    /** @test */
    public function el_receptor_guarda_el_plan_en_el_dueno()
    {
        $r = $this->putJson('api/admin-sync/plan-ia', [
            'nombre'                     => 'Intermedio',
            'tope_tokens_mensual'        => 60000000,
            'tope_interacciones_diarias' => 50,
        ], $this->headers());

        $r->assertStatus(200)->assertJson(['ok' => true]);

        $dueno = $this->comercio->fresh();
        $this->assertSame('Intermedio', (string) $dueno->plan_ia_nombre);
        $this->assertSame(60000000, (int) $dueno->plan_ia_tope_tokens_mensual);
        $this->assertSame(50, (int) $dueno->plan_ia_tope_interacciones_diarias);
    }

    /** @test */
    public function un_tope_cero_se_guarda_como_sin_tope()
    {
        $this->putJson('api/admin-sync/plan-ia', [
            'nombre'                     => 'Sin límite',
            'tope_tokens_mensual'        => 0,
            'tope_interacciones_diarias' => 0,
        ], $this->headers())->assertStatus(200);

        $dueno = $this->comercio->fresh();
        $this->assertNull($dueno->plan_ia_tope_tokens_mensual);
        $this->assertNull($dueno->plan_ia_tope_interacciones_diarias);
    }

    /** @test */
    public function sin_la_clave_el_receptor_corta_con_401()
    {
        $this->putJson('api/admin-sync/plan-ia', [
            'nombre'                     => 'Intermedio',
            'tope_tokens_mensual'        => 100,
            'tope_interacciones_diarias' => 5,
        ], $this->headers(false))->assertStatus(401);
    }

    /** @test */
    public function el_canal_de_whatsapp_corta_por_tope_sin_despachar_el_job()
    {
        Queue::fake();
        $this->dar_extension(self::SLUG_ASISTENTE);

        $this->comercio->plan_ia_tope_interacciones_diarias = 3;
        $this->comercio->save();

        /*
         * Tres respuestas del agente de hoy (con dos vueltas del loop cada una: las filas `chat_mensaje`
         * ya no son la unidad, ver TopeDeTokensHelper; desde R3 cada fila lleva su mensaje).
         */
        $hilo = $this->conversacion_whatsapp(now()->subDay());

        for ($i = 0; $i < 3; $i++) {
            $this->respuesta_con_vueltas($hilo, $i, 2);
        }

        $this->mandar(['texto' => '¿Cómo venís hoy?', 'whatsapp_message_id' => 'wamid.tope.' . uniqid()])
             ->assertStatus(202);

        $assistant = AiMessage::where('rol', 'assistant')
            ->where('canal', AiMessage::CANAL_WHATSAPP)
            ->orderBy('id', 'DESC')
            ->first();

        $this->assertNotNull($assistant);
        $this->assertSame('listo', (string) $assistant->estado);
        $this->assertSame(TopeDeTokensHelper::MENSAJE_LIMITE_DIARIO, (string) $assistant->contenido);

        Queue::assertNotPushed(ResponderMensajeChatIaJob::class);
    }

    /**
     * Con 2 respuestas de 3 permitidas —aunque el loop haya grabado muchas filas `chat_mensaje`— el canal
     * de WhatsApp NO corta: el tope cuenta respuestas, no vueltas (misión agente-memoria-y-sin-techo).
     *
     * @test
     */
    public function el_canal_de_whatsapp_no_corta_por_las_vueltas_del_loop()
    {
        Queue::fake();
        $this->dar_extension(self::SLUG_ASISTENTE);

        $this->comercio->plan_ia_tope_interacciones_diarias = 3;
        $this->comercio->save();

        $hilo = $this->conversacion_whatsapp(now()->subDay());

        // Dos respuestas de 8 vueltas cada una: 16 filas `chat_mensaje`, 2 interacciones.
        for ($i = 0; $i < 2; $i++) {
            $this->respuesta_con_vueltas($hilo, $i, 8);
        }

        $this->mandar(['texto' => '¿Cómo venís hoy?', 'whatsapp_message_id' => 'wamid.tope.' . uniqid()])
             ->assertStatus(202);

        Queue::assertPushed(ResponderMensajeChatIaJob::class);
    }

    /**
     * 🔴 Ronda de arreglos de la revisión independiente (R3, 30/10/2026): borrar la conversación desde el
     * panel (AiConversationController::destroy borra los mensajes en duro) NO baja el contador del día: el
     * WhatsApp siguiente igual corta. Antes de R3 el tope contaba ai_messages y borrar devolvía las
     * interacciones.
     *
     * @test
     */
    public function borrar_la_conversacion_no_baja_el_contador_del_whatsapp()
    {
        Queue::fake();
        $this->dar_extension(self::SLUG_ASISTENTE);

        $this->comercio->plan_ia_tope_interacciones_diarias = 3;
        $this->comercio->save();

        $hilo = $this->conversacion_whatsapp(now()->subDay());

        for ($i = 0; $i < 3; $i++) {
            $this->respuesta_con_vueltas($hilo, $i, 2);
        }

        $this->actingAs($this->comercio, 'sanctum')
             ->deleteJson('api/ai-conversations/' . $hilo->id)
             ->assertStatus(200);

        $this->assertSame(0, AiMessage::where('ai_conversation_id', $hilo->id)->count(), 'La conversación se borró con sus mensajes.');
        $this->assertSame(3, TopeDeTokensHelper::estado($this->comercio->fresh())['consumo_interacciones']);

        $this->mandar(['texto' => '¿Cómo venís hoy?', 'whatsapp_message_id' => 'wamid.tope.' . uniqid()])
             ->assertStatus(202);

        $assistant = AiMessage::where('rol', 'assistant')
            ->where('canal', AiMessage::CANAL_WHATSAPP)
            ->orderBy('id', 'DESC')
            ->first();

        $this->assertNotNull($assistant);
        $this->assertSame(TopeDeTokensHelper::MENSAJE_LIMITE_DIARIO, (string) $assistant->contenido);
        Queue::assertNotPushed(ResponderMensajeChatIaJob::class);
    }

    /**
     * Una respuesta del agente de hoy en el hilo: el mensaje de la persona, la respuesta y $vueltas filas
     * `chat_mensaje` con la respuesta en `referencia_id`, como las graba el loop desde la ronda de
     * arreglos R3.
     *
     * @param  \App\Models\AiConversation  $hilo
     * @param  int  $i
     * @param  int  $vueltas
     * @return AiMessage
     */
    protected function respuesta_con_vueltas($hilo, $i, $vueltas)
    {
        $this->mensaje($hilo, 'user', 'listo', ['contenido' => 'Pedido ' . $i]);
        $respuesta = $this->mensaje($hilo, 'assistant', 'listo', ['contenido' => 'Respuesta ' . $i]);

        for ($vuelta = 0; $vuelta < $vueltas; $vuelta++) {
            AiTokenUsage::create([
                'user_id'                     => $this->comercio->id,
                'auth_user_id'                => $this->comercio->id,
                'proceso'                     => TopeDeTokensHelper::PROCESO_CHAT,
                'proveedor'                   => 'anthropic',
                'modelo'                      => 'claude-sonnet-5',
                'input_tokens'                => 10,
                'output_tokens'               => 10,
                'cache_creation_input_tokens' => 0,
                'cache_read_input_tokens'     => 0,
                'ai_conversation_id'          => $hilo->id,
                'referencia_id'               => $respuesta->id,
                'created_at'                  => Carbon::now(),
                'updated_at'                  => Carbon::now(),
            ]);
        }

        return $respuesta;
    }
}
