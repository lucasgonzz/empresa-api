<?php

namespace Tests\Feature\MemoriaDelAgente;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Base de la suite de la memoria de contexto del agente y del cuestionario de inicio (misión
 * app-ia-y-memoria, E2, 29/10/2026).
 *
 * Arma un dueño con la extensión `agentes_ia` y deja a mano otro dueño (para la tenencia: dos
 * comercios en la misma base no se ven la memoria) y un empleado.
 *
 * 🔴 Ningún test sale a la red: la clave de DeepSeek queda nula y la de Anthropic es de prueba, con
 * Http::fake donde se corre el loop.
 */
abstract class MemoriaTestCase extends TestCase
{
    use DatabaseTransactions;

    const SLUG = 'agentes_ia';

    /** @var User */
    protected $dueno;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.anthropic.api_key' => 'clave-de-prueba',
            'services.deepseek.api_key'  => null,
        ]);

        $this->dueno = $this->nuevo_dueno('Ferretería Memoria', true);
    }

    /**
     * Un dueño nuevo, con o sin la extensión.
     *
     * @param  string  $negocio
     * @param  bool  $con_extension
     * @return \App\Models\User
     */
    protected function nuevo_dueno($negocio, $con_extension = true)
    {
        $dueno = User::create([
            'name'             => 'Dueño ' . $negocio,
            'company_name'     => $negocio . ' ' . uniqid(),
            'email'            => 'memoria-' . uniqid() . '@test.local',
            'password'         => Hash::make('secret'),
            'agente_proveedor' => 'anthropic',
        ]);

        if ($con_extension) {
            $this->dar_extension($dueno);
        }

        return $dueno;
    }

    /**
     * Le da la extensión al dueño.
     *
     * @param  \App\Models\User  $dueno
     * @return void
     */
    protected function dar_extension(User $dueno)
    {
        $extencion = ExtencionEmpresa::where('slug', self::SLUG)->first();

        if (is_null($extencion)) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => self::SLUG, 'name' => 'Agentes de IA']);
        }

        $dueno->extencions()->syncWithoutDetaching([$extencion->id]);
        $dueno->load('extencions');
    }

    /**
     * Un empleado del dueño de la suite.
     *
     * @param  int  $admin_access
     * @return \App\Models\User
     */
    protected function empleado($admin_access = 0)
    {
        return User::create([
            'name'         => 'Empleado memoria',
            'email'        => 'memoria-empleado-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
            'owner_id'     => $this->dueno->id,
            'admin_access' => $admin_access,
        ]);
    }

    /**
     * Una conversación del dueño con el mensaje del usuario y el assistant pendiente, en el canal pedido.
     *
     * @param  string  $canal
     * @param  string  $pedido
     * @param  \App\Models\User|null  $dueno
     * @param  bool  $con_acciones
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function conversacion_con_pendiente($canal = 'sistema', $pedido = 'Hola', $dueno = null, $con_acciones = false)
    {
        $dueno = is_null($dueno) ? $this->dueno : $dueno;

        $conversation = AiConversation::create([
            'user_id'      => $dueno->id,
            'auth_user_id' => $dueno->id,
        ]);

        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => $pedido,
            'estado'             => 'listo',
            'canal'              => $canal,
        ]);

        $assistant = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => (bool) $con_acciones,
            'canal'                => $canal,
        ]);

        return [$conversation, $assistant];
    }

    /**
     * Respuesta end_turn de Anthropic.
     *
     * @param  string  $texto
     * @return array
     */
    protected function end_turn($texto)
    {
        return [
            'model'       => 'claude-modelo-fake',
            'stop_reason' => 'end_turn',
            'content'     => [['type' => 'text', 'text' => $texto]],
            'usage'       => ['input_tokens' => 100, 'output_tokens' => 10],
        ];
    }

    /**
     * Respuesta tool_use de Anthropic con una sola herramienta.
     *
     * @param  string  $nombre
     * @param  array  $input
     * @return array
     */
    protected function tool_use($nombre, array $input)
    {
        return [
            'model'       => 'claude-modelo-fake',
            'stop_reason' => 'tool_use',
            'content'     => [[
                'type'  => 'tool_use',
                'id'    => 'toolu_' . uniqid(),
                'name'  => $nombre,
                'input' => $input,
            ]],
            'usage'       => ['input_tokens' => 100, 'output_tokens' => 10],
        ];
    }
}
