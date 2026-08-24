<?php

namespace Tests\Import;

use App\Models\Article;
use App\Models\StockMovement;

/**
 * Regresion del incidente de importacion de Servian del 24/07/2026.
 *
 * Todos los demas tests de importacion usan archivos que entran en un solo
 * lote. El bug original era justamente lo contrario: la deduplicacion funciona
 * DENTRO de un lote y no existe ENTRE lotes, asi que un archivo de un lote no
 * lo reproduce.
 *
 * Por eso este test baja ARTICLE_EXCEL_CHUNK_SIZE a 10 y usa un fixture de 50
 * filas: 5 lotes, con los codigos conflictivos repartidos a proposito.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos
 * nombrados, union types, promocion de constructor, readonly, enum ni #[...].
 */
class IncidenteServianTest extends ImportTestCase
{
    const FIXTURE = '06_incidente_servian.xlsx';

    /** @var \App\Models\ImportHistory */
    protected $import;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Esto es lo que hace que el test valga: sin varios lotes, ninguno de
         * los bugs del incidente aparece.
         */
        config(['app.ARTICLE_EXCEL_CHUNK_SIZE' => 10]);

        $this->import = $this->importar(self::FIXTURE);
    }

    /**
     * Guarda de la guarda: si el chunk size no tuvo efecto, el resto de las
     * aserciones pasarian por el motivo equivocado.
     *
     * @return void
     */
    public function test_la_importacion_se_partio_en_varios_lotes()
    {
        $this->assertGreaterThanOrEqual(
            5,
            (int) $this->import->total_chunks,
            'El fixture tiene que partirse en al menos 5 lotes. Si quedo en 1, '
            . 'ARTICLE_EXCEL_CHUNK_SIZE no se aplico y el test no prueba nada.'
        );
    }

    /**
     * El sintoma mas visible: 12 movimientos de stock sobre un mismo articulo
     * en una sola importacion.
     *
     * @return void
     */
    public function test_ningun_articulo_recibe_mas_de_un_movimiento_de_stock()
    {
        $repetidos = StockMovement::query()
            ->where('user_id', $this->tenant->id)
            ->where('created_at', '>=', $this->import->created_at)
            ->groupBy('article_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('article_id')
            ->all();

        $this->assertSame(
            [],
            $repetidos,
            'Hay articulos con mas de un movimiento de stock en una sola '
            . 'importacion. Es exactamente el sintoma del incidente: '
            . 'articulos: ' . implode(', ', $repetidos)
        );
    }

    /**
     * Las cinco filas con provider_code "-" son cinco productos distintos.
     * En el incidente se fusionaron en uno solo.
     *
     * @return void
     */
    public function test_las_filas_con_placeholder_no_se_fusionan()
    {
        $nombres = [
            'CORREA POLY V 5PK698 (HUTCHINSON)',
            'CORREA POLY V 6PK1550 (SKF)',
            'CORREA POLY V CONTINENTAL 4PK775',
            'CORREA POLY V DAYCO 5PK1065',
            'CORREA 7PK-1535 HUTCHINSON',
        ];

        foreach ($nombres as $nombre) {

            $articulos = Article::where('user_id', $this->tenant->id)
                            ->where('name', $nombre)
                            ->get();

            $this->assertCount(
                1,
                $articulos,
                'Se esperaba exactamente un articulo llamado "' . $nombre . '", '
                . 'hay ' . $articulos->count() . '.'
            );
        }

        /* Y ninguno se quedo con el "-" como codigo de proveedor. */
        $con_guion = Article::where('user_id', $this->tenant->id)
                        ->where('provider_code', '-')
                        ->count();

        $this->assertSame(0, $con_guion, 'Quedo un articulo con "-" como codigo de proveedor.');
    }

    /**
     * Cada producto del Excel tiene que existir. En el incidente ~44 productos
     * nunca se crearon: fueron absorbidos.
     *
     * @return void
     */
    public function test_todos_los_productos_del_excel_existen()
    {
        $esperados = [
            'CORREA POLY V 5PK698 (HUTCHINSON)',
            'KIT EMBRAGUE RENAULT MECANICA',
            'KIT EMBRAGUE FIAT MECANICA',
            'FILTRO AIRE VW UP 2014',
            'FILTRO AIRE VOLKSWAGEN (BOSCH)',
            'BUJIA NGK BKR6E',
            'ACEITE 10W40 4L',
            'ACEITE 15W40 4L',
        ];

        foreach ($esperados as $nombre) {
            $this->assertTrue(
                Article::where('user_id', $this->tenant->id)->where('name', $nombre)->exists(),
                'Falta el articulo "' . $nombre . '": fue absorbido por otro.'
            );
        }
    }

    /**
     * Las dos filas THOMPSON estan en el lote 1 y en el lote 5 y comparten
     * bar_code. En el incidente esto creo dos articulos con el mismo codigo.
     *
     * ------------------------------------------------------------------------
     * POR QUE LA CONSULTA SE ACOTA A LOS ARTICULOS CREADOS (no lo revuelvas)
     * ------------------------------------------------------------------------
     * Hasta el 24/8/2026 este test barria TODOS los articulos del tenant. Eso lo
     * hacia imposible de pasar: el escenario sembrado (ImportTestSeeder, lineas
     * 51-52) crea A7 y A8 con el MISMO bar_code '7790007' a proposito -- son los
     * que fijan que el escalon bar_code de ArticleIndexCache de ambiguo -- y el
     * sembrado corre en cada setUp(), antes de importar. La consulta devolvia
     * siempre ['7790007'], importara lo que importara el fixture: el test nacio
     * rojo y nunca pudo medir nada, era un fail() con pasos de mas.
     *
     * Acotar a los articulos creados NO es aflojar la asercion: es hacer que mida
     * lo que su docblock dice, que es que la IMPORTACION no cree dos articulos con
     * el mismo bar_code. Y el unico caso que el acotamiento dejaria afuera -- que
     * un articulo creado choque con el bar_code de uno sembrado -- se cubre
     * explicitamente en la segunda asercion, que antes no existia.
     *
     * @return void
     */
    public function test_no_se_crean_dos_articulos_con_el_mismo_bar_code()
    {
        $creados = $this->articulos_creados();

        /* bar_code => cuantos articulos creados lo tienen. */
        $conteo_por_bar_code = [];

        foreach ($creados as $articulo) {

            $bar_code = (string) $articulo->bar_code;

            if ($bar_code === '') {
                continue;
            }

            if (!isset($conteo_por_bar_code[$bar_code])) {
                $conteo_por_bar_code[$bar_code] = 0;
            }

            $conteo_por_bar_code[$bar_code]++;
        }

        $duplicados = [];

        foreach ($conteo_por_bar_code as $bar_code => $total) {
            if ($total > 1) {
                $duplicados[] = (string) $bar_code;
            }
        }

        $this->assertSame(
            [],
            $duplicados,
            'La importacion creo dos articulos con el mismo codigo de barras: '
            . implode(', ', $duplicados)
        );

        /*
         * Cobertura nueva: un articulo creado tampoco puede quedarse con el
         * bar_code de un articulo que YA existia. Si pasa, la fila tenia que
         * haber matcheado (o reportado ambiguedad) contra el sembrado y en vez de
         * eso creo un duplicado -- que es exactamente el incidente de Servian,
         * solo que contra el catalogo previo en lugar de contra el mismo archivo.
         */
        $bar_codes_sembrados = [];

        foreach ($this->seed as $clave => $sembrado) {

            $bar_code = (string) $sembrado->bar_code;

            if ($bar_code === '') {
                continue;
            }

            $bar_codes_sembrados[$bar_code] = $clave;
        }

        $colisiones = [];

        foreach (array_keys($conteo_por_bar_code) as $bar_code) {

            $bar_code = (string) $bar_code;

            if (isset($bar_codes_sembrados[$bar_code])) {
                $colisiones[] = $bar_code . ' (ya lo tenia ' . $bar_codes_sembrados[$bar_code] . ')';
            }
        }

        $this->assertSame(
            [],
            $colisiones,
            'Un articulo creado por la importacion se quedo con el codigo de barras '
            . 'de un articulo que ya existia: ' . implode(', ', $colisiones)
        );
    }

    /**
     * Las dos filas que comparten bar_code son ambiguas entre si: ninguna se
     * procesa, las dos se reportan.
     *
     * @return void
     */
    public function test_el_bar_code_repetido_entre_lotes_se_reporta()
    {
        $filas = $this->filas_de_conflictos($this->import, 'ambiguo');

        $this->assertContains(46, $filas, 'La fila 46 (THOMPSON, lote 5) tenia que reportarse como ambigua.');
    }

    /**
     * Dos productos con nombre identico y sin codigos: ambiguos en el escalon 5.
     *
     * @return void
     */
    public function test_nombres_identicos_sin_codigo_son_ambiguos()
    {
        $articulos = Article::where('user_id', $this->tenant->id)
                        ->where('name', 'AMORTIGUADOR DELANTERO RENAULT (SACHS)')
                        ->count();

        $this->assertLessThanOrEqual(
            1,
            $articulos,
            'Dos filas con el mismo nombre exacto no pueden terminar en dos '
            . 'articulos creados sin reportar el conflicto.'
        );

        $this->assertNotEmpty(
            $this->conflictos($this->import, 'ambiguo'),
            'No se reporto ningun conflicto ambiguo.'
        );
    }

    /**
     * Los codigos tienen que quedar tal cual estaban en el Excel, sin ".0"
     * ni notacion cientifica.
     *
     * @return void
     */
    public function test_los_codigos_numericos_quedan_literales()
    {
        $bujia = Article::where('user_id', $this->tenant->id)
                    ->where('name', 'BUJIA NGK BKR6E')
                    ->first();

        $this->assertNotNull($bujia, 'No se creo la bujia NGK.');

        $this->assertSame('504346', (string) $bujia->sku, 'El sku quedo casteado.');
        $this->assertSame('7790001234567', (string) $bujia->bar_code, 'El codigo de barras quedo casteado.');

        $bosch = Article::where('user_id', $this->tenant->id)
                    ->where('name', 'BUJIA BOSCH FR7DC')
                    ->first();

        $this->assertNotNull($bosch);
        $this->assertSame('12345678901234', (string) $bosch->sku, 'Sku de 14 digitos casteado a notacion cientifica.');
    }

    /**
     * El mismo costo como texto y como numero tiene que dar identico.
     *
     * @return void
     */
    public function test_el_costo_con_decimales_se_interpreta_igual_como_texto_y_como_numero()
    {
        $texto  = Article::where('user_id', $this->tenant->id)->where('name', 'ACEITE 10W40 4L')->first();
        $numero = Article::where('user_id', $this->tenant->id)->where('name', 'ACEITE 15W40 4L')->first();

        $this->assertNotNull($texto);
        $this->assertNotNull($numero);

        $this->assertDecimal('123123.343240', $texto->cost,  'Costo como texto mal interpretado.');
        $this->assertDecimal('123123.343240', $numero->cost, 'Costo como numero mal interpretado.');

        $this->assertSame(
            (string) $texto->cost,
            (string) $numero->cost,
            'El mismo valor da distinto segun el tipo de celda.'
        );
    }

    /**
     * Separador de miles y simbolo de moneda.
     *
     * @return void
     */
    public function test_separador_de_miles_y_moneda()
    {
        $frenos = Article::where('user_id', $this->tenant->id)->where('name', 'LIQUIDO FRENOS DOT4')->first();
        $refri  = Article::where('user_id', $this->tenant->id)->where('name', 'REFRIGERANTE VERDE')->first();

        $this->assertNotNull($frenos);
        $this->assertNotNull($refri);

        /* "1.234" son grupos de exactamente 3 digitos: separador de miles. */
        $this->assertDecimal('1234.000000', $frenos->cost);

        /* "$ 37468,24": simbolo de moneda y coma decimal. */
        $this->assertDecimal('37468.240000', $refri->cost);
    }

    /**
     * El stock final de cada articulo es el de SU fila del Excel, no el de la
     * ultima fila que lo toco por error.
     *
     * @return void
     */
    public function test_el_stock_final_es_el_del_excel()
    {
        $esperados = [
            'CORREA POLY V 5PK698 (HUTCHINSON)' => 1,
            'CORREA POLY V 6PK1550 (SKF)'       => 3,
            'CORREA POLY V CONTINENTAL 4PK775'  => 5,
            'CORREA POLY V DAYCO 5PK1065'       => 2,
            'CORREA 7PK-1535 HUTCHINSON'        => 7,
            'KIT EMBRAGUE RENAULT MECANICA'     => 4,
            'KIT EMBRAGUE FIAT MECANICA'        => 6,
        ];

        foreach ($esperados as $nombre => $stock) {

            $articulo = Article::where('user_id', $this->tenant->id)->where('name', $nombre)->first();

            $this->assertNotNull($articulo, 'Falta el articulo "' . $nombre . '".');

            $this->assertDecimal(
                $stock,
                $articulo->stock,
                'Stock incorrecto en "' . $nombre . '".'
            );
        }
    }

    /**
     * El diff del detalle del lote tiene que guardar el resultante en 'new'
     * y el delta aparte. En el incidente mostraba "2 -> -1" cuando el
     * resultante era 1.
     *
     * @return void
     */
    public function test_el_diff_de_stock_guarda_resultante_y_delta()
    {
        $movimientos = StockMovement::where('user_id', $this->tenant->id)
                        ->where('created_at', '>=', $this->import->created_at)
                        ->get();

        $this->assertNotEmpty($movimientos, 'La importacion no genero ningun movimiento de stock.');

        foreach ($movimientos as $movimiento) {

            $articulo = Article::find($movimiento->article_id);

            $this->assertDecimal(
                $articulo->stock,
                $movimiento->stock_resultante,
                'El stock_resultante del movimiento no coincide con el stock del articulo.'
            );
        }
    }

    /**
     * Reimportar el mismo archivo no tiene que generar movimientos nuevos:
     * el stock ya esta donde el Excel pide.
     *
     * @return void
     */
    public function test_reimportar_no_genera_movimientos_nuevos()
    {
        $antes = StockMovement::where('user_id', $this->tenant->id)->count();

        /*
         * Grupo 301, prompt 02: filtrar "los movimientos de la segunda importacion" por
         * created_at >= $segunda->created_at (como era antes) es un artefacto de medicion,
         * no un chequeo real. stock_movements.created_at es un TIMESTAMP de Laravel
         * ($table->timestamps()), resolucion de UN SEGUNDO sin fraccion. Si la primera
         * importacion termina de escribir sus movimientos de creacion en el MISMO segundo
         * de reloj en que arranca la segunda, la comparacion >= (inclusiva) cuenta esos
         * movimientos de la PRIMERA corrida como si fueran nuevos -- no es un bug de
         * calculo de stock, es puro azar de en que segundo cae cada corrida (ver
         * diagnostico.md del grupo 301). Un id autoincremental no tiene ese problema:
         * se captura el ultimo id ANTES de la segunda importacion y se filtra estrictamente
         * por encima de ese watermark.
         */
        $ultimo_id_antes = (int) (StockMovement::where('user_id', $this->tenant->id)->max('id') ?? 0);

        $segunda = $this->importar(self::FIXTURE);

        $nuevos = StockMovement::where('user_id', $this->tenant->id)
                    ->where('id', '>', $ultimo_id_antes)
                    ->count();

        $this->assertSame(
            0,
            $nuevos,
            'La segunda importacion del mismo archivo genero ' . $nuevos . ' movimientos. '
            . 'El stock ya estaba en el valor del Excel: no habia nada que mover.'
        );

        $this->assertSame(
            $antes,
            StockMovement::where('user_id', $this->tenant->id)->count()
        );
    }

    /**
     * Ningun articulo tiene que terminar con datos de otro producto.
     *
     * @return void
     */
    public function test_ningun_articulo_queda_con_datos_de_otro_producto()
    {
        $correa = Article::where('user_id', $this->tenant->id)
                    ->where('name', 'CORREA POLY V 5PK698 (HUTCHINSON)')
                    ->first();

        $this->assertNotNull($correa);

        $this->assertSame('SKU-G-01', (string) $correa->sku, 'La correa quedo con el sku de otro producto.');
        $this->assertDecimal('10244.360000', $correa->cost, 'La correa quedo con el costo de otro producto.');
    }
}
