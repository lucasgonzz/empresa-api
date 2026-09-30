<?php

namespace Tests\Feature\ModelosIa;

use App\Models\ExtencionEmpresa;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Misión modelos-ia-por-cliente (30/9/2026) — el contrato `GET|PUT admin-sync/modelos-ia`.
 *
 * Protege: la forma del payload (catálogo con disponibilidad, y por tarea lo elegido, lo que vale y
 * lo que corre), la clave del header validada ADENTRO del controlador cuando el cliente la tiene
 * cargada, el 409 sin dueño resoluble, el PUT que guarda solo lo que vino, el 422 con una opción que
 * no existe o no vale (imágenes con Pro) sin guardar NADA, la opción de un proveedor sin clave
 * aceptada (el payload muestra el fallback), el asistente traducido a agente_proveedor +
 * agente_pensamiento, y "gana el último" contra el PUT user/asistente-config del dueño.
 *
 * 🔴 Ningún test sale a la red: acá no se llama a la IA (Http::fake vacío igual, por las dudas).
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
class Admin_sync_modelos_ia_Test extends TestCase
{
    use DatabaseTransactions;

    /** La clave del header que tiene cargada este cliente. */
    const CLAVE = 'clave-admin-de-prueba';

    /** @var User */
    protected $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

        config([
            'services.admin_api.api_key'           => self::CLAVE,
            'services.admin_api.require_api_key'   => false,
            'services.anthropic.api_key'           => 'clave-anthropic-de-prueba',
            'services.anthropic.model'             => 'claude-general-legado-test',
            'services.anthropic.model_agil'        => 'claude-haiku-test',
            'services.anthropic.model_equilibrado' => 'claude-sonnet-test',
            'services.anthropic.model_profundo'    => 'claude-opus-test',
            'services.deepseek.api_key'            => 'clave-deepseek-de-prueba',
            'services.deepseek.model_agil'         => 'deepseek-flash-test',
            'services.deepseek.model_profundo'     => 'deepseek-pro-test',
            'services.article_image_validation.model'       => 'claude-haiku-legado-imagenes-test',
            'services.importacion_excel_ia.model_anthropic' => 'claude-sonnet-legado-excel-test',
        ]);

        $this->comercio = User::create([
            'name'         => 'Comercio modelos IA A5',
            'company_name' => 'Ferreteria A5',
            'email'        => 'modelos-ia-a5-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        /* La instancia es la de este comercio (la base de testing tiene varios dueños). */
        config(['app.USER_ID' => $this->comercio->id]);
    }

    /**
     * @param  bool  $con_clave
     * @return array
     */
    protected function headers($con_clave = true)
    {
        return $con_clave ? ['X-Admin-Api-Key' => self::CLAVE] : [];
    }

    /**
     * @group admin-sync
     * @test
     */
    public function el_get_devuelve_el_catalogo_y_las_cuatro_tareas_con_lo_que_corre()
    {
        $r = $this->getJson('api/admin-sync/modelos-ia', $this->headers());

        $r->assertStatus(200)
          ->assertJsonPath('ok', true)
          ->assertJsonCount(5, 'opciones')
          ->assertJsonPath('opciones.0.id', 'deepseek_flash')
          ->assertJsonPath('opciones.0.proveedor', 'deepseek')
          ->assertJsonPath('opciones.0.nombre', 'DeepSeek Flash')
          ->assertJsonPath('opciones.0.modelo', 'deepseek-flash-test')
          ->assertJsonPath('opciones.0.vision', true)
          ->assertJsonPath('opciones.0.disponible', true)
          ->assertJsonPath('opciones.1.id', 'deepseek_pro')
          ->assertJsonPath('opciones.1.vision', false)
          ->assertJsonPath('tareas.asistente.nombre', 'Asistente del dueño')
          ->assertJsonPath('tareas.asistente.opcion', 'deepseek_flash')
          ->assertJsonPath('tareas.whatsapp.opcion', 'deepseek_flash')
          ->assertJsonPath('tareas.imagenes.opcion', 'deepseek_flash')
          ->assertJsonPath('tareas.excel.opcion', 'deepseek_pro')
          ->assertJsonPath('tareas.excel.efectiva.opcion', 'deepseek_pro')
          ->assertJsonPath('tareas.excel.efectiva.proveedor', 'deepseek')
          ->assertJsonPath('tareas.excel.efectiva.modelo', 'deepseek-pro-test')
          ->assertJsonPath('tareas.excel.efectiva.fallback', false);

        $this->assertNotContains('deepseek_pro', $r->json('tareas.imagenes.opciones_validas'));
        $this->assertCount(5, $r->json('tareas.whatsapp.opciones_validas'));
    }

    /**
     * Sin clave de DeepSeek: lo elegido sigue siendo DeepSeek y lo efectivo es el legado de Anthropic
     * (opcion null, el id real del modelo, fallback true). `disponible` de DeepSeek en false.
     *
     * @group admin-sync
     * @test
     */
    public function sin_clave_de_deepseek_el_payload_muestra_el_fallback_legado()
    {
        config(['services.deepseek.api_key' => null]);

        $r = $this->getJson('api/admin-sync/modelos-ia', $this->headers());

        $r->assertStatus(200)
          ->assertJsonPath('opciones.0.disponible', false)
          ->assertJsonPath('opciones.2.disponible', true)
          ->assertJsonPath('tareas.whatsapp.opcion', 'deepseek_flash')
          ->assertJsonPath('tareas.whatsapp.efectiva.opcion', null)
          ->assertJsonPath('tareas.whatsapp.efectiva.proveedor', 'anthropic')
          ->assertJsonPath('tareas.whatsapp.efectiva.modelo', 'claude-general-legado-test')
          ->assertJsonPath('tareas.whatsapp.efectiva.fallback', true)
          ->assertJsonPath('tareas.imagenes.efectiva.modelo', 'claude-haiku-legado-imagenes-test')
          ->assertJsonPath('tareas.excel.efectiva.modelo', 'claude-sonnet-legado-excel-test')
          ->assertJsonPath('tareas.asistente.efectiva.opcion', 'claude_haiku')
          ->assertJsonPath('tareas.asistente.efectiva.fallback', true);

        /* Sin ninguna clave, efectiva es null. */
        config(['services.anthropic.api_key' => null]);

        $this->getJson('api/admin-sync/modelos-ia', $this->headers())
             ->assertStatus(200)
             ->assertJsonPath('tareas.whatsapp.efectiva', null);
    }

    /**
     * La clave del header se exige ADENTRO del controlador cuando el cliente la tiene cargada, aunque
     * require_api_key esté apagado (como en producción). Sin clave cargada de este lado, pasa.
     *
     * @group admin-sync
     * @test
     */
    public function sin_la_clave_del_header_es_401_y_no_guarda_nada()
    {
        $this->getJson('api/admin-sync/modelos-ia', $this->headers(false))->assertStatus(401)->assertJson(['error' => 'unauthorized']);
        $this->getJson('api/admin-sync/modelos-ia', ['X-Admin-Api-Key' => 'otra'])->assertStatus(401);

        $this->putJson('api/admin-sync/modelos-ia', ['whatsapp' => 'claude_opus'], $this->headers(false))->assertStatus(401);

        $this->assertNull($this->comercio->fresh()->ia_modelo_whatsapp);

        /* El cliente sin la clave cargada: el criterio de siempre del canal, pasa. */
        config(['services.admin_api.api_key' => null]);

        $this->getJson('api/admin-sync/modelos-ia')->assertStatus(200);
    }

    /**
     * Sin dueño resoluble (varios dueños en la base y sin USER_ID): 409.
     *
     * @group admin-sync
     * @test
     */
    public function sin_duenno_resoluble_es_409()
    {
        User::create([
            'name'     => 'Otro comercio A5',
            'email'    => 'modelos-ia-a5-otro-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        config(['app.USER_ID' => null]);

        $this->getJson('api/admin-sync/modelos-ia', $this->headers())->assertStatus(409)->assertJsonStructure(['message']);
        $this->putJson('api/admin-sync/modelos-ia', ['whatsapp' => 'claude_opus'], $this->headers())->assertStatus(409);
    }

    /**
     * El PUT guarda solo las tareas que vinieron, traduce el asistente a las dos columnas del modal
     * y responde con el payload ya actualizado.
     *
     * @group admin-sync
     * @test
     */
    public function el_put_guarda_lo_que_vino_y_traduce_el_asistente()
    {
        $this->comercio->ia_modelo_excel = 'claude_sonnet';
        $this->comercio->save();

        $r = $this->putJson('api/admin-sync/modelos-ia', [
            'asistente' => 'claude_opus',
            'whatsapp'  => 'deepseek_pro',
            'imagenes'  => 'claude_haiku',
            'otra_cosa' => 'se ignora',
        ], $this->headers());

        $r->assertStatus(200)
          ->assertJsonPath('tareas.asistente.opcion', 'claude_opus')
          ->assertJsonPath('tareas.asistente.efectiva.modelo', 'claude-opus-test')
          ->assertJsonPath('tareas.whatsapp.opcion', 'deepseek_pro')
          ->assertJsonPath('tareas.imagenes.opcion', 'claude_haiku')
          ->assertJsonPath('tareas.imagenes.efectiva.modelo', 'claude-haiku-test')
          ->assertJsonPath('tareas.excel.opcion', 'claude_sonnet');

        $fresco = $this->comercio->fresh();

        $this->assertSame('anthropic', (string) $fresco->agente_proveedor, 'El asistente se guarda en las columnas del modal.');
        $this->assertSame('profundo', (string) $fresco->agente_pensamiento);
        $this->assertSame('deepseek_pro', (string) $fresco->ia_modelo_whatsapp);
        $this->assertSame('claude_haiku', (string) $fresco->ia_modelo_imagenes);
        $this->assertSame('claude_sonnet', (string) $fresco->ia_modelo_excel, 'Lo que no vino no se toca.');
    }

    /**
     * Una opción inexistente, o Pro para imágenes, es 422 con el error en su tarea, y no se guarda
     * NADA (tampoco lo válido que venía al lado).
     *
     * @group admin-sync
     * @test
     */
    public function una_opcion_invalida_es_422_y_no_guarda_nada()
    {
        $r = $this->putJson('api/admin-sync/modelos-ia', [
            'whatsapp' => 'claude_opus',
            'imagenes' => 'deepseek_pro',
            'excel'    => 'gpt_5',
        ], $this->headers());

        $r->assertStatus(422)->assertJsonStructure(['message', 'errors' => ['imagenes', 'excel']]);

        $this->assertArrayNotHasKey('whatsapp', $r->json('errors'));
        $this->assertStringContainsString('no ve imágenes', $r->json('errors.imagenes.0'));

        $fresco = $this->comercio->fresh();

        $this->assertNull($fresco->ia_modelo_whatsapp, 'Con un error no se guarda ninguna.');
        $this->assertNull($fresco->ia_modelo_imagenes);
        $this->assertNull($fresco->ia_modelo_excel);

        $this->putJson('api/admin-sync/modelos-ia', ['asistente' => null], $this->headers())->assertStatus(422);
        $this->putJson('api/admin-sync/modelos-ia', ['asistente' => ['claude_opus']], $this->headers())->assertStatus(422);
    }

    /**
     * Se puede dejar elegida una opción de un proveedor SIN clave (el admin la deja lista antes de
     * cargarla): 200, y el payload muestra que corre el fallback.
     *
     * @group admin-sync
     * @test
     */
    public function se_acepta_una_opcion_de_un_proveedor_sin_clave_y_se_ve_el_fallback()
    {
        config(['services.deepseek.api_key' => null]);

        $this->comercio->ia_modelo_whatsapp = 'claude_opus';
        $this->comercio->save();

        $this->putJson('api/admin-sync/modelos-ia', ['whatsapp' => 'deepseek_flash', 'asistente' => 'deepseek_pro'], $this->headers())
             ->assertStatus(200)
             ->assertJsonPath('tareas.whatsapp.opcion', 'deepseek_flash')
             ->assertJsonPath('tareas.whatsapp.efectiva.proveedor', 'anthropic')
             ->assertJsonPath('tareas.whatsapp.efectiva.fallback', true)
             ->assertJsonPath('tareas.asistente.opcion', 'deepseek_pro');

        $this->assertSame('deepseek_flash', (string) $this->comercio->fresh()->ia_modelo_whatsapp);
        $this->assertSame('deepseek', (string) $this->comercio->fresh()->agente_proveedor);
    }

    /**
     * "Gana el último" (decisión 2 de Lucas): el admin y el modal del dueño escriben las mismas
     * columnas del asistente, así que lo que muestra el GET es lo último que escribió cualquiera.
     *
     * @group admin-sync
     * @test
     */
    public function el_asistente_gana_el_ultimo_entre_el_admin_y_el_modal_del_dueno()
    {
        $extencion = ExtencionEmpresa::where('slug', 'asistente_ia')->first();

        if (! $extencion) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => 'asistente_ia', 'name' => 'Asistente IA']);
        }

        $this->comercio->extencions()->attach($extencion->id);

        /* 1. El admin elige Claude Opus. */
        $this->putJson('api/admin-sync/modelos-ia', ['asistente' => 'claude_opus'], $this->headers())->assertStatus(200);

        /* 2. El dueño, desde su modal, pasa a DeepSeek Ágil. */
        $this->actingAs($this->comercio, 'sanctum')
             ->putJson('api/user/asistente-config', ['confianza' => 'directo', 'pensamiento' => 'agil', 'proveedor' => 'deepseek'])
             ->assertStatus(200);

        $this->getJson('api/admin-sync/modelos-ia', $this->headers())->assertJsonPath('tareas.asistente.opcion', 'deepseek_flash');

        /* 3. El admin vuelve a elegir: gana otra vez, y el modal del dueño lo ve. */
        $this->putJson('api/admin-sync/modelos-ia', ['asistente' => 'claude_sonnet'], $this->headers())->assertStatus(200);

        $this->actingAs($this->comercio, 'sanctum')
             ->getJson('api/user/asistente-config')
             ->assertStatus(200)
             ->assertJsonPath('proveedor', 'anthropic')
             ->assertJsonPath('pensamiento', 'equilibrado');
    }
}
