<?php

namespace Tests\Feature\ChatIa;

use App\Jobs\ResponderMensajeChatIaJob;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\ExtencionEmpresa;
use App\Models\Pending;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Misión asistente-ia-acciones — el esquema de las tarjetas de carga y los endpoints que las
 * resuelven (contrato §2 del plan).
 *
 * Lo que protege este archivo: que los mensajes viajen con sus tarjetas y sin el payload interno;
 * que la tenencia doble del chat también cubra confirmar y cancelar (una tarjeta de otra persona o
 * de otra conversación es un 404); el candado contra el segundo clic (409 sin duplicar la carga);
 * que una tarjeta vencida o cancelada ya no se pueda confirmar; que el motivo de un 422 quede
 * guardado en la tarjeta aunque la transacción se revierta; y que el POST del mensaje guarde el
 * flag `acciones` y descarte las tarjetas de un pendiente vencido.
 *
 * Las tarjetas se ejecutan sobre una tarea de la agenda (tarea_nueva): es la carga que no depende
 * de cajas ni del fixture de plata. Los caminos de plata tienen sus propios archivos (12, 13, 14).
 *
 * 🔴 LA SEGUNDA PERSONA ES UN ENCARGADO (admin_access), NO UN EMPLEADO RASO: desde la misión
 * agente-ia-mano-derecha el chat es solo del dueño, así que un empleado raso queda afuera en la
 * puerta con 403 y no llega a ninguna tarjeta. La tenencia doble que mide este archivo sigue
 * exigiendo lo mismo, y ahora contra alguien que sí puede entrar.
 *
 * 🔴 La clave de Anthropic queda null: nada de este archivo sale a la red.
 */
class Acciones_esquema_y_endpoints_Test extends TestCase
{
    use DatabaseTransactions;

    /** Slug de la extensión que gatea el módulo. */
    const SLUG = 'asistente_ia';

    /** @var User */
    protected $comercio;

    /** @var User */
    protected $encargado;

    protected function setUp(): void
    {
        parent::setUp();

        // 🔴 Nunca la clave real del .env.testing: los tests jamás salen a la red.
        config(['services.anthropic.api_key' => null]);

        $this->comercio = User::create([
            'name'         => 'Comercio acciones P11',
            'company_name' => 'Ferreteria P11',
            'email'        => 'acciones-p11-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->encargado = User::create([
            'name'     => 'Encargado acciones P11',
            'email'    => 'acciones-p11-encargado-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->comercio->id,
            // 🔴 Con admin_access porque desde la misión agente-ia-mano-derecha el chat es SOLO del
            // dueño: un empleado raso ya no pasa la puerta, y lo que este archivo mide es lo que
            // pasa ADENTRO. Que el empleado raso quede afuera lo mide 18_Gate_solo_el_dueno_Test.
            'admin_access' => 1,
        ]);
    }

    /**
     * Asigna la extensión al comercio (el middleware resuelve al dueño).
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
     * @param User $persona
     * @return AiConversation
     */
    protected function conversacion($persona)
    {
        return AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $persona->id,
        ]);
    }

    /**
     * @param AiConversation $conversation
     * @param string $rol
     * @param string $estado
     * @return AiMessage
     */
    protected function mensaje($conversation, $rol = 'assistant', $estado = 'listo')
    {
        return AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => $rol,
            'contenido'          => $estado === 'pendiente' ? null : 'Te dejé la tarjeta para confirmar.',
            'estado'             => $estado,
        ]);
    }

    /**
     * Tarjeta de una tarea nueva puntual, con el body de `POST api/pending` en `datos`.
     *
     * @param AiConversation $conversation
     * @param AiMessage $mensaje
     * @param array $overrides
     * @return AiMessageAction
     */
    protected function tarjeta($conversation, $mensaje, array $overrides = [])
    {
        return AiMessageAction::create(array_merge([
            'ai_conversation_id' => $conversation->id,
            'ai_message_id'      => $mensaje->id,
            'user_id'            => $this->comercio->id,
            'auth_user_id'       => $conversation->auth_user_id,
            'tipo'               => AiMessageAction::TIPO_TAREA_NUEVA,
            'clave'              => 'tarea_nueva',
            'estado'             => AiMessageAction::ESTADO_PROPUESTA,
            'datos'              => [
                'detalle'               => 'Tarea de la tarjeta P11 ' . uniqid(),
                'fecha_realizacion'     => Carbon::today()->addDays(3)->format('Y-m-d'),
                'es_recurrente'         => false,
                'unidad_frecuencia_id'  => null,
                'cantidad_frecuencia'   => null,
                'fecha_fin_recurrencia' => null,
                'expense_concept_id'    => null,
                'expense_amount'        => null,
                'notas'                 => null,
            ],
            'presentacion'       => [
                'titulo'    => 'Tarea en la agenda',
                'renglones' => [['etiqueta' => 'Qué', 'valor' => 'Tarea de la tarjeta P11']],
                'aviso'     => null,
            ],
        ], $overrides));
    }

    /**
     * @param AiConversation $conversation
     * @param AiMessageAction $tarjeta
     * @param string $accion confirmar | cancelar
     * @return \Illuminate\Testing\TestResponse
     */
    protected function resolver($conversation, $tarjeta, $accion)
    {
        return $this->postJson('api/ai-conversations/' . $conversation->id . '/acciones/' . $tarjeta->id . '/' . $accion);
    }

    /**
     * @group chat-ia
     * @test
     */
    public function la_tabla_de_tarjetas_y_la_columna_del_flag_existen_con_sus_defaults_e_indices()
    {
        $this->assertTrue(Schema::hasTable('ai_message_actions'), 'Falta la tabla ai_message_actions.');

        $columnas = [
            'id', 'ai_conversation_id', 'ai_message_id', 'user_id', 'auth_user_id', 'tipo', 'clave',
            'estado', 'datos', 'presentacion', 'resultado', 'error_mensaje', 'referencia_updated_at',
            'resuelta_at', 'created_at', 'updated_at',
        ];

        foreach ($columnas as $columna) {
            $this->assertTrue(Schema::hasColumn('ai_message_actions', $columna), "Falta la columna ai_message_actions.{$columna}.");
        }

        $default_estado = DB::selectOne(
            "SELECT COLUMN_DEFAULT as valor FROM information_schema.columns WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_message_actions' AND COLUMN_NAME = 'estado'"
        );
        $this->assertEquals('propuesta', $default_estado->valor, 'Una tarjeta nace propuesta.');

        foreach (['aima_message_idx', 'aima_conv_estado_idx'] as $indice) {
            $this->assertNotEmpty(DB::select("SHOW INDEX FROM ai_message_actions WHERE Key_name = '{$indice}'"), "Falta el índice {$indice}.");
        }

        $this->assertTrue(Schema::hasColumn('ai_messages', 'acciones_habilitadas'), 'Falta ai_messages.acciones_habilitadas.');

        $default_flag = DB::selectOne(
            "SELECT COLUMN_DEFAULT as valor FROM information_schema.columns WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_messages' AND COLUMN_NAME = 'acciones_habilitadas'"
        );
        $this->assertEquals('0', (string) $default_flag->valor, 'Sin el flag, un mensaje es de solo lectura como siempre.');
    }

    /**
     * Contrato §2.2 y §2.3: cada mensaje viaja con `acciones` (en orden de id, sin el payload
     * interno), y un mensaje que no está listo nunca muestra tarjetas.
     *
     * @group chat-ia
     * @test
     */
    public function messages_y_show_message_traen_las_tarjetas_sin_el_payload_interno()
    {
        $this->dar_extension();
        $this->actingAs($this->encargado, 'web');

        $conversation = $this->conversacion($this->encargado);

        $this->mensaje($conversation, 'user');
        $listo = $this->mensaje($conversation);
        $primera = $this->tarjeta($conversation, $listo);
        $segunda = $this->tarjeta($conversation, $listo, ['estado' => AiMessageAction::ESTADO_REEMPLAZADA]);

        $pendiente = $this->mensaje($conversation, 'assistant', 'pendiente');
        $this->tarjeta($conversation, $pendiente);

        $response = $this->getJson('api/ai-conversations/' . $conversation->id . '/messages');
        $response->assertStatus(200);

        $por_id = [];
        foreach ($response->json('models.data') as $fila) {
            $por_id[$fila['id']] = $fila;
        }

        $this->assertSame([], $por_id[$pendiente->id]['acciones'], 'Un mensaje pendiente no muestra tarjetas.');

        $acciones = $por_id[$listo->id]['acciones'];
        $this->assertCount(2, $acciones);
        $this->assertEquals([$primera->id, $segunda->id], array_column($acciones, 'id'), 'Las tarjetas van en orden de id.');

        foreach (['id', 'ai_message_id', 'tipo', 'estado', 'presentacion', 'resultado', 'error_mensaje', 'created_at'] as $clave) {
            $this->assertArrayHasKey($clave, $acciones[0], "La tarjeta tiene que traer {$clave}.");
        }

        foreach (['datos', 'clave', 'referencia_updated_at'] as $oculta) {
            $this->assertArrayNotHasKey($oculta, $acciones[0], "{$oculta} es interno y no viaja a la SPA.");
        }

        $this->assertEquals('propuesta', $acciones[0]['estado']);
        $this->assertEquals('reemplazada', $acciones[1]['estado']);
        $this->assertEquals('Tarea en la agenda', $acciones[0]['presentacion']['titulo']);

        $uno = $this->getJson('api/ai-conversations/' . $conversation->id . '/messages/' . $listo->id);
        $uno->assertStatus(200);
        $this->assertCount(2, $uno->json('model.acciones'));

        $this->assertSame([], $this->getJson('api/ai-conversations/' . $conversation->id . '/messages/' . $pendiente->id)->json('model.acciones'));
    }

    /**
     * @group chat-ia
     * @test
     */
    public function confirmar_y_cancelar_una_tarjeta_de_otra_persona_dan_404_sin_tocar_nada()
    {
        $this->dar_extension();

        $del_dueno = $this->conversacion($this->comercio);
        $tarjeta = $this->tarjeta($del_dueno, $this->mensaje($del_dueno));

        $this->actingAs($this->encargado, 'web');

        $this->resolver($del_dueno, $tarjeta, 'confirmar')->assertStatus(404);
        $this->resolver($del_dueno, $tarjeta, 'cancelar')->assertStatus(404);

        $this->assertEquals('propuesta', $tarjeta->fresh()->estado);
        $this->assertEquals(0, Pending::where('detalle', $tarjeta->datos['detalle'])->count());
    }

    /**
     * @group chat-ia
     * @test
     */
    public function una_tarjeta_de_otra_conversacion_de_la_misma_persona_da_404()
    {
        $this->dar_extension();
        $this->actingAs($this->encargado, 'web');

        $una = $this->conversacion($this->encargado);
        $otra = $this->conversacion($this->encargado);
        $tarjeta_de_la_otra = $this->tarjeta($otra, $this->mensaje($otra));

        $this->resolver($una, $tarjeta_de_la_otra, 'confirmar')->assertStatus(404);
        $this->resolver($una, $tarjeta_de_la_otra, 'cancelar')->assertStatus(404);

        $this->assertEquals('propuesta', $tarjeta_de_la_otra->fresh()->estado);
    }

    /**
     * El candado contra el segundo clic: el segundo confirmar sale por 409 y la tarea no se crea dos
     * veces.
     *
     * @group chat-ia
     * @test
     */
    public function el_segundo_confirmar_da_409_sin_duplicar_la_carga()
    {
        $this->dar_extension();
        $this->actingAs($this->comercio, 'web');

        $conversation = $this->conversacion($this->comercio);
        $tarjeta = $this->tarjeta($conversation, $this->mensaje($conversation));

        $primero = $this->resolver($conversation, $tarjeta, 'confirmar');

        $primero->assertStatus(200);
        $this->assertEquals('confirmada', $primero->json('model.estado'));
        $this->assertStringStartsWith('Tarea agendada para el ', $primero->json('model.resultado.texto'));
        $this->assertEquals('pending', $primero->json('model.resultado.ruta.name'));
        $this->assertNull($primero->json('model.error_mensaje'));
        $this->assertEquals(1, Pending::where('detalle', $tarjeta->datos['detalle'])->where('user_id', $this->comercio->id)->count());

        $segundo = $this->resolver($conversation, $tarjeta, 'confirmar');

        $segundo->assertStatus(409);
        $this->assertEquals('accion_resuelta', $segundo->json('code'));
        $this->assertEquals('confirmada', $segundo->json('model.estado'));
        $this->assertEquals(1, Pending::where('detalle', $tarjeta->datos['detalle'])->count(), 'El segundo clic no puede crear otra tarea.');
    }

    /**
     * @group chat-ia
     * @test
     */
    public function cancelar_y_despues_confirmar_da_409_y_no_carga_nada()
    {
        $this->dar_extension();
        $this->actingAs($this->comercio, 'web');

        $conversation = $this->conversacion($this->comercio);
        $tarjeta = $this->tarjeta($conversation, $this->mensaje($conversation));

        $cancelada = $this->resolver($conversation, $tarjeta, 'cancelar');

        $cancelada->assertStatus(200);
        $this->assertEquals('cancelada', $cancelada->json('model.estado'));
        $this->assertNotNull($tarjeta->fresh()->resuelta_at);

        $confirmar = $this->resolver($conversation, $tarjeta, 'confirmar');

        $confirmar->assertStatus(409);
        $this->assertEquals('accion_resuelta', $confirmar->json('code'));
        $this->assertEquals('cancelada', $confirmar->json('model.estado'));
        $this->assertEquals(0, Pending::where('detalle', $tarjeta->datos['detalle'])->count());

        // Cancelar dos veces tampoco: la segunda también es un 409.
        $this->resolver($conversation, $tarjeta, 'cancelar')->assertStatus(409);
    }

    /**
     * Contrato §2.3: una propuesta de más de 24 h se lee 'vencida' sin cron, y al intentar
     * confirmarla queda guardada como vencida.
     *
     * @group chat-ia
     * @test
     */
    public function una_tarjeta_vencida_da_409_y_queda_vencida_en_la_base()
    {
        $this->dar_extension();
        $this->actingAs($this->comercio, 'web');

        $conversation = $this->conversacion($this->comercio);
        $tarjeta = $this->tarjeta($conversation, $this->mensaje($conversation));

        AiMessageAction::where('id', $tarjeta->id)->update(['created_at' => now()->subHours(25)]);

        $listado = $this->getJson('api/ai-conversations/' . $conversation->id . '/messages');
        $acciones = [];
        foreach ($listado->json('models.data') as $fila) {
            $acciones = array_merge($acciones, $fila['acciones']);
        }
        $this->assertEquals('vencida', $acciones[0]['estado'], 'La SPA ya la recibe vencida aunque la base diga propuesta.');
        $this->assertEquals('propuesta', DB::table('ai_message_actions')->where('id', $tarjeta->id)->value('estado'));

        $confirmar = $this->resolver($conversation, $tarjeta, 'confirmar');

        $confirmar->assertStatus(409);
        $this->assertEquals('accion_resuelta', $confirmar->json('code'));
        $this->assertEquals('vencida', $confirmar->json('model.estado'));
        $this->assertEquals('vencida', DB::table('ai_message_actions')->where('id', $tarjeta->id)->value('estado'), 'El paso a vencida se escribe aunque la transacción se revierta.');
        $this->assertEquals(0, Pending::where('detalle', $tarjeta->datos['detalle'])->count());
    }

    /**
     * 🔴 Un 422 revierte la carga pero el motivo queda guardado en la tarjeta, que sigue propuesta:
     * la SPA muestra `model.error_mensaje`, así que tiene que viajar en la respuesta.
     *
     * @group chat-ia
     * @test
     */
    public function un_422_deja_el_motivo_en_error_mensaje_y_la_tarjeta_sigue_propuesta()
    {
        $this->dar_extension();
        $this->actingAs($this->comercio, 'web');

        $conversation = $this->conversacion($this->comercio);

        $tarjeta = $this->tarjeta($conversation, $this->mensaje($conversation));
        $datos = $tarjeta->datos;
        $datos['detalle'] = '';
        $tarjeta->datos = $datos;
        $tarjeta->save();

        $confirmar = $this->resolver($conversation, $tarjeta, 'confirmar');

        $confirmar->assertStatus(422);
        $this->assertEquals('Escribí qué hay que hacer.', $confirmar->json('model.error_mensaje'));
        $this->assertEquals('propuesta', $confirmar->json('model.estado'));
        $this->assertEquals('Escribí qué hay que hacer.', $tarjeta->fresh()->error_mensaje);
        $this->assertEquals(0, Pending::where('user_id', $this->comercio->id)->count());
    }

    /**
     * Una tarjeta que quedó propuesta en un mensaje que terminó en error no se ejecuta: se descarta.
     *
     * @group chat-ia
     * @test
     */
    public function una_tarjeta_de_un_mensaje_en_error_da_409_y_queda_descartada()
    {
        $this->dar_extension();
        $this->actingAs($this->comercio, 'web');

        $conversation = $this->conversacion($this->comercio);
        $tarjeta = $this->tarjeta($conversation, $this->mensaje($conversation, 'assistant', 'error'));

        $confirmar = $this->resolver($conversation, $tarjeta, 'confirmar');

        $confirmar->assertStatus(409);
        $this->assertEquals('descartada', $confirmar->json('model.estado'));
        $this->assertEquals(0, Pending::where('detalle', $tarjeta->datos['detalle'])->count());
    }

    /**
     * @group chat-ia
     * @test
     */
    public function destroy_borra_las_tarjetas_de_la_conversacion()
    {
        $this->dar_extension();
        $this->actingAs($this->encargado, 'web');

        $conversation = $this->conversacion($this->encargado);
        $this->tarjeta($conversation, $this->mensaje($conversation));
        $this->tarjeta($conversation, $this->mensaje($conversation));

        $this->deleteJson('api/ai-conversations/' . $conversation->id)->assertStatus(200);

        $this->assertEquals(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
    }

    /**
     * @group chat-ia
     * @test
     */
    public function sin_la_extension_confirmar_y_cancelar_dan_403()
    {
        $this->actingAs($this->comercio, 'web');

        $conversation = $this->conversacion($this->comercio);
        $tarjeta = $this->tarjeta($conversation, $this->mensaje($conversation));

        $this->resolver($conversation, $tarjeta, 'confirmar')->assertStatus(403);
        $this->resolver($conversation, $tarjeta, 'cancelar')->assertStatus(403);

        $this->assertEquals('propuesta', $tarjeta->fresh()->estado);
    }

    /**
     * Contrato §2.1: `acciones` es opcional. Con true el assistant pendiente queda con el flag; sin
     * él (la SPA vieja), queda de solo lectura.
     *
     * @group chat-ia
     * @test
     */
    public function send_message_guarda_el_flag_de_acciones_solo_cuando_viene()
    {
        $this->dar_extension();
        $this->actingAs($this->encargado, 'web');
        Queue::fake();

        $con_flag = $this->conversacion($this->encargado);

        $this->postJson('api/ai-conversations/' . $con_flag->id . '/messages', [
            'contenido' => 'Cargame el flete',
            'acciones'  => true,
        ])->assertStatus(201);

        $sin_flag = $this->conversacion($this->encargado);

        $this->postJson('api/ai-conversations/' . $sin_flag->id . '/messages', [
            'contenido' => '¿Cuánto stock tengo?',
        ])->assertStatus(201);

        $this->assertTrue(AiMessage::where('ai_conversation_id', $con_flag->id)->where('rol', 'assistant')->first()->acciones_habilitadas);
        $this->assertFalse(AiMessage::where('ai_conversation_id', $sin_flag->id)->where('rol', 'assistant')->first()->acciones_habilitadas);

        Queue::assertPushed(ResponderMensajeChatIaJob::class, 2);
    }

    /**
     * El cierre de pendientes vencidos de send_message deja en error al huérfano y descarta las
     * tarjetas que alcanzó a proponer.
     *
     * @group chat-ia
     * @test
     */
    public function el_cierre_de_un_pendiente_vencido_descarta_sus_tarjetas()
    {
        $this->dar_extension();
        $this->actingAs($this->encargado, 'web');
        Queue::fake();

        $conversation = $this->conversacion($this->encargado);

        $huerfano = AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'assistant',
            'estado'             => 'pendiente',
            'created_at'         => now()->subMinutes(11),
        ]);

        $tarjeta = $this->tarjeta($conversation, $huerfano);

        $this->postJson('api/ai-conversations/' . $conversation->id . '/messages', [
            'contenido' => 'mensaje después del huérfano',
            'acciones'  => true,
        ])->assertStatus(201);

        $this->assertEquals('error', $huerfano->fresh()->estado);
        $this->assertEquals('descartada', $tarjeta->fresh()->estado);
    }
}
