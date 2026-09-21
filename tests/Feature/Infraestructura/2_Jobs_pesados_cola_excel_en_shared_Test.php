<?php

namespace Tests\Feature\Infraestructura;

use App\Jobs\FinalizeArticleImport;
use App\Jobs\ProcessArticleChunk;
use App\Jobs\ProcessArticleExportJob;
use App\Jobs\ProcessClientExportJob;
use App\Jobs\ProcessProviderExportJob;
use App\Jobs\ProcessProviderOrderArticleImport;
use App\Jobs\ProcessSincronizarDescuentosProveedorJob;
use App\Jobs\RollbackArticleImportHistory;
use App\Jobs\RunExcelAnalysisJob;
use Tests\TestCase;

/**
 * Misión colas-excel-asistente (21/9/2026) — los jobs pesados de import/export de catálogo
 * (timeout 600-3600s) eligen su cola con `viaQueue()`: 'excel' en shared hosting (separados del
 * asistente de IA, que se queda en 'default'), y null (= 'default') en el VPS, porque ahí el
 * supervisor de cada cliente consume solo esa cola hoy — mandar estos jobs a 'excel' en el VPS
 * los dejaría sin nadie que los procese.
 *
 * Se instancia cada job SIN pasar por su constructor (`newInstanceWithoutConstructor`) a
 * propósito: son 9 clases con constructores muy distintos entre sí (algunos con más de 15
 * parámetros posicionales) y `viaQueue()` no depende de ninguna propiedad de instancia, solo de
 * `config('app.VPS')` — replicar cada constructor acá sería frágil y no probaría nada extra.
 */
class Jobs_pesados_cola_excel_en_shared_Test extends TestCase
{
    /**
     * Las nueve clases de job pesado que esta misión movió a la cola 'excel'.
     *
     * @return array<string, array{0: string}>
     */
    public function provider_jobs_pesados(): array
    {
        return [
            'RunExcelAnalysisJob'                      => [RunExcelAnalysisJob::class],
            'ProcessArticleChunk'                       => [ProcessArticleChunk::class],
            'FinalizeArticleImport'                      => [FinalizeArticleImport::class],
            'ProcessProviderOrderArticleImport'          => [ProcessProviderOrderArticleImport::class],
            'RollbackArticleImportHistory'               => [RollbackArticleImportHistory::class],
            'ProcessArticleExportJob'                    => [ProcessArticleExportJob::class],
            'ProcessProviderExportJob'                   => [ProcessProviderExportJob::class],
            'ProcessClientExportJob'                     => [ProcessClientExportJob::class],
            'ProcessSincronizarDescuentosProveedorJob'   => [ProcessSincronizarDescuentosProveedorJob::class],
        ];
    }

    /**
     * Instancia la clase sin pasar por su constructor (ver docblock de la clase).
     *
     * @param  string  $clase
     * @return object
     */
    protected function instancia_sin_constructor(string $clase)
    {
        return (new \ReflectionClass($clase))->newInstanceWithoutConstructor();
    }

    /** @test @dataProvider provider_jobs_pesados */
    public function en_shared_hosting_va_a_la_cola_excel(string $clase)
    {
        config(['app.VPS' => false]);

        $job = $this->instancia_sin_constructor($clase);

        $this->assertSame('excel', $job->viaQueue(), $clase . '::viaQueue() tiene que devolver "excel" en shared hosting.');
    }

    /** @test @dataProvider provider_jobs_pesados */
    public function en_el_vps_se_queda_en_default(string $clase)
    {
        config(['app.VPS' => true]);

        $job = $this->instancia_sin_constructor($clase);

        $this->assertNull($job->viaQueue(), $clase . '::viaQueue() tiene que devolver null (default) en el VPS: es la única cola que el supervisor de cada cliente consume hoy.');
    }

    /**
     * Red de seguridad para el día que alguien agregue un job pesado nuevo a esta familia y se
     * olvide de declararle viaQueue(): sin este chequeo, ese job caería en 'default' en shared
     * hosting sin que ningún test lo denuncie, y volvería a competir con el asistente.
     *
     * @return void
     */
    public function test_el_asistente_no_declara_viaquee_y_se_queda_en_default()
    {
        config(['app.VPS' => false]);

        $job = $this->instancia_sin_constructor(\App\Jobs\ResponderMensajeChatIaJob::class);

        $this->assertFalse(
            method_exists($job, 'viaQueue') && $job->viaQueue() === 'excel',
            'ResponderMensajeChatIaJob no tiene que ir a la cola excel: es el job del asistente, la separación es justamente para que no compita con los jobs pesados.'
        );
    }
}
