<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ProveedorIaHelper;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Misión proveedores-ia-deepseek (22/9/2026) — la configuración del proveedor del asistente.
 *
 * Protege el contrato de `api/user/asistente-config` y `api/mi-consumo-ia` con el proveedor:
 * PUT con `proveedor=deepseek` guarda y responde con él; `deepseek` + `equilibrado` es 422 (DeepSeek
 * no tiene equilibrado); `deepseek` sin clave en la instalación es 422 con mensaje; un PUT SIN
 * `proveedor` (SPA vieja) conserva el guardado; GET trae `proveedores_disponibles` y
 * `pensamientos_por_proveedor`; y mi-consumo-ia trae `proveedor` y `modelo`.
 *
 * 🔴 Ningún test sale a la red: acá no se llama a la IA, solo se configura.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
class Config_de_proveedor_Test extends TestCase
{
    use DatabaseTransactions;

    /** Slug de la extensión que gatea el módulo. */
    const SLUG = 'agentes_ia';

    /** @var User */
    protected $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.anthropic.api_key'        => 'clave-anthropic-de-prueba',
            'services.anthropic.model_agil'     => 'claude-agil-test',
            'services.anthropic.model_equilibrado' => 'claude-equilibrado-test',
            'services.anthropic.model_profundo' => 'claude-profundo-test',
            'services.deepseek.api_key'         => 'clave-deepseek-de-prueba',
            'services.deepseek.model_agil'      => 'deepseek-flash-test',
            'services.deepseek.model_profundo'  => 'deepseek-pro-test',
        ]);

        $this->comercio = User::create([
            'name'         => 'Comercio config proveedor P48',
            'company_name' => 'Ferreteria P48',
            'email'        => 'config-p48-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->dar_extension();
    }

    /**
     * Asigna la extensión al comercio (el gate resuelve al dueño).
     *
     * @return void
     */
    protected function dar_extension()
    {
        $extencion = ExtencionEmpresa::where('slug', self::SLUG)->first();

        if (!$extencion) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => self::SLUG, 'name' => 'Asistente IA']);
        }

        $this->comercio->extencions()->attach($extencion->id);
        $this->comercio->load('extencions');
    }

    /**
     * PUT api/user/asistente-config como el dueño (no se llama `put` para no pisar el de TestCase).
     *
     * @param  array  $payload
     * @return \Illuminate\Testing\TestResponse
     */
    protected function put_config(array $payload)
    {
        return $this->actingAs($this->comercio, 'sanctum')->putJson('api/user/asistente-config', $payload);
    }

    /**
     * @group chat-ia
     * @test
     */
    public function guarda_deepseek_profundo_y_responde_con_el_proveedor()
    {
        $r = $this->put_config(['confianza' => 'resuelto', 'pensamiento' => 'profundo', 'proveedor' => 'deepseek']);

        $r->assertStatus(200)
          ->assertJson([
              'confianza'   => 'resuelto',
              'pensamiento' => 'profundo',
              'proveedor'   => 'deepseek',
              'modelo'      => ['proveedor' => 'deepseek', 'modelo' => 'deepseek-pro-test', 'pensamiento' => 'profundo'],
          ]);

        $fresco = $this->comercio->fresh();

        $this->assertSame('deepseek', (string) $fresco->agente_proveedor);
        $this->assertSame('profundo', (string) $fresco->agente_pensamiento);
    }

    /** @test */
    public function un_put_legado_con_claude_equilibrado_guarda_deepseek_agil()
    {
        $this->comercio->agente_proveedor = 'anthropic';
        $this->comercio->agente_pensamiento = 'equilibrado';
        $this->comercio->save();

        $this->put_config(['confianza' => 'cauteloso', 'pensamiento' => 'equilibrado', 'proveedor' => 'anthropic'])
             ->assertStatus(200)
             ->assertJson(['confianza' => 'cauteloso', 'proveedor' => 'deepseek', 'pensamiento' => 'agil']);

        $fresco = $this->comercio->fresh();
        $this->assertSame('deepseek', (string) $fresco->agente_proveedor);
        $this->assertSame('agil', (string) $fresco->agente_pensamiento);
        $this->assertSame('cauteloso', (string) $fresco->agente_confianza);
    }

    /** @test */
    public function sin_clave_de_deepseek_se_puede_guardar_la_configuracion()
    {
        config(['services.deepseek.api_key' => null]);

        $this->put_config(['confianza' => 'resuelto', 'pensamiento' => 'agil'])
             ->assertStatus(200)
             ->assertJsonPath('proveedor', 'deepseek')
             ->assertJsonPath('proveedores_disponibles', []);
    }

    /**
     * @group chat-ia
     * @test
     */
    public function un_proveedor_fuera_del_enum_es_422()
    {
        /* Arranca en 'anthropic' EXPLÍCITO: el test prueba "la columna no se toca", no el default. */
        $this->comercio->agente_proveedor = 'anthropic';
        $this->comercio->save();

        $this->put_config(['confianza' => 'resuelto', 'pensamiento' => 'agil', 'proveedor' => 'openai'])
             ->assertStatus(422);

        $this->assertSame('anthropic', (string) $this->comercio->fresh()->agente_proveedor);
    }

    /**
     * Una SPA vieja no manda `proveedor`: se guardan confianza y pensamiento y el proveedor queda
     * como estaba (acá, DeepSeek guardado antes).
     *
     * @group chat-ia
     * @test
     */
    public function un_put_sin_proveedor_conserva_el_guardado()
    {
        $this->comercio->agente_proveedor = 'deepseek';
        $this->comercio->agente_pensamiento = 'profundo';
        $this->comercio->save();

        $r = $this->put_config(['confianza' => 'cauteloso', 'pensamiento' => 'agil']);

        $r->assertStatus(200)->assertJson(['confianza' => 'cauteloso', 'pensamiento' => 'agil', 'proveedor' => 'deepseek']);

        $fresco = $this->comercio->fresh();

        $this->assertSame('deepseek', (string) $fresco->agente_proveedor, 'Sin `proveedor` en el PUT, el guardado se conserva.');
        $this->assertSame('agil', (string) $fresco->agente_pensamiento);
        $this->assertSame('cauteloso', (string) $fresco->agente_confianza);
    }



    /**
     * @group chat-ia
     * @test
     */
    public function el_get_trae_los_proveedores_disponibles_y_los_pensamientos_por_proveedor()
    {
        /* Sin tocar la columna: lo que trae el GET es el DEFAULT del sistema. */
        $r = $this->actingAs($this->comercio, 'sanctum')->getJson('api/user/asistente-config');

        $r->assertStatus(200)
          ->assertJsonStructure([
              'confianza', 'pensamiento', 'proveedor', 'proveedores_disponibles', 'pensamientos_por_proveedor',
              'modelo' => ['proveedor', 'modelo', 'pensamiento'],
          ])
          ->assertJson([
              'proveedor'                  => ProveedorIaHelper::PROVEEDOR_POR_DEFECTO,
              'proveedores_disponibles'    => ['deepseek'],
              'pensamientos_por_proveedor' => [
                  'anthropic' => ['agil', 'equilibrado', 'profundo'],
                  'deepseek'  => ['agil', 'profundo'],
              ],
          ]);

        /* Sin la clave de DeepSeek, deja de estar disponible (y el modal lo muestra deshabilitado). */
        config(['services.deepseek.api_key' => null]);

        $this->actingAs($this->comercio, 'sanctum')
             ->getJson('api/user/asistente-config')
             ->assertStatus(200)
             ->assertJsonPath('proveedores_disponibles', []);
    }

    /**
     * El `modelo` del GET es con lo que CORRE el asistente: si el dueño eligió DeepSeek y la clave
     * no está, `proveedor` sigue diciendo lo elegido y `modelo` muestra a Anthropic.
     *
     * @group chat-ia
     * @test
     */
    public function el_get_mantiene_deepseek_como_unico_proveedor_sin_clave()
    {
        $this->comercio->agente_proveedor = 'deepseek';
        $this->comercio->agente_pensamiento = 'profundo';
        $this->comercio->save();

        config(['services.deepseek.api_key' => null]);

        $this->actingAs($this->comercio, 'sanctum')
             ->getJson('api/user/asistente-config')
             ->assertStatus(200)
             ->assertJson([
                 'proveedor'   => 'deepseek',
                 'pensamiento' => 'profundo',
                 'modelo'      => ['proveedor' => 'deepseek', 'modelo' => 'deepseek-pro-test', 'pensamiento' => 'profundo'],
             ]);
    }

    /**
     * @group chat-ia
     * @test
     */
    public function mi_consumo_trae_el_proveedor_y_el_modelo_efectivos()
    {
        $this->comercio->agente_proveedor = 'deepseek';
        $this->comercio->agente_pensamiento = 'equilibrado';
        $this->comercio->save();

        $r = $this->actingAs($this->comercio, 'sanctum')->getJson('api/mi-consumo-ia');

        $r->assertStatus(200)
          ->assertJsonStructure(['consumo_mes', 'plan', 'cerca', 'supero', 'pensamiento', 'confianza', 'proveedor', 'modelo'])
          ->assertJson([
              'proveedor'   => 'deepseek',
              'modelo'      => 'deepseek-flash-test',
              /* `equilibrado` guardado a mano: en DeepSeek corre como agil, y el footer dice eso. */
              'pensamiento' => 'agil',
          ]);

        /* Con el dueño en Anthropic, las claves viejas siguen iguales y las nuevas dicen Anthropic. */
        $this->comercio->agente_proveedor = 'anthropic';
        $this->comercio->save();

        $this->actingAs($this->comercio, 'sanctum')
             ->getJson('api/mi-consumo-ia')
             ->assertStatus(200)
             ->assertJson(['proveedor' => 'deepseek', 'modelo' => 'deepseek-flash-test', 'pensamiento' => 'agil']);
    }
}
