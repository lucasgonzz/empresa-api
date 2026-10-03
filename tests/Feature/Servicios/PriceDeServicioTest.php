<?php

namespace Tests\Feature\Servicios;

use App\Models\Service;
use Tests\EmpresaTestCase;

/**
 * `POST api/service` valida el precio antes de insertar.
 *
 * Caso real (2/10/2026, pack-descartables): llegó `price = "3500}"` y el insert reventó con
 * `1265 Data truncated for column 'price'` (500). Ahora es un 422 y no se escribe nada.
 *
 * @group servicios
 */
class PriceDeServicioTest extends EmpresaTestCase
{
    /**
     * @test
     * @return void
     */
    public function un_precio_con_basura_es_422_y_no_crea_el_servicio()
    {
        $antes = Service::count();

        $response = $this->postJson('api/service', ['name' => 'ENVIO', 'price' => '3500}']);

        $response->assertStatus(422)->assertJsonValidationErrors(['price']);
        $this->assertSame($antes, Service::count());
    }

    /**
     * @test
     * @return void
     */
    public function precio_vacio_o_ausente_es_422()
    {
        $this->postJson('api/service', ['name' => 'ENVIO', 'price' => ''])
            ->assertStatus(422)->assertJsonValidationErrors(['price']);

        $this->postJson('api/service', ['name' => 'ENVIO'])
            ->assertStatus(422)->assertJsonValidationErrors(['price']);
    }

    /**
     * @test
     * @return void
     */
    public function precio_fuera_del_rango_del_decimal_es_422()
    {
        $this->postJson('api/service', ['name' => 'ENVIO', 'price' => '100000000'])
            ->assertStatus(422)->assertJsonValidationErrors(['price']);
    }

    /**
     * @test
     * @return void
     */
    public function nombre_ausente_es_422()
    {
        $this->postJson('api/service', ['price' => 3500])
            ->assertStatus(422)->assertJsonValidationErrors(['name']);
    }

    /**
     * @test
     * @return void
     */
    public function un_precio_valido_se_guarda_como_siempre()
    {
        $response = $this->postJson('api/service', ['name' => 'ENVIO', 'price' => '3500.50']);

        $response->assertStatus(201);
        $this->assertEquals(3500.50, Service::find($response->json('model.id'))->price);

        $this->postJson('api/service', ['name' => 'ENVIO', 'price' => 3500])->assertStatus(201);
    }
}
