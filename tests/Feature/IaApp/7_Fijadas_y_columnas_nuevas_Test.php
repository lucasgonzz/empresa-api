<?php

namespace Tests\Feature\IaApp;

use App\Http\Controllers\Helpers\asistente_ia\ColumnasDeLaAppIaHelper;
use App\Models\AiMessage;
use App\Services\AsistenteIa\AsistenteIaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Misión app-ia-y-memoria — correcciones de la revisión sobre el listado y las columnas nuevas.
 *
 *   1. Las fijadas van primero SOLO con el token de la app; con la cookie del sistema el orden es el de
 *      siempre (el panel abre conversations[0]).
 *   2. 🔴 Un frente con el código nuevo y la migración sin correr no se queda sin chat: el listado, el
 *      cierre de huérfanos y el historial que va al modelo no tocan `fijada`, `progreso` ni `archivos`
 *      si no existen. Se finge que faltan (ColumnasDeLaAppIaHelper::fingir) porque un ALTER de verdad
 *      confirma la transacción del test.
 */
class Fijadas_y_columnas_nuevas_Test extends IaAppTestCase
{
    protected function tearDown(): void
    {
        ColumnasDeLaAppIaHelper::olvidar();

        parent::tearDown();
    }

    /**
     * Una fijada vieja y una sin fijar nueva.
     *
     * @return array{0: \App\Models\AiConversation, 1: \App\Models\AiConversation}
     */
    protected function una_fijada_vieja_y_una_nueva()
    {
        $vieja = $this->conversacion(['titulo' => 'Vieja fijada', 'last_message_at' => now()->subDays(5), 'fijada' => true]);
        $nueva = $this->conversacion(['titulo' => 'Nueva', 'last_message_at' => now()]);

        return [$vieja, $nueva];
    }

    public function test_con_el_token_de_la_app_las_fijadas_van_primero()
    {
        list($vieja, $nueva) = $this->una_fijada_vieja_y_una_nueva();

        $ids = array_column($this->getJson('api/ai-conversations', $this->de_la_app())->assertStatus(200)->json('models'), 'id');

        $this->assertSame([$vieja->id, $nueva->id], $ids);
    }

    public function test_con_la_cookie_del_sistema_el_orden_es_el_de_siempre()
    {
        list($vieja, $nueva) = $this->una_fijada_vieja_y_una_nueva();

        $this->actingAs($this->dueno, 'web');

        $modelos = $this->getJson('api/ai-conversations')->assertStatus(200)->json('models');

        $this->assertSame([$nueva->id, $vieja->id], array_column($modelos, 'id'), 'La fijada no le cambia al panel cuál abre.');
        $this->assertTrue($modelos[1]['fijada'], 'El dato viaja igual.');
    }

    /**
     * Las consultas SQL que corre $hacer.
     *
     * @param  callable  $hacer
     * @return array<int, string>
     */
    protected function consultas_de(callable $hacer)
    {
        $consultas = [];

        DB::listen(function ($consulta) use (&$consultas) {
            $consultas[] = (string) $consulta->sql;
        });

        $hacer();

        return $consultas;
    }

    public function test_sin_las_columnas_nuevas_el_chat_no_las_toca()
    {
        ColumnasDeLaAppIaHelper::fingir('ai_conversations', 'fijada', false);
        ColumnasDeLaAppIaHelper::fingir('ai_messages', 'progreso', false);
        ColumnasDeLaAppIaHelper::fingir('ai_messages', 'archivos', false);

        $conversation = $this->conversacion(['titulo' => 'Huérfana']);

        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'estado' => 'listo', 'contenido' => 'hola']);

        // Un pendiente vencido: el próximo POST lo cierra.
        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'assistant',
            'estado'             => 'pendiente',
            'created_at'         => now()->subMinutes(30),
        ]);

        Queue::fake();

        $consultas = $this->consultas_de(function () use ($conversation) {

            $this->getJson('api/ai-conversations', $this->de_la_app())->assertStatus(200);

            $this->como_otro_cliente();

            $this->postJson('api/ai-conversations/' . $conversation->id . '/messages', ['contenido' => 'sigo'], $this->de_la_app())
                ->assertStatus(201);

            (new AsistenteIaService())->build_messages_payload($conversation);
        });

        foreach ($consultas as $sql) {
            $this->assertStringNotContainsString('fijada', $sql);
            $this->assertStringNotContainsString('progreso', $sql);
            $this->assertStringNotContainsString('`archivos`', $sql);
        }

        $this->assertSame(
            'error',
            AiMessage::where('ai_conversation_id', $conversation->id)->where('rol', 'assistant')->orderBy('id')->value('estado'),
            'El huérfano igual se cierra.'
        );
    }

    public function test_con_las_columnas_el_historial_suma_los_mensajes_con_archivo()
    {
        $conversation = $this->conversacion(['titulo' => 'Archivo']);

        $mensaje = AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'estado' => 'listo', 'contenido' => '']);
        $mensaje->archivos = [[
            'orden' => 1, 'nombre' => 'lista.csv', 'tipo' => 'csv', 'mime' => 'text/csv', 'tamano' => 10,
            'path' => 'x', 'estado' => 'listo', 'texto' => 'A1 | Tornillo', 'recortado' => false, 'error' => null,
        ]];
        $mensaje->save();

        $payload = (new AsistenteIaService())->build_messages_payload($conversation);

        $this->assertCount(1, $payload);
        $this->assertStringContainsString('A1 | Tornillo', $payload[0]['content']);
    }
}
