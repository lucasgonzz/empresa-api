<?php

namespace Tests\Feature\Sales;

use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Feature tests de la mision 57 (tarea 04): previus-next/sale/{index} deja de hidratar todas
 * las ventas que tiene por delante y pasa a traer una sola con skip()/take(1).
 *
 * Es un cambio de rendimiento, no de comportamiento: lo que estos tests protegen es que para
 * un indice dado se siga devolviendo EXACTAMENTE la misma venta que devolvia la version
 * anterior, incluido el caso de un indice mayor al total (donde la consulta vieja devolvia
 * todas y se quedaba con la mas vieja).
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing de esta rama esta sembrada de
 * antes y un refresh la vaciaria, rompiendo el resto de las suites.
 */
class Previus_next_venta_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return \App\Models\User|null
     */
    protected function usuario_de_testing()
    {
        return User::find(500);
    }

    /**
     * Crea una venta minima con user_id 500.
     *
     * @param array $overrides
     * @return \App\Models\Sale
     */
    protected function crear_venta($overrides = [])
    {
        return Sale::create(array_merge([
            'user_id'                       => 500,
            'client_id'                     => null,
            'omitir_en_cuenta_corriente'    => 0,
            'save_current_acount'           => 0,
            'terminada'                     => 1,
            'is_cerrada'                    => 0,
            'caja_id'                       => null,
            'sub_total'                     => 100,
            'total'                         => 100,
        ], $overrides));
    }

    /**
     * Reproduce la consulta que hacia previusNext() antes de la mision 57: traer las primeras
     * $index ventas ordenadas por id DESC y quedarse con la ultima. Es la referencia contra la
     * que se compara la implementacion nueva.
     *
     * @param int $index
     * @return \App\Models\Sale|null
     */
    protected function venta_esperada_con_la_consulta_vieja($index)
    {
        // select('id') a proposito: es la unica columna que se compara y evita que el test
        // reproduzca justamente la carga que la tarea 04 vino a sacar (traer N ventas enteras
        // para quedarse con una).
        $models = Sale::where('user_id', 500)
                    ->select('id')
                    ->orderBy('id', 'DESC')
                    ->take($index)
                    ->get();

        if (count($models) >= 1) {
            return $models[count($models) - 1];
        }

        return null;
    }

    /**
     * @group ventas-previus-next
     * @group sales
     * @test
     */
    public function el_indice_1_devuelve_la_venta_mas_nueva()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $venta_mas_nueva = $this->crear_venta();

        $response = $this->get('api/previus-next/sale/1');

        $response->assertStatus(200);
        $response->assertJsonPath('model.id', $venta_mas_nueva->id);
    }

    /**
     * @group ventas-previus-next
     * @group sales
     * @test
     */
    public function el_indice_recorre_las_ventas_hacia_atras_en_orden()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        // Tres ventas nuevas: quedan como los indices 1, 2 y 3 (id DESC).
        $vieja = $this->crear_venta();
        $del_medio = $this->crear_venta();
        $nueva = $this->crear_venta();

        $this->get('api/previus-next/sale/1')->assertJsonPath('model.id', $nueva->id);
        $this->get('api/previus-next/sale/2')->assertJsonPath('model.id', $del_medio->id);
        $this->get('api/previus-next/sale/3')->assertJsonPath('model.id', $vieja->id);
    }

    /**
     * El caso que justifica el min() del controlador: con un indice mayor al total, la consulta
     * vieja devolvia todas las ventas y se quedaba con la mas vieja. Esa semantica se conserva.
     *
     * @group ventas-previus-next
     * @group sales
     * @test
     */
    public function un_indice_mayor_al_total_devuelve_la_venta_mas_vieja()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $this->crear_venta();

        $total = Sale::where('user_id', 500)->count();

        $mas_vieja = Sale::where('user_id', 500)
                        ->orderBy('id', 'ASC')
                        ->first();

        $response = $this->get('api/previus-next/sale/'.($total + 50));

        $response->assertStatus(200);
        $response->assertJsonPath('model.id', $mas_vieja->id);
    }

    /**
     * @group ventas-previus-next
     * @group sales
     * @test
     */
    public function el_indice_0_devuelve_model_en_null()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $this->crear_venta();

        $response = $this->get('api/previus-next/sale/0');

        $response->assertStatus(200);
        // assertExactJson y no assertJsonPath: este ultimo tambien pasaria si la clave 'model'
        // directamente no viniera en la respuesta.
        $response->assertExactJson(['model' => null]);
    }

    /**
     * El endpoint tiene que seguir devolviendo la venta con todas sus relaciones cargadas
     * (scopeWithAll). Sin esta asercion, sacar el withAll() del controlador dejaria al front
     * sin cliente, sin articulos y sin descuentos, y los otros tests seguirian en verde
     * porque solo miran el id.
     *
     * @group ventas-previus-next
     * @group sales
     * @test
     */
    public function la_venta_viene_con_las_relaciones_cargadas()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $this->crear_venta();

        $response = $this->get('api/previus-next/sale/1');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'model' => [
                'id',
                'articles',
                'combos',
                'services',
                'discounts',
                'surchages',
                'current_acount_payment_methods',
                'afip_tickets',
                'sale_modifications_count',
            ],
        ]);
    }

    /**
     * Equivalencia directa con la implementacion anterior para varios indices seguidos,
     * incluido uno que se pasa del total. Es la asercion central de la tarea 04: el
     * controlador cambia como consulta, no que devuelve.
     *
     * @group ventas-previus-next
     * @group sales
     * @test
     */
    public function devuelve_la_misma_venta_que_la_consulta_vieja()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $this->crear_venta();
        $this->crear_venta();
        $this->crear_venta();

        $total = Sale::where('user_id', 500)->count();

        $indices = [1, 2, 3, $total, $total + 10];

        foreach ($indices as $index) {
            $esperada = $this->venta_esperada_con_la_consulta_vieja($index);

            $response = $this->get('api/previus-next/sale/'.$index);

            $response->assertStatus(200);
            $response->assertJsonPath('model.id', $esperada->id);
        }
    }
}
