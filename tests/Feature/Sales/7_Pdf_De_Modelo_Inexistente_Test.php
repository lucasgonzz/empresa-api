<?php

namespace Tests\Feature\Sales;

use App\Models\AfipTicket;
use App\Models\Budget;
use App\Models\Sale;
use App\Models\User;
use Tests\TestCase;

/**
 * Grupo 326 (prompts 01 y 02): pedir el PDF de un modelo que no existe tiene que dar 404,
 * no un 500 con stack trace. Estos tests solo cubren el camino de error.
 *
 * El camino feliz de los PDF de este proyecto NO es testeable desde PHPUnit: los
 * constructores de las clases *Pdf terminan en $this->Output(); exit;, y ese exit() mata
 * el proceso del test. El camino del 404 corta antes de llegar a instanciar el PDF, asi
 * que si lo es. No perder una tarde intentando testear el camino feliz de estos endpoints.
 */
class Pdf_De_Modelo_Inexistente_Test extends TestCase
{

    /**
     * @group ventas-edicion
     * @test
    */
    public function pdf_de_venta_inexistente_da_404()
    {
        $user = User::find(500);

        $this->actingAs($user, 'web');

        $id = Sale::max('id') + 1;

        $response = $this->get('sale/pdf/'.$id);

        $response->assertStatus(404);
    }

    /**
     * @group ventas-edicion
     * @test
    */
    public function ticket_de_venta_inexistente_da_404()
    {
        $user = User::find(500);

        $this->actingAs($user, 'web');

        $id = Sale::max('id') + 1;

        $response = $this->get('sale/sale-ticket-pdf/'.$id);

        $response->assertStatus(404);
    }

    /**
     * @group ventas-edicion
     * @test
    */
    public function pdf_de_articulos_entregados_de_venta_inexistente_da_404()
    {
        $user = User::find(500);

        $this->actingAs($user, 'web');

        $id = Sale::max('id') + 1;

        $response = $this->get('sale/delivered-articles-pdf/'.$id);

        $response->assertStatus(404);
    }

    /**
     * @group ventas-edicion
     * @test
    */
    public function pdf_de_comprobante_afip_inexistente_da_404()
    {
        $user = User::find(500);

        $this->actingAs($user, 'web');

        // afipTicketA4Pdf busca en AfipTicket, no en Sale: el id inexistente tiene que
        // derivarse de esa tabla.
        $id = AfipTicket::max('id') + 1;

        $response = $this->get('sale/afip-ticket-a4-pdf/'.$id);

        $response->assertStatus(404);
    }

    /**
     * @group ventas-edicion
     * @test
    */
    public function pdf_de_presupuesto_inexistente_da_404()
    {
        $user = User::find(500);

        $this->actingAs($user, 'web');

        $id = Budget::max('id') + 1;

        $response = $this->get('budget/pdf/'.$id.'/0/0');

        $response->assertStatus(404);
    }

}
