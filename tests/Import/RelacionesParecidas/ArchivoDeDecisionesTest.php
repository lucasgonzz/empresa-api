<?php

namespace Tests\Import\RelacionesParecidas;

use App\Http\Controllers\Helpers\import\relaciones\EquivalenciasResolver;
use App\Jobs\ProcessArticleChunk;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;

/**
 * Las decisiones del paso 3 viajan a los lotes como la RUTA de un archivo y no copiadas en cada
 * ProcessArticleChunk (misión importacion-relaciones-parecidas, tanda 4).
 *
 * InitExcelImport las guarda saneadas UNA vez en `<excel>.hoja-equivalencias-<uuid>.json`, junto
 * al volcado de la hoja: misma vida útil y misma limpieza (FinalizeArticleImport borra
 * `<excel>.hoja*` al terminar bien). Cada lote lo lee una vez; si no está, sigue sin decisiones y
 * lo loguea. Un job serializado con el array adentro sigue andando.
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
        $this->assertStringStartsWith(storage_path('app/' . $import->excel_url) . '.hoja-equivalencias-', $ruta);

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

        /* La misma limpieza que el volcado de la hoja. */
        $this->assertFileDoesNotExist($ruta, 'FinalizeArticleImport lo borra con el resto de <excel>.hoja*.');
    }

    public function test_sin_decisiones_no_se_escribe_ningun_archivo()
    {
        $this->espiar_lotes();

        $import = $this->importar_articulos(self::ARTICULOS, []);

        $this->assertSame([], $this->lotes[0]['equivalencias']);
        $this->assertSame([], glob(storage_path('app/' . $import->excel_url) . '.hoja-equivalencias-*') ?: []);
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
        $ruta = $carpeta . '/' . uniqid('relaciones_parecidas_') . '.xlsx.hoja-equivalencias-x.json';
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
