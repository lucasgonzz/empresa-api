<?php

namespace Tests\Import;

use App\Http\Controllers\Helpers\import\article\AiExcelAnalyzer;
use App\Http\Controllers\Helpers\import\article\ExcelDuplicateStats;
use App\Http\Controllers\Helpers\import\excel\EncabezadosRepetidos;
use App\Jobs\RunExcelAnalysisJob;
use App\Models\Article;
use App\Models\ExcelAnalysisRun;
use App\Models\ImportHistory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Fakes\HttpFactorySinSalida;

/**
 * Encabezados repetidos adentro de la planilla, proveedor inferido por los artículos y SKU
 * repetidos (misión importacion-ia-mapeo-y-recomendacion, 10/11/2026).
 *
 * La planilla de estos tests tiene la forma de la que originó la misión: una columna con un
 * contador, el código en la B, y cada tanto una fila con «Articulo» en la B, el título de la
 * sección en la C y el resto vacío.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos
 * nombrados, union types, promoción de constructor, readonly, enum ni #[...].
 */
class EncabezadosRepetidosTest extends ImportTestCase
{
    /** @var string[] Excels generados en storage, para limpiarlos con sus archivos laterales. */
    protected $generados = [];

    protected function tearDown(): void
    {
        foreach ($this->generados as $ruta) {
            foreach (glob($ruta . '*') ?: [] as $archivo) {
                @unlink($archivo);
            }
        }

        parent::tearDown();
    }

    /** @return array */
    protected function encabezado()
    {
        return ['Nro', 'CODIGO', 'Nombre', 'COSTO', 'MARCA', 'CATEGORIA', 'STOCK'];
    }

    /**
     * Filas físicas: título en la 1, vacía la 2, encabezado en la 3 y datos desde la 4.
     * 'Articulo' queda en las filas 7, 9 y 12. S-5 es un repetido real (filas 10 y 11).
     *
     * @return array
     */
    protected function filas_mayorista()
    {
        return [
            ['Lista de precios'],
            [null],
            $this->encabezado(),
            [1, 'S-1', 'Anzuelo uno', 100, 'SURFISH', 'ACCESORIOS', 5],
            [2, 'S-2', 'Anzuelo dos', 200, 'SURFISH', 'ACCESORIOS', 5],
            [3, 'S-3', 'Anzuelo tres', 300, 'SURFISH', 'ACCESORIOS', 5],
            [4, 'Articulo', 'Anzuelos forjados', null, null, null, null],
            [5, 'S-4', 'Anzuelo cuatro', 400, 'SURFISH', 'ACCESORIOS', 5],
            [6, 'Articulo', 'Anzuelos punta crystal', null, null, null, null],
            [7, 'S-5', 'Anzuelo cinco', 500, 'SURFISH', 'ACCESORIOS', 5],
            [8, 'S-5', 'Anzuelo cinco bis', 550, 'SURFISH', 'ACCESORIOS', 5],
            [9, 'Articulo', 'Anzuelos serie', null, null, null, null],
            [10, 'S-6', 'Anzuelo seis', 600, 'SURFISH', 'ACCESORIOS', 5],
        ];
    }

    /**
     * @param  array $filas
     * @return string ruta absoluta
     */
    protected function planilla(array $filas)
    {
        $libro = new Spreadsheet();
        $libro->getActiveSheet()->fromArray($filas, null, 'A1', true);

        $ruta = storage_path('app/imported_files/encabezados_test_' . uniqid('', true) . '.xlsx');

        (new Xlsx($libro))->save($ruta);
        $libro->disconnectWorksheets();

        $this->generados[] = $ruta;

        return $ruta;
    }

    /**
     * @param  array $propiedades  por columna, en orden
     * @param  array $extra        claves extra del JSON de la IA
     * @return string
     */
    protected function respuesta_ia(array $propiedades, array $extra = [])
    {
        $mapeo = [];

        foreach ($propiedades as $titulo => $propiedad) {
            $mapeo[] = ['excel_column' => $titulo, 'system_property' => $propiedad, 'confidence' => 0.95, 'interpretation_note' => null];
        }

        return json_encode(array_merge([
            'column_mapping'      => $mapeo,
            'provider_id'         => null,
            'provider_confidence' => 'bajo',
            'assistant_notes'     => [],
        ], $extra));
    }

    /** @return array */
    protected function propiedades_mayorista()
    {
        return [
            'Nro'       => null,
            'CODIGO'    => 'codigo_de_proveedor',
            'Nombre'    => 'nombre',
            'COSTO'     => 'costo',
            'MARCA'     => null,
            'CATEGORIA' => null,
            'STOCK'     => 'stock_actual',
        ];
    }

    /** @return array */
    protected function mapeo_con_codigo_en($columna)
    {
        return [['system_property' => 'codigo_de_proveedor', 'excel_column_index' => $columna]];
    }

    /**
     * @param  string $ruta
     * @param  string $respuesta
     * @param  string $nombre
     * @param  array  $opciones
     * @return array
     */
    protected function analizar($ruta, $respuesta, $nombre = 'lista.xlsx', array $opciones = ['fila_encabezado' => 3])
    {
        $analizador = new AnalizadorDeEncabezadosFalso($this->tenant->id);
        $analizador->respuesta = $respuesta;

        return $analizador->analyze($ruta, $nombre, $opciones);
    }

    public function test_arma_candidatas_y_la_ia_confirma_la_regla()
    {
        $ruta       = $this->planilla($this->filas_mayorista());
        $candidatas = EncabezadosRepetidos::candidatas($ruta, 0, 3, $this->encabezado());

        $this->assertCount(1, $candidatas['grupos']);
        $this->assertSame(1, $candidatas['grupos'][0]['columna']);
        $this->assertSame('Articulo', $candidatas['grupos'][0]['valor']);
        $this->assertSame(3, $candidatas['grupos'][0]['repeticiones']);
        $this->assertStringContainsString('«Articulo»', EncabezadosRepetidos::seccion_para_prompt($candidatas));

        /* Mayúsculas y acento distintos a los de la planilla: es el mismo rótulo. */
        $regla = EncabezadosRepetidos::confirmar($candidatas, [['columna' => 1, 'valor' => 'ARTÍCULO']], []);

        $this->assertSame(3, $regla['filas']);
        $this->assertSame([7, 9, 12], $regla['ejemplos']);
        $this->assertSame(['Articulo'], $regla['valores']);
    }

    public function test_sin_confirmacion_de_la_ia_entra_por_la_columna_de_codigo()
    {
        $ruta       = $this->planilla($this->filas_mayorista());
        $candidatas = EncabezadosRepetidos::candidatas($ruta, 0, 3, $this->encabezado());

        $this->assertSame(3, EncabezadosRepetidos::confirmar($candidatas, null, $this->mapeo_con_codigo_en(1))['filas']);
        $this->assertSame(0, EncabezadosRepetidos::confirmar($candidatas, null, $this->mapeo_con_codigo_en(2))['filas']);
    }

    public function test_la_ia_no_puede_agregar_un_valor_que_la_medicion_no_encontro()
    {
        $ruta       = $this->planilla($this->filas_mayorista());
        $candidatas = EncabezadosRepetidos::candidatas($ruta, 0, 3, $this->encabezado());

        $regla = EncabezadosRepetidos::confirmar($candidatas, [['columna' => 4, 'valor' => 'SURFISH']], []);

        $this->assertSame(0, $regla['filas']);
    }

    public function test_un_valor_que_aparece_en_filas_completas_no_es_candidato()
    {
        $filas   = $this->filas_mayorista();
        $filas[] = [11, 'Articulo', 'Un artículo de verdad', 700, 'SURFISH', 'ACCESORIOS', 5];

        $candidatas = EncabezadosRepetidos::candidatas($this->planilla($filas), 0, 3, $this->encabezado());

        $this->assertSame([], $candidatas['grupos']);
    }

    public function test_una_repeticion_exacta_del_encabezado_se_detecta_sin_ia()
    {
        $filas   = $this->filas_mayorista();
        $filas[] = $this->encabezado();
        $ruta    = $this->planilla($filas);

        $candidatas = EncabezadosRepetidos::candidatas($ruta, 0, 3, $this->encabezado());
        $regla      = EncabezadosRepetidos::confirmar($candidatas, [], []);

        $this->assertSame(1, $candidatas['exactas']);
        $this->assertSame(1, $regla['filas']);
        $this->assertSame([14], $regla['ejemplos']);
    }

    public function test_en_una_tabla_de_dos_columnas_no_se_detecta_nada()
    {
        $filas = [['Codigo', 'Nombre'], ['A-1', 'Uno'], ['Seccion', ''], ['Seccion', ''], ['Seccion', ''], ['A-2', 'Dos']];

        $candidatas = EncabezadosRepetidos::candidatas($this->planilla($filas), 0, 1, ['Codigo', 'Nombre']);

        $this->assertSame([], $candidatas['grupos']);
    }

    public function test_sin_ia_un_codigo_real_sin_digitos_y_sin_precio_no_se_descarta()
    {
        $filas = [
            ['Codigo', 'Nombre', 'Precio'],
            ['A-1', 'Anzuelo', 100],
            ['A-2', 'Boya', 200],
            ['A-3', 'Plomada', 300],
            ['A-4', 'Reel', 400],
            ['TANZA', 'Tanza roja', null],
            ['TANZA', 'Tanza azul', null],
            ['TANZA', 'Tanza verde', null],
        ];

        $encabezado = ['Codigo', 'Nombre', 'Precio'];
        $candidatas = EncabezadosRepetidos::candidatas($this->planilla($filas), 0, 1, $encabezado);

        $this->assertSame(0, EncabezadosRepetidos::confirmar($candidatas, null, $this->mapeo_con_codigo_en(0))['filas']);
    }

    public function test_un_marcador_de_sin_codigo_no_es_candidato()
    {
        $filas = [
            ['Codigo', 'Nombre', 'Precio'],
            ['A-1', 'Anzuelo', 100],
            ['A-2', 'Boya', 200],
            ['A-3', 'Plomada', 300],
            ['S/C', 'Tanza roja', null],
            ['S/C', 'Tanza azul', null],
            ['S/C', 'Tanza verde', null],
        ];

        $candidatas = EncabezadosRepetidos::candidatas($this->planilla($filas), 0, 1, ['Codigo', 'Nombre', 'Precio']);

        $this->assertSame([], $candidatas['grupos']);
    }

    public function test_sin_ia_entra_el_titulo_de_la_propia_columna()
    {
        $filas = $this->filas_mayorista();

        foreach ([6, 8, 11] as $indice) {
            $filas[$indice][1] = 'CODIGO';
        }

        $ruta       = $this->planilla($filas);
        $candidatas = EncabezadosRepetidos::candidatas($ruta, 0, 3, $this->encabezado());

        $this->assertSame(3, EncabezadosRepetidos::confirmar($candidatas, null, $this->mapeo_con_codigo_en(1))['filas']);
    }

    public function test_en_una_tabla_de_dos_columnas_se_detecta_la_repeticion_exacta()
    {
        $filas = [['Codigo', 'Nombre'], ['A-1', 'Uno'], ['Codigo', 'Nombre'], ['A-2', 'Dos'], ['Codigo', 'Nombre'], ['A-3', 'Tres']];

        $candidatas = EncabezadosRepetidos::candidatas($this->planilla($filas), 0, 1, ['Codigo', 'Nombre']);
        $regla      = EncabezadosRepetidos::confirmar($candidatas, [], []);

        $this->assertSame(2, $candidatas['exactas']);
        $this->assertSame(2, $regla['filas']);
    }

    public function test_la_recomendacion_recalcula_con_el_mapeo_que_corrigio_el_usuario()
    {
        config([
            'services.anthropic.api_key' => 'clave-anthropic-de-prueba',
            'services.deepseek.api_key'  => 'clave-deepseek-de-prueba',
        ]);

        Notification::fake();

        $respuesta = json_encode([
            'politica_colision'      => 'actualizar_todos',
            'politica_intra_archivo' => 'ultima_gana',
            'explicacion'            => 'Recomendación de prueba.',
        ]);

        Http::swap(new HttpFactorySinSalida(app('events')));
        Http::fake(function () use ($respuesta) {
            return Http::response([
                'model'       => 'modelo-de-prueba',
                'content'     => [['type' => 'text', 'text' => $respuesta]],
                'stop_reason' => 'end_turn',
                'usage'       => ['input_tokens' => 1, 'output_tokens' => 1],
            ], 200);
        });

        $filas = array_values(array_filter($this->filas_mayorista(), function ($fila) {
            return ($fila[1] ?? null) !== 'Articulo';
        }));

        $ruta = $this->planilla($filas);

        /* provider_code_column_index llega en null: es el del análisis, antes de la corrección. */
        $run = ExcelAnalysisRun::create([
            'uuid'         => Str::uuid()->toString(),
            'user_id'      => $this->tenant->id,
            'auth_user_id' => $this->tenant->id,
            'tipo'         => 'recomendacion',
            'estado'       => 'pendiente',
            'excel_path'   => 'imported_files/' . basename($ruta),
            'payload'      => [
                'model'                      => 'article',
                'provider_code_column_index' => null,
                'hoja'                       => 0,
                'header_row'                 => 3,
                'column_mapping'             => [
                    ['system_property' => 'codigo_de_proveedor', 'excel_column_index' => 1, 'excel_column' => 'CODIGO'],
                    ['system_property' => 'nombre', 'excel_column_index' => 2, 'excel_column' => 'Nombre'],
                ],
            ],
        ]);

        (new RunExcelAnalysisJob($run->id))->handle();

        $run->refresh();

        $this->assertSame('listo', $run->estado, json_encode($run->toArray(), JSON_UNESCAPED_UNICODE));
        $this->assertSame(1, $run->resultado['duplicate_stats']['provider_codes_duplicados_intra_archivo']);
        $this->assertArrayNotHasKey('provider_codes_distintos', $run->resultado['duplicate_stats']);
        $this->assertTrue($run->resultado['cadena_identificacion']['disponible']);
        $this->assertSame(7, $run->resultado['cadena_identificacion']['total_filas']);
        $this->assertArrayHasKey('desempate_por_nombre', $run->resultado);
    }

    public function test_el_analisis_excluye_esas_filas_de_todos_los_conteos()
    {
        $ruta = $this->planilla($this->filas_mayorista());

        $resultado = $this->analizar($ruta, $this->respuesta_ia($this->propiedades_mayorista(), [
            'encabezados_repetidos' => [['columna' => 1, 'valor' => 'Articulo']],
        ]));

        $this->assertSame(['filas' => 3, 'ejemplos' => [7, 9, 12], 'valores' => ['Articulo']], $resultado['encabezados_repetidos']);
        $this->assertSame(7, $resultado['row_count']);
        $this->assertSame(7, $resultado['duplicate_stats']['total_filas_datos']);
        $this->assertSame(1, $resultado['duplicate_stats']['provider_codes_duplicados_intra_archivo']);
        $this->assertSame('S-5', $resultado['duplicate_stats']['detalle_provider_codes_duplicados'][0]['codigo']);
        $this->assertSame([10, 11], $resultado['duplicate_stats']['detalle_provider_codes_duplicados'][0]['filas']);
        $this->assertSame(7, $resultado['cadena_identificacion']['total_filas']);

        $codigos = array_column($resultado['preview_rows'], 1);
        $this->assertSame(['S-1', 'S-2', 'S-3', 'S-4', 'S-5'], $codigos);
    }

    public function test_sin_encabezados_repetidos_la_clave_viene_vacia_y_no_queda_archivo_lateral()
    {
        $filas = array_values(array_filter($this->filas_mayorista(), function ($fila) {
            return ($fila[1] ?? null) !== 'Articulo';
        }));

        $ruta      = $this->planilla($filas);
        $resultado = $this->analizar($ruta, $this->respuesta_ia($this->propiedades_mayorista()));

        $this->assertSame(['filas' => 0, 'ejemplos' => [], 'valores' => []], $resultado['encabezados_repetidos']);
        $this->assertNull(EncabezadosRepetidos::leer($ruta, 0));
    }

    public function test_la_seccion_del_prompt_viaja_a_la_ia()
    {
        $analizador = new AnalizadorDeEncabezadosFalso($this->tenant->id);
        $analizador->respuesta = $this->respuesta_ia($this->propiedades_mayorista());

        $analizador->analyze($this->planilla($this->filas_mayorista()), 'lista.xlsx', ['fila_encabezado' => 3]);

        $this->assertStringContainsString('Posibles encabezados repetidos', $analizador->ultimo_prompt);
        $this->assertStringContainsString('«Articulo»', $analizador->ultimo_prompt);
        $this->assertStringContainsString('"encabezados_repetidos"', $analizador->ultimo_prompt);
    }

    public function test_una_regla_de_otro_archivo_con_la_misma_ruta_no_se_lee()
    {
        $ruta       = $this->planilla($this->filas_mayorista());
        $candidatas = EncabezadosRepetidos::candidatas($ruta, 0, 3, $this->encabezado());

        EncabezadosRepetidos::guardar($ruta, 0, EncabezadosRepetidos::confirmar($candidatas, null, $this->mapeo_con_codigo_en(1)));
        $this->assertNotNull(EncabezadosRepetidos::leer($ruta, 0));

        $otro = new Spreadsheet();
        $otro->getActiveSheet()->fromArray([['Otra', 'Cosa'], ['1', '2']], null, 'A1', true);
        (new Xlsx($otro))->save($ruta);
        $otro->disconnectWorksheets();

        $this->assertNull(EncabezadosRepetidos::leer($ruta, 0));
    }

    public function test_vaciar_conserva_un_registro_por_fila_y_copia_el_resto_tal_cual()
    {
        $bom = "\xEF\xBB\xBF";
        $csv = tempnam(sys_get_temp_dir(), 'encabezados_');

        file_put_contents($csv, $bom
            . "Nro,Codigo,Nombre\n"
            . "1,\"multi\nlinea\",x\n"
            . "2,Articulo,Titulo\n"
            . "3,\"con \"\"comillas\"\"\",y\n"
            . "4,Articulo,Otro");

        $regla = [
            'fila_encabezado' => 1,
            'encabezado'      => [],
            'etiquetas'       => [['columna' => 1, 'valor' => 'articulo', 'max_celdas' => 3]],
        ];

        $this->assertTrue(EncabezadosRepetidos::vaciar_en_csv($csv, $regla));

        $this->assertSame($bom
            . "Nro,Codigo,Nombre\n"
            . "1,\"multi\nlinea\",x\n"
            . ",,\n"
            . "3,\"con \"\"comillas\"\"\",y\n"
            . ',,', file_get_contents($csv));

        @unlink($csv);
    }

    public function test_max_celdas_protege_a_un_articulo_real_con_ese_codigo()
    {
        $regla = [
            'fila_encabezado' => 1,
            'encabezado'      => [],
            'etiquetas'       => [['columna' => 1, 'valor' => 'articulo', 'max_celdas' => 3]],
        ];

        $this->assertTrue(EncabezadosRepetidos::es_encabezado([9, 'Articulo', 'Titulo', ''], 5, $regla));
        $this->assertFalse(EncabezadosRepetidos::es_encabezado([9, 'Articulo', 'Real', 100, 'Marca'], 5, $regla));
        $this->assertFalse(EncabezadosRepetidos::es_encabezado([9, 'Articulo', 'Titulo', ''], 1, $regla));
    }

    public function test_la_importacion_no_crea_los_encabezados_y_reporta_la_fila_real()
    {
        $ruta = $this->planilla($this->filas_mayorista());

        $this->analizar($ruta, $this->respuesta_ia($this->propiedades_mayorista(), [
            'encabezados_repetidos' => [['columna' => 1, 'valor' => 'Articulo']],
        ]));

        $this->postJson('/api/ai-excel-import/import', array_merge(self::config_por_defecto(), [
            'model'       => 'article',
            'excel_path'  => 'imported_files/' . basename($ruta),
            'columns'     => ['codigo_de_proveedor' => 1, 'nombre' => 2, 'costo' => 3, 'stock_actual' => 6],
            'start_row'   => 4,
            'finish_row'  => 99999,
            'provider_id' => null,
        ]))->assertStatus(200);

        $import = ImportHistory::where('user_id', $this->tenant->id)->orderBy('id', 'DESC')->first();

        $this->assertNotNull($import);
        $this->assertInvariantesDeConteo($import);

        $de_la_planilla = Article::where('user_id', $this->tenant->id)
            ->where(function ($consulta) {
                $consulta->where('provider_code', 'Articulo')->orWhere('name', 'like', 'Anzuelos %');
            })
            ->count();

        $this->assertSame(0, $de_la_planilla);
        $this->assertSame(1, Article::where('user_id', $this->tenant->id)->where('provider_code', 'S-6')->count());

        /* S-5 está en las filas físicas 10 y 11: si el CSV se hubiera corrido, el número no daría. */
        $this->assertSame([10 => 11], $this->sobrescrituras($import));
    }

    /**
     * @param  string   $codigo
     * @param  int|null $provider_id
     * @return void
     */
    protected function articulo($codigo, $provider_id)
    {
        $articulo                = new Article();
        $articulo->user_id       = $this->tenant->id;
        $articulo->name          = 'Artículo ' . $codigo;
        $articulo->provider_code = $codigo;
        $articulo->provider_id   = $provider_id;
        $articulo->status        = 'active';
        $articulo->save();
    }

    /**
     * @param  array $codigos
     * @return string
     */
    protected function planilla_de_codigos(array $codigos)
    {
        $filas = [['CODIGO', 'Nombre', 'COSTO']];

        foreach ($codigos as $indice => $codigo) {
            $filas[] = [$codigo, 'Nombre ' . $codigo, 100 + $indice];
        }

        return $this->planilla($filas);
    }

    /** @return array */
    protected function propiedades_de_codigos()
    {
        return ['CODIGO' => 'codigo_de_proveedor', 'Nombre' => 'nombre', 'COSTO' => 'costo'];
    }

    /**
     * @param  array $codigos
     * @param  array $extra
     * @return array
     */
    protected function analizar_codigos(array $codigos, array $extra = [])
    {
        return $this->analizar(
            $this->planilla_de_codigos($codigos),
            $this->respuesta_ia($this->propiedades_de_codigos(), $extra),
            'lista.xlsx',
            []
        );
    }

    public function test_proveedor_por_articulos_si_todos_los_encontrados_son_del_mismo()
    {
        $proveedor = $this->providers['A']->id;

        foreach (['PV-1', 'PV-2', 'PV-3', 'PV-4'] as $codigo) {
            $this->articulo($codigo, $proveedor);
        }

        $resultado = $this->analizar_codigos(['PV-1', 'PV-2', 'PV-3', 'PV-4']);

        $this->assertSame($proveedor, $resultado['provider_id']);
        $this->assertSame('alto', $resultado['provider_confidence']);
        $this->assertSame(4, $resultado['duplicate_stats']['provider_codes_existentes_mismo_proveedor']);
    }

    public function test_proveedor_por_articulos_con_cobertura_parcial_queda_en_confianza_media()
    {
        $proveedor = $this->providers['A']->id;

        foreach (['PV-1', 'PV-2', 'PV-3'] as $codigo) {
            $this->articulo($codigo, $proveedor);
        }

        $resultado = $this->analizar_codigos(['PV-1', 'PV-2', 'PV-3', 'PV-NUEVO']);

        $this->assertSame($proveedor, $resultado['provider_id']);
        $this->assertSame('medio', $resultado['provider_confidence']);
    }

    public function test_un_articulo_de_otro_proveedor_no_permite_inferir()
    {
        $this->articulo('PV-1', $this->providers['A']->id);
        $this->articulo('PV-2', $this->providers['A']->id);
        $this->articulo('PV-3', $this->providers['B']->id);

        $this->assertNull($this->analizar_codigos(['PV-1', 'PV-2', 'PV-3'])['provider_id']);
    }

    public function test_con_cobertura_baja_no_se_infiere()
    {
        $this->articulo('PV-1', $this->providers['A']->id);

        $this->assertNull($this->analizar_codigos(['PV-1', 'PV-2', 'PV-3', 'PV-4'])['provider_id']);
    }

    public function test_con_columna_de_proveedor_por_fila_no_se_infiere()
    {
        $this->articulo('PV-1', $this->providers['A']->id);

        $resultado = $this->analizar(
            $this->planilla([['CODIGO', 'Nombre', 'Prov'], ['PV-1', 'Uno', 'Alguno']]),
            $this->respuesta_ia(['CODIGO' => 'codigo_de_proveedor', 'Nombre' => 'nombre', 'Prov' => 'proveedor']),
            'lista.xlsx',
            []
        );

        $this->assertNull($resultado['provider_id']);
    }

    public function test_el_nombre_del_archivo_manda_sobre_los_articulos()
    {
        $this->articulo('PV-1', $this->providers['A']->id);

        $resultado = $this->analizar_codigos(['PV-1'], [
            'provider_id'         => $this->providers['B']->id,
            'provider_confidence' => 'alto',
        ]);

        $this->assertSame($this->providers['B']->id, $resultado['provider_id']);
    }

    public function test_el_prompt_no_infiere_el_proveedor_por_los_datos()
    {
        $analizador = new AnalizadorDeEncabezadosFalso($this->tenant->id);
        $analizador->respuesta = $this->respuesta_ia($this->propiedades_de_codigos());

        $analizador->analyze($this->planilla_de_codigos(['PV-1']), 'lista.xlsx');

        $this->assertStringNotContainsString('usá encabezados y datos de muestra', $analizador->ultimo_prompt);
        $this->assertStringContainsString('marca no es un proveedor', $analizador->ultimo_prompt);
    }

    public function test_la_corrida_en_segundo_plano_expone_las_filas_ignoradas()
    {
        config([
            'services.anthropic.api_key' => 'clave-anthropic-de-prueba',
            'services.deepseek.api_key'  => 'clave-deepseek-de-prueba',
        ]);

        Notification::fake();

        $respuesta = $this->respuesta_ia($this->propiedades_mayorista(), [
            'encabezados_repetidos' => [['columna' => 1, 'valor' => 'Articulo']],
        ]);

        Http::swap(new HttpFactorySinSalida(app('events')));
        Http::fake(function () use ($respuesta) {
            return Http::response([
                'model'       => 'modelo-de-prueba',
                'content'     => [['type' => 'text', 'text' => $respuesta]],
                'stop_reason' => 'end_turn',
                'usage'       => ['input_tokens' => 1, 'output_tokens' => 1],
            ], 200);
        });

        $ruta = $this->planilla($this->filas_mayorista());

        $run = ExcelAnalysisRun::create([
            'uuid'         => Str::uuid()->toString(),
            'user_id'      => $this->tenant->id,
            'auth_user_id' => $this->tenant->id,
            'tipo'         => 'analisis',
            'estado'       => 'pendiente',
            'excel_path'   => 'imported_files/' . basename($ruta),
            'payload'      => ['model' => 'article', 'original_filename' => 'lista.xlsx', 'header_row' => 3],
        ]);

        (new RunExcelAnalysisJob($run->id))->handle();

        $run->refresh();

        $this->assertSame('listo', $run->estado, json_encode($run->toArray(), JSON_UNESCAPED_UNICODE));
        $this->assertSame(3, $run->resultado['encabezados_repetidos']['filas']);
        $this->assertSame([7, 9, 12], $run->resultado['encabezados_repetidos']['ejemplos']);
        $this->assertSame(1, $run->resultado['duplicate_stats']['provider_codes_duplicados_intra_archivo']);
    }

    public function test_cuenta_los_sku_repetidos()
    {
        $ruta = $this->planilla([['SKU', 'Nombre', 'COSTO'], ['K-1', 'Uno', 1], ['K-1', 'Dos', 2], ['K-2', 'Tres', 3]]);

        $estadisticas = ExcelDuplicateStats::analyze($ruta, null, null, null, $this->tenant->id, ['sku_column_index' => 0]);

        $this->assertSame(1, $estadisticas['skus_duplicados_intra_archivo']);
        $this->assertSame('K-1', $estadisticas['detalle_skus_duplicados'][0]['codigo']);
        $this->assertSame(2, $estadisticas['detalle_skus_duplicados'][0]['veces']);
    }

    public function test_sin_columna_de_sku_las_claves_vienen_en_cero()
    {
        $estadisticas = ExcelDuplicateStats::analyze($this->planilla_de_codigos(['A', 'B']), null, 0, null, $this->tenant->id);

        $this->assertSame(0, $estadisticas['skus_duplicados_intra_archivo']);
        $this->assertSame([], $estadisticas['detalle_skus_duplicados']);
    }
}

/**
 * El analizador real con la llamada a la IA reemplazada por una respuesta fija.
 */
class AnalizadorDeEncabezadosFalso extends AiExcelAnalyzer
{
    /** @var string */
    public $respuesta = '';

    /** @var string */
    public $ultimo_prompt = '';

    /**
     * @param  string $prompt
     * @return string
     */
    protected function call_claude(string $prompt): string
    {
        $this->ultimo_prompt = $prompt;

        return $this->respuesta;
    }
}
