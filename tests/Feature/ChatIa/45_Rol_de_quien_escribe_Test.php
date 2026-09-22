<?php

namespace Tests\Feature\ChatIa;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Misión asistente-ventas-y-fotos — A: QUIÉN ESTÁ ESCRIBIENDO VA EN EL SYSTEM PROMPT.
 *
 * 🔴 LA ASERCIÓN DEL ARCHIVO: el bug que este bloque tapa NO era de permisos. Medido en producción
 * el 21/9/2026, el DUEÑO pidió por WhatsApp asignarle una foto a una sucursal y recibió "solo el
 * dueño puede hacerlo". La conversación era suya (`auth_user_id` = un `User` con `owner_id` nulo),
 * así que `PermisosIaHelper::es_admin()` habría dado true — pero el turno registró UNA SOLA vuelta
 * en `ai_token_usages`, o sea que la herramienta nunca se llamó, y el texto no era la constante del
 * código sino una redacción libre del modelo. El modelo leía "la foto de una sucursal solo la puede
 * asignar el dueño" en el bloque de carga, no tenía NINGÚN dato de quién le hablaba, y se negaba
 * solo.
 *
 * Por eso lo que se prueba acá es lo que efectivamente viaja en el `system`, y no el resultado del
 * helper de permisos (que ya está probado en 18_Gate_solo_el_dueno_Test y sigue igual: la guarda
 * real no cambió).
 */
class Rol_de_quien_escribe_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    /** @var User */
    protected $empleado;

    /** @var User */
    protected $administrativo;

    /** @var AsistenteIaService */
    protected $service;

    protected function setUp(): void
    {
        parent::setUp();

        // 🔴 Nunca la clave real del .env.testing: los tests jamás salen a la red.
        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        $this->comercio = User::create([
            'name'         => 'Lucas Dueño',
            'company_name' => 'Ferretería del rol',
            'email'        => 'rol-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->empleado = User::create([
            'name'     => 'Pedro Empleado',
            'email'    => 'rol-empleado-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->comercio->id,
        ]);

        $this->administrativo = User::create([
            'name'         => 'Ana Administrativa',
            'email'        => 'rol-admin-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
            'owner_id'     => $this->comercio->id,
            'admin_access' => 1,
        ]);

        $this->service = new AsistenteIaService();
    }

    /**
     * El caso del bug: conversación del dueño, con las herramientas de carga. El prompt lo nombra,
     * dice que es el dueño y le prohíbe explícitamente negarse por permiso.
     *
     * @group chat-ia
     * @test
     */
    public function la_conversacion_del_dueno_declara_que_es_el_dueno_y_que_no_se_niegue_por_permiso()
    {
        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->comercio->id,
        ]);

        $texto = $this->service->build_system_prompt($conversation, $this->comercio, true);

        $this->assertStringContainsString('Quién te está escribiendo:', $texto);
        $this->assertStringContainsString('Lucas Dueño', $texto);
        $this->assertStringContainsString('EL DUEÑO', $texto);
        $this->assertStringContainsString('NO te niegues por permiso', $texto);

        /*
         * 🔴 La regla de permiso NO se sacó: sigue en el bloque de carga y PermisosIaHelper sigue
         * siendo la guarda real. Lo único que se agregó es el dato de quién escribe. Si alguien
         * "simplifica" sacando la regla, este assert lo denuncia.
         */
        $this->assertStringContainsString('La foto de una sucursal solo la puede asignar el dueño', $texto);
    }

    /**
     * Un empleado sin `admin_access` sí puede recibir la negativa, y el prompt se lo dice — pero el
     * resto lo sigue decidiendo la herramienta, no el modelo.
     *
     * @group chat-ia
     * @test
     */
    public function la_conversacion_de_un_empleado_declara_que_no_es_el_dueno()
    {
        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->empleado->id,
        ]);

        $texto = $this->service->build_system_prompt($conversation, $this->comercio, true);

        $this->assertStringContainsString('Pedro Empleado', $texto);
        $this->assertStringContainsString('No es el dueño ni administrador', $texto);
        $this->assertStringNotContainsString('NO te niegues por permiso', $texto);
        $this->assertStringContainsString('lo tiene que hacer el dueño', $texto);
    }

    /**
     * `admin_access` es admin pero no dueño: el mismo criterio que `PermisosIaHelper::es_admin()` y
     * que el `is_admin` de la SPA. Se dice que no es el dueño, porque no lo es.
     *
     * @group chat-ia
     * @test
     */
    public function un_admin_access_se_declara_administrador_y_no_dueno()
    {
        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->administrativo->id,
        ]);

        $texto = $this->service->build_system_prompt($conversation, $this->comercio, true);

        $this->assertStringContainsString('Ana Administrativa', $texto);
        $this->assertStringContainsString('acceso de administrador', $texto);
        $this->assertStringContainsString('No es el dueño', $texto);
        $this->assertStringNotContainsString('NO te niegues por permiso', $texto);
    }

    /**
     * Sin herramientas de carga no hay nada que se pueda negar por permiso: queda la identidad sola
     * y el renglón del permiso no se escribe (bytes de prompt que no tapan ningún error).
     *
     * @group chat-ia
     * @test
     */
    public function sin_acciones_va_la_identidad_pero_no_el_renglon_del_permiso()
    {
        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->comercio->id,
        ]);

        $texto = $this->service->build_system_prompt($conversation, $this->comercio, false);

        $this->assertStringContainsString('Quién te está escribiendo:', $texto);
        $this->assertStringContainsString('EL DUEÑO', $texto);
        $this->assertStringNotContainsString('NO te niegues por permiso', $texto);
    }

    /**
     * Una conversación sin `auth_user_id` usable (o con un usuario borrado) NO inventa quién es:
     * queda el prompt de antes. Afirmar un rol equivocado sería peor que no decir nada.
     *
     * @group chat-ia
     * @test
     */
    public function sin_persona_resoluble_no_se_escribe_el_bloque()
    {
        /*
         * `auth_user_id` no tiene default en la tabla, así que "sin persona" se escribe con un 0 y
         * no dejando la columna afuera: el caso que defiende esta rama es una fila vieja o un id
         * que ya no apunta a nadie, no un INSERT sin la columna (que la base rechaza sola).
         */
        $sin_auth = AiConversation::create(['user_id' => $this->comercio->id, 'auth_user_id' => 0]);

        $this->assertStringNotContainsString(
            'Quién te está escribiendo:',
            $this->service->build_system_prompt($sin_auth, $this->comercio, true)
        );

        $borrado = User::create([
            'name'     => 'Usuario que se va',
            'email'    => 'rol-borrado-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->comercio->id,
        ]);

        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $borrado->id,
        ]);

        $borrado->delete();

        $this->assertStringNotContainsString(
            'Quién te está escribiendo:',
            $this->service->build_system_prompt($conversation, $this->comercio, true)
        );
    }

    /**
     * 🔴 Y LO QUE DE VERDAD IMPORTA: que el rol esté en el `system` QUE SE MANDA A LA API en el
     * camino real de WhatsApp con acciones, que es exactamente el turno que falló en producción.
     * Probar solo el string del builder dejaría pasar el día que alguien cambie el payload.
     *
     * @group chat-ia
     * @test
     */
    public function el_system_que_viaja_a_la_api_por_whatsapp_lleva_el_rol_del_dueno()
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'model'       => 'claude-modelo-fake',
                'stop_reason' => 'end_turn',
                'content'     => [['type' => 'text', 'text' => 'Listo, le puse la foto a la sucursal.']],
                'usage'       => ['input_tokens' => 100, 'output_tokens' => 10],
            ], 200),
        ]);

        $conversation = AiConversation::create([
            'user_id'         => $this->comercio->id,
            'auth_user_id'    => $this->comercio->id,
            'origen'          => AiConversation::ORIGEN_WHATSAPP,
            'last_message_at' => now(),
        ]);

        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => 'Ponele esta foto a la sucursal Florida',
            'estado'             => 'listo',
            'canal'              => AiMessage::CANAL_WHATSAPP,
        ]);

        $assistant = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'canal'                => AiMessage::CANAL_WHATSAPP,
            'acciones_habilitadas' => 1,
        ]);

        $this->service->responder($conversation, $assistant);

        Http::assertSent(function ($request) {
            $cuerpo = $request->data();

            $system = '';

            foreach ($cuerpo['system'] as $bloque) {
                $system .= $bloque['text'];
            }

            return strpos($system, 'Lucas Dueño') !== false
                && strpos($system, 'EL DUEÑO') !== false
                && strpos($system, 'NO te niegues por permiso') !== false;
        });
    }
}
