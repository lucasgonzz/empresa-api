<?php

namespace Tests\Feature\OnboardingConversacional;

use App\Models\AiMessage;

/**
 * Misión onboarding-conversacional-whatsapp — CA10, contrato C3: una nota de voz larga (el dueño «se explaya»)
 * llega transcripta por Kapso y hoy se perdía con un 422 a los 4.000 caracteres. Con `tipo=audio` el techo
 * sube a 12.000; el texto y la imagen siguen en 4.000.
 *
 * (El POST responde 202 —el turno queda pendiente—, como en toda la suite del canal: el «200» del plan es el
 * éxito del pedido, no el código.)
 */
class Audios_largos_por_whatsapp_Test extends OnboardingTestCase
{
    /**
     * @group onboarding-conversacional
     * @test
     */
    public function test_audio_de_9000_entra_entero()
    {
        $texto = str_repeat('Contame mucho sobre el negocio. ', 281);
        $texto = mb_substr($texto, 0, 9000);

        $respuesta = $this->mandar(['texto' => $texto, 'tipo' => 'audio'])->assertStatus(202);

        $guardado = AiMessage::where('ai_conversation_id', $respuesta->json('ai_conversation_id'))->where('rol', 'user')->first();

        $this->assertSame(9000, mb_strlen($guardado->contenido), 'El mensaje del dueño se guarda entero.');
        $this->assertSame('audio', $guardado->tipo);
    }

    /**
     * @group onboarding-conversacional
     * @test
     */
    public function test_audio_de_12001_es_422()
    {
        $this->mandar(['texto' => str_repeat('a', 12001), 'tipo' => 'audio'])->assertStatus(422);

        $this->mandar(['texto' => str_repeat('a', 12000), 'tipo' => 'audio'])->assertStatus(202);
    }

    /**
     * @group onboarding-conversacional
     * @test
     */
    public function test_texto_de_4001_sigue_422()
    {
        $this->mandar(['texto' => str_repeat('a', 4001), 'tipo' => 'texto'])->assertStatus(422);

        $this->mandar(['texto' => str_repeat('a', 4001), 'tipo' => 'imagen'])->assertStatus(422);
    }
}
