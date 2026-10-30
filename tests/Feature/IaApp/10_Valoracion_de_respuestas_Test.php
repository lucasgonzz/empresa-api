<?php

namespace Tests\Feature\IaApp;

use App\Http\Controllers\Helpers\asistente_ia\IaAppHelper;
use App\Models\AiMessage;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * Misión agente-mejoras-uso-real (29/10/2026) — me gusta / no me gusta de cada respuesta del agente,
 * con el comentario del no me gusta: `PUT api/ai-mensajes/{message_id}/valoracion`.
 *
 * Lo usan la app (token `ia-app`) y el chat del sistema (sesión de Sanctum), y lo lee el análisis
 * diario de las 22 h. Lo que protege este archivo: que se guarde lo que el dueño eligió, que el
 * comentario solo viva con un no me gusta, que solo se valoren respuestas del agente y que la tenencia
 * doble del chat se respete (un mensaje de otro dueño da 404).
 */
class Valoracion_de_respuestas_Test extends IaAppTestCase
{
    /**
     * Una respuesta del agente en una conversación del dueño.
     *
     * @param  string  $rol
     * @return \App\Models\AiMessage
     */
    protected function respuesta($rol = 'assistant')
    {
        $conversation = $this->conversacion(['titulo' => 'Valoración']);

        return AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => $rol,
            'estado'             => 'listo',
            'contenido'          => 'Hay uno solo con margen 0.',
            'canal'              => 'app',
        ]);
    }

    public function test_la_app_anuncia_la_funcion_y_la_ruta_esta_en_su_lista_blanca()
    {
        $this->assertContains('valoracion', IaAppHelper::funciones());

        $mensaje = $this->respuesta();

        $this->putJson('api/ai-mensajes/' . $mensaje->id . '/valoracion', ['valoracion' => 'me_gusta'], $this->de_la_app())
            ->assertStatus(200)
            ->assertJsonPath('model.valoracion', 'me_gusta')
            ->assertJsonPath('model.valoracion_comentario', null);

        $guardado = AiMessage::find($mensaje->id);
        $this->assertSame('me_gusta', $guardado->valoracion);
        $this->assertNotNull($guardado->valorado_at);
    }

    public function test_no_me_gusta_guarda_el_comentario_y_me_gusta_lo_borra()
    {
        $mensaje = $this->respuesta();

        $this->putJson('api/ai-mensajes/' . $mensaje->id . '/valoracion', [
            'valoracion' => 'no_me_gusta',
            'comentario' => '  Había dos, no uno.  ',
        ], $this->de_la_app())
            ->assertStatus(200)
            ->assertJsonPath('model.valoracion', 'no_me_gusta')
            ->assertJsonPath('model.valoracion_comentario', 'Había dos, no uno.');

        $this->como_otro_cliente();

        $this->putJson('api/ai-mensajes/' . $mensaje->id . '/valoracion', [
            'valoracion' => 'me_gusta',
            'comentario' => 'esto no se guarda',
        ], $this->de_la_app())->assertStatus(200);

        $guardado = AiMessage::find($mensaje->id);
        $this->assertSame('me_gusta', $guardado->valoracion);
        $this->assertNull($guardado->valoracion_comentario);
    }

    public function test_null_saca_la_valoracion()
    {
        $mensaje = $this->respuesta();
        $mensaje->update(['valoracion' => 'no_me_gusta', 'valoracion_comentario' => 'mal', 'valorado_at' => now()]);

        $this->putJson('api/ai-mensajes/' . $mensaje->id . '/valoracion', ['valoracion' => null], $this->de_la_app())
            ->assertStatus(200)
            ->assertJsonPath('model.valoracion', null);

        $guardado = AiMessage::find($mensaje->id);
        $this->assertNull($guardado->valoracion);
        $this->assertNull($guardado->valoracion_comentario);
        $this->assertNull($guardado->valorado_at);
    }

    public function test_el_mensaje_viaja_con_su_valoracion_en_la_lista_de_mensajes()
    {
        $mensaje = $this->respuesta();
        $mensaje->update(['valoracion' => 'no_me_gusta', 'valoracion_comentario' => 'Faltaron fotos']);

        $this->getJson('api/ai-conversations/' . $mensaje->ai_conversation_id . '/messages', $this->de_la_app())
            ->assertStatus(200)
            ->assertJsonFragment(['valoracion' => 'no_me_gusta', 'valoracion_comentario' => 'Faltaron fotos']);
    }

    public function test_solo_se_valoran_respuestas_del_agente()
    {
        $mensaje = $this->respuesta('user');

        $this->putJson('api/ai-mensajes/' . $mensaje->id . '/valoracion', ['valoracion' => 'me_gusta'], $this->de_la_app())
            ->assertStatus(422)
            ->assertJsonPath('code', 'no_es_del_agente');

        $this->assertNull(AiMessage::find($mensaje->id)->valoracion);
    }

    public function test_valores_invalidos_dan_422()
    {
        $mensaje = $this->respuesta();

        $this->putJson('api/ai-mensajes/' . $mensaje->id . '/valoracion', ['valoracion' => 'maso'], $this->de_la_app())
            ->assertStatus(422)
            ->assertJsonPath('code', 'valoracion_invalida');

        $this->como_otro_cliente();

        $this->putJson('api/ai-mensajes/' . $mensaje->id . '/valoracion', [
            'valoracion' => 'no_me_gusta',
            'comentario' => str_repeat('a', 1001),
        ], $this->de_la_app())
            ->assertStatus(422)
            ->assertJsonPath('code', 'comentario_muy_largo');
    }

    public function test_un_mensaje_de_otro_dueno_da_404()
    {
        $mensaje = $this->respuesta();

        $otro = User::create([
            'name'         => 'Otro dueño',
            'company_name' => 'Otra ferretería ' . uniqid(),
            'email'        => 'ia-app-otro-' . uniqid() . '@test.local',
            'doc_number'   => '50' . substr(uniqid(), -6),
            'password'     => bcrypt('otra-clave'),
        ]);
        $this->dar_extension($otro);
        config(['app.USER_ID' => $otro->id]);

        $this->putJson('api/ai-mensajes/' . $mensaje->id . '/valoracion', ['valoracion' => 'me_gusta'], $this->de_la_app(IaAppHelper::emitir_token($otro, 'Otro')))
            ->assertStatus(404);

        $this->assertNull(AiMessage::find($mensaje->id)->valoracion);
    }

    public function test_el_chat_del_sistema_valora_con_la_sesion()
    {
        $mensaje = $this->respuesta();

        Sanctum::actingAs($this->dueno, ['*']);

        $this->putJson('api/ai-mensajes/' . $mensaje->id . '/valoracion', [
            'valoracion' => 'no_me_gusta',
            'comentario' => 'No le cargó la foto a todos los clavos',
        ])->assertStatus(200);

        $this->assertSame('No le cargó la foto a todos los clavos', AiMessage::find($mensaje->id)->valoracion_comentario);
    }
}
