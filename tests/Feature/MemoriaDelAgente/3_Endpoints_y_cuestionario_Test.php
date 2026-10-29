<?php

namespace Tests\Feature\MemoriaDelAgente;

use App\Http\Controllers\Helpers\asistente_ia\AgenteContextoHelper;
use App\Models\AgenteContextoArchivo;
use App\Models\AgenteContextoRevision;
use App\Models\User;

/**
 * Misión app-ia-y-memoria (E2) — lo que ve la app (contrato C5) y lo que llama el admin (C1.3).
 *
 * Protege: GET/PUT api/agente-contexto con la tenencia por dueño y el gate del chat; el cuestionario
 * de inicio (escribe los archivos y las secciones del maestro de forma determinista, guarda la
 * confianza en la MISMA columna que PUT user/asistente-config, marca completado y guarda las
 * respuestas; mandado dos veces no sube versiones); saltear; y el admin-sync de inicialización con la
 * clave estricta.
 */
class Endpoints_y_cuestionario_Test extends MemoriaTestCase
{
    /** Una respuesta completa y válida del cuestionario. */
    protected function respuestas(array $cambios = [])
    {
        return array_merge([
            'negocio_frase'   => 'Pinturería de barrio con venta al por mayor a pintores.',
            'objetivos'       => ['vender más', 'cobrar a tiempo', 'vender más'],
            'objetivos_texto' => 'Quiero abrir una segunda sucursal el año que viene.',
            'areas'           => ['stock', 'clientes y cobranzas'],
            'importa_hoy'     => 'Que los pintores me paguen antes de fin de mes.',
            'largo'           => 'breve',
            'forma'           => 'graficos',
            'confianza'       => 'cauteloso',
            'extra'           => 'Abro de lunes a sábado de 8 a 13.',
        ], $cambios);
    }

    /** @test */
    public function get_devuelve_la_memoria_del_dueno_y_la_crea_si_faltaba()
    {
        $r = $this->actingAs($this->dueno, 'sanctum')->getJson('api/agente-contexto');

        $r->assertStatus(200)->assertJsonStructure(['archivos' => [['ruta', 'titulo', 'resumen', 'contenido', 'version', 'origen', 'actualizado_en']]]);

        $archivos = $r->json('archivos');

        $this->assertCount(16, $archivos);
        $this->assertSame('contexto/contexto_maestro.md', $archivos[0]['ruta'], 'El maestro primero, en el orden de la plantilla.');
        $this->assertSame('contexto/INDICE.md', $archivos[1]['ruta']);
    }

    /** @test */
    public function put_reemplaza_con_origen_dueno_y_deja_la_revision()
    {
        $r = $this->actingAs($this->dueno, 'sanctum')->putJson('api/agente-contexto', [
            'ruta'      => 'dueno/perfil.md',
            'contenido' => "# Quién es el dueño\n\nSe llama Raúl. Decide todo él.",
        ]);

        $r->assertStatus(200)
          ->assertJsonPath('archivo.ruta', 'dueno/perfil.md')
          ->assertJsonPath('archivo.origen', 'dueno')
          ->assertJsonPath('archivo.version', 2);

        $archivo = AgenteContextoArchivo::where('user_id', $this->dueno->id)->where('ruta', 'dueno/perfil.md')->first();

        $this->assertStringContainsString('Se llama Raúl', $archivo->contenido);
        $this->assertSame('dueno', AgenteContextoRevision::where('archivo_id', $archivo->id)->where('version', 2)->value('origen'));
    }

    /** @test */
    public function put_rechaza_el_indice_una_ruta_mala_y_lo_que_pasa_el_tope()
    {
        $this->actingAs($this->dueno, 'sanctum')
             ->putJson('api/agente-contexto', ['ruta' => 'contexto/INDICE.md', 'contenido' => 'x'])
             ->assertStatus(422)->assertJsonPath('code', 'archivo_generado');

        $this->actingAs($this->dueno, 'sanctum')
             ->putJson('api/agente-contexto', ['ruta' => '../x.md', 'contenido' => 'x'])
             ->assertStatus(422)->assertJsonPath('code', 'ruta_invalida');

        $this->actingAs($this->dueno, 'sanctum')
             ->putJson('api/agente-contexto', ['ruta' => 'contexto/contexto_maestro.md', 'contenido' => str_repeat('a', AgenteContextoHelper::TOPE_MAESTRO + 10)])
             ->assertStatus(422)->assertJsonPath('code', 'contexto_muy_largo');

        $this->actingAs($this->dueno, 'sanctum')
             ->putJson('api/agente-contexto', ['contenido' => 'x'])
             ->assertStatus(422)->assertJsonPath('code', 'validacion')->assertJsonStructure(['code', 'message']);
    }

    /** @test */
    public function cada_duenio_ve_solo_su_memoria_y_el_empleado_comun_no_entra()
    {
        $otro = $this->nuevo_dueno('Otro comercio', true);

        AgenteContextoHelper::guardar($otro, 'areas/stock.md', 'agregar', '- Secreto del otro comercio.', null, 'm', 'agente');

        $r = $this->actingAs($this->dueno, 'sanctum')->getJson('api/agente-contexto');

        $r->assertStatus(200);
        $this->assertStringNotContainsString('Secreto del otro comercio', $r->getContent());

        $this->actingAs($this->dueno, 'sanctum')->putJson('api/agente-contexto', ['ruta' => 'areas/stock.md', 'contenido' => 'Mío'])->assertStatus(200);

        $this->assertStringContainsString('Secreto del otro comercio', AgenteContextoHelper::leer($otro, 'areas/stock.md')['contenido'], 'El PUT de uno no toca la memoria del otro.');

        // Un empleado sin admin_access no pasa el gate del chat.
        $this->actingAs($this->empleado(0), 'sanctum')->getJson('api/agente-contexto')->assertStatus(403);

        // Uno con admin_access entra, y ve la memoria del DUEÑO.
        $admin = $this->empleado(1);
        $this->actingAs($admin, 'sanctum')->getJson('api/agente-contexto')->assertStatus(200);
        $this->assertSame(0, AgenteContextoArchivo::where('user_id', $admin->id)->count());

        // Sin la extensión, el gate corta.
        $sin = $this->nuevo_dueno('Sin extensión', false);
        $this->actingAs($sin, 'sanctum')->getJson('api/agente-contexto')->assertStatus(403);
    }

    /** @test */
    public function el_cuestionario_escribe_la_memoria_la_confianza_y_lo_marca()
    {
        $this->dueno->agente_confianza = 'directo';
        $this->dueno->save();

        $r = $this->actingAs($this->dueno, 'sanctum')->postJson('api/ia-app/cuestionario', $this->respuestas());

        $r->assertStatus(200)
          ->assertJsonPath('completado', true)
          ->assertJsonPath('salteado', false)
          ->assertJsonPath('respuestas.largo', 'breve')
          ->assertJsonPath('respuestas.objetivos', ['vender más', 'cobrar a tiempo']);

        $this->assertNotNull($r->json('completado_en'));
        $this->assertEmpty($r->json('avisos'));

        $dueno = $this->dueno->fresh();

        $this->assertSame('cauteloso', (string) $dueno->agente_confianza, 'La confianza va a la misma columna que PUT user/asistente-config.');
        $this->assertNotNull($dueno->ia_cuestionario_completado_at);
        $this->assertSame('Pinturería de barrio con venta al por mayor a pintores.', json_decode($dueno->ia_cuestionario_respuestas, true)['negocio_frase']);

        // Lo ve también la config del asistente, por su propio camino.
        $this->actingAs($this->dueno, 'sanctum')->getJson('api/user/asistente-config')->assertStatus(200)->assertJsonPath('confianza', 'cauteloso');

        $como = AgenteContextoHelper::leer($this->dueno, 'dueno/como_responder.md');
        $this->assertStringContainsString('Breve', $como['contenido']);
        $this->assertStringContainsString('gráficos', $como['contenido']);
        $this->assertSame('cuestionario', $como['origen']);

        $objetivos = AgenteContextoHelper::leer($this->dueno, 'negocio/objetivos.md')['contenido'];
        $this->assertStringContainsString("- Vender más\n- Cobrar a tiempo", $objetivos, 'Sin repetidos y con mayúscula.');
        $this->assertStringContainsString('segunda sucursal', $objetivos);

        $prioridades = AgenteContextoHelper::leer($this->dueno, 'negocio/prioridades.md')['contenido'];
        $this->assertStringContainsString('- Clientes y cobranzas', $prioridades);
        $this->assertStringContainsString('fin de mes', $prioridades);

        $this->assertStringContainsString('Pinturería de barrio', AgenteContextoHelper::leer($this->dueno, 'negocio/resumen.md')['contenido']);
        $this->assertStringContainsString("## Lo que contó al empezar\n\nAbro de lunes a sábado", AgenteContextoHelper::leer($this->dueno, 'dueno/perfil.md')['contenido']);

        $maestro = AgenteContextoHelper::leer($this->dueno, 'contexto/contexto_maestro.md')['contenido'];
        $this->assertStringContainsString("## El negocio\n\nPinturería de barrio", $maestro);
        $this->assertStringContainsString("## Cómo quiere que le respondan\n\n- Largo: Breve", $maestro);
        $this->assertStringContainsString('fin de mes', $maestro);
        $this->assertStringContainsString('## Dónde buscar cada cosa', $maestro, 'Las secciones que el cuestionario no toca quedan.');
        $this->assertStringNotContainsString('cauteloso', $maestro, 'La confianza no se copia a la memoria: su fuente es la columna.');

        // Y todo eso entra al prompt del agente.
        $this->assertStringContainsString('Pinturería de barrio', AgenteContextoHelper::bloque_para_prompt($this->dueno));

        // GET devuelve lo mismo.
        $this->actingAs($this->dueno, 'sanctum')->getJson('api/ia-app/cuestionario')
             ->assertStatus(200)
             ->assertJsonPath('completado', true)
             ->assertJsonPath('respuestas.forma', 'graficos');

        $this->assertTrue(AgenteContextoHelper::estado_cuestionario($this->dueno->fresh())['completado']);
    }

    /** @test */
    public function el_cuestionario_mandado_dos_veces_no_sube_versiones()
    {
        $this->actingAs($this->dueno, 'sanctum')->postJson('api/ia-app/cuestionario', $this->respuestas())->assertStatus(200);

        $versiones = AgenteContextoArchivo::where('user_id', $this->dueno->id)->pluck('version', 'ruta')->all();
        $revisiones = AgenteContextoRevision::count();

        $r = $this->actingAs($this->dueno, 'sanctum')->postJson('api/ia-app/cuestionario', $this->respuestas());

        $r->assertStatus(200);
        $this->assertSame([], $r->json('escritos'), 'Nada cambió: nada se escribió.');
        $this->assertSame($versiones, AgenteContextoArchivo::where('user_id', $this->dueno->id)->pluck('version', 'ruta')->all());
        $this->assertSame($revisiones, AgenteContextoRevision::count());
    }

    /** @test */
    public function el_cuestionario_valida_con_code()
    {
        $this->actingAs($this->dueno, 'sanctum')
             ->postJson('api/ia-app/cuestionario', $this->respuestas(['largo' => 'larguísimo']))
             ->assertStatus(422)->assertJsonPath('code', 'validacion');

        $this->actingAs($this->dueno, 'sanctum')
             ->postJson('api/ia-app/cuestionario', $this->respuestas(['objetivos' => array_fill(0, 9, 'x')]))
             ->assertStatus(422);

        $this->actingAs($this->dueno, 'sanctum')
             ->postJson('api/ia-app/cuestionario', $this->respuestas(['confianza' => 'suelto']))
             ->assertStatus(422);

        // Lo mínimo: largo, forma y confianza.
        $this->actingAs($this->dueno, 'sanctum')
             ->postJson('api/ia-app/cuestionario', ['largo' => 'explayado', 'forma' => 'numeros', 'confianza' => 'resuelto'])
             ->assertStatus(200)->assertJsonPath('completado', true);

        $this->assertStringContainsString('(todavía vacío)', AgenteContextoHelper::leer($this->dueno, 'negocio/objetivos.md')['contenido'], 'Lo que no se contestó no pisa nada.');
        $this->assertSame('resuelto', (string) $this->dueno->fresh()->agente_confianza);
    }

    /** @test */
    public function saltear_devuelve_204_y_es_idempotente()
    {
        $this->actingAs($this->dueno, 'sanctum')->postJson('api/ia-app/cuestionario/saltear')->assertStatus(204);

        $primera = $this->dueno->fresh()->ia_cuestionario_salteado_at;

        $this->assertNotNull($primera);

        $this->actingAs($this->dueno, 'sanctum')->postJson('api/ia-app/cuestionario/saltear')->assertStatus(204);
        $this->assertSame((string) $primera, (string) $this->dueno->fresh()->ia_cuestionario_salteado_at);

        $this->actingAs($this->dueno, 'sanctum')->getJson('api/ia-app/cuestionario')
             ->assertStatus(200)
             ->assertJsonPath('completado', false)
             ->assertJsonPath('salteado', true)
             ->assertJsonPath('respuestas', null);

        // Completarlo después lo deja completado, no salteado.
        $this->actingAs($this->dueno, 'sanctum')->postJson('api/ia-app/cuestionario', $this->respuestas())->assertStatus(200);

        $estado = AgenteContextoHelper::estado_cuestionario($this->dueno->fresh());
        $this->assertTrue($estado['completado']);
        $this->assertFalse($estado['salteado']);
    }

    /** @test */
    public function un_empleado_comun_no_llega_al_cuestionario()
    {
        $this->actingAs($this->empleado(0), 'sanctum')->postJson('api/ia-app/cuestionario', $this->respuestas())->assertStatus(403);
        $this->assertNull($this->dueno->fresh()->ia_cuestionario_completado_at);
    }

    /**
     * 🔴 El agente no puede tocar su memoria ni su cuestionario por una acción de pantalla: el catálogo
     * genérico toma solo toda ruta `api/` con auth:sanctum, y el POST del cuestionario cambia el modo de
     * confianza (lo mismo que ya excluía user/asistente-config).
     *
     * @test
     */
    public function el_agente_no_llega_a_estas_rutas_por_una_accion_de_pantalla()
    {
        $catalogo = \App\Http\Controllers\Helpers\asistente_ia\CatalogoDeAccionesDePantallaIaHelper::class;

        $catalogo::olvidar();

        foreach ([['GET', 'api/agente-contexto'], ['PUT', 'api/agente-contexto'], ['GET', 'api/ia-app/cuestionario'],
                  ['POST', 'api/ia-app/cuestionario'], ['POST', 'api/ia-app/cuestionario/saltear']] as $ruta) {

            $this->assertNull($catalogo::declaracion($ruta[0], $ruta[1]), $ruta[0] . ' ' . $ruta[1] . ' no puede ofrecerse como acción de pantalla.');
            $motivo = (string) $catalogo::motivo_de_exclusion($ruta[0], $ruta[1]);

            $this->assertStringContainsString(
                strpos($ruta[1], 'agente-contexto') !== false ? 'memoria del negocio' : 'cuestionario de inicio',
                $motivo,
                $ruta[0] . ' ' . $ruta[1] . ': la excluye su propia regla, con su motivo.'
            );
        }

        foreach ($catalogo::todas() as $accion) {
            $this->assertStringNotContainsString('agente-contexto', json_encode($accion));
            $this->assertStringNotContainsString('ia-app/', json_encode($accion));
        }
    }

    /** @test */
    public function el_admin_sync_inicializa_con_la_clave_estricta()
    {
        config([
            'services.admin_api.api_key'          => 'clave-admin-de-prueba',
            'services.admin_api.require_api_key'  => false,
            'app.USER_ID'                         => $this->dueno->id,
        ]);

        // Sin clave: aunque el middleware del grupo deje pasar (flag apagado), el controlador corta.
        $this->putJson('api/admin-sync/agente-contexto/inicializar')
             ->assertStatus(401)->assertJsonPath('code', 'clave_invalida');

        $this->putJson('api/admin-sync/agente-contexto/inicializar', [], ['X-Admin-Api-Key' => 'otra'])
             ->assertStatus(401)->assertJsonPath('code', 'clave_invalida');

        $this->assertSame(0, AgenteContextoArchivo::where('user_id', $this->dueno->id)->count());

        $primera = $this->putJson('api/admin-sync/agente-contexto/inicializar', [], ['X-Admin-Api-Key' => 'clave-admin-de-prueba']);

        $primera->assertStatus(200)->assertExactJson(['creada' => true, 'archivos' => 16]);

        $this->putJson('api/admin-sync/agente-contexto/inicializar', [], ['X-Admin-Api-Key' => 'clave-admin-de-prueba'])
             ->assertStatus(200)->assertExactJson(['creada' => false, 'archivos' => 16]);
    }

    /** @test */
    public function el_admin_sync_sin_la_extension_contesta_409()
    {
        $sin = $this->nuevo_dueno('Sin extensión admin', false);

        config([
            'services.admin_api.api_key'         => 'clave-admin-de-prueba',
            'services.admin_api.require_api_key' => false,
            'app.USER_ID'                        => $sin->id,
        ]);

        $this->putJson('api/admin-sync/agente-contexto/inicializar', [], ['X-Admin-Api-Key' => 'clave-admin-de-prueba'])
             ->assertStatus(409)->assertJsonPath('code', 'sin_extension');

        $this->assertSame(0, AgenteContextoArchivo::where('user_id', $sin->id)->count());
    }
}
