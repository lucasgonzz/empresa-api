<?php

namespace Tests\Feature\IaApp;

use App\Http\Controllers\Helpers\asistente_ia\IaAppHelper;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Misión app-ia-y-memoria — el token de la app y lo que abre (contratos C1.1, C1.2 y C5, §4 E1.1 y
 * E1.2 del plan).
 *
 * Lo que protege este archivo:
 *   1. admin-sync/ia-app/token emite el token SOLO con la clave del cliente, la contraseña correcta,
 *      la extensión prendida y una persona que puede usar el asistente; cada rechazo con su `code`.
 *   2. 🔴 Emitir el token NO toca el candado de sesión única (`session_id`/`last_activity`) ni abre
 *      una sesión de Laravel: abrir la app en el teléfono no le cierra la caja al dueño.
 *   3. 🔴 Un token de la app solo abre las rutas de la app (403 `fuera_de_la_app` en todo lo demás),
 *      sin cambiar nada para la cookie del sistema ni para la clave MCP.
 *   4. ia-app/yo y DELETE ia-app/token con la forma del contrato; el CORS suma el origen de la app.
 */
class Token_y_rutas_de_la_app_Test extends IaAppTestCase
{
    /**
     * @param  array  $body
     * @param  string|null  $clave
     * @return \Illuminate\Testing\TestResponse
     */
    protected function pedir_token(array $body, $clave = self::CLAVE_ADMIN)
    {
        return $this->postJson('api/admin-sync/ia-app/token', $body, $this->del_admin($clave));
    }

    public function test_emite_el_token_con_la_forma_del_contrato_y_el_token_abre_la_app()
    {
        $respuesta = $this->pedir_token([
            'doc_number'  => $this->dueno->doc_number,
            'password'    => self::CLAVE,
            'dispositivo' => 'Moto G de Lucas',
        ]);

        $respuesta->assertStatus(200)
            ->assertJsonStructure([
                'token',
                'usuario'     => ['id', 'nombre'],
                'negocio'     => ['nombre', 'logo_url'],
                'capacidades' => ['version_api', 'funciones'],
            ])
            ->assertJsonPath('usuario.id', $this->dueno->id)
            ->assertJsonPath('negocio.nombre', $this->dueno->company_name)
            ->assertJsonPath('capacidades.version_api', '1');

        $token = (string) $respuesta->json('token');

        $this->assertMatchesRegularExpression('/^\d+\|.+$/', $token, 'El token tiene la forma "<id>|<texto>".');

        $fila = PersonalAccessToken::findToken($token);

        $this->assertNotNull($fila);
        $this->assertSame('ia-app:Moto G de Lucas', $fila->name);
        $this->assertSame(['ia-app'], $fila->abilities);

        $funciones = $respuesta->json('capacidades.funciones');

        foreach (['modelo', 'adjuntos', 'audio', 'bloques', 'imagenes', 'buscar', 'renombrar'] as $funcion) {
            $this->assertContains($funcion, $funciones);
        }

        $this->como_otro_cliente();

        $this->getJson('api/ia-app/yo', $this->de_la_app($token))->assertStatus(200)
            ->assertJsonPath('usuario.id', $this->dueno->id);
    }

    public function test_no_toca_el_candado_de_sesion_unica_ni_abre_una_sesion()
    {
        $hace_un_rato = Carbon::now()->subMinutes(3)->startOfSecond();

        User::where('id', $this->dueno->id)->update([
            'session_id'    => 'sesion-de-la-caja',
            'last_activity' => $hace_un_rato,
        ]);

        $this->pedir_token([
            'doc_number' => $this->dueno->doc_number,
            'password'   => self::CLAVE,
        ])->assertStatus(200);

        $despues = User::find($this->dueno->id);

        $this->assertSame('sesion-de-la-caja', $despues->session_id);
        $this->assertSame($hace_un_rato->toDateTimeString(), Carbon::parse($despues->last_activity)->toDateTimeString());
        $this->assertNull(Auth::guard('web')->user(), 'Emitir el token no puede dejar a nadie logueado por la sesión.');
    }

    public function test_sin_la_clave_del_admin_no_emite_nada()
    {
        $body = ['doc_number' => $this->dueno->doc_number, 'password' => self::CLAVE];

        $this->pedir_token($body, 'otra-clave')->assertStatus(401)->assertJsonPath('code', 'clave_invalida');
        $this->postJson('api/admin-sync/ia-app/token', $body, ['Accept' => 'application/json'])->assertStatus(401);

        // Sin clave cargada de este lado, "no hay clave" no es un pase libre.
        config(['services.admin_api.api_key' => '']);
        $this->pedir_token($body, '')->assertStatus(401);

        $this->assertSame(1, PersonalAccessToken::where('tokenable_id', $this->dueno->id)->count(), 'Solo el token del setUp.');
    }

    public function test_contrasena_mala_y_documento_inexistente_dan_el_mismo_401()
    {
        $mala = $this->pedir_token(['doc_number' => $this->dueno->doc_number, 'password' => 'otra']);
        $inexistente = $this->pedir_token(['doc_number' => '99999999999', 'password' => self::CLAVE]);

        $mala->assertStatus(401)->assertJsonPath('code', 'credenciales_invalidas');
        $inexistente->assertStatus(401)->assertJsonPath('code', 'credenciales_invalidas');
        $this->assertSame($mala->json('message'), $inexistente->json('message'));
    }

    public function test_con_la_contrasena_mala_siempre_401_aunque_no_tenga_extension_o_no_sea_dueno()
    {
        // 🔴 sin_extension y no_es_dueno salen solo DESPUÉS de una contraseña correcta: si no, el
        // endpoint le diría a cualquiera qué documentos son de un cliente y cuáles de un empleado.
        $raso = $this->empleado(0);

        $this->pedir_token(['doc_number' => $raso->doc_number, 'password' => 'mala'])
            ->assertStatus(401)
            ->assertJsonPath('code', 'credenciales_invalidas');

        $this->dueno->extencions()->detach();

        $this->pedir_token(['doc_number' => $this->dueno->doc_number, 'password' => 'mala'])
            ->assertStatus(401)
            ->assertJsonPath('code', 'credenciales_invalidas');

        $this->pedir_token(['doc_number' => $raso->doc_number, 'password' => 'mala'])
            ->assertStatus(401)
            ->assertJsonPath('code', 'credenciales_invalidas');
    }

    public function test_el_documento_con_puntos_tambien_entra()
    {
        $this->dueno->doc_number = '30123456';
        $this->dueno->save();

        $this->pedir_token(['doc_number' => '30.123.456', 'password' => self::CLAVE])->assertStatus(200);
    }

    public function test_validacion_con_code()
    {
        $this->pedir_token(['doc_number' => $this->dueno->doc_number])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validacion');
    }

    public function test_sin_la_extension_da_sin_extension()
    {
        $this->dueno->extencions()->detach();

        $this->pedir_token(['doc_number' => $this->dueno->doc_number, 'password' => self::CLAVE])
            ->assertStatus(403)
            ->assertJsonPath('code', 'sin_extension');
    }

    public function test_un_empleado_raso_da_no_es_dueno_y_uno_con_admin_access_entra()
    {
        $raso = $this->empleado(0);

        $this->pedir_token(['doc_number' => $raso->doc_number, 'password' => 'clave-del-empleado'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'no_es_dueno');

        $encargado = $this->empleado(1);

        $this->pedir_token(['doc_number' => $encargado->doc_number, 'password' => 'clave-del-empleado'])
            ->assertStatus(200)
            ->assertJsonPath('usuario.id', $encargado->id)
            ->assertJsonPath('negocio.nombre', $this->dueno->company_name);
    }

    public function test_el_usuario_de_otro_negocio_de_la_misma_base_no_entra_por_esta_instancia()
    {
        $otro = User::create([
            'name'         => 'Otro dueño',
            'company_name' => 'Otro negocio',
            'email'        => 'ia-app-otro-' . uniqid() . '@test.local',
            'doc_number'   => '27' . substr(uniqid(), -6),
            'password'     => \Illuminate\Support\Facades\Hash::make('otra-clave'),
        ]);

        $this->dar_extension($otro);

        $this->pedir_token(['doc_number' => $otro->doc_number, 'password' => 'otra-clave'])
            ->assertStatus(401)
            ->assertJsonPath('code', 'credenciales_invalidas');
    }

    public function test_el_dueno_para_el_directorio_del_admin()
    {
        $this->getJson('api/admin-sync/ia-app/dueno', $this->del_admin())
            ->assertStatus(200)
            ->assertExactJson([
                'doc_number'       => $this->dueno->doc_number,
                'nombre'           => 'Dueño de la app',
                'negocio'          => $this->dueno->company_name,
                'extension_activa' => true,
            ]);

        $this->getJson('api/admin-sync/ia-app/dueno', $this->del_admin('otra'))->assertStatus(401);
    }

    public function test_yo_trae_usuario_negocio_capacidades_y_cuestionario()
    {
        $respuesta = $this->getJson('api/ia-app/yo', $this->de_la_app());

        $respuesta->assertStatus(200)
            ->assertJsonStructure([
                'usuario'      => ['id', 'nombre'],
                'negocio'      => ['nombre', 'logo_url'],
                'capacidades'  => ['version_api', 'funciones'],
                'cuestionario' => ['completado', 'salteado', 'completado_en'],
            ])
            ->assertJsonPath('cuestionario.completado', false)
            ->assertJsonPath('cuestionario.salteado', false);

        $hay_memoria = class_exists(IaAppHelper::CLASE_DE_LA_MEMORIA);

        $this->assertSame($hay_memoria, in_array('contexto', $respuesta->json('capacidades.funciones'), true),
            'contexto se anuncia solo si la memoria del negocio está en este código.');
    }

    public function test_un_token_de_la_app_no_abre_nada_fuera_de_la_app()
    {
        foreach ([['GET', 'api/user'], ['GET', 'api/article/1'], ['POST', 'api/mcp'], ['GET', 'api/plan-feature']] as $ruta) {

            $this->como_otro_cliente();

            $this->json($ruta[0], $ruta[1], [], $this->de_la_app())
                ->assertStatus(403)
                ->assertJsonPath('code', 'fuera_de_la_app');
        }

        $this->como_otro_cliente();

        $this->getJson('api/ai-conversations', $this->de_la_app())->assertStatus(200);
    }

    public function test_la_clave_mcp_no_cambia_y_la_cookie_tampoco()
    {
        $mcp = $this->dueno->createToken('mcp', ['mcp'])->plainTextToken;

        // La clave MCP sigue entrando al chat (no es de la app: el middleware no la mira).
        $this->getJson('api/ai-conversations', $this->de_la_app($mcp))->assertStatus(200);
    }

    public function test_la_cookie_del_sistema_no_pasa_por_la_lista_blanca()
    {
        $this->actingAs($this->dueno, 'web');

        // `user/asistente-config` no tiene nada que ver: lo que importa es que una ruta de afuera de
        // la lista blanca responde lo de siempre con la cookie.
        $this->getJson('api/ai-conversations')->assertStatus(200);
        $this->getJson('api/plan-feature')->assertStatus(200);
    }

    public function test_cerrar_sesion_revoca_el_token()
    {
        $this->deleteJson('api/ia-app/token', [], $this->de_la_app())->assertStatus(204);

        $this->assertNull(PersonalAccessToken::findToken($this->token));

        $this->como_otro_cliente();

        $this->getJson('api/ia-app/yo', $this->de_la_app())->assertStatus(401);
    }

    public function test_cors_suma_el_origen_de_la_app_sin_duplicados_ni_vacios()
    {
        $origenes = config('cors.allowed_origins');

        $this->assertContains('https://ia.comerciocity.com', $origenes);
        $this->assertSame(array_values(array_unique($origenes)), $origenes, 'Sin duplicados.');

        foreach ($origenes as $origen) {
            $this->assertNotSame('', trim((string) $origen), 'Sin vacíos.');
            $this->assertNotNull($origen);
        }

        $this->assertContains('Content-Disposition', config('cors.exposed_headers'));

        $preflight = $this->call('OPTIONS', 'api/ia-app/yo', [], [], [], [
            'HTTP_ORIGIN'                         => 'https://ia.comerciocity.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD'  => 'GET',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization',
        ]);

        $this->assertSame('https://ia.comerciocity.com', $preflight->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_cors_lee_los_origenes_de_la_variable()
    {
        $anterior = getenv('IA_APP_ORIGENES');

        $this->poner_variable('IA_APP_ORIGENES', 'https://ia.comerciocity.com, ,https://otra.ia.test,https://ia.comerciocity.com');

        try {
            $config = require config_path('cors.php');
        } finally {
            $this->poner_variable('IA_APP_ORIGENES', $anterior === false ? null : $anterior);
        }

        $this->assertContains('https://ia.comerciocity.com', $config['allowed_origins']);
        $this->assertContains('https://otra.ia.test', $config['allowed_origins']);
        $this->assertSame(array_values(array_unique($config['allowed_origins'])), $config['allowed_origins']);
        $this->assertNotContains('', $config['allowed_origins']);
    }

    /**
     * Pone (o saca, con null) una variable de entorno en los tres lugares que lee env().
     *
     * @param  string  $nombre
     * @param  string|null  $valor
     * @return void
     */
    protected function poner_variable($nombre, $valor)
    {
        if (is_null($valor)) {
            putenv($nombre);
            unset($_ENV[$nombre], $_SERVER[$nombre]);

            return;
        }

        putenv($nombre . '=' . $valor);
        $_ENV[$nombre] = $valor;
        $_SERVER[$nombre] = $valor;
    }
}
