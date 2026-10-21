<?php

namespace Tests\Feature\Busqueda;

use App\Models\CurrentAcount;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;

/**
 * Tests del modo "Historico" (buscador general paginado) de las pantallas de Comprobantes y de
 * Depósito (misión historico-paginado-comprobantes, 21/10/2026).
 *
 * El botón Historico de esas pantallas le pide al buscador general (`POST api/global-search/{modelo}`)
 * la primera página de TODO el listado, ordenado por id descendente. Con `nota_credito` la
 * respuesta era 400 "Modelo inexistente" (no existe ese modelo: la pantalla lista cuentas
 * corrientes con status `nota_credito`), y el usuario veía el cartel "Hubo un error y no
 * pudimos leer el detalle".
 */
class Global_Search_Historico_De_Comprobantes_Test extends BusquedaTestCase
{
    /** Cantidad de NC que se siembran: más que una página de a 5, para probar el paginado. */
    const CANTIDAD_DE_NC = 7;

    /**
     * Dueño con el que corre la suite (el del fixture de testing).
     *
     * @return int
     */
    private function user_id_de_testing()
    {
        return (int) User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first()->id;
    }

    /**
     * Siembra NC del dueño de testing, y un pago y una deuda que NO son NC.
     *
     * @return array<int> ids de las NC sembradas, en orden de creación
     */
    private function sembrar_notas_de_credito()
    {
        $user_id = $this->user_id_de_testing();
        $ids     = [];

        for ($i = 1; $i <= self::CANTIDAD_DE_NC; $i++) {
            $ids[] = CurrentAcount::create([
                'detalle'     => 'NC de prueba '.$i,
                'haber'       => 100 * $i,
                'status'      => 'nota_credito',
                'num_receipt' => 9000 + $i,
                'user_id'     => $user_id,
            ])->id;
        }

        CurrentAcount::create([
            'detalle' => 'Pago que no es NC',
            'haber'   => 5,
            'status'  => 'pago_from_client',
            'user_id' => $user_id,
        ]);

        CurrentAcount::create([
            'detalle' => 'Deuda que no es NC',
            'debe'    => 5,
            'status'  => 'sin_pagar',
            'user_id' => $user_id,
        ]);

        return $ids;
    }

    /**
     * Test 1 - `nota_credito` ya no da 400: devuelve solo notas de crédito y de este dueño.
     *
     * @group busqueda
     * @test
     */
    public function el_historico_de_notas_de_credito_responde_y_solo_trae_notas_de_credito()
    {
        $ids = $this->sembrar_notas_de_credito();

        $response = $this->postJson('api/global-search/nota_credito?page=1', $this->payload_global_search([
            'per_page'        => 50,
            'order_by'        => 'id',
            'order_direction' => 'DESC',
        ]));

        $response->assertStatus(200);

        $filas = $response->json('models.data');

        $this->assertNotEmpty($filas, 'el historico de notas de credito no puede venir vacio');

        foreach ($filas as $fila) {
            $this->assertSame('nota_credito', $fila['status'], 'una fila que no es nota de credito se colo en el historico');
            $this->assertSame($this->user_id_de_testing(), (int) $fila['user_id'], 'una nota de credito de otro dueno se colo en el historico');
        }

        $ids_devueltos = array_column($filas, 'id');

        foreach ($ids as $id) {
            $this->assertContains($id, $ids_devueltos, 'falta una nota de credito sembrada en el historico');
        }

        // Las relaciones que dibuja la tabla viajan embebidas, igual que en el listado "Por fecha".
        $primera = $filas[0];
        foreach (['afip_ticket', 'sale', 'articles', 'client', 'provider', 'nota_credito_descriptions'] as $relacion) {
            $this->assertArrayHasKey($relacion, $primera, 'la fila del historico no trae la relacion '.$relacion);
        }
    }

    /**
     * Test 2 - el historico de notas de crédito se pagina: la página 2 trae lo que sigue y el total
     * es el de todas las notas, no el de la página.
     *
     * @group busqueda
     * @test
     */
    public function el_historico_de_notas_de_credito_se_pagina()
    {
        $this->sembrar_notas_de_credito();

        $total = CurrentAcount::where('user_id', $this->user_id_de_testing())->where('status', 'nota_credito')->count();

        $pagina_1 = $this->postJson('api/global-search/nota_credito?page=1', $this->payload_global_search([
            'per_page' => 5, 'order_by' => 'id', 'order_direction' => 'DESC',
        ]));
        $pagina_2 = $this->postJson('api/global-search/nota_credito?page=2', $this->payload_global_search([
            'per_page' => 5, 'order_by' => 'id', 'order_direction' => 'DESC',
        ]));

        $pagina_1->assertStatus(200);
        $pagina_2->assertStatus(200);

        $this->assertCount(5, $pagina_1->json('models.data'), 'la pagina 1 tiene que traer 5 filas, no todo');
        $this->assertSame($total, $pagina_1->json('models.total'), 'el total tiene que ser el de todas las notas de credito');
        $this->assertSame((int) ceil($total / 5), $pagina_1->json('models.last_page'));

        $ids_1 = array_column($pagina_1->json('models.data'), 'id');
        $ids_2 = array_column($pagina_2->json('models.data'), 'id');

        $this->assertEmpty(array_intersect($ids_1, $ids_2), 'una misma nota aparecio en dos paginas');
        $this->assertGreaterThan(max($ids_2), min($ids_1), 'el orden por id descendente se rompio entre paginas');
    }

    /**
     * Test 3 - el buscador de texto de la pantalla sigue funcionando sobre las notas de crédito (el
     * alias no puede dejar el endpoint a medias) y un pago no se cuela como nota.
     *
     * @group busqueda
     * @test
     */
    public function el_historico_de_notas_de_credito_se_puede_buscar()
    {
        $this->sembrar_notas_de_credito();

        $por_texto = $this->postJson('api/global-search/nota_credito?page=1', $this->payload_global_search([
            'query_value' => 'NC de prueba 3',
            'props'       => ['detalle'],
            'per_page'    => 50,
        ]));

        $por_texto->assertStatus(200);
        $this->assertSame(1, $por_texto->json('models.total'), 'la busqueda de texto tiene que traer solo la NC 3');
        $this->assertSame('NC de prueba 3', $por_texto->json('models.data.0.detalle'));

        // Una búsqueda que solo coincide con un pago (que no es NC) no puede devolver nada.
        $solo_pago = $this->postJson('api/global-search/nota_credito?page=1', $this->payload_global_search([
            'query_value' => 'Pago que no es NC',
            'props'       => ['detalle'],
            'per_page'    => 50,
        ]));

        $solo_pago->assertStatus(200);
        $this->assertSame(0, $solo_pago->json('models.total'), 'un pago no es una nota de credito');
    }

    /**
     * Test 4 - el "Por fecha" de notas de crédito sigue devolviendo lo de siempre, con las mismas
     * relaciones (la lista de relaciones pasó a un scope compartido con el Historico).
     *
     * @group busqueda
     * @test
     */
    public function el_por_fecha_de_notas_de_credito_sigue_igual()
    {
        $this->sembrar_notas_de_credito();

        $response = $this->getJson('api/nota-credito/from-date/'.date('Y-m-d'));

        $response->assertStatus(200);

        $filas = $response->json('models');

        $this->assertGreaterThanOrEqual(self::CANTIDAD_DE_NC, count($filas));

        foreach ($filas as $fila) {
            $this->assertSame('nota_credito', $fila['status']);
        }

        foreach (['afip_ticket', 'sale', 'articles', 'client', 'provider', 'nota_credito_descriptions'] as $relacion) {
            $this->assertArrayHasKey($relacion, $filas[0]);
        }
    }

    /**
     * Test 5 - regresión de las otras dos pantallas con modo Historico cuyo store estaba escrito a
     * mano (movimientos de depósito y de producción): el endpoint responde paginado.
     *
     * @group busqueda
     * @test
     */
    public function el_historico_de_movimientos_de_deposito_y_de_produccion_responde_paginado()
    {
        foreach (['deposit_movement', 'production_movement'] as $modelo) {
            $response = $this->postJson('api/global-search/'.$modelo.'?page=1', $this->payload_global_search([
                'per_page' => 5, 'order_by' => 'id', 'order_direction' => 'DESC',
            ]));

            $response->assertStatus(200);
            $this->assertArrayHasKey('data', $response->json('models'), $modelo.': la respuesta tiene que venir paginada');
            $this->assertArrayHasKey('last_page', $response->json('models'), $modelo.': falta last_page');
            $this->assertArrayHasKey('total', $response->json('models'), $modelo.': falta total');
        }
    }
}
