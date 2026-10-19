<?php

namespace Tests\Import\RelacionesParecidas;

use App\Http\Controllers\Helpers\import\article\ImportFailureHandler;
use App\Http\Controllers\Helpers\import\relaciones\EquivalenciasResolver;
use App\Jobs\FinalizeArticleImport;
use App\Jobs\ProcessArticleChunk;
use App\Models\ImportHistory;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;

/**
 * Las decisiones del paso 3 viajan a los lotes como la RUTA de un archivo y no copiadas en cada
 * ProcessArticleChunk (misión importacion-relaciones-parecidas, tandas 4 y 5).
 *
 * InitExcelImport las guarda saneadas UNA vez en `<excel>.equivalencias-<id del ImportHistory>.json`
 * y cada importación borra SOLO el suyo: FinalizeArticleImport al terminar bien,
 * ImportFailureHandler si falla. Tanda 5: antes llevaba el prefijo `<excel>.hoja` y lo borraba el
 * `<excel>.hoja*` del cierre de CUALQUIER importación de la misma subida (doble envío, o reabrir la
 * corrida guardada), con lo que los últimos lotes de la otra perdían sus decisiones. Cada lote lo
 * lee una vez; si no está, sigue sin decisiones y lo loguea. Un job serializado con el array adentro
 * sigue andando.
 *
 * Fixture 33 con lotes de 2 filas: "Del norte" cae en los lotes 1 y 4. Las decisiones de estos
 * tests son `crear` a propósito: un `usar` lo recuerda el controlador antes de encolar, y la
 * equivalencia recordada lo aplicaría aunque el lote no leyera el archivo.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class ArchivoDeDecisionesTest extends RelacionesParecidasTestCase
{
    /** @var array Lo que traía cada ProcessArticleChunk al empezar: ['equivalencias' => ..., 'existe' => bool, 'contenido' => ...]. */
    protected $lotes = [];

    /** @var array [[nivel, mensaje], ...] */
    protected $logs = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.ARTICLE_EXCEL_CHUNK_SIZE' => 2]);

        $test = $this;

        Event::listen(MessageLogged::class, function (MessageLogged $evento) use ($test) {
            $test->logs[] = [$evento->level, $evento->message];
        });
    }

    public function test_los_lotes_reciben_la_ruta_y_el_archivo_se_borra_al_terminar()
    {
        $vieja = $this->crear_categoria('DEL NORTE');

        $this->espiar_lotes();

        $import = $this->importar_articulos(self::ARTICULOS, [
            ['tipo' => 'categoria',      'valor' => 'Del norte', 'accion' => 'crear'],
            ['tipo' => 'cualquier_cosa', 'valor' => 'Del norte', 'accion' => 'crear'],
        ]);

        $this->assertSame(5, (int) $import->total_chunks, 'Si no hay 5 lotes, este test no prueba nada.');
        $this->assertCount(5, $this->lotes);

        $ruta = $this->lotes[0]['equivalencias'];

        $this->assertIsString($ruta, 'Al lote le llega la ruta, no el array.');
        $this->assertSame(EquivalenciasResolver::ruta_del_archivo_de_decisiones(storage_path('app/' . $import->excel_url), $import->id), $ruta);

        foreach ($this->lotes as $numero => $lote) {
            $this->assertSame($ruta, $lote['equivalencias'], 'Lote ' . ($numero + 1) . ': la misma ruta.');
            $this->assertTrue($lote['existe'], 'Lote ' . ($numero + 1) . ': el archivo está mientras corre la importación.');
        }

        /* Guardadas UNA vez y ya saneadas: el tipo inválido no llega. */
        $guardadas = json_decode($this->lotes[0]['contenido'], true);
        $this->assertCount(1, $guardadas);
        $this->assertSame('categoria', $guardadas[0]['tipo']);
        $this->assertSame('crear', $guardadas[0]['accion']);

        /* La decisión se aplicó en el lote 1 y en el 4: una sola "Del norte", aparte de la vieja. */
        $this->assertSame(1, $this->cuantos_exactos('categories', 'Del norte'));
        $this->assertNotSame((int) $vieja->id, (int) $this->articulo('RP-01')->category_id);
        $this->assertSame((int) $this->articulo('RP-01')->category_id, (int) $this->articulo('RP-08')->category_id);

        /* Lo borra el cierre de ESTA importación. */
        $this->assertFileDoesNotExist($ruta, 'FinalizeArticleImport borra el archivo de su importación.');
    }

    /**
     * Dos importaciones de la MISMA subida (mismo excel_path): el cierre de A, que llega con B a
     * mitad de camino, no le borra el archivo a B. B lo conserva en todos sus lotes y lo borra su
     * propio cierre.
     */
    public function test_el_cierre_de_otra_importacion_de_la_misma_subida_no_borra_el_archivo()
    {
        $this->crear_categoria('DEL NORTE');

        $relativa = $this->copiar_a_storage(self::ARTICULOS);
        $excel = storage_path('app/' . $relativa);
        $decisiones = [['tipo' => 'categoria', 'valor' => 'Del norte', 'accion' => 'crear']];

        $a = $this->importar_articulos(self::ARTICULOS, $decisiones, ['excel_path' => $relativa]);

        $this->assertSame($relativa, (string) $a->excel_url);
        $this->assertFileDoesNotExist(EquivalenciasResolver::ruta_del_archivo_de_decisiones($excel, $a->id), 'A borró el suyo al terminar.');

        /* B arranca; al empezar su lote 3 corre el cierre de A (el FinalizeArticleImport real). */
        $test = $this;
        $lote = 0;

        Event::listen(JobProcessing::class, function (JobProcessing $evento) use ($test, $a, &$lote) {
            $payload = $evento->job->payload();
            $comando = isset($payload['data']['command']) ? unserialize($payload['data']['command']) : null;

            if (!$comando instanceof ProcessArticleChunk) {
                return;
            }

            $lote++;

            if ($lote === 3) {
                (new FinalizeArticleImport((int) $test->tenant->id, (int) $a->id, (int) $a->import_status_id))->handle();
            }
        });

        $this->espiar_lotes();

        $b = $this->importar_articulos(self::ARTICULOS, $decisiones, ['excel_path' => $relativa]);

        $this->assertSame($relativa, (string) $b->excel_url, 'Las dos importaciones son de la misma subida.');
        $this->assertGreaterThanOrEqual(3, $lote, 'El cierre de A corrió con B a mitad de camino.');

        $ruta_b = EquivalenciasResolver::ruta_del_archivo_de_decisiones($excel, $b->id);

        $this->assertCount(5, $this->lotes);

        foreach ($this->lotes as $numero => $visto) {
            $this->assertTrue($visto['existe'], 'Lote ' . ($numero + 1) . ' de B: el archivo sigue ahí aunque A ya cerró.');
        }

        foreach ($this->lotes as $numero => $visto) {
            $this->assertSame($ruta_b, $visto['equivalencias'], 'Lote ' . ($numero + 1) . ' de B: su archivo.');
        }

        $perdidos = array_filter($this->logs, function ($log) {
            return strpos($log[1], 'no está el archivo de decisiones') !== false;
        });

        $this->assertCount(0, $perdidos, 'Ningún lote de B se quedó sin decisiones.');

        $this->assertFileDoesNotExist($ruta_b, 'B borra el suyo con su propio cierre.');
    }

    public function test_si_la_importacion_falla_borra_solo_su_archivo()
    {
        $relativa = $this->copiar_a_storage(self::ARTICULOS);
        $excel = storage_path('app/' . $relativa);

        $que_falla = $this->historial_en_proceso($relativa);
        $otra = $this->historial_en_proceso($relativa);

        $ruta_que_falla = EquivalenciasResolver::ruta_del_archivo_de_decisiones($excel, $que_falla->id);
        $ruta_otra = EquivalenciasResolver::ruta_del_archivo_de_decisiones($excel, $otra->id);

        $this->assertTrue(EquivalenciasResolver::guardar_archivo_de_decisiones($ruta_que_falla, [['tipo' => 'marca', 'valor' => 'Bosh', 'accion' => 'crear']]));
        $this->assertTrue(EquivalenciasResolver::guardar_archivo_de_decisiones($ruta_otra, [['tipo' => 'marca', 'valor' => 'Bosh', 'accion' => 'crear']]));

        ImportFailureHandler::registrar($que_falla->id, null, null, 'Falla de prueba.');

        $this->assertSame('fallo', (string) $que_falla->fresh()->status);
        $this->assertFileDoesNotExist($ruta_que_falla, 'La que falla borra el suyo: nadie más lo borraría.');
        $this->assertFileExists($ruta_otra, 'El de la otra importación de la misma subida queda.');
    }

    public function test_sin_decisiones_no_se_escribe_ningun_archivo()
    {
        $this->espiar_lotes();

        $import = $this->importar_articulos(self::ARTICULOS, []);

        $this->assertSame([], $this->lotes[0]['equivalencias']);
        $this->assertSame([], glob(storage_path('app/' . $import->excel_url) . '.equivalencias-*') ?: []);
    }

    public function test_si_el_archivo_no_esta_el_lote_sigue_sin_decisiones_y_lo_loguea()
    {
        $vieja = $this->crear_categoria('DEL NORTE');

        /* El archivo desaparece antes del primer lote (p. ej. lo borró otra limpieza). */
        $this->espiar_lotes(true);

        $import = $this->importar_articulos(self::ARTICULOS, [
            ['tipo' => 'categoria', 'valor' => 'Del norte', 'accion' => 'crear'],
        ]);

        $this->assertSame('terminado', (string) $import->fresh()->status, 'La importación no se rompe.');

        /* Sin la decisión, "Del norte" se resuelve por el camino de siempre: la de la misma clave. */
        $this->assertSame((int) $vieja->id, (int) $this->articulo('RP-01')->category_id);
        $this->assertSame((int) $vieja->id, (int) $this->articulo('RP-08')->category_id);
        $this->assertSame(0, $this->cuantos_exactos('categories', 'Del norte'));

        $avisos = array_filter($this->logs, function ($log) {
            return $log[0] === 'warning' && strpos($log[1], 'no está el archivo de decisiones') !== false;
        });

        $this->assertCount(5, $avisos, 'Un aviso por lote.');
    }

    public function test_un_job_viejo_con_el_array_sigue_andando()
    {
        $decisiones = [['tipo' => 'categoria', 'valor' => 'Del norte', 'accion' => 'usar', 'id' => 7]];

        $this->assertSame($decisiones, EquivalenciasResolver::decisiones_del_lote($decisiones));
        $this->assertSame([], EquivalenciasResolver::decisiones_del_lote(null));
        $this->assertSame([], EquivalenciasResolver::decisiones_del_lote(''));

        /* Y el job lo pasa tal cual (antes de la tanda 4 la propiedad era siempre un array). */
        $job = (new \ReflectionClass(ProcessArticleChunk::class))->newInstanceWithoutConstructor();
        $propiedad = new \ReflectionProperty(ProcessArticleChunk::class, 'equivalencias');
        $propiedad->setAccessible(true);
        $propiedad->setValue($job, $decisiones);

        $deserializado = unserialize(serialize($job));
        $this->assertSame($decisiones, $propiedad->getValue($deserializado));
    }

    public function test_un_archivo_ilegible_o_que_no_se_puede_escribir_no_rompe()
    {
        $carpeta = storage_path('app/imported_files');
        $ruta = $carpeta . '/' . uniqid('relaciones_parecidas_') . '.xlsx.equivalencias-1.json';
        $this->copias[] = $ruta;

        file_put_contents($ruta, '{esto no es json');

        $this->assertSame([], EquivalenciasResolver::decisiones_del_lote($ruta));

        $this->assertTrue(EquivalenciasResolver::guardar_archivo_de_decisiones($ruta, [['tipo' => 'marca', 'valor' => 'Bosh', 'accion' => 'crear']]));
        $this->assertSame([['tipo' => 'marca', 'valor' => 'Bosh', 'accion' => 'crear']], EquivalenciasResolver::decisiones_del_lote($ruta));

        $this->assertFalse(EquivalenciasResolver::guardar_archivo_de_decisiones($carpeta . '/no-existe-' . uniqid() . '/decisiones.json', []));

        $niveles = array_map(function ($log) {
            return $log[0];
        }, $this->logs);

        $this->assertContains('warning', $niveles);
    }

    /**
     * Un ImportHistory de artículos en proceso, de la subida $excel_url.
     *
     * @param  string $excel_url
     * @return ImportHistory
     */
    protected function historial_en_proceso($excel_url)
    {
        return ImportHistory::create([
            'created_models'   => 0,
            'updated_models'   => 0,
            'articles_match'   => 0,
            'status'           => 'en_proceso',
            'user_id'          => $this->tenant->id,
            'model_name'       => 'article',
            'excel_url'        => $excel_url,
            'total_chunks'     => 1,
            'processed_chunks' => 0,
        ]);
    }

    /**
     * Anota, al empezar cada ProcessArticleChunk, qué trae en `equivalencias` y si el archivo
     * está. Con $borrar, el primer lote borra el archivo antes de leerlo.
     *
     * @param  bool $borrar
     * @return void
     */
    protected function espiar_lotes($borrar = false)
    {
        $test = $this;

        Event::listen(JobProcessing::class, function (JobProcessing $evento) use ($test, $borrar) {
            $payload = $evento->job->payload();

            if (!isset($payload['data']['command'])) {
                return;
            }

            $comando = unserialize($payload['data']['command']);

            if (!$comando instanceof ProcessArticleChunk) {
                return;
            }

            $propiedad = new \ReflectionProperty(ProcessArticleChunk::class, 'equivalencias');
            $propiedad->setAccessible(true);

            $equivalencias = $propiedad->getValue($comando);

            if ($borrar && is_string($equivalencias)) {
                @unlink($equivalencias);
            }

            $existe = is_string($equivalencias) && is_file($equivalencias);

            $test->lotes[] = [
                'equivalencias' => $equivalencias,
                'existe'        => $existe,
                'contenido'     => $existe ? file_get_contents($equivalencias) : null,
            ];
        });
    }
}
