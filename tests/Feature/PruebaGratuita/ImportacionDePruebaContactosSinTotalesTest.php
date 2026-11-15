<?php

namespace Tests\Feature\PruebaGratuita;

use App\Http\Controllers\Helpers\LocalImportHelper;
use App\Http\Controllers\Helpers\PruebaHelper;
use App\Imports\ClientImport;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\Provider;
use App\Notifications\GlobalNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\EmpresaTestCase;

/**
 * La fila de total de una lista de clientes o proveedores en la prueba gratuita (misión
 * prueba-e2e-campana-imperio, ronda 6, ítem E8.1, 14/11/2026).
 *
 * En la prueba los clientes NO pasan por la carga asistida (PlanillaDePrueba es solo de artículos): el admin
 * reenvía el Excel del ticket al análisis en segundo plano (AdminSync\AiExcelImportController::analizar_archivo,
 * `model=client` sin `automatizar_prueba`) y el dueño lo importa desde el modal, que llega a
 * AiExcelImportController::import_clients() y a ClientImport. Medido contra 9f300ddb: «deudores.xlsx» (6 clientes y
 * una fila «TOTAL» con la suma de la columna «Debe») creaba 7 clientes, uno «TOTAL» con $ 555.700,50 de saldo.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
class ImportacionDePruebaContactosSinTotalesTest extends EmpresaTestCase
{
    const FIXTURES = __DIR__ . '/../../Import/fixtures/';
    const CLIENTES = ['Constructora Del Litoral SRL', 'Gómez, Jorge (albañil)', 'Plomería El Caño', 'Municipalidad - Obras',
        'Rosa Fernández', 'Taller Mecánico Ruta 14'];
    const COLUMNAS_DEUDORES = ['nombre' => 0, 'cuit' => 1, 'telefono' => 2, 'saldo_actual' => 3];

    private $dueno;
    private $archivos = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno = auth()->user();
        config(['app.FOR_USER' => 'prueba', 'app.USER_ID' => $this->dueno->id]);
        PruebaHelper::olvidar_cache();
        PruebaHelper::activar_desde_payload($this->dueno, ['dias' => 7]);
        PruebaHelper::olvidar_cache();
    }

    protected function tearDown(): void
    {
        foreach ($this->archivos as $archivo) {
            @unlink($archivo);
        }
        PruebaHelper::olvidar_cache();

        parent::tearDown();
    }

    /** Deja el Excel donde lo deja /analyze y lo importa por el modal, como el dueño. */
    private function importar_por_el_modal($fuente, string $modelo, array $columnas, int $desde)
    {
        $relativa = 'imported_files/zz_prueba_contactos_' . uniqid() . '.xlsx';
        $destino = storage_path('app/' . $relativa);
        if (is_string($fuente)) {
            copy(self::FIXTURES . $fuente, $destino);
        } else {
            $libro = new Spreadsheet();
            $libro->getActiveSheet()->fromArray($fuente, null, 'A1', true);
            (new Xlsx($libro))->save($destino);
            $libro->disconnectWorksheets();
        }
        $this->archivos[] = $destino;

        $this->postJson('api/ai-excel-import/import', [
            'excel_path' => $relativa, 'model' => $modelo, 'columns' => $columnas,
            'create_and_edit' => true, 'start_row' => $desde, 'finish_row' => null,
        ])->assertStatus(200);
    }

    private function saldo(Client $cliente): float
    {
        return (float) CreditAccount::where('model_name', 'client')->where('model_id', $cliente->id)->where('moneda_id', 1)->value('saldo');
    }

    public function test_los_deudores_cargan_6_clientes_y_ninguno_se_llama_total(): void
    {
        $this->assertSame('b46cabc58487f0f0886c0cc863470c54961307fe73d8c87b6cc7cc5349f38897', hash_file('sha256', self::FIXTURES . 'deudores.xlsx'));
        $antes = (int) Client::withTrashed()->max('id');
        // Título en la fila 1 y cabecera en la 3: los datos arrancan en la 4.
        $this->importar_por_el_modal('deudores.xlsx', 'client', self::COLUMNAS_DEUDORES, 4);

        $nuevos = Client::where('user_id', $this->dueno->id)->where('id', '>', $antes)->orderBy('id')->get();
        $this->assertSame(self::CLIENTES, $nuevos->pluck('name')->all());
        $this->assertSame(0, Client::where('user_id', $this->dueno->id)->where('name', 'TOTAL')->count());

        $por_nombre = $nuevos->keyBy('name');
        // «$ 185.400,50» en texto y 42000 número; un saldo negativo es a favor del cliente; 0 queda en 0.
        $this->assertEquals(185400.50, $this->saldo($por_nombre['Constructora Del Litoral SRL']));
        $this->assertEquals(42000, $this->saldo($por_nombre['Gómez, Jorge (albañil)']));
        $this->assertEquals(-1500, $this->saldo($por_nombre['Rosa Fernández']));
        $this->assertEquals(0, $this->saldo($por_nombre['Taller Mecánico Ruta 14']));
        $this->assertSame('30712345678', (string) $por_nombre['Constructora Del Litoral SRL']->cuit);
    }

    public function test_el_aviso_de_la_importacion_nombra_la_fila_que_dejo_afuera(): void
    {
        $import = (new ClientImport(self::COLUMNAS_DEUDORES, true, 4, null))->descartando_filas_que_no_son_contactos();
        Excel::import($import, self::FIXTURES . 'deudores.xlsx');

        $this->assertSame([['fila' => 11, 'nombre' => 'TOTAL']], $import->filas_que_no_son_contactos());
        $bloques = $import->getInfoToShow();
        $this->assertSame('Filas que no son clientes', $bloques[0]['title']);
        $this->assertSame('Fila 11: "TOTAL"', $bloques[0]['parrafos'][0]);
    }

    public function test_un_cliente_que_se_parece_a_un_total_se_carga(): void
    {
        $antes = (int) Client::withTrashed()->max('id');
        $this->importar_por_el_modal([
            ['Cliente', 'CUIT', 'Tel', 'Debe'],
            ['Totalgas SRL', null, null, 1000],            // no es la palabra completa
            ['Total Energies', '30-11111111-2', null, 500], // trae CUIT
            ['Total Hogar', null, '3442 401010', 200],      // trae teléfono
            ['Suma 2000 SA', null, null, 300],              // trae números en el nombre
            ['Subtotal', null, null, 2000],                 // ESTE no es un cliente
        ], 'client', self::COLUMNAS_DEUDORES, 2);

        $this->assertSame(['Totalgas SRL', 'Total Energies', 'Total Hogar', 'Suma 2000 SA'],
            Client::where('user_id', $this->dueno->id)->where('id', '>', $antes)->orderBy('id')->pluck('name')->all());
    }

    public function test_la_fila_de_total_de_una_lista_de_proveedores_tampoco_se_carga(): void
    {
        $antes = (int) Provider::withTrashed()->max('id');
        $this->importar_por_el_modal([
            ['Proveedor', 'Le debo'], ['Distribuidora del Litoral', 1000], ['--- MAYORISTAS ---', null], ['Corralón Sur', 500], ['Totales:', 1500],
        ], 'provider', ['nombre' => 0, 'saldo_actual' => 1], 2);

        $this->assertSame(['Distribuidora del Litoral', 'Corralón Sur'],
            Provider::where('user_id', $this->dueno->id)->where('id', '>', $antes)->orderBy('id')->pluck('name')->all());
    }

    public function test_un_negocio_que_paga_sigue_cargando_todas_las_filas(): void
    {
        // Sin vencimiento: el negocio ya no está en la prueba (como en los tests de modo soporte).
        PruebaHelper::fijar_vencimiento($this->dueno->id, null);
        PruebaHelper::olvidar_cache();
        $this->assertFalse(PruebaHelper::tiene_periodo_de_prueba($this->dueno->id));

        $antes = (int) Client::withTrashed()->max('id');
        $this->importar_por_el_modal('deudores.xlsx', 'client', self::COLUMNAS_DEUDORES, 4);
        $this->assertSame(array_merge(self::CLIENTES, ['TOTAL']),
            Client::where('user_id', $this->dueno->id)->where('id', '>', $antes)->orderBy('id')->pluck('name')->all());
    }

    public function test_sin_el_setter_entra_todo_aunque_el_negocio_este_en_la_prueba(): void
    {
        // Así construye ClientImport AdminSync: la regla se activa solo si alguien la pide.
        $this->assertTrue(PruebaHelper::tiene_periodo_de_prueba($this->dueno->id));
        $antes = (int) Client::withTrashed()->max('id');
        $import = new ClientImport(self::COLUMNAS_DEUDORES, true, 4, null);
        Excel::import($import, self::FIXTURES . 'deudores.xlsx');

        $this->assertSame([], $import->filas_que_no_son_contactos());
        $this->assertSame(array_merge(self::CLIENTES, ['TOTAL']),
            Client::where('user_id', $this->dueno->id)->where('id', '>', $antes)->orderBy('id')->pluck('name')->all());
        $this->assertNotContains('Filas que no son clientes', array_column($import->getInfoToShow(), 'title'));
        // La regla en sí reconoce la fila: lo que la deja pasar es que nadie la pidió.
        $this->assertSame('TOTAL', LocalImportHelper::fila_que_no_es_un_contacto(['TOTAL', null, null, 555700.5], self::COLUMNAS_DEUDORES));
    }

    /** Las columnas de «deudores.xlsx» como las manda el modal clásico (`prop_<clave>`, 1-based). */
    const PROPS_DEUDORES = ['prop_nombre' => 1, 'prop_cuit' => 2, 'prop_telefono' => 3, 'prop_saldo_actual' => 4];

    /** La importación clásica de la lista de clientes o proveedores (`api/{client|provider}/excel/import`). */
    private function importar_por_el_clasico($fuente, string $modelo, array $props, int $desde): void
    {
        $copia = storage_path('app/imported_files/zz_prueba_clasico_' . uniqid() . '.xlsx');
        if (is_string($fuente)) {
            copy(self::FIXTURES . $fuente, $copia);
        } else {
            $libro = new Spreadsheet();
            $libro->getActiveSheet()->fromArray($fuente, null, 'A1', true);
            (new Xlsx($libro))->save($copia);
            $libro->disconnectWorksheets();
        }
        $this->archivos[] = $copia;

        $this->post('api/' . ($modelo === 'client' ? 'client' : 'provider') . '/excel/import', $props + [
            'models' => new UploadedFile($copia, 'lista.xlsx', null, null, true),
            'create_and_edit' => 1, 'start_row' => $desde, 'finish_row' => '',
        ])->assertStatus(200);
    }

    public function test_por_la_importacion_clasica_los_deudores_tampoco_crean_el_cliente_total_y_el_aviso_lo_nombra(): void
    {
        Notification::fake();
        $antes = (int) Client::withTrashed()->max('id');
        $this->importar_por_el_clasico('deudores.xlsx', 'client', self::PROPS_DEUDORES, 4);

        $this->assertSame(self::CLIENTES, Client::where('user_id', $this->dueno->id)->where('id', '>', $antes)->orderBy('id')->pluck('name')->all());
        Notification::assertSentTo($this->dueno, GlobalNotification::class, function ($aviso) {
            $bloque = collect((array) $aviso->info_to_show)->firstWhere('title', 'Filas que no son clientes');

            return !is_null($bloque) && $bloque['parrafos'][0] === 'Fila 11: "TOTAL"';
        });
    }

    public function test_por_la_importacion_clasica_la_fila_de_total_de_proveedores_tampoco_se_carga(): void
    {
        $antes = (int) Provider::withTrashed()->max('id');
        $this->importar_por_el_clasico([
            ['Proveedor', 'Le debo'], ['Distribuidora del Litoral', 1000], ['--- MAYORISTAS ---', null], ['Corralón Sur', 500], ['TOTAL GENERAL', 1500],
        ], 'provider', ['prop_nombre' => 1, 'prop_saldo_actual' => 2], 2);

        $this->assertSame(['Distribuidora del Litoral', 'Corralón Sur'],
            Provider::where('user_id', $this->dueno->id)->where('id', '>', $antes)->orderBy('id')->pluck('name')->all());
    }

    public function test_por_la_importacion_clasica_un_negocio_que_paga_sigue_cargando_todas_las_filas(): void
    {
        PruebaHelper::fijar_vencimiento($this->dueno->id, null);
        PruebaHelper::olvidar_cache();
        $this->assertFalse(PruebaHelper::tiene_periodo_de_prueba($this->dueno->id));

        $antes = (int) Client::withTrashed()->max('id');
        $this->importar_por_el_clasico('deudores.xlsx', 'client', self::PROPS_DEUDORES, 4);
        $this->assertSame(array_merge(self::CLIENTES, ['TOTAL']),
            Client::where('user_id', $this->dueno->id)->where('id', '>', $antes)->orderBy('id')->pluck('name')->all());

        $antes = (int) Provider::withTrashed()->max('id');
        $this->importar_por_el_clasico([['Proveedor', 'Le debo'], ['Distribuidora del Litoral', 1000], ['TOTAL GENERAL', 1000]],
            'provider', ['prop_nombre' => 1, 'prop_saldo_actual' => 2], 2);
        $this->assertSame(['Distribuidora del Litoral', 'TOTAL GENERAL'],
            Provider::where('user_id', $this->dueno->id)->where('id', '>', $antes)->orderBy('id')->pluck('name')->all());
    }
}
