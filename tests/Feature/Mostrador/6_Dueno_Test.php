<?php

namespace Tests\Feature\Mostrador;

use App\Models\AiConversation;
use App\Models\MostradorReporte;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Misión modulo-ia-mostrador — P6: los endpoints del dueño (mostrador/reportes/*).
 *
 * El escritorio lista solo los informes 'listo' del dueño (ni los que están en
 * 'hechos' sin texto, ni los de otro dueño), con el más nuevo de cada tipo en
 * `ultimos` y el resto en `anteriores`; un empleado sin admin_access recibe 403 y
 * uno con admin_access entra; abrir un informe estampa leido_at una sola vez; y la
 * conversación es idempotente por (informe, persona) y nace con el título y un texto
 * del contenido en su contexto.
 */
class Dueno_Test extends MostradorTestCase
{
    /**
     * @group mostrador
     * @test
     */
    public function sin_la_extension_todas_las_rutas_devuelven_403()
    {
        $this->entrar_como($this->comercio);

        $reporte = $this->reporte_listo('dia', $this->ayer->format('Y-m-d'));

        $this->getJson('api/mostrador/reportes')->assertStatus(403);
        $this->getJson('api/mostrador/reportes/' . $reporte->id)->assertStatus(403);
        $this->postJson('api/mostrador/reportes/' . $reporte->id . '/conversacion')->assertStatus(403);
    }

    /**
     * @group mostrador
     * @test
     */
    public function el_escritorio_lista_solo_los_informes_listos_del_dueno()
    {
        $this->dar_extension();
        $this->entrar_como($this->comercio);

        $dia_ayer   = $this->reporte_listo('dia', $this->ayer->format('Y-m-d'));
        $dia_viejo  = $this->reporte_listo('dia', $this->ayer->copy()->subDays(3)->format('Y-m-d'));
        $stock_hoy  = $this->reporte_listo('stock', now()->format('Y-m-d'));
        $muy_viejo  = $this->reporte_listo('tienda', $this->ayer->copy()->subDays(40)->format('Y-m-d'));

        // Con hechos y sin texto: no se lista.
        MostradorReporte::create([
            'user_id' => $this->comercio->id,
            'tipo'    => 'compras',
            'fecha'   => now()->format('Y-m-d'),
            'hechos'  => ['aplica' => true],
        ]);

        // De otro dueño: no se lista.
        $otro = User::create(['name' => 'Otro', 'email' => 'otro-' . uniqid() . '@test.local', 'password' => Hash::make('secret')]);
        $this->reporte_listo('dia', $this->ayer->format('Y-m-d'), $otro->id);

        $respuesta = $this->getJson('api/mostrador/reportes');

        $respuesta->assertStatus(200);

        // Orden fijo por tipo: dia, (tienda no tiene uno reciente), (compras no está listo), stock.
        $this->assertSame([$dia_ayer->id, $stock_hoy->id], array_column($respuesta->json('ultimos'), 'id'));
        $this->assertSame([$dia_viejo->id], array_column($respuesta->json('anteriores'), 'id'));

        $fila = $respuesta->json('ultimos.0');
        $this->assertSame([
            'id', 'tipo', 'fecha', 'titulo', 'resumen', 'generado_at', 'leido_at', 'conversation_id',
        ], array_keys($fila));
        $this->assertSame('dia', $fila['tipo']);
        $this->assertSame($this->ayer->format('Y-m-d'), $fila['fecha']);
        $this->assertNull($fila['leido_at']);
        $this->assertNull($fila['conversation_id']);
        $this->assertArrayNotHasKey('contenido', $fila);
        $this->assertArrayNotHasKey('hechos', $fila);

        // El de hace 40 días queda afuera de los 30.
        $this->assertNotContains($muy_viejo->id, array_column($respuesta->json('anteriores'), 'id'));
    }

    /**
     * @group mostrador
     * @test
     */
    public function un_empleado_sin_admin_access_recibe_403_y_con_admin_access_entra()
    {
        $this->dar_extension();

        $reporte = $this->reporte_listo('dia', $this->ayer->format('Y-m-d'));

        $this->entrar_como($this->empleado(false));

        $this->getJson('api/mostrador/reportes')
            ->assertStatus(403)
            ->assertJsonPath('message', 'Solo el dueño puede ver el mostrador.');
        $this->getJson('api/mostrador/reportes/' . $reporte->id)->assertStatus(403);
        $this->postJson('api/mostrador/reportes/' . $reporte->id . '/conversacion')->assertStatus(403);

        $this->entrar_como($this->empleado(true));

        $this->getJson('api/mostrador/reportes')->assertStatus(200)->assertJsonCount(1, 'ultimos');
        $this->getJson('api/mostrador/reportes/' . $reporte->id)->assertStatus(200);
    }

    /**
     * @group mostrador
     * @test
     */
    public function abrir_un_informe_devuelve_el_contenido_y_estampa_leido_at_una_sola_vez()
    {
        $this->dar_extension();
        $this->entrar_como($this->comercio);

        $reporte = $this->reporte_listo('dia', $this->ayer->format('Y-m-d'));

        $respuesta = $this->getJson('api/mostrador/reportes/' . $reporte->id);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('model.id', $reporte->id);
        $respuesta->assertJsonPath('model.titulo', 'Rendimiento de ayer');
        $respuesta->assertJsonPath('model.contenido.bloques.0.tipo', 'resumen');
        $respuesta->assertJsonPath('model.conversation_id', null);
        $this->assertArrayNotHasKey('hechos', $respuesta->json('model'));
        $this->assertNotNull($respuesta->json('model.leido_at'));

        $primera_lectura = $reporte->fresh()->leido_at->toDateTimeString();

        // Una segunda apertura no mueve la marca.
        MostradorReporte::where('id', $reporte->id)->update(['leido_at' => '2026-01-01 08:00:00']);

        $this->getJson('api/mostrador/reportes/' . $reporte->id)
            ->assertStatus(200)
            ->assertJsonPath('model.leido_at', '2026-01-01 08:00:00');

        $this->assertSame('2026-01-01 08:00:00', $reporte->fresh()->leido_at->toDateTimeString());
        $this->assertNotSame($primera_lectura, '2026-01-01 08:00:00');

        // Sin texto todavía: 404. De otro dueño: 404.
        $sin_texto = MostradorReporte::create([
            'user_id' => $this->comercio->id,
            'tipo'    => 'stock',
            'fecha'   => now()->format('Y-m-d'),
            'hechos'  => ['aplica' => false],
        ]);

        $this->getJson('api/mostrador/reportes/' . $sin_texto->id)->assertStatus(404);

        $otro = User::create(['name' => 'Otro', 'email' => 'otro-' . uniqid() . '@test.local', 'password' => Hash::make('secret')]);
        $ajeno = $this->reporte_listo('dia', $this->ayer->format('Y-m-d'), $otro->id);

        $this->getJson('api/mostrador/reportes/' . $ajeno->id)->assertStatus(404);
    }

    /**
     * @group mostrador
     * @test
     */
    public function la_conversacion_es_idempotente_y_nace_con_el_informe_como_contexto()
    {
        $this->dar_extension();
        $this->entrar_como($this->comercio);

        $reporte = $this->reporte_listo('dia', $this->ayer->format('Y-m-d'));

        $primera = $this->postJson('api/mostrador/reportes/' . $reporte->id . '/conversacion');

        $primera->assertStatus(201);
        $conversation_id = $primera->json('model.id');
        $this->assertNotNull($conversation_id);

        $primera->assertJsonPath('model.origen', 'mostrador_reporte');
        $primera->assertJsonPath('model.referencia_id', $reporte->id);
        $primera->assertJsonPath('model.user_id', $this->comercio->id);
        $primera->assertJsonPath('model.auth_user_id', $this->comercio->id);
        $primera->assertJsonPath('model.titulo', 'Rendimiento de ayer · ' . $this->ayer->format('d/m'));
        $this->assertNotNull($primera->json('model.last_message_at'));

        $conversation = AiConversation::find($conversation_id);
        $this->assertStringContainsString('Rendimiento de ayer', $conversation->contexto);
        $this->assertStringContainsString('Ayer se vendió bien y quedaron dos cobranzas pendientes.', $conversation->contexto);
        $this->assertStringContainsString('El cliente Pérez debe hace 40 días', $conversation->contexto);
        $this->assertStringContainsString('Hechos: {', $conversation->contexto);
        $this->assertStringContainsString('"aplica":true', $conversation->contexto);
        $this->assertLessThanOrEqual(12000, mb_strlen($conversation->contexto));

        // Segunda vez: la misma, sin crear otra.
        $segunda = $this->postJson('api/mostrador/reportes/' . $reporte->id . '/conversacion');
        $segunda->assertStatus(200);
        $this->assertSame($conversation_id, $segunda->json('model.id'));
        $this->assertSame(1, AiConversation::where('origen', 'mostrador_reporte')->where('referencia_id', $reporte->id)->count());

        // El escritorio y el informe abierto la muestran.
        $this->getJson('api/mostrador/reportes')->assertJsonPath('ultimos.0.conversation_id', $conversation_id);
        $this->getJson('api/mostrador/reportes/' . $reporte->id)->assertJsonPath('model.conversation_id', $conversation_id);

        // Y es una conversación común para el chat: la persona la lee por ai-conversations.
        $this->getJson('api/ai-conversations/' . $conversation_id . '/messages')->assertStatus(200);

        // Otra persona de la cuenta (admin_access) tiene la suya, no la del dueño.
        $this->entrar_como($this->empleado(true));

        $tercera = $this->postJson('api/mostrador/reportes/' . $reporte->id . '/conversacion');
        $tercera->assertStatus(201);
        $this->assertNotSame($conversation_id, $tercera->json('model.id'));
        $this->getJson('api/mostrador/reportes')->assertJsonPath('ultimos.0.conversation_id', $tercera->json('model.id'));

        // Sobre un informe sin texto no hay conversación.
        $sin_texto = MostradorReporte::create([
            'user_id' => $this->comercio->id,
            'tipo'    => 'stock',
            'fecha'   => now()->format('Y-m-d'),
        ]);

        $this->postJson('api/mostrador/reportes/' . $sin_texto->id . '/conversacion')->assertStatus(404);
    }

    /**
     * Un informe listo (con texto) de un dueño.
     *
     * @param string $tipo
     * @param string $fecha
     * @param int|null $user_id
     * @return MostradorReporte
     */
    protected function reporte_listo($tipo, $fecha, $user_id = null)
    {
        return MostradorReporte::create([
            'user_id'     => $user_id ?: $this->comercio->id,
            'tipo'        => $tipo,
            'fecha'       => $fecha,
            'titulo'      => 'Rendimiento de ayer',
            'resumen'     => 'Tres ventas y un pago.',
            'hechos'      => ['aplica' => true, 'fecha' => $fecha, 'ventas' => ['cantidad' => 3]],
            'contenido'   => $this->contenido_valido(),
            'estado'      => 'listo',
            'hechos_at'   => now(),
            'generado_at' => now(),
        ]);
    }
}
