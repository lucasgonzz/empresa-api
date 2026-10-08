<?php

namespace Tests\Import;

use App\Http\Controllers\Helpers\CreditAccountHelper;
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

    protected function tearDown(): void
    {
        foreach ($this->temporales as $ruta) {
            @unlink($ruta);
        }

        $this->temporales = [];

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
    }
}
