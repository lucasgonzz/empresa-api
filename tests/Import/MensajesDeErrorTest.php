<?php

namespace Tests\Import;

use App\Http\Controllers\Helpers\import\article\AiExcelAnalyzer;
use App\Http\Controllers\Helpers\import\client\AiClientAnalyzer;
use App\Http\Controllers\Helpers\import\provider\AiProviderAnalyzer;
use App\Jobs\RunExcelAnalysisJob;
use App\Models\ExcelAnalysisRun;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Nada de lo que se rompe adentro del análisis puede terminar, tal cual, en la pantalla
 * del comerciante.
 *
 * Este archivo cubre tres cosas distintas que se confundían entre sí:
 *
 *   1. 🔴 QueryException extends PDOException extends RuntimeException. Los handlers del
 *      job atajaban \RuntimeException y le pasaban el getMessage() crudo al usuario, así
 *      que CUALQUIER error de base durante el análisis le mostraba el SQL entero con los
 *      bindings — esquema y datos del comercio, bastante peor que una ruta de servidor.
 *      El arreglo es un catch de QueryException ANTES del de \RuntimeException, y el
 *      orden ES todo el arreglo: dado vuelta, el catch nuevo no corre nunca y nada avisa.
 *
 *   2. Alguien asumió que \RuntimeException quería decir "mensaje escrito para el
 *      usuario", y no es cierto. Había cinco con texto técnico que se mostraban tal cual,
 *      incluido el body crudo de Anthropic y el nombre de la variable de entorno que
 *      faltaba (o sea, cómo está configurado el servidor).
 *
 *   3. ⚠️ Los tres analizadores (artículos, clientes, proveedores) son copias casi
 *      idénticas. Ya pasó en este módulo que un arreglo se hizo en uno solo y se perdió
 *      en los otros dos, y nadie se enteró hasta que un chequeo independiente lo midió.
 *      Por eso acá se corre la MISMA causa contra los tres y se exige el MISMO mensaje.
 *
 * Extiende ImportTestCase (y no Tests\TestCase) porque casi todo pega contra el endpoint
 * real /api/ai-excel-import/analyze, que necesita el tenant sembrado y la sesión
 * autenticada. Con QUEUE_CONNECTION=sync (ver phpunit.xml) el RunExcelAnalysisJob corre
 * inline adentro del POST, así que al volver del request la corrida ya está en estado
 * final y su columna `error` tiene exactamente el texto que va a ver el usuario.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos
 * nombrados, union types, promoción de constructor, readonly, enum ni #[...].
 */
class MensajesDeErrorTest extends ImportTestCase
{
    /** Fixture chico y de una sola hoja: acá no se mide el mapeo, se mide el error. */
    const FIXTURE = '15_una_sola_hoja.xlsx';

    /**
     * Los tres modelos que soporta el análisis, con la clase que los atiende.
     *
     * @var array
     */
    const ANALIZADORES = [
        'article'  => AiExcelAnalyzer::class,
        'client'   => AiClientAnalyzer::class,
        'provider' => AiProviderAnalyzer::class,
    ];

    /**
     * Los seis archivos del grupo. Se escanean como texto para las guardas de orden de
     * catch y de "ningún mensaje al usuario concatena getMessage()".
     *
     * @var array
     */
    const FUENTES = [
        'app/Http/Controllers/AiExcelImportController.php',
        'app/Http/Controllers/AdminSync/AiExcelImportController.php',
        'app/Jobs/RunExcelAnalysisJob.php',
        'app/Http/Controllers/Helpers/import/article/AiExcelAnalyzer.php',
        'app/Http/Controllers/Helpers/import/client/AiClientAnalyzer.php',
        'app/Http/Controllers/Helpers/import/provider/AiProviderAnalyzer.php',
    ];

    /* =====================================================================
     * Helpers
     * ================================================================== */

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
     * Copia temporal del fixture, lista para subir.
     *
     * El endpoint hace storeAs(), que MUEVE el archivo subido: pasarle el fixture directo
     * se lo llevaría de la carpeta y dejaría al resto de la suite sin él. Mismo criterio
     * que ImportTestCase::importar().
     *
     * @return \Illuminate\Http\UploadedFile
     */
    protected function subida()
    {
        $origen = $this->fixture(self::FIXTURE);

        $this->assertFileExists($origen, 'Falta el fixture ' . self::FIXTURE);

        $copia = sys_get_temp_dir() . '/' . uniqid('fixture_') . '_' . self::FIXTURE;
        copy($origen, $copia);

        return new UploadedFile(
            $copia,
            self::FIXTURE,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );
    }

    /**
     * Corre el análisis real de un modelo contra Anthropic falseado y devuelve la corrida
     * ya terminada.
     *
     * @param  string   $model  article | client | provider
     * @param  mixed    $fake   Lo que se le pasa a Http::fake() (array de rutas o closure)
     * @param  string   $api_key  Clave de API a configurar ('' para simular que falta)
     * @return \App\Models\ExcelAnalysisRun
     */
    protected function analizar($model, $fake, $api_key = 'fake-key')
    {
        config(['services.anthropic.api_key' => $api_key]);

        /*
         * 🔴 Factory nueva ANTES de cada fake, y no es prolijidad.
         *
         * Http::fake() no reemplaza los stubs: los ACUMULA sobre la misma Factory (hace
         * merge, tanto con array de URLs como con closure), y en el match gana el primero
         * que se registró. Un test que corre tres causas seguidas contra el mismo endpoint
         * se queda con la respuesta de la PRIMERA para las tres, y las otras dos pasan a
         * medir algo que no es lo que dicen medir. Medido: la causa "529 sobrecargado"
         * seguía recibiendo el 400 de la causa anterior.
         */
        Http::swap(new \Illuminate\Http\Client\Factory());

        /* Nunca sale a internet: intercepta el POST que hace call_claude(). */
        Http::fake($fake);

        $respuesta = $this->post('/api/ai-excel-import/analyze', [
            'excel_file' => $this->subida(),
            'model'      => $model,
        ]);

        $respuesta->assertStatus(202);

        $uuid = $respuesta->json('analysis_uuid');

        $this->assertNotEmpty($uuid, 'El análisis no devolvió analysis_uuid.');

        $run = ExcelAnalysisRun::where('uuid', $uuid)->first();

        $this->assertNotNull($run, 'No quedó la ExcelAnalysisRun del análisis.');

        return $run;
    }

    /**
     * El texto que efectivamente ve el usuario para un modelo y una causa dados.
     *
     * @param  string $model
     * @param  mixed  $fake
     * @param  string $api_key
     * @return string
     */
    protected function mensaje_al_usuario($model, $fake, $api_key = 'fake-key')
    {
        $run = $this->analizar($model, $fake, $api_key);

        $this->assertSame(
            'error',
            $run->estado,
            'La corrida de ' . $model . ' tenía que terminar en error.'
        );

        $this->assertNotEmpty(
            $run->error,
            'La corrida de ' . $model . ' terminó en error pero sin ningún mensaje para el usuario.'
        );

        return (string) $run->error;
    }

    /**
     * Respuesta HTTP falsa de Anthropic con el status y el tipo de error indicados.
     *
     * El body lleva marcas reconocibles a propósito: si alguna de ellas aparece en el
     * mensaje del usuario, es que el body crudo se está filtrando a la pantalla.
     *
     * @param  int    $status
     * @param  string $tipo
     * @return array
     */
    protected function claude_falla($status, $tipo)
    {
        return [
            'api.anthropic.com/*' => Http::response([
                'type'  => 'error',
                'error' => [
                    'type'    => $tipo,
                    'message' => 'MARCA_INTERNA_DEL_BODY_DE_ANTHROPIC',
                ],
            ], $status),
        ];
    }

    /**
     * Substrings que ningún mensaje al usuario puede contener nunca.
     *
     * @return array
     */
    protected function prohibidos()
    {
        return [
            'SQLSTATE',
            'select ',
            'insert ',
            'update ',
            'ANTHROPIC',
            'Claude API',
            storage_path(),
            storage_path('app'),
            'imported_files',
            'Could not open',
            '/var/',
        ];
    }

    /**
     * @param  string $mensaje
     * @param  string $contexto
     * @return void
     */
    protected function assertMensajeLimpio($mensaje, $contexto)
    {
        foreach ($this->prohibidos() as $prohibido) {
            $this->assertStringNotContainsString(
                $prohibido,
                $mensaje,
                $contexto . ' — el mensaje al usuario filtra "' . $prohibido . '": ' . $mensaje
            );
        }

        /* La ruta de Windows del servidor, que es como se filtraba en este entorno. */
        $this->assertStringNotContainsString(':\\', $mensaje, $contexto . ' — hay una ruta de servidor adentro.');
    }

    /**
     * Código fuente de uno de los seis archivos del grupo.
     *
     * @param  string $ruta_relativa
     * @return string
     */
    protected function fuente($ruta_relativa)
    {
        $ruta = base_path($ruta_relativa);

        $this->assertFileExists($ruta, 'No existe ' . $ruta_relativa);

        return (string) file_get_contents($ruta);
    }

    /* =====================================================================
     * 1. El SQL en pantalla
     * ================================================================== */

    /**
     * 🔴 EL TEST MÁS IMPORTANTE DEL ARCHIVO.
     *
     * Se rompe la base EN EL MEDIO del análisis —adentro de call_claude(), o sea con el
     * archivo ya abierto y el analizador andando— y se mira exactamente lo que queda en
     * `excel_analysis_runs.error`, que es el texto que el frontend le muestra al usuario.
     *
     * La consulta que revienta es real y trae SQL de verdad en el getMessage():
     *
     *     SQLSTATE[42S02]: Base table or view not found: 1146 Table
     *     'empresa_testing_sN.tabla_que_no_existe_...' doesn't exist
     *     (SQL: select * from tabla_que_no_existe_... where user_id = 5)
     *
     * Sin el catch de QueryException —o con el catch puesto DESPUÉS del de
     * \RuntimeException, que es lo mismo porque entonces no corre nunca— ese texto entero
     * queda en `error` y se muestra. Con el arreglo queda MENSAJE_ERROR_DE_BASE.
     *
     * @return void
     */
    public function test_un_error_de_base_durante_el_analisis_no_le_muestra_el_sql_al_usuario()
    {
        $tabla = 'tabla_que_no_existe_mensajes_de_error';

        $mensaje = $this->mensaje_al_usuario('article', function () use ($tabla) {
            /* Explota adentro de call_claude(), en el medio del try de handle_analisis(). */
            DB::select('select * from ' . $tabla . ' where user_id = 5');
        });

        $this->assertSame(
            RunExcelAnalysisJob::MENSAJE_ERROR_DE_BASE,
            $mensaje,
            'Un error de base tiene que dar el mensaje de base, no el getMessage() de la QueryException.'
        );

        $this->assertStringNotContainsString('SQLSTATE', $mensaje);
        $this->assertStringNotContainsString($tabla, $mensaje);
        $this->assertStringNotContainsString('select ', $mensaje);
        $this->assertStringNotContainsString('user_id', $mensaje);

        $this->assertMensajeLimpio($mensaje, 'QueryException durante el análisis');
    }

    /**
     * El mismo agujero, en los otros dos analizadores: la guarda vive en el job, así que
     * tiene que valer igual para clientes y para proveedores.
     *
     * @return void
     */
    public function test_el_error_de_base_da_el_mismo_mensaje_en_los_tres_modelos()
    {
        $tabla = 'tabla_que_no_existe_mensajes_de_error';

        $fake = function () use ($tabla) {
            DB::select('select * from ' . $tabla . ' where user_id = 5');
        };

        $mensajes = [];

        foreach (array_keys(self::ANALIZADORES) as $model) {
            $mensajes[$model] = $this->mensaje_al_usuario($model, $fake);

            $this->assertSame(
                RunExcelAnalysisJob::MENSAJE_ERROR_DE_BASE,
                $mensajes[$model],
                'El modelo ' . $model . ' no da el mensaje de error de base.'
            );

            $this->assertMensajeLimpio($mensajes[$model], 'QueryException en ' . $model);
        }

        $this->assertCount(1, array_unique(array_values($mensajes)));
    }

    /**
     * El orden de los catch, leído del fuente.
     *
     * El test de arriba prueba el orden en handle_analisis() por comportamiento. Esta
     * guarda cubre los otros dos lugares donde conviven los dos catch y donde una
     * inversión no la ve nadie: handle_recomendacion() y AdminSync::analyze().
     *
     * @return void
     */
    public function test_el_catch_de_query_exception_va_antes_del_de_runtime_exception()
    {
        $archivos = [
            'app/Jobs/RunExcelAnalysisJob.php',
            'app/Http/Controllers/AdminSync/AiExcelImportController.php',
        ];

        foreach ($archivos as $archivo) {
            $codigo = $this->fuente($archivo);

            $posiciones_query   = [];
            $posiciones_runtime = [];

            $offset = 0;
            while (($pos = strpos($codigo, 'catch (\Illuminate\Database\QueryException', $offset)) !== false) {
                $posiciones_query[] = $pos;
                $offset = $pos + 1;
            }

            $offset = 0;
            while (($pos = strpos($codigo, 'catch (\RuntimeException', $offset)) !== false) {
                $posiciones_runtime[] = $pos;
                $offset = $pos + 1;
            }

            $this->assertNotEmpty(
                $posiciones_query,
                $archivo . ' perdió el catch de QueryException: vuelve el SQL a la pantalla.'
            );

            $this->assertSameSize(
                $posiciones_runtime,
                $posiciones_query,
                $archivo . ': cada catch de \RuntimeException necesita el suyo de QueryException adelante.'
            );

            foreach ($posiciones_runtime as $i => $pos_runtime) {
                $this->assertLessThan(
                    $pos_runtime,
                    $posiciones_query[$i],
                    $archivo . ': el catch de QueryException quedó DESPUÉS del de \RuntimeException, '
                        . 'así que no corre nunca (QueryException extends PDOException extends RuntimeException).'
                );
            }
        }
    }

    /* =====================================================================
     * 2. Los mensajes técnicos que se mostraban tal cual
     * ================================================================== */

    /**
     * El body crudo de Anthropic no puede llegar a la pantalla. Antes el mensaje era
     * literalmente 'Error al comunicarse con Claude API (HTTP 429): {"type":"error",...}'.
     *
     * @return void
     */
    public function test_el_body_crudo_de_anthropic_no_llega_a_la_pantalla()
    {
        $mensaje = $this->mensaje_al_usuario('article', $this->claude_falla(429, 'rate_limit_error'));

        $this->assertSame(AiExcelAnalyzer::MENSAJE_IA_RECHAZO, $mensaje);

        $this->assertStringNotContainsString('MARCA_INTERNA_DEL_BODY_DE_ANTHROPIC', $mensaje);
        $this->assertStringNotContainsString('429', $mensaje);
        $this->assertStringNotContainsString('rate_limit_error', $mensaje);
        $this->assertStringNotContainsString('{', $mensaje);

        $this->assertMensajeLimpio($mensaje, 'HTTP 429 de Anthropic');
    }

    /**
     * Que falte la clave de API es un problema de configuración del servidor, no del
     * usuario. El mensaje de antes —'La clave ANTHROPIC_API_KEY no está configurada en el
     * entorno.'— le contaba a un tercero cómo está armado este sistema.
     *
     * @return void
     */
    public function test_la_falta_de_clave_de_api_no_le_cuenta_al_usuario_como_esta_configurado_el_servidor()
    {
        $mensaje = $this->mensaje_al_usuario(
            'article',
            ['api.anthropic.com/*' => Http::response([], 200)],
            ''
        );

        $this->assertSame(AiExcelAnalyzer::MENSAJE_IA_SIN_CONFIGURAR, $mensaje);

        $this->assertStringNotContainsString('ANTHROPIC', $mensaje);
        $this->assertStringNotContainsString('API_KEY', $mensaje);
        $this->assertStringNotContainsString('entorno', $mensaje);

        $this->assertMensajeLimpio($mensaje, 'sin ANTHROPIC_API_KEY');
    }

    /**
     * El getMessage() de la excepción sólo puede llegar al usuario por UN camino: el catch
     * de \RuntimeException, que es el canal reservado para los mensajes que sí están
     * escritos para él (ExcelWorkbookReader::MENSAJE_ARCHIVO_ILEGIBLE, las cinco constantes
     * MENSAJE_IA_* de los analizadores). Desde cualquier otro catch —\Throwable,
     * QueryException, el que sea— es una fuga.
     *
     * Se miran las dos salidas que efectivamente le hablan al usuario: `response()->json()`
     * en los controladores y `finalizar_con_error()` en el job. Los arrays de Log::error
     * quedan afuera a propósito: ahí el getMessage() sí va, y completo, que es justamente
     * donde sirve.
     *
     * @return void
     */
    public function test_el_get_message_solo_llega_al_usuario_desde_el_catch_de_runtime_exception()
    {
        $salidas = ['response()->json(', 'finalizar_con_error('];

        foreach (self::FUENTES as $archivo) {
            $codigo = $this->fuente($archivo);

            foreach ($salidas as $salida) {
                $offset = 0;

                while (($pos = strpos($codigo, $salida, $offset)) !== false) {
                    $offset = $pos + 1;

                    /*
                     * El bloque va desde la llamada hasta el ");" que la cierra. El corte
                     * importa: con una ventana fija de N caracteres se cuela el catch
                     * siguiente y el test falla por el Log::error del vecino.
                     */
                    $fin   = strpos($codigo, ');', $pos);
                    $largo = ($fin === false) ? 500 : min(($fin - $pos) + 2, 500);

                    $bloque = substr($codigo, $pos, $largo);

                    if (strpos($bloque, 'getMessage()') === false) {
                        continue;
                    }

                    /* Tipo del catch que envuelve esta salida (el más cercano hacia atrás). */
                    $pos_catch = strrpos(substr($codigo, 0, $pos), 'catch (');

                    $tipo = ($pos_catch === false)
                                ? '(ningún catch)'
                                : trim(substr($codigo, $pos_catch + 7, strpos($codigo, ' $', $pos_catch) - ($pos_catch + 7)));

                    $this->assertSame(
                        '\RuntimeException',
                        $tipo,
                        $archivo . ': hay un "' . $salida . '" que le manda el getMessage() al usuario desde un '
                            . 'catch de ' . $tipo . '. Ahí adentro viaja la ruta del servidor y, si el que falló '
                            . 'fue MySQL, el SQL entero con los bindings. El único catch que puede pasar el '
                            . 'getMessage() es el de \RuntimeException, y sólo porque los mensajes que llegan '
                            . 'por ahí están escritos para el usuario a propósito.'
                    );
                }
            }
        }
    }

    /* =====================================================================
     * 3. Los tres analizadores, la misma causa, el mismo mensaje
     * ================================================================== */

    /**
     * ⚠️ La guarda contra el defecto que este módulo ya sufrió: un arreglo hecho en uno de
     * los tres clones y perdido en los otros dos.
     *
     * Comparación directa de las constantes, sin tocar la base ni la red. Es barata y es
     * la que va a fallar primero si alguien toca un solo archivo.
     *
     * @return void
     */
    public function test_los_tres_analizadores_declaran_los_mismos_mensajes()
    {
        $constantes = [
            'MENSAJE_IA_RECHAZO',
            'MENSAJE_IA_NO_DISPONIBLE',
            'MENSAJE_IA_SIN_RESPUESTA',
            'MENSAJE_IA_RESPUESTA_ILEGIBLE',
            'MENSAJE_IA_SIN_CONFIGURAR',
        ];

        foreach ($constantes as $constante) {
            $valores = [];

            foreach (self::ANALIZADORES as $model => $clase) {
                $this->assertTrue(
                    defined($clase . '::' . $constante),
                    $clase . ' no declara ' . $constante . ': se perdió la copia en ese analizador.'
                );

                $valores[$model] = constant($clase . '::' . $constante);
            }

            $this->assertCount(
                1,
                array_unique(array_values($valores)),
                $constante . ' no es igual en los tres analizadores: ' . json_encode($valores, JSON_UNESCAPED_UNICODE)
            );

            $this->assertMensajeLimpio($valores['article'], $constante);
        }
    }

    /**
     * Y lo mismo medido de punta a punta: la MISMA causa contra los tres analizadores
     * tiene que dejar el MISMO texto en pantalla.
     *
     * Las tres causas son las que el usuario efectivamente puede encontrarse: la API
     * contesta un error duro, la API está sobrecargada, y la API contesta algo que no se
     * puede leer. La de "sobrecargado" importa doble: el bloque que la detecta existía
     * SÓLO en el analizador de artículos, así que clientes y proveedores veían el error
     * crudo.
     *
     * @return void
     */
    public function test_los_tres_analizadores_dan_el_mismo_mensaje_ante_la_misma_causa()
    {
        $causas = [
            'la API rechaza el pedido' => [
                'fake'     => $this->claude_falla(400, 'invalid_request_error'),
                'esperado' => AiExcelAnalyzer::MENSAJE_IA_RECHAZO,
            ],
            'la API está sobrecargada' => [
                'fake'     => $this->claude_falla(529, 'overloaded_error'),
                'esperado' => AiExcelAnalyzer::MENSAJE_IA_NO_DISPONIBLE,
            ],
            'la API contesta algo ilegible' => [
                'fake'     => ['api.anthropic.com/*' => Http::response(['content' => []], 200)],
                'esperado' => AiExcelAnalyzer::MENSAJE_IA_RESPUESTA_ILEGIBLE,
            ],
        ];

        foreach ($causas as $descripcion => $causa) {
            $mensajes = [];

            foreach (array_keys(self::ANALIZADORES) as $model) {
                $mensajes[$model] = $this->mensaje_al_usuario($model, $causa['fake']);

                $this->assertMensajeLimpio($mensajes[$model], $descripcion . ' / ' . $model);
            }

            $this->assertCount(
                1,
                array_unique(array_values($mensajes)),
                'Con "' . $descripcion . '" los tres analizadores dan mensajes distintos: '
                    . json_encode($mensajes, JSON_UNESCAPED_UNICODE)
            );

            $this->assertSame(
                $causa['esperado'],
                $mensajes['article'],
                'Con "' . $descripcion . '" el mensaje no es el que se acordó.'
            );
        }
    }

    /**
     * El .xls viejo sigue dando SU mensaje y no lo pisa ninguno de los nuevos.
     *
     * Va acá porque es la contraprueba de todo lo demás: el catch de \RuntimeException
     * tiene que seguir dejando pasar los mensajes que SÍ están escritos para el usuario.
     * Si al agregar el catch de QueryException adelante alguien rompiera esa cadena, el
     * usuario perdería la única instrucción útil que hay en todo el módulo ("guardalo
     * como .xlsx").
     *
     * @return void
     */
    public function test_el_mensaje_del_xls_viejo_sigue_llegando_entero()
    {
        config(['services.anthropic.api_key' => 'fake-key']);

        Http::fake(['api.anthropic.com/*' => Http::response([], 200)]);

        $origen = $this->fixture('16_viejo.xls');

        $this->assertFileExists($origen);

        $copia = sys_get_temp_dir() . '/' . uniqid('fixture_') . '_16_viejo.xls';
        copy($origen, $copia);

        $respuesta = $this->post('/api/ai-excel-import/analyze', [
            'excel_file' => new UploadedFile($copia, '16_viejo.xls', 'application/vnd.ms-excel', null, true),
            'model'      => 'article',
        ]);

        $respuesta->assertStatus(202);

        $run = ExcelAnalysisRun::where('uuid', $respuesta->json('analysis_uuid'))->first();

        $this->assertNotNull($run);
        $this->assertSame('error', $run->estado);

        $this->assertStringContainsString(
            '.xlsx',
            (string) $run->error,
            'El mensaje del .xls viejo perdió la instrucción de qué hacer.'
        );

        $this->assertMensajeLimpio((string) $run->error, 'xls viejo');
    }
}
