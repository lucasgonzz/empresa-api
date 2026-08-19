<?php

namespace Tests\Feature\AiExcelImport;

use App\Jobs\RunExcelAnalysisJob;
use App\Models\ExcelAnalysisRun;
use App\Models\User;
use App\Notifications\GlobalNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\EmpresaTestCase;

/**
 * El análisis de un Excel con IA corre en segundo plano y le avisa al usuario
 * cuando termina, en vez de tenerlo esperando frente al modal.
 *
 * Lo que se cubre acá es el mecanismo del aviso y de la recuperación, que es
 * donde está el riesgo: el análisis en sí (los recorridos del archivo y la
 * llamada a Claude) ya existía y no cambió.
 */
class AnalisisEnSegundoPlanoTest extends EmpresaTestCase
{
    /**
     * Crea una corrida de análisis para el usuario autenticado.
     *
     * @param  array  $overrides  Campos a pisar (estado, visto_at, auth_user_id, etc.)
     * @return \App\Models\ExcelAnalysisRun
     */
    protected function corrida(array $overrides = [])
    {
        $auth_user = auth()->user();

        return ExcelAnalysisRun::create(array_merge([
            'uuid'         => Str::uuid()->toString(),
            'user_id'      => $auth_user->user_id ?? $auth_user->id,
            'auth_user_id' => $auth_user->id,
            'tipo'         => 'analisis',
            'estado'       => 'pendiente',
            /* Ruta inexistente a propósito: hace que el job termine por el camino de error. */
            'excel_path'   => 'imported_files/no_existe_' . Str::random(8) . '.xlsx',
            'payload'      => [
                'model'             => 'article',
                'original_filename' => 'Lista_Proveedor.xlsx',
                'start_row'         => 3,
                'finish_row'        => 1840,
                'has_header_row'    => 1,
            ],
        ], $overrides));
    }

    /**
     * Cuando la corrida termina, el usuario que subió el Excel recibe el aviso.
     *
     * Es la pieza que permite que el modal deje de ser una sala de espera: sin
     * este aviso, cerrar el modal significaba no enterarse nunca del resultado.
     *
     * @test
     * @return void
     */
    public function avisa_al_usuario_cuando_la_corrida_termina()
    {
        Notification::fake();

        $run = $this->corrida();

        (new RunExcelAnalysisJob($run->id))->handle();

        $run->refresh();

        $this->assertEquals('error', $run->estado, 'La corrida tendria que haber terminado en error (el archivo no existe).');

        $owner = User::find($run->user_id);

        Notification::assertSentTo(
            $owner,
            GlobalNotification::class,
            function ($notification) use ($run) {
                /* El aviso tiene que ser del modal nuevo, no del genérico. */
                $this->assertEquals('excel_analysis_ready', $notification->notification_modal);

                /* Dirigido solo a quien subió el archivo, no a todo el comercio. */
                $this->assertEquals($run->auth_user_id, $notification->is_only_for_auth_user);

                /*
                 * El aviso lleva el uuid (para poder ir a buscar el resultado) y el
                 * nombre del archivo (para poder decir de qué habla), y NADA del
                 * resumen: el resumen se pide recién si el usuario aprieta el botón.
                 */
                $this->assertEquals($run->uuid, $notification->excel_analysis['uuid']);
                $this->assertEquals('Lista_Proveedor.xlsx', $notification->excel_analysis['original_filename']);
                $this->assertEquals('article', $notification->excel_analysis['model']);
                $this->assertArrayNotHasKey('resultado', $notification->excel_analysis);

                return true;
            }
        );
    }

    /**
     * Una corrida sin auth_user_id no dispara ningún aviso.
     *
     * Son las corridas encoladas antes de este cambio. El canal de
     * global_notification es del comercio entero: avisar sin saber a quién sería
     * interrumpir a todos los empleados por un archivo que subió uno solo.
     *
     * @test
     * @return void
     */
    public function no_avisa_si_no_sabe_quien_subio_el_archivo()
    {
        Notification::fake();

        $run = $this->corrida(['auth_user_id' => null]);

        (new RunExcelAnalysisJob($run->id))->handle();

        $run->refresh();

        $this->assertEquals('error', $run->estado);

        Notification::assertNothingSent();
    }

    /**
     * La corrida terminada sigue disponible después de cerrar la pestaña, y deja
     * de ofrecerse una vez que el usuario la vio.
     *
     * Es la red de seguridad del broadcast: un evento emitido mientras el usuario
     * no estaba conectado no se reenvía cuando vuelve.
     *
     * @test
     * @return void
     */
    public function recupera_la_corrida_que_quedo_sin_ver_y_deja_de_ofrecerla_al_verla()
    {
        $run = $this->corrida(['estado' => 'listo', 'progreso' => 100]);

        $response = $this->getJson('api/ai-excel-import/analysis-en-curso');

        $response->assertStatus(200);
        $this->assertEquals($run->uuid, $response->json('run.uuid'));
        $this->assertEquals('listo', $response->json('run.estado'));

        /* El contexto es lo que permite rearmar el modal sin el archivo local. */
        $this->assertEquals('Lista_Proveedor.xlsx', $response->json('run.contexto.original_filename'));
        $this->assertEquals(3, $response->json('run.contexto.start_row'));
        $this->assertEquals(1840, $response->json('run.contexto.finish_row'));

        $this->postJson('api/ai-excel-import/analysis/' . $run->uuid . '/visto')->assertStatus(200);

        $run->refresh();
        $this->assertNotNull($run->visto_at, 'La corrida tendria que haber quedado marcada como vista.');

        /* Ya vista, no se vuelve a ofrecer en la proxima carga de la SPA. */
        $this->getJson('api/ai-excel-import/analysis-en-curso')
            ->assertStatus(200)
            ->assertJson(['run' => null]);
    }

    /**
     * Una recomendación hereda de su análisis padre el nombre del archivo y el
     * módulo.
     *
     * Es lo que permite que el aviso de la recomendación diga de qué archivo
     * habla: la corrida de recomendación no lo sabe por sí misma, porque es el
     * segundo tramo del mismo flujo.
     *
     * @test
     * @return void
     */
    public function la_recomendacion_hereda_el_archivo_del_analisis_del_que_salio()
    {
        $analisis = $this->corrida(['estado' => 'listo']);

        $recomendacion = $this->corrida([
            'tipo'    => 'recomendacion',
            'estado'  => 'listo',
            'payload' => [
                'analysis_uuid'              => $analisis->uuid,
                'provider_id'                => 7,
                'provider_code_column_index' => 2,
                'column_mapping'             => [],
            ],
        ]);

        $contexto = $recomendacion->contexto_para_frontend();

        $this->assertEquals('Lista_Proveedor.xlsx', $contexto['original_filename']);
        $this->assertEquals('article', $contexto['model']);
        /* Y conserva lo que el usuario habia confirmado a mano en el paso 2. */
        $this->assertEquals($analisis->uuid, $contexto['analysis_uuid']);
        $this->assertEquals(7, $contexto['provider_id']);
        $this->assertEquals(2, $contexto['provider_code_column_index']);
    }
}
