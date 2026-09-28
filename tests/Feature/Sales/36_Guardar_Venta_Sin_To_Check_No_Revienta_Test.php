<?php

namespace Tests\Feature\Sales;

use App\Models\Client;
use App\Models\Sale;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\EmpresaTestCase;

/**
 * Reproduce el 500 real de produccion (cliente Tiju/bellabianca, 28/9/2026): una venta
 * sincronizada desde el modo offline (offline/sync_sales.js) llega sin `to_check`,
 * `discounts_in_services` ni `surchages_in_services` en el payload -- exactamente lo que pasa
 * cuando esas claves quedan en `undefined` en el store de vender y `JSON.stringify` las
 * descarta. Antes del fix, `SaleController::store()` las leia crudas de `$request` y el
 * `null` explicito rompia la columna NOT NULL de `to_check`
 * (SQLSTATE[23000]: Column 'to_check' cannot be null), porque el DEFAULT de MySQL solo
 * aplica cuando la columna se omite del INSERT, no cuando viaja con NULL explicito.
 */
class Guardar_Venta_Sin_To_Check_No_Revienta_Test extends EmpresaTestCase
{

    /**
     * @group sales
     * @test
    */
    public function guardar_venta_sin_to_check_no_revienta()
    {
        $client = Client::where('name', TestingFerreteriaSeeder::CLIENTE_CONTADO)->first();

        $articulo = $this->articulo('Martillo acero');

        $data = [
            'client_id'                         => $client->id,
            'save_current_acount'               => 1,
            'omitir_en_cuenta_corriente'        => 0,
            'sub_total'                         => 100,
            'total'                             => 100,
            'terminada'                         => 1,
            'discounts'                         => [],
            'surchages'                         => [],
            'items' => [
                [
                    'is_article'    => true,
                    'id'            => $articulo->id,
                    'price_vender'  => 100,
                    'amount'        => 1,
                ],
            ],
        ];

        // A proposito: sin 'to_check', 'discounts_in_services' ni 'surchages_in_services',
        // igual que llega una venta offline sincronizada despues de perder esas claves.
        $response = $this->post('api/sale', $data);

        $response->assertStatus(201);

        $sale = Sale::find($response->json('model.id'));

        $this->assertEquals(0, $sale->to_check);
        $this->assertEquals(1, $sale->discounts_in_services);
        $this->assertEquals(1, $sale->surchages_in_services);
    }
}
