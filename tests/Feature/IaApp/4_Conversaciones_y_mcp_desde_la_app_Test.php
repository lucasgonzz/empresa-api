<?php

namespace Tests\Feature\IaApp;

use App\Models\AiConversation;
use App\Models\AiMessage;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Misión app-ia-y-memoria — la barra lateral de la app y la conexión MCP desde su configuración
 * (contrato C5, §4 E1.8 y E1.9 del plan).
 *
 * Lo que protege este archivo:
 *   1. `GET ai-conversations?buscar=` filtra por título y por lo que dicen los mensajes (con los
 *      comodines de LIKE escapados), cada conversación trae `fijada` y las fijadas van primero.
 *   2. `PATCH ai-conversations/{id}` renombra y fija, con validación y la tenencia de siempre.
 *   3. `mcp/conexion` se puede administrar con un token `ia-app` (la configuración de la app), pero
 *      NUNCA con una clave `mcp` (una clave filtrada no puede fabricarse otra).
 */
class Conversaciones_y_mcp_desde_la_app_Test extends IaAppTestCase
{
    public function test_buscar_por_titulo_y_por_contenido_de_los_mensajes()
    {
        $por_titulo = $this->conversacion(['titulo' => 'Precios de Distribuidora Sur', 'last_message_at' => now()->subDay()]);
        $por_mensaje = $this->conversacion(['titulo' => 'Charla del lunes', 'last_message_at' => now()]);
        $ninguna = $this->conversacion(['titulo' => 'Otra cosa', 'last_message_at' => now()]);

        AiMessage::create([
            'ai_conversation_id' => $por_mensaje->id, 'rol' => 'user', 'estado' => 'listo',
            'contenido' => '¿cuánto le debo a distribuidora sur?',
        ]);
        AiMessage::create([
            'ai_conversation_id' => $ninguna->id, 'rol' => 'user', 'estado' => 'listo',
            'contenido' => 'nada que ver',
        ]);

        $ids = array_column($this->getJson('api/ai-conversations?buscar=' . rawurlencode('distribuidora sur'), $this->de_la_app())
            ->assertStatus(200)
            ->json('models'), 'id');

        $this->assertSame([$por_mensaje->id, $por_titulo->id], $ids);

        $this->como_otro_cliente();

        // Un % no es un comodín: busca el carácter.
        $this->assertSame([], $this->getJson('api/ai-conversations?buscar=%25', $this->de_la_app())->json('models'));

        $this->como_otro_cliente();

        // Sin buscar, todas.
        $this->assertCount(3, $this->getJson('api/ai-conversations', $this->de_la_app())->json('models'));
    }

    public function test_fijada_viaja_en_cada_conversacion_y_las_fijadas_van_primero()
    {
        $vieja = $this->conversacion(['titulo' => 'Vieja', 'last_message_at' => now()->subDays(5)]);
        $nueva = $this->conversacion(['titulo' => 'Nueva', 'last_message_at' => now()]);

        $modelos = $this->getJson('api/ai-conversations', $this->de_la_app())->json('models');

        $this->assertSame([$nueva->id, $vieja->id], array_column($modelos, 'id'));
        $this->assertFalse($modelos[0]['fijada']);

        $this->como_otro_cliente();

        $this->patchJson('api/ai-conversations/' . $vieja->id, ['fijada' => true], $this->de_la_app())
            ->assertStatus(200)
            ->assertJsonPath('model.id', $vieja->id)
            ->assertJsonPath('model.fijada', true);

        $this->como_otro_cliente();

        $modelos = $this->getJson('api/ai-conversations', $this->de_la_app())->json('models');

        $this->assertSame([$vieja->id, $nueva->id], array_column($modelos, 'id'));
        $this->assertTrue($modelos[0]['fijada']);
    }

    public function test_renombrar_y_sus_validaciones()
    {
        $conversation = $this->conversacion(['titulo' => 'Nueva conversación']);

        $this->patchJson('api/ai-conversations/' . $conversation->id, ['titulo' => '  Ventas de octubre  '], $this->de_la_app())
            ->assertStatus(200)
            ->assertJsonPath('model.titulo', 'Ventas de octubre');

        $this->assertSame('Ventas de octubre', $conversation->fresh()->titulo);

        $this->como_otro_cliente();

        $this->patchJson('api/ai-conversations/' . $conversation->id, ['titulo' => str_repeat('x', 121)], $this->de_la_app())
            ->assertStatus(422)
            ->assertJsonPath('code', 'validacion');

        $this->como_otro_cliente();

        $this->patchJson('api/ai-conversations/' . $conversation->id, ['titulo' => ''], $this->de_la_app())
            ->assertStatus(422);

        $this->como_otro_cliente();

        $this->patchJson('api/ai-conversations/' . $conversation->id, ['fijada' => 'quizás'], $this->de_la_app())
            ->assertStatus(422);

        $this->assertSame('Ventas de octubre', $conversation->fresh()->titulo);
        $this->assertFalse((bool) $conversation->fresh()->fijada);
    }

    public function test_no_se_puede_tocar_la_conversacion_de_otra_persona()
    {
        $encargado = $this->empleado(1);

        $ajena = AiConversation::create([
            'user_id'      => $this->dueno->id,
            'auth_user_id' => $encargado->id,
            'titulo'       => 'La del encargado',
        ]);

        $this->patchJson('api/ai-conversations/' . $ajena->id, ['titulo' => 'Mía'], $this->de_la_app())->assertStatus(404);

        $this->assertSame('La del encargado', $ajena->fresh()->titulo);
    }

    public function test_la_conexion_mcp_se_administra_desde_la_app_pero_no_con_una_clave_mcp()
    {
        $this->postJson('api/mcp/conexion', [], $this->de_la_app())
            ->assertStatus(201)
            ->assertJsonPath('activa', true);

        $mcp = PersonalAccessToken::where('tokenable_id', $this->dueno->id)->where('name', 'mcp')->first();

        $this->assertNotNull($mcp);
        $this->assertSame(['mcp'], $mcp->abilities);

        $this->como_otro_cliente();

        $this->getJson('api/mcp/conexion', $this->de_la_app())->assertStatus(200)->assertJsonPath('activa', true);

        // Con la clave MCP no: una clave filtrada no puede fabricarse otra.
        $clave_mcp = $this->dueno->createToken('mcp', ['mcp'])->plainTextToken;

        $this->como_otro_cliente();

        $this->postJson('api/mcp/conexion', [], $this->de_la_app($clave_mcp))->assertStatus(403);

        $this->como_otro_cliente();

        $this->deleteJson('api/mcp/conexion', [], $this->de_la_app())->assertStatus(200)->assertJsonPath('activa', false);

        $this->assertSame(0, PersonalAccessToken::where('tokenable_id', $this->dueno->id)->where('name', 'mcp')->count());
        $this->assertNotNull(PersonalAccessToken::findToken($this->token), 'Revocar la clave MCP no toca el token de la app.');
    }
}
