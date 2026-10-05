<?php

namespace Tests\Import;

use App\Models\Article;
use App\Models\ArticleImportResult;
use App\Models\ArticleImportResultObservation;

/**
 * Una celda con salto de línea (Alt+Enter) no puede desalinear los lotes de la importación
 * (misión importacion-celda-multilinea, 4/10/2026).
 *
 * El defecto: el volcado XLSX -> CSV escribe UN REGISTRO CSV por fila del Excel, pero una celda
 * multilínea sale entrecomillada y ocupa varias LÍNEAS FÍSICAS. Los offsets de cada lote
 * (InitExcelImport::build_csv_chunk_offsets()) se calculaban contando líneas físicas con
 * fgets(), y cada lote (ProcessArticleChunk::get_row_from_csv()) cuenta registros con fgetcsv()
 * desde ese offset. Con N saltos de línea antes de un lote, el lote arrancaba N filas antes:
 * filas leídas dos veces (y matcheadas contra el artículo que la misma importación acababa de
 * crear), todo corrido N lugares, y las últimas N filas del archivo sin leer nunca.
 *
 * Y con el encabezado multilínea —lo más común en una lista de proveedor— se rompía hasta el
 * lote 1: con start_row = 2 el lote 1 siempre tiene offset, y caía en el medio del encabezado.
 *
 * Como IncidenteServianTest, baja ARTICLE_EXCEL_CHUNK_SIZE a 10: con un solo lote no hay
 * offsets que se desalineen y el test no probaría nada.
 *
 * Fixtures (ver fixtures/generar_celdas_multilinea.php):
 *
 *   28_celda_multilinea_en_los_datos.xlsx   cabecera común en la 1, datos 2 a 26 (ML-02..ML-26,
 *                                           costo 1000 + fila); un salto en el nombre de la
 *                                           fila 5 y dos en el de la 8; costo "consultar" en
 *                                           15 y 24; el nombre de la 3 termina en barra
 *                                           invertida (guarda del escape vacío). Lotes [2-11],
 *                                           [12-21], [22-26].
 *   29_encabezado_multilinea.xlsx           encabezado con saltos en A1 ("codigo de barras") y
 *                                           E1 ("costo sin IVA"), datos 2 a 13 (EM-02..EM-13,
 *                                           costo 2000 + fila). Lotes [2-11], [12-13]. A1 es la
 *                                           celda pegada al BOM del CSV: ver el generador.
 *   30_sin_encabezado_a1_multilinea.xlsx    sin encabezado (start_row = 1), A1 multilínea,
 *                                           datos 1 a 12 (SE-01..SE-12, costo 3000 + fila),
 *                                           columnas nombre / codigo_de_proveedor / costo / iva.
 *                                           Lotes [1-10], [11-12].
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos
 * nombrados, union types, promoción de constructor, readonly, enum ni #[...].
 */
class CeldaConSaltoDeLineaTest extends ImportTestCase
{
    const FIXTURE_DATOS          = '28_celda_multilinea_en_los_datos.xlsx';
    const FIXTURE_ENCABEZADO     = '29_encabezado_multilinea.xlsx';
    const FIXTURE_SIN_ENCABEZADO = '30_sin_encabezado_a1_multilinea.xlsx';

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Esto es lo que hace que el test valga: sin varios lotes no hay offsets, y sin
         * offsets el conteo por líneas físicas no tiene dónde equivocarse.
         */
        config(['app.ARTICLE_EXCEL_CHUNK_SIZE' => 10]);
    }

    /**
     * Guarda de la guarda: si el chunk size no tuvo efecto, el resto de las aserciones
     * pasarían por el motivo equivocado.
     *
     * @return void
     */
    public function test_los_dos_fixtures_se_parten_en_varios_lotes()
    {
        $import_28 = $this->importar(self::FIXTURE_DATOS);

        $this->assertSame(
            3,
            (int) $import_28->total_chunks,
            'El fixture 28 (filas 2 a 26) tiene que partirse en 3 lotes de 10. Si no, '
            . 'ARTICLE_EXCEL_CHUNK_SIZE no se aplicó y el test no prueba nada.'
        );

        $import_29 = $this->importar(self::FIXTURE_ENCABEZADO);

        $this->assertSame(
            2,
            (int) $import_29->total_chunks,
            'El fixture 29 (filas 2 a 13) tiene que partirse en 2 lotes de 10.'
        );
    }

    /**
     * Cada fila del Excel se procesa exactamente una vez: ni leída dos veces por dos lotes, ni
     * salteada al final del archivo.
     *
     * @return void
     */
    public function test_cada_fila_del_excel_se_procesa_una_sola_vez()
    {
        $import = $this->importar(self::FIXTURE_DATOS);

        /*
         * Ojo: esta aserción sola NO detecta el corrimiento. ArticleImport rotula cada registro
         * del lote como start_row + su posición, así que un lote corrido también sale rotulado
         * 2..26 (medido sin el arreglo: daba verde). Lo que mira es que el detalle por lote tenga
         * un renglón por fila, sin huecos ni repetidos en los rótulos. Que cada fila del Excel se
         * haya leído una vez lo prueban los buckets y los códigos de abajo.
         */
        $this->assertSame(
            range(2, 26),
            $this->filas_del_detalle_por_lote($import),
            'El detalle por fila de los lotes tiene que tener un renglón por fila del Excel (2 a 26).'
        );

        /*
         * Una fila leída dos veces matchea, en el segundo lote, contra el artículo que creó el
         * primero: aparece en un bucket de match. Con cada fila leída una vez, las 25 son nuevas.
         */
        $this->assertBuckets($import, ['creado_nuevo' => 25]);

        $creados = $this->articulos_creados();

        $this->assertCount(25, $creados, 'Tienen que crearse 25 artículos, uno por fila de datos.');

        $esperados = [];

        for ($fila = 2; $fila <= 26; $fila++) {
            $esperados[] = 'ML-' . str_pad((string) $fila, 2, '0', STR_PAD_LEFT);
        }

        $obtenidos = $creados->pluck('provider_code')->map(function ($codigo) {
            return (string) $codigo;
        })->sort()->values()->all();

        $this->assertSame($esperados, $obtenidos, 'Tiene que haber exactamente un artículo por código ML-02 a ML-26.');
    }

    /**
     * La última fila del archivo es la primera que se perdía: con N saltos de línea antes del
     * último lote, las últimas N filas no las leía nadie.
     *
     * @return void
     */
    public function test_la_ultima_fila_se_importa_con_su_costo()
    {
        $this->importar(self::FIXTURE_DATOS);

        $ultima = $this->articulo_por_codigo('ML-26');

        $this->assertNotNull($ultima, 'La fila 26 (ML-26), la última del archivo, no se importó.');
        $this->assertSame('7794280000026', (string) $ultima->bar_code);
        $this->assertDecimal('1026', $ultima->cost, 'La fila 26 no quedó con su costo.');
    }

    /**
     * El registro de la celda multilínea se lee entero: el nombre trae todas sus partes, y el
     * costo (la columna que viene DESPUÉS del nombre) es el de su fila, o sea que el registro no
     * se cortó en el salto de línea.
     *
     * No se aserta el nombre byte a byte: lo que el sistema promete es que la celda no se
     * pierde ni se parte, no un formato de salto de línea en particular.
     *
     * @return void
     */
    public function test_la_celda_multilinea_llega_entera_al_articulo()
    {
        $this->importar(self::FIXTURE_DATOS);

        $casos = [
            'ML-05' => ['partes' => ['TORNILLO AUTOPERFORANTE 8x1', 'CAJA X 100'],             'costo' => '1005'],
            'ML-08' => ['partes' => ['MECHA ACERO RAPIDO 8mm', 'PARA METAL', 'BLISTER X 2'], 'costo' => '1008'],
        ];

        foreach ($casos as $codigo => $caso) {

            $articulo = $this->articulo_por_codigo($codigo);

            $this->assertNotNull($articulo, 'No se creó el artículo de la celda multilínea ' . $codigo . '.');

            foreach ($caso['partes'] as $parte) {
                $this->assertNotFalse(
                    strpos((string) $articulo->name, $parte),
                    'El nombre de ' . $codigo . ' perdió "' . $parte . '": quedó ' . json_encode((string) $articulo->name) . '.'
                );
            }

            $this->assertDecimal($caso['costo'], $articulo->cost, 'El costo de ' . $codigo . ' no es el de su fila.');
        }

        /*
         * La fila 3 termina en barra invertida (la guarda del escape vacío, ver el generador): el
         * registro se lee entero, con la barra, y la fila queda con su costo.
         */
        $perfil = $this->articulo_por_codigo('ML-03');

        $this->assertNotNull($perfil, 'No se creó el artículo de la fila 3 (ML-03), la que termina en barra invertida.');
        $this->assertSame('\\', substr((string) $perfil->name, -1), 'El nombre de ML-03 perdió la barra final: quedó ' . json_encode((string) $perfil->name) . '.');
        $this->assertDecimal('1003', $perfil->cost, 'El costo de ML-03 no es el de su fila.');

        /* Y las filas que vienen después de las celdas multilínea quedan con su propio costo. */
        foreach ([9 => '1009', 12 => '1012', 21 => '1021', 22 => '1022'] as $fila => $costo) {

            $codigo   = 'ML-' . str_pad((string) $fila, 2, '0', STR_PAD_LEFT);
            $articulo = $this->articulo_por_codigo($codigo);

            $this->assertNotNull($articulo, 'Falta el artículo de la fila ' . $fila . ' (' . $codigo . ').');
            $this->assertDecimal($costo, $articulo->cost, 'El artículo de la fila ' . $fila . ' no quedó con su costo.');
        }
    }

    /**
     * Los costos no numéricos se reportan en su fila real del Excel: la 15 (lote 2) y la 24
     * (lote 3), las dos DESPUÉS de las celdas multilínea.
     *
     * Ojo: que `import_conflicts.fila` sea la fila del Excel (y no la fila del Excel − 1) lo
     * arregló la misión `fila-sobrescrita-corrida`. Sin esa misión, esta aserción da [14, 23]
     * aunque los lotes estén bien alineados.
     *
     * @return void
     */
    public function test_los_costos_invalidos_se_reportan_en_su_fila_real()
    {
        $import = $this->importar(self::FIXTURE_DATOS);

        $this->assertSame(
            [15, 24],
            $this->filas_de_conflictos($import, 'numero_invalido'),
            'El costo "consultar" está en las filas 15 y 24 del Excel.'
        );
    }

    /**
     * Encabezado con saltos de línea: las 12 filas de datos se importan, cada una una sola vez y
     * con sus propios datos.
     *
     * @return void
     */
    public function test_con_encabezado_multilinea_se_importan_todas_las_filas()
    {
        $import = $this->importar(self::FIXTURE_ENCABEZADO);

        /* Sólo los rótulos del detalle (ver test_cada_fila_del_excel_se_procesa_una_sola_vez). */
        $this->assertSame(
            range(2, 13),
            $this->filas_del_detalle_por_lote($import),
            'El detalle por fila de los lotes tiene que tener un renglón por fila del Excel (2 a 13).'
        );

        $this->assertBuckets($import, ['creado_nuevo' => 12]);

        $this->assertCount(12, $this->articulos_creados(), 'Tienen que crearse 12 artículos, uno por fila de datos.');

        for ($fila = 2; $fila <= 13; $fila++) {

            $rr       = str_pad((string) $fila, 2, '0', STR_PAD_LEFT);
            $articulo = $this->articulo_por_codigo('EM-' . $rr);

            $this->assertNotNull($articulo, 'La fila ' . $fila . ' (EM-' . $rr . ') no se importó.');
            $this->assertSame('ARTICULO ENCABEZADO FILA ' . $rr, (string) $articulo->name);
            $this->assertDecimal((string) (2000 + $fila), $articulo->cost, 'La fila ' . $fila . ' no quedó con su costo.');
        }
    }

    /**
     * Encabezado con saltos de línea: ningún artículo creado por la importación se queda con un
     * pedazo del encabezado, en ninguna columna. Es lo que pasaba cuando el offset del lote 1 caía
     * en el medio del registro del encabezado.
     *
     * Se mira sólo lo creado: el escenario sembrado tiene artículos con "nombre" en el nombre
     * ("Art solo por nombre") que no vienen del archivo.
     *
     * @return void
     */
    public function test_con_encabezado_multilinea_ningun_articulo_tiene_texto_del_encabezado()
    {
        $this->importar(self::FIXTURE_ENCABEZADO);

        $textos_del_encabezado = ['codigo', 'de barras', 'nombre', 'sin IVA', 'precio', 'stock_actual'];

        foreach ($this->articulos_creados() as $articulo) {

            $columnas = [
                'name'          => (string) $articulo->name,
                'bar_code'      => (string) $articulo->bar_code,
                'sku'           => (string) $articulo->sku,
                'provider_code' => (string) $articulo->provider_code,
            ];

            foreach ($columnas as $columna => $valor) {
                foreach ($textos_del_encabezado as $texto) {
                    $this->assertFalse(
                        stripos($valor, $texto) !== false,
                        'El artículo ' . $articulo->id . ' se quedó con ' . json_encode($valor) . ' en ' . $columna
                        . ': es texto del encabezado ("' . $texto . '").'
                    );
                }
            }
        }
    }

    /**
     * Sin encabezado (start_row = 1) y con la primera celda del archivo multilínea: el lote que
     * arranca en la fila 1 lee las 10 filas que le tocan, cada una con sus datos.
     *
     * Es el resto del mismo defecto que encontró el chequeo independiente: con el offset de la
     * fila 1 en el byte 0, el BOM queda pegado a la comilla de A1, fgetcsv() parte ese registro en
     * dos, el lote 1 se corre un lugar y la fila 10 no la lee nadie.
     *
     * @return void
     */
    public function test_sin_encabezado_y_a1_multilinea_el_lote_de_la_fila_1_no_se_corre()
    {
        $import = $this->importar(self::FIXTURE_SIN_ENCABEZADO, [
            'start_row'                => 1,
            'prop_codigo_de_barras'    => -1,
            'prop_sku'                 => -1,
            'prop_nombre'              => 1,
            'prop_codigo_de_proveedor' => 2,
            'prop_costo'               => 3,
            'prop_precio'              => -1,
            'prop_stock_actual'        => -1,
            'prop_iva'                 => 4,
        ]);

        $this->assertSame(2, (int) $import->total_chunks, 'El fixture 30 tiene que partirse en 2 lotes: [1-10] y [11-12].');

        $this->assertBuckets($import, ['creado_nuevo' => 12]);

        $this->assertCount(12, $this->articulos_creados(), 'Tienen que crearse 12 artículos, uno por fila.');

        for ($fila = 1; $fila <= 12; $fila++) {

            $rr       = str_pad((string) $fila, 2, '0', STR_PAD_LEFT);
            $articulo = $this->articulo_por_codigo('SE-' . $rr);

            $this->assertNotNull($articulo, 'La fila ' . $fila . ' (SE-' . $rr . ') no se importó.');
            $this->assertDecimal((string) (3000 + $fila), $articulo->cost, 'La fila ' . $fila . ' no quedó con su costo.');
        }

        /* A1 entera: las dos partes, sin el BOM ni la comilla del CSV pegados. */
        $nombre = (string) $this->articulo_por_codigo('SE-01')->name;

        foreach (['LISTA SIN ENCABEZADO', 'ARTICULO SIN ENCABEZADO FILA 01'] as $parte) {
            $this->assertNotFalse(strpos($nombre, $parte), 'El nombre de SE-01 perdió "' . $parte . '": quedó ' . json_encode($nombre) . '.');
        }

        $this->assertSame(0, strpos($nombre, 'LISTA'), 'El nombre de SE-01 tiene algo pegado adelante: quedó ' . json_encode($nombre) . '.');
        $this->assertFalse(strpos($nombre, '"') !== false, 'El nombre de SE-01 se quedó con una comilla del CSV: ' . json_encode($nombre) . '.');
    }

    /**
     * Filas del detalle por fila de todos los lotes de una importación (article_import_result_
     * observations), ordenadas ascendente. Ya en develop son la fila del Excel.
     *
     * @param  \App\Models\ImportHistory $import
     * @return array  [int, int, ...]
     */
    protected function filas_del_detalle_por_lote($import)
    {
        $ids_de_lotes = ArticleImportResult::where('import_history_id', $import->id)->pluck('id');

        return ArticleImportResultObservation::whereIn('article_import_result_id', $ids_de_lotes)
                    ->orderBy('fila')
                    ->pluck('fila')
                    ->map(function ($fila) {
                        return (int) $fila;
                    })
                    ->values()
                    ->all();
    }

    /**
     * @param  string $provider_code
     * @return \App\Models\Article|null
     */
    protected function articulo_por_codigo($provider_code)
    {
        return Article::where('user_id', $this->tenant->id)
                    ->where('provider_code', $provider_code)
                    ->first();
    }
}
