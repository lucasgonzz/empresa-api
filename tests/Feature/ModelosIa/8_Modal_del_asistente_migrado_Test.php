<?php

namespace Tests\Feature\ModelosIa;

use App\Models\ExtencionEmpresa;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Misión modelos-ia-por-cliente (30/9/2026, arreglo 1 de la revisión) — el modal "Configurá tu
 * asistente" de un dueño que la migración pasó de Claude a DeepSeek, en una instalación SIN clave
 * de DeepSeek.
 *
 * El arreglo vive en la SPA (ConfiguracionAgente.vue preselecciona `modelo.proveedor` y
 * `modelo.pensamiento` cuando lo elegido no está disponible). Esto protege lo que la SPA necesita
 * del API para que funcione, sin cambiar su contrato: el GET trae en `modelo` lo que corre de
 * verdad —Claude y EQUILIBRADO, no Haiku—, y un PUT con eso da 200 y deja al dueño exactamente en
 * el Sonnet que ya usaba. Con la clave cargada, el mismo dueño corre DeepSeek sin tocar nada.
 *
 * 🔴 Ningún test sale a la red: acá no se llama a la IA, solo se configura.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
class Modal_del_asistente_migrado_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

        config([
            'services.anthropic.api_key'           => 'clave-anthropic-de-prueba',
            'services.anthropic.model_agil'        => 'claude-haiku-test',
            'services.anthropic.model_equilibrado' => 'claude-sonnet-test',
            'services.anthropic.model_profundo'    => 'claude-opus-test',
            'services.deepseek.api_key'            => null,
            'services.deepseek.model_agil'         => 'deepseek-flash-test',
        ]);

        $this->comercio = User::create([
            'name'         => 'Comercio modal migrado M8',
            'company_name' => 'Ferreteria M8',
            'email'        => 'modelos-ia-m8-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        /* Antes de la migración: el dueño en Claude Equilibrado (Sonnet). */
        $this->comercio->agente_proveedor   = 'anthropic';
        $this->comercio->agente_pensamiento = 'equilibrado';
        $this->comercio->agente_confianza   = 'resuelto';
        $this->comercio->save();

        $extencion = ExtencionEmpresa::where('slug', 'asistente_ia')->first();

        if (! $extencion) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => 'asistente_ia', 'name' => 'Asistente IA']);
        }

        $this->comercio->extencions()->attach($extencion->id);

        /* La migración de datos del 30/9. */
        require_once base_path('database/migrations/2026_09_30_100100_pasar_agente_a_deepseek.php');
        (new \PasarAgenteADeepseek())->up();

        $this->comercio = $this->comercio->fresh();
    }

    /**
     * @test
     */
    public function sin_clave_de_deepseek_el_get_trae_lo_que_corre_y_guardarlo_da_200_sin_bajar_de_modelo()
    {
        $this->assertSame('deepseek', (string) $this->comercio->agente_proveedor, 'La migración lo pasó a DeepSeek.');
        $this->assertSame('equilibrado', (string) $this->comercio->agente_pensamiento, 'Y no tocó el pensamiento.');

        $r = $this->actingAs($this->comercio, 'sanctum')->getJson('api/user/asistente-config');

        /* Lo que la SPA usa para preseleccionar: lo que corre, Claude EQUILIBRADO (Sonnet). */
        $r->assertStatus(200)
          ->assertJsonPath('proveedores_disponibles', ['anthropic'])
          ->assertJsonPath('modelo.proveedor', 'anthropic')
          ->assertJsonPath('modelo.pensamiento', 'equilibrado')
          ->assertJsonPath('modelo.modelo', 'claude-sonnet-test');

        /* Lo que manda el modal con lo preseleccionado: 200, y queda en el mismo Sonnet. */
        $this->actingAs($this->comercio, 'sanctum')
             ->putJson('api/user/asistente-config', ['confianza' => 'resuelto', 'pensamiento' => 'equilibrado', 'proveedor' => 'anthropic'])
             ->assertStatus(200)
             ->assertJsonPath('modelo.modelo', 'claude-sonnet-test');

        $fresco = $this->comercio->fresh();

        $this->assertSame('anthropic', (string) $fresco->agente_proveedor);
        $this->assertSame('equilibrado', (string) $fresco->agente_pensamiento, 'No baja de Sonnet a Haiku.');
    }

    /**
     * El dueño que NO abre el modal: el día que se carga la clave, corre DeepSeek solo.
     *
     * @test
     */
    public function con_la_clave_cargada_el_mismo_dueno_corre_deepseek_sin_tocar_nada()
    {
        config(['services.deepseek.api_key' => 'clave-deepseek-de-prueba']);

        $this->actingAs($this->comercio, 'sanctum')
             ->getJson('api/user/asistente-config')
             ->assertStatus(200)
             ->assertJsonPath('proveedor', 'deepseek')
             ->assertJsonPath('modelo.proveedor', 'deepseek')
             ->assertJsonPath('modelo.modelo', 'deepseek-flash-test');
    }
}
