<?php

namespace Tests\Unit;

use App\Http\Controllers\Helpers\WhatsappReactionHelper;
use PHPUnit\Framework\TestCase;

/**
 * Misión asistente-reacciones-wa (22/11/2026) — cálculo aislado del clasificador de reacciones de WhatsApp
 * (sin base, sin framework): qué emoji cuenta como «no me gusta», cuál como «me gusta» y cuál no cuenta.
 *
 * El clasificador es compartido por los dos agentes (el del dueño y el de los clientes del comercio) y la lista
 * es criterio, no ciencia: un emoji que no está en ninguna lista es neutro y no hace daño. Acá se fija el criterio
 * que aprobó Lucas: 👎 y las caras enojadas valoran en contra, 😢 y 😮 (que vienen en la paleta por defecto de
 * WhatsApp y se usan como empatía o sorpresa) no cuentan.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados.
 */
class WhatsappReactionHelperTest extends TestCase
{
    /**
     * CA1-3 — normaliza (tono de piel, selector de variación, género) y clasifica.
     *
     * @test
     */
    public function test_clasifica_y_normaliza()
    {
        // Lo que sale de la normalización: la base del emoji.
        $this->assertSame('👎', WhatsappReactionHelper::normalize_emoji('👎🏽'));
        $this->assertSame('👎', WhatsappReactionHelper::normalize_emoji(' 👎 '));
        $this->assertSame(
            WhatsappReactionHelper::normalize_emoji('❤️'),
            WhatsappReactionHelper::normalize_emoji('❤'),
            '❤️ (con selector de variación) y ❤ (sin él) son el mismo emoji.'
        );
        $this->assertSame('🤦', WhatsappReactionHelper::normalize_emoji("🤦\u{200D}\u{2642}\u{FE0F}"));

        // Negativas.
        foreach (['👎', '👎🏽', '😡', '😠', '🤬', '🤦', "🤦\u{200D}\u{2642}\u{FE0F}", '💩', '❌'] as $emoji) {
            $this->assertSame('negativa', WhatsappReactionHelper::classify($emoji), 'Con ' . $emoji);
        }

        // Positivas.
        foreach (['👍', '👍🏿', '❤️', '❤', '😍', '🙏', '🔥', '✅'] as $emoji) {
            $this->assertSame('positiva', WhatsappReactionHelper::classify($emoji), 'Con ' . $emoji);
        }

        // Neutras: empatía, sorpresa, risa, duda.
        foreach (['😂', '😢', '😮', '🤔', '🎉'] as $emoji) {
            $this->assertSame('neutra', WhatsappReactionHelper::classify($emoji), 'Con ' . $emoji);
        }

        // Quitada: el emoji vacío (o sólo espacios) es «sacó la reacción».
        $this->assertSame('quitada', WhatsappReactionHelper::classify(''));
        $this->assertSame('quitada', WhatsappReactionHelper::classify('  '));
        $this->assertSame('quitada', WhatsappReactionHelper::classify(null));
    }

    /**
     * CA1-3, CA9 — extrae la reacción del payload de Kapso: el formato nativo (`type: reaction`) y los tres
     * textos legados; todo lo demás (un mensaje normal) da null.
     *
     * @test
     */
    public function test_extrae_la_reaccion_del_payload_en_los_dos_formatos()
    {
        // Nativo.
        $nativo = WhatsappReactionHelper::extract_reaction([
            'message' => [
                'id'       => 'wamid.REACT1',
                'from'     => '5493415551234',
                'type'     => 'reaction',
                'reaction' => ['message_id' => 'wamid.OUT1', 'emoji' => '👎'],
            ],
        ]);
        $this->assertSame([
            'from'                  => '5493415551234',
            'target_wa_message_id'  => 'wamid.OUT1',
            'emoji'                 => '👎',
            'reaction_wa_message_id' => 'wamid.REACT1',
        ], $nativo);

        // Nativo quitando la reacción.
        $quitada = WhatsappReactionHelper::extract_reaction([
            'message' => ['from' => '5493415551234', 'type' => 'reaction', 'reaction' => ['message_id' => 'wamid.OUT1', 'emoji' => '']],
        ]);
        $this->assertSame('', $quitada['emoji']);
        $this->assertSame('wamid.OUT1', $quitada['target_wa_message_id']);

        // Sin el teléfono en el mensaje: sale de la conversación.
        $de_la_conversacion = WhatsappReactionHelper::extract_reaction([
            'message'      => ['type' => 'reaction', 'reaction' => ['message_id' => 'wamid.OUT1', 'emoji' => '👍']],
            'conversation' => ['phone_number' => '+5493415551234'],
        ]);
        $this->assertSame('+5493415551234', $de_la_conversacion['from']);

        // Legados.
        $casos = [
            'Reacted with 👎 to message wamid.OUT1' => '👎',
            'Reacted 👎 to message wamid.OUT1'      => '👎',
            'Removed reaction from message wamid.OUT1' => '',
        ];
        foreach ($casos as $cuerpo => $emoji_esperado) {
            $legado = WhatsappReactionHelper::extract_reaction([
                'message' => ['id' => 'wamid.REACT2', 'from' => '5493415551234', 'type' => 'text', 'text' => ['body' => $cuerpo]],
            ]);
            $this->assertNotNull($legado, $cuerpo);
            $this->assertSame($emoji_esperado, $legado['emoji'], $cuerpo);
            $this->assertSame('wamid.OUT1', $legado['target_wa_message_id'], $cuerpo);
        }

        // Un mensaje común no es una reacción, ni siquiera si habla de una.
        $this->assertNull(WhatsappReactionHelper::extract_reaction([
            'message' => ['from' => '5493415551234', 'type' => 'text', 'text' => ['body' => 'Hola, ¿tenés tornillos?']],
        ]));
        $this->assertNull(WhatsappReactionHelper::extract_reaction([
            'message' => ['from' => '5493415551234', 'type' => 'text', 'text' => ['body' => 'Te escribí que reacted with 👎 pero no es un wamid']],
        ]));
        $this->assertNull(WhatsappReactionHelper::extract_reaction(['message' => 'no es un arreglo']));
        $this->assertNull(WhatsappReactionHelper::extract_reaction([]));
    }

    /**
     * El comentario que queda en la valoración lleva el emoji tal como llegó (sin normalizar).
     *
     * @test
     */
    public function test_el_comentario_lleva_el_emoji_tal_como_llego()
    {
        $this->assertSame('reacción 👎🏽 por WhatsApp', WhatsappReactionHelper::comment_for('👎🏽'));
        $this->assertSame('reacción ❤️ por WhatsApp', WhatsappReactionHelper::comment_for('❤️'));
    }
}
