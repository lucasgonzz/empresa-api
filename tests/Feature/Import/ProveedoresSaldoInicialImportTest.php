<?php

namespace Tests\Feature\Import;

use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\Provider;
use App\Models\User;
use App\Notifications\GlobalNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\EmpresaTestCase;
use Tests\Feature\CuentaCorriente\ArmaCadenas;

/**
 * Misión importacion-proveedores-saldo-inicial (8/10/2026) — el saldo de la importación de
 * proveedores.
 *
 * El bug: `ProviderImport` creaba el proveedor con `Provider::create()` y NO le creaba las cuentas
 * corrientes; después `LocalImportHelper::setSaldoInicial()` buscaba la cuenta en pesos, no la
 * encontraba y salía con un `return`. El saldo del Excel se perdía sin error ni aviso (con clientes
 * sí andaba, porque `ClientImport` crea las cuentas). Y de yapa, el proveedor quedaba sin cuenta: una
 * compra en cuenta corriente a ese proveedor revienta (ver Compras/15).
 *
 * Además, el saldo a favor (negativo en el Excel) se guardaba con el `haber` NEGATIVO: quedaba bien
 * hasta el primer recálculo y `checkSaldos()` lo daba vuelta (−7.600 pasaba a +7.600). Les pasaba a
 * clientes y proveedores, porque los dos usan el mismo helper.
 *
 * Todo por los endpoints reales, con un Excel de verdad, por los tres caminos que usan
 * `ProviderImport`: el modal con IA (`api/ai-excel-import/import`), la importación clásica
 * (`api/provider/excel/import`) y el admin (`api/admin-sync/ai-excel-import/import`, el que usa el
 * motor de `/implementar`).
 *
 * 🔴 La cadena de saldos se verifica con `ArmaCadenas::assert_cadena_cierra()`, que suma las filas
 * por su cuenta y no con `checkSaldos()`: un test que verifica el saldo con la misma función que lo
 * calculó no puede dar rojo.
 *
 * DatabaseTransactions (heredado de EmpresaTestCase, InnoDB verificado): nada de lo que se crea
 * acá sobrevive al test. Los Excel copiados a `storage/app/imported_files` se borran en tearDown().
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group importacion-proveedores-saldo-inicial
 */
class ProveedoresSaldoInicialImportTest extends EmpresaTestCase
{
    use ArmaCadenas;

    /** Clave del admin para el endpoint de admin-sync (se setea en config() en el test). */
    const CLAVE_ADMIN = 'clave-de-prueba';

    /** @var int Dueño de la sesión (el usuario 500 del fixture). */
    protected $user_id;

    /** @var string Sufijo para que los nombres no choquen con nada de la base de testing. */
    protected $sufijo;

    /** @var array Archivos temporales (tempnam y copias en storage) a borrar al terminar. */
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
     * Nombre único para un proveedor o cliente del test.
     *
     * @param  string $base
     * @return string
     */
    protected function nombre($base)
    {
        return 'zz '.$base.' '.$this->sufijo;
    }

    /**
     * Escribe un .xlsx temporal con la cabecera y las filas dadas. Una celda null queda VACÍA.
     *
     * @param  array $cabecera
     * @param  array $filas
     * @return string Ruta absoluta.
     */
    protected function xlsx(array $cabecera, array $filas)
    {
        $spreadsheet = new Spreadsheet();

        // Comparación ESTRICTA con null: sin ella fromArray() saltea también las celdas en 0
        // (0 == null), y un saldo 0 llegaría al importador como una celda vacía.
        $spreadsheet->getActiveSheet()->fromArray(array_merge([$cabecera], $filas), null, 'A1', true);

        $ruta = tempnam(sys_get_temp_dir(), 'zz_import_prov_saldo_').'.xlsx';

        $writer = new Xlsx($spreadsheet);
        $writer->save($ruta);

        $this->archivos_temporales[] = $ruta;

        return $ruta;
    }

    /**
     * El Excel de los tres caminos: un proveedor con deuda, uno con saldo a favor y uno sin saldo.
     *
     * @return string Ruta absoluta.
     */
    protected function excel_base()
    {
        return $this->xlsx(['Nombre', 'Saldo'], [
            [$this->nombre('Prov deuda'),     52000],
            [$this->nombre('Prov a favor'),   -7600],
            [$this->nombre('Prov sin saldo'), null],
        ]);
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
     * Payload común de los dos endpoints con IA: nombre en la columna 0 y saldo en la 1.
     *
     * @param  string $ruta
     * @param  array  $overrides
     * @return array
     */
    protected function payload_ia($ruta, array $overrides = [])
    {
        return array_merge([
            'excel_path'      => $this->excel_en_storage($ruta),
            'model'           => 'provider',
            'columns'         => ['nombre' => 0, 'saldo_actual' => 1],
            'create_and_edit' => true,
            'start_row'       => 2,
            'finish_row'      => null,
        ], $overrides);
    }

    /**
     * El modal con IA de la SPA.
     *
     * @param  string $ruta
     * @param  array  $overrides
     * @return \Illuminate\Testing\TestResponse
     */
    protected function importar_por_ia($ruta, array $overrides = [])
    {
        return $this->postJson('api/ai-excel-import/import', $this->payload_ia($ruta, $overrides));
    }

    /**
     * La importación clásica (modal viejo): el archivo sube con el request y el mapeo va 1-based.
     *
     * @param  string $ruta
     * @return \Illuminate\Testing\TestResponse
     */
    protected function importar_por_el_clasico($ruta)
    {
        $copia = tempnam(sys_get_temp_dir(), 'zz_import_prov_copia_').'.xlsx';

        copy($ruta, $copia);

        $this->archivos_temporales[] = $copia;

        return $this->post('api/provider/excel/import', [
            'models'            => new UploadedFile($copia, 'proveedores.xlsx', null, null, true),
            'create_and_edit'   => 1,
            'start_row'         => 2,
            'finish_row'        => '',
            'prop_nombre'       => 1,
            'prop_saldo_actual' => 2,
        ]);
    }

    /**
     * El endpoint del admin: sin sesión, con el header de la clave y el `user_id` del comercio. Es
     * el que usa el motor de `/implementar` contra el sistema de un cliente.
     *
     * @param  string $ruta
     * @return \Illuminate\Testing\TestResponse
     */
    protected function importar_por_admin_sync($ruta)
    {
        config([
            'services.admin_api.require_api_key' => true,
            'services.admin_api.api_key'         => self::CLAVE_ADMIN,
        ]);

        return $this->postJson(
            'api/admin-sync/ai-excel-import/import',
            $this->payload_ia($ruta, ['user_id' => $this->user_id]),
            ['X-Admin-Api-Key' => self::CLAVE_ADMIN]
        );
    }

    /**
     * Un proveedor dado de alta desde el ABM, por el endpoint real: nace con sus dos cuentas vacías.
     *
     * `price_from_cost_mas_iva` va porque el formulario de la SPA lo manda siempre (es un checkbox,
     * 0 por defecto) y `ProviderController@store` lo escribe tal cual: sin él, la columna NOT NULL
     * revienta el insert.
     *
     * @param  string $base
     * @return \App\Models\Provider
     */
    protected function proveedor_desde_el_abm($base)
    {
        $respuesta = $this->postJson('api/provider', [
                                'name'                    => $this->nombre($base),
                                'price_from_cost_mas_iva' => 0,
                            ])
                          ->assertStatus(201);

        return Provider::find($respuesta->json('model.id'));
    }

    /**
     * El único proveedor del comercio con ese nombre (la importación busca por nombre).
     *
     * @param  string $nombre
     * @return \App\Models\Provider
     */
    protected function proveedor_por_nombre($nombre)
    {
        $proveedores = Provider::where('user_id', $this->user_id)->where('name', $nombre)->get();

        $this->assertCount(1, $proveedores, 'Tiene que haber exactamente un proveedor "'.$nombre.'".');

        return $proveedores->first();
    }

    /**
     * La cuenta de una moneda de un cliente o proveedor.
     *
     * @param  string $model_name
     * @param  int    $model_id
     * @param  int    $moneda_id
     * @return \App\Models\CreditAccount|null
     */
    protected function cuenta($model_name, $model_id, $moneda_id)
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
     * Una y solo una cuenta por moneda (pesos y dólares), del comercio del test.
     *
     * @param  string $model_name
     * @param  mixed  $model
     * @return void
     */
    protected function assert_tiene_sus_dos_cuentas($model_name, $model)
    {
        foreach ([1, 2] as $moneda_id) {

            $cuentas = CreditAccount::where('model_name', $model_name)
                                    ->where('model_id', $model->id)
                                    ->where('moneda_id', $moneda_id)
                                    ->get();

            $this->assertCount(
                1,
                $cuentas,
                'El '.$model_name.' "'.$model->name.'" tiene que tener exactamente una cuenta en la moneda '.$moneda_id.'.'
            );

            $this->assertEquals(
                $this->user_id,
                $cuentas->first()->user_id,
                'La cuenta en la moneda '.$moneda_id.' de "'.$model->name.'" quedó con el user_id de otro comercio.'
            );
        }
    }

    /**
     * La cuenta en pesos tiene UN movimiento, el "Saldo inicial" con el saldo del Excel, del lado
     * que corresponde y con el monto en positivo; la cadena cierra; y la cuenta y el
     * `saldo_pesos` dicen lo mismo que el Excel. La de dólares sigue vacía.
     *
     * @param  string $model_name
     * @param  mixed  $model
     * @param  float  $saldo
     * @return void
     */
    protected function assert_saldo_inicial($model_name, $model, $saldo)
    {
        $cuenta = $this->cuenta($model_name, $model->id, 1);

        $this->assertNotNull($cuenta, 'El '.$model_name.' "'.$model->name.'" no tiene cuenta en pesos: el saldo del Excel no tiene dónde cargarse.');

        $movimientos = $this->movimientos($cuenta);

        $this->assertCount(1, $movimientos, 'La cuenta en pesos de "'.$model->name.'" tiene que tener un único "Saldo inicial".');

        $saldo_inicial = $movimientos->first();

        $this->assertEquals('Saldo inicial', $saldo_inicial->detalle);
        $this->assertEquals($model_name == 'provider' ? $model->id : null, $saldo_inicial->provider_id);
        $this->assertEquals($model_name == 'client' ? $model->id : null, $saldo_inicial->client_id);

        if ($saldo >= 0) {
            $this->assertEquals('sin_pagar', $saldo_inicial->status);
            $this->assertEqualsWithDelta($saldo, (float) $saldo_inicial->debe, 0.001, 'La deuda va en el debe.');
            $this->assertNull($saldo_inicial->haber);
        } else {
            $this->assertEquals('pago_from_client', $saldo_inicial->status);
            $this->assertNull($saldo_inicial->debe);
            $this->assertEqualsWithDelta(
                abs($saldo),
                (float) $saldo_inicial->haber,
                0.001,
                'El saldo a favor va en el haber y en POSITIVO, como el del botón "Saldo inicial": con el haber negativo la cadena lo suma al revés.'
            );
        }

        $this->assertEqualsWithDelta($saldo, (float) $saldo_inicial->saldo, 0.01, 'El saldo del movimiento es el del Excel, con su signo.');

        $this->assertEqualsWithDelta($saldo, (float) $cuenta->fresh()->saldo, 0.01, 'credit_accounts.saldo no es el del Excel.');
        $this->assertEqualsWithDelta($saldo, (float) $model->fresh()->saldo_pesos, 0.01, 'saldo_pesos no es el del Excel.');

        $this->assert_cadena_cierra($cuenta->id, 'Saldo inicial importado de "'.$model->name.'"');

        $cuenta_dolares = $this->cuenta($model_name, $model->id, 2);

        $this->assertNotNull($cuenta_dolares);
        $this->assertCount(0, $this->movimientos($cuenta_dolares), 'El saldo del Excel va en pesos: la cuenta en dólares sigue vacía.');
    }

    /**
     * Lo que tiene que dejar `excel_base()`, por cualquiera de los tres caminos.
     *
     * @return void
     */
    protected function assert_escenario_base()
    {
        $deuda     = $this->proveedor_por_nombre($this->nombre('Prov deuda'));
        $a_favor   = $this->proveedor_por_nombre($this->nombre('Prov a favor'));
        $sin_saldo = $this->proveedor_por_nombre($this->nombre('Prov sin saldo'));

        foreach ([$deuda, $a_favor, $sin_saldo] as $proveedor) {
            $this->assert_tiene_sus_dos_cuentas('provider', $proveedor);
        }

        $this->assert_saldo_inicial('provider', $deuda, 52000);
        $this->assert_saldo_inicial('provider', $a_favor, -7600);

        // La fila sin saldo: el proveedor nace con su cuenta, vacía.
        $this->assertCount(0, $this->movimientos($this->cuenta('provider', $sin_saldo->id, 1)), 'Una fila sin saldo no carga ningún movimiento.');
        $this->assertEqualsWithDelta(0, (float) $this->cuenta('provider', $sin_saldo->id, 1)->saldo, 0.01);
    }

    /* =====================================================================
     * Los tres caminos
     * ================================================================== */

    /**
     * El modal con IA (el camino de todos los días): proveedores nuevos con sus dos cuentas y el
     * saldo del Excel cargado. Sin casos que avisar, la notificación no trae ningún bloque.
     *
     * @test
     */
    public function por_el_modal_con_ia_el_proveedor_nuevo_nace_con_su_cuenta_y_su_saldo_inicial()
    {
        Notification::fake();

        $this->importar_por_ia($this->excel_base())->assertStatus(200);

        $this->assert_escenario_base();

        Notification::assertSentTo(
            User::find($this->user_id),
            GlobalNotification::class,
            function ($notification) {
                return $notification->info_to_show === [];
            }
        );
    }

    /**
     * La importación clásica de proveedores.
     *
     * @test
     */
    public function por_la_importacion_clasica_el_proveedor_nuevo_nace_con_su_cuenta_y_su_saldo_inicial()
    {
        $this->importar_por_el_clasico($this->excel_base())->assertStatus(200);

        $this->assert_escenario_base();
    }

    /**
     * El endpoint del admin, el que usa el motor de `/implementar`. El pedido llega SIN sesión (el
     * admin no está logueado en el sistema del cliente): el controlador loguea al dueño del
     * `user_id` con `Auth::loginUsingId()` mientras importa y lo desloguea al terminar. Las cuentas
     * tienen que salir con el user_id de ese comercio.
     *
     * @test
     */
    public function por_admin_sync_el_proveedor_nuevo_nace_con_su_cuenta_y_su_saldo_inicial()
    {
        // Sin sesión, como llega el pedido del admin (EmpresaTestCase deja logueado al usuario 500).
        $this->app['auth']->forgetGuards();

        $this->importar_por_admin_sync($this->excel_base())->assertStatus(200);

        $this->assert_escenario_base();
    }

    /* =====================================================================
     * El signo
     * ================================================================== */

    /**
     * Un saldo a favor importado sigue siendo a favor después de un recálculo de la cadena.
     *
     * Es lo de los "Saldo inicial" de Servian: con el haber negativo, `checkSaldos()` (que corre
     * después de cualquier pago, venta o nota) daba vuelta el signo y el proveedor pasaba de tener
     * $7.600 a favor a deber $7.600. El proveedor viene del ABM, con su cuenta vacía, para que el
     * rojo de antes del arreglo sea el del signo y no el de la cuenta que falta.
     *
     * @test
     */
    public function el_saldo_a_favor_importado_sigue_a_favor_despues_de_recalcular_la_cadena()
    {
        $proveedor = $this->proveedor_desde_el_abm('Prov a favor del ABM');

        $this->importar_por_ia($this->xlsx(['Nombre', 'Saldo'], [
            [$proveedor->name, -7600],
        ]))->assertStatus(200);

        $cuenta = $this->cuenta('provider', $proveedor->id, 1);

        CurrentAcountHelper::checkSaldos($cuenta->id);

        $saldo_inicial = $this->movimientos($cuenta)->first();

        $this->assertNotNull($saldo_inicial, 'El saldo del Excel no se cargó.');

        $this->assertEqualsWithDelta(-7600, (float) $saldo_inicial->saldo, 0.01, 'checkSaldos() dio vuelta el signo del saldo inicial importado.');
        $this->assertEqualsWithDelta(-7600, (float) $cuenta->fresh()->saldo, 0.01, 'La cuenta quedó con el signo dado vuelta después del recálculo.');
        $this->assertEqualsWithDelta(-7600, (float) $proveedor->fresh()->saldo_pesos, 0.01, 'saldo_pesos quedó con el signo dado vuelta después del recálculo.');

        $this->assert_saldo_inicial('provider', $proveedor, -7600);
    }

    /**
     * Clientes usa el mismo helper (`crearSaldoInicialPorImportacion()`): el arreglo del signo le
     * llega también. Por la importación clásica de clientes.
     *
     * @test
     */
    public function clientes_el_saldo_a_favor_importado_va_al_haber_en_positivo_y_sobrevive_al_recalculo()
    {
        $ruta = $this->xlsx(['Nombre', 'Saldo'], [
            [$this->nombre('Cliente a favor'), -4500],
            [$this->nombre('Cliente deuda'),   1200],
        ]);

        $copia = tempnam(sys_get_temp_dir(), 'zz_import_cli_copia_').'.xlsx';
        copy($ruta, $copia);
        $this->archivos_temporales[] = $copia;

        $this->post('api/client/excel/import', [
            'models'            => new UploadedFile($copia, 'clientes.xlsx', null, null, true),
            'create_and_edit'   => 1,
            'start_row'         => 2,
            'finish_row'        => '',
            'prop_nombre'       => 1,
            'prop_saldo_actual' => 2,
        ])->assertStatus(200);

        $a_favor = Client::where('user_id', $this->user_id)->where('name', $this->nombre('Cliente a favor'))->first();
        $deuda   = Client::where('user_id', $this->user_id)->where('name', $this->nombre('Cliente deuda'))->first();

        $this->assertNotNull($a_favor, 'El cliente a favor no se creó.');
        $this->assertNotNull($deuda, 'El cliente con deuda no se creó.');

        $this->assert_saldo_inicial('client', $a_favor, -4500);
        $this->assert_saldo_inicial('client', $deuda, 1200);

        CurrentAcountHelper::checkSaldos($this->cuenta('client', $a_favor->id, 1)->id);

        $this->assertEqualsWithDelta(-4500, (float) $a_favor->fresh()->saldo_pesos, 0.01, 'checkSaldos() dio vuelta el signo del saldo inicial importado del cliente.');

        $this->assert_saldo_inicial('client', $a_favor, -4500);
    }

    /* =====================================================================
     * Reimportar
     * ================================================================== */

    /**
     * El mismo archivo dos veces: ni proveedores repetidos ni un segundo "Saldo inicial". El saldo
     * inicial va solo en una cuenta vacía, y en la segunda pasada la cuenta ya tiene el de la primera.
     *
     * Es lo que hace hoy el motor de `/implementar` (dos pasadas): con el arreglo, la segunda no
     * puede duplicar la deuda de nadie.
     *
     * 🔴 Y la segunda pasada NO avisa nada: la cuenta ya tiene el saldo del Excel. Un aviso de "no se
     * cargó, ajustá con una nota" ahí es falso, y quien lo siga duplica la deuda.
     *
     * @test
     */
    public function reimportar_el_mismo_archivo_no_duplica_proveedores_ni_saldos_ni_avisa()
    {
        $ruta = $this->excel_base();

        $this->importar_por_ia($ruta)->assertStatus(200);

        // Solo se mira la notificación de la SEGUNDA pasada.
        Notification::fake();

        $this->importar_por_ia($ruta)->assertStatus(200);

        $this->assert_escenario_base();

        $this->assert_notificacion_sin_avisos();
    }

    /**
     * La reparación de lo ya importado: un proveedor que dejó la importación vieja (creado con
     * `Provider::create()` y sin cuentas) recibe su cuenta y su saldo al reimportar el Excel.
     *
     * @test
     */
    public function un_proveedor_viejo_sin_cuenta_la_recibe_al_reimportar_y_carga_su_saldo()
    {
        // Tal cual lo dejaba ProviderImport antes del arreglo: sin crear_credit_accounts().
        $viejo = Provider::create([
            'num'     => (int) Provider::where('user_id', $this->user_id)->max('num') + 1,
            'name'    => $this->nombre('Prov viejo sin cuenta'),
            'user_id' => $this->user_id,
        ]);

        $this->assertEquals(0, CreditAccount::where('model_name', 'provider')->where('model_id', $viejo->id)->count(), 'Premisa: el proveedor viejo no tiene cuentas.');

        $this->importar_por_ia($this->xlsx(['Nombre', 'Saldo'], [
            [$viejo->name, 30000],
        ]))->assertStatus(200);

        $this->assert_tiene_sus_dos_cuentas('provider', $viejo);

        $this->assert_saldo_inicial('provider', $viejo, 30000);

        $this->assertEquals(1, Provider::where('user_id', $this->user_id)->where('name', $viejo->name)->count(), 'La reimportación duplicó el proveedor.');
    }

    /* =====================================================================
     * Lo que no se carga, y se avisa
     * ================================================================== */

    /**
     * La única notificación de fin de importación que se mandó (desde el último
     * Notification::fake()).
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
     * El bloque "Saldos del Excel que no se cargaron" de la notificación de fin de importación: el
     * único bloque, con ese título. Devuelve sus párrafos.
     *
     * @return array
     */
    protected function parrafos_del_aviso()
    {
        $info = $this->notificacion_de_fin()->info_to_show;

        $this->assertIsArray($info);
        $this->assertCount(1, $info, 'La notificación tiene que traer un único bloque de avisos.');
        $this->assertSame('Saldos del Excel que no se cargaron', $info[0]['title']);

        return $info[0]['parrafos'];
    }

    /**
     * Un proveedor con movimientos y su cuenta en pesos, con un saldo cargado por el camino real:
     * el botón "Saldo inicial" de la cuenta.
     *
     * @param  string $base
     * @param  float  $saldo  Positivo: deuda (debe).
     * @return array  [Provider, CreditAccount]
     */
    protected function proveedor_con_movimientos($base, $saldo)
    {
        $proveedor = $this->proveedor_desde_el_abm($base);

        $cuenta = $this->cuenta('provider', $proveedor->id, 1);

        $this->postJson('api/current-acount/saldo-inicial', [
            'credit_account_id' => $cuenta->id,
            'model_name'        => 'provider',
            'model_id'          => $proveedor->id,
            'is_for_debe'       => true,
            'saldo_inicial'     => $saldo,
        ])->assertStatus(201);

        return [$proveedor, $cuenta];
    }

    /**
     * Un proveedor que YA tiene movimientos y cuyo saldo es OTRO que el del Excel: el saldo del
     * Excel es un saldo INICIAL y no ajusta nada (a diferencia de clientes, que ajusta con una
     * nota). No se carga y se avisa al final, con su nombre y los dos montos, para que el
     * comerciante vea la diferencia antes de ajustar. El que importa en la misma pasada con la
     * cuenta vacía, se carga y no se avisa.
     *
     * @test
     */
    public function un_proveedor_con_movimientos_y_otro_saldo_no_recibe_el_del_excel_y_se_avisa_con_los_dos_montos()
    {
        list($con_movimientos, $cuenta) = $this->proveedor_con_movimientos('Prov con movimientos', 1000);

        Notification::fake();

        $nombre_nuevo = $this->nombre('Prov nuevo de la misma pasada');

        $this->importar_por_ia($this->xlsx(['Nombre', 'Saldo'], [
            [$con_movimientos->name, -7600.5],
            [$nombre_nuevo,          300],
        ]))->assertStatus(200);

        // La cuenta con movimientos quedó como estaba.
        $this->assertCount(1, $this->movimientos($cuenta), 'El saldo del Excel se cargó sobre una cuenta que ya tenía movimientos.');
        $this->assertEqualsWithDelta(1000, (float) $cuenta->fresh()->saldo, 0.01);
        $this->assertEqualsWithDelta(1000, (float) $con_movimientos->fresh()->saldo_pesos, 0.01);

        $parrafos = $this->parrafos_del_aviso();

        // Un párrafo por proveedor avisado, y la explicación al final.
        $this->assertCount(2, $parrafos, 'El aviso tiene que nombrar solo al proveedor cuyo saldo no se cargó.');

        $this->assertSame(
            $con_movimientos->name.': saldo en el Excel -$7.600,50, saldo en la cuenta $1.000',
            $parrafos[0],
            'El párrafo del proveedor tiene que traer su nombre y los dos montos, como los lee un comerciante.'
        );

        $this->assertStringNotContainsString($nombre_nuevo, implode(' ', $parrafos));

        $this->assertStringContainsString('cuenta vacía', $parrafos[1]);
        $this->assertStringContainsString('nota de crédito o de débito por la diferencia', $parrafos[1]);

        // El nuevo de la misma pasada, con la cuenta vacía, sí recibe su saldo.
        $this->assert_saldo_inicial('provider', $this->proveedor_por_nombre($nombre_nuevo), 300);
    }

    /**
     * Un proveedor con movimientos cuyo saldo YA ES el del Excel: no se carga nada y NO se avisa
     * (el aviso diría "ajustá con una nota", y seguirlo duplicaría la deuda). Lo mismo con un
     * proveedor nuevo repetido en el mismo archivo con el mismo saldo: la primera fila carga el
     * saldo inicial y la segunda lo encuentra igual.
     *
     * @test
     */
    public function un_proveedor_con_movimientos_y_el_mismo_saldo_no_se_avisa()
    {
        list($con_movimientos, $cuenta) = $this->proveedor_con_movimientos('Prov mismo saldo', 1000);

        Notification::fake();

        $nombre_repetido = $this->nombre('Prov repetido en el archivo');

        $this->importar_por_ia($this->xlsx(['Nombre', 'Saldo'], [
            [$con_movimientos->name, 1000],
            [$nombre_repetido,       450],
            [$nombre_repetido,       450],
        ]))->assertStatus(200);

        $this->assertCount(1, $this->movimientos($cuenta));
        $this->assertEqualsWithDelta(1000, (float) $cuenta->fresh()->saldo, 0.01);

        // El repetido: un solo proveedor con un solo "Saldo inicial".
        $this->assert_saldo_inicial('provider', $this->proveedor_por_nombre($nombre_repetido), 450);

        $this->assert_notificacion_sin_avisos();
    }

    /**
     * Saldo 0 en el Excel: no hay nada que cargar. El proveedor queda con sus cuentas VACÍAS (la
     * cuenta se crea si faltaba, también al proveedor viejo que no la tenía) y el botón "Saldo
     * inicial" sigue disponible. Antes la importación cargaba un "Saldo inicial" de $0, la cuenta
     * quedaba "con movimientos" y el botón, que no acepta 0, ya no se podía usar (422).
     *
     * @test
     */
    public function un_saldo_cero_no_carga_movimiento_y_deja_la_cuenta_lista_para_el_boton()
    {
        // Tal cual lo dejaba ProviderImport antes del arreglo: sin crear_credit_accounts().
        $viejo = Provider::create([
            'num'     => (int) Provider::where('user_id', $this->user_id)->max('num') + 1,
            'name'    => $this->nombre('Prov viejo en cero'),
            'user_id' => $this->user_id,
        ]);

        $nombre_nuevo = $this->nombre('Prov nuevo en cero');

        Notification::fake();

        $this->importar_por_ia($this->xlsx(['Nombre', 'Saldo'], [
            [$nombre_nuevo, 0],
            [$viejo->name,  0],
        ]))->assertStatus(200);

        $nuevo = $this->proveedor_por_nombre($nombre_nuevo);

        foreach ([$nuevo, $viejo] as $proveedor) {

            $this->assert_tiene_sus_dos_cuentas('provider', $proveedor);

            $cuenta = $this->cuenta('provider', $proveedor->id, 1);

            $this->assertCount(0, $this->movimientos($cuenta), 'Un saldo 0 no carga ningún movimiento en la cuenta de "'.$proveedor->name.'".');

            // Y la cuenta vacía acepta el saldo inicial por el botón.
            $this->postJson('api/current-acount/saldo-inicial', [
                'credit_account_id' => $cuenta->id,
                'model_name'        => 'provider',
                'model_id'          => $proveedor->id,
                'is_for_debe'       => true,
                'saldo_inicial'     => 1500,
            ])->assertStatus(201);
        }

        $this->assert_notificacion_sin_avisos();
    }

    /**
     * El aviso tiene tope: como mucho 50 proveedores y, si hay más, un párrafo "y N proveedores
     * más". Sin el tope, con unos 300 nombres el evento supera los 10 KB de Pusher y se pierde la
     * notificación ENTERA de fin de importación, botón incluido.
     *
     * Los proveedores se siembran por el camino real más barato: una primera importación les carga
     * un saldo y la segunda trae otro.
     *
     * @test
     */
    public function el_aviso_nombra_como_mucho_cincuenta_proveedores()
    {
        $filas_primera = [];
        $filas_segunda = [];

        for ($i = 1; $i <= 52; $i++) {
            $nombre = $this->nombre('Prov tope '.str_pad($i, 2, '0', STR_PAD_LEFT));

            $filas_primera[] = [$nombre, 100];
            $filas_segunda[] = [$nombre, 200];
        }

        $this->importar_por_ia($this->xlsx(['Nombre', 'Saldo'], $filas_primera))->assertStatus(200);

        Notification::fake();

        $this->importar_por_ia($this->xlsx(['Nombre', 'Saldo'], $filas_segunda))->assertStatus(200);

        $parrafos = $this->parrafos_del_aviso();

        // 50 proveedores, "y 2 proveedores más" y la explicación.
        $this->assertCount(52, $parrafos);

        for ($i = 0; $i < 50; $i++) {
            $this->assertSame($filas_segunda[$i][0].': saldo en el Excel $200, saldo en la cuenta $100', $parrafos[$i]);
        }

        $this->assertSame('y 2 proveedores más', $parrafos[50]);
        $this->assertStringContainsString('cuenta vacía', $parrafos[51]);

        // Y el evento entra en Pusher con margen (el límite es 10 KB para el evento entero).
        $notificacion = $this->notificacion_de_fin();
        $datos = $notificacion->toBroadcast(User::find($this->user_id))->data;

        $this->assertLessThan(9000, strlen(json_encode($datos)), 'El evento de fin de importación no entra en Pusher.');
    }

    /**
     * "Solo editar" (`create_and_edit` apagado) con una fila de un proveedor que no existe y con
     * saldo: no hay proveedor donde cargarlo, así que la fila no hace nada. Antes reventaba con un
     * 500 a mitad de la importación (`$model->id` sobre null) y el resto del archivo no se importaba.
     *
     * @test
     */
    public function solo_editar_con_un_proveedor_que_no_existe_no_revienta_ni_crea_nada()
    {
        $existente = $this->proveedor_desde_el_abm('Prov existente solo editar');

        $nombre_inexistente = $this->nombre('Prov que no existe');

        // La fila que no existe va PRIMERO: si revienta, la del existente no llega a procesarse.
        $this->importar_por_ia($this->xlsx(['Nombre', 'Saldo'], [
            [$nombre_inexistente, 1500],
            [$existente->name,    2500],
        ]), ['create_and_edit' => false])->assertStatus(200);

        $this->assertEquals(
            0,
            Provider::withTrashed()->where('user_id', $this->user_id)->where('name', $nombre_inexistente)->count(),
            '"Solo editar" creó un proveedor.'
        );

        // El resto del archivo se importó.
        $this->assert_saldo_inicial('provider', $existente, 2500);
    }
}
