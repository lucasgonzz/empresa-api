<?php

namespace Tests\Feature\IaApp;

use App\Http\Controllers\Helpers\asistente_ia\PedidosDeImagenIaHelper;
use App\Models\AiMessage;
use App\Models\IaImagenPedido;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Misión app-ia-y-memoria — lo que quedó esperando para siempre se cierra (corrección de la revisión,
 * 29/10/2026).
 *
 *   1. Un pendiente vencido que contestaba un AUDIO: al cerrarlo, la transcripción pasa a `fallida` (si
 *      no, la app muestra "transcribiendo…" para siempre).
 *   2. Una imagen generada `pendiente` / `generando` de hace más de 8 h se da por `fallida` al leer la
 *      conversación, con «No pude terminar la imagen. Pedímela de nuevo.», y queda guardado. Una de hace
 *      2 h sigue esperando.
 */
class Huerfanos_de_la_app_Test extends IaAppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\Storage::fake('local');
    }

    public function test_al_cerrar_un_pendiente_vencido_su_audio_pendiente_pasa_a_fallida()
    {
        $conversation = $this->conversacion(['titulo' => 'Audio colgado']);

        $audio = AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'estado'             => 'listo',
            'contenido'          => '',
            'canal'              => 'app',
            'tipo'               => 'audio',
            'created_at'         => now()->subMinutes(31),
        ]);
        $audio->audio = ['path' => 'x.webm', 'ext' => 'webm', 'mime' => 'video/webm', 'tamano' => 10, 'duracion_seg' => null, 'transcripcion' => 'pendiente'];
        $audio->save();

        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'assistant',
            'estado'             => 'pendiente',
            'canal'              => 'app',
            'created_at'         => now()->subMinutes(30),
        ]);

        Queue::fake();

        $this->postJson('api/ai-conversations/' . $conversation->id . '/messages', ['contenido' => '¿y?'], $this->de_la_app())
            ->assertStatus(201);

        $this->assertSame('fallida', AiMessage::find($audio->id)->audio['transcripcion']);
    }

    /**
     * Un mensaje del asistente con un bloque de imagen en curso y su pedido.
     *
     * @param  int  $horas  Hace cuánto se pidió.
     * @param  string  $estado
     * @return array{0: \App\Models\AiMessage, 1: \App\Models\IaImagenPedido, 2: \App\Models\AiConversation}
     */
    protected function imagen_en_curso($horas, $estado = 'pendiente')
    {
        $conversation = $this->conversacion(['titulo' => 'Imagen de hace ' . $horas . ' h']);

        $mensaje = AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'assistant',
            'estado'             => 'listo',
            'contenido'          => 'Te la estoy haciendo.',
            'canal'              => 'app',
            'created_at'         => now()->subHours($horas),
        ]);

        $pedido = IaImagenPedido::create([
            'uuid'          => (string) Str::uuid(),
            'user_id'       => $this->dueno->id,
            'ai_message_id' => $mensaje->id,
            'prompt'        => 'Una placa',
            'tamano'        => 'cuadrada',
            'estado'        => 'pendiente',
        ]);

        $mensaje->bloques = [['tipo' => 'imagen_generada', 'uuid' => $pedido->uuid, 'estado' => $estado, 'prompt' => 'Una placa', 'tamano' => 'cuadrada']];
        $mensaje->save();

        return [$mensaje, $pedido, $conversation];
    }

    public function test_una_imagen_de_mas_de_8_horas_se_da_por_fallida_al_leer_y_queda_guardado()
    {
        list($mensaje, $pedido, $conversation) = $this->imagen_en_curso(9, 'generando');

        $respuesta = $this->getJson('api/ai-conversations/' . $conversation->id . '/messages', $this->de_la_app())->assertStatus(200);

        $bloque = $respuesta->json('models.data.0.bloques.0');

        $this->assertSame('fallida', $bloque['estado']);
        $this->assertSame(PedidosDeImagenIaHelper::PERDIDA_PARA_EL_DUENO, $bloque['error']);
        $this->assertSame('No pude terminar la imagen. Pedímela de nuevo.', $bloque['error']);

        // Persistido: el bloque del mensaje y el pedido.
        $this->assertSame('fallida', AiMessage::find($mensaje->id)->bloques[0]['estado']);
        $this->assertSame('fallida', IaImagenPedido::find($pedido->id)->estado);

        // Y por el mensaje puntual también se ve así.
        $this->como_otro_cliente();

        $this->getJson('api/ai-conversations/' . $conversation->id . '/messages/' . $mensaje->id, $this->de_la_app())
            ->assertStatus(200)
            ->assertJsonPath('model.bloques.0.estado', 'fallida');
    }

    public function test_una_imagen_de_hace_2_horas_sigue_esperando()
    {
        list($mensaje, $pedido, $conversation) = $this->imagen_en_curso(2);

        $this->getJson('api/ai-conversations/' . $conversation->id . '/messages/' . $mensaje->id, $this->de_la_app())
            ->assertStatus(200)
            ->assertJsonPath('model.bloques.0.estado', 'pendiente');

        $this->assertSame('pendiente', IaImagenPedido::find($pedido->id)->estado);
    }

    public function test_si_la_imagen_llega_tarde_igual_se_muestra()
    {
        list($mensaje, $pedido, $conversation) = $this->imagen_en_curso(9);

        $this->getJson('api/ai-conversations/' . $conversation->id . '/messages', $this->de_la_app())->assertStatus(200);

        $this->post('api/admin-sync/ia-imagenes/' . $pedido->uuid . '/resultado', [
            'estado' => 'lista',
            'imagen' => \Illuminate\Http\UploadedFile::fake()->image('tarde.png', 32, 32),
        ], $this->del_admin())->assertStatus(200)->assertJsonPath('estado', 'lista');

        $this->assertSame('lista', AiMessage::find($mensaje->id)->bloques[0]['estado']);
    }
}
