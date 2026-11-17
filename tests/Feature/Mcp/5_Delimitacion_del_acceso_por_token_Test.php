<?php

namespace Tests\Feature\Mcp;

use App\Http\Controllers\Helpers\asistente_ia\IaAppHelper;
use Illuminate\Support\Facades\Route;

class Delimitacion_del_acceso_por_token_Test extends McpTestCase
{
    public function test_la_clave_mcp_no_lee_ni_escribe_fuera_de_su_transporte()
    {
        foreach ([['GET', 'api/article/1'], ['PUT', 'api/article-update-addresses'],
            ['GET', 'api/ai-conversations'], ['GET', 'api/plan-feature'],
            ['GET', 'api/mcp/conexion'], ['POST', 'api/mcp/conexion'],
            ['DELETE', 'api/mcp/conexion'], ['HEAD', 'api/ai-conversations']] as $ruta) {
            $this->app['auth']->forgetGuards();
            $respuesta = $this->json($ruta[0], $ruta[1], [], $this->headers())->assertStatus(403);
            if ($ruta[0] !== 'HEAD') {
                $respuesta->assertJsonPath('code', 'fuera_del_mcp');
            }
        }
    }

    public function test_el_transporte_mcp_sigue_funcionando_con_clave_real()
    {
        $this->rpc('ping')->assertStatus(200)->assertJsonPath('result', []);
        $this->app['auth']->forgetGuards();
        $this->getJson('api/mcp', $this->headers())->assertStatus(405);
        $this->app['auth']->forgetGuards();
        $this->json('HEAD', 'api/mcp', [], $this->headers())->assertStatus(405);
        $this->app['auth']->forgetGuards();
        $this->deleteJson('api/mcp', [], $this->headers())->assertStatus(204);
    }

    public function test_la_cookie_no_convierte_una_clave_mcp_en_acceso_general()
    {
        $this->actingAs($this->dueno, 'web');
        $this->getJson('api/ai-conversations', $this->headers())
            ->assertStatus(403)->assertJsonPath('code', 'fuera_del_mcp');
        $this->getJson('api/ai-conversations')->assertStatus(200);
    }

    public function test_las_claves_genericas_y_mezcladas_no_abren_el_erp_ni_el_mcp()
    {
        foreach ([['*'], ['mcp', '*'], ['ia-app', '*'], ['mcp', 'ia-app'],
            ['mcp', 'otro'], ['ia-app', 'otro'], ['otro'], []] as $habilidades) {
            $token = $this->clave_mcp($this->dueno, $habilidades, 'clave-arbitraria');
            foreach (['api/ai-conversations', 'api/mcp'] as $ruta) {
                $this->app['auth']->forgetGuards();
                $this->getJson($ruta, $this->headers($token))
                    ->assertStatus(403)->assertJsonPath('code', 'token_no_autorizado');
            }
        }
    }

    public function test_una_clave_desconocida_no_se_salva_por_la_cookie()
    {
        $this->actingAs($this->dueno, 'web');
        $this->getJson('api/ai-conversations', $this->headers('123456|inexistente'))
            ->assertStatus(401)->assertJsonPath('code', 'token_no_valido');
    }

    public function test_una_clave_generica_tampoco_se_salva_por_la_cookie()
    {
        $token = $this->clave_mcp($this->dueno, ['*'], 'generica');
        $this->actingAs($this->dueno, 'web');
        $this->getJson('api/ai-conversations', $this->headers($token))
            ->assertStatus(403)->assertJsonPath('code', 'token_no_autorizado');
    }

    public function test_el_token_de_la_app_conserva_su_canal_sin_abrir_mcp_ni_erp()
    {
        $token = $this->dueno->createToken('ia-app:prueba', [IaAppHelper::HABILIDAD])->plainTextToken;
        $this->getJson('api/ia-app/yo', $this->headers($token))->assertStatus(200);
        foreach (['api/article/1', 'api/mcp'] as $ruta) {
            $this->app['auth']->forgetGuards();
            $this->getJson($ruta, $this->headers($token))
                ->assertStatus(403)->assertJsonPath('code', 'fuera_de_la_app');
        }
    }

    public function test_un_prefijo_o_un_alias_del_controller_no_amplia_el_canal_mcp()
    {
        Route::middleware(['api', 'auth:sanctum'])->get('api/mcp/exportar', function () {
            return response()->json(['no_debe_ejecutarse' => true]);
        });
        Route::middleware(['api', 'auth:sanctum'])->get('api/alias-mcp', 'App\Http\Controllers\McpController@get');
        foreach (['api/mcp/exportar', 'api/alias-mcp'] as $ruta) {
            $this->app['auth']->forgetGuards();
            $this->getJson($ruta, $this->headers())->assertStatus(403)->assertJsonPath('code', 'fuera_del_mcp');
        }
    }

    public function test_parametros_y_barra_final_no_amplian_las_operaciones_de_la_clave()
    {
        foreach (['api/plan-feature?destino=api/mcp', 'api/mcp/conexion/'] as $ruta) {
            $this->app['auth']->forgetGuards();
            $this->getJson($ruta, $this->headers())->assertStatus(403)->assertJsonPath('code', 'fuera_del_mcp');
        }
        $this->app['auth']->forgetGuards();
        $this->postJson('api/mcp/?irrelevante=1', $this->mensaje('ping'), $this->headers())->assertStatus(200);
    }

    public function test_los_canales_con_autenticacion_propia_no_se_convierten_en_sanctum()
    {
        // Una clave arbitraria no sustituye los secretos propios de admin-sync o de impresión.
        foreach (['api/admin-sync/ia-app/token', 'api/print-agent/heartbeat'] as $ruta) {
            $this->app['auth']->forgetGuards();
            $respuesta = $this->postJson($ruta, [], $this->headers('123456|inexistente'));
            $this->assertNotSame('token_no_valido', $respuesta->json('code'));
            $this->assertContains($respuesta->status(), [401, 403]);
        }
    }
}
