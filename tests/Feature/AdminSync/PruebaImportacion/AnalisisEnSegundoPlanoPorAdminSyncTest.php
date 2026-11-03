<?php

namespace Tests\Feature\AdminSync\PruebaImportacion;

use App\Jobs\RunExcelAnalysisJob;
use App\Models\ExcelAnalysisRun;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\EmpresaTestCase;

/**
 * `POST api/admin-sync/ai-excel-import/analyze` con `segundo_plano=1` y
 * `GET api/admin-sync/ai-excel-import/analysis/{uuid}` (misión cruzada prueba-martin-importacion,
 * 3/11/2026, contrato C4 del programa «Prueba gratuita de 7 días»).
 *
 * Lo que protege, en orden:
 *  1. Con `segundo_plano=1` NO se analiza en el request: queda una `ExcelAnalysisRun` DEL DUEÑO (user_id y
 *     auth_user_id), con `origen`, `avisar_por_whatsapp` y `provider_id` en el payload, y el job encolado.
 *     La respuesta es 202 con el uuid. Es lo que hace que el dueño encuentre la importación abierta al
 *     entrar al sistema, exactamente como si la hubiera subido él.
 *  2. La consulta exige `user_id` y la corrida tiene que ser suya: otro dueño no puede leer el resultado
 *     adivinando el uuid.
 *
 * @group admin-sync
 */
class AnalisisEnSegundoPlanoPorAdminSyncTest extends EmpresaTestCase
{
    const CLAVE = 'clave-de-admin-del-test-de-importacion-en-prueba';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.admin_api.api_key'         => self::CLAVE,
            'services.admin_api.require_api_key' => true,
        ]);

        Storage::fake('local');
    }

    /**
     * @return array<string, string>
     */
    protected function headers(): array
    {
        return ['X-Admin-Api-Key' => self::CLAVE, 'Accept' => 'application/json'];
    }

    /**
     * Un Excel falso válido para la validación de extensión.
     *
     * @return UploadedFile
     */
    protected function excel(): UploadedFile
    {
        return UploadedFile::fake()->create('Lista_Proveedor.xlsx', 12, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    /** @test */
    public function con_segundo_plano_encola_la_corrida_del_dueno_y_contesta_202()
    {
        Bus::fake();

        $dueno = auth()->user();

        $respuesta = $this->post('api/admin-sync/ai-excel-import/analyze', [
            'excel_file'          => $this->excel(),
            'user_id'             => $dueno->id,
            'model'               => 'article',
            'segundo_plano'       => '1',
            'avisar_por_whatsapp' => '1',
            'origen'              => 'martin',
            'provider_id'         => '7',
        ], $this->headers());

        $respuesta->assertStatus(202);

        $uuid = $respuesta->json('analysis_uuid');
        $this->assertNotEmpty($uuid);
        $this->assertSame('pendiente', $respuesta->json('estado'));
        $this->assertStringStartsWith('imported_files/', (string) $respuesta->json('excel_path'));

        $run = ExcelAnalysisRun::where('uuid', $uuid)->first();
        $this->assertNotNull($run, 'La corrida tiene que quedar en excel_analysis_runs.');
        $this->assertSame((int) $dueno->id, (int) $run->user_id);
        $this->assertSame((int) $dueno->id, (int) $run->auth_user_id, 'auth_user_id es el dueño: a él le llega excel_analysis_ready y la corrida abierta.');
        $this->assertSame('analisis', $run->tipo);
        $this->assertSame('article', $run->payload['model']);
        $this->assertSame('Lista_Proveedor.xlsx', $run->payload['original_filename']);
        $this->assertSame('martin', $run->payload['origen']);
        $this->assertTrue($run->payload['avisar_por_whatsapp']);
        $this->assertSame(7, $run->payload['provider_id']);
        $this->assertSame(0, $run->payload['hoja']);
        $this->assertNull($run->payload['header_row']);

        Bus::assertDispatched(RunExcelAnalysisJob::class);
    }

    /** @test */
    public function sin_avisar_por_whatsapp_el_payload_lo_dice_en_false()
    {
        Bus::fake();

        $dueno = auth()->user();

        $respuesta = $this->post('api/admin-sync/ai-excel-import/analyze', [
            'excel_file'    => $this->excel(),
            'user_id'       => $dueno->id,
            'model'         => 'article',
            'segundo_plano' => '1',
        ], $this->headers());

        $respuesta->assertStatus(202);

        $run = ExcelAnalysisRun::where('uuid', $respuesta->json('analysis_uuid'))->first();
        $this->assertFalse($run->payload['avisar_por_whatsapp']);
        $this->assertSame('admin', $run->payload['origen']);
        $this->assertNull($run->payload['provider_id']);
    }

    /** @test */
    public function la_consulta_devuelve_la_corrida_solo_a_su_dueno()
    {
        $dueno = auth()->user();

        $run = ExcelAnalysisRun::create([
            'uuid'         => Str::uuid()->toString(),
            'user_id'      => $dueno->id,
            'auth_user_id' => $dueno->id,
            'tipo'         => 'analisis',
            'estado'       => 'listo',
            'excel_path'   => 'imported_files/x.xlsx',
            'payload'      => ['model' => 'article', 'original_filename' => 'x.xlsx'],
            'resultado'    => ['column_mapping' => [], 'row_count' => 3],
            'progreso'     => 100,
        ]);

        $ok = $this->getJson('api/admin-sync/ai-excel-import/analysis/' . $run->uuid . '?user_id=' . $dueno->id, $this->headers());
        $ok->assertStatus(200);
        $this->assertSame($run->uuid, $ok->json('uuid'));
        $this->assertSame('listo', $ok->json('estado'));
        $this->assertSame(3, $ok->json('resultado.row_count'));
        $this->assertSame('article', $ok->json('contexto.model'));

        $this->getJson('api/admin-sync/ai-excel-import/analysis/' . $run->uuid . '?user_id=' . ($dueno->id + 999999), $this->headers())
            ->assertStatus(422);

        $this->getJson('api/admin-sync/ai-excel-import/analysis/' . $run->uuid, $this->headers())
            ->assertStatus(422);

        $this->getJson('api/admin-sync/ai-excel-import/analysis/' . Str::uuid()->toString() . '?user_id=' . $dueno->id, $this->headers())
            ->assertStatus(404);
    }

    /** @test */
    public function sin_la_clave_de_admin_contesta_401()
    {
        $dueno = auth()->user();

        $this->postJson('api/admin-sync/ai-excel-import/analyze', ['user_id' => $dueno->id, 'model' => 'article', 'segundo_plano' => '1'])
            ->assertStatus(401);
    }
}
