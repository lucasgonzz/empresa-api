<?php

namespace Tests\Feature\ChatIa;

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
    const SLUG = 'asistente_ia';

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

    /**
     * @group chat-ia
     * @test
     */
    public function deepseek_con_equilibrado_es_422_sin_guardar_nada()
    {
        $this->comercio->agente_pensamiento = 'agil';
        $this->comercio->save();

        $r = $this->put_config(['confianza' => 'cauteloso', 'pensamiento' => 'equilibrado', 'proveedor' => 'deepseek']);

        $r->assertStatus(422);
        $this->assertStringContainsString('DeepSeek', (string) $r->json('message'));
        $this->assertStringContainsString('equilibrado', (string) $r->json('message'));

        /* No se tocó nada: ni el proveedor, ni el pensamiento, ni la confianza. */
        $fresco = $this->comercio->fresh();

        $this->assertSame('anthropic', (string) $fresco->agente_proveedor);
        $this->assertSame('agil', (string) $fresco->agente_pensamiento);
        $this->assertNotSame('cauteloso', (string) $fresco->agente_confianza);
    }

    /**
     * @group chat-ia
     * @test
     */
    public function deepseek_sin_clave_en_la_instalacion_es_422_con_mensaje()
    {
        config(['services.deepseek.api_key' => null]);

        $r = $this->put_config(['confianza' => 'resuelto', 'pensamiento' => 'agil', 'proveedor' => 'deepseek']);

        $r->assertStatus(422);
        $this->assertEquals(
            'DeepSeek no está disponible en esta instalación: falta cargar la clave de la API.',
            (string) $r->json('message')
        );

        $this->assertSame('anthropic', (string) $this->comercio->fresh()->agente_proveedor, 'Sin clave no se puede elegir: la columna no se toca.');
    }

    /**
     * @group chat-ia
     * @test
     */
    public function un_proveedor_fuera_del_enum_es_422()
    {
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
     * Y sin `proveedor` la validez del pensamiento se mide contra el proveedor GUARDADO: con
     * DeepSeek guardado, `equilibrado` sigue siendo 422 aunque la SPA vieja no mande proveedor.
     *
     * @group chat-ia
     * @test
     */
    public function un_put_sin_proveedor_valida_el_pensamiento_contra_el_guardado()
    {
        $this->comercio->agente_proveedor = 'deepseek';
        $this->comercio->agente_pensamiento = 'agil';
        $this->comercio->save();

        $this->put_config(['confianza' => 'resuelto', 'pensamiento' => 'equilibrado'])->assertStatus(422);

        $this->assertSame('agil', (string) $this->comercio->fresh()->agente_pensamiento);
    }

    /**
     * @group chat-ia
     * @test
     */
    public function el_get_trae_los_proveedores_disponibles_y_los_pensamientos_por_proveedor()
    {
        $r = $this->actingAs($this->comercio, 'sanctum')->getJson('api/user/asistente-config');

        $r->assertStatus(200)
          ->assertJsonStructure([
              'confianza', 'pensamiento', 'proveedor', 'proveedores_disponibles', 'pensamientos_por_proveedor',
              'modelo' => ['proveedor', 'modelo', 'pensamiento'],
          ])
          ->assertJson([
              'proveedor'                  => 'anthropic',
              'proveedores_disponibles'    => ['anthropic', 'deepseek'],
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
             ->assertJson(['proveedores_disponibles' => ['anthropic']]);
    }

    /**
     * El `modelo` del GET es con lo que CORRE el asistente: si el dueño eligió DeepSeek y la clave
     * no está, `proveedor` sigue diciendo lo elegido y `modelo` muestra a Anthropic.
     *
     * @group chat-ia
     * @test
     */
    public function el_get_distingue_el_proveedor_elegido_del_que_efectivamente_corre()
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
                 'modelo'      => ['proveedor' => 'anthropic', 'modelo' => 'claude-profundo-test', 'pensamiento' => 'profundo'],
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
             ->assertJson(['proveedor' => 'anthropic', 'modelo' => 'claude-equilibrado-test', 'pensamiento' => 'equilibrado']);
    }
}
