<?php

namespace Tests\Feature\Compras;

use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\Provider;
use App\Models\ProviderOrder;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Misión importacion-proveedores-saldo-inicial (8/10/2026) — la compra a un proveedor importado.
 *
 * La importación de proveedores creaba el proveedor SIN sus cuentas corrientes. El saldo del Excel
 * se perdía en silencio, y además el proveedor quedaba roto para lo que sigue: una compra en cuenta
 * corriente a ese proveedor reventaba con un 500 (`Trying to get property 'id' of non-object` en
 * `NewProviderOrderHelper::crear_current_acount()`, porque `set_credit_account()` busca la cuenta y
 * no la crea). Medido en s24 antes del arreglo.
 *
 * Por los endpoints reales: el proveedor entra por el modal con IA (`api/ai-excel-import/import`) y
 * la compra por `api/provider-order`. Los ítems del fixture son de otro proveedor: van con
 * `update_provider` en 0, y sin actualizar precios ni stock, para no tocar el fixture.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group compras
 * @group importacion-proveedores-saldo-inicial
 */
class Compra_a_proveedor_importado_Test extends ComprasTestCase
{
    /** @var array Archivos temporales (los .xlsx de %TEMP% y la copia en storage) a borrar al terminar. */
    protected $archivos_temporales = [];

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

    /**
     * Importa un proveedor (solo el nombre) por el modal con IA y lo devuelve.
     *
     * @param  string $nombre
     * @return \App\Models\Provider
     */
    protected function importar_proveedor($nombre)
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray([['Nombre'], [$nombre]], null, 'A1');

        $ruta = sys_get_temp_dir().'/'.uniqid('zz_compra_prov_importado_').'.xlsx';
        (new Xlsx($spreadsheet))->save($ruta);
        $this->archivos_temporales[] = $ruta;

        // El modal con IA lee el Excel de storage/app, donde lo dejó /analyze.
        $relativa = 'imported_files/zz_test_'.uniqid().'.xlsx';
        copy($ruta, storage_path('app/'.$relativa));
        $this->archivos_temporales[] = storage_path('app/'.$relativa);

        $this->postJson('api/ai-excel-import/import', [
            'excel_path'      => $relativa,
            'model'           => 'provider',
            'columns'         => ['nombre' => 0],
            'create_and_edit' => true,
            'start_row'       => 2,
            'finish_row'      => null,
        ])->assertStatus(200);

        $proveedor = Provider::where('user_id', $this->app['auth']->user()->id)->where('name', $nombre)->first();

        $this->assertNotNull($proveedor, 'La importación no creó el proveedor.');

        return $proveedor;
    }

    /**
     * El proveedor recién importado acepta una compra en cuenta corriente, y la deuda queda en SU
     * cuenta en pesos.
     *
     * @test
     */
    public function una_compra_en_cuenta_corriente_a_un_proveedor_recien_importado_va_a_su_cuenta()
    {
        $proveedor = $this->importar_proveedor('zz Proveedor importado compra '.uniqid());

        $respuesta = $this->postJson('api/provider-order', $this->payload_compra([
            'provider_id'             => $proveedor->id,
            'modo_facturacion'        => 'manual',
            'update_prices'           => 0,
            'update_stock'            => 0,
            'total_with_iva'          => 0,
            'moneda_id'               => 1,
            'generate_current_acount' => 1,
            'articles'                => [
                $this->item('Marco para cama', 1000, 1, ['update_provider' => 0]),
            ],
        ]));

        $respuesta->assertStatus(201);

        $compra = ProviderOrder::find($respuesta->json('model.id'));

        $this->assertNotNull($compra);

        $cuenta = CreditAccount::where('model_name', 'provider')
                                ->where('model_id', $proveedor->id)
                                ->where('moneda_id', 1)
                                ->first();

        $this->assertNotNull($cuenta, 'El proveedor importado no tiene cuenta en pesos.');

        $movimiento = CurrentAcount::where('provider_order_id', $compra->id)->first();

        $this->assertNotNull($movimiento, 'La compra con generate_current_acount no dejó su movimiento en la cuenta corriente.');

        $this->assertEquals($cuenta->id, $movimiento->credit_account_id, 'El movimiento de la compra no quedó en la cuenta en pesos del proveedor.');
        $this->assertEquals($proveedor->id, $movimiento->provider_id);
        $this->assertGreaterThan(0, (float) $compra->total);
        $this->assertEqualsWithDelta((float) $compra->total, (float) $movimiento->debe, 0.01, 'La deuda con el proveedor es el total de la compra.');
        $this->assertEqualsWithDelta((float) $compra->total, (float) $movimiento->saldo, 0.01, 'Es el primer movimiento de la cuenta: su saldo es su debe.');
    }
}
