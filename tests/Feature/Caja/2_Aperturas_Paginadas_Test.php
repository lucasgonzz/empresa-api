<?php

namespace Tests\Feature\Caja;

use App\Models\AperturaCaja;
use App\Models\Caja;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Auth;
use Tests\EmpresaTestCase;

/**
 * El modal "Aperturas" de Tesorería pagina las aperturas de una caja.
 *
 * `GET api/apertura-caja/{caja_id}` sin `page` sigue devolviendo TODAS (una SPA vieja no manda
 * `page`); con `page` devuelve solo esa página más el paginador.
 *
 * @group caja
 */
class Aperturas_Paginadas_Test extends EmpresaTestCase
{
    /** @var \App\Models\Caja */
    protected $caja;

    /** @var int */
    protected $cantidad = 7;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $this->caja = Caja::where('name', TestingFerreteriaSeeder::CAJA_EFECTIVO)
                        ->where('user_id', $owner->id)
                        ->first();

        if (is_null($this->caja)) {
            $this->fail('No existe la caja del fixture de testing. Sembrá la base del slot.');
        }

        // Base determinística: solo las aperturas que crea este test (se revierten al terminar).
        AperturaCaja::where('caja_id', $this->caja->id)->delete();

        for ($i = 1; $i <= $this->cantidad; $i++) {
            AperturaCaja::create([
                'caja_id'    => $this->caja->id,
                'saldo_apertura' => $i,
                'created_at' => now()->subDays($this->cantidad - $i),
            ]);
        }

        Auth::forgetGuards();
        $this->actingAs($owner, 'web');
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();

        parent::tearDown();
    }

    /**
     * @test
     * @return void
     */
    public function sin_page_devuelve_todas_las_aperturas_como_siempre()
    {
        $response = $this->getJson('api/apertura-caja/'.$this->caja->id);

        $response->assertStatus(200);
        $this->assertCount($this->cantidad, $response->json('models'));
        $this->assertNull($response->json('total'), 'Sin page no debería venir el paginador.');
    }

    /**
     * @test
     * @return void
     */
    public function con_page_devuelve_solo_esa_pagina_y_el_paginador()
    {
        $response = $this->getJson('api/apertura-caja/'.$this->caja->id.'?page=1&per_page=3');

        $response->assertStatus(200);
        $this->assertCount(3, $response->json('models'));
        $this->assertEquals($this->cantidad, $response->json('total'));
        $this->assertEquals(1, $response->json('current_page'));
        $this->assertEquals(3, $response->json('last_page'));
        $this->assertEquals(3, $response->json('per_page'));

        // La más nueva primero: la de saldo_apertura 7 es la de hoy.
        $this->assertEquals(7, (int) $response->json('models.0.saldo_apertura'));
    }

    /**
     * @test
     * @return void
     */
    public function la_ultima_pagina_trae_el_resto_sin_repetir_filas()
    {
        $primera = $this->getJson('api/apertura-caja/'.$this->caja->id.'?page=1&per_page=3')->json('models');
        $ultima  = $this->getJson('api/apertura-caja/'.$this->caja->id.'?page=3&per_page=3');

        $ultima->assertStatus(200);
        $this->assertCount(1, $ultima->json('models'));

        $ids_primera = array_column($primera, 'id');
        $this->assertNotContains($ultima->json('models.0.id'), $ids_primera);
    }

    /**
     * @test
     * @return void
     */
    public function per_page_se_acota_para_no_pedir_el_universo()
    {
        $response = $this->getJson('api/apertura-caja/'.$this->caja->id.'?page=1&per_page=100000');

        $response->assertStatus(200);
        $this->assertEquals(100, $response->json('per_page'));
    }
}
