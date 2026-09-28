<?php

namespace Tests\Feature\Listado;

use App\Events\BackgroundProcessUpdated;
use App\Exports\ArticleExport;
use App\Http\Controllers\Helpers\BackgroundProcessHelper;
use App\Http\Controllers\Helpers\Excel\Article\ArticleExportStreamer;
use App\Http\Controllers\Helpers\ExportHistoryHelper;
use App\Jobs\ProcessArticleExportJob;
use App\Models\Article;
use App\Models\BackgroundProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use OpenSpout\Reader\Common\Creator\ReaderEntityFactory;
use Tests\EmpresaTestCase;

/**
 * La exportación de artículos por lotes (misión exportacion-articulos-streaming, 28/9/2026).
 *
 * La exportación de Servian (750k artículos) reventaba los 4 GB del worker al minuto: el Excel se
 * armaba entero en memoria con maatwebsite. Ahora ArticleExportStreamer lo escribe por lotes con
 * XlsxStreamWriter. Lo que se protege acá:
 *
 * - Que el archivo nuevo diga exactamente lo mismo que el de maatwebsite (mismas celdas, mismos
 *   tipos), cruzando varios lotes: el Excel se vuelve a importar.
 * - Que la selección no se salga del comercio ni filtre por estado.
 * - Que el job cierre el historial y el registro de procesos con total y avance reales.
 * - Que la cantidad de consultas NO crezca con el catálogo (el viejo hacía varias por fila).
 *
 * La comparación contra el código ANTERIOR (origin/develop 4.2.6, celda por celda en cuatro
 * escenarios de listas de precio) se hizo fuera de la suite y está en el informe de la misión.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
class Exportacion_De_Articulos_Por_Lotes_Test extends EmpresaTestCase
{
    /** @var int */
    protected $user_id;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user_id = (int) auth()->id();

        Storage::fake('local');
    }

    /**
     * Arma un catálogo chico pero con todo lo que agrega columnas o tipos raros: dos depósitos,
     * listas de precio, descuentos y recargos (con F), un código con ceros a la izquierda, stock
     * en cero y un artículo inactivo.
     *
     * @param int $cantidad
     * @return array ids de artículos creados
     */
    private function sembrar_catalogo($cantidad)
    {
        $ahora = '2026-09-28 10:00:00';
        $depositos = [
            DB::table('addresses')->insertGetId(['user_id' => $this->user_id, 'street' => 'Deposito Lotes', 'created_at' => $ahora, 'updated_at' => $ahora]),
            DB::table('addresses')->insertGetId(['user_id' => $this->user_id, 'street' => 'Local Lotes', 'created_at' => $ahora, 'updated_at' => $ahora]),
        ];
        $lista = DB::table('price_types')->insertGetId(['user_id' => $this->user_id, 'name' => 'Lista Lotes', 'position' => 99, 'percentage' => 10, 'created_at' => $ahora, 'updated_at' => $ahora]);

        $ids = [];
        for ($i = 1; $i <= $cantidad; $i++) {
            $id = DB::table('articles')->insertGetId([
                'user_id'       => $this->user_id,
                'name'          => 'Articulo por lotes ñandú ' . $i,
                'bar_code'      => $i % 3 == 0 ? '00' . (7790 + $i) : (string) (7790001000000 + $i),
                'provider_code' => $i % 2 ? (string) (90000 + $i) : 'WK-' . $i,
                'cost'          => $i % 4 == 0 ? 0 : 100 + $i / 4,
                'price'         => 150 + $i,
                'final_price'   => 181.5 + $i,
                'stock'         => $i % 5 == 0 ? 0 : $i,
                'status'        => $i == 2 ? 'inactive' : 'active',
                'aplicar_iva'   => $i % 2,
                'created_at'    => $ahora,
                'updated_at'    => $ahora,
            ]);
            $ids[] = $id;

            if ($i % 2 == 0) {
                DB::table('article_discounts')->insert(['article_id' => $id, 'percentage' => 10, 'created_at' => $ahora, 'updated_at' => $ahora]);
            }
            if ($i % 3 == 0) {
                DB::table('article_surchages')->insert(['article_id' => $id, 'percentage' => 5, 'luego_del_precio_final' => 1, 'created_at' => $ahora, 'updated_at' => $ahora]);
            }
            if ($i % 2 == 1) {
                DB::table('address_article')->insert(['article_id' => $id, 'address_id' => $depositos[$i % 2], 'amount' => $i, 'stock_min' => 1, 'stock_max' => 50, 'created_at' => $ahora, 'updated_at' => $ahora]);
            }
            DB::table('article_price_type')->insert(['article_id' => $id, 'price_type_id' => $lista, 'percentage' => 10, 'final_price' => 200 + $i, 'setear_precio_final' => $i % 2, 'created_at' => $ahora, 'updated_at' => $ahora]);
        }

        return $ids;
    }

    /**
     * Filas del Excel como [tipo, valor] con OpenSpout, que es el lector del importador.
     *
     * @param string $ruta
     * @return array
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

    /** @test */
    public function el_excel_por_lotes_es_igual_al_de_maatwebsite_aunque_cruce_varios_lotes()
    {
        $this->sembrar_catalogo(11);

        Excel::store(new ArticleExport(null, $this->user_id), 'maatwebsite.xlsx');

        (new ArticleExportStreamer($this->user_id))
            ->con_lote(3)
            ->guardar('por-lotes.xlsx');

        $esperado = $this->leer(Storage::disk('local')->path('maatwebsite.xlsx'));
        $obtenido = $this->leer(Storage::disk('local')->path('por-lotes.xlsx'));

        $this->assertGreaterThan(12, count($esperado), 'El Excel de referencia salió sin filas.');
        $this->assertSame(count($esperado), count($obtenido), 'Distinta cantidad de filas.');

        foreach ($esperado as $i => $fila) {
            $this->assertSame($fila, $obtenido[$i], 'La fila ' . ($i + 1) . ' no coincide con la de maatwebsite.');
        }
    }

    /** @test */
    public function el_excel_conserva_los_ceros_a_la_izquierda_y_escribe_numeros_como_numeros()
    {
        $ids = $this->sembrar_catalogo(3);

        (new ArticleExportStreamer($this->user_id, [$ids[2]]))->guardar('uno.xlsx');

        $filas = $this->leer(Storage::disk('local')->path('uno.xlsx'));
        $encabezados = array_column($filas[0], 1);
        $fila = $filas[1];

        $codigo = $fila[array_search('Codigo de barras', $encabezados)];
        $this->assertSame('007793', $codigo[1], 'El código con ceros a la izquierda tiene que quedar como texto.');

        $numero = $fila[array_search('Numero', $encabezados)];
        $this->assertSame($ids[2], (int) $numero[1]);
        $this->assertTrue(is_int($numero[1]) || is_float($numero[1]), 'El número de artículo tiene que ser una celda numérica.');

        $costo = $fila[array_search('Costo', $encabezados)];
        $this->assertTrue(is_float($costo[1]), 'El costo (decimal de MySQL, llega como string) tiene que salir como número.');
    }

    /**
     * Sin listas_de_precio (el caso de Servian) los encabezados de las listas van al final, y
     * hasta el 28/9/2026 los valores se insertaban después de "Aplicar Iva": desde "Categoria" en
     * adelante cada valor caía en la columna de al lado. Cada columna tiene que decir lo suyo.
     *
     * @test
     */
    public function sin_listas_de_precio_cada_valor_queda_bajo_su_encabezado()
    {
        DB::table('users')->where('id', $this->user_id)->update(['listas_de_precio' => 0]);
        $ids = $this->sembrar_catalogo(1);
        $categoria = DB::table('categories')->insertGetId(['user_id' => $this->user_id, 'name' => 'Categoria Alineada', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('articles')->where('id', $ids[0])->update(['category_id' => $categoria, 'descripcion' => 'Descripcion alineada', 'stock_min' => 7]);

        (new ArticleExportStreamer($this->user_id, [$ids[0]]))->guardar('alineado.xlsx');

        $filas = $this->leer(Storage::disk('local')->path('alineado.xlsx'));
        $encabezados = array_column($filas[0], 1);
        $celda = function ($titulo) use ($filas, $encabezados) {
            $indice = array_search($titulo, $encabezados);
            $this->assertNotFalse($indice, 'Falta la columna ' . $titulo);
            return $filas[1][$indice][1];
        };

        $this->assertCount(count($encabezados), $filas[1], 'La fila no tiene el mismo ancho que los encabezados.');
        $this->assertSame('Articulo por lotes ñandú 1', $celda('Nombre'));
        $this->assertSame('Categoria Alineada', $celda('Categoria'));
        $this->assertSame('Descripcion alineada', $celda('Descripcion'));
        $this->assertSame('2026-09-28 10:00:00', $celda('Creado'));
        $this->assertSame('2026-09-28 10:00:00', $celda('Actualizado'));
        $this->assertSame('', $celda('Lista Lotes'), 'Sin listas_de_precio la columna de la lista va vacía, no con otro dato.');
    }

    /**
     * Excel no abre un archivo con una celda de más de 32.767 caracteres. PhpSpreadsheet recortaba;
     * `descripcion` es TEXT, así que alcanza con un artículo con una descripción larga.
     *
     * @test
     */
    public function una_descripcion_larga_se_recorta_como_lo_hacia_phpspreadsheet()
    {
        $ids = $this->sembrar_catalogo(1);
        DB::table('articles')->where('id', $ids[0])->update(['descripcion' => "Linea 1\r\n" . str_repeat('á', 100) . str_repeat('a', 40000)]);

        (new ArticleExportStreamer($this->user_id, [$ids[0]]))->guardar('larga.xlsx');
        Excel::store(new ArticleExport(Article::where('id', $ids[0])->get(), $this->user_id), 'larga-maatwebsite.xlsx');

        $filas = $this->leer(Storage::disk('local')->path('larga.xlsx'));
        $encabezados = array_column($filas[0], 1);
        $descripcion = $filas[1][array_search('Descripcion', $encabezados)][1];

        $referencia = $this->leer(Storage::disk('local')->path('larga-maatwebsite.xlsx'));
        $descripcion_referencia = $referencia[1][array_search('Descripcion', array_column($referencia[0], 1))][1];

        // PhpSpreadsheet recorta a 32.767 y DESPUÉS convierte CRLF en LF: con un salto queda uno menos.
        $this->assertLessThanOrEqual(32767, mb_strlen($descripcion, 'UTF-8'));
        $this->assertGreaterThan(32000, mb_strlen($descripcion, 'UTF-8'));
        $this->assertSame($descripcion_referencia, $descripcion);
        $this->assertSame(0, strpos($descripcion, "Linea 1\ná"), 'El salto de línea tiene que quedar como \n.');
        $this->assertSame($this->leer(Storage::disk('local')->path('larga-maatwebsite.xlsx')), $filas);
    }

    /** @test */
    public function la_seleccion_exporta_solo_los_ids_pedidos_del_comercio_sin_filtrar_por_estado()
    {
        $ids = $this->sembrar_catalogo(6);

        $ajeno = Article::where('user_id', '<>', $this->user_id)->value('id');
        if (is_null($ajeno)) {
            $ajeno = DB::table('articles')->insertGetId(['user_id' => $this->user_id + 100000, 'name' => 'Ajeno', 'status' => 'active']);
        }

        // El segundo artículo está inactivo; el ajeno es de otro comercio; hay un id repetido.
        $pedidos = [$ids[0], $ids[1], $ids[4], $ids[4], $ajeno];

        $streamer = new ArticleExportStreamer($this->user_id, $pedidos);
        $escritos = $streamer->con_lote(2)->guardar('seleccion.xlsx');

        $filas = $this->leer(Storage::disk('local')->path('seleccion.xlsx'));
        $numeros = array_map(function ($fila) {
            return (int) $fila[0][1];
        }, array_slice($filas, 1));

        $this->assertSame(3, $escritos);
        $this->assertSame([$ids[0], $ids[1], $ids[4]], $numeros, 'Tienen que salir los tres pedidos del comercio, por id ascendente como antes, incluido el inactivo.');
        $this->assertNotContains((int) $ajeno, $numeros, 'Se coló un artículo de otro comercio.');
    }

    /** @test */
    public function las_consultas_no_crecen_con_el_catalogo()
    {
        $contar = function ($cantidad_extra) {
            $this->sembrar_catalogo($cantidad_extra);
            $consultas = 0;
            DB::listen(function () use (&$consultas) {
                $consultas++;
            });
            (new ArticleExportStreamer($this->user_id))->escribir(Storage::disk('local')->path('conteo-' . $cantidad_extra . '.xlsx'));
            return $consultas;
        };

        $con_pocos = $contar(5);
        $con_mas = $contar(40);

        // Un solo lote en los dos casos: la cantidad de consultas no puede depender de cuántos
        // artículos hay. El código anterior hacía varias por artículo (más de 10 por fila).
        $this->assertLessThanOrEqual($con_pocos + 2, $con_mas, 'Hay consultas por artículo (N+1) en la exportación.');
        $this->assertLessThan(40, $con_mas);
    }

    /** @test */
    public function el_job_cierra_el_historial_y_el_proceso_con_total_y_avance_reales()
    {
        Notification::fake();
        Event::fake([BackgroundProcessUpdated::class]);

        $this->sembrar_catalogo(4);
        $activos = Article::where('user_id', $this->user_id)->where('status', 'active')->count();

        $export_history = ExportHistoryHelper::create_pending($this->user_id, $this->user_id, 'article');
        $proceso = BackgroundProcessHelper::por_referencia($export_history);

        (new ProcessArticleExportJob($this->user_id, $this->user_id, [], $export_history->id))->handle();

        $export_history = $export_history->fresh();
        $this->assertSame('completed', $export_history->status);
        $this->assertSame($activos, (int) $export_history->exported_count);
        Storage::disk('local')->assertExists('exported-files/' . $export_history->file_name);

        $proceso = BackgroundProcess::find($proceso->id);
        $this->assertSame(BackgroundProcess::STATUS_COMPLETADO, $proceso->status);
        $this->assertSame($activos, (int) $proceso->total, 'La barra de la exportación ahora es medible: tiene que llevar el total.');
        $this->assertSame($export_history->excel_url, $proceso->resultado()['link']);

        $filas = $this->leer(Storage::disk('local')->path('exported-files/' . $export_history->file_name));
        $this->assertCount($activos + 1, $filas, 'El Excel tiene que tener el encabezado y una fila por artículo activo.');
    }

    /** @test */
    public function las_tablas_de_descuentos_recargos_y_listas_tienen_indice_por_article_id()
    {
        foreach (['article_discounts', 'article_surchages', 'article_discount_blancos', 'article_surchage_blancos', 'article_price_type', 'article_price_type_monedas'] as $tabla) {
            $indices = DB::select('SHOW INDEX FROM `' . $tabla . '` WHERE Column_name = ? AND Seq_in_index = 1', ['article_id']);
            $this->assertNotEmpty($indices, 'La tabla ' . $tabla . ' no tiene índice por article_id.');
        }
    }
}
