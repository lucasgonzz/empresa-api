<?php

namespace Tests\Feature\Import;

use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\Provider;
use App\Models\User;
use App\Notifications\GlobalNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\EmpresaTestCase;
use Tests\Feature\CuentaCorriente\ArmaCadenas;

/**
 * Misión importacion-saldo-celdas-de-texto (9/10/2026) — el "Saldo actual" de la importación de
 * clientes y de proveedores lee las celdas de TEXTO como los artículos.
 *
 * El bug: `procesarSaldoImportacion()` (clientes) y `setSaldoInicial()` (proveedores) hacían
 * `(float)` del string de la celda. `"$ 52.000,50"` daba 0, `"52.000"` daba 52, `"1.234,56"` daba
 * 1.234, y un texto que no es un número (`"s/d"`, `"-"`, una fórmula, que llega como su texto porque
 * ningún importador calcula fórmulas) daba 0. Lo más grave: a un cliente EXISTENTE con saldo, una
 * celda ilegible le dejaba el saldo en 0 con una nota de crédito por todo lo que debía.
 *
 * Además, `ImportHelper::getColumnValue()` descartaba toda celda que valiera −1 entero (comparaba el
 * VALOR de la celda con el centinela de "columna sin usar", que vive en el mapeo): un saldo de −1 no
 * se importaba.
 *
 * Lo que fija esta clase:
 *   - una celda de TEXTO se lee con `ImportHelper::parseNumericValue()` ('auto'), como en artículos,
 *     y el signo antes de la moneda (`-$ 7.600`) vale;
 *   - una celda NUMÉRICA entra tal cual (1.234 es uno coma dos, no mil doscientos);
 *   - una celda ilegible NO carga nada (ni ajusta a un cliente existente), la fila se importa igual y
 *     la notificación de fin la lista con su número de fila;
 *   - −1 es un saldo como cualquier otro.
 *
 * 🔴 Las celdas de texto se escriben con `setCellValueExplicit(..., TYPE_STRING)`: con `fromArray()`
 * el binder convierte "52.000" en el número 52 y el test no prueba lo que dice. `xlsx()` relee el
 * archivo y verifica el tipo de cada celda antes de devolverlo.
 *
 * Todo por los endpoints reales: el modal con IA (`api/ai-excel-import/import`, que es también el
 * camino del motor de `/implementar`: hace `Request::create()` de esa ruta en el mismo proceso), la
 * importación clásica (`api/client|provider/excel/import`) y admin-sync
 * (`api/admin-sync/ai-excel-import/import`, el que usa admin-api desde `ImplementationImportService`).
 *
 * DatabaseTransactions (heredado de EmpresaTestCase): nada de lo que se crea acá sobrevive al test.
 * Los Excel temporales y las copias en `storage/app/imported_files` se borran en tearDown().
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group importacion-saldo-celdas-de-texto
 */
class SaldoCeldasDeTextoImportTest extends EmpresaTestCase
{
    use ArmaCadenas;

    /** Clave del admin para el endpoint de admin-sync (se setea en config() en el test). */
    const CLAVE_ADMIN = 'clave-de-prueba';

    /** Título del bloque de la notificación que lista los saldos ilegibles. */
    const TITULO_DEL_AVISO = 'Saldos del Excel que no se pudieron leer';

    /** @var int Dueño de la sesión (el usuario 500 del fixture). */
    protected $user_id;

    /** @var string Sufijo para que los nombres no choquen con nada de la base de testing. */
    protected $sufijo;

    /** @var array Archivos temporales (los .xlsx de %TEMP% y copias en storage) a borrar al terminar. */
    protected $archivos_temporales = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user_id = $this->app['auth']->user()->id;

        $this->sufijo = uniqid();
    }

    protected function tearDown(): void
    {
        foreach ($this->archivos_temporales as $ruta) {
            if (is_file($ruta)) {
                @unlink($ruta);
            }
        }

        $this->archivos_temporales = [];

        parent::tearDown();
    }

    /* =====================================================================
     * Ayudantes
     * ================================================================== */

    /**
     * Nombre único para un cliente o proveedor del test. Empieza con mayúscula porque el ABM de
     * clientes le hace ucfirst() al nombre.
     *
     * @param  string $base
     * @return string
     */
    protected function nombre($base)
    {
        return 'Zz '.$base.' '.$this->sufijo;
    }

    /**
     * Una celda con fórmula para xlsx(): el texto de la fórmula, con el "=" adelante.
     *
     * @param  string $formula
     * @return array
     */
    protected function formula($formula)
    {
        return ['formula' => $formula];
    }

    /**
     * Escribe un .xlsx temporal con la cabecera y las filas dadas, con el TIPO de celda explícito:
     *
     *   string de PHP   -> celda de TEXTO (setCellValueExplicit, TYPE_STRING): "52.000" queda texto.
     *   int / float     -> celda NUMÉRICA.
     *   bool            -> celda VERDADERO/FALSO (TYPE_BOOL).
     *   formula('=..')  -> celda con FÓRMULA.
     *   null            -> celda VACÍA.
     *
     * Después relee el archivo y verifica que cada celda quedó del tipo pedido: si el binder de
     * PhpSpreadsheet hubiera convertido un texto en número, el test no probaría lo que dice. A las
     * de texto les verifica además el contenido exacto (BOM, espacios y NBSP de los bordes
     * incluidos), que es lo que llega al importador.
     *
     * @param  array $cabecera
     * @param  array $filas
     * @return string Ruta absoluta.
     */
    protected function xlsx(array $cabecera, array $filas)
    {
        $spreadsheet = new Spreadsheet();
        $hoja = $spreadsheet->getActiveSheet();

        // Tipo esperado de cada celda escrita, para verificarlo al releer: [referencia => tipo].
        $tipos_esperados = [];

        // Contenido esperado de cada celda de TEXTO: [referencia => string].
        $textos_esperados = [];

        foreach (array_merge([$cabecera], $filas) as $indice_fila => $fila) {
            foreach (array_values($fila) as $indice_columna => $valor) {

                if (is_null($valor)) {
                    continue;
                }

                $referencia = Coordinate::stringFromColumnIndex($indice_columna + 1).($indice_fila + 1);

                if (is_array($valor)) {
                    // setCellValue() con "=" adelante guarda una fórmula.
                    $hoja->setCellValue($referencia, $valor['formula']);
                    $tipos_esperados[$referencia] = DataType::TYPE_FORMULA;
                } elseif (is_string($valor)) {
                    $hoja->setCellValueExplicit($referencia, $valor, DataType::TYPE_STRING);
                    $tipos_esperados[$referencia] = DataType::TYPE_STRING;
                    $textos_esperados[$referencia] = $valor;
                } elseif (is_bool($valor)) {
                    $hoja->setCellValueExplicit($referencia, $valor, DataType::TYPE_BOOL);
                    $tipos_esperados[$referencia] = DataType::TYPE_BOOL;
                } else {
                    $hoja->setCellValue($referencia, $valor);
                    $tipos_esperados[$referencia] = DataType::TYPE_NUMERIC;
                }
            }
        }

        $ruta = sys_get_temp_dir().'/'.uniqid('zz_import_saldo_texto_').'.xlsx';

        $writer = new Xlsx($spreadsheet);
        $writer->save($ruta);

        $this->archivos_temporales[] = $ruta;

        // Premisa: el archivo tiene las celdas del tipo que el test dice probar.
        $releida = IOFactory::load($ruta)->getActiveSheet();

        foreach ($tipos_esperados as $referencia => $tipo) {
            $this->assertSame(
                $tipo,
                $releida->getCell($referencia)->getDataType(),
                'Premisa del test: la celda '.$referencia.' no quedó como "'.$tipo.'" en el Excel.'
            );
        }

        foreach ($textos_esperados as $referencia => $texto) {
            $this->assertSame(
                $texto,
                (string) $releida->getCell($referencia)->getValue(),
                'Premisa del test: el texto de la celda '.$referencia.' no quedó tal cual en el Excel.'
            );
        }

        return $ruta;
    }

    /**
     * Copia el Excel a `storage/app/imported_files`, que es donde lo deja `/analyze` y de donde lo
     * leen los dos endpoints con IA (`excel_path` es relativo a `storage/app`).
     *
     * @param  string $ruta
     * @return string Ruta relativa a storage/app.
     */
    protected function excel_en_storage($ruta)
    {
        $relativa = 'imported_files/zz_test_'.uniqid().'.xlsx';

        $destino = storage_path('app/'.$relativa);

        copy($ruta, $destino);

        $this->archivos_temporales[] = $destino;

        return $relativa;
    }

    /**
     * Payload común de los dos endpoints con IA.
     *
     * @param  string $model    'client' | 'provider'
     * @param  string $ruta
     * @param  array  $columns  Mapeo 0-based.
     * @param  array  $overrides
     * @return array
     */
    protected function payload_ia($model, $ruta, array $columns, array $overrides = [])
    {
        return array_merge([
            'excel_path'      => $this->excel_en_storage($ruta),
            'model'           => $model,
            'columns'         => $columns,
            'create_and_edit' => true,
            'start_row'       => 2,
            'finish_row'      => null,
        ], $overrides);
    }

    /**
     * El modal con IA de la SPA.
     *
     * @param  string $model    'client' | 'provider'
     * @param  string $ruta
     * @param  array  $columns  Mapeo 0-based. Por defecto: nombre en la 0 y saldo en la 1.
     * @return \Illuminate\Testing\TestResponse
     */
    protected function importar_por_ia($model, $ruta, array $columns = ['nombre' => 0, 'saldo_actual' => 1])
    {
        return $this->postJson('api/ai-excel-import/import', $this->payload_ia($model, $ruta, $columns));
    }

    /**
     * La importación clásica (modal viejo): el archivo sube con el request y el mapeo va 1-based.
     * Nombre en la columna 1 y saldo en la 2.
     *
     * @param  string $model  'client' | 'provider'
     * @param  string $ruta
     * @return \Illuminate\Testing\TestResponse
     */
    protected function importar_por_el_clasico($model, $ruta)
    {
        $copia = sys_get_temp_dir().'/'.uniqid('zz_import_saldo_texto_copia_').'.xlsx';

        copy($ruta, $copia);

        $this->archivos_temporales[] = $copia;

        return $this->post('api/'.$model.'/excel/import', [
            'models'            => new UploadedFile($copia, $model.'s.xlsx', null, null, true),
            'create_and_edit'   => 1,
            'start_row'         => 2,
            'finish_row'        => '',
            'prop_nombre'       => 1,
            'prop_saldo_actual' => 2,
        ]);
    }

    /**
     * El endpoint de admin-sync, el que usa admin-api (`ImplementationImportService`): sin sesión,
     * con el header de la clave y el `user_id` del comercio. Nombre en la columna 0 y saldo en la 1.
     *
     * NO es el camino del motor de `/implementar`: el motor hace `Request::create()` de
     * `/api/ai-excel-import/import` en el mismo proceso, o sea el de importar_por_ia().
     *
     * @param  string $model  'client' | 'provider'
     * @param  string $ruta
     * @return \Illuminate\Testing\TestResponse
     */
    protected function importar_por_admin_sync($model, $ruta)
    {
        config([
            'services.admin_api.require_api_key' => true,
            'services.admin_api.api_key'         => self::CLAVE_ADMIN,
        ]);

        return $this->postJson(
            'api/admin-sync/ai-excel-import/import',
            $this->payload_ia($model, $ruta, ['nombre' => 0, 'saldo_actual' => 1], ['user_id' => $this->user_id]),
            ['X-Admin-Api-Key' => self::CLAVE_ADMIN]
        );
    }

    /**
     * El único cliente o proveedor del comercio con ese nombre (la importación busca por nombre).
     *
     * @param  string $model_name  'client' | 'provider'
     * @param  string $nombre
     * @return \App\Models\Client|\App\Models\Provider
     */
    protected function por_nombre($model_name, $nombre)
    {
        $clase = $model_name == 'client' ? Client::class : Provider::class;

        $encontrados = $clase::where('user_id', $this->user_id)->where('name', $nombre)->get();

        $this->assertCount(1, $encontrados, 'Tiene que haber exactamente un '.$model_name.' "'.$nombre.'".');

        return $encontrados->first();
    }

    /**
     * La cuenta de una moneda de un cliente o proveedor.
     *
     * @param  string $model_name
     * @param  int    $model_id
     * @param  int    $moneda_id
     * @return \App\Models\CreditAccount|null
     */
    protected function cuenta($model_name, $model_id, $moneda_id = 1)
    {
        return CreditAccount::where('model_name', $model_name)
                            ->where('model_id', $model_id)
                            ->where('moneda_id', $moneda_id)
                            ->first();
    }

    /**
     * Los movimientos de una cuenta, provisorios incluidos.
     *
     * @param  \App\Models\CreditAccount $cuenta
     * @return \Illuminate\Support\Collection
     */
    protected function movimientos($cuenta)
    {
        return CurrentAcount::where('credit_account_id', $cuenta->id)->orderBy('id')->get();
    }

    /**
     * La cuenta en pesos tiene UN movimiento, el "Saldo inicial" con el saldo dado, del lado que
     * corresponde y con el monto en positivo; la cadena cierra; y la cuenta y el `saldo_pesos`
     * dicen lo mismo. (Mismo criterio que ProveedoresSaldoInicialImportTest.)
     *
     * @param  string $model_name
     * @param  mixed  $model
     * @param  float  $saldo
     * @return void
     */
    protected function assert_saldo_inicial($model_name, $model, $saldo)
    {
        $cuenta = $this->cuenta($model_name, $model->id);

        $this->assertNotNull($cuenta, 'El '.$model_name.' "'.$model->name.'" no tiene cuenta en pesos.');

        $movimientos = $this->movimientos($cuenta);

        $this->assertCount(1, $movimientos, 'La cuenta en pesos de "'.$model->name.'" tiene que tener un único "Saldo inicial" (saldo esperado '.$saldo.').');

        $saldo_inicial = $movimientos->first();

        $this->assertEquals('Saldo inicial', $saldo_inicial->detalle);

        if ($saldo >= 0) {
            $this->assertEqualsWithDelta($saldo, (float) $saldo_inicial->debe, 0.001, 'La deuda de "'.$model->name.'" va en el debe y tiene que ser '.$saldo.'.');
            $this->assertNull($saldo_inicial->haber);
        } else {
            $this->assertNull($saldo_inicial->debe);
            $this->assertEqualsWithDelta(abs($saldo), (float) $saldo_inicial->haber, 0.001, 'El saldo a favor de "'.$model->name.'" va en el haber, en positivo, y tiene que ser '.abs($saldo).'.');
        }

        $this->assertEqualsWithDelta($saldo, (float) $saldo_inicial->saldo, 0.001, 'El saldo del movimiento de "'.$model->name.'" no es el del Excel.');
        $this->assertEqualsWithDelta($saldo, (float) $cuenta->fresh()->saldo, 0.001, 'credit_accounts.saldo de "'.$model->name.'" no es el del Excel.');
        $this->assertEqualsWithDelta($saldo, (float) $model->fresh()->saldo_pesos, 0.001, 'saldo_pesos de "'.$model->name.'" no es el del Excel.');

        $this->assert_cadena_cierra($cuenta->id, 'Saldo inicial importado de "'.$model->name.'"');
    }

    /**
     * La cuenta en pesos del cliente/proveedor existe y no tiene ningún movimiento.
     *
     * @param  string $model_name
     * @param  mixed  $model
     * @return void
     */
    protected function assert_cuenta_vacia($model_name, $model)
    {
        $cuenta = $this->cuenta($model_name, $model->id);

        $this->assertNotNull($cuenta, 'El '.$model_name.' "'.$model->name.'" tiene que tener su cuenta en pesos.');

        $this->assertCount(0, $this->movimientos($cuenta), 'Un saldo ilegible no puede cargar ningún movimiento en la cuenta de "'.$model->name.'".');

        $this->assertEqualsWithDelta(0, (float) $cuenta->fresh()->saldo, 0.001);
    }

    /**
     * La única notificación de fin de importación que se mandó (desde el último Notification::fake()).
     *
     * @return \App\Notifications\GlobalNotification
     */
    protected function notificacion_de_fin()
    {
        $enviadas = Notification::sent(User::find($this->user_id), GlobalNotification::class);

        $this->assertCount(1, $enviadas, 'Tiene que salir exactamente una notificación de fin de importación.');

        return $enviadas->first();
    }

    /**
     * La notificación de fin de importación salió y no trae ningún bloque de avisos.
     *
     * @return void
     */
    protected function assert_notificacion_sin_avisos()
    {
        $this->assertSame([], $this->notificacion_de_fin()->info_to_show, 'La importación avisó algo que no tenía que avisar.');
    }

    /**
     * Los párrafos del bloque "Saldos del Excel que no se pudieron leer" de la notificación de fin.
     * Falla si el bloque no está.
     *
     * @return array
     */
    protected function parrafos_del_aviso_de_ilegibles()
    {
        $info = $this->notificacion_de_fin()->info_to_show;

        $this->assertIsArray($info);

        foreach ($info as $bloque) {
            if (isset($bloque['title']) && $bloque['title'] === self::TITULO_DEL_AVISO) {
                return $bloque['parrafos'];
            }
        }

        $this->fail('La notificación de fin no trae el bloque "'.self::TITULO_DEL_AVISO.'". Trae: '.json_encode($info, JSON_UNESCAPED_UNICODE));
    }

    /**
     * El párrafo de explicación del aviso de ilegibles (siempre el último): dice que el saldo no se
     * cargó, cómo escribirlo y que hay que volver a importar.
     *
     * @param  string $parrafo
     * @return void
     */
    protected function assert_explicacion_del_aviso($parrafo)
    {
        $this->assertStringContainsString('no se cargó', $parrafo);
        $this->assertStringContainsString('52000,50', $parrafo);
        $this->assertStringContainsString('52.000,50', $parrafo);
        $this->assertStringContainsString('-7600', $parrafo);
        $this->assertStringContainsString('fórmula', $parrafo);
        $this->assertStringContainsString('volvé a importar', $parrafo);
    }

    /**
     * Un cliente dado de alta desde el ABM, por el endpoint real, con un "Saldo inicial" cargado con
     * el botón de la cuenta corriente: el cliente EXISTENTE con saldo.
     *
     * `pasar_ventas_a_la_cuenta_corriente_sin_esperar_a_facturar` va porque el formulario de la SPA
     * lo manda siempre (es un checkbox, 0 por defecto) y `ClientController@store` lo escribe tal
     * cual: sin él, la columna NOT NULL revienta el insert.
     *
     * @param  string $base
     * @param  float  $saldo  Positivo: deuda (debe).
     * @return array  [Client, CreditAccount]
     */
    protected function cliente_existente_con_saldo($base, $saldo)
    {
        $respuesta = $this->postJson('api/client', [
                                'name' => $this->nombre($base),
                                'pasar_ventas_a_la_cuenta_corriente_sin_esperar_a_facturar' => 0,
                            ])
                          ->assertStatus(201);

        $cliente = Client::find($respuesta->json('model.id'));

        $cuenta = $this->cuenta('client', $cliente->id);

        $this->assertNotNull($cuenta, 'Premisa: el cliente del ABM nace con su cuenta en pesos.');

        $this->postJson('api/current-acount/saldo-inicial', [
            'credit_account_id' => $cuenta->id,
            'model_name'        => 'client',
            'model_id'          => $cliente->id,
            'is_for_debe'       => true,
            'saldo_inicial'     => $saldo,
        ])->assertStatus(201);

        $this->assert_saldo_inicial('client', $cliente, $saldo);

        return [$cliente, $cuenta];
    }

    /**
     * Las celdas de texto con formato y lo que tiene que quedar de cada una.
     *
     * Un saldo en dólares ("-USD 100") ya no está acá: desde la ronda de correcciones es ilegible
     * (la columna carga la cuenta en pesos). Lo cubre los_casos_borde_del_saldo_en_una_sola_importacion().
     *
     * @return array [[texto de la celda, saldo esperado], ...]
     */
    protected function textos_con_formato()
    {
        return [
            ['$ 52.000,50', 52000.5],
            ['52.000',      52000],
            ['1.234,56',    1234.56],
            ['-7.600',      -7600],
            ['-$ 7.600',    -7600],
            ['$ -1.500',    -1500],
            ['- $7.600',    -7600],
        ];
    }

    /* =====================================================================
     * 1. Texto con formato
     * ================================================================== */

    /**
     * Proveedores nuevos por el modal con IA: cada saldo escrito como TEXTO con formato de moneda,
     * de miles o con el signo antes de la moneda queda cargado como el número que es. Sin nada que
     * avisar.
     *
     * @test
     */
    public function por_el_modal_los_proveedores_nuevos_leen_el_saldo_escrito_como_texto()
    {
        $this->assert_textos_con_formato_por_el_modal('provider', 'Prov');
    }

    /**
     * Lo mismo para clientes nuevos.
     *
     * @test
     */
    public function por_el_modal_los_clientes_nuevos_leen_el_saldo_escrito_como_texto()
    {
        $this->assert_textos_con_formato_por_el_modal('client', 'Cli');
    }

    /**
     * Cuerpo común de los dos tests de texto con formato.
     *
     * @param  string $model_name
     * @param  string $prefijo
     * @return void
     */
    protected function assert_textos_con_formato_por_el_modal($model_name, $prefijo)
    {
        $filas = [];

        foreach ($this->textos_con_formato() as $i => $caso) {
            $filas[] = [$this->nombre($prefijo.' texto '.$i), $caso[0]];
        }

        Notification::fake();

        $this->importar_por_ia($model_name, $this->xlsx(['Nombre', 'Saldo'], $filas))->assertStatus(200);

        foreach ($this->textos_con_formato() as $i => $caso) {
            $this->assert_saldo_inicial($model_name, $this->por_nombre($model_name, $filas[$i][0]), $caso[1]);
        }

        $this->assert_notificacion_sin_avisos();
    }

    /* =====================================================================
     * 2. Celdas numéricas: tal cual
     * ================================================================== */

    /**
     * Una celda NUMÉRICA entra tal cual: no pasa por el lector de texto. 1.234 (un float con tres
     * decimales) es uno coma dos, NO mil doscientos treinta y cuatro (que es lo que daría leerla
     * como el texto "1.234"). Las columnas de saldo guardan dos decimales: queda 1,23.
     *
     * @test
     */
    public function las_celdas_numericas_entran_tal_cual_para_clientes_y_proveedores()
    {
        foreach (['client' => 'Cli', 'provider' => 'Prov'] as $model_name => $prefijo) {

            $casos = [
                [$this->nombre($prefijo.' num decimal'), 52000.5, 52000.5],
                [$this->nombre($prefijo.' num a favor'), -7600,   -7600],
                [$this->nombre($prefijo.' num 1.234'),   1.234,   1.23],
            ];

            $filas = [];

            foreach ($casos as $caso) {
                $filas[] = [$caso[0], $caso[1]];
            }

            $this->importar_por_ia($model_name, $this->xlsx(['Nombre', 'Saldo'], $filas))->assertStatus(200);

            foreach ($casos as $caso) {
                $this->assert_saldo_inicial($model_name, $this->por_nombre($model_name, $caso[0]), $caso[2]);
            }
        }
    }

    /* =====================================================================
     * 3. −1
     * ================================================================== */

    /**
     * Una celda numérica −1 es un saldo de −1 (a favor), para clientes y para proveedores. Antes
     * getColumnValue() la descartaba por compararla con el centinela de "columna sin usar".
     *
     * @test
     */
    public function un_saldo_de_menos_uno_se_importa_como_cualquier_otro()
    {
        foreach (['client' => 'Cli', 'provider' => 'Prov'] as $model_name => $prefijo) {

            $nombre_menos_uno = $this->nombre($prefijo.' menos uno');
            $nombre_menos_dos = $this->nombre($prefijo.' menos dos');

            $this->importar_por_ia($model_name, $this->xlsx(['Nombre', 'Saldo'], [
                [$nombre_menos_uno, -1],
                [$nombre_menos_dos, -2],
            ]))->assertStatus(200);

            $this->assert_saldo_inicial($model_name, $this->por_nombre($model_name, $nombre_menos_uno), -1);
            $this->assert_saldo_inicial($model_name, $this->por_nombre($model_name, $nombre_menos_dos), -2);
        }
    }

    /* =====================================================================
     * 4. Ilegible
     * ================================================================== */

    /**
     * Proveedores con saldos que no son un número ("abc", "-", "s/d" y una fórmula, que llega como su
     * texto): no se carga ningún movimiento, el proveedor se crea igual con su teléfono, el resto
     * del archivo se importa, y la notificación los lista con su fila.
     *
     * @test
     */
    public function proveedores_con_saldo_ilegible_se_crean_sin_saldo_y_se_avisan_con_su_fila()
    {
        $this->assert_ilegibles_por_el_modal('provider', 'Prov');
    }

    /**
     * Lo mismo para clientes. Antes, a cada cliente nuevo con saldo ilegible se le cargaba un
     * "Saldo inicial" de $0.
     *
     * @test
     */
    public function clientes_con_saldo_ilegible_se_crean_sin_saldo_y_se_avisan_con_su_fila()
    {
        $this->assert_ilegibles_por_el_modal('client', 'Cli');
    }

    /**
     * Cuerpo común de los dos tests de ilegibles.
     *
     * @param  string $model_name
     * @param  string $prefijo
     * @return void
     */
    protected function assert_ilegibles_por_el_modal($model_name, $prefijo)
    {
        $abc      = $this->nombre($prefijo.' abc');
        $guion    = $this->nombre($prefijo.' guion');
        $sd       = $this->nombre($prefijo.' sd');
        $formula  = $this->nombre($prefijo.' formula');
        $numero   = $this->nombre($prefijo.' numero');
        $texto_ok = $this->nombre($prefijo.' texto legible');

        // Fila 1: cabecera. Fila 2 en adelante: datos. La fórmula apunta a una celda numérica.
        $ruta = $this->xlsx(['Nombre', 'Telefono', 'Saldo'], [
            [$abc,      '11-1111', 'abc'],
            [$guion,    '11-2222', '-'],
            [$sd,       '11-3333', 's/d'],
            [$formula,  '11-4444', $this->formula('=C6*2')],
            [$numero,   '11-5555', 1500],
            [$texto_ok, '11-6666', '2.500'],
        ]);

        Notification::fake();

        $this->importar_por_ia($model_name, $ruta, ['nombre' => 0, 'telefono' => 1, 'saldo_actual' => 2])->assertStatus(200);

        $telefonos = [$abc => '11-1111', $guion => '11-2222', $sd => '11-3333', $formula => '11-4444'];

        foreach ($telefonos as $nombre => $telefono) {
            $modelo = $this->por_nombre($model_name, $nombre);

            $this->assertSame($telefono, $modelo->phone, 'La fila con saldo ilegible tiene que importar el resto de sus datos.');

            $this->assert_cuenta_vacia($model_name, $modelo);
        }

        // El resto del archivo se importó.
        $this->assert_saldo_inicial($model_name, $this->por_nombre($model_name, $numero), 1500);
        $this->assert_saldo_inicial($model_name, $this->por_nombre($model_name, $texto_ok), 2500);

        $parrafos = $this->parrafos_del_aviso_de_ilegibles();

        $this->assertCount(5, $parrafos, 'Un párrafo por fila ilegible y la explicación al final.');

        $this->assertSame('Fila 2, '.$abc.': "abc"', $parrafos[0]);
        $this->assertSame('Fila 3, '.$guion.': "-"', $parrafos[1]);
        $this->assertSame('Fila 4, '.$sd.': "s/d"', $parrafos[2]);
        $this->assertSame('Fila 5, '.$formula.': "=C6*2"', $parrafos[3]);

        $this->assert_explicacion_del_aviso($parrafos[4]);
    }

    /* =====================================================================
     * 5. Cliente existente
     * ================================================================== */

    /**
     * 🔴 Un cliente EXISTENTE con saldo y una celda ilegible: la cuenta queda como estaba, sin nota.
     * Antes la celda daba 0 y `ajustarSaldoPorImportacion()` le cargaba una nota de crédito por todo
     * lo que debía. Y se avisa.
     *
     * @test
     */
    public function un_cliente_existente_con_saldo_ilegible_queda_con_la_cuenta_como_estaba()
    {
        list($cliente, $cuenta) = $this->cliente_existente_con_saldo('Cli existente ilegible', 1000);

        Notification::fake();

        $this->importar_por_ia('client', $this->xlsx(['Nombre', 'Saldo'], [
            [$cliente->name, 's/d'],
        ]))->assertStatus(200);

        $this->assertCount(1, $this->movimientos($cuenta), 'Un saldo ilegible no puede ajustar la cuenta de un cliente existente.');

        $this->assert_saldo_inicial('client', $cliente, 1000);

        $parrafos = $this->parrafos_del_aviso_de_ilegibles();

        $this->assertSame('Fila 2, '.$cliente->name.': "s/d"', $parrafos[0]);
    }

    /**
     * El mismo cliente existente con el saldo escrito como "$ 52.000,50": se ajusta al monto entero
     * con una nota de débito por la diferencia (antes la celda daba 0 y la nota era de crédito).
     *
     * @test
     */
    public function un_cliente_existente_con_saldo_en_texto_se_ajusta_al_monto_completo()
    {
        list($cliente, $cuenta) = $this->cliente_existente_con_saldo('Cli existente texto', 1000);

        Notification::fake();

        $this->importar_por_ia('client', $this->xlsx(['Nombre', 'Saldo'], [
            [$cliente->name, '$ 52.000,50'],
        ]))->assertStatus(200);

        $movimientos = $this->movimientos($cuenta);

        $this->assertCount(2, $movimientos, 'El ajuste es UNA nota por la diferencia.');

        $nota = $movimientos->last();

        $this->assertEquals('Nota de debito', $nota->detalle, 'La diferencia es a cargo del cliente: nota de débito.');
        $this->assertEqualsWithDelta(51000.5, (float) $nota->debe, 0.001, 'La nota es por la diferencia entre 52.000,50 y 1.000.');

        $this->assertEqualsWithDelta(52000.5, (float) $cuenta->fresh()->saldo, 0.001, 'El cliente tiene que quedar con el saldo del Excel.');

        $this->assert_cadena_cierra($cuenta->id, 'Ajuste por importación de "'.$cliente->name.'"');

        $this->assert_notificacion_sin_avisos();
    }

    /* =====================================================================
     * 6. Reimportar
     * ================================================================== */

    /**
     * El mismo archivo de proveedores con celdas de texto, dos veces: ni proveedores repetidos ni un
     * segundo "Saldo inicial", y la segunda pasada no avisa nada (la cuenta ya tiene el saldo del
     * Excel: 'sin_cambios').
     *
     * @test
     */
    public function reimportar_proveedores_con_celdas_de_texto_no_duplica_ni_avisa()
    {
        $deuda     = $this->nombre('Prov reimp deuda');
        $a_favor   = $this->nombre('Prov reimp a favor');
        $sin_saldo = $this->nombre('Prov reimp sin saldo');

        $ruta = $this->xlsx(['Nombre', 'Saldo'], [
            [$deuda,     '$ 52.000,50'],
            [$a_favor,   '-$ 7.600'],
            [$sin_saldo, null],
        ]);

        $this->importar_por_ia('provider', $ruta)->assertStatus(200);

        // Solo se mira la notificación de la SEGUNDA pasada.
        Notification::fake();

        $this->importar_por_ia('provider', $ruta)->assertStatus(200);

        $this->assert_saldo_inicial('provider', $this->por_nombre('provider', $deuda), 52000.5);
        $this->assert_saldo_inicial('provider', $this->por_nombre('provider', $a_favor), -7600);
        $this->assert_cuenta_vacia('provider', $this->por_nombre('provider', $sin_saldo));

        $this->assert_notificacion_sin_avisos();
    }

    /* =====================================================================
     * 7. Tope del aviso y tamaño del evento
     * ================================================================== */

    /**
     * El aviso nombra como mucho 20 filas; si hay más, "y N filas más" (en singular si es una). El
     * nombre se corta a 60 caracteres y el texto a 40, con "…".
     *
     * @test
     */
    public function el_aviso_de_clientes_nombra_como_mucho_veinte_filas()
    {
        $nombre_largo = $this->nombre('Cli con un nombre larguisimo que no entra entero en el aviso de fin');
        $texto_largo  = 'Debe la factura 0001-00004567 del 12/03 mas los intereses de mora';

        $filas = [[$nombre_largo, $texto_largo]];

        // 21 filas ilegibles en total: una más que el tope.
        for ($i = 2; $i <= 21; $i++) {
            $filas[] = [$this->nombre('Cli tope '.str_pad($i, 2, '0', STR_PAD_LEFT)), 's/d'];
        }

        Notification::fake();

        $this->importar_por_ia('client', $this->xlsx(['Nombre', 'Saldo'], $filas))->assertStatus(200);

        $parrafos = $this->parrafos_del_aviso_de_ilegibles();

        // 20 filas, "y 1 fila más" y la explicación.
        $this->assertCount(22, $parrafos);

        $this->assertGreaterThan(60, mb_strlen($nombre_largo), 'Premisa: el nombre tiene que pasar los 60 caracteres.');

        $this->assertSame(
            'Fila 2, '.mb_substr($nombre_largo, 0, 60).'…: "'.mb_substr($texto_largo, 0, 40).'…"',
            $parrafos[0],
            'El nombre se corta a 60 caracteres y el texto a 40.'
        );

        for ($i = 1; $i < 20; $i++) {
            $this->assertSame('Fila '.($i + 2).', '.$filas[$i][0].': "s/d"', $parrafos[$i]);
        }

        $this->assertSame('y 1 fila más', $parrafos[20]);

        $this->assert_explicacion_del_aviso($parrafos[21]);
    }

    /**
     * Proveedores con los DOS bloques del aviso llenos: 52 saldos que no se cargaron porque la
     * cuenta ya tenía otro y 22 saldos ilegibles, con nombres y textos largos. El bloque nuevo va
     * DESPUÉS del que ya existía, y el evento entero sigue entrando en Pusher (10 KB) con margen.
     * Con 30 filas en el bloque nuevo pesaba 10.056 bytes: por eso el tope es 20
     * (LocalImportHelper::MAXIMO_DE_FILAS_EN_EL_AVISO_DE_SALDOS_ILEGIBLES). Y con el presupuesto de
     * bytes este escenario ya no nombra 50 en el bloque viejo: nombra 46 y "y 6 proveedores más"
     * (medido el 9/10/2026); el de ilegibles queda en 20 y "y 2 filas más".
     *
     * @test
     */
    public function con_los_dos_avisos_de_proveedores_al_tope_el_evento_entra_en_pusher()
    {
        $filas_primera = [];
        $filas_segunda = [];

        // 52 proveedores que ya tienen un saldo y en la segunda pasada traen otro.
        for ($i = 1; $i <= 52; $i++) {
            $nombre = $this->nombre('Prov tope con movimientos '.str_pad($i, 2, '0', STR_PAD_LEFT));

            $filas_primera[] = [$nombre, 100];
            $filas_segunda[] = [$nombre, 200];
        }

        // 22 proveedores nuevos con nombre largo y saldo ilegible largo: dos más que el tope.
        for ($i = 1; $i <= 22; $i++) {
            $filas_segunda[] = [
                $this->nombre('Prov ilegible con un nombre bien largo para el aviso de fin '.str_pad($i, 2, '0', STR_PAD_LEFT)),
                'Debe la factura 0001-000045'.str_pad($i, 2, '0', STR_PAD_LEFT).' del 12/03 mas los intereses de mora',
            ];
        }

        $this->importar_por_ia('provider', $this->xlsx(['Nombre', 'Saldo'], $filas_primera))->assertStatus(200);

        Notification::fake();

        $this->importar_por_ia('provider', $this->xlsx(['Nombre', 'Saldo'], $filas_segunda))->assertStatus(200);

        $notificacion = $this->notificacion_de_fin();

        $this->assertCount(2, $notificacion->info_to_show, 'Tienen que venir los dos bloques.');
        $this->assertSame('Saldos del Excel que no se cargaron', $notificacion->info_to_show[0]['title'], 'El bloque que ya existía va primero.');
        $this->assertSame(self::TITULO_DEL_AVISO, $notificacion->info_to_show[1]['title']);

        $parrafos = $notificacion->info_to_show[1]['parrafos'];

        $this->assertCount(22, $parrafos, '20 filas, "y 2 filas más" y la explicación.');
        $this->assertSame('y 2 filas más', $parrafos[20]);

        $datos = $notificacion->toBroadcast(User::find($this->user_id))->data;

        $this->assertLessThan(9000, strlen(json_encode($datos)), 'El evento de fin de importación no entra en Pusher.');
    }

    /* =====================================================================
     * 8. Los otros dos caminos
     * ================================================================== */

    /**
     * La importación clásica de clientes y de proveedores, con celdas de texto.
     *
     * @test
     */
    public function por_la_importacion_clasica_el_saldo_en_texto_se_lee()
    {
        $cliente   = $this->nombre('Cli clasico');
        $proveedor = $this->nombre('Prov clasico');

        $this->importar_por_el_clasico('client', $this->xlsx(['Nombre', 'Saldo'], [
            [$cliente, '$ 52.000,50'],
        ]))->assertStatus(200);

        $this->importar_por_el_clasico('provider', $this->xlsx(['Nombre', 'Saldo'], [
            [$proveedor, '-$ 7.600'],
        ]))->assertStatus(200);

        $this->assert_saldo_inicial('client', $this->por_nombre('client', $cliente), 52000.5);
        $this->assert_saldo_inicial('provider', $this->por_nombre('provider', $proveedor), -7600);
    }

    /**
     * admin-sync, el endpoint que usa admin-api (`ImplementationImportService`), con celdas de
     * texto. Llega sin sesión. (El motor de `/implementar` no pasa por acá: va por el modal con IA.)
     *
     * @test
     */
    public function por_admin_sync_el_saldo_en_texto_se_lee()
    {
        $cliente   = $this->nombre('Cli admin sync');
        $proveedor = $this->nombre('Prov admin sync');

        // Sin sesión, como llega el pedido del admin (EmpresaTestCase deja logueado al usuario 500).
        $this->app['auth']->forgetGuards();

        $this->importar_por_admin_sync('client', $this->xlsx(['Nombre', 'Saldo'], [
            [$cliente, '1.234,56'],
        ]))->assertStatus(200);

        $this->importar_por_admin_sync('provider', $this->xlsx(['Nombre', 'Saldo'], [
            [$proveedor, '52.000'],
        ]))->assertStatus(200);

        $this->assert_saldo_inicial('client', $this->por_nombre('client', $cliente), 1234.56);
        $this->assert_saldo_inicial('provider', $this->por_nombre('provider', $proveedor), 52000);
    }

    /* =====================================================================
     * 9. Ronda de correcciones: los huecos que dejaron las mutaciones
     * ================================================================== */

    /**
     * Una celda NUMÉRICA −1 es un dato también fuera del saldo: un cliente y un proveedor con −1 en
     * el teléfono quedan con teléfono "-1". Con el centinela viejo de getColumnValue() (`!== -1`
     * sobre el VALOR de la celda) quedaban sin teléfono.
     *
     * Es el test que prueba el arreglo de getColumnValue() por un camino que lo ve: clientes y
     * proveedores leen por Maatwebsite, que entrega `int` para un entero. (CeldaMenosUnoTest es solo
     * una guarda de artículos, que leen del CSV intermedio y nunca recibieron un −1 entero.)
     *
     * @test
     */
    public function una_celda_numerica_menos_uno_en_el_telefono_se_importa_como_menos_uno()
    {
        foreach (['client' => 'Cli', 'provider' => 'Prov'] as $model_name => $prefijo) {

            $nombre = $this->nombre($prefijo.' telefono menos uno');

            $this->importar_por_ia($model_name, $this->xlsx(['Nombre', 'Telefono'], [
                [$nombre, -1],
            ]), ['nombre' => 0, 'telefono' => 1])->assertStatus(200);

            $this->assertSame(
                '-1',
                $this->por_nombre($model_name, $nombre)->phone,
                'Una celda numérica −1 en el teléfono del '.$model_name.' tiene que importarse como "-1", no descartarse.'
            );
        }
    }

    /**
     * 🔴 Un cliente EXISTENTE con saldo y la celda de saldo VACÍA, o con solo espacios: la cuenta
     * queda como estaba, sin nota, y no se avisa nada (una celda vacía no es un saldo ilegible: es
     * "no informo saldo"). Si una celda vacía se leyera como 0, el ajuste le cargaría una nota de
     * crédito por todo lo que debe: es el daño que esta misión vino a cerrar. La fila se importa
     * igual con el resto de sus datos.
     *
     * @test
     */
    public function un_cliente_existente_con_la_celda_de_saldo_vacia_o_con_espacios_queda_como_estaba()
    {
        list($con_celda_vacia, $cuenta_celda_vacia)       = $this->cliente_existente_con_saldo('Cli existente celda vacia', 1000);
        list($con_celda_espacios, $cuenta_celda_espacios) = $this->cliente_existente_con_saldo('Cli existente celda con espacios', 2500);

        Notification::fake();

        $this->importar_por_ia('client', $this->xlsx(['Nombre', 'Telefono', 'Saldo'], [
            [$con_celda_vacia->name,    '11-1000', null],
            [$con_celda_espacios->name, '11-2500', '   '],
        ]), ['nombre' => 0, 'telefono' => 1, 'saldo_actual' => 2])->assertStatus(200);

        // La fila se importó: el teléfono es el del Excel.
        $this->assertSame('11-1000', $con_celda_vacia->fresh()->phone);
        $this->assertSame('11-2500', $con_celda_espacios->fresh()->phone);

        $this->assertCount(1, $this->movimientos($cuenta_celda_vacia), 'Una celda de saldo vacía no puede ajustar la cuenta de un cliente existente.');
        $this->assertCount(1, $this->movimientos($cuenta_celda_espacios), 'Una celda de saldo con solo espacios no puede ajustar la cuenta de un cliente existente.');

        $this->assert_saldo_inicial('client', $con_celda_vacia, 1000);
        $this->assert_saldo_inicial('client', $con_celda_espacios, 2500);

        $this->assert_notificacion_sin_avisos();
    }

    /**
     * Todos los casos borde del lector en una sola importación de proveedores, con la columna
     * mapeada con el alias viejo `'saldo actual'` (con espacio):
     *
     *   - se leen: BOM adelante, espacios y comillas en los bordes, el `+` adelante ("+$ 7.600",
     *     "$ +7.600", "+7.600": antes daban 7,6), el signo con espacio ("- 500"), el menos
     *     tipográfico U+2212 y el NBSP / U+202F en los bordes;
     *   - quedan ilegibles y se avisan: VERDADERO, "1e999" (PHP lo lee como infinito), todo saldo en
     *     dólares (USD, U$S, US$, en mayúsculas o minúsculas, con o sin signo), porque la columna
     *     carga la cuenta en PESOS (antes "USD 1.500" se cargaba como $1.500 sin aviso), y el doble
     *     signo o la doble moneda ("$++1.500", "$$+1.500", "$+-500"...): el lector saca un signo y
     *     una moneda, y lo que sobraba llegaba a parseNumericValue(), que leía "+1.500" como 1,5.
     *
     * @test
     */
    public function los_casos_borde_del_saldo_en_una_sola_importacion()
    {
        // [base del nombre, celda, saldo esperado]
        $legibles = [
            ['Prov borde bom',               "\xEF\xBB\xBF500",          500],
            ['Prov borde espacios',          '  -$ 7.600  ',             -7600],
            ['Prov borde comillas',          '"500"',                    500],
            ['Prov borde mas pesos',         '+$ 7.600',                 7600],
            ['Prov borde pesos mas',         '$ +7.600',                 7600],
            ['Prov borde mas',               '+7.600',                   7600],
            ['Prov borde signo y espacio',   '- 500',                    -500],
            ['Prov borde menos tipografico', "\u{2212}7.600",            -7600],
            ['Prov borde nbsp',              "\u{00A0}1.500\u{00A0}",    1500],
            ['Prov borde espacio fino',      "\u{202F}2.000\u{202F}",    2000],
        ];

        // [base del nombre, celda, texto que tiene que mostrar el aviso]
        $ilegibles = [
            ['Prov borde verdadero',     true,         'VERDADERO'],
            ['Prov borde infinito',      '1e999',      '1e999'],
            ['Prov borde usd',           'USD 1.500',  'USD 1.500'],
            ['Prov borde menos usd',     '-USD 100',   '-USD 100'],
            ['Prov borde u$s',           'U$S 100',    'U$S 100'],
            ['Prov borde us$',           'US$ 100',    'US$ 100'],
            ['Prov borde usd minuscula', 'usd 50',     'usd 50'],
            ['Prov borde doble mas',            '$++1.500',    '$++1.500'],
            ['Prov borde doble mas separado',   '$ + +7.600',  '$ + +7.600'],
            ['Prov borde doble moneda',         '$$+1.500',    '$$+1.500'],
            ['Prov borde doble moneda separada', '$ $ +7.600', '$ $ +7.600'],
            ['Prov borde mas y menos',          '$+-500',      '$+-500'],
        ];

        $filas = [];

        foreach (array_merge($legibles, $ilegibles) as $caso) {
            $filas[] = [$this->nombre($caso[0]), $caso[1]];
        }

        Notification::fake();

        $this->importar_por_ia('provider', $this->xlsx(['Nombre', 'Saldo'], $filas), ['nombre' => 0, 'saldo actual' => 1])->assertStatus(200);

        foreach ($legibles as $caso) {
            $this->assert_saldo_inicial('provider', $this->por_nombre('provider', $this->nombre($caso[0])), $caso[2]);
        }

        foreach ($ilegibles as $caso) {
            $this->assert_cuenta_vacia('provider', $this->por_nombre('provider', $this->nombre($caso[0])));
        }

        $parrafos = $this->parrafos_del_aviso_de_ilegibles();

        $this->assertCount(count($ilegibles) + 1, $parrafos, 'Un párrafo por fila ilegible y la explicación al final.');

        foreach ($ilegibles as $i => $caso) {
            // Fila 1: cabecera. Los ilegibles van después de los legibles.
            $fila = count($legibles) + $i + 2;

            $this->assertSame('Fila '.$fila.', '.$this->nombre($caso[0]).': "'.$caso[2].'"', $parrafos[$i]);
        }

        $explicacion = end($parrafos);

        $this->assert_explicacion_del_aviso($explicacion);
        $this->assertStringContainsString('dólares', $explicacion, 'La explicación tiene que decir que un saldo en dólares no se importa.');
        $this->assertStringContainsString('pesos', $explicacion);
    }

    /**
     * Con datos REALES el evento de fin de importación de proveedores tiene que seguir entrando en
     * Pusher: 50 proveedores con movimientos y otro saldo, con nombres de más de 60 caracteres con
     * tildes y eñes (cada una pesa seis bytes en el JSON) y montos de cientos de millones, más 22
     * saldos ilegibles con nombres y textos con acentos. Con topes fijos (50 y 20) el bloque de los
     * "no se cargaron" solo ya pasaba los 9 KB.
     *
     * Vienen los dos bloques y, en cada uno, los nombrados más el "y N ... más" dan el total: el
     * presupuesto achica cuántos se nombran, nunca cuántos se cuentan.
     *
     * @test
     */
    public function con_datos_reales_los_dos_avisos_de_proveedores_entran_en_pusher()
    {
        $filas_primera = [];
        $filas_segunda = [];

        for ($i = 1; $i <= 50; $i++) {
            $nombre = $this->nombre('Distribuidora Ñandú de Artículos Eléctricos y Construcción Peñarol '.str_pad($i, 2, '0', STR_PAD_LEFT));

            $filas_primera[] = [$nombre, 123456789.5 + $i];
            $filas_segunda[] = [$nombre, 987654321.75 + $i];
        }

        for ($i = 1; $i <= 22; $i++) {
            $filas_segunda[] = [
                $this->nombre('Ferretería Ñuñoa del Señor de los Milagros y Compañía '.str_pad($i, 2, '0', STR_PAD_LEFT)),
                'Según lo acordado debía más de lo anotado en el cuaderno '.$i,
            ];
        }

        $this->assertGreaterThan(60, mb_strlen($filas_primera[0][0]), 'Premisa: los nombres tienen que pasar los 60 caracteres.');

        $this->importar_por_ia('provider', $this->xlsx(['Nombre', 'Saldo'], $filas_primera))->assertStatus(200);

        Notification::fake();

        $this->importar_por_ia('provider', $this->xlsx(['Nombre', 'Saldo'], $filas_segunda))->assertStatus(200);

        $notificacion = $this->notificacion_de_fin();

        $this->assertCount(2, $notificacion->info_to_show, 'Tienen que venir los dos bloques.');
        $this->assertSame('Saldos del Excel que no se cargaron', $notificacion->info_to_show[0]['title']);
        $this->assertSame(self::TITULO_DEL_AVISO, $notificacion->info_to_show[1]['title']);

        $this->assert_nombrados_mas_restantes_dan_el_total($notificacion->info_to_show[0]['parrafos'], 50, 'proveedor', 'proveedores');
        $this->assert_nombrados_mas_restantes_dan_el_total($notificacion->info_to_show[1]['parrafos'], 22, 'fila', 'filas');

        // En el bloque de los "no se cargaron" el nombre de más de 60 caracteres sale cortado a 60 + "…".
        $this->assertStringStartsWith(
            mb_substr($filas_segunda[0][0], 0, 60).'…: saldo en el Excel ',
            $notificacion->info_to_show[0]['parrafos'][0],
            'El nombre del proveedor en el aviso de saldos no cargados se corta a 60 caracteres.'
        );

        $bytes = strlen(json_encode($notificacion->toBroadcast(User::find($this->user_id))->data));

        $this->assertLessThan(9000, $bytes, 'El evento de fin de importación pesa '.$bytes.' bytes: no entra en Pusher.');
    }

    /**
     * El bloque de saldos ilegibles también se achica por debajo de 20 cuando los avisos no entran
     * en el presupuesto de Pusher, y el "y N filas más" sigue contando contra el TOTAL de filas, no
     * contra el tope de 20.
     *
     * Escenario real de clientes: 60 filas con una sucursal que no existe (el bloque de sucursales
     * es fijo: no tiene tope y no se achica) y el saldo ilegible, con nombres y textos largos. Con
     * las sucursales ocupando casi todo el presupuesto, el de ilegibles tiene que nombrar menos de
     * 20 y contar el resto.
     *
     * @test
     */
    public function el_aviso_de_ilegibles_se_achica_por_debajo_de_veinte_y_cuenta_el_resto()
    {
        $filas = [];

        for ($i = 1; $i <= 60; $i++) {
            $numero = str_pad($i, 2, '0', STR_PAD_LEFT);

            $filas[] = [
                $this->nombre('Cli achica el aviso con un nombre bastante largo para cortar '.$numero),
                'Sucursal que no existe en el sistema, deposito de la zona norte del Gran Buenos Aires '.$numero.' '.$this->sufijo,
                'Debe la factura 0001-000045'.$numero.' del 12/03 mas los intereses de mora',
            ];
        }

        Notification::fake();

        $this->importar_por_ia('client', $this->xlsx(['Nombre', 'Sucursal', 'Saldo'], $filas), ['nombre' => 0, 'sucursal' => 1, 'saldo_actual' => 2])->assertStatus(200);

        $notificacion = $this->notificacion_de_fin();

        $this->assertCount(2, $notificacion->info_to_show, 'Tienen que venir el bloque de sucursales y el de saldos ilegibles.');
        $this->assertSame('Sucursales que no existen en el sistema', $notificacion->info_to_show[0]['title']);
        $this->assertSame(self::TITULO_DEL_AVISO, $notificacion->info_to_show[1]['title']);

        // El de sucursales es fijo: las 60 y su explicación.
        $this->assertCount(61, $notificacion->info_to_show[0]['parrafos'], 'El bloque de sucursales no se achica.');

        $parrafos = $notificacion->info_to_show[1]['parrafos'];

        // Nombradas: todo menos el "y N filas más" y la explicación.
        $this->assertLessThan(20, count($parrafos) - 2, 'Premisa: con este escenario el aviso de ilegibles tiene que nombrar menos de 20 filas.');

        $this->assert_nombrados_mas_restantes_dan_el_total($parrafos, 60, 'fila', 'filas');

        $this->assertLessThanOrEqual(
            \App\Http\Controllers\Helpers\LocalImportHelper::PRESUPUESTO_DE_BYTES_DE_LOS_AVISOS,
            strlen(json_encode($notificacion->info_to_show)),
            'Los avisos tienen que entrar en el presupuesto.'
        );
    }

    /**
     * recortar_para_el_aviso() devuelve UTF-8 válido aunque el texto del Excel traiga un byte roto,
     * y el aviso se puede pasar a JSON. Con un solo byte inválido json_encode() devuelve false y se
     * pierde la notificación entera.
     *
     * @test
     */
    public function recortar_para_el_aviso_sanea_un_byte_roto()
    {
        $recortado = \App\Http\Controllers\Helpers\LocalImportHelper::recortar_para_el_aviso("Prov\x80 roto", 60);

        $this->assertTrue(mb_check_encoding($recortado, 'UTF-8'), 'El texto del aviso tiene que quedar en UTF-8 válido.');
        $this->assertNotFalse(json_encode(['parrafos' => [$recortado]]), 'Con un byte roto json_encode() falla y se pierde la notificación.');
        $this->assertStringStartsWith('Prov', $recortado);
        $this->assertStringEndsWith(' roto', $recortado);
    }

    /**
     * En un bloque del aviso, los nombrados más el "y N ... más" dan el total, y se nombra al menos
     * uno. Los párrafos son: un nombrado por párrafo, el "y N ... más" (si hay restantes) y la
     * explicación al final.
     *
     * @param  array  $parrafos
     * @param  int    $total     Filas o proveedores que había para avisar.
     * @param  string $singular  "proveedor" | "fila"
     * @param  string $plural    "proveedores" | "filas"
     * @return void
     */
    protected function assert_nombrados_mas_restantes_dan_el_total(array $parrafos, $total, $singular, $plural)
    {
        // Sin la explicación del final.
        $sin_explicacion = array_slice($parrafos, 0, count($parrafos) - 1);

        $restantes = 0;

        $ultimo = end($sin_explicacion);

        if (preg_match('/^y (\d+) (' . preg_quote($singular, '/') . '|' . preg_quote($plural, '/') . ') más$/u', (string) $ultimo, $partes) === 1) {
            $restantes = (int) $partes[1];

            $this->assertSame($restantes == 1 ? $singular : $plural, $partes[2], 'El "y N ... más" tiene que ir en singular solo si N es 1.');

            array_pop($sin_explicacion);
        }

        $nombrados = count($sin_explicacion);

        $this->assertGreaterThanOrEqual(1, $nombrados, 'Cada bloque tiene que nombrar al menos uno.');

        foreach ($sin_explicacion as $parrafo) {
            $this->assertDoesNotMatchRegularExpression('/^y \d+ /u', $parrafo, 'Un "y N ... más" en el medio del bloque.');
        }

        $this->assertSame($total, $nombrados + $restantes, 'Nombrados ('.$nombrados.') más restantes ('.$restantes.') tienen que dar el total.');
    }
}
