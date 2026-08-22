<?php

namespace Tests\Import;

use App\Models\ExcelAnalysisRun;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

/**
 * La hoja elegida atraviesa el pipeline entero (unidad 3 de la misión de importación
 * de Excel): del request HTTP al payload de la corrida, del payload al analizador, y del
 * endpoint de importación al CSV que después leen los chunks.
 *
 * El defecto que cubre: los lectores del import abrían el libro y cortaban con `break`
 * después de la primera hoja. Un libro con la lista de precios en la hoja 2 se importaba
 * vacío y el usuario no tenía forma de elegir ni de enterarse.
 *
 * Estos dos tests son de PUNTA A PUNTA a propósito. Que ExcelWorkbookReader lea bien la
 * hoja 2 ya lo prueba LecturaDeHojasTest; lo que no prueba nadie más es que la elección
 * del usuario sobreviva los seis saltos que hay entre el navegador y el CSV. Ese es
 * justamente el lugar donde una elección se pierde sin que nada avise.
 *
 * Extiende ImportTestCase (y no Tests\TestCase) porque los dos tests pegan contra
 * endpoints reales que necesitan el tenant sembrado y la sesión autenticada.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos
 * nombrados, union types, promoción de constructor, readonly, enum ni #[...].
 */
class HojaElegidaEnImportacionTest extends ImportTestCase
{
    /**
     * Ruta absoluta a un fixture de tests/Import/fixtures/.
     *
     * @param  string $archivo
     * @return string
     */
    protected function fixture($archivo)
    {
        return __DIR__ . '/fixtures/' . $archivo;
    }

    /**
     * Copia temporal de un fixture, lista para subir.
     *
     * Los endpoints de importación hacen storeAs(), que MUEVE el archivo subido. Si se
     * les pasara el fixture directo, el primer test que corra se lo llevaría de la
     * carpeta y el resto de la suite quedaría sin fixture. Mismo criterio que
     * ImportTestCase::importar().
     *
     * @param  string $archivo
     * @return \Illuminate\Http\UploadedFile
     */
    protected function subida($archivo)
    {
        $origen = $this->fixture($archivo);

        $this->assertFileExists($origen, 'Falta el fixture ' . $archivo);

        $copia = sys_get_temp_dir() . '/' . uniqid('fixture_') . '_' . $archivo;
        copy($origen, $copia);

        return new UploadedFile(
            $copia,
            $archivo,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );
    }

    /**
     * Respuesta falsa de la API de Anthropic, con la forma REAL que espera
     * AiExcelAnalyzer::call_claude() (el JSON de Claude como string en content[0].text).
     *
     * El mapeo devuelto es el de la cabecera común de los fixtures. No importa que sea
     * bueno: lo que estos tests miden es la hoja, no el mapeo.
     *
     * @return array
     */
    protected function respuesta_claude()
    {
        $column_mapping = [];

        $cabecera = [
            'codigo_de_barras'    => 'codigo_de_barras',
            'sku'                 => 'sku',
            'codigo_de_proveedor' => 'codigo_de_proveedor',
            'nombre'              => 'nombre',
            'costo'               => 'costo',
            'precio'              => 'precio',
            'stock_actual'        => 'stock_actual',
            'iva'                 => 'iva',
        ];

        foreach ($cabecera as $excel_column => $system_property) {
            $column_mapping[] = [
                'excel_column'    => $excel_column,
                'system_property' => $system_property,
                'confidence'      => 'alto',
            ];
        }

        return [
            'content' => [
                [
                    'text' => json_encode([
                        'column_mapping'      => $column_mapping,
                        'provider_id'         => null,
                        'provider_confidence' => 'bajo',
                        'assistant_notes'     => [],
                    ]),
                ],
            ],
        ];
    }

    /**
     * Corre el análisis de un fixture contra el endpoint real y devuelve la corrida ya
     * terminada.
     *
     * Con QUEUE_CONNECTION=sync (ver phpunit.xml) el RunExcelAnalysisJob corre inline
     * adentro del POST, así que al volver del request la corrida ya está en estado final.
     *
     * @param  string $archivo  Nombre del fixture
     * @param  array  $extra    Claves nuevas del contrato (hoja, hoja_nombre, header_row)
     * @return \App\Models\ExcelAnalysisRun
     */
    protected function analizar($archivo, array $extra = [])
    {
        config(['services.anthropic.api_key' => 'fake-key']);

        /* Nunca sale a internet: intercepta el POST que hace call_claude(). */
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->respuesta_claude(), 200),
        ]);

        $data = array_merge(
            [
                'excel_file' => $this->subida($archivo),
                'model'      => 'article',
            ],
            $extra
        );

        $respuesta = $this->post('/api/ai-excel-import/analyze', $data);

        $respuesta->assertStatus(202);

        $uuid = $respuesta->json('analysis_uuid');

        $this->assertNotEmpty($uuid, 'El análisis no devolvió analysis_uuid.');

        $run = ExcelAnalysisRun::where('uuid', $uuid)->first();

        $this->assertNotNull($run, 'No quedó la ExcelAnalysisRun del análisis.');

        return $run;
    }

    /* ---------------------------------------------------------------------
     * Test 2a — el análisis ofrece las tres hojas y elige la primera solo.
     * ------------------------------------------------------------------- */

    /**
     * Sin `hoja` en el request —o sea, exactamente lo que manda un cliente viejo: la SPA
     * sin desplegar, o AdminSync— el análisis tiene que devolver las tres hojas del libro
     * y haber trabajado sobre la primera.
     *
     * Las dos mitades importan y por motivos distintos:
     *   - `hojas` con las tres entradas es lo que le permite al usuario ELEGIR. Sin eso,
     *     el selector del paso 1 no se dibuja y volvemos al defecto original.
     *   - `hoja_elegida.indice === 0` es la compatibilidad hacia atrás: quien no elige
     *     nada importa la primera hoja, igual que antes de esta misión.
     *
     * @return void
     */
    public function test_el_analisis_expone_las_tres_hojas_y_por_defecto_toma_la_primera()
    {
        $run = $this->analizar('12_tres_hojas.xlsx');

        $this->assertSame(
            'listo',
            $run->estado,
            'El análisis no terminó bien. Error guardado: ' . (string) $run->error
        );

        $resultado = $run->resultado;

        $this->assertIsArray($resultado);

        /*
         * Los nombres de estas claves están congelados: la SPA ya está codeada contra
         * ellos. Si alguna se renombra en el camino, el modal no rompe — se queda mudo, que
         * es peor: el selector no aparece y el usuario importa a ciegas.
         */
        $this->assertArrayHasKey('hojas', $resultado);
        $this->assertArrayHasKey('hoja_elegida', $resultado);

        $this->assertCount(
            3,
            $resultado['hojas'],
            'El libro tiene tres hojas y las tres se tienen que ofrecer.'
        );

        $nombres = [];
        foreach ($resultado['hojas'] as $hoja) {
            $nombres[] = $hoja['nombre'];
        }

        $this->assertSame(['Lista de precios', 'Notas', 'Resumen'], $nombres);

        $this->assertSame(
            0,
            (int) $resultado['hoja_elegida']['indice'],
            'Sin elección explícita, el análisis tiene que trabajar sobre la primera hoja.'
        );

        /*
         * El contexto es lo que el modal usa para rearmarse después de un F5 o desde otra
         * pestaña. Con los defaults del contrato: hoja 0, sin nombre, sin fila de encabezado.
         */
        $contexto = $this->getJson('/api/ai-excel-import/analysis/' . $run->uuid)->json('contexto');

        $this->assertSame(0, $contexto['hoja']);
        $this->assertNull($contexto['hoja_nombre']);
        $this->assertNull($contexto['header_row']);
    }

    /* ---------------------------------------------------------------------
     * Test 2b — la elección llega hasta el CSV. El importante.
     * ------------------------------------------------------------------- */

    /**
     * Con `hoja = 1`, el CSV que arma InitExcelImport tiene el contenido de "Notas" y NO
     * el de "Lista de precios".
     *
     * Este es el test que prueba que el `break` murió de verdad. Los helpers pueden leer
     * perfecto la hoja 2 y la elección igual perderse en cualquiera de los saltos que hay
     * entre el request y el CSV — y perderse ahí no se ve: la importación termina bien, con
     * su tilde verde, importando la planilla equivocada.
     *
     * Se mira el CSV y no los artículos creados porque el CSV es el artefacto del que
     * después leen los chunks: build_csv_chunk_offsets() y armar_jobs_de_chunks() navegan
     * ese archivo por número de línea. Si ahí adentro está la hoja que no era, todo lo que
     * viene después está mal por más que cada paso individual funcione.
     *
     * @return void
     */
    public function test_la_hoja_elegida_es_la_que_termina_en_el_csv_de_la_importacion()
    {
        $contenido = $this->csv_de_una_importacion('12_tres_hojas.xlsx', 1);

        $this->assertStringContainsString(
            'Estas son notas internas del vendedor',
            $contenido,
            'El CSV no tiene el contenido de la hoja "Notas", que es la que se eligió.'
        );

        $this->assertStringContainsString('Actualizado por Marcela el 12 de agosto', $contenido);

        /*
         * La contraparte, que es la que de verdad pincha el defecto: si la elección se
         * hubiera perdido en el camino, el CSV tendría la hoja 0 y esto pasaría igual que
         * antes de la misión, sin que nada más lo denuncie.
         */
        $this->assertStringNotContainsString(
            'Art unico prov A EDITADO',
            $contenido,
            'El CSV tiene datos de "Lista de precios": la hoja elegida se perdió en el camino.'
        );

        $this->assertStringNotContainsString(
            'codigo_de_proveedor',
            $contenido,
            'El CSV tiene la cabecera de "Lista de precios": se volcó la hoja equivocada.'
        );

    }

    /**
     * La contraparte de compatibilidad: sin `hoja` en el FormData —o sea, la SPA sin
     * desplegar— el mismo endpoint tiene que volcar la primera hoja, igual que siempre.
     *
     * Va como test aparte y no como un segundo tramo del anterior porque el nombre del CSV
     * lleva time() en segundos: dos importaciones seguidas dentro del mismo segundo se
     * pisan el archivo, y el test empieza a medir el volcado equivocado sin fallar por eso.
     *
     * @return void
     */
    public function test_sin_elegir_hoja_la_importacion_vuelca_la_primera_como_siempre()
    {
        $contenido = $this->csv_de_una_importacion('12_tres_hojas.xlsx', null);

        $this->assertStringContainsString(
            'Art unico prov A EDITADO',
            $contenido,
            'Sin elegir hoja, la importación tiene que volcar la primera, igual que siempre.'
        );

        $this->assertStringNotContainsString(
            'Estas son notas internas del vendedor',
            $contenido,
            'Sin elegir hoja se volcó una hoja que no es la primera.'
        );
    }

    /**
     * Corre una importación contra el endpoint clásico y devuelve el contenido del CSV que
     * armó InitExcelImport.
     *
     * El archivo se deja en storage con un nombre único (uniqid) y se pasa por
     * `archivo_excel_path` en vez de subirlo como `models`. No es un atajo: el nombre del
     * CSV se deriva del nombre del Excel más time() en segundos, así que con el
     * `import_<time>.xlsx` que arma el endpoint al subir, dos importaciones del mismo
     * segundo generan el MISMO nombre de CSV y una pisa a la otra. Con un nombre único de
     * entrada, el CSV de esta importación es identificable sin ambigüedad y sin adivinar
     * por fecha de modificación.
     *
     * @param  string   $archivo  Nombre del fixture
     * @param  int|null $hoja     Índice 0-based, o null para no mandar la clave (cliente viejo)
     * @return string             Contenido del CSV
     */
    protected function csv_de_una_importacion($archivo, $hoja)
    {
        $origen = $this->fixture($archivo);

        $this->assertFileExists($origen, 'Falta el fixture ' . $archivo);

        $carpeta = storage_path('app/imported_files');

        if (!is_dir($carpeta)) {
            mkdir($carpeta, 0777, true);
        }

        $nombre  = uniqid('hoja_elegida_') . '.xlsx';
        $destino = $carpeta . '/' . $nombre;

        copy($origen, $destino);

        $data = array_merge(
            [
                'archivo_excel_path' => 'imported_files/' . $nombre,
                'start_row'          => 2,
                /* InitExcelImport::ajustar_finish_row_segun_excel_real() lo baja al real. */
                'finish_row'         => 99999,
                'provider_id'        => null,
            ],
            self::config_por_defecto(),
            self::columnas()
        );

        /*
         * Con $hoja en null la clave NO se manda. Es la diferencia que importa: probar el
         * default del backend no es lo mismo que mandar 'hoja' => 0.
         */
        if (!is_null($hoja)) {
            $data['hoja'] = $hoja;
        }

        $this->postJson('/api/article/excel/import', $data)->assertStatus(200);

        $csvs = glob($carpeta . '/' . pathinfo($nombre, PATHINFO_FILENAME) . '_*.csv');

        $this->assertCount(
            1,
            is_array($csvs) ? $csvs : [],
            'La importación tenía que dejar exactamente un CSV.'
        );

        $contenido = file_get_contents($csvs[0]);

        /* La cola corrió inline (QUEUE_CONNECTION=sync): nadie más va a leer estos dos archivos. */
        @unlink($csvs[0]);
        @unlink($destino);

        return $contenido;
    }
}
