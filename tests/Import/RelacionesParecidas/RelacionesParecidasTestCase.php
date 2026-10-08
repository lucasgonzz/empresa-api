<?php

namespace Tests\Import\RelacionesParecidas;

use App\Models\Article;
use App\Models\Brand;
use App\Models\Category;
use App\Models\ImportHistory;
use App\Models\Provider;
use App\Models\SubCategory;
use Illuminate\Support\Facades\Http;
use Tests\Import\ImportTestCase;

/**
 * Base de los tests de nombres parecidos en las relaciones de la importación (misión
 * importacion-relaciones-parecidas, 8/10/2026): el tenant y el escenario de ImportTestCase, más
 * los ayudantes para sembrar relaciones, copiar un fixture a storage como lo deja /analyze, pegarle
 * a los endpoints del modal con IA y falsear la IA.
 *
 * 🔴 La IA siempre va con Http::fake() (por callback, para que conteste según lo que se le mandó):
 * ningún test de esta carpeta sale a internet.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
abstract class RelacionesParecidasTestCase extends ImportTestCase
{
    /** Fixtures (ver tests/Import/fixtures/generar_relaciones_parecidas.php). */
    const ARTICULOS     = '33_relaciones_parecidas_articulos.xlsx';
    const REIMPORTACION = '34_relaciones_parecidas_reimportacion.xlsx';
    const CLIENTES      = '35_relaciones_parecidas_clientes.xlsx';
    const PROVEEDORES   = '36_relaciones_parecidas_proveedores.xlsx';
    const LOCALIDADES   = '37_relaciones_parecidas_localidades.xlsx';

    /** Para elegir_por_nombre(): la clave `seguro` no viaja en la respuesta de la IA. */
    const ABSENT = '__sin_seguro__';

    /** @var string[] Prefijos de los archivos copiados a storage, para borrarlos al final. */
    protected $copias = [];

    /** @var array Consultas que recibió la IA de relaciones (una entrada por llamada). */
    protected $llamadas_relaciones = [];

    /** @var int Llamadas a la IA de la recomendación de artículos. */
    protected $llamadas_recomendacion = 0;

    protected function setUp(): void
    {
        parent::setUp();

        /* Las dos claves de mentira: el fake atiende a cualquiera de los dos proveedores. */
        config([
            'services.anthropic.api_key' => 'clave-anthropic-de-prueba',
            'services.deepseek.api_key'  => 'clave-deepseek-de-prueba',
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->copias as $prefijo) {
            foreach (glob($prefijo . '*') ?: [] as $residuo) {
                @unlink($residuo);
            }
        }

        parent::tearDown();
    }

    /* ------------------------------------------------------------------
     * Columnas
     * ------------------------------------------------------------------ */

    /**
     * Columnas del fixture 33/34, 0-based, como las manda el modal en `columns`.
     *
     * @return array
     */
    protected function columnas_articulos()
    {
        return [
            'codigo_de_barras'    => 0,
            'sku'                 => 1,
            'codigo_de_proveedor' => 2,
            'nombre'              => 3,
            'costo'               => 4,
            'proveedor'           => 8,
            'categoria'           => 9,
            'sub_categoria'       => 10,
            'marca'               => 11,
        ];
    }

    /**
     * @return array
     */
    protected function columnas_clientes()
    {
        return [
            'nombre'         => 0,
            'provincia'      => 1,
            'localidad'      => 2,
            'vendedor'       => 3,
            'tipo_de_precio' => 4,
        ];
    }

    /**
     * @return array
     */
    protected function columnas_proveedores()
    {
        return [
            'nombre'    => 0,
            'localidad' => 1,
        ];
    }

    /**
     * El `column_mapping` de get-recomendacion: [{system_property, excel_column_index}].
     *
     * @param  array $columnas
     * @return array
     */
    protected function column_mapping(array $columnas)
    {
        $mapping = [];

        foreach ($columnas as $propiedad => $indice) {
            $mapping[] = ['system_property' => $propiedad, 'excel_column_index' => $indice];
        }

        return $mapping;
    }

    /* ------------------------------------------------------------------
     * Archivos y endpoints
     * ------------------------------------------------------------------ */

    /**
     * Copia un fixture a storage/app/imported_files, como lo deja /analyze, y devuelve el
     * excel_path relativo.
     *
     * @param  string $fixture
     * @return string
     */
    protected function copiar_a_storage($fixture)
    {
        $origen = dirname(__DIR__) . '/fixtures/' . $fixture;

        $this->assertFileExists($origen, 'Falta el fixture ' . $fixture);

        $carpeta = storage_path('app/imported_files');

        if (!is_dir($carpeta)) {
            mkdir($carpeta, 0777, true);
        }

        $base = uniqid('relaciones_parecidas_');

        copy($origen, $carpeta . '/' . $base . '.xlsx');

        $this->copias[] = $carpeta . '/' . $base;

        return 'imported_files/' . $base . '.xlsx';
    }

    /**
     * POST /api/ai-excel-import/import de artículos (el endpoint del modal) con las decisiones.
     *
     * @param  string     $fixture
     * @param  array|null $equivalencias  null = el campo no viaja (SPA vieja)
     * @param  array      $extra
     * @return \App\Models\ImportHistory
     */
    protected function importar_articulos($fixture, $equivalencias = null, array $extra = [])
    {
        $data = array_merge([
            'excel_path'      => $this->copiar_a_storage($fixture),
            'model'           => 'article',
            'columns'         => $this->columnas_articulos(),
            'start_row'       => 2,
            'finish_row'      => 99999,
            'provider_id'     => null,
            'create_and_edit' => true,
            'registrar_art_cre'                                  => true,
            'registrar_art_act'                                  => true,
            'actualizar_por_provider_code'                       => true,
            'actualizar_proveedor'                               => true,
            'permitir_provider_code_repetido'                    => false,
            'permitir_provider_code_repetido_en_multi_providers' => true,
            'actualizar_articulos_de_otro_proveedor'             => false,
        ], $extra);

        if (!is_null($equivalencias)) {
            $data['equivalencias'] = $equivalencias;
        }

        $this->postJson('/api/ai-excel-import/import', $data)->assertStatus(200);

        $import = ImportHistory::where('user_id', $this->tenant->id)->orderBy('id', 'DESC')->first();

        $this->assertNotNull($import, 'La importación no dejó ImportHistory.');
        $this->assertNotSame('fallo', (string) $import->status, 'La importación falló: ' . (string) $import->error_message);

        $this->assertInvariantesDeConteo($import);

        return $import;
    }

    /**
     * POST /api/ai-excel-import/import de clientes o proveedores.
     *
     * @param  string     $modelo  client | provider
     * @param  string     $fixture
     * @param  array      $columnas
     * @param  array|null $equivalencias
     * @return void
     */
    protected function importar_modelo($modelo, $fixture, array $columnas, $equivalencias = null)
    {
        $data = [
            'excel_path'      => $this->copiar_a_storage($fixture),
            'model'           => $modelo,
            'columns'         => $columnas,
            'start_row'       => 2,
            'finish_row'      => null,
            'create_and_edit' => true,
        ];

        if (!is_null($equivalencias)) {
            $data['equivalencias'] = $equivalencias;
        }

        $this->postJson('/api/ai-excel-import/import', $data)->assertStatus(200);
    }

    /**
     * POST /api/ai-excel-import/get-recomendacion. Con la cola en sync la corrida termina adentro
     * del request; devuelve el `resultado` leído de la base.
     *
     * @param  string $modelo
     * @param  string $fixture
     * @param  array  $columnas
     * @param  bool|null $chequear  null = la bandera no viaja
     * @return array
     */
    protected function recomendacion($modelo, $fixture, array $columnas, $chequear)
    {
        $data = [
            'excel_path'     => $this->copiar_a_storage($fixture),
            'provider_id'    => null,
            'column_mapping' => $this->column_mapping($columnas),
            'model'          => $modelo,
        ];

        if (!is_null($chequear)) {
            $data['chequear_relaciones'] = $chequear;
        }

        $respuesta = $this->postJson('/api/ai-excel-import/get-recomendacion', $data)->assertStatus(202);

        $estado = $this->getJson('/api/ai-excel-import/analysis/' . $respuesta->json('analysis_uuid'))->assertStatus(200);

        $this->assertSame('listo', $estado->json('estado'), 'La corrida no terminó: ' . json_encode($estado->json()));

        return $estado->json('resultado');
    }

    /* ------------------------------------------------------------------
     * IA falsa
     * ------------------------------------------------------------------ */

    /**
     * Falsea la IA. La de relaciones contesta lo que diga $elegir (fn(array $consulta): c|null)
     * para cada consulta; la de la recomendación de artículos, una recomendación válida.
     *
     * @param  callable|null $elegir   null = la IA de relaciones contesta $status / $texto_crudo
     * @param  int           $status
     * @param  string|null   $texto_crudo  texto de la respuesta tal cual (para un JSON roto)
     * @return void
     */
    protected function falsear_ia($elegir, $status = 200, $texto_crudo = null)
    {
        $test = $this;

        $this->llamadas_relaciones = [];
        $this->llamadas_recomendacion = 0;

        Http::fake(function ($request) use ($test, $elegir, $status, $texto_crudo) {
            $datos  = $request->data();
            $prompt = isset($datos['messages'][0]['content']) ? (string) $datos['messages'][0]['content'] : '';

            if (strpos($prompt, 'Valores a revisar (JSON):') === false) {
                $test->llamadas_recomendacion++;

                return Http::response($test->cuerpo_ia(json_encode([
                    'politica_colision'      => 'actualizar_todos',
                    'politica_intra_archivo' => 'ultima_gana',
                    'explicacion'            => 'Recomendación de prueba.',
                ])), 200);
            }

            $consultas = [];

            if (preg_match('/Valores a revisar \(JSON\):\s*(\[.*\])\s*\n/su', $prompt, $coincidencia)) {
                $decodificado = json_decode($coincidencia[1], true);
                $consultas = is_array($decodificado) ? $decodificado : [];
            }

            $test->llamadas_relaciones[] = $consultas;

            if ((int) $status !== 200) {
                return Http::response(['type' => 'error', 'error' => ['type' => 'api_error', 'message' => 'caída de prueba']], $status);
            }

            if (!is_null($texto_crudo)) {
                return Http::response($test->cuerpo_ia($texto_crudo), 200);
            }

            $respuestas = [];

            foreach ($consultas as $consulta) {
                $respuesta = call_user_func($elegir, $consulta);

                /* $elegir devuelve el resto de la entrada (elegido, seguro) o, a secas, el `c` / null. */
                $respuestas[] = is_array($respuesta)
                    ? array_merge(['n' => $consulta['n']], $respuesta)
                    : ['n' => $consulta['n'], 'elegido' => $respuesta];
            }

            return Http::response($test->cuerpo_ia(json_encode(['respuestas' => $respuestas])), 200);
        });
    }

    /**
     * Body con la forma real de la API de mensajes.
     *
     * @param  string $texto
     * @return array
     */
    public function cuerpo_ia($texto)
    {
        return [
            'model'       => 'modelo-de-prueba',
            'stop_reason' => 'end_turn',
            'content'     => [
                ['type' => 'text', 'text' => $texto],
            ],
            'usage'       => ['input_tokens' => 100, 'output_tokens' => 20],
        ];
    }

    /**
     * Un $elegir que elige, para cada valor del mapa, el candidato con ese nombre; para el resto
     * (y para los valores del mapa en null), `elegido: null` ("es otra cosa").
     *
     * @param  array $mapa    [valor del Excel => nombre del candidato]
     * @param  mixed $seguro  lo que viaja en `seguro`; ABSENT = la clave no viaja
     * @return callable
     */
    protected function elegir_por_nombre(array $mapa, $seguro = true)
    {
        return function (array $consulta) use ($mapa, $seguro) {
            if (!array_key_exists($consulta['valor'], $mapa) || is_null($mapa[$consulta['valor']])) {
                return ['elegido' => null];
            }

            foreach ($consulta['candidatos'] as $candidato) {
                if ($candidato['nombre'] === $mapa[$consulta['valor']]) {
                    $respuesta = ['elegido' => $candidato['c']];

                    if ($seguro !== self::ABSENT) {
                        $respuesta['seguro'] = $seguro;
                    }

                    return $respuesta;
                }
            }

            return ['elegido' => null];
        };
    }

    /* ------------------------------------------------------------------
     * Siembra y lectura
     * ------------------------------------------------------------------ */

    protected function crear_categoria($nombre, $user_id = null)
    {
        return Category::create([
            'num'     => 1,
            'name'    => $nombre,
            'user_id' => is_null($user_id) ? $this->tenant->id : $user_id,
        ]);
    }

    protected function crear_sub($nombre, $categoria)
    {
        return SubCategory::create([
            'num'         => 1,
            'name'        => $nombre,
            'category_id' => $categoria->id,
            'user_id'     => $this->tenant->id,
        ]);
    }

    protected function crear_marca($nombre)
    {
        return Brand::create(['name' => $nombre, 'user_id' => $this->tenant->id]);
    }

    protected function crear_proveedor($nombre)
    {
        return Provider::create(['name' => $nombre, 'user_id' => $this->tenant->id]);
    }

    /**
     * Artículo del tenant por código de proveedor (RP-01...).
     *
     * @param  string $codigo
     * @return \App\Models\Article
     */
    protected function articulo($codigo)
    {
        $articulo = Article::where('user_id', $this->tenant->id)->where('provider_code', $codigo)->first();

        $this->assertNotNull($articulo, 'No se creó el artículo ' . $codigo);

        return $articulo;
    }

    /**
     * Cuántas filas de una tabla del tenant se llaman EXACTAMENTE así (binario, no la collation).
     *
     * @param  string $tabla
     * @param  string $nombre
     * @return int
     */
    protected function cuantos_exactos($tabla, $nombre)
    {
        return \Illuminate\Support\Facades\DB::table($tabla)
            ->where('user_id', $this->tenant->id)
            ->whereRaw('BINARY name = ?', [$nombre])
            ->count();
    }

    /**
     * Ítem de relaciones_parecidas por clave.
     *
     * @param  array  $relaciones
     * @param  string $clave
     * @return array|null
     */
    protected function item(array $relaciones, $clave)
    {
        foreach ($relaciones['items'] as $item) {
            if ($item['clave'] === $clave) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param  array $relaciones
     * @return string[] las claves de todos los ítems, ordenadas
     */
    protected function claves(array $relaciones)
    {
        $claves = [];

        foreach ($relaciones['items'] as $item) {
            $claves[] = $item['clave'];
        }

        sort($claves);

        return $claves;
    }
}
