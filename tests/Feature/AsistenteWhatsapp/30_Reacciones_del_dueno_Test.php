<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Jobs\ResponderMensajeChatIaJob;
use App\Models\AiConversation;
use App\Models\AiMessage;
use Illuminate\Support\Facades\Queue;

/**
 * Misión asistente-reacciones-wa (22/11/2026) — las reacciones del DUEÑO (👍 / 👎 / 😡 …) a las respuestas de su
 * asistente por WhatsApp: `POST api/admin-sync/asistente/reacciones`.
 *
 * Pedido de Lucas: «quiero que los usuarios puedan reaccionar por WhatsApp a los mensajes del asistente, y si
 * reaccionan con un pulgar para abajo, con una cara enojada, quiero que analices ese mensaje para ver por qué no
 * les gustó y lo mejores». La reacción entra por el número de ComercioCity, o sea por el webhook del ADMIN, que
 * la reenvía acá (el wamid de cada respuesta del asistente vive en el admin). Esta suite es el lado de empresa
 * del contrato; el de admin vive en admin-api (ReaccionesDelDuenoAlAsistenteTest).
 *
 * Lo que protege este archivo, en orden de importancia:
 *
 * 🔴 UNA REACCIÓN NO ES UN MENSAJE: no crea turno, no despacha al asistente, no mueve la conversación (CA7).
 * 🔴 NO SE PISA LO QUE EL DUEÑO ESCRIBIÓ EN EL SISTEMA: una valoración puesta desde el chat con un comentario
 *    («los números de caja estaban mal») vale más que un emoji; la reacción no la toca (CA8).
 * 🔴 SÓLO LA RESPUESTA DEL ASISTENTE, SÓLO DEL DUEÑO: el `wamid` del turno está en las DOS filas (la del dueño y la
 *    del asistente, ver `guardar_turno`), así que reaccionar a lo que uno mismo escribió no valora la respuesta
 *    (CA6). Un mensaje de otro dueño nunca se valora.
 * - Que el PUT del sistema deje constancia de su origen (`valoracion_origen = sistema`) para poder distinguirlo (CA11).
 * - Que quitar la reacción o cambiarla por una neutra limpie la valoración que había puesto una reacción (CA5).
 *
 * El gate y la clave son los del resto del canal (`rechazo_de_acceso`): CA12.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class Reacciones_del_dueno_Test extends AsistenteWhatsappTestCase
{
    /** Wamid de la respuesta del asistente en los casos que lo mandan por su id. */
    const WAMID_RESPUESTA = 'wamid.OUTA1';

    /** Wamid del mensaje ENTRANTE del dueño: el que el turno guarda en sus dos filas. */
    const WAMID_ENTRANTE = 'wamid.ENTRANTE1';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dar_extension(self::SLUG_ASISTENTE);
    }

    /**
     * Un turno completo del canal: el mensaje del dueño y la respuesta del asistente, las DOS con el wamid del
     * ENTRANTE (así lo guarda `guardar_turno`).
     *
     * @param  AiConversation|null  $conversacion
     * @return array  [AiMessage del dueño, AiMessage del asistente]
     */
    protected function turno($conversacion = null)
    {
        $conversacion = is_null($conversacion) ? $this->conversacion_whatsapp() : $conversacion;

        $del_dueno = $this->mensaje($conversacion, 'user', 'listo', ['whatsapp_message_id' => self::WAMID_ENTRANTE]);
        $respuesta = $this->mensaje($conversacion, 'assistant', 'listo', ['whatsapp_message_id' => self::WAMID_ENTRANTE]);

        return [$del_dueno, $respuesta];
    }

    /**
     * POST de una reacción tal como lo manda el admin.
     *
     * @param  array  $datos  whatsapp_message_id, emoji, ai_message_id?, reaction_message_id?
     * @param  bool  $con_clave
     * @return \Illuminate\Testing\TestResponse
     */
    protected function reaccionar(array $datos, $con_clave = true)
    {
        $cuerpo = array_merge(['whatsapp_message_id' => self::WAMID_RESPUESTA, 'emoji' => '👎'], $datos);

        return $this->postJson('api/admin-sync/asistente/reacciones', $cuerpo, $this->headers($con_clave));
    }

    /**
     * Las columnas de la valoración de un mensaje, tal como están en la base.
     *
     * @param  AiMessage  $mensaje
     * @return array
     */
    protected function valoracion_de(AiMessage $mensaje)
    {
        $fresco = AiMessage::find($mensaje->id);

        return [
            'valoracion'            => $fresco->valoracion,
            'valoracion_comentario' => $fresco->valoracion_comentario,
            'valorado_at'           => $fresco->valorado_at,
            'valoracion_origen'     => $fresco->valoracion_origen,
        ];
    }

    /** Las cuatro columnas en null: un mensaje sin valorar. */
    protected function sin_valorar()
    {
        return ['valoracion' => null, 'valoracion_comentario' => null, 'valorado_at' => null, 'valoracion_origen' => null];
    }

    /**
     * CA4 — 😡 (y 👎) a la respuesta del asistente = no me gusta, con el comentario exacto y el origen
     * `reaccion_whatsapp`. El admin manda el `ai_message_id` de la respuesta.
     *
     * @test
     */
    public function test_cara_enojada_valora_no_me_gusta_con_origen_reaccion()
    {
        list(, $respuesta) = $this->turno();

        $this->reaccionar(['emoji' => '😡', 'ai_message_id' => $respuesta->id])
            ->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('aplicada', true)
            ->assertJsonPath('ai_message_id', $respuesta->id)
            ->assertJsonPath('valoracion', 'no_me_gusta');

        $valoracion = $this->valoracion_de($respuesta);
        $this->assertSame('no_me_gusta', $valoracion['valoracion']);
        $this->assertSame('reacción 😡 por WhatsApp', $valoracion['valoracion_comentario']);
        $this->assertSame('reaccion_whatsapp', $valoracion['valoracion_origen']);
        $this->assertNotNull($valoracion['valorado_at']);

        // También el pulgar para abajo, con otro tono de piel.
        $this->reaccionar(['emoji' => '👎🏽', 'ai_message_id' => $respuesta->id])->assertStatus(200);
        $this->assertSame('reacción 👎🏽 por WhatsApp', $this->valoracion_de($respuesta)['valoracion_comentario']);
    }

    /**
     * CA5 — positiva = me gusta; quitarla (emoji vacío) limpia todo, origen incluido; 👎 y después una neutra
     * (😮) también limpia (WhatsApp admite una sola reacción por persona y mensaje).
     *
     * @test
     */
    public function test_positiva_quitar_y_neutra()
    {
        list(, $respuesta) = $this->turno();
        $id = $respuesta->id;

        $this->reaccionar(['emoji' => '👍', 'ai_message_id' => $id])->assertStatus(200)->assertJsonPath('aplicada', true);
        $valoracion = $this->valoracion_de($respuesta);
        $this->assertSame('me_gusta', $valoracion['valoracion']);
        $this->assertSame('reacción 👍 por WhatsApp', $valoracion['valoracion_comentario']);
        $this->assertSame('reaccion_whatsapp', $valoracion['valoracion_origen']);

        // Quitar la reacción: el admin manda el emoji vacío.
        $this->reaccionar(['emoji' => '', 'ai_message_id' => $id])->assertStatus(200)->assertJsonPath('aplicada', true);
        $this->assertSame($this->sin_valorar(), $this->valoracion_de($respuesta));

        // 👎 y después una neutra.
        $this->reaccionar(['emoji' => '👎', 'ai_message_id' => $id])->assertStatus(200);
        $this->assertSame('no_me_gusta', $this->valoracion_de($respuesta)['valoracion']);

        $this->reaccionar(['emoji' => '😮', 'ai_message_id' => $id])->assertStatus(200)->assertJsonPath('aplicada', true);
        $this->assertSame($this->sin_valorar(), $this->valoracion_de($respuesta));

        // Quitar o una neutra sobre una respuesta SIN valorar no hace nada.
        $this->reaccionar(['emoji' => '', 'ai_message_id' => $id])
            ->assertStatus(200)
            ->assertJsonPath('aplicada', false)
            ->assertJsonPath('motivo', 'sin_cambios');
    }

    /**
     * CA6 — una reacción a algo que no es una respuesta del asistente de ESTE dueño se ignora: `ai_message_id`
     * de otro dueño, de una fila `rol = user`, wamid inexistente y wamid que comparten la fila del dueño y la
     * del asistente del turno (sin `ai_message_id`). Siempre 200 `aplicada: false, motivo: mensaje_desconocido`
     * y ninguna fila cambia.
     *
     * @test
     */
    public function test_mensaje_ajeno_o_desconocido_no_se_valora()
    {
        list($del_dueno, $respuesta) = $this->turno();

        $otro = $this->otro_dueno();
        $conversacion_ajena = $this->conversacion_whatsapp(null, $otro);
        $respuesta_ajena    = $this->mensaje($conversacion_ajena, 'assistant', 'listo', ['whatsapp_message_id' => 'wamid.AJENO1']);

        $mensajes = [$del_dueno, $respuesta, $respuesta_ajena];
        $antes    = [];
        foreach ($mensajes as $mensaje) {
            $antes[$mensaje->id] = $this->valoracion_de($mensaje);
        }

        $casos = [
            // El id de una respuesta de OTRO dueño.
            ['ai_message_id' => $respuesta_ajena->id, 'whatsapp_message_id' => 'wamid.NOEXISTE'],
            // El id de lo que escribió el propio dueño.
            ['ai_message_id' => $del_dueno->id, 'whatsapp_message_id' => 'wamid.NOEXISTE'],
            // Un wamid que no existe.
            ['whatsapp_message_id' => 'wamid.NOEXISTE'],
            // El wamid del ENTRANTE del dueño, que el turno guarda también en la respuesta: reaccionar a lo
            // propio no valora la respuesta.
            ['whatsapp_message_id' => self::WAMID_ENTRANTE],
            // El wamid de la respuesta de otro dueño, sin ai_message_id.
            ['whatsapp_message_id' => 'wamid.AJENO1'],
        ];

        foreach ($casos as $caso) {
            $this->reaccionar(array_merge(['emoji' => '👎'], $caso))
                ->assertStatus(200)
                ->assertJsonPath('ok', true)
                ->assertJsonPath('aplicada', false)
                ->assertJsonPath('motivo', 'mensaje_desconocido');
        }

        foreach ($mensajes as $mensaje) {
            $this->assertSame($antes[$mensaje->id], $this->valoracion_de($mensaje), 'La fila ' . $mensaje->id . ' no se puede haber tocado.');
        }
    }

    /**
     * CA7 — la reacción no dispara al asistente: no se despacha el job que responde, `ai_messages` no suma filas
     * y la conversación no se mueve (`last_message_at`).
     *
     * @test
     */
    public function test_la_reaccion_no_dispara_respuesta_del_asistente()
    {
        Queue::fake();

        $conversacion = $this->conversacion_whatsapp('2026-11-20 09:00:00');
        list(, $respuesta) = $this->turno($conversacion);

        $mensajes_antes = AiMessage::count();
        $ultimo_antes   = AiConversation::find($conversacion->id)->last_message_at;

        foreach (['👎', '👍', '😂', ''] as $emoji) {
            $this->reaccionar(['emoji' => $emoji, 'ai_message_id' => $respuesta->id])->assertStatus(200);
        }
        $this->reaccionar(['emoji' => '👎', 'whatsapp_message_id' => 'wamid.NOEXISTE'])->assertStatus(200);

        Queue::assertNotPushed(ResponderMensajeChatIaJob::class);
        $this->assertSame($mensajes_antes, AiMessage::count(), 'Una reacción no crea ningún mensaje.');
        $this->assertEquals($ultimo_antes, AiConversation::find($conversacion->id)->last_message_at);
    }

    /**
     * CA8 — no pisa la valoración escrita en el sistema. El dueño marcó «no me gusta» con un comentario desde el
     * chat (PUT real): una reacción 👍 y el «quitar» no la tocan (`aplicada: false, motivo:
     * valoracion_del_sistema`). Con una valoración del sistema SIN comentario, en cambio, 👎 sí la reemplaza.
     *
     * @test
     */
    public function test_no_pisa_la_valoracion_con_comentario_del_sistema()
    {
        list(, $respuesta) = $this->turno();

        $this->actingAs($this->comercio, 'sanctum')
            ->putJson('api/ai-mensajes/' . $respuesta->id . '/valoracion', [
                'valoracion' => 'no_me_gusta',
                'comentario' => 'los números de caja estaban mal',
            ])
            ->assertStatus(200);

        $puesta = $this->valoracion_de($respuesta);
        $this->assertSame('sistema', $puesta['valoracion_origen']);
        $this->assertSame('los números de caja estaban mal', $puesta['valoracion_comentario']);

        foreach (['👍', ''] as $emoji) {
            $this->reaccionar(['emoji' => $emoji, 'ai_message_id' => $respuesta->id])
                ->assertStatus(200)
                ->assertJsonPath('aplicada', false)
                ->assertJsonPath('motivo', 'valoracion_del_sistema');

            $this->assertSame($puesta, $this->valoracion_de($respuesta), 'La valoración del sistema con comentario no se toca.');
        }

        // Una valoración del sistema SIN comentario sí se reemplaza.
        $otra = $this->mensaje($this->conversacion_whatsapp(), 'assistant', 'listo', ['whatsapp_message_id' => 'wamid.OTRA1']);

        $this->actingAs($this->comercio, 'sanctum')
            ->putJson('api/ai-mensajes/' . $otra->id . '/valoracion', ['valoracion' => 'me_gusta'])
            ->assertStatus(200);
        $this->assertSame('sistema', $this->valoracion_de($otra)['valoracion_origen']);

        $this->reaccionar(['emoji' => '👎', 'ai_message_id' => $otra->id])
            ->assertStatus(200)
            ->assertJsonPath('aplicada', true);

        $reemplazada = $this->valoracion_de($otra);
        $this->assertSame('no_me_gusta', $reemplazada['valoracion']);
        $this->assertSame('reacción 👎 por WhatsApp', $reemplazada['valoracion_comentario']);
        $this->assertSame('reaccion_whatsapp', $reemplazada['valoracion_origen']);
    }

    /**
     * CA10 (lado asistente) — un aviso que el asistente mandó solo guarda en su fila el wamid del SALIENTE, y
     * ninguna fila `user` lo comparte: la reacción sin `ai_message_id` llega por ese wamid y se valora.
     *
     * @test
     */
    public function test_reaccion_a_un_aviso_por_su_wamid_propio()
    {
        $conversacion = $this->conversacion_whatsapp();
        $aviso        = $this->mensaje($conversacion, 'assistant', 'listo', ['whatsapp_message_id' => 'wamid.AVISO1']);

        $this->reaccionar(['emoji' => '😠', 'whatsapp_message_id' => 'wamid.AVISO1'])
            ->assertStatus(200)
            ->assertJsonPath('aplicada', true)
            ->assertJsonPath('ai_message_id', $aviso->id);

        $valoracion = $this->valoracion_de($aviso);
        $this->assertSame('no_me_gusta', $valoracion['valoracion']);
        $this->assertSame('reacción 😠 por WhatsApp', $valoracion['valoracion_comentario']);
        $this->assertSame('reaccion_whatsapp', $valoracion['valoracion_origen']);
    }

    /**
     * CA11 — el PUT del sistema deja constancia del origen: `no_me_gusta` → `valoracion_origen = sistema`; con
     * `valoracion: null` el origen vuelve a null. El resto del contrato del PUT sigue igual.
     *
     * @test
     */
    public function test_el_put_del_sistema_marca_origen_sistema()
    {
        list(, $respuesta) = $this->turno();

        $this->actingAs($this->comercio, 'sanctum')
            ->putJson('api/ai-mensajes/' . $respuesta->id . '/valoracion', ['valoracion' => 'no_me_gusta', 'comentario' => 'faltó el detalle'])
            ->assertStatus(200)
            ->assertJsonPath('model.valoracion', 'no_me_gusta');

        $this->assertSame('sistema', $this->valoracion_de($respuesta)['valoracion_origen']);

        $this->actingAs($this->comercio, 'sanctum')
            ->putJson('api/ai-mensajes/' . $respuesta->id . '/valoracion', ['valoracion' => null])
            ->assertStatus(200)
            ->assertJsonPath('model.valoracion', null);

        $this->assertSame($this->sin_valorar(), $this->valoracion_de($respuesta));

        // El 422 de un valor inválido sigue igual que hoy.
        $this->actingAs($this->comercio, 'sanctum')
            ->putJson('api/ai-mensajes/' . $respuesta->id . '/valoracion', ['valoracion' => 'regular'])
            ->assertStatus(422);
    }

    /**
     * CA12 — el acceso del endpoint es el del resto del canal: sin clave 401, sin la extensión `agentes_ia` 403,
     * sin `whatsapp_message_id` (o con un emoji que no es texto) 422.
     *
     * @test
     */
    public function test_acceso_del_endpoint()
    {
        list(, $respuesta) = $this->turno();

        // Sin la clave del admin.
        $this->reaccionar(['ai_message_id' => $respuesta->id], false)->assertStatus(401);

        // Falta el wamid.
        $this->postJson('api/admin-sync/asistente/reacciones', ['emoji' => '👎'], $this->headers())->assertStatus(422);

        // El emoji tiene que ser texto.
        $this->reaccionar(['emoji' => ['👎']])->assertStatus(422);

        $this->assertSame($this->sin_valorar(), $this->valoracion_de($respuesta), 'Ningún rechazo puede valorar.');

        // Sin la extensión: se la saca al dueño.
        $this->comercio->extencions()->detach();
        $this->comercio->load('extencions');

        $this->reaccionar(['ai_message_id' => $respuesta->id])->assertStatus(403);
        $this->assertSame($this->sin_valorar(), $this->valoracion_de($respuesta));
    }
}
