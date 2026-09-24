<?php

namespace Tests\Feature\Import;

use App\Http\Controllers\Helpers\import\article\ProviderImportMappingHelper;
use App\Models\Address;
use App\Models\ExcelAnalysisRun;
use App\Models\ProviderImportMapping;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use OpenSpout\Writer\Common\Creator\WriterEntityFactory;
use Tests\Import\ImportTestCase;

/**
 * Configuración de columnas guardada por proveedor (misión importacion-excel-motor-rapido,
 * 24/9/2026).
 *
 * Lo que pidió Lucas: el usuario confirma el paso 2 del modal (columnas revisadas o corregidas,
 * y el proveedor del archivo) y eso se guarda; la próxima vez que importa un Excel de ese
 * proveedor, la IA lo tiene de referencia. El caso concreto: la columna "Precio" del proveedor
 * es el COSTO del negocio; el usuario lo corrige una vez y a partir de ahí se recomienda así.
 *
 * Los tests pegan contra los endpoints reales (analyze, get-recomendacion,
 * refresh-provider-stats) con Claude fakeado: la respuesta del análisis siempre dice que
 * "Precio" es `precio`, que es justamente lo que la configuración guardada tiene que corregir.
 * Con QUEUE_CONNECTION=sync (phpunit.xml) el RunExcelAnalysisJob corre inline en el POST.
 *
 * Extiende ImportTestCase por el tenant 900 y los proveedores A/B/C que siembra.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class MapeoPorProveedorTest extends ImportTestCase
{
    /** Encabezados del "archivo del proveedor" de todos los tests. */
    const ENCABEZADOS = ['Codigo', 'Descripcion', 'Precio', 'Stock'];

    /** Rutas de los archivos que quedaron en storage y hay que borrar al terminar. */
    protected $archivos_a_borrar = [];

    /**
     * Respuesta de análisis que devuelve el fake de Anthropic en este momento.
     *
     * 🔴 Http::fake() no reemplaza los stubs anteriores: los ACUMULA, y responde el primero que
     * devuelve algo. Llamarlo dos veces en un test deja ganando siempre al primero. Por eso el
     * fake se registra una sola vez por test y lee la respuesta vigente de acá.
     *
     * @var array|null
     */
    protected $respuesta_de_analisis_vigente = null;

    /** @var bool Si el fake de Anthropic ya se registró en este test. */
    protected $claude_fakeado = false;

    protected function tearDown(): void
    {
        foreach ($this->archivos_a_borrar as $ruta) {
            @unlink($ruta);
        }

        parent::tearDown();
    }

    /* =====================================================================
     * Helpers
     * ================================================================== */

    /**
     * Escribe un xlsx temporal con los encabezados y filas indicados y devuelve su ruta.
     *
     * @param  array $encabezados
     * @param  array $filas
     * @return string
     */
    protected function xlsx(array $encabezados, array $filas = null)
    {
        if (is_null($filas)) {
            $filas = [
                ['A-001', 'Tornillo 3x20', 100.5, 10],
                ['A-002', 'Tuerca 8mm', 20, 4],
                ['A-003', 'Arandela plana', 5.25, 0],
            ];
        }

        $ruta = sys_get_temp_dir() . '/' . uniqid('mapeo_proveedor_') . '.xlsx';

        $writer = WriterEntityFactory::createXLSXWriter();
        $writer->openToFile($ruta);
        $writer->addRow(WriterEntityFactory::createRowFromArray($encabezados));

        foreach ($filas as $fila) {
            $writer->addRow(WriterEntityFactory::createRowFromArray($fila));
        }

        $writer->close();

        return $ruta;
    }

    /**
     * Respuesta falsa de Anthropic con la forma real (el JSON de Claude como string en
     * content[0].text). Por defecto: Codigo → codigo_de_proveedor, Descripcion → nombre,
     * Precio → PRECIO (lo que la configuración guardada tiene que corregir), Stock → stock_actual.
     *
     * @param  array       $mapeo        [encabezado => system_property]
     * @param  int|null    $provider_id
     * @param  string      $confianza
     * @return array
     */
    protected function respuesta_de_analisis(array $mapeo = [], $provider_id = null, $confianza = 'bajo')
    {
        if (empty($mapeo)) {
            $mapeo = [
                'Codigo'      => 'codigo_de_proveedor',
                'Descripcion' => 'nombre',
                'Precio'      => 'precio',
                'Stock'       => 'stock_actual',
            ];
        }

        $column_mapping = [];

        foreach ($mapeo as $excel_column => $system_property) {
            $column_mapping[] = [
                'excel_column'        => $excel_column,
                'system_property'     => $system_property,
                'confidence'          => 0.9,
                'interpretation_note' => null,
            ];
        }

        return [
            'content' => [
                [
                    'text' => json_encode([
                        'column_mapping'      => $column_mapping,
                        'provider_id'         => $provider_id,
                        'provider_confidence' => $confianza,
                        'assistant_notes'     => [],
                    ]),
                ],
            ],
        ];
    }

    /**
     * Intercepta TODO lo que va a Anthropic: el prompt del análisis recibe la respuesta de
     * análisis; el de la recomendación (se reconoce porque nombra politica_colision) recibe
     * una recomendación válida. Nunca sale a internet.
     *
     * @param  array $respuesta_de_analisis
     * @return void
     */
    protected function fakear_claude(array $respuesta_de_analisis)
    {
        $this->respuesta_de_analisis_vigente = $respuesta_de_analisis;

        if ($this->claude_fakeado) {
            return;
        }

        $this->claude_fakeado = true;

        config(['services.anthropic.api_key' => 'fake-key']);

        Http::fake(function ($request) {
            $respuesta_de_analisis = $this->respuesta_de_analisis_vigente;

            $prompt = $this->prompt_de($request);

            if (strpos($prompt, 'politica_colision') !== false) {
                return Http::response([
                    'content' => [
                        ['text' => json_encode([
                            'politica_colision'      => 'saltear_y_reportar',
                            'politica_intra_archivo' => 'ultima_gana',
                            'explicacion'            => 'Recomendación de prueba.',
                        ])],
                    ],
                ], 200);
            }

            return Http::response($respuesta_de_analisis, 200);
        });
    }

    /**
     * Texto del prompt de un request interceptado (messages[0].content).
     *
     * @param  \Illuminate\Http\Client\Request $request
     * @return string
     */
    protected function prompt_de($request)
    {
        $data = $request->data();

        return (string) ($data['messages'][0]['content'] ?? '');
    }

    /**
     * Corre el análisis de un xlsx contra el endpoint real y devuelve la corrida terminada.
     *
     * @param  string $ruta_xlsx
     * @return \App\Models\ExcelAnalysisRun
     */
    protected function analizar($ruta_xlsx)
    {
        $subida = new UploadedFile(
            $ruta_xlsx,
            'Lista_Proveedor.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );

        $respuesta = $this->post('/api/ai-excel-import/analyze', [
            'excel_file' => $subida,
            'model'      => 'article',
        ]);

        $respuesta->assertStatus(202);

        $run = ExcelAnalysisRun::where('uuid', $respuesta->json('analysis_uuid'))->first();

        $this->assertNotNull($run, 'No quedó la ExcelAnalysisRun del análisis.');

        $run->refresh();

        $this->assertSame('listo', $run->estado, 'El análisis no terminó bien. Error guardado: ' . (string) $run->error);

        $this->archivos_a_borrar[] = storage_path('app/' . $run->excel_path);

        return $run;
    }

    /**
     * Confirma el paso 2 (POST get-recomendacion) con el mapeo del análisis, aplicando los
     * cambios indicados, y devuelve la corrida de recomendación.
     *
     * @param  \App\Models\ExcelAnalysisRun $analisis
     * @param  int|null                     $provider_id
     * @param  array                        $cambios   [encabezado => system_property nuevo]
     * @return \App\Models\ExcelAnalysisRun
     */
    protected function confirmar(ExcelAnalysisRun $analisis, $provider_id, array $cambios = [])
    {
        $column_mapping = [];
        $provider_code_column_index = null;

        foreach ($analisis->resultado['column_mapping'] as $col) {
            $item = [
                'excel_column'        => $col['excel_column'],
                'system_property'     => $col['system_property'],
                'confidence'          => $col['confidence'],
                'interpretation_note' => $col['interpretation_note'],
                'excel_column_index'  => $col['excel_column_index'],
                'excel_column_letter' => $col['excel_column_letter'],
                'mapeo_guardado'      => $col['mapeo_guardado'] ?? null,
            ];

            if (array_key_exists($col['excel_column'], $cambios)) {
                $item['system_property'] = $cambios[$col['excel_column']];
            }

            if ($item['system_property'] === 'codigo_de_proveedor') {
                $provider_code_column_index = $item['excel_column_index'];
            }

            $column_mapping[] = $item;
        }

        $respuesta = $this->postJson('/api/ai-excel-import/get-recomendacion', [
            'excel_path'                 => $analisis->excel_path,
            'provider_id'                => $provider_id,
            'provider_code_column_index' => $provider_code_column_index,
            'column_mapping'             => $column_mapping,
            'analysis_uuid'              => $analisis->uuid,
            'hoja'                       => 0,
            'header_row'                 => 1,
        ]);

        $respuesta->assertStatus(202);

        $run = ExcelAnalysisRun::where('uuid', $respuesta->json('analysis_uuid'))->first();

        $this->assertNotNull($run, 'No quedó la ExcelAnalysisRun de la recomendación.');

        return $run->refresh();
    }

    /**
     * @param  int $provider_id
     * @return \App\Models\ProviderImportMapping|null
     */
    protected function configuracion_guardada($provider_id)
    {
        return ProviderImportMapping::where('user_id', $this->tenant->id)
            ->where('provider_id', $provider_id)
            ->first();
    }

    /**
     * Ítem del column_mapping de un resultado por encabezado.
     *
     * @param  array  $column_mapping
     * @param  string $excel_column
     * @return array
     */
    protected function columna(array $column_mapping, $excel_column)
    {
        foreach ($column_mapping as $col) {
            if (($col['excel_column'] ?? null) === $excel_column) {
                return $col;
            }
        }

        $this->fail('No hay columna «' . $excel_column . '» en el mapeo.');
    }

    /**
     * Entrada guardada por encabezado dentro de column_mapping de la configuración.
     *
     * @param  \App\Models\ProviderImportMapping $mapeo
     * @param  string                            $excel_column
     * @return array
     */
    protected function guardada(ProviderImportMapping $mapeo, $excel_column)
    {
        return $this->columna($mapeo->column_mapping, $excel_column);
    }

    /* =====================================================================
     * 1. Guardar al confirmar el paso 2
     * ================================================================== */

    /**
     * El click "Confirmar y configurar importación" con un proveedor elegido guarda la
     * configuración: las columnas no ignoradas, con «Precio» marcada como corregida (la IA
     * dijo precio, el usuario puso costo) y el resto como confirmadas.
     *
     * @return void
     */
    public function test_se_guarda_al_confirmar_el_paso_2_con_proveedor()
    {
        $this->fakear_claude($this->respuesta_de_analisis());

        $analisis = $this->analizar($this->xlsx(self::ENCABEZADOS));

        $this->assertNull($this->configuracion_guardada($this->providers['A']->id), 'Todavía no tenía que haber configuración.');

        $this->confirmar($analisis, $this->providers['A']->id, ['Precio' => 'costo']);

        $mapeo = $this->configuracion_guardada($this->providers['A']->id);

        $this->assertNotNull($mapeo, 'Confirmar el paso 2 con proveedor tenía que guardar la configuración.');
        $this->assertSame('article', $mapeo->model_name);
        $this->assertSame(1, $mapeo->veces_usado);
        $this->assertSame(1, $mapeo->columnas_corregidas);
        $this->assertSame(1, $mapeo->header_row);
        $this->assertNotNull($mapeo->ultimo_uso_at);
        $this->assertCount(4, $mapeo->column_mapping);

        /* La firma: sha1 de los encabezados normalizados, en orden. */
        $this->assertSame(sha1("codigo\ndescripcion\nprecio\nstock"), $mapeo->headers_hash);

        $precio = $this->guardada($mapeo, 'Precio');
        $this->assertSame('costo', $precio['system_property']);
        $this->assertTrue($precio['corregida'], '«Precio» pasó de precio a costo: es una corrección.');
        $this->assertSame(2, $precio['excel_column_index']);
        $this->assertSame('C', $precio['excel_column_letter']);
        $this->assertSame('precio', $precio['excel_column_normalizada']);

        $codigo = $this->guardada($mapeo, 'Codigo');
        $this->assertSame('codigo_de_proveedor', $codigo['system_property']);
        $this->assertFalse($codigo['corregida'], '«Codigo» quedó como lo propuso la IA: confirmada, no corregida.');

        $this->assertFalse($this->guardada($mapeo, 'Descripcion')['corregida']);
        $this->assertFalse($this->guardada($mapeo, 'Stock')['corregida']);
    }

    /**
     * Una columna ignorada por el usuario no forma parte de la configuración.
     *
     * @return void
     */
    public function test_las_columnas_ignoradas_no_se_guardan()
    {
        $this->fakear_claude($this->respuesta_de_analisis());

        $analisis = $this->analizar($this->xlsx(self::ENCABEZADOS));

        $this->confirmar($analisis, $this->providers['A']->id, ['Stock' => null]);

        $mapeo = $this->configuracion_guardada($this->providers['A']->id);

        $this->assertNotNull($mapeo);
        $this->assertCount(3, $mapeo->column_mapping);

        foreach ($mapeo->column_mapping as $col) {
            $this->assertNotSame('Stock', $col['excel_column'], 'La columna ignorada no tenía que guardarse.');
        }
    }

    /**
     * Sin proveedor elegido ("Sin proveedor" en el select) no hay a quién atarle la configuración.
     *
     * @return void
     */
    public function test_no_se_guarda_sin_proveedor()
    {
        $this->fakear_claude($this->respuesta_de_analisis());

        $analisis = $this->analizar($this->xlsx(self::ENCABEZADOS));

        $recomendacion = $this->confirmar($analisis, null, ['Precio' => 'costo']);

        $this->assertSame('listo', $recomendacion->estado, 'La recomendación tiene que salir igual, con o sin configuración.');

        $this->assertSame(0, ProviderImportMapping::where('user_id', $this->tenant->id)->count());
    }

    /**
     * Con una columna mapeada a 'proveedor' el proveedor va por fila: no se guarda nada aunque
     * el select tenga un proveedor.
     *
     * @return void
     */
    public function test_no_se_guarda_con_una_columna_mapeada_a_proveedor()
    {
        $this->fakear_claude($this->respuesta_de_analisis());

        $analisis = $this->analizar($this->xlsx(self::ENCABEZADOS));

        $this->confirmar($analisis, $this->providers['A']->id, ['Precio' => 'costo', 'Stock' => 'proveedor']);

        $this->assertNull($this->configuracion_guardada($this->providers['A']->id));
    }

    /**
     * Clientes y proveedores no tienen proveedor: el helper no guarda para esos modelos. Se
     * prueba con corridas armadas a mano (lo que decide es el model del análisis padre), con un
     * control positivo para 'article' con las mismas corridas.
     *
     * @return void
     */
    public function test_no_se_guarda_para_clientes_ni_proveedores()
    {
        $resultado = [
            'column_mapping' => [
                ['excel_column' => 'Nombre', 'system_property' => 'nombre', 'excel_column_index' => 0, 'excel_column_letter' => 'A'],
                ['excel_column' => 'Telefono', 'system_property' => 'telefono', 'excel_column_index' => 1, 'excel_column_letter' => 'B'],
            ],
            'encabezado_detectado' => ['columnas' => ['Nombre', 'Telefono']],
        ];

        foreach (['client', 'provider', 'article'] as $model) {
            $padre = ExcelAnalysisRun::create([
                'uuid'       => Str::uuid()->toString(),
                'user_id'    => $this->tenant->id,
                'tipo'       => 'analisis',
                'estado'     => 'listo',
                'excel_path' => 'imported_files/no_existe_' . Str::random(6) . '.xlsx',
                'payload'    => ['model' => $model, 'original_filename' => 'x.xlsx'],
                'resultado'  => $resultado,
            ]);

            $recomendacion = ExcelAnalysisRun::create([
                'uuid'       => Str::uuid()->toString(),
                'user_id'    => $this->tenant->id,
                'tipo'       => 'recomendacion',
                'estado'     => 'pendiente',
                'excel_path' => $padre->excel_path,
                'payload'    => [
                    'analysis_uuid'  => $padre->uuid,
                    'provider_id'    => $this->providers['B']->id,
                    'column_mapping' => $resultado['column_mapping'],
                ],
            ]);

            $guardado = ProviderImportMappingHelper::guardar_desde_recomendacion($recomendacion);

            if ($model === 'article') {
                $this->assertNotNull($guardado, 'Control positivo: para article las mismas corridas sí guardan.');
            } else {
                $this->assertNull($guardado, 'Para ' . $model . ' no tenía que guardarse nada.');
            }
        }

        $this->assertSame(1, ProviderImportMapping::where('user_id', $this->tenant->id)->count());
    }

    /**
     * Una segunda confirmación del mismo proveedor no duplica la fila: la actualiza y cuenta
     * las veces que se usó.
     *
     * @return void
     */
    public function test_una_segunda_corrida_del_mismo_proveedor_hace_upsert_y_cuenta_veces_usado()
    {
        $this->fakear_claude($this->respuesta_de_analisis());

        $analisis = $this->analizar($this->xlsx(self::ENCABEZADOS));

        $this->confirmar($analisis, $this->providers['A']->id, ['Precio' => 'costo']);

        $primera = $this->configuracion_guardada($this->providers['A']->id);

        /* Segunda vez: además cambia Stock a ignorada y Precio vuelve a lo que dijo la IA. */
        $this->confirmar($analisis, $this->providers['A']->id, ['Stock' => null]);

        $this->assertSame(1, ProviderImportMapping::where('user_id', $this->tenant->id)->count(), 'Una fila por proveedor: tenía que ser un upsert.');

        $segunda = $this->configuracion_guardada($this->providers['A']->id);

        $this->assertSame($primera->id, $segunda->id);
        $this->assertSame(2, $segunda->veces_usado);
        $this->assertCount(3, $segunda->column_mapping, 'La configuración es la ÚLTIMA confirmada, no una mezcla.');
        $this->assertSame('precio', $this->guardada($segunda, 'Precio')['system_property']);
        $this->assertSame(0, $segunda->columnas_corregidas);

        /* Otro proveedor con el mismo archivo es otra fila. */
        $this->confirmar($analisis, $this->providers['B']->id, ['Precio' => 'costo']);

        $this->assertSame(2, ProviderImportMapping::where('user_id', $this->tenant->id)->count());
        $this->assertSame(1, $this->configuracion_guardada($this->providers['B']->id)->veces_usado);
    }

    /* =====================================================================
     * 2. Usar lo guardado en el análisis
     * ================================================================== */

    /**
     * El caso de Lucas, de punta a punta. Un archivo con la MISMA firma de encabezados:
     *   - se reconoce el proveedor con confianza alta y se avisa;
     *   - la configuración entra al prompt de Claude;
     *   - «Precio», que la IA fakeada sigue devolviendo como precio, termina en costo con
     *     mapeo_guardado.origen = corregido_por_el_usuario; las demás quedan como confirmadas;
     *   - y al volver a confirmar sin tocar nada, la corrección sigue marcada como tal
     *     (pegajosa) y veces_usado sube.
     *
     * @return void
     */
    public function test_un_archivo_con_la_misma_firma_sugiere_el_proveedor_y_aplica_el_mapeo()
    {
        $this->fakear_claude($this->respuesta_de_analisis());

        $primero = $this->analizar($this->xlsx(self::ENCABEZADOS));

        /* Antes de guardar nada: la IA manda y no hay configuración en ninguna columna. */
        $this->assertNull($primero->resultado['provider_id']);
        $this->assertSame('precio', $this->columna($primero->resultado['column_mapping'], 'Precio')['system_property']);

        foreach ($primero->resultado['column_mapping'] as $col) {
            $this->assertArrayHasKey('mapeo_guardado', $col, 'La clave viaja siempre, en null cuando no hay configuración.');
            $this->assertNull($col['mapeo_guardado']);
        }

        $this->confirmar($primero, $this->providers['A']->id, ['Precio' => 'costo']);

        /* Segunda importación del mismo proveedor: otro archivo, mismos encabezados. */
        $segundo = $this->analizar($this->xlsx(self::ENCABEZADOS, [['A-009', 'Clavo 2"', 3, 100]]));

        $resultado = $segundo->resultado;

        $this->assertSame($this->providers['A']->id, (int) $resultado['provider_id'], 'La firma coincide: el proveedor es el de la configuración.');
        $this->assertSame('alto', $resultado['provider_confidence']);
        $this->assertNotEmpty($resultado['assistant_notes']);
        $this->assertStringContainsString('Reconocimos el formato de este archivo', $resultado['assistant_notes'][0]);
        $this->assertStringContainsString('Proveedor A Test', $resultado['assistant_notes'][0]);

        $precio = $this->columna($resultado['column_mapping'], 'Precio');
        $this->assertSame('costo', $precio['system_property'], 'Lo que el usuario corrigió manda sobre lo que propone la IA.');
        $this->assertSame('corregido_por_el_usuario', $precio['mapeo_guardado']['origen']);
        $this->assertSame('costo', $precio['mapeo_guardado']['system_property']);
        $this->assertSame('Proveedor A Test', $precio['mapeo_guardado']['proveedor']);
        $this->assertSame(now()->format('Y-m-d'), $precio['mapeo_guardado']['guardado_en']);

        $codigo = $this->columna($resultado['column_mapping'], 'Codigo');
        $this->assertSame('codigo_de_proveedor', $codigo['system_property']);
        $this->assertSame('confirmado', $codigo['mapeo_guardado']['origen']);

        /* La configuración viajó en el prompt del análisis, con la corrección marcada. */
        Http::assertSent(function ($request) {
            $prompt = $this->prompt_de($request);

            return strpos($prompt, 'Configuración confirmada por el usuario en la importación anterior de este proveedor') !== false
                && strpos($prompt, '«Precio» → costo (corregida por el usuario)') !== false
                && strpos($prompt, '«Codigo» → codigo_de_proveedor') !== false
                && strpos($prompt, 'provider_id debe ser ' . $this->providers['A']->id) !== false;
        });

        /* Confirma sin tocar nada: la corrección es pegajosa y el uso se cuenta. */
        $this->confirmar($segundo, $this->providers['A']->id);

        $mapeo = $this->configuracion_guardada($this->providers['A']->id);

        $this->assertSame(2, $mapeo->veces_usado);
        $this->assertTrue($this->guardada($mapeo, 'Precio')['corregida'], 'Sigue siendo distinta de lo que la IA diría sola: sigue corregida.');
        $this->assertSame(1, $mapeo->columnas_corregidas);
        $this->assertFalse($this->guardada($mapeo, 'Codigo')['corregida']);
    }

    /**
     * Encabezados distintos (una columna más) no reconocen el formato: la IA infiere el
     * proveedor y, si ese proveedor tiene configuración, se aplica sólo en las columnas que
     * coinciden. La confianza del proveedor es la de la IA, y no hay nota de reconocimiento.
     *
     * @return void
     */
    public function test_con_encabezados_distintos_pero_proveedor_inferido_aplica_solo_las_columnas_que_coinciden()
    {
        $this->fakear_claude($this->respuesta_de_analisis());

        $primero = $this->analizar($this->xlsx(self::ENCABEZADOS));
        $this->confirmar($primero, $this->providers['A']->id, ['Precio' => 'costo']);

        /* Ahora el proveedor manda una columna más, y la IA lo reconoce por el nombre del archivo. */
        $this->fakear_claude($this->respuesta_de_analisis([
            'Codigo'      => 'codigo_de_proveedor',
            'Descripcion' => 'nombre',
            'Precio'      => 'precio',
            'Stock'       => 'stock_actual',
            'Marca'       => 'marca',
        ], $this->providers['A']->id, 'medio'));

        $segundo = $this->analizar($this->xlsx(
            ['Codigo', 'Descripcion', 'Precio', 'Stock', 'Marca'],
            [['A-001', 'Tornillo 3x20', 100.5, 10, 'Fischer']]
        ));

        $resultado = $segundo->resultado;

        $this->assertSame($this->providers['A']->id, (int) $resultado['provider_id']);
        $this->assertSame('medio', $resultado['provider_confidence'], 'Sin firma reconocida, la confianza del proveedor es la que dijo la IA.');

        foreach ($resultado['assistant_notes'] as $nota) {
            $this->assertStringNotContainsString('Reconocimos el formato', $nota);
        }

        $precio = $this->columna($resultado['column_mapping'], 'Precio');
        $this->assertSame('costo', $precio['system_property']);
        $this->assertSame('corregido_por_el_usuario', $precio['mapeo_guardado']['origen']);

        $marca = $this->columna($resultado['column_mapping'], 'Marca');
        $this->assertSame('marca', $marca['system_property']);
        $this->assertNull($marca['mapeo_guardado'], 'Una columna que no estaba en la configuración queda como la propuso la IA.');

        /* Y si la IA infiere OTRO proveedor, la configuración de A no se aplica. */
        $this->fakear_claude($this->respuesta_de_analisis([
            'Codigo'      => 'codigo_de_proveedor',
            'Descripcion' => 'nombre',
            'Precio'      => 'precio',
            'Stock'       => 'stock_actual',
            'Marca'       => 'marca',
        ], $this->providers['B']->id, 'alto'));

        $tercero = $this->analizar($this->xlsx(
            ['Codigo', 'Descripcion', 'Precio', 'Stock', 'Marca'],
            [['B-001', 'Otro', 1, 1, 'X']]
        ));

        $this->assertSame('precio', $this->columna($tercero->resultado['column_mapping'], 'Precio')['system_property']);
        $this->assertNull($this->columna($tercero->resultado['column_mapping'], 'Precio')['mapeo_guardado']);
    }

    /**
     * Un depósito o una lista de precio que ya no existe no se aplica: queda lo de la IA y sin
     * mapeo_guardado. Mientras existe, sí se aplica.
     *
     * @return void
     */
    public function test_un_deposito_o_lista_que_ya_no_existe_no_se_aplica()
    {
        $deposito = Address::create(['user_id' => $this->tenant->id, 'street' => 'Depósito Norte']);

        ProviderImportMapping::create([
            'user_id'        => $this->tenant->id,
            'model_name'     => 'article',
            'provider_id'    => $this->providers['C']->id,
            'headers_hash'   => ProviderImportMappingHelper::firma_de_encabezados(self::ENCABEZADOS),
            'header_row'     => 1,
            'column_mapping' => [
                ['excel_column' => 'Codigo', 'excel_column_normalizada' => 'codigo', 'excel_column_index' => 0, 'excel_column_letter' => 'A', 'system_property' => 'codigo_de_proveedor', 'corregida' => false],
                /* El tenant 900 no usa listas de precio: esta lista no es válida aunque exista el id. */
                ['excel_column' => 'Precio', 'excel_column_normalizada' => 'precio', 'excel_column_index' => 2, 'excel_column_letter' => 'C', 'system_property' => 'price_type_1_final_price', 'corregida' => true],
                ['excel_column' => 'Stock', 'excel_column_normalizada' => 'stock', 'excel_column_index' => 3, 'excel_column_letter' => 'D', 'system_property' => 'address_' . $deposito->id . '_amount', 'corregida' => true],
            ],
            'columnas_corregidas' => 2,
            'veces_usado'         => 1,
            'ultimo_uso_at'       => now(),
        ]);

        $this->fakear_claude($this->respuesta_de_analisis());

        /* Con el depósito vivo, se aplica. */
        $con_deposito = $this->analizar($this->xlsx(self::ENCABEZADOS));

        $stock = $this->columna($con_deposito->resultado['column_mapping'], 'Stock');
        $this->assertSame('address_' . $deposito->id . '_amount', $stock['system_property']);
        $this->assertSame('corregido_por_el_usuario', $stock['mapeo_guardado']['origen']);

        $precio = $this->columna($con_deposito->resultado['column_mapping'], 'Precio');
        $this->assertSame('precio', $precio['system_property'], 'Una lista de precio que el usuario no puede usar no se aplica.');
        $this->assertNull($precio['mapeo_guardado']);

        /* El depósito se borró: la columna vuelve a lo que propone la IA. */
        $deposito->delete();

        $sin_deposito = $this->analizar($this->xlsx(self::ENCABEZADOS));

        $stock = $this->columna($sin_deposito->resultado['column_mapping'], 'Stock');
        $this->assertSame('stock_actual', $stock['system_property']);
        $this->assertNull($stock['mapeo_guardado']);

        /* La firma sigue reconociendo el proveedor: lo que no se aplica es la columna, no la configuración. */
        $this->assertSame($this->providers['C']->id, (int) $sin_deposito->resultado['provider_id']);
        $this->assertSame('confirmado', $this->columna($sin_deposito->resultado['column_mapping'], 'Codigo')['mapeo_guardado']['origen']);
    }

    /* =====================================================================
     * 3. Usar lo guardado al cambiar el proveedor en el paso 2
     * ================================================================== */

    /**
     * refresh-provider-stats devuelve la configuración del proveedor elegido resuelta contra
     * los encabezados del análisis de ese archivo (sin releerlo), y null si no hay.
     *
     * @return void
     */
    public function test_refresh_provider_stats_devuelve_el_mapeo_guardado_del_proveedor()
    {
        $this->fakear_claude($this->respuesta_de_analisis());

        $analisis = $this->analizar($this->xlsx(self::ENCABEZADOS));
        $this->confirmar($analisis, $this->providers['A']->id, ['Precio' => 'costo']);

        $payload = [
            'excel_path'                 => $analisis->excel_path,
            'provider_code_column_index' => 0,
            'provider_id'                => $this->providers['A']->id,
            'hoja'                       => 0,
            'header_row'                 => 1,
        ];

        $respuesta = $this->postJson('/api/ai-excel-import/refresh-provider-stats', $payload);

        $respuesta->assertStatus(200);

        /* Lo de siempre sigue viniendo. */
        $this->assertArrayHasKey('provider_codes_existentes_mismo_proveedor', $respuesta->json());
        $this->assertArrayHasKey('provider_codes_existentes_otros_proveedores', $respuesta->json());

        $mapeo = $respuesta->json('mapeo_guardado_del_proveedor');

        $this->assertIsArray($mapeo);
        $this->assertCount(4, $mapeo);

        $precio = $this->columna($mapeo, 'Precio');
        $this->assertSame(2, $precio['excel_column_index']);
        $this->assertSame('costo', $precio['system_property']);
        $this->assertSame('corregido_por_el_usuario', $precio['origen']);

        $this->assertSame('confirmado', $this->columna($mapeo, 'Codigo')['origen']);

        /* Un proveedor sin configuración: null. */
        $this->postJson('/api/ai-excel-import/refresh-provider-stats', array_merge($payload, [
            'provider_id' => $this->providers['B']->id,
        ]))->assertStatus(200)->assertJson(['mapeo_guardado_del_proveedor' => null]);

        /* "Sin proveedor": null. */
        $this->postJson('/api/ai-excel-import/refresh-provider-stats', array_merge($payload, [
            'provider_id' => null,
        ]))->assertStatus(200)->assertJson(['mapeo_guardado_del_proveedor' => null]);
    }
}
