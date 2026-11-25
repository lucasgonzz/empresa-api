<?php

namespace Tests\Feature\OnboardingConversacional;

use App\Jobs\ResponderMensajeChatIaJob;
use App\Models\AiMessage;
use Illuminate\Support\Facades\Http;

/**
 * Misión onboarding-conversacional-whatsapp — CA16: el recorrido tipo «dueño que recién activa su asistente»,
 * de punta a punta y con datos ficticios (nada de nombres, teléfonos ni textos de un cliente real):
 *
 *   1. «Hola, ¿me escuchás?» → arranca, una tarea para mañana a las 17:00, cursor en la primera pregunta.
 *   2. No contesta: el motor de tareas despierta tres veces (+1, +3 y +7 días) y se detiene: tres ejecuciones,
 *      pausa por falta de respuesta, ningún cuarto recordatorio.
 *   3. A los 20 días manda un audio largo (6.000 caracteres) que cuenta su historia y qué vende: entra entero,
 *      el modelo anota los dos temas, el onboarding vuelve a `en_curso` con un seguimiento nuevo.
 *
 * El modelo es un doble (tool_use con `temas`): se verifica el estado, el cursor y las tareas, no el texto.
 */
class Recorrido_completo_Test extends OnboardingTestCase
{
    /**
     * @group onboarding-conversacional
     * @test
     */
    public function test_recorrido_de_un_duenio_que_no_contesta_y_vuelve_con_un_audio()
    {
        $this->comercio->company_name = 'Distribuidora Ficticia';
        $this->comercio->save();

        // (1) El primer mensaje.
        $this->el_dueno_escribe('Hola, ¿me escuchás?');

        $dueno = $this->onboarding();

        $this->assertSame('en_curso', $dueno->ia_onboarding_estado);
        $this->assertSame('historia', $dueno->ia_onboarding_cursor);

        $activas = $this->tareas_de_onboarding('activa');

        $this->assertCount(1, $activas);
        $this->assertSame('2026-11-24 17:00:00', $activas[0]->proxima_ejecucion_at->format('Y-m-d H:i:s'));

        // (2) Tres despertares sin respuesta.
        $this->despierta_y_corre_el_turno();
        $this->assertSame('2026-11-27 17:00:00', $this->tareas_de_onboarding('activa')[0]->proxima_ejecucion_at->format('Y-m-d H:i:s'));

        $this->despierta_y_corre_el_turno();
        $this->assertSame('2026-12-04 17:00:00', $this->tareas_de_onboarding('activa')[0]->proxima_ejecucion_at->format('Y-m-d H:i:s'));

        $this->despierta_y_corre_el_turno();

        $this->assertCount(3, $this->ejecuciones(), 'Tres recordatorios, ni uno más.');
        $this->assertCount(0, $this->tareas_de_onboarding('activa'), 'Ningún cuarto recordatorio.');
        $this->assertSame('pausado_sin_respuesta', $this->onboarding()->ia_onboarding_estado);
        $this->assertSame('historia', $this->onboarding()->ia_onboarding_cursor, 'La pregunta en curso sigue guardada.');

        // Aunque pase el tiempo, el comando no despierta nada más.
        $this->ahora('2026-12-10 17:30:00');
        $this->despertar();
        $this->assertCount(3, $this->ejecuciones());

        // (3) A los 20 días, un audio largo.
        $this->ahora('2026-12-13 11:00:00');

        $audio = mb_substr(str_repeat('Arrancamos con mi viejo hace veinte años vendiendo golosinas y hoy abastecemos kioscos de toda la zona. ', 60), 0, 6000);

        $llamadas = 0;

        $this->instalar_el_doble_del_modelo();

        $this->comportamiento_del_modelo = function () use (&$llamadas) {
            $llamadas++;

            if ($llamadas === 1) {
                return Http::response([
                    'model'       => 'deepseek-flash-test',
                    'stop_reason' => 'tool_use',
                    'content'     => [[
                        'type'  => 'tool_use',
                        'id'    => 'toolu_' . uniqid(),
                        'name'  => 'guardar_en_contexto',
                        'input' => [
                            'ruta'      => 'dueno/perfil.md',
                            'operacion' => 'agregar',
                            'contenido' => '- Empezó con su padre hace veinte años; vende golosinas a kioscos de la zona.',
                            'motivo'    => 'Lo contó en un audio',
                            'temas'     => ['historia', 'que_vende_y_a_quien'],
                        ],
                    ]],
                    'usage'       => ['input_tokens' => 300, 'output_tokens' => 40],
                ], 200);
            }

            return Http::response([
                'model'       => 'deepseek-flash-test',
                'stop_reason' => 'end_turn',
                'content'     => [['type' => 'text', 'text' => 'Me lo anoto. ¿Cómo describirías tu negocio si un cliente te lo preguntara?']],
                'usage'       => ['input_tokens' => 300, 'output_tokens' => 20],
            ], 200);
        };

        $respuesta = $this->mandar(['texto' => $audio, 'tipo' => 'audio', 'whatsapp_message_id' => 'wamid.AUDIO1'])->assertStatus(202);

        (new ResponderMensajeChatIaJob((int) $respuesta->json('ai_message_id')))->handle();

        $guardado = AiMessage::where('ai_conversation_id', $respuesta->json('ai_conversation_id'))->where('rol', 'user')->first();

        $this->assertSame(6000, mb_strlen($guardado->contenido), 'El audio largo se guarda entero.');

        $marcados = $this->temas_marcados();

        $this->assertSame('sabido', $marcados['historia']->estado);
        $this->assertSame('sabido', $marcados['que_vende_y_a_quien']->estado);

        $dueno = $this->onboarding();

        $this->assertSame('en_curso', $dueno->ia_onboarding_estado, 'Contestó: vuelve a en_curso.');
        $this->assertSame('como_lo_describen', $dueno->ia_onboarding_cursor, 'El cursor salta a lo que falta.');

        $nuevas = $this->tareas_de_onboarding('activa');

        $this->assertCount(1, $nuevas);
        $this->assertSame('2026-12-14 17:00:00', $nuevas[0]->proxima_ejecucion_at->format('Y-m-d H:i:s'), 'Escalera nueva: +1 día.');
    }
}
