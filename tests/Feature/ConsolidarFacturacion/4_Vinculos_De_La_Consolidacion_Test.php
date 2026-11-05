<?php

namespace Tests\Feature\ConsolidarFacturacion;

use App\Models\AfipTicket;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\EmpresaTestCase;

/** @group consolidar_facturacion */
class Vinculos_De_La_Consolidacion_Test extends EmpresaTestCase
{
    /** @test */
    public function expone_los_vinculos_en_ambos_sentidos_solo_cuando_se_piden()
    {
        $comercio = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->firstOrFail();
        $consolidada = Sale::create(['user_id' => $comercio->id, 'is_consolidacion_facturacion' => 1]);
        $original = Sale::create([
            'user_id' => $comercio->id,
            'consolidacion_facturacion_id' => $consolidada->id,
        ]);
        AfipTicket::create(['sale_id' => $consolidada->id, 'cae' => '12345678901234', 'cbte_numero' => 14]);

        $this->getJson('api/sale/'.$original->id.'?vinculos_consolidacion=1')
            ->assertOk()
            ->assertJsonPath('model.vinculos_consolidacion.consolidada.id', $consolidada->id)
            ->assertJsonPath('model.vinculos_consolidacion.consolidada.afip_tickets.0.cbte_numero', '14');

        $this->getJson('api/sale/'.$consolidada->id.'?vinculos_consolidacion=1')
            ->assertOk()
            ->assertJsonPath('model.vinculos_consolidacion.ventas_de_origen.0.id', $original->id);

        $respuesta_anterior = $this->getJson('api/sale/'.$original->id)->assertOk();
        $this->assertArrayNotHasKey('vinculos_consolidacion', $respuesta_anterior->json('model'));
    }

    /** @test */
    public function un_vinculo_no_expone_una_venta_de_otro_comercio()
    {
        $comercio = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->firstOrFail();
        $otro_comercio = User::factory()->create();
        $consolidada = Sale::create(['user_id' => $otro_comercio->id, 'is_consolidacion_facturacion' => 1]);
        $original = Sale::create([
            'user_id' => $comercio->id,
            'consolidacion_facturacion_id' => $consolidada->id,
        ]);

        $this->getJson('api/sale/'.$original->id.'?vinculos_consolidacion=1')
            ->assertOk()
            ->assertJsonPath('model.vinculos_consolidacion.consolidada', null);

        $this->getJson('api/sale/'.$consolidada->id.'?vinculos_consolidacion=1')->assertNotFound();
    }
}
