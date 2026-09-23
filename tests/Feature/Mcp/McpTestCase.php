<?php

namespace Tests\Feature\Mcp;

use App\Models\AiConversation;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Base de la suite del servidor MCP (misión asistente-mcp, Fase 8 del plan, 22/9/2026).
 *
 * Arma un dueño propio con la extensión asistente_ia y una clave MCP REAL (createToken con la
 * habilidad `mcp`), y habla con POST api/mcp como lo haría un cliente de afuera: JSON-RPC 2.0 con
 * `Authorization: Bearer`. Un segundo dueño y un empleado se crean a pedido para los tests de
 * tenencia y de gate.
 *
 * 🔴 ACÁ NO HAY actingAs(). Después de un request con bearer el guard default queda en `sanctum`,
 * y un actingAs('web') en el mismo test haría que la sesión en memoria autentique TAMBIÉN al
 * siguiente request con token (Sanctum mira el guard web primero): los tests de "este token no
 * entra" pasarían por el motivo equivocado. Lo que necesita cookie del SPA (la administración de la
 * clave, 4_Conexion_Test) vive en su archivo y ahí no hay bearer.
 *
 * 🔴 La clave de Anthropic queda null: nada de esta suite sale a la red (las tools de lectura y de
 * carga se ejecutan de verdad, pero ninguna llama a la IA).
 */
abstract class McpTestCase extends TestCase
{
    use DatabaseTransactions;

    /** Slug de la extensión que gatea el módulo IA. */
    const SLUG = 'asistente_ia';

    /** Nombre del cliente que declaran los initialize de la suite. */
    const CLIENTE = 'Cliente MCP de prueba';

    /** @var User El dueño del negocio. */
    protected $dueno;

    /** @var string La clave MCP del dueño, en texto plano. */
    protected $token;

    protected function setUp(): void
    {
        parent::setUp();

        // 🔴 Nunca la clave real del .env.testing: los tests jamás salen a la red.
        config(['services.anthropic.api_key' => null]);

        $this->dueno = User::create([
            'name'         => 'Dueño MCP',
            'company_name' => 'Ferretería MCP ' . uniqid(),
            'email'        => 'mcp-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->dar_extension($this->dueno);

        $this->token = $this->clave_mcp($this->dueno);
    }

    /**
     * Asigna la extensión al dueño (el middleware resuelve siempre al dueño).
     *
     * @param  \App\Models\User  $dueno
     * @return void
     */
    protected function dar_extension(User $dueno)
    {
        $extencion = ExtencionEmpresa::where('slug', self::SLUG)->first();

        if (is_null($extencion)) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => self::SLUG, 'name' => 'Asistente IA']);
        }

        $dueno->extencions()->syncWithoutDetaching([$extencion->id]);
        $dueno->load('extencions');
    }

    /**
     * Una clave de Sanctum para la persona, con la habilidad `mcp` salvo que se pida otra.
     *
     * @param  \App\Models\User  $persona
     * @param  array<int, string>  $habilidades
     * @param  string  $nombre
     * @return string  El token en texto plano.
     */
    protected function clave_mcp(User $persona, array $habilidades = ['mcp'], $nombre = 'mcp')
    {
        return $persona->createToken($nombre, $habilidades)->plainTextToken;
    }

    /**
     * Otro dueño, con o sin la extensión, para los tests de aislamiento y de gate.
     *
     * @param  bool  $con_extension
     * @return \App\Models\User
     */
    protected function otro_dueno($con_extension = true)
    {
        $otro = User::create([
            'name'         => 'Otro dueño MCP',
            'company_name' => 'Otro negocio MCP',
            'email'        => 'mcp-otro-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        if ($con_extension) {
            $this->dar_extension($otro);
        }

        return $otro;
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
            'name'         => 'Empleado MCP',
            'email'        => 'mcp-empleado-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
            'owner_id'     => $this->dueno->id,
            'admin_access' => $admin_access,
        ]);
    }

    /**
     * Los headers de un request MCP.
     *
     * @param  string|null  $token  Default: la clave del dueño.
     * @param  string|null  $sesion  El Mcp-Session-Id, si hay.
     * @param  array  $extra
     * @return array<string, string>
     */
    protected function headers($token = null, $sesion = null, array $extra = [])
    {
        $headers = ['Authorization' => 'Bearer ' . (is_null($token) ? $this->token : $token)];

        if (!is_null($sesion)) {
            $headers['Mcp-Session-Id'] = $sesion;
        }

        return array_merge($headers, $extra);
    }

    /**
     * Un mensaje JSON-RPC.
     *
     * @param  string  $metodo
     * @param  array  $params
     * @param  mixed  $id  null = notificación (sin la clave `id`).
     * @return array
     */
    protected function mensaje($metodo, array $params = [], $id = 1)
    {
        $mensaje = ['jsonrpc' => '2.0', 'method' => $metodo];

        if (!is_null($id)) {
            $mensaje['id'] = $id;
        }

        if (count($params)) {
            $mensaje['params'] = $params;
        }

        return $mensaje;
    }

    /**
     * POST api/mcp con un solo mensaje.
     *
     * @param  string  $metodo
     * @param  array  $params
     * @param  mixed  $id
     * @param  string|null  $token
     * @param  string|null  $sesion
     * @param  array  $extra_headers
     * @return \Illuminate\Testing\TestResponse
     */
    protected function rpc($metodo, array $params = [], $id = 1, $token = null, $sesion = null, array $extra_headers = [])
    {
        return $this->postJson('api/mcp', $this->mensaje($metodo, $params, $id), $this->headers($token, $sesion, $extra_headers));
    }

    /**
     * Inicializa una sesión y devuelve su Mcp-Session-Id.
     *
     * @param  string|null  $token
     * @param  string  $cliente
     * @return string
     */
    protected function inicializar($token = null, $cliente = self::CLIENTE)
    {
        $respuesta = $this->rpc('initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities'    => [],
            'clientInfo'      => ['name' => $cliente, 'version' => '1.0'],
        ], 1, $token);

        $respuesta->assertStatus(200);

        $sesion = (string) $respuesta->headers->get('Mcp-Session-Id');

        $this->assertNotEquals('', $sesion, 'initialize tiene que devolver el header Mcp-Session-Id.');

        return $sesion;
    }

    /**
     * tools/call de una tool, con o sin sesión.
     *
     * @param  string  $name
     * @param  array  $arguments
     * @param  string|null  $sesion
     * @param  mixed  $id
     * @param  string|null  $token
     * @return \Illuminate\Testing\TestResponse
     */
    protected function tool($name, array $arguments = [], $sesion = null, $id = 1, $token = null)
    {
        return $this->rpc('tools/call', ['name' => $name, 'arguments' => $arguments], $id, $token, $sesion);
    }

    /**
     * El `structuredContent` de una respuesta de tools/call, como array asociativo.
     *
     * @param  \Illuminate\Testing\TestResponse  $respuesta
     * @return array
     */
    protected function estructurado(TestResponse $respuesta)
    {
        $respuesta->assertStatus(200);

        $estructurado = $respuesta->json('result.structuredContent');

        $this->assertIsArray($estructurado, 'La tool tenía que devolver un objeto JSON: ' . $respuesta->getContent());

        return $estructurado;
    }

    /**
     * Simula que el PRÓXIMO request lo hace otro cliente (otro proceso PHP).
     *
     * 🔴 Sin esto, dos requests con tokens de personas distintas en el mismo test se autentican
     * como la primera: el RequestGuard de Sanctum cachea el usuario que resolvió y Laravel no lo
     * olvida entre requests de un mismo test (en producción cada request es un proceso nuevo y el
     * problema no existe). Un test de tenencia que no llame a esto pasaría o fallaría por el motivo
     * equivocado.
     *
     * @return void
     */
    protected function como_otro_cliente()
    {
        $this->app['auth']->forgetGuards();
    }

    /**
     * La conversación de una sesión.
     *
     * @param  string  $sesion
     * @return \App\Models\AiConversation
     */
    protected function conversacion_de($sesion)
    {
        $conversation = AiConversation::where('mcp_sesion', $sesion)->first();

        $this->assertNotNull($conversation, 'La sesión tiene que tener su conversación.');

        return $conversation;
    }
}
