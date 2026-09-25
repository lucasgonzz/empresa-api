<?php

namespace Tests\Import;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\import\article\ArticleIndexCache;
use App\Http\Controllers\Helpers\import\article\ProcessRow;
use App\Models\Address;
use App\Models\Article;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * La fase de filas de la importación (ProcessRow::procesar() sobre cada fila del lote) no hace
 * consultas por fila a `articles`, `address_article`, `article_discounts`, `article_surchages`
 * ni `ivas` (misión `importacion-excel-motor-rapido`, 24/9/2026).
 *
 * Antes, cada fila con match cargaba el artículo con tres relaciones (4 consultas), leía el
 * stock de cada depósito con una consulta por depósito (dos veces: al armar el stock y al
 * depurar los diffs), hacía un load() de descuentos y otro de recargos, y buscaba la alícuota
 * de IVA del back-out con un find(). Ahora los modelos del lote se precargan de una vez
 * (ArticleIndexCache::precargar_modelos) con esas relaciones, ProcessRow lee de la relación ya
 * cargada y las alícuotas van por un cache estático.
 *
 * Cómo se mide: se cuentan con DB::listen las consultas a esas tablas durante la precarga más
 * el procesamiento de 5 filas, y durante la de 50 filas, todas con match contra artículos
 * sembrados; los conteos tienen que ser IGUALES. Si alguien vuelve a meter una consulta por
 * fila, el de 50 crece y el test lo denuncia. Se instancia ProcessRow directo (como hace
 * Captura_de_ofertas_en_la_importacion_Test) porque el endpoint mezcla esta fase con la
 * escritura de ActualizarBBDD, que tiene su propio conteo.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos
 * nombrados, union types, promoción de constructor, readonly, enum ni #[...].
 */
class SinConsultasPorFilaEnProcessRowTest extends ImportTestCase
{
    /** Tablas cuyas consultas no pueden crecer con la cantidad de filas. */
    const TABLAS = ['articles', 'address_article', 'article_discounts', 'article_surchages', 'ivas'];

    /** @var \App\Models\Address */
    protected $deposito;

    /** Columnas del "Excel" de este test: sin stock global, con stock por depósito, descuentos y recargos. */
    protected function columnas_del_lote()
    {
        return [
            'codigo_de_proveedor' => 0,
            'nombre'              => 1,
            'costo'               => 2,
            'iva'                 => 3,
            'descuentos'          => 4,
            'recargos'            => 5,
            /* Clave de columna del depósito: ProcessRow la arma desde el street del Address. */
            'deposito_central'    => 6,
        ];
    }

    /**
     * Siembra un depósito y $cantidad artículos del proveedor A con stock en ese depósito, un
     * descuento y un recargo cada uno, y devuelve las filas que los actualizan (otro costo,
     * otro descuento, otro recargo, otro stock en el depósito).
     *
     * @param  int $cantidad
     * @return array
     */
    protected function sembrar_y_armar_filas($cantidad)
    {
        $this->deposito = new Address();
        $this->deposito->street  = 'Deposito Central';
        $this->deposito->user_id = $this->tenant->id;
        $this->deposito->save();

        $filas = [];

        for ($i = 1; $i <= $cantidad; $i++) {
            $articulo = new Article();
            $articulo->user_id       = $this->tenant->id;
            $articulo->name          = 'Articulo N ' . $i;
            $articulo->provider_code = 'PC-N-' . $i;
            $articulo->provider_id   = $this->providers['A']->id;
            $articulo->cost          = 100 + $i;
            $articulo->stock         = 5;
            $articulo->iva_id        = 2;
            $articulo->status        = 'active';
            $articulo->online        = 1;
            $articulo->save();

            $articulo->addresses()->attach($this->deposito->id, ['amount' => 5, 'stock_min' => 1, 'stock_max' => 50]);

            DB::table('article_discounts')->insert([
                'article_id' => $articulo->id,
                'percentage' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('article_surchages')->insert([
                'article_id'             => $articulo->id,
                'percentage'             => 5,
                'luego_del_precio_final' => 0,
                'created_at'             => now(),
                'updated_at'             => now(),
            ]);

            $filas[] = ['PC-N-' . $i, 'Articulo N ' . $i, (string) (200 + $i), '21', '12', '7', '9'];
        }

        return $filas;
    }

    /**
     * @return void
     */
    public function test_las_consultas_de_la_fase_de_filas_no_crecen_con_la_cantidad_de_filas()
    {
        $filas = $this->sembrar_y_armar_filas(50);

        $con_5  = $this->consultas_por_tabla(array_slice($filas, 0, 5));
        $con_50 = $this->consultas_por_tabla($filas);

        foreach (self::TABLAS as $tabla) {
            /*
             * "No crece": con 50 filas no puede haber MÁS consultas que con 5. Puede haber menos
             * (las alícuotas de IVA quedan en un cache estático del proceso después de la primera
             * pasada), y eso también es lo buscado.
             */
            $this->assertLessThanOrEqual(
                $con_5[$tabla],
                $con_50[$tabla],
                'Tabla `' . $tabla . '`: con 5 filas hubo ' . $con_5[$tabla] . ' consultas y con 50 hubo '
                    . $con_50[$tabla] . '. Hay una consulta por fila (N+1) en la fase de filas.'
            );
        }

        /* La precarga existió: sin ninguna consulta a articles el test no estaría midiendo nada. */
        $this->assertGreaterThan(0, $con_50['articles']);
    }

    /**
     * Procesa las filas con un ProcessRow nuevo (como hace cada lote) y devuelve, por tabla, las
     * consultas hechas desde la precarga de modelos hasta la última fila.
     *
     * @param  array $filas
     * @return array [tabla => cantidad]
     */
    protected function consultas_por_tabla(array $filas)
    {
        Cache::flush();
        ArticleIndexCache::reset_runtime_de_tests();

        $columns = $this->columnas_del_lote();

        $index = ArticleIndexCache::get_index($this->tenant->id, null, false);

        /* Las 8 consultas del constructor (listas, depósitos, marcas, categorías, IVA...) quedan afuera del conteo a propósito: son por lote. */
        $process_row = new ProcessRow([
            'ct'                                                 => new Controller(),
            'columns'                                            => $columns,
            'blank_flags'                                        => [],
            'user'                                               => $this->tenant,
            'provider_id'                                        => null,
            'create_and_edit'                                    => true,
            'import_history_id'                                  => null,
            'fila_inicial'                                       => 2,
            'actualizar_articulos_de_otro_proveedor'             => false,
            'actualizar_proveedor'                               => true,
            'permitir_provider_code_repetido'                    => false,
            'permitir_provider_code_repetido_en_multi_providers' => true,
            'actualizar_por_provider_code'                       => true,
            'interpretacion_punto'                               => 'auto',
            'filas_repetidas_del_archivo'                        => 'ultima_gana',
            /* Enciende el back-out de IVA, que es el que buscaba la alícuota por fila. */
            'precios_incluyen_iva'                               => true,
            'desempatar_por_nombre'                              => false,
        ]);

        $process_row->set_article_index($index);

        $conteo   = array_fill_keys(self::TABLAS, 0);
        $contando = true;

        DB::listen(function ($consulta) use (&$conteo, &$contando) {
            if (!$contando) {
                return;
            }

            foreach (self::TABLAS as $tabla) {
                if (strpos($consulta->sql, '`' . $tabla . '`') !== false) {
                    $conteo[$tabla]++;
                }
            }
        });

        /* Lo mismo que hace ArticleImport::collection() antes del loop. */
        ArticleIndexCache::precargar_modelos(
            (int) $this->tenant->id,
            ArticleIndexCache::ids_candidatos_de_filas($filas, $columns, $index),
            ArticleIndexCache::relaciones_de_precarga()
        );

        foreach ($filas as $fila) {
            $process_row->procesar($fila, []);
        }

        $contando = false;

        /* Todas las filas tenían que matchear y dejar cambios (costo, descuento, recargo y stock distintos). */
        $this->assertCount(count($filas), $process_row->getArticulosParaActualizar(), 'No todas las filas dejaron una actualización encolada.');

        return $conteo;
    }
}
