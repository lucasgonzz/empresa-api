<?php

namespace Tests\Feature\ChatIa;

use App\Jobs\ResponderMensajeChatIaJob;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Misión asistente-mejoras-4-dias (16/11/2026), P7 con más de un worker (revisión independiente de la rama B).
 *
 * El tope del historial (P141) alcanza con un solo worker: los turnos de una conversación corren en orden. Con dos
 * workers, el turno del segundo mensaje podía arrancar mientras otro todavía contestaba el primero, sin ver esa
 * respuesta: contestaba algo ya contestado o registraba una venta dos veces. Ahora el job espera: si hay un turno
 * anterior de la misma conversación todavía `pendiente`, se vuelve a encolar con una demora corta, hasta
 * MAX_ESPERAS veces, y después sigue igual. Mientras espera no le avisa nada a nadie.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types, readonly ni enum.
 *
 * @group chat-ia
 */
class P145_Un_turno_a_la_vez_por_conversacion_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);

        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        /* Sin la extensión: si el job corre de verdad, termina en el error amigable sin llamar a ningún modelo. */
        $this->comercio = User::create([
            'name'         => 'Comercio un turno P145',
            'company_name' => 'Bebidas P145',
            'email'        => 'un-turno-p145-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);
    }

    /**
     * Dos mensajes seguidos con su assistant pendiente cada uno.
     *
     * @return array{0: AiMessage, 1: AiMessage}
     */
    protected function dos_turnos()
    {
        $conversation = AiConversation::create(['user_id' => $this->comercio->id, 'auth_user_id' => $this->comercio->id]);

        $base = ['ai_conversation_id' => $conversation->id, 'canal' => AiMessage::CANAL_WHATSAPP];

        AiMessage::create($base + ['rol' => 'user', 'contenido' => 'Llevó una caja de Mascota Malbec', 'estado' => 'listo']);
        $primero = AiMessage::create($base + ['rol' => 'assistant', 'estado' => 'pendiente', 'acciones_habilitadas' => true]);

        AiMessage::create($base + ['rol' => 'user', 'contenido' => 'Sumale $1.000 de envío', 'estado' => 'listo']);
        $segundo = AiMessage::create($base + ['rol' => 'assistant', 'estado' => 'pendiente', 'acciones_habilitadas' => true]);

        return [$primero, $segundo];
    }

    /**
     * Con el turno anterior todavía generándose, el segundo se vuelve a encolar y no toca nada.
     *
     * @test
     */
    public function con_el_turno_anterior_en_curso_el_segundo_se_vuelve_a_encolar()
    {
        Queue::fake();

        list($primero, $segundo) = $this->dos_turnos();

        (new ResponderMensajeChatIaJob($segundo->id))->handle();

        Queue::assertPushed(ResponderMensajeChatIaJob::class, function ($job) {
            return $job->esperas === 1 && ! is_null($job->delay);
        });

        $this->assertSame('pendiente', $segundo->fresh()->estado, 'Sigue esperando: no se contestó ni se avisó nada.');
        $this->assertNull($segundo->fresh()->contenido);
    }

    /**
     * Pasado el tope de esperas, o con un turno anterior colgado de hace más de MINUTOS_DEL_TURNO_ANTERIOR, o con el
     * anterior ya terminado, el job corre como siempre (acá termina en el error de la extensión, sin encolar nada).
     *
     * @test
     */
    public function pasado_el_tope_o_con_el_anterior_colgado_o_terminado_corre_como_siempre()
    {
        Queue::fake();

        list($primero, $segundo) = $this->dos_turnos();
        (new ResponderMensajeChatIaJob($segundo->id, ResponderMensajeChatIaJob::MAX_ESPERAS))->handle();
        $this->assertSame('error', $segundo->fresh()->estado, 'Con el tope usado, corre.');

        list($primero, $segundo) = $this->dos_turnos();
        DB::table('ai_messages')->where('id', $primero->id)->update(['created_at' => now()->subMinutes(ResponderMensajeChatIaJob::MINUTOS_DEL_TURNO_ANTERIOR + 1)]);
        (new ResponderMensajeChatIaJob($segundo->id))->handle();
        $this->assertSame('error', $segundo->fresh()->estado, 'Un turno anterior colgado no se espera.');

        list($primero, $segundo) = $this->dos_turnos();
        $primero->update(['estado' => 'listo', 'contenido' => 'Venta 3098 registrada.']);
        (new ResponderMensajeChatIaJob($segundo->id))->handle();
        $this->assertSame('error', $segundo->fresh()->estado, 'Con el anterior terminado, corre.');

        Queue::assertNotPushed(ResponderMensajeChatIaJob::class);
    }
}
