<?php

namespace Tests\Feature\Mostrador;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\MostradorMemoria;
use App\Models\MostradorReporte;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Misión modulo-ia-mostrador — P5: los endpoints admin-sync/mostrador/* que consume
 * la skill /mostrador.
 *
 * POST hechos crea la fila por la unique y la actualiza sin pisar el contenido que
 * depositó la skill; PUT reportes/{id} acepta un contenido válido y rechaza con 422
 * y errores legibles uno con un bloque desconocido, uno sin resumen primero y uno con
 * un tono inválido; GET contexto devuelve la memoria, los mensajes nuevos y los no
 * leídos; PUT memoria hace upsert; y con ADMIN_SYNC_REQUIRE_API_KEY el header manda.
 */
class Admin_sync_Test extends MostradorTestCase
{
    /** Ruta base del grupo. */
    const BASE = 'api/admin-sync/mostrador';

    /**
     * @group mostrador
     * @test
     */
    public function hechos_devuelve_404_sin_dueno_o_sin_extension_y_422_con_tipo_o_fecha_invalidos()
    {
        // Dueño sin la extensión.
        $this->postJson(self::BASE . '/hechos', ['user_id' => $this->comercio->id, 'tipo' => 'dia'])
            ->assertStatus(404);

        // Dueño inexistente.
        $this->dar_extension();

        $this->postJson(self::BASE . '/hechos', ['user_id' => 999999999, 'tipo' => 'dia'])
            ->assertStatus(404);

        // Un empleado no es un dueño.
        $this->postJson(self::BASE . '/hechos', ['user_id' => $this->empleado()->id, 'tipo' => 'dia'])
            ->assertStatus(404);

        $this->postJson(self::BASE . '/hechos', ['user_id' => $this->comercio->id, 'tipo' => 'ventas'])
            ->assertStatus(422);

        $this->postJson(self::BASE . '/hechos', ['user_id' => $this->comercio->id])
            ->assertStatus(422);

        $this->postJson(self::BASE . '/hechos', ['user_id' => $this->comercio->id, 'tipo' => 'dia', 'fecha' => '13/09/2026'])
            ->assertStatus(422);

        $this->assertSame(0, MostradorReporte::where('user_id', $this->comercio->id)->count());
    }

    /**
     * @group mostrador
     * @test
     */
    public function hechos_crea_la_fila_y_la_vuelve_a_calcular_sin_pisar_el_contenido()
    {
        $this->dar_extension();

        $respuesta = $this->postJson(self::BASE . '/hechos', ['user_id' => $this->comercio->id, 'tipo' => 'dia']);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('tipo', 'dia');
        $respuesta->assertJsonPath('estado', 'hechos');
        $respuesta->assertJsonPath('fecha', $this->ayer->format('Y-m-d'));
        $respuesta->assertJsonPath('hechos.aplica', true);
        $respuesta->assertJsonPath('hechos.ventas.cantidad', 0);

        $reporte_id = $respuesta->json('reporte_id');

        $reporte = MostradorReporte::find($reporte_id);
        $this->assertNotNull($reporte);
        $this->assertNotNull($reporte->hechos_at);
        $this->assertNull($reporte->contenido);

        // Segunda corrida el mismo día: la misma fila (la unique), sin duplicar.
        $segunda = $this->postJson(self::BASE . '/hechos', ['user_id' => $this->comercio->id, 'tipo' => 'dia', 'fecha' => $this->ayer->format('Y-m-d')]);
        $segunda->assertStatus(200);
        $this->assertSame($reporte_id, $segunda->json('reporte_id'));
        $this->assertSame(1, MostradorReporte::where('user_id', $this->comercio->id)->count());

        // La skill deposita el texto.
        $this->putJson(self::BASE . '/reportes/' . $reporte_id, [
            'titulo'    => 'Un jueves tranquilo',
            'resumen'   => 'Sin ventas, dos cobranzas pendientes.',
            'contenido' => $this->contenido_valido(),
        ])->assertStatus(200);

        // Ya listo y sin forzar: devuelve lo guardado sin recalcular.
        MostradorReporte::where('id', $reporte_id)->update(['hechos_at' => '2026-01-01 05:00:00']);

        $tercera = $this->postJson(self::BASE . '/hechos', ['user_id' => $this->comercio->id, 'tipo' => 'dia']);
        $tercera->assertStatus(200);
        $tercera->assertJsonPath('estado', 'listo');
        $tercera->assertJsonPath('hechos.aplica', true);

        $reporte->refresh();
        $this->assertSame('2026-01-01 05:00:00', $reporte->hechos_at->toDateTimeString());
        $this->assertSame('Un jueves tranquilo', $reporte->titulo);

        // Con forzar recalcula los hechos y el contenido sigue intacto.
        $cuarta = $this->postJson(self::BASE . '/hechos', ['user_id' => $this->comercio->id, 'tipo' => 'dia', 'forzar' => true]);
        $cuarta->assertStatus(200);
        $cuarta->assertJsonPath('estado', 'listo');

        $reporte->refresh();
        $this->assertNotSame('2026-01-01 05:00:00', $reporte->hechos_at->toDateTimeString());
        $this->assertSame('listo', $reporte->estado);
        $this->assertSame('Un jueves tranquilo', $reporte->titulo);
        $this->assertSame('resumen', $reporte->contenido['bloques'][0]['tipo']);
    }

    /**
     * @group mostrador
     * @test
     */
    public function hechos_de_stock_con_una_sucursal_guarda_aplica_false()
    {
        $this->dar_extension();
        $this->sucursal('Única');

        $respuesta = $this->postJson(self::BASE . '/hechos', ['user_id' => $this->comercio->id, 'tipo' => 'stock']);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('hechos.aplica', false);
        $respuesta->assertJsonPath('fecha', now()->format('Y-m-d'));

        $this->assertSame('hechos', MostradorReporte::find($respuesta->json('reporte_id'))->estado);
    }

    /**
     * @group mostrador
     * @test
     */
    public function depositar_acepta_un_contenido_valido_y_deja_el_informe_listo()
    {
        $reporte = $this->reporte_con_hechos();

        $respuesta = $this->putJson(self::BASE . '/reportes/' . $reporte->id, [
            'titulo'    => 'Rendimiento de ayer',
            'resumen'   => 'Tres ventas y un pago.',
            'contenido' => $this->contenido_valido(),
        ]);

        $respuesta->assertStatus(200);
        $respuesta->assertExactJson(['ok' => true, 'reporte_id' => $reporte->id]);

        $reporte->refresh();
        $this->assertSame('listo', $reporte->estado);
        $this->assertNotNull($reporte->generado_at);
        $this->assertSame('Rendimiento de ayer', $reporte->titulo);
        $this->assertSame('Tres ventas y un pago.', $reporte->resumen);
        $this->assertCount(8, $reporte->contenido['bloques']);

        // También como string JSON (el script de la skill arma el body desde un archivo).
        $otro = $this->reporte_con_hechos('tienda');

        $this->putJson(self::BASE . '/reportes/' . $otro->id, [
            'titulo'    => 'Tu tienda',
            'resumen'   => 'Dos pedidos.',
            'contenido' => json_encode($this->contenido_valido()),
        ])->assertStatus(200);

        $this->assertSame('listo', $otro->fresh()->estado);
    }

    /**
     * @group mostrador
     * @test
     */
    public function depositar_rechaza_con_422_y_errores_legibles_lo_que_no_cumple_el_formato()
    {
        $reporte = $this->reporte_con_hechos();

        $this->putJson(self::BASE . '/reportes/999999999', [
            'titulo' => 'x', 'resumen' => 'x', 'contenido' => $this->contenido_valido(),
        ])->assertStatus(404);

        // Un bloque desconocido.
        $contenido = $this->contenido_valido();
        $contenido['bloques'][1] = ['tipo' => 'grafico', 'texto' => 'no existe'];

        $respuesta = $this->depositar($reporte, $contenido);
        $respuesta->assertStatus(422);
        $this->assertStringContainsString('bloques[1].tipo', implode("\n", $respuesta->json('errores')));

        // Sin resumen primero.
        $contenido = $this->contenido_valido();
        $contenido['bloques'][0] = ['tipo' => 'parrafo', 'texto' => 'Arranca con un párrafo.'];

        $respuesta = $this->depositar($reporte, $contenido);
        $respuesta->assertStatus(422);
        $this->assertStringContainsString('primer bloque tiene que ser "resumen"', implode("\n", $respuesta->json('errores')));

        // Un tono inválido.
        $contenido = $this->contenido_valido();
        $contenido['bloques'][1]['items'][0]['tono'] = 'verde';

        $respuesta = $this->depositar($reporte, $contenido);
        $respuesta->assertStatus(422);
        $this->assertStringContainsString('bloques[1].items[0].tono', implode("\n", $respuesta->json('errores')));

        // Una clave desconocida.
        $contenido = $this->contenido_valido();
        $contenido['bloques'][0]['html'] = '<b>no</b>';

        $respuesta = $this->depositar($reporte, $contenido);
        $respuesta->assertStatus(422);
        $this->assertStringContainsString('bloques[0].html: clave desconocida', implode("\n", $respuesta->json('errores')));

        // Una acción con tipo fuera de la lista y una imagen que no es http(s).
        $contenido = $this->contenido_valido();
        $contenido['bloques'][7]['items'][0]['tipo'] = 'vender';
        $contenido['bloques'][6]['items'][0]['imagen_url'] = 'javascript:alert(1)';

        $respuesta = $this->depositar($reporte, $contenido);
        $respuesta->assertStatus(422);
        $errores = implode("\n", $respuesta->json('errores'));
        $this->assertStringContainsString('bloques[7].items[0].tipo', $errores);
        $this->assertStringContainsString('bloques[6].items[0].imagen_url', $errores);

        // Versión distinta y demasiadas cifras.
        $contenido = $this->contenido_valido();
        $contenido['version'] = 2;
        $contenido['bloques'][1]['items'] = array_fill(0, 7, ['etiqueta' => 'x', 'valor' => '1']);

        $respuesta = $this->depositar($reporte, $contenido);
        $respuesta->assertStatus(422);
        $errores = implode("\n", $respuesta->json('errores'));
        $this->assertStringContainsString('contenido.version', $errores);
        $this->assertStringContainsString('bloques[1].items: tiene que tener entre 1 y 6', $errores);

        // Sin título.
        $this->putJson(self::BASE . '/reportes/' . $reporte->id, [
            'resumen' => 'x', 'contenido' => $this->contenido_valido(),
        ])->assertStatus(422);

        // Nada de eso dejó el informe listo.
        $this->assertSame('hechos', $reporte->fresh()->estado);
    }

    /**
     * @group mostrador
     * @test
     */
    public function contexto_devuelve_memoria_mensajes_nuevos_y_no_leidos()
    {
        $this->dar_extension();

        $this->getJson(self::BASE . '/contexto/999999999')->assertStatus(404);

        $reporte = $this->reporte_con_hechos();
        $reporte->update([
            'titulo'      => 'Rendimiento de ayer',
            'resumen'     => 'Tres ventas.',
            'contenido'   => $this->contenido_valido(),
            'estado'      => 'listo',
            'generado_at' => now(),
        ]);

        $conversacion = AiConversation::create([
            'user_id'       => $this->comercio->id,
            'auth_user_id'  => $this->comercio->id,
            'titulo'        => 'Rendimiento de ayer · 13/09',
            'origen'        => 'mostrador_reporte',
            'referencia_id' => $reporte->id,
        ]);

        $pregunta = AiMessage::create(['ai_conversation_id' => $conversacion->id, 'rol' => 'user', 'contenido' => '¿Quién me debe más?', 'estado' => 'listo']);
        $respuesta_ia = AiMessage::create(['ai_conversation_id' => $conversacion->id, 'rol' => 'assistant', 'contenido' => 'Pérez, $900.', 'estado' => 'listo']);
        AiMessage::create(['ai_conversation_id' => $conversacion->id, 'rol' => 'assistant', 'contenido' => null, 'estado' => 'pendiente']);

        // Una conversación del chat común no entra.
        $otra = AiConversation::create(['user_id' => $this->comercio->id, 'auth_user_id' => $this->comercio->id]);
        AiMessage::create(['ai_conversation_id' => $otra->id, 'rol' => 'user', 'contenido' => 'hola', 'estado' => 'listo']);

        $respuesta = $this->getJson(self::BASE . '/contexto/' . $this->comercio->id);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('memoria', null);
        $respuesta->assertJsonCount(1, 'conversaciones_nuevas');
        $respuesta->assertJsonPath('conversaciones_nuevas.0.reporte_id', $reporte->id);
        $respuesta->assertJsonPath('conversaciones_nuevas.0.tipo', 'dia');
        $respuesta->assertJsonPath('conversaciones_nuevas.0.titulo', 'Rendimiento de ayer');
        $respuesta->assertJsonCount(2, 'conversaciones_nuevas.0.mensajes');
        $respuesta->assertJsonPath('conversaciones_nuevas.0.mensajes.0.id', $pregunta->id);
        $respuesta->assertJsonPath('conversaciones_nuevas.0.mensajes.0.rol', 'user');
        $respuesta->assertJsonPath('conversaciones_nuevas.0.mensajes.1.contenido', 'Pérez, $900.');
        $respuesta->assertJsonCount(1, 'no_leidos');
        $respuesta->assertJsonPath('no_leidos.0.reporte_id', $reporte->id);
        $respuesta->assertJsonPath('no_leidos.0.resumen', 'Tres ventas.');

        // La memoria mueve el "desde": solo lo posterior al último sintetizado.
        $this->putJson(self::BASE . '/memoria/' . $this->comercio->id, [
            'texto'               => 'Le importan las cobranzas.',
            'hasta_ai_message_id' => $pregunta->id,
        ])->assertStatus(200)->assertExactJson(['ok' => true]);

        $respuesta = $this->getJson(self::BASE . '/contexto/' . $this->comercio->id);
        $respuesta->assertJsonPath('memoria.texto', 'Le importan las cobranzas.');
        $respuesta->assertJsonPath('memoria.hasta_ai_message_id', $pregunta->id);
        $respuesta->assertJsonCount(1, 'conversaciones_nuevas.0.mensajes');
        $respuesta->assertJsonPath('conversaciones_nuevas.0.mensajes.0.id', $respuesta_ia->id);

        // El query param pisa la memoria.
        $this->getJson(self::BASE . '/contexto/' . $this->comercio->id . '?desde_ai_message_id=0')
            ->assertJsonCount(2, 'conversaciones_nuevas.0.mensajes');

        $this->getJson(self::BASE . '/contexto/' . $this->comercio->id . '?desde_ai_message_id=' . $respuesta_ia->id)
            ->assertJsonCount(0, 'conversaciones_nuevas');

        // Leído: deja de estar en no_leidos.
        $reporte->update(['leido_at' => now()]);

        $this->getJson(self::BASE . '/contexto/' . $this->comercio->id)->assertJsonCount(0, 'no_leidos');
    }

    /**
     * @group mostrador
     * @test
     */
    public function memoria_hace_upsert()
    {
        $this->putJson(self::BASE . '/memoria/999999999', ['texto' => 'x', 'hasta_ai_message_id' => 1])->assertStatus(404);

        $this->putJson(self::BASE . '/memoria/' . $this->comercio->id, ['texto' => ['no', 'es', 'texto'], 'hasta_ai_message_id' => 1])->assertStatus(422);
        $this->putJson(self::BASE . '/memoria/' . $this->comercio->id, ['texto' => 'x', 'hasta_ai_message_id' => 'doce'])->assertStatus(422);

        $this->putJson(self::BASE . '/memoria/' . $this->comercio->id, ['texto' => 'Primera síntesis.', 'hasta_ai_message_id' => 10])
            ->assertStatus(200);
        $this->putJson(self::BASE . '/memoria/' . $this->comercio->id, ['texto' => 'Segunda síntesis.', 'hasta_ai_message_id' => 25])
            ->assertStatus(200);

        $this->assertSame(1, MostradorMemoria::where('user_id', $this->comercio->id)->count());

        $memoria = MostradorMemoria::where('user_id', $this->comercio->id)->first();
        $this->assertSame('Segunda síntesis.', $memoria->texto);
        $this->assertSame(25, $memoria->hasta_ai_message_id);
    }

    /**
     * @group mostrador
     * @test
     */
    public function duenos_lista_solo_los_que_tienen_la_extension()
    {
        $this->getJson(self::BASE . '/duenos')->assertStatus(200)->assertJsonMissing(['user_id' => $this->comercio->id]);

        $this->dar_extension();
        $this->sucursal('Casa central');
        $this->sucursal('Norte');

        $respuesta = $this->getJson(self::BASE . '/duenos');
        $respuesta->assertStatus(200);

        $fila = collect($respuesta->json('duenos'))->firstWhere('user_id', $this->comercio->id);

        $this->assertNotNull($fila);
        $this->assertSame('Ferretería Mostrador', $fila['nombre']);
        $this->assertSame($this->comercio->email, $fila['email']);
        $this->assertFalse($fila['tiene_tienda']);
        $this->assertSame(2, $fila['sucursales']);
        $this->assertNull($fila['ultimo_reporte_at']);

        // Con USER_ID configurado, la instancia atiende a ese dueño y a nadie más.
        $otro = User::create(['name' => 'Otro dueño', 'email' => 'otro-' . uniqid() . '@test.local', 'password' => Hash::make('secret')]);
        $this->dar_extension($otro);

        config(['app.USER_ID' => $otro->id]);

        $ids = array_column($this->getJson(self::BASE . '/duenos')->json('duenos'), 'user_id');
        $this->assertSame([$otro->id], $ids);
    }

    /**
     * @group mostrador
     * @test
     */
    public function con_la_clave_exigida_el_header_manda()
    {
        config(['services.admin_api.require_api_key' => true]);
        config(['services.admin_api.api_key' => 'clave-de-prueba']);

        $this->getJson(self::BASE . '/duenos')->assertStatus(401);

        $this->getJson(self::BASE . '/duenos', ['X-Admin-Api-Key' => 'otra'])->assertStatus(401);

        $this->getJson(self::BASE . '/duenos', ['X-Admin-Api-Key' => 'clave-de-prueba'])->assertStatus(200);
    }

    /**
     * Un informe con hechos (sin texto) del comercio del test.
     *
     * @param string $tipo
     * @return MostradorReporte
     */
    protected function reporte_con_hechos($tipo = 'dia')
    {
        return MostradorReporte::create([
            'user_id'   => $this->comercio->id,
            'tipo'      => $tipo,
            'fecha'     => $this->ayer->format('Y-m-d'),
            'hechos'    => ['aplica' => true, 'fecha' => $this->ayer->format('Y-m-d')],
            'hechos_at' => now(),
        ]);
    }

    /**
     * PUT del contenido con título y resumen válidos.
     *
     * @param MostradorReporte $reporte
     * @param array $contenido
     * @return \Illuminate\Testing\TestResponse
     */
    protected function depositar($reporte, array $contenido)
    {
        return $this->putJson(self::BASE . '/reportes/' . $reporte->id, [
            'titulo'    => 'Título',
            'resumen'   => 'Resumen.',
            'contenido' => $contenido,
        ]);
    }
}
