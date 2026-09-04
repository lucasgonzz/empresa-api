<?php

namespace Tests\Feature\Impresion;

use App\Models\PrintAgent;
use App\Models\PrintJob;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Feature tests del agente de impresion, el reemplazo de QZ Tray.
 *
 * Lo que protege es todo lo que puede fallar EN SILENCIO y terminar en un ticket que sale dos
 * veces, uno que no sale, o uno que sale en la caja de otro comercio:
 *
 * - que el codigo de vinculacion sea de UN SOLO USO y que venza;
 * - que un sondeo repetido no se lleve dos veces el mismo trabajo;
 * - que un equipo no pueda encolar ni cerrar los trabajos de otro comercio;
 * - que sin token no se pueda hacer nada.
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing esta sembrada de antes y un
 * refresh la vaciaria, rompiendo el resto de las suites.
 */
class Agente_De_Impresion_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return \App\Models\User|null
     */
    protected function usuario_de_testing()
    {
        return User::find(500);
    }

    /**
     * Abre el codigo pegable igual que lo hace el agente: prefijo + base64url de un json.
     *
     * @param string $codigo_pegable
     * @return array
     */
    protected function abrir_codigo($codigo_pegable)
    {
        $crudo = substr($codigo_pegable, strlen('CC1.'));

        return json_decode(base64_decode(strtr($crudo, '-_', '+/')), true);
    }

    /**
     * Vincula un equipo y devuelve [PrintAgent, token, codigo].
     *
     * @param \App\Models\User $user
     * @return array
     */
    protected function vincular_un_equipo($user)
    {
        $this->actingAs($user, 'web');

        $response = $this->postJson('api/print-agents/codigo');
        $response->assertStatus(200);

        $datos = $this->abrir_codigo($response->json('codigo'));

        $vinculacion = $this->postJson('api/print-agent/vincular', [
            'codigo'        => $datos['c'],
            'nombre_equipo' => 'CAJA-TESTING',
            'impresoras'    => ['XP-80', 'Microsoft Print to PDF'],
        ]);

        $vinculacion->assertStatus(200);

        $token = $vinculacion->json('token');

        $print_agent = PrintAgent::where('token_hash', hash('sha256', $token))->first();

        return [$print_agent, $token, $datos['c']];
    }

    /**
     * @group impresion
     * @test
     */
    public function el_codigo_de_vinculacion_lleva_adentro_la_url_de_la_api()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $response = $this->postJson('api/print-agents/codigo');

        $response->assertStatus(200);

        $codigo_pegable = $response->json('codigo');

        $this->assertStringStartsWith('CC1.', $codigo_pegable);

        $datos = $this->abrir_codigo($codigo_pegable);

        /*
         * La url va adentro del codigo justamente para que el cliente no tenga que tipear ninguna
         * direccion: si faltara, el agente no sabria con quien hablar.
         */
        $this->assertArrayHasKey('u', $datos);
        $this->assertArrayHasKey('c', $datos);
        $this->assertNotEmpty($datos['u']);
        $this->assertNotEmpty($datos['c']);
    }

    /**
     * @group impresion
     * @test
     */
    public function el_agente_se_vincula_y_queda_con_sus_impresoras()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        list($print_agent, $token) = $this->vincular_un_equipo($user);

        $this->assertNotNull($print_agent);
        $this->assertNotEmpty($token);
        $this->assertEquals('CAJA-TESTING', $print_agent->nombre_equipo);
        $this->assertEquals(['XP-80', 'Microsoft Print to PDF'], $print_agent->impresoras_array);
        $this->assertNotNull($print_agent->vinculado_at);

        /* El codigo se consume al canjearlo. */
        $this->assertNull($print_agent->link_code_hash);
    }

    /**
     * El codigo es de un solo uso: si se pudiera reusar, cualquiera que lo viera de reojo en la
     * pantalla podria vincular su propia maquina y quedarse con los tickets del comercio.
     *
     * @group impresion
     * @test
     */
    public function el_codigo_no_se_puede_usar_dos_veces()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        list($print_agent, $token, $codigo) = $this->vincular_un_equipo($user);

        $segundo_intento = $this->postJson('api/print-agent/vincular', [
            'codigo'        => $codigo,
            'nombre_equipo' => 'CAJA-INTRUSA',
            'impresoras'    => ['XP-80'],
        ]);

        $segundo_intento->assertStatus(404);
    }

    /**
     * @group impresion
     * @test
     */
    public function un_codigo_vencido_no_vincula()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $response = $this->postJson('api/print-agents/codigo');
        $datos = $this->abrir_codigo($response->json('codigo'));

        /* Se lo vence a mano en vez de esperar media hora. */
        PrintAgent::where('link_code_hash', hash('sha256', $datos['c']))
            ->update(['link_code_expira_at' => Carbon::now()->subMinute()]);

        $vinculacion = $this->postJson('api/print-agent/vincular', [
            'codigo'     => $datos['c'],
            'impresoras' => ['XP-80'],
        ]);

        $vinculacion->assertStatus(410);
    }

    /**
     * @group impresion
     * @test
     */
    public function sin_token_el_agente_no_puede_hacer_nada()
    {
        $this->getJson('api/print-agent/jobs')->assertStatus(401);

        $this->withHeaders(['X-Print-Agent-Token' => 'un-token-inventado'])
            ->getJson('api/print-agent/jobs')
            ->assertStatus(401);
    }

    /**
     * El test que mas importa: un ticket se entrega UNA sola vez.
     *
     * El agente sondea cada dos segundos, asi que dos sondeos se pisan solos apenas hay un poco de
     * latencia. Si el trabajo siguiera en pendiente despues de entregarlo, el ticket saldria dos
     * veces por la comandera.
     *
     * @group impresion
     * @test
     */
    public function un_trabajo_se_entrega_una_sola_vez()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        list($print_agent, $token) = $this->vincular_un_equipo($user);

        $this->actingAs($user, 'web');

        $creacion = $this->postJson('api/print-jobs', [
            'print_agent_id' => $print_agent->id,
            'printer_name'   => 'XP-80',
            'payload_base64' => base64_encode("hola comandera\n"),
        ]);

        $creacion->assertStatus(201);

        $primer_sondeo = $this->withHeaders(['X-Print-Agent-Token' => $token])
            ->getJson('api/print-agent/jobs');

        $primer_sondeo->assertStatus(200);
        $this->assertCount(1, $primer_sondeo->json('jobs'));
        $this->assertEquals('XP-80', $primer_sondeo->json('jobs.0.printer_name'));

        $segundo_sondeo = $this->withHeaders(['X-Print-Agent-Token' => $token])
            ->getJson('api/print-agent/jobs');

        $segundo_sondeo->assertStatus(200);
        $this->assertCount(0, $segundo_sondeo->json('jobs'));
    }

    /**
     * @group impresion
     * @test
     */
    public function el_agente_informa_como_le_fue_y_queda_registrado()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        list($print_agent, $token) = $this->vincular_un_equipo($user);

        $this->actingAs($user, 'web');

        $creacion = $this->postJson('api/print-jobs', [
            'print_agent_id' => $print_agent->id,
            'printer_name'   => 'XP-80',
            'payload_base64' => base64_encode("hola\n"),
        ]);

        $job_id = $creacion->json('model.id');

        $this->withHeaders(['X-Print-Agent-Token' => $token])->getJson('api/print-agent/jobs');

        $resultado = $this->withHeaders(['X-Print-Agent-Token' => $token])
            ->postJson('api/print-agent/jobs/' . $job_id . '/resultado', [
                'status' => 'error',
                'error'  => 'La impresora esta sin papel',
            ]);

        $resultado->assertStatus(200);

        $job = PrintJob::find($job_id);

        $this->assertEquals(PrintJob::STATUS_ERROR, $job->status);
        $this->assertEquals('La impresora esta sin papel', $job->error);
        $this->assertNotNull($job->terminado_at);
    }

    /**
     * Sin este filtro, mandar un id cualquiera imprimiria un ticket en la caja de otro comercio.
     *
     * @group impresion
     * @test
     */
    public function no_se_puede_encolar_en_el_equipo_de_otro_comercio()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        list($print_agent, $token) = $this->vincular_un_equipo($user);

        /* El equipo pasa a ser de otro comercio. */
        $print_agent->owner_id = $print_agent->owner_id + 99999;
        $print_agent->save();

        $this->actingAs($user, 'web');

        $creacion = $this->postJson('api/print-jobs', [
            'print_agent_id' => $print_agent->id,
            'printer_name'   => 'XP-80',
            'payload_base64' => base64_encode("hola\n"),
        ]);

        $creacion->assertStatus(404);
    }

    /**
     * Un equipo apagado tiene que avisarse ANTES de encolar: si el trabajo se aceptara igual, el
     * operador no veria ningun error y el ticket no saldria hasta que alguien prenda esa maquina.
     *
     * @group impresion
     * @test
     */
    public function no_se_encola_en_un_equipo_desconectado()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        list($print_agent, $token) = $this->vincular_un_equipo($user);

        $print_agent->last_seen_at = Carbon::now()->subMinutes(5);
        $print_agent->save();

        $this->actingAs($user, 'web');

        $creacion = $this->postJson('api/print-jobs', [
            'print_agent_id' => $print_agent->id,
            'printer_name'   => 'XP-80',
            'payload_base64' => base64_encode("hola\n"),
        ]);

        $creacion->assertStatus(409);
    }

    /**
     * @group impresion
     * @test
     */
    public function el_heartbeat_actualiza_las_impresoras_pero_una_lista_ausente_no_las_borra()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        list($print_agent, $token) = $this->vincular_un_equipo($user);

        $this->withHeaders(['X-Print-Agent-Token' => $token])
            ->postJson('api/print-agent/heartbeat', ['impresoras' => ['SOLO-UNA']])
            ->assertStatus(200);

        $this->assertEquals(['SOLO-UNA'], $print_agent->fresh()->impresoras_array);

        /* Un heartbeat sin la lista no deja al equipo sin impresoras. */
        $this->withHeaders(['X-Print-Agent-Token' => $token])
            ->postJson('api/print-agent/heartbeat', [])
            ->assertStatus(200);

        $this->assertEquals(['SOLO-UNA'], $print_agent->fresh()->impresoras_array);
    }
}
