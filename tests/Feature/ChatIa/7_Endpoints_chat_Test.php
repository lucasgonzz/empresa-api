<?php

namespace Tests\Feature\ChatIa;

use App\Jobs\InferirTituloConversacionIaJob;
use App\Jobs\ResponderMensajeChatIaJob;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Misión chat-ia-y-modulo-ia — P7: los endpoints REST del chat.
 *
 * Lo que protege este archivo son las dos fronteras del módulo: la extensión
 * (sin asistente_ia, 403 en TODAS las rutas) y la tenencia por PERSONA (las
 * conversaciones pueden traer saldos de clientes: el empleado no ve las del
 * dueño ni al revés, aunque compartan cuenta). Más el contrato del POST del
 * mensaje: guarda los dos globos, toca la actividad, despacha los jobs y
 * defiende la carrera de dos mensajes seguidos con el 409 de servidor.
 *
 * Queue::fake en los tests de send_message: acá se verifica el DESPACHO; los
 * jobs ya tienen su propio archivo (P6) y la cola sync saldría a la red.
 */
class Endpoints_chat_Test extends TestCase
{
    use DatabaseTransactions;

    /** Slug de la extensión que gatea el módulo. */
    const SLUG = 'asistente_ia';

    /** @var User */
    protected $comercio;

    /** @var User */
    protected $empleado;

    protected function setUp(): void
    {
        parent::setUp();

        // 🔴 Nunca la clave real del .env.testing: los tests jamás salen a la red.
        config(['services.anthropic.api_key' => null]);

        $this->comercio = User::create([
            'name'         => 'Comercio chat-ia P7',
            'company_name' => 'Ferreteria P7',
            'email'        => 'chat-ia-p7-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->empleado = User::create([
            'name'     => 'Empleado chat-ia P7',
            'email'    => 'chat-ia-p7-empleado-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->comercio->id,
        ]);
    }

    /**
     * Asigna la extensión al comercio (el middleware resuelve al owner, así
     * que con dársela al dueño alcanza también para sus empleados).
     *
     * @return void
     */
    protected function dar_extension()
    {
        $extencion = ExtencionEmpresa::where('slug', self::SLUG)->first();

        if (!$extencion) {
            $extencion = ExtencionEmpresa::forceCreate([
                'slug' => self::SLUG,
                'name' => 'Asistente IA',
            ]);
        }

        $this->comercio->extencions()->attach($extencion->id);
        $this->comercio->load('extencions');
    }

    /**
     * Conversación directa en la base para una persona de la cuenta del test.
     *
     * @param User $persona
     * @param array $extra
     * @return AiConversation
     */
    protected function conversacion($persona, array $extra = [])
    {
        return AiConversation::create(array_merge([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $persona->id,
        ], $extra));
    }

    /**
     * @group chat-ia
     * @test
     */
    public function sin_la_extension_todas_las_rutas_devuelven_403()
    {
        $this->actingAs($this->comercio, 'web');

        $conversation = $this->conversacion($this->comercio);

        $this->getJson('api/ai-conversations')->assertStatus(403);
        $this->postJson('api/ai-conversations')->assertStatus(403);
        $this->deleteJson('api/ai-conversations/' . $conversation->id)->assertStatus(403);
        $this->getJson('api/ai-conversations/' . $conversation->id . '/messages')->assertStatus(403);
        $this->postJson('api/ai-conversations/' . $conversation->id . '/messages', [
            'contenido' => 'hola',
        ])->assertStatus(403);
        $this->getJson('api/ai-conversations/' . $conversation->id . '/messages/1')->assertStatus(403);
    }

    /**
     * R5: la tenencia es por PERSONA. Compartir la cuenta no comparte los
     * chats, en ninguna de las dos direcciones — cada dirección va en su
     * propio método (patrón del test 5 del canal privado): el guard de
     * Sanctum cachea a la persona resuelta durante todo el test, así que un
     * segundo actingAs en el MISMO método no llega al controller y la
     * segunda mitad correría re-autenticada como la primera persona sin que
     * ninguna aserción lo denuncie.
     *
     * @group chat-ia
     * @test
     */
    public function el_indice_del_empleado_no_muestra_las_conversaciones_del_dueno()
    {
        $this->dar_extension();

        $del_dueno    = $this->conversacion($this->comercio, ['titulo' => 'Chat del dueño']);
        $del_empleado = $this->conversacion($this->empleado, ['titulo' => 'Chat del empleado']);

        $this->actingAs($this->empleado, 'web');
        $response = $this->getJson('api/ai-conversations');
        $response->assertStatus(200);

        $ids = array_column($response->json('models'), 'id');
        $this->assertEquals([$del_empleado->id], $ids, 'El empleado ve SOLO su conversación, no la del dueño.');
    }

    /**
     * La jerarquía tampoco corre al revés: ser el dueño de la cuenta no abre
     * los chats de los empleados (pueden charlar de saldos con la IA igual
     * que el dueño).
     *
     * @group chat-ia
     * @test
     */
    public function el_indice_del_dueno_no_muestra_las_conversaciones_del_empleado()
    {
        $this->dar_extension();

        $del_dueno    = $this->conversacion($this->comercio, ['titulo' => 'Chat del dueño']);
        $del_empleado = $this->conversacion($this->empleado, ['titulo' => 'Chat del empleado']);

        $this->actingAs($this->comercio, 'web');
        $response = $this->getJson('api/ai-conversations');
        $response->assertStatus(200);

        $ids = array_column($response->json('models'), 'id');
        $this->assertEquals([$del_dueno->id], $ids, 'El dueño ve SOLO su conversación, no la del empleado.');
    }

    /**
     * D44: la sidebar abre conversations[0], así que el orden del índice es
     * contrato: actividad descendente, sin actividad al final.
     *
     * @group chat-ia
     * @test
     */
    public function el_indice_ordena_por_actividad_con_las_sin_actividad_al_final()
    {
        $this->dar_extension();
        $this->actingAs($this->empleado, 'web');

        $vieja = $this->conversacion($this->empleado, ['last_message_at' => now()->subDay()]);
        $sin_actividad = $this->conversacion($this->empleado);
        $reciente = $this->conversacion($this->empleado, ['last_message_at' => now()]);

        $ids = array_column($this->getJson('api/ai-conversations')->json('models'), 'id');

        $this->assertEquals([$reciente->id, $vieja->id, $sin_actividad->id], $ids);
    }

    /**
     * @group chat-ia
     * @test
     */
    public function messages_pagina_con_per_page_y_techo_200()
    {
        $this->dar_extension();
        $this->actingAs($this->empleado, 'web');

        $conversation = $this->conversacion($this->empleado);

        for ($i = 1; $i <= 5; $i++) {
            AiMessage::create([
                'ai_conversation_id' => $conversation->id,
                'rol'                => ($i % 2 === 1) ? 'user' : 'assistant',
                'contenido'          => 'mensaje ' . $i,
            ]);
        }

        $response = $this->getJson('api/ai-conversations/' . $conversation->id . '/messages?per_page=2&page=1');
        $response->assertStatus(200);

        // Paginador entero en models (molde WhatsApp): data, total, per_page adentro.
        $this->assertCount(2, $response->json('models.data'));
        $this->assertEquals(5, $response->json('models.total'));
        // Del más nuevo al más viejo: la primera página trae el último mensaje.
        $this->assertEquals('mensaje 5', $response->json('models.data.0.contenido'));

        // El techo de per_page es 200: pedir más no revienta la respuesta.
        $response = $this->getJson('api/ai-conversations/' . $conversation->id . '/messages?per_page=9999');
        $this->assertEquals(200, (int) $response->json('models.per_page'));
    }

    /**
     * @group chat-ia
     * @test
     */
    public function send_message_guarda_los_dos_globos_toca_la_actividad_y_despacha_los_jobs()
    {
        $this->dar_extension();
        $this->actingAs($this->empleado, 'web');
        Queue::fake();

        $conversation = $this->conversacion($this->empleado);
        $this->assertNull($conversation->last_message_at);

        $response = $this->postJson('api/ai-conversations/' . $conversation->id . '/messages', [
            'contenido' => '¿Cuánto stock tengo de tornillos?',
        ]);

        $response->assertStatus(201);

        $user_message = $response->json('user_message');
        $assistant_message = $response->json('assistant_message');

        $this->assertEquals('user', $user_message['rol']);
        $this->assertEquals('¿Cuánto stock tengo de tornillos?', $user_message['contenido']);
        $this->assertEquals('assistant', $assistant_message['rol']);
        $this->assertEquals('pendiente', $assistant_message['estado']);

        $conversation->refresh();
        $this->assertNotNull($conversation->last_message_at, 'El POST toca last_message_at: es lo que ordena la sidebar.');

        Queue::assertPushed(ResponderMensajeChatIaJob::class, 1);
        // Primer mensaje de una conversación sin título: se infiere.
        Queue::assertPushed(InferirTituloConversacionIaJob::class, 1);
    }

    /**
     * D19: el título se infiere UNA vez, con el primer mensaje. Los
     * siguientes no vuelven a despachar el job.
     *
     * @group chat-ia
     * @test
     */
    public function el_titulo_solo_se_infiere_con_el_primer_mensaje()
    {
        $this->dar_extension();
        $this->actingAs($this->empleado, 'web');
        Queue::fake();

        $conversation = $this->conversacion($this->empleado);

        $this->postJson('api/ai-conversations/' . $conversation->id . '/messages', [
            'contenido' => 'primer mensaje',
        ])->assertStatus(201);

        // La respuesta del primero ya llegó (si quedara pendiente, el segundo POST daría 409).
        AiMessage::where('ai_conversation_id', $conversation->id)
            ->where('rol', 'assistant')
            ->update(['estado' => 'listo', 'contenido' => 'respuesta']);

        $this->postJson('api/ai-conversations/' . $conversation->id . '/messages', [
            'contenido' => 'segundo mensaje',
        ])->assertStatus(201);

        Queue::assertPushed(ResponderMensajeChatIaJob::class, 2);
        Queue::assertPushed(InferirTituloConversacionIaJob::class, 1);
    }

    /**
     * R2: la defensa real contra dos mensajes seguidos es del servidor (una
     * pestaña vieja o un doble clic pasan por encima del bloqueo de la SPA).
     *
     * @group chat-ia
     * @test
     */
    public function con_una_respuesta_en_curso_devuelve_409_y_no_crea_nada()
    {
        $this->dar_extension();
        $this->actingAs($this->empleado, 'web');
        Queue::fake();

        $conversation = $this->conversacion($this->empleado);

        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'assistant',
            'estado'             => 'pendiente',
        ]);

        $antes = AiMessage::where('ai_conversation_id', $conversation->id)->count();

        $response = $this->postJson('api/ai-conversations/' . $conversation->id . '/messages', [
            'contenido' => 'otro mensaje apurado',
        ]);

        $response->assertStatus(409);
        $this->assertEquals('respuesta_en_curso', $response->json('code'));

        $this->assertEquals(
            $antes,
            AiMessage::where('ai_conversation_id', $conversation->id)->count(),
            'El 409 no puede haber creado ningún mensaje.'
        );
        Queue::assertNothingPushed();
    }

    /**
     * @group chat-ia
     * @test
     */
    public function un_contenido_vacio_o_pasado_de_largo_devuelve_422()
    {
        $this->dar_extension();
        $this->actingAs($this->empleado, 'web');
        Queue::fake();

        $conversation = $this->conversacion($this->empleado);

        $this->postJson('api/ai-conversations/' . $conversation->id . '/messages', [
            'contenido' => '',
        ])->assertStatus(422);

        $this->postJson('api/ai-conversations/' . $conversation->id . '/messages', [
            'contenido' => str_repeat('x', 4001),
        ])->assertStatus(422);

        Queue::assertNothingPushed();
    }

    /**
     * @group chat-ia
     * @test
     */
    public function los_metodos_por_id_devuelven_404_para_una_conversacion_de_otra_persona()
    {
        $this->dar_extension();

        $del_dueno = $this->conversacion($this->comercio);
        $mensaje_del_dueno = AiMessage::create([
            'ai_conversation_id' => $del_dueno->id,
            'rol'                => 'user',
            'contenido'          => 'saldo del cliente García',
        ]);

        $this->actingAs($this->empleado, 'web');
        Queue::fake();

        $base = 'api/ai-conversations/' . $del_dueno->id;

        $this->getJson($base . '/messages')->assertStatus(404);
        $this->getJson($base . '/messages/' . $mensaje_del_dueno->id)->assertStatus(404);
        $this->postJson($base . '/messages', ['contenido' => 'hola'])->assertStatus(404);
        $this->deleteJson($base)->assertStatus(404);

        // Nada de lo anterior puede haber tocado la conversación ajena.
        $this->assertNotNull(AiConversation::find($del_dueno->id));
        $this->assertNotNull(AiMessage::find($mensaje_del_dueno->id));
        Queue::assertNothingPushed();
    }

    /**
     * @group chat-ia
     * @test
     */
    public function destroy_borra_la_conversacion_con_sus_mensajes()
    {
        $this->dar_extension();
        $this->actingAs($this->empleado, 'web');

        $conversation = $this->conversacion($this->empleado);

        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => 'hola',
        ]);
        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'assistant',
            'estado'             => 'pendiente',
        ]);

        $this->deleteJson('api/ai-conversations/' . $conversation->id)->assertStatus(200);

        $this->assertNull(AiConversation::find($conversation->id));
        $this->assertEquals(
            0,
            AiMessage::where('ai_conversation_id', $conversation->id)->count(),
            'Los mensajes se borran con la conversación: es lo que hace concreta la carrera R3 del job.'
        );
    }

    /**
     * @group chat-ia
     * @test
     */
    public function store_crea_una_conversacion_de_la_persona_sin_titulo()
    {
        $this->dar_extension();
        $this->actingAs($this->empleado, 'web');

        $response = $this->postJson('api/ai-conversations');

        $response->assertStatus(201);

        $model = $response->json('model');

        $this->assertEquals($this->empleado->id, (int) $model['auth_user_id'], 'La conversación es de la PERSONA.');
        $this->assertEquals($this->comercio->id, (int) $model['user_id'], 'La cuenta es la del dueño.');
        $this->assertNull($model['titulo'], 'El título nace null: la SPA muestra "Nueva conversación".');
        $this->assertEquals('usuario', AiConversation::find($model['id'])->origen);
    }
}
