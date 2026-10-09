<?php

namespace Tests\Import;

use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\ImportHistory;
use App\Models\Provider;
use Illuminate\Http\UploadedFile;
use OpenSpout\Writer\Common\Creator\WriterEntityFactory;

/**
 * Misión importacion-proveedores-saldo-inicial (8/10/2026) — los proveedores que crea la
 * importación de ARTÍCULOS.
 *
 * Una lista de artículos con columna "Proveedor" crea los proveedores que no existen
 * (`ArticleImport::set_providers()`, por lote) con un `Provider::create()` pelado: sin cuentas
 * corrientes. Es la misma clase de error que la importación de proveedores, por otra puerta: ese
 * proveedor no acepta una compra en cuenta corriente (500) ni un saldo inicial importado.
 *
 * El arreglo asegura las dos cuentas (pesos y dólares) de cada proveedor de la columna, los nuevos
 * y también los viejos que hayan quedado sin cuenta, sin duplicar las de quien ya las tiene. Corre
 * en el job de cada lote, SIN sesión: las cuentas tienen que salir con el user_id del comercio.
 *
 * Caja negra: todo por el endpoint real, con un .xlsx que escribe el propio test (mismo molde que
 * ImportacionGeneraSlugTest). Tenant 900 (ImportTestCase): sus proveedores A, B y C se siembran sin
 * cuentas, que es justo el estado "proveedor viejo sin cuenta".
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class ProveedoresDeLaImportacionDeArticulosTest extends ImportTestCase
{
    /** @var array archivos temporales a borrar al terminar cada test */
    protected $temporales = [];

    /**
     * Excel que la importación dejó en storage/app (rutas relativas, como `ImportHistory::excel_url`).
     * Al terminar se borran junto con sus derivados: el CSV de los lotes
     * (`imported_files/<nombre>_<time>.csv`, que en producción queda a propósito) y los de
     * FinalizeArticleImport::borrar_archivos_derivados().
     *
     * @var array
     */
    protected $excels_en_storage = [];

    protected function tearDown(): void
    {
        foreach ($this->temporales as $ruta) {
            @unlink($ruta);
        }

        $this->temporales = [];

        foreach ($this->excels_en_storage as $relativa) {

            $derivados = array_merge(
                [storage_path('app/' . $relativa)],
                glob(storage_path('app/' . $relativa) . '.hoja*') ?: [],
                glob(storage_path('app/imported_files/' . pathinfo($relativa, PATHINFO_FILENAME) . '_*.csv*')) ?: []
            );

            foreach ($derivados as $archivo) {
                if (is_file($archivo)) {
                    @unlink($archivo);
                }
            }
        }

        $this->excels_en_storage = [];

        parent::tearDown();
    }

    /**
     * Escribe un .xlsx temporal con las columnas de ImportTestCase::columnas() más "proveedor" al
     * final (columna 9).
     *
     * @param  array $filas
     * @return string ruta absoluta
     */
    protected function xlsx(array $filas)
    {
        $ruta = sys_get_temp_dir() . '/' . uniqid('prov_art_') . '.xlsx';

        $writer = WriterEntityFactory::createXLSXWriter();
        $writer->openToFile($ruta);
        $writer->addRow(WriterEntityFactory::createRowFromArray([
            'codigo_de_barras', 'sku', 'codigo_de_proveedor', 'nombre', 'costo', 'precio', 'stock', 'iva', 'proveedor',
        ]));

        foreach ($filas as $fila) {
            $writer->addRow(WriterEntityFactory::createRowFromArray($fila));
        }

        $writer->close();

        $this->temporales[] = $ruta;

        return $ruta;
    }

    /**
     * Una fila nueva (sin bar_code ni sku) con código de proveedor, nombre y el proveedor de la
     * columna "proveedor".
     *
     * @param  string $provider_code
     * @param  string $nombre
     * @param  string $proveedor
     * @return array
     */
    protected function fila($provider_code, $nombre, $proveedor)
    {
        return [null, null, $provider_code, $nombre, 100.0, 200.0, 1.0, '21', $proveedor];
    }

    /**
     * Importa un .xlsx por el endpoint real (mismo camino que ImportTestCase::importar(), pero para
     * un archivo fuera de fixtures/ y con la columna "proveedor" mapeada) y devuelve el ImportHistory.
     *
     * @param  string $ruta
     * @return \App\Models\ImportHistory
     */
    protected function importar_xlsx($ruta)
    {
        /* ArticleController@import mueve el archivo con storeAs(): se importa sobre una copia. */
        $copia = sys_get_temp_dir() . '/' . uniqid('prov_art_copia_') . '.xlsx';
        copy($ruta, $copia);
        // storeAs() copia la subida a storage y la deja donde estaba: se borra al terminar.
        $this->temporales[] = $copia;

        $data = array_merge(
            [
                'models' => new UploadedFile(
                    $copia,
                    basename($ruta),
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    null,
                    true
                ),
                'start_row'   => 2,
                'finish_row'  => 99999,
                'provider_id' => null,
            ],
            self::config_por_defecto(),
            self::columnas(),
            ['prop_proveedor' => 9]
        );

        $this->postJson('/api/article/excel/import', $data)->assertStatus(200);

        $import = ImportHistory::where('user_id', $this->tenant->id)->orderBy('id', 'DESC')->first();

        $this->assertNotNull($import, 'La importación no dejó ImportHistory.');

        if (!empty($import->excel_url)) {
            $this->excels_en_storage[] = $import->excel_url;
        }

        $this->assertInvariantesDeConteo($import);

        return $import;
    }

    /**
     * Cuántas cuentas tiene un proveedor en una moneda.
     *
     * @param  int $provider_id
     * @param  int $moneda_id
     * @return int
     */
    protected function cuentas($provider_id, $moneda_id)
    {
        return CreditAccount::where('model_name', 'provider')
                            ->where('model_id', $provider_id)
                            ->where('moneda_id', $moneda_id)
                            ->count();
    }

    /**
     * Una y solo una cuenta por moneda, del comercio del tenant y en cero.
     *
     * @param  \App\Models\Provider $proveedor
     * @return void
     */
    protected function assert_tiene_sus_dos_cuentas($proveedor)
    {
        foreach ([1, 2] as $moneda_id) {

            $cuentas = CreditAccount::where('model_name', 'provider')
                                    ->where('model_id', $proveedor->id)
                                    ->where('moneda_id', $moneda_id)
                                    ->get();

            $this->assertCount(1, $cuentas, 'El proveedor "' . $proveedor->name . '" tiene que tener exactamente una cuenta en la moneda ' . $moneda_id . '.');

            $this->assertEquals(
                $this->tenant->id,
                (int) $cuentas->first()->user_id,
                'La cuenta en la moneda ' . $moneda_id . ' de "' . $proveedor->name . '" quedó con el user_id de otro comercio: el job corre sin sesión.'
            );

            $this->assertEqualsWithDelta(0, (float) $cuentas->first()->saldo, 0.001);
        }
    }

    /**
     * Un proveedor NUEVO de la columna "proveedor" nace con sus dos cuentas. Y uno que ya tenía
     * las suyas (B, en la misma lista) no recibe otras.
     *
     * @return void
     */
    public function test_un_proveedor_nuevo_de_la_columna_proveedor_nace_con_sus_dos_cuentas()
    {
        $nombre_nuevo = 'zz Proveedor nuevo de articulos ' . uniqid();

        $con_cuentas = $this->providers['B'];

        CreditAccountHelper::crear_credit_accounts('provider', $con_cuentas->id, $this->tenant->id);

        $ids_de_b = CreditAccount::where('model_name', 'provider')->where('model_id', $con_cuentas->id)->orderBy('id')->pluck('id')->all();

        $this->importar_xlsx($this->xlsx([
            $this->fila('PC-PROV-ART-1', 'Taladro de la lista', $nombre_nuevo),
            $this->fila('PC-PROV-ART-2', 'Amoladora de la lista', $con_cuentas->name),
        ]));

        $nuevo = Provider::where('user_id', $this->tenant->id)->where('name', $nombre_nuevo)->first();

        $this->assertNotNull($nuevo, 'La importación no creó el proveedor de la columna "proveedor".');

        $this->assert_tiene_sus_dos_cuentas($nuevo);

        $this->assertEquals(
            $ids_de_b,
            CreditAccount::where('model_name', 'provider')->where('model_id', $con_cuentas->id)->orderBy('id')->pluck('id')->all(),
            'Un proveedor que ya tenía sus dos cuentas recibió otras (o perdió las suyas).'
        );
    }

    /**
     * Un proveedor que YA existía sin cuentas (como los dejaba la importación vieja) y aparece en
     * la columna queda con ellas: la importación de artículos también repara.
     *
     * @return void
     */
    public function test_un_proveedor_existente_sin_cuenta_que_aparece_en_la_columna_queda_con_ellas()
    {
        $sin_cuentas = $this->providers['A'];

        $this->assertEquals(0, $this->cuentas($sin_cuentas->id, 1) + $this->cuentas($sin_cuentas->id, 2), 'Premisa: el proveedor A se siembra sin cuentas.');

        $this->importar_xlsx($this->xlsx([
            $this->fila('PC-PROV-ART-3', 'Sierra de la lista', $sin_cuentas->name),
        ]));

        $this->assertEquals(
            1,
            Provider::where('user_id', $this->tenant->id)->where('name', $sin_cuentas->name)->count(),
            'La importación duplicó el proveedor existente.'
        );

        $this->assert_tiene_sus_dos_cuentas($sin_cuentas);

        // El vecino: C también se siembra sin cuentas, pero NO aparece en la columna. No se toca.
        $vecino = $this->providers['C'];

        $this->assertEquals(
            0,
            $this->cuentas($vecino->id, 1) + $this->cuentas($vecino->id, 2),
            'La importación les creó cuentas a proveedores que no aparecen en la columna "proveedor".'
        );
    }

    /**
     * Un proveedor que tiene SOLO la cuenta en pesos (los de antes de las cuentas en dólares) y
     * aparece en la columna queda con las dos; la de pesos no cambia.
     *
     * @return void
     */
    public function test_un_proveedor_con_solo_la_cuenta_en_pesos_queda_con_las_dos()
    {
        $solo_pesos = $this->providers['B'];

        $pesos = CreditAccount::create([
            'moneda_id'  => 1,
            'model_name' => 'provider',
            'model_id'   => $solo_pesos->id,
            'saldo'      => 0,
            'user_id'    => $this->tenant->id,
        ]);

        $this->importar_xlsx($this->xlsx([
            $this->fila('PC-PROV-ART-5', 'Taladro percutor de la lista', $solo_pesos->name),
        ]));

        $this->assert_tiene_sus_dos_cuentas($solo_pesos);

        $this->assertEquals(
            $pesos->id,
            CreditAccount::where('model_name', 'provider')->where('model_id', $solo_pesos->id)->where('moneda_id', 1)->value('id'),
            'La cuenta en pesos que ya existía cambió.'
        );
    }

    /**
     * Las cuentas de un CLIENTE con el mismo id que el proveedor no cuentan como del proveedor
     * (`credit_accounts` es de clientes y proveedores, y los ids de las dos tablas se pisan): el
     * proveedor de la columna recibe las suyas igual.
     *
     * @return void
     */
    public function test_las_cuentas_de_un_cliente_con_el_mismo_id_no_cuentan_como_del_proveedor()
    {
        // Un proveedor del tenant sin cuentas, como los sembrados.
        $proveedor = Provider::create([
            'name'    => 'zz Proveedor con cliente del mismo id ' . uniqid(),
            'user_id' => $this->tenant->id,
        ]);

        $cliente = Client::find($proveedor->id);

        if (is_null($cliente)) {
            $cliente = Client::create([
                'id'      => $proveedor->id,
                'name'    => 'zz Cliente con el id del proveedor ' . uniqid(),
                'user_id' => $this->tenant->id,
            ]);
        }

        CreditAccountHelper::crear_credit_accounts('client', $cliente->id, $cliente->user_id);

        $this->assertEquals(
            2,
            CreditAccount::where('model_name', 'client')->where('model_id', $proveedor->id)->count(),
            'Premisa: el cliente con el mismo id que el proveedor tiene sus dos cuentas.'
        );

        $this->importar_xlsx($this->xlsx([
            $this->fila('PC-PROV-ART-6', 'Atornillador de la lista', $proveedor->name),
        ]));

        $this->assert_tiene_sus_dos_cuentas($proveedor);
    }

    /**
     * Por admin-sync (el motor de /implementar), SIN sesión: las cuentas salen con el user_id del
     * comercio de la importación, no con el de UserHelper::userId().
     *
     * Es el caso que el `user_id` explícito existe para cubrir. Con `model=article`, AdminSync no
     * loguea a nadie (a diferencia de clientes y proveedores) y en producción el lote corre en un
     * job, también sin sesión: ahí UserHelper::userId() cae a `config('app.USER_ID')`, que en una
     * base compartida es OTRO comercio. En testing esa clave es 500 y el tenant es 900, así que una
     * cuenta creada con el user_id de la sesión se ve.
     *
     * @return void
     */
    public function test_por_admin_sync_sin_sesion_las_cuentas_salen_con_el_user_id_del_comercio()
    {
        $nombre_nuevo = 'zz Proveedor admin-sync ' . uniqid();

        $ruta = $this->xlsx([
            $this->fila('PC-PROV-ART-4', 'Lijadora de la lista', $nombre_nuevo),
        ]);

        // El admin manda el Excel ya guardado por /analyze, relativo a storage/app.
        $relativa = 'imported_files/zz_test_' . uniqid() . '.xlsx';
        copy($ruta, storage_path('app/' . $relativa));
        $this->excels_en_storage[] = $relativa;

        config([
            'services.admin_api.require_api_key' => true,
            'services.admin_api.api_key'         => 'clave-de-prueba',
        ]);

        // Sin sesión: ImportTestCase deja logueado al tenant, y el admin no viene logueado.
        $this->app['auth']->forgetGuards();

        $this->assertNotEquals(
            $this->tenant->id,
            UserHelper::userId(),
            'Premisa: sin sesión, UserHelper::userId() tiene que ser OTRO comercio para que el test distinga.'
        );

        $this->postJson('api/admin-sync/ai-excel-import/import', [
            'user_id'                                           => $this->tenant->id,
            'model'                                             => 'article',
            'excel_path'                                        => $relativa,
            'columns'                                           => [
                'codigo_de_proveedor' => 2,
                'nombre'              => 3,
                'costo'               => 4,
                'precio'              => 5,
                'stock_actual'        => 6,
                'iva'                 => 7,
                'proveedor'           => 8,
            ],
            'create_and_edit'                                   => true,
            'start_row'                                         => 2,
            'finish_row'                                        => 99999,
            'provider_id'                                       => null,
            'permitir_provider_code_repetido_en_multi_providers' => true,
        ], ['X-Admin-Api-Key' => 'clave-de-prueba'])->assertStatus(200);

        $nuevo = Provider::where('user_id', $this->tenant->id)->where('name', $nombre_nuevo)->first();

        $this->assertNotNull($nuevo, 'La importación por admin-sync no creó el proveedor de la columna "proveedor".');

        $this->assert_tiene_sus_dos_cuentas($nuevo);
    }
}
