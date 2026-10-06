<?php

namespace Tests\Feature\AiExcelImport;

use App\Jobs\RunExcelAnalysisJob;
use App\Models\ExcelAnalysisRun;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\EmpresaTestCase;

/**
 * El paso 3 del importador con IA (la "Recomendación de Claude") es de artículos: habla de
 * códigos de barras, códigos de proveedor y formatos de costo. Para clientes y proveedores
 * corría igual, sus Excel no tienen esas columnas y Claude contestaba "el archivo contiene 0
 * filas de datos, no se importará ningún artículo" sobre un Excel de clientes perfecto.
 *
 * Acá se cubre que para esos modelos la corrida cierra sin recorrer el archivo ni llamar a la
 * IA, y que artículos sigue yendo por su camino de siempre.
 */
class RecomendacionSegunModeloTest extends EmpresaTestCase
{
    /** @var string[] Archivos temporales creados por el test, para borrarlos al final. */
    protected $archivos = [];

    protected function tearDown(): void
    {
        foreach ($this->archivos as $archivo) {
            @unlink($archivo);
        }

        parent::tearDown();
    }

    /**
     * Crea un archivo en storage (el job corta antes si no existe) y devuelve su ruta relativa.
     * No es un Excel válido a propósito: si algún camino intentara leerlo, el test se enteraría.
     *
     * @return string
     */
    protected function archivo_que_existe()
    {
        $relativa = 'imported_files/recomendacion_test_' . Str::random(8) . '.xlsx';
        $absoluta = storage_path('app/' . $relativa);

        if (!is_dir(dirname($absoluta))) {
            mkdir(dirname($absoluta), 0777, true);
        }

        file_put_contents($absoluta, 'no es un excel');
        $this->archivos[] = $absoluta;

        return $relativa;
    }

    /**
     * Crea una corrida de tipo recomendación para el usuario autenticado.
     *
     * @param  array $payload  Claves del payload (model, analysis_uuid, ...)
     * @param  string|null $excel_path
     * @return \App\Models\ExcelAnalysisRun
     */
    protected function recomendacion(array $payload, $excel_path = null)
    {
        $auth_user = auth()->user();

        return ExcelAnalysisRun::create([
            'uuid'         => Str::uuid()->toString(),
            'user_id'      => $auth_user->user_id ?? $auth_user->id,
            'auth_user_id' => $auth_user->id,
            'tipo'         => 'recomendacion',
            'estado'       => 'pendiente',
            'excel_path'   => $excel_path ?: $this->archivo_que_existe(),
            'payload'      => array_merge([
                'provider_id'                => null,
                'provider_code_column_index' => null,
                'column_mapping'             => [],
            ], $payload),
        ]);
    }

    /**
     * @test
     * @return void
     */
    public function una_recomendacion_de_clientes_cierra_sin_llamar_a_la_ia()
    {
        Notification::fake();
        Http::fake();

        $run = $this->recomendacion(['model' => 'client']);

        (new RunExcelAnalysisJob($run->id))->handle();

        $run->refresh();

        $this->assertEquals('listo', $run->estado);
        $this->assertNull($run->resultado['recomendacion_configuracion']);
        $this->assertEquals(0, $run->resultado['provider_codes_existentes_mismo_proveedor']);
        $this->assertEquals(0, $run->resultado['provider_codes_existentes_otros_proveedores']);
        $this->assertNull($run->resultado['formatos_numericos']);

        Http::assertNothingSent();
    }

    /**
     * @test
     * @return void
     */
    public function una_recomendacion_de_proveedores_tambien_cierra_sin_llamar_a_la_ia()
    {
        Notification::fake();
        Http::fake();

        $run = $this->recomendacion(['model' => 'provider']);

        (new RunExcelAnalysisJob($run->id))->handle();

        $run->refresh();

        $this->assertEquals('listo', $run->estado);
        $this->assertNull($run->resultado['recomendacion_configuracion']);

        Http::assertNothingSent();
    }

    /**
     * La SPA que todavía no se desplegó no manda `model` a /get-recomendacion: el modelo sale
     * del análisis del que salió la recomendación.
     *
     * @test
     * @return void
     */
    public function sin_model_en_la_recomendacion_se_toma_el_del_analisis_padre()
    {
        Notification::fake();
        Http::fake();

        $auth_user = auth()->user();

        $padre = ExcelAnalysisRun::create([
            'uuid'         => Str::uuid()->toString(),
            'user_id'      => $auth_user->user_id ?? $auth_user->id,
            'auth_user_id' => $auth_user->id,
            'tipo'         => 'analisis',
            'estado'       => 'listo',
            'excel_path'   => $this->archivo_que_existe(),
            'payload'      => ['model' => 'client', 'original_filename' => 'Clientes.xlsx'],
        ]);

        $run = $this->recomendacion(['analysis_uuid' => $padre->uuid]);

        (new RunExcelAnalysisJob($run->id))->handle();

        $run->refresh();

        $this->assertEquals('listo', $run->estado);
        $this->assertNull($run->resultado['recomendacion_configuracion']);

        Http::assertNothingSent();
    }

    /**
     * Una corrida vieja, sin modelo y sin padre, sigue por el camino de artículos de siempre:
     * acá el archivo no es un Excel, así que termina en error en vez de en el resultado neutro.
     *
     * @test
     * @return void
     */
    public function sin_modelo_ni_padre_sigue_el_camino_de_articulos()
    {
        Notification::fake();
        Http::fake();

        $run = $this->recomendacion([]);

        (new RunExcelAnalysisJob($run->id))->handle();

        $run->refresh();

        $this->assertEquals('error', $run->estado, 'Tendria que haber intentado leer el archivo como articulos.');
    }
}
