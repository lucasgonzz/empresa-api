<?php

namespace Tests\Feature\ChatIa;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Misión agente-ia-mano-derecha — A5: el chat del asistente es SOLO del dueño.
 *
 * Decisión de Lucas del 16/9/2026: el asistente lo usa el dueño, y nadie más. Un empleado con el
 * permiso de la pantalla de Gastos podía, hasta acá, abrir el chat y cargar un gasto por tarjeta;
 * y leer, de paso, lo que el chat contesta — que es cobranzas, deudas y compras. El gate es el
 * mismo MostradorHelper::puede_ver() que ya usaba el mostrador del módulo IA, porque es la misma
 * pregunta sobre el mismo módulo: dueño, o admin_access, o acceso maestro.
 *
 * 🔴 EL TEST QUE IMPORTA ES EL DE TODAS LAS RUTAS. Un gate que cubre seis de ocho rutas no es un
 * gate: el que quede afuera es por donde se entra. Por eso acá se recorren TODAS las rutas del
 * grupo, incluidas las dos de las tarjetas.
 *
 * ⚠️ El mostrador NO entra en este archivo: sus rutas siguen gateadas por el controlador, con su
 * propio mensaje, y eso también se verifica acá para que nadie lo mueva sin querer.
 *
 * PHP 7.4: sin match, sin ?-> y sin str_contains.
 */
class Gate_solo_el_dueno_Test extends TestCase
{
    use DatabaseTransactions;

    /** Slug de la extensión que gatea el módulo. */
    const SLUG = 'asistente_ia';

    /** El 403 del gate, palabra por palabra: la SPA lo muestra tal cual. */
    const MENSAJE = 'Solo el dueño puede usar el asistente de IA.';

    /** @var User */
    protected $comercio;

    /** @var User */
    protected $empleado;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => null]);

        $this->comercio = User::create([
            'name'         => 'Comercio gate IA',
            'company_name' => 'Ferreteria gate',
            'email'        => 'chat-ia-gate-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->empleado = User::create([
            'name'     => 'Empleado raso gate IA',
            'email'    => 'chat-ia-gate-empleado-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->comercio->id,
        ]);

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
     * Una conversación de una persona de la cuenta, con un mensaje para que las rutas por id
     * tengan a qué apuntar.
     *
     * @param User $persona
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function conversacion($persona)
    {
        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $persona->id,
        ]);

        $message = AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => '¿Cuánto me debe Tucumana?',
        ]);

        return [$conversation, $message];
    }

    /**
     * Las ocho rutas del grupo, como [método, url].
     *
     * @param AiConversation $conversation
     * @param AiMessage $message
     * @return array<int, array<int, string>>
     */
    protected function todas_las_rutas($conversation, $message)
    {
        $base = 'api/ai-conversations';

        return [
            ['get', $base],
            ['post', $base],
            ['delete', $base . '/' . $conversation->id],
            ['get', $base . '/' . $conversation->id . '/messages'],
            ['post', $base . '/' . $conversation->id . '/messages'],
            ['get', $base . '/' . $conversation->id . '/messages/' . $message->id],
            ['post', $base . '/' . $conversation->id . '/acciones/1/confirmar'],
            ['post', $base . '/' . $conversation->id . '/acciones/1/cancelar'],
        ];
    }

    /**
     * 🔴 Un empleado raso no entra por NINGUNA de las ocho rutas, ni siquiera a la suya propia: la
     * conversación es de él y la respuesta sigue siendo 403.
     *
     * @group chat-ia
     * @test
     */
    public function un_empleado_raso_recibe_403_en_todas_las_rutas_del_chat()
    {
        Queue::fake();

        list($conversation, $message) = $this->conversacion($this->empleado);

        $this->actingAs($this->empleado, 'web');

        foreach ($this->todas_las_rutas($conversation, $message) as $ruta) {
            $response = $this->json($ruta[0], $ruta[1], ['contenido' => 'hola']);

            $response->assertStatus(403);
            $this->assertEquals(
                self::MENSAJE,
                $response->json('message'),
                'La ruta ' . strtoupper($ruta[0]) . ' ' . $ruta[1] . ' quedó afuera del gate.'
            );
        }

        // Y no escribió nada de paso: el POST de mensaje no llegó a crear globos ni a encolar.
        $this->assertEquals(1, AiMessage::where('ai_conversation_id', $conversation->id)->count());
        Queue::assertNothingPushed();
    }

    /**
     * El dueño entra, que es el punto entero de la funcionalidad.
     *
     * @group chat-ia
     * @test
     */
    public function el_dueno_entra_como_siempre()
    {
        list($conversation, $message) = $this->conversacion($this->comercio);

        $this->actingAs($this->comercio, 'web');

        $this->getJson('api/ai-conversations')->assertStatus(200);
        $this->getJson('api/ai-conversations/' . $conversation->id . '/messages')->assertStatus(200);
        $this->getJson('api/ai-conversations/' . $conversation->id . '/messages/' . $message->id)->assertStatus(200);
    }

    /**
     * Y un encargado con admin_access también: es la misma regla que el mostrador, y el acceso
     * maestro de ComercioCity entra por ahí.
     *
     * @group chat-ia
     * @test
     */
    public function un_encargado_con_admin_access_entra()
    {
        $encargado = User::create([
            'name'         => 'Encargado gate IA',
            'email'        => 'chat-ia-gate-encargado-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
            'owner_id'     => $this->comercio->id,
            'admin_access' => 1,
        ]);

        list($conversation, $message) = $this->conversacion($encargado);

        $this->actingAs($encargado, 'web');

        $this->getJson('api/ai-conversations')->assertStatus(200);
        $this->getJson('api/ai-conversations/' . $conversation->id . '/messages')->assertStatus(200);
    }

    /**
     * El gate nuevo va ENCIMA del de la extensión, no en vez de él: sin asistente_ia sigue
     * cortando la extensión, y el motivo que ve la persona es el de la extensión.
     *
     * @group chat-ia
     * @test
     */
    public function sin_la_extension_sigue_cortando_la_extension_y_no_el_gate_nuevo()
    {
        $this->comercio->extencions()->detach();
        $this->comercio->load('extencions');

        $this->actingAs($this->comercio, 'web');

        $response = $this->getJson('api/ai-conversations');

        $response->assertStatus(403);
        $this->assertStringContainsString('Extensión requerida', (string) $response->json('message'));
        $this->assertNotEquals(self::MENSAJE, $response->json('message'));
    }

    /**
     * ⚠️ El mostrador queda como estaba: lo gatea su controlador, con su propio mensaje. El
     * middleware nuevo NO cuelga de ese tramo del grupo, y este test es lo que lo sostiene — si
     * alguien lo sube al grupo entero, el mensaje del mostrador cambia sin que nadie lo pida.
     *
     * @group chat-ia
     * @test
     */
    public function el_mostrador_conserva_su_propio_403()
    {
        $this->actingAs($this->empleado, 'web');

        $response = $this->getJson('api/mostrador/reportes');

        $response->assertStatus(403);
        $this->assertEquals('Solo el dueño puede ver el mostrador.', $response->json('message'));
    }
}
