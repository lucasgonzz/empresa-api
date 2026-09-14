<?php

namespace Tests\Feature\Sales;

use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Fix del 12/9/2026 — diagnostico del reporte "el sistema no me funciona" del cliente Tiju.
 *
 * clear_actualizandose_por() escribia sobre el resultado de Sale::find($sale_id) sin
 * comprobar si era null. Cuando la venta ya no existe (borrada, o el id quedo viejo en el
 * frontend), eso tiraba "Creating default object from empty value" y el endpoint respondia
 * 500 en vez de 200 -- confirmado en produccion (Tiju, sale_id=591, 12/9/2026 11:02:13).
 *
 * Mismo patron que 7_Pdf_De_Modelo_Inexistente_Test.php para el id inexistente
 * (Sale::max('id') + 1), pero esta ruta vive en routes/api.php (grupo 'api', no 'web' como
 * sale/pdf): la URL lleva el prefijo api/ y se pega con putJson(), como el resto de los
 * tests de Sales/ que ejercitan rutas de ese archivo (ver 17_Actualizar_venta...Test.php).
 */
class Clear_actualizandose_por_venta_inexistente_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * @group ventas-edicion
     * @test
     */
    public function limpiar_actualizandose_por_de_una_venta_inexistente_da_200_y_no_500()
    {
        $user = User::find(500);

        $this->actingAs($user, 'web');

        $id = Sale::max('id') + 1;

        $response = $this->putJson('api/sale-clear-actualizandose-por/'.$id);

        $response->assertStatus(200);
    }

    /**
     * Camino feliz, para que la guarda nueva no rompa el caso donde la venta si existe.
     *
     * @group ventas-edicion
     * @test
     */
    public function limpiar_actualizandose_por_de_una_venta_existente_limpia_el_campo()
    {
        $user = User::find(500);

        $this->actingAs($user, 'web');

        $sale = Sale::create([
            'user_id'               => $user->id,
            'actualizandose_por_id' => $user->id,
        ]);

        $response = $this->putJson('api/sale-clear-actualizandose-por/'.$sale->id);

        $response->assertStatus(200);

        $this->assertNull($sale->fresh()->actualizandose_por_id);
    }
}
