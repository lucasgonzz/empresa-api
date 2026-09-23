<?php

namespace Tests\Feature\Mcp;

use App\Models\ExtencionEmpresa;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Misión asistente-mcp — la clave de conexión (contrato 2 del plan): lo que el modal de
 * configuración del asistente usa para "Conectá tu asistente a Claude y a otras apps".
 *
 * Lo que protege este archivo: que el POST devuelva el token UNA vez y deje UN solo token `mcp`
 * (el segundo POST revoca el primero, que deja de existir en la tabla y por lo tanto de
 * autenticar), que el GET diga el estado con la URL del servidor, que el DELETE revoque, que el
 * empleado raso quede afuera, que un token MCP no pueda administrar la conexión, y que los tres
 * ejemplos tengan la forma que cada cliente acepta tal cual.
 *
 * 🔴 EL MODO DE FALLA QUE TAPA: una clave que se pueda recuperar después (el GET la devolvería),
 * dos claves vivas por persona (revocar deja de ser una sola cosa), un token filtrado que se
 * fabrique otro, y un ejemplo pegado que no conecte —el de Claude Desktop con el header inline que
 * mcp-remote rompe por los espacios, o el de la API de Anthropic sin el `mcp_toolset` que la API
 * exige.
 *
 * 🔴 ACÁ SE ENTRA CON actingAs('web') Y NO CON BEARER: estas rutas son del SPA (cookie), y encima
 * exigen la sesión del sistema. No se mezcla con requests bearer en el mismo test: después de un
 * actingAs, Sanctum autentica también al bearer por el guard web y el "no entra" pasaría por el
 * motivo equivocado (memoria del proyecto). Lo que hay que verificar del token se mira en la tabla.
 */
class Conexion_Test extends TestCase
{
    use DatabaseTransactions;

    /** Slug de la extensión que gatea el módulo IA. */
    const SLUG = 'asistente_ia';

    /**
     * El formato de `creada_at` / `ultimo_uso_at` del contrato 2: ISO 8601 con la zona explícita
     * ("2026-09-23T10:00:00-03:00"), que es lo que la SPA parsea con moment. Ni el formato de
     * MySQL ni el de toJSON() con microsegundos y "Z" (hallazgo del verificador del contrato).
     */
    const ISO_8601_CON_ZONA = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/';

    /** @var User */
    protected $dueno;

    protected function setUp(): void
    {
        parent::setUp();

        // 🔴 Nunca la clave real del .env.testing: los tests jamás salen a la red.
        config(['services.anthropic.api_key' => null]);

        $this->dueno = User::create([
            'name'         => 'Dueño conexión MCP',
            'company_name' => 'Ferretería conexión MCP',
            'email'        => 'mcp-conexion-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $extencion = ExtencionEmpresa::where('slug', self::SLUG)->first();

        if (is_null($extencion)) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => self::SLUG, 'name' => 'Asistente IA']);
        }

        $this->dueno->extencions()->syncWithoutDetaching([$extencion->id]);
        $this->dueno->load('extencions');
    }

    /**
     * Cuántos tokens `mcp` tiene la persona en la tabla.
     *
     * @param  User  $persona
     * @return int
     */
    protected function tokens_mcp_de($persona)
    {
        return $persona->tokens()->where('name', 'mcp')->count();
    }

    /**
     * El POST devuelve el token una vez, con la URL y los ejemplos; el segundo POST revoca el
     * primero y deja UN solo token vivo.
     *
     * @group mcp
     * @test
     */
    public function el_post_devuelve_el_token_una_vez_y_deja_un_solo_token()
    {
        $this->actingAs($this->dueno, 'web');

        $primero = $this->postJson('api/mcp/conexion');

        $primero->assertStatus(201);

        $token_1 = (string) $primero->json('token');

        $this->assertNotEquals('', $token_1);
        $this->assertTrue($primero->json('activa'));
        $this->assertEquals('mcp', $primero->json('nombre'));
        $this->assertNotNull($primero->json('creada_at'));
        // ISO 8601 con zona, que es lo que la SPA parsea con moment: nunca el formato de MySQL ni el "Z" de toJSON().
        $this->assertMatchesRegularExpression(self::ISO_8601_CON_ZONA, $primero->json('creada_at'));
        $this->assertNull($primero->json('ultimo_uso_at'));
        $this->assertStringEndsWith('/api/mcp', $primero->json('url'));

        $this->assertEquals(1, $this->tokens_mcp_de($this->dueno));

        $guardado = PersonalAccessToken::findToken($token_1);

        $this->assertNotNull($guardado, 'El texto plano del POST tiene que corresponder a la fila de la tabla.');
        $this->assertTrue($guardado->can('mcp'), 'Con la habilidad que McpController exige.');
        $this->assertFalse($guardado->can('otra-cosa'), 'Y solo esa.');

        $segundo = $this->postJson('api/mcp/conexion');

        $segundo->assertStatus(201);

        $token_2 = (string) $segundo->json('token');

        $this->assertNotEquals($token_1, $token_2);
        $this->assertEquals(1, $this->tokens_mcp_de($this->dueno), 'Una clave viva por persona.');
        $this->assertNull(PersonalAccessToken::findToken($token_1), 'El primero deja de existir: ya no autentica.');
        $this->assertNotNull(PersonalAccessToken::findToken($token_2));
    }

    /**
     * Los tres ejemplos tienen la forma que cada cliente acepta pegada tal cual.
     *
     * @group mcp
     * @test
     */
    public function los_ejemplos_tienen_la_forma_de_cada_cliente()
    {
        $this->actingAs($this->dueno, 'web');

        $respuesta = $this->postJson('api/mcp/conexion');

        $respuesta->assertStatus(201);

        $token = (string) $respuesta->json('token');
        $url = (string) $respuesta->json('url');
        $ejemplos = $respuesta->json('ejemplos');

        $this->assertEquals(['claude_code', 'claude_desktop', 'anthropic_api', 'anthropic_api_nota'], array_keys($ejemplos));

        // Claude Code: el comando completo, con transporte http y el header.
        $this->assertEquals(
            'claude mcp add --transport http comerciocity ' . $url . ' --header "Authorization: Bearer ' . $token . '"',
            $ejemplos['claude_code']
        );

        // Claude Desktop: mcp-remote con el header por variable de entorno (el bug de los espacios en args).
        $desktop = json_decode($ejemplos['claude_desktop'], true);

        $this->assertIsArray($desktop, 'El ejemplo de Claude Desktop tiene que ser JSON válido.');
        $this->assertEquals('npx', $desktop['mcpServers']['comerciocity']['command']);
        $this->assertEquals(['-y', 'mcp-remote', $url, '--header', 'Authorization:${AUTH_HEADER}'], $desktop['mcpServers']['comerciocity']['args']);
        $this->assertEquals('Bearer ' . $token, $desktop['mcpServers']['comerciocity']['env']['AUTH_HEADER']);
        $this->assertStringNotContainsString('Authorization: Bearer', $ejemplos['claude_desktop'], 'El header con espacio NO va inline en los args.');

        // API de Anthropic: JSON PURO (se pega en un body tal cual) con las DOS mitades, y la nota
        // del header beta APARTE: un comentario adentro del JSON lo rompe hasta que alguien lo borre.
        $body = json_decode($ejemplos['anthropic_api'], true);

        $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'El ejemplo de la API tiene que ser JSON válido tal cual se copia.');
        $this->assertIsArray($body);
        $this->assertStringStartsWith('{', $ejemplos['anthropic_api'], 'Sin ninguna línea de comentario antes del JSON.');
        $this->assertEquals('El request lleva el header anthropic-beta: mcp-client-2025-11-20.', $ejemplos['anthropic_api_nota']);
        $this->assertEquals('url', $body['mcp_servers'][0]['type']);
        $this->assertEquals($url, $body['mcp_servers'][0]['url']);
        $this->assertEquals('comerciocity', $body['mcp_servers'][0]['name']);
        $this->assertEquals($token, $body['mcp_servers'][0]['authorization_token']);
        $this->assertEquals('mcp_toolset', $body['tools'][0]['type']);
        $this->assertEquals('comerciocity', $body['tools'][0]['mcp_server_name']);
    }

    /**
     * El GET dice el estado: sin clave, activa false; con clave, activa true, desde cuándo y la
     * URL. Y nunca devuelve el token.
     *
     * @group mcp
     * @test
     */
    public function el_get_dice_el_estado_y_nunca_devuelve_el_token()
    {
        $this->actingAs($this->dueno, 'web');

        $sin_clave = $this->getJson('api/mcp/conexion');

        $sin_clave->assertStatus(200);

        $this->assertFalse($sin_clave->json('activa'));
        $this->assertEquals('mcp', $sin_clave->json('nombre'));
        $this->assertNull($sin_clave->json('creada_at'));
        $this->assertNull($sin_clave->json('ultimo_uso_at'));
        $this->assertStringEndsWith('/api/mcp', $sin_clave->json('url'));

        $this->postJson('api/mcp/conexion')->assertStatus(201);

        $con_clave = $this->getJson('api/mcp/conexion');

        $con_clave->assertStatus(200);

        $this->assertTrue($con_clave->json('activa'));
        $this->assertNotNull($con_clave->json('creada_at'));
        $this->assertMatchesRegularExpression(self::ISO_8601_CON_ZONA, $con_clave->json('creada_at'));
        $this->assertNull($con_clave->json('ultimo_uso_at'), 'Todavía no se usó.');
        $this->assertArrayNotHasKey('token', $con_clave->json(), 'El token viaja UNA vez, en el POST.');
    }

    /**
     * @group mcp
     * @test
     */
    public function el_delete_revoca_y_el_get_dice_activa_false()
    {
        $this->actingAs($this->dueno, 'web');

        $this->postJson('api/mcp/conexion')->assertStatus(201);

        $this->assertEquals(1, $this->tokens_mcp_de($this->dueno));

        $revocar = $this->deleteJson('api/mcp/conexion');

        $revocar->assertStatus(200);

        $this->assertFalse($revocar->json('activa'));
        $this->assertNull($revocar->json('creada_at'));
        $this->assertStringEndsWith('/api/mcp', $revocar->json('url'));

        $this->assertEquals(0, $this->tokens_mcp_de($this->dueno));

        $this->assertFalse($this->getJson('api/mcp/conexion')->json('activa'));
    }

    /**
     * Un empleado raso queda afuera en la puerta, como en todo el módulo IA.
     *
     * @group mcp
     * @test
     */
    public function un_empleado_sin_admin_access_recibe_403()
    {
        $empleado = User::create([
            'name'     => 'Empleado conexión MCP',
            'email'    => 'mcp-conexion-empleado-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->dueno->id,
        ]);

        $this->actingAs($empleado, 'web');

        $this->getJson('api/mcp/conexion')->assertStatus(403);
        $this->postJson('api/mcp/conexion')->assertStatus(403);
        $this->deleteJson('api/mcp/conexion')->assertStatus(403);

        $this->assertEquals(0, $this->tokens_mcp_de($empleado));
    }

    /**
     * 🔴 Un token MCP no administra la conexión: si pudiera, una clave filtrada se fabricaría otra
     * que sobreviva a la revocación. Acá NO hay actingAs: se entra solo con el bearer.
     *
     * @group mcp
     * @test
     */
    public function un_token_mcp_no_puede_crear_ni_revocar_la_conexion()
    {
        $token = $this->dueno->createToken('mcp', ['mcp'])->plainTextToken;

        $headers = ['Authorization' => 'Bearer ' . $token];

        $this->getJson('api/mcp/conexion', $headers)->assertStatus(403);
        $this->postJson('api/mcp/conexion', [], $headers)->assertStatus(403);
        $this->deleteJson('api/mcp/conexion', [], $headers)->assertStatus(403);

        $this->assertEquals(1, $this->tokens_mcp_de($this->dueno), 'El token sigue vivo y no hay otro.');
    }
}
