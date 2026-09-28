<?php

namespace Tests\Feature\Listado;

use App\Http\Controllers\CommonLaravel\SearchController;
use App\Http\Controllers\Helpers\Excel\Article\ArticleExportStreamer;
use App\Jobs\ProcessArticleExportJob;
use App\Models\ExportHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\Common\Creator\ReaderEntityFactory;
use Tests\EmpresaTestCase;

/**
 * Exportación de los artículos FILTRADOS del listado (misión exportacion-articulos-con-filtros,
 * 28/9/2026).
 *
 * Antes el request corría el buscador completo (withAll() + ->get() de todo lo filtrado) para
 * sacar los ids y mandárselos al job: con un filtro amplio en un catálogo grande eran cientos de
 * miles de modelos en el pedido web. Ahora el request solo cuenta y el job reaplica los filtros
 * y los recorre por lotes. Lo que se protege:
 *
 * - Que el Excel sea EXACTAMENTE el mismo que salía por el camino viejo (buscador → ids), para
 *   filtros de texto, número, relación, "en blanco" y con orden, cruzando lotes.
 * - Que el endpoint no hidrate los artículos filtrados y le pase los filtros al job.
 * - Que un filtro sin resultados siga devolviendo 422.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
class Exportacion_De_Articulos_Filtrados_Test extends EmpresaTestCase
{
    /** @var int */
    protected $user_id;

    /** @var int */
    protected $proveedor_id;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user_id = (int) auth()->id();

        Storage::fake('local');
    }

    /**
     * Doce artículos "Filtro lotes", la mitad con proveedor, con stock variado y uno inactivo.
     *
     * @return array ids
     */
    private function sembrar()
    {
        $ahora = '2026-09-28 10:00:00';
        $this->proveedor_id = DB::table('providers')->insertGetId(['user_id' => $this->user_id, 'name' => 'Proveedor Filtro Lotes', 'created_at' => $ahora, 'updated_at' => $ahora]);

        $ids = [];
        for ($i = 1; $i <= 12; $i++) {
            $ids[] = DB::table('articles')->insertGetId([
                'user_id'     => $this->user_id,
                'name'        => ($i % 3 == 0 ? 'Filtro lotes rojo ' : 'Filtro lotes azul ') . $i,
                'provider_id' => $i % 2 ? $this->proveedor_id : null,
                'cost'        => 100 + $i,
                'stock'       => $i * 10,
                'status'      => $i == 4 ? 'inactive' : 'active',
                'created_at'  => $ahora,
                'updated_at'  => $ahora,
            ]);
        }

        return $ids;
    }

    /**
     * @param string $ruta
     * @return array Filas como [tipo, valor].
     */
    private function leer($ruta)
    {
        $reader = ReaderEntityFactory::createXLSXReader();
        $reader->setShouldPreserveEmptyRows(true);
        $reader->open($ruta);

        $filas = [];
        foreach ($reader->getSheetIterator() as $hoja) {
            foreach ($hoja->getRowIterator() as $row) {
                $filas[] = array_map(function ($cell) {
                    return [$cell->getType(), $cell->getValue()];
                }, $row->getCells());
            }
            break;
        }
        $reader->close();

        return $filas;
    }

    /**
     * Los filtros que se prueban, con la forma que manda la SPA.
     *
     * @return array nombre => filtros
     */
    private function filtros_de_prueba()
    {
        return [
            'texto que contenga' => [
                ['key' => 'name', 'type' => 'text', 'que_contenga' => 'Filtro lotes'],
            ],
            'dos palabras' => [
                ['key' => 'name', 'type' => 'text', 'que_contenga' => 'lotes rojo'],
            ],
            'numero mayor que' => [
                ['key' => 'name', 'type' => 'text', 'que_contenga' => 'Filtro lotes'],
                ['key' => 'stock', 'type' => 'number', 'mayor_que' => '45'],
            ],
            'proveedor' => [
                ['key' => 'provider_id', 'type' => 'search', 'igual_que' => $this->proveedor_id],
            ],
            'proveedor en blanco' => [
                ['key' => 'name', 'type' => 'text', 'que_contenga' => 'Filtro lotes'],
                ['key' => 'provider_id', 'type' => 'search', 'en_blanco' => true],
            ],
            'con orden por columna' => [
                ['key' => 'name', 'type' => 'text', 'que_contenga' => 'Filtro lotes', 'ordenar_de' => 'DESC'],
            ],
        ];
    }

    /** @test */
    public function el_excel_filtrado_es_el_mismo_que_salia_por_ids()
    {
        $this->sembrar();

        foreach ($this->filtros_de_prueba() as $nombre => $filtros) {

            // Camino viejo: el buscador del listado resuelve los ids y el job exporta esos ids.
            $modelos = (new SearchController())->search(new Request(), 'article', $filtros);
            $ids = $modelos->pluck('id')->toArray();
            $this->assertNotEmpty($ids, 'El filtro "' . $nombre . '" no encontró nada: la prueba no prueba nada.');

            (new ArticleExportStreamer($this->user_id, $ids))->guardar('por-ids.xlsx');

            // Camino nuevo: el job reaplica los filtros, de a dos artículos para cruzar lotes.
            $streamer = new ArticleExportStreamer($this->user_id, null, $filtros);
            $this->assertSame(count($ids), $streamer->total(), 'El total del filtro "' . $nombre . '" no coincide con el buscador.');
            $escritos = $streamer->con_lote(2)->guardar('por-filtros.xlsx');

            $this->assertSame(count($ids), $escritos, 'Cantidad distinta con el filtro "' . $nombre . '".');
            $this->assertSame(
                $this->leer(Storage::disk('local')->path('por-ids.xlsx')),
                $this->leer(Storage::disk('local')->path('por-filtros.xlsx')),
                'El Excel del filtro "' . $nombre . '" no es igual al que salía por ids.'
            );
        }
    }

    /** @test */
    public function el_endpoint_cuenta_sin_traer_los_articulos_y_le_pasa_los_filtros_al_job()
    {
        Queue::fake();
        $this->sembrar();

        $filtros = [['key' => 'name', 'type' => 'text', 'que_contenga' => 'Filtro lotes']];

        $selects_de_articulos = 0;
        DB::listen(function ($query) use (&$selects_de_articulos) {
            // Un SELECT de filas de articles (no un COUNT) sería el buscador trayendo modelos.
            if (preg_match('/^select (?!count).* from `articles`/i', $query->sql)) {
                $selects_de_articulos++;
            }
        });

        $response = $this->getJson('api/article/excel/export?filters=' . urlencode(json_encode($filtros)));
        $response->assertStatus(200);

        $this->assertSame(0, $selects_de_articulos, 'El request volvió a traer los artículos filtrados.');

        Queue::assertPushed(ProcessArticleExportJob::class, function ($job) use ($filtros) {
            $leer = function ($propiedad) use ($job) {
                $reflexion = new \ReflectionProperty($job, $propiedad);
                $reflexion->setAccessible(true);
                return $reflexion->getValue($job);
            };

            return $leer('filters') === $filtros && $leer('article_ids') === [];
        });
    }

    /** @test */
    public function el_job_con_filtros_exporta_los_filtrados_y_cierra_el_historial()
    {
        \Illuminate\Support\Facades\Notification::fake();
        $this->sembrar();

        $filtros = [['key' => 'name', 'type' => 'text', 'que_contenga' => 'lotes rojo']];
        $esperados = (new SearchController())->search(new Request(), 'article', $filtros)->count();

        $export_history = \App\Http\Controllers\Helpers\ExportHistoryHelper::create_pending($this->user_id, $this->user_id, 'article');

        (new ProcessArticleExportJob($this->user_id, $this->user_id, [], $export_history->id, $filtros))->handle();

        $export_history = ExportHistory::find($export_history->id);
        $this->assertSame('completed', $export_history->status);
        $this->assertSame($esperados, (int) $export_history->exported_count);

        $filas = $this->leer(Storage::disk('local')->path('exported-files/' . $export_history->file_name));
        $this->assertCount($esperados + 1, $filas);
    }

    /** @test */
    public function un_filtro_sin_resultados_sigue_devolviendo_422()
    {
        Queue::fake();

        $filtros = [['key' => 'name', 'type' => 'text', 'que_contenga' => 'no-existe-ningun-articulo-asi-xyz']];

        $this->getJson('api/article/excel/export?filters=' . urlencode(json_encode($filtros)))
             ->assertStatus(422);

        Queue::assertNothingPushed();
    }
}
