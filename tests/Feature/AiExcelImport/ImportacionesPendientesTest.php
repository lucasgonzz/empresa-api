<?php

namespace Tests\Feature\AiExcelImport;

use App\Models\ExcelAnalysisRun;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\EmpresaTestCase;

/**
 * Importaciones pendientes recuperables: una vez analizado el Excel, el usuario puede
 * cerrar el modal o la sesión y retomar desde donde quedó, sin volver a subir ni analizar.
 *
 * Lo que se cubre es el contrato nuevo (lista, borrador, descarte, finalización) y su
 * aislamiento por comercio y por usuario. El análisis en sí no cambió.
 *
 * Usa archivos ficticios en storage: sin Excel real ni datos reales.
 */
class ImportacionesPendientesTest extends EmpresaTestCase
{
    /** @var string[] Archivos de prueba a borrar al terminar. */
    protected $archivos = [];

    protected function tearDown(): void
    {
        foreach ($this->archivos as $ruta) {
            Storage::delete($ruta);
        }

        parent::tearDown();
    }

    /**
     * Crea una corrida del usuario autenticado con un archivo ficticio en disco.
     *
     * @param  array  $overrides  Campos a pisar.
     * @param  array  $payload    Claves a pisar dentro del payload.
     * @param  bool   $con_archivo  false para simular que el archivo ya no está.
     * @return \App\Models\ExcelAnalysisRun
     */
    protected function corrida(array $overrides = [], array $payload = [], $con_archivo = true)
    {
        $auth_user = auth()->user();
        $ruta      = 'imported_files/pendiente_test_' . Str::random(8) . '.xlsx';

        if ($con_archivo) {
            Storage::put($ruta, 'contenido ficticio');
            $this->archivos[] = $ruta;
        }

        return ExcelAnalysisRun::create(array_merge([
            'uuid'         => Str::uuid()->toString(),
            'user_id'      => $auth_user->user_id ?? $auth_user->id,
            'auth_user_id' => $auth_user->id,
            'tipo'         => 'analisis',
            'estado'       => 'listo',
            'progreso'     => 100,
            'excel_path'   => $ruta,
            'resultado'    => ['mapeo' => []],
            'payload'      => array_merge([
                'model'             => 'article',
                'original_filename' => 'Lista_Ficticia.xlsx',
                'start_row'         => 2,
                'finish_row'        => 50,
                'has_header_row'    => 1,
            ], $payload),
        ], $overrides));
    }

    /**
     * El análisis ya hecho aparece en la lista aunque el usuario lo haya visto, y
     * trae sólo metadatos (nunca el resultado ni la ruta del archivo).
     *
     * @test
     * @return void
     */
    public function lista_la_importacion_ya_vista_sin_exponer_resultado_ni_ruta()
    {
        $run = $this->corrida(['visto_at' => now()]);

        $response = $this->getJson('api/ai-excel-import/pendientes?model=article');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('pendientes'));
        $this->assertEquals($run->uuid, $response->json('pendientes.0.uuid'));
        $this->assertEquals('Lista_Ficticia.xlsx', $response->json('pendientes.0.original_filename'));
        $this->assertEquals(2, $response->json('pendientes.0.paso'));
        $this->assertNull($response->json('pendientes.0.resultado'));
        $this->assertNull($response->json('pendientes.0.excel_path'));
    }

    /**
     * Cada módulo ve sólo lo suyo: una importación de clientes no aparece en artículos.
     *
     * @test
     * @return void
     */
    public function no_mezcla_los_modulos()
    {
        $this->corrida([], ['model' => 'client']);

        $this->assertCount(0, $this->getJson('api/ai-excel-import/pendientes?model=article')->json('pendientes'));
        $this->assertCount(1, $this->getJson('api/ai-excel-import/pendientes?model=client')->json('pendientes'));
        $this->assertCount(0, $this->getJson('api/ai-excel-import/pendientes?model=cualquier_cosa')->json('pendientes'));
    }

    /**
     * Aislamiento: la corrida de otro usuario del mismo comercio y la de otro comercio
     * no se listan, y el borrador/descarte sobre ellas da 404.
     *
     * @test
     * @return void
     */
    public function no_ve_ni_toca_las_corridas_de_otro_usuario_ni_de_otro_comercio()
    {
        $auth_user = auth()->user();
        $owner_id  = $auth_user->user_id ?? $auth_user->id;

        $de_otro_empleado = $this->corrida(['auth_user_id' => $auth_user->id + 1000]);
        $de_otro_comercio = $this->corrida(['user_id' => $owner_id + 1000]);
        $sin_autor        = $this->corrida(['auth_user_id' => null]);

        $this->assertCount(0, $this->getJson('api/ai-excel-import/pendientes?model=article')->json('pendientes'));

        foreach ([$de_otro_empleado, $de_otro_comercio, $sin_autor] as $ajena) {
            $this->postJson('api/ai-excel-import/analysis/' . $ajena->uuid . '/borrador', ['borrador' => ['paso' => 2]])
                ->assertStatus(404);
            $this->postJson('api/ai-excel-import/analysis/' . $ajena->uuid . '/descartar')
                ->assertStatus(404);

            $this->assertArrayNotHasKey('borrador', $ajena->fresh()->payload);
            $this->assertArrayNotHasKey('finalizada_at', $ajena->fresh()->payload);
        }
    }

    /**
     * El borrador viaja de ida y vuelta: se guarda, vuelve en analysis/{uuid} y la
     * lista informa el paso en el que quedó.
     *
     * @test
     * @return void
     */
    public function el_borrador_se_guarda_y_se_recupera()
    {
        $run = $this->corrida();

        $borrador = [
            'paso'                 => 2,
            'selected_provider_id' => 7,
            'start_row'            => 5,
            'column_mapping'       => [['excel_column_index' => 0, 'system_property' => 'name']],
        ];

        $this->postJson('api/ai-excel-import/analysis/' . $run->uuid . '/borrador', ['borrador' => $borrador])
            ->assertStatus(204);

        $estado = $this->getJson('api/ai-excel-import/analysis/' . $run->uuid);
        $estado->assertStatus(200);
        $this->assertEquals(7, $estado->json('borrador.selected_provider_id'));
        $this->assertEquals(5, $estado->json('borrador.start_row'));
        $this->assertEquals('name', $estado->json('borrador.column_mapping.0.system_property'));

        /* El resultado del análisis y el resto del payload siguen intactos. */
        $this->assertEquals(['mapeo' => []], $run->fresh()->resultado);
        $this->assertEquals('Lista_Ficticia.xlsx', $run->fresh()->payload['original_filename']);
    }

    /**
     * Sólo se guardan las claves del modal: lo demás (rutas, flags de otra parte) se descarta.
     *
     * @test
     * @return void
     */
    public function el_borrador_descarta_claves_ajenas_al_modal()
    {
        $run = $this->corrida();

        $this->postJson('api/ai-excel-import/analysis/' . $run->uuid . '/borrador', ['borrador' => [
            'paso'          => 3,
            'start_row'     => 4,
            'excel_path'    => '../../.env',
            'finalizada_at' => 'x',
            'pdf_path'      => 'algo.pdf',
        ]])->assertStatus(204);

        $guardado = $run->fresh()->payload['borrador'];

        $this->assertEquals(['paso' => 3, 'start_row' => 4], $guardado);
        $this->assertArrayNotHasKey('finalizada_at', $run->fresh()->payload);
        $this->assertArrayNotHasKey('pdf_path', $run->fresh()->payload);
    }

    /**
     * Un borrador que supera el tope se rechaza y no pisa el anterior.
     *
     * @test
     * @return void
     */
    public function rechaza_un_borrador_demasiado_grande()
    {
        $run = $this->corrida();

        $this->postJson('api/ai-excel-import/analysis/' . $run->uuid . '/borrador', ['borrador' => ['paso' => 2]])
            ->assertStatus(204);

        $enorme = ['paso' => 3, 'column_mapping' => [str_repeat('x', ExcelAnalysisRun::BORRADOR_MAX_BYTES + 10)]];

        $this->postJson('api/ai-excel-import/analysis/' . $run->uuid . '/borrador', ['borrador' => $enorme])
            ->assertStatus(422);

        $this->assertEquals(['paso' => 2], $run->fresh()->payload['borrador']);
    }

    /**
     * Retomar en el paso en que quedó: la lista devuelve el paso guardado y, si hay una
     * recomendación terminada que salió de este análisis, su uuid (para no pedirla de nuevo).
     *
     * @test
     * @return void
     */
    public function informa_el_paso_y_la_recomendacion_lista()
    {
        $run = $this->corrida();

        $recomendacion = $this->corrida([], ['analysis_uuid' => $run->uuid], false);
        $recomendacion->update(['tipo' => 'recomendacion', 'excel_path' => $run->excel_path]);

        $otra_recomendacion = $this->corrida([], ['analysis_uuid' => Str::uuid()->toString()], false);
        $otra_recomendacion->update(['tipo' => 'recomendacion', 'excel_path' => $run->excel_path]);

        $this->postJson('api/ai-excel-import/analysis/' . $run->uuid . '/borrador', ['borrador' => ['paso' => 3]])
            ->assertStatus(204);

        $pendientes = $this->getJson('api/ai-excel-import/pendientes?model=article')->json('pendientes');

        /* La recomendación no es una importación aparte: sólo el análisis figura en la lista. */
        $this->assertCount(1, $pendientes);
        $this->assertEquals(3, $pendientes[0]['paso']);
        $this->assertEquals($recomendacion->uuid, $pendientes[0]['recomendacion_uuid']);
    }

    /**
     * Descartar saca la corrida de la lista y no se puede volver a tocar.
     *
     * @test
     * @return void
     */
    public function descartar_la_saca_de_la_lista()
    {
        $run = $this->corrida();

        $this->postJson('api/ai-excel-import/analysis/' . $run->uuid . '/descartar')->assertStatus(204);

        $this->assertCount(0, $this->getJson('api/ai-excel-import/pendientes?model=article')->json('pendientes'));
        $this->postJson('api/ai-excel-import/analysis/' . $run->uuid . '/borrador', ['borrador' => ['paso' => 2]])
            ->assertStatus(404);

        /* La fila y el archivo quedan: los limpia la limpieza de 2 días que ya existía. */
        $this->assertNotNull(ExcelAnalysisRun::where('uuid', $run->uuid)->first());
        $this->assertTrue(Storage::exists($run->excel_path));
    }

    /**
     * Al lanzar la importación (por el archivo) la corrida deja de ser pendiente, aunque
     * la SPA no avise nada. Las de otro comercio con el mismo nombre de archivo no se tocan.
     *
     * @test
     * @return void
     */
    public function marcar_finalizada_por_archivo_respeta_el_comercio()
    {
        $auth_user = auth()->user();
        $owner_id  = $auth_user->user_id ?? $auth_user->id;

        $propia = $this->corrida();
        $ajena  = $this->corrida(['user_id' => $owner_id + 1000, 'excel_path' => $propia->excel_path]);

        $this->assertEquals(1, ExcelAnalysisRun::marcar_finalizada_por_archivo($owner_id, $propia->excel_path));

        $this->assertNotEmpty($propia->fresh()->payload['finalizada_at']);
        $this->assertArrayNotHasKey('finalizada_at', $ajena->fresh()->payload);
        $this->assertCount(0, $this->getJson('api/ai-excel-import/pendientes?model=article')->json('pendientes'));
    }

    /**
     * No se ofrecen las corridas con error, las de más de 48 h ni las que perdieron su archivo.
     *
     * @test
     * @return void
     */
    public function no_ofrece_corridas_con_error_vencidas_ni_sin_archivo()
    {
        $this->corrida(['estado' => 'error']);
        $vencida = $this->corrida();
        $vencida->forceFill(['created_at' => now()->subHours(ExcelAnalysisRun::VENTANA_PENDIENTES_HORAS + 1)])->save();
        $this->corrida([], [], false);

        $this->assertCount(0, $this->getJson('api/ai-excel-import/pendientes?model=article')->json('pendientes'));
    }

    /**
     * Una corrida todavía trabajando se lista (el modal la sigue en vivo).
     *
     * @test
     * @return void
     */
    public function lista_la_corrida_que_todavia_esta_procesando()
    {
        $run = $this->corrida(['estado' => 'procesando', 'progreso' => 40, 'resultado' => null]);

        $pendientes = $this->getJson('api/ai-excel-import/pendientes?model=article')->json('pendientes');

        $this->assertCount(1, $pendientes);
        $this->assertEquals($run->uuid, $pendientes[0]['uuid']);
        $this->assertEquals('procesando', $pendientes[0]['estado']);
    }
}
