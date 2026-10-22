<?php

namespace Tests\Feature\Busqueda;

use App\Models\CurrentAcount;
use App\Models\DepositMovement;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Tests del modo "Por fecha" paginado OPT-IN de Movimientos de depósito y Notas de crédito
 * (misión paginado-por-fecha-movimientos, 22/10/2026).
 *
 * `GET <modelo>/from-date/{desde}/{hasta?}` hacía `->get()` y devolvía el día o el rango entero.
 * Con `per_page` en la query string devuelve una página (`models` pasa a ser un paginador de
 * Laravel); sin `per_page` la respuesta es EXACTAMENTE la de siempre. Ver
 * `ListadoPorFechaPaginadoHelper`.
 */
class Por_Fecha_Paginado_Test extends BusquedaTestCase
{
    /** Día de las filas sembradas. */
    const DIA = '2026-03-10';

    /** Un día después, para probar que el rango recorta. */
    const DIA_SIGUIENTE = '2026-03-11';

    /** Un día fuera del rango de la prueba. */
    const DIA_AJENO = '2026-03-20';

    /** Filas sembradas en DIA: más que dos páginas de a 5. */
    const FILAS_DEL_DIA = 12;

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
     * Fija `created_at` por SQL (el modelo no lo deja pisar en el create) y devuelve el id.
     *
     * @param  string $tabla
     * @param  int    $id
     * @param  string $fecha Y-m-d
     * @return int
     */
    private function fechar($tabla, $id, $fecha)
    {
        DB::table($tabla)->where('id', $id)->update(['created_at' => $fecha.' 10:00:00']);

        return $id;
    }

    /**
     * Siembra movimientos de depósito: FILAS_DEL_DIA en DIA, 2 en DIA_SIGUIENTE, 3 en DIA_AJENO y 1 de OTRO dueño en DIA.
     *
     * @return array{dia: int[], siguiente: int[], ajeno: int[], otro_dueno: int}
     */
    private function sembrar_movimientos_de_deposito()
    {
        $user_id = $this->user_id_de_testing();
        $ids     = ['dia' => [], 'siguiente' => [], 'ajeno' => []];

        $plan = ['dia' => [self::DIA, self::FILAS_DEL_DIA], 'siguiente' => [self::DIA_SIGUIENTE, 2], 'ajeno' => [self::DIA_AJENO, 3]];

        foreach ($plan as $clave => $datos) {
            for ($i = 1; $i <= $datos[1]; $i++) {
                $modelo = DepositMovement::create(['num' => 8000 + count($ids['dia']) + count($ids['siguiente']) + count($ids['ajeno']), 'from_address_id' => 1, 'to_address_id' => 2, 'deposit_movement_status_id' => 1, 'notes' => 'PF '.$clave.' '.$i, 'user_id' => $user_id]);
                $ids[$clave][] = $this->fechar('deposit_movements', $modelo->id, $datos[0]);
            }
        }

        $otro = DepositMovement::create(['num' => 8999, 'from_address_id' => 1, 'to_address_id' => 2, 'deposit_movement_status_id' => 1, 'notes' => 'PF otro dueno', 'user_id' => $user_id + 100000]);
        $ids['otro_dueno'] = $this->fechar('deposit_movements', $otro->id, self::DIA);

        return $ids;
    }

    /**
     * Siembra NC: FILAS_DEL_DIA en DIA y 3 en DIA_AJENO; más un pago que no es NC en DIA.
     *
     * @return array{dia: int[], ajeno: int[]}
     */
    private function sembrar_notas_de_credito()
    {
        $user_id = $this->user_id_de_testing();
        $ids     = ['dia' => [], 'ajeno' => []];

        foreach (['dia' => [self::DIA, self::FILAS_DEL_DIA], 'ajeno' => [self::DIA_AJENO, 3]] as $clave => $datos) {
            for ($i = 1; $i <= $datos[1]; $i++) {
                $modelo = CurrentAcount::create(['detalle' => 'PF NC '.$clave.' '.$i, 'haber' => 10 * $i, 'status' => 'nota_credito', 'user_id' => $user_id]);
                $ids[$clave][] = $this->fechar('current_acounts', $modelo->id, $datos[0]);
            }
        }

        $pago = CurrentAcount::create(['detalle' => 'PF pago', 'haber' => 1, 'status' => 'pago_from_client', 'user_id' => $user_id]);
        $this->fechar('current_acounts', $pago->id, self::DIA);

        return $ids;
    }

    /**
     * Test 1 - depósito sin `per_page`: respuesta de siempre (array entero, sin paginador).
     *
     * @group busqueda
     * @test
     */
    public function deposito_sin_per_page_devuelve_el_dia_entero_como_siempre()
    {
        $ids = $this->sembrar_movimientos_de_deposito();

        $response = $this->getJson('api/deposit-movement/from-date/'.self::DIA);
        $response->assertStatus(200);

        $filas = $response->json('models');

        $this->assertCount(self::FILAS_DEL_DIA, $filas, 'sin per_page tienen que venir todas las filas del dia');
        $this->assertArrayNotHasKey('data', $filas, 'sin per_page models tiene que seguir siendo un array y no un paginador');
        $this->assertNull($response->json('models.current_page'));
        $this->assertEqualsCanonicalizing($ids['dia'], array_column($filas, 'id'));
    }

    /**
     * Test 2 - depósito con `per_page`: pagina el día, el total es el del día y la página 2 sigue sin repetir.
     *
     * @group busqueda
     * @test
     */
    public function deposito_con_per_page_pagina_el_dia_sin_repetir_filas()
    {
        $ids = $this->sembrar_movimientos_de_deposito();

        $vistos = [];

        foreach ([1 => 5, 2 => 5, 3 => 2] as $pagina => $esperadas) {
            $response = $this->getJson('api/deposit-movement/from-date/'.self::DIA.'?per_page=5&page='.$pagina);
            $response->assertStatus(200);

            $this->assertSame(self::FILAS_DEL_DIA, $response->json('models.total'), 'el total es el del dia, no el de la pagina');
            $this->assertSame(3, $response->json('models.last_page'));
            $this->assertSame($pagina, $response->json('models.current_page'));
            $this->assertCount($esperadas, $response->json('models.data'));

            $vistos = array_merge($vistos, array_column($response->json('models.data'), 'id'));
        }

        $this->assertCount(self::FILAS_DEL_DIA, array_unique($vistos), 'una fila se repitio entre paginas');
        $this->assertEqualsCanonicalizing($ids['dia'], $vistos, 'las paginas tienen que cubrir el dia entero y nada de otro dia ni de otro dueno');
    }

    /**
     * Test 3 - depósito: el rango recorta, `per_page` se sanea y las relaciones siguen embebidas.
     *
     * @group busqueda
     * @test
     */
    public function deposito_respeta_el_rango_sanea_per_page_y_trae_las_relaciones()
    {
        $ids = $this->sembrar_movimientos_de_deposito();

        $rango = $this->getJson('api/deposit-movement/from-date/'.self::DIA.'/'.self::DIA_SIGUIENTE.'?per_page=200');
        $rango->assertStatus(200);
        $this->assertSame(self::FILAS_DEL_DIA + 2, $rango->json('models.total'), 'el rango tiene que sumar los dos dias y ninguno de afuera');
        $this->assertEqualsCanonicalizing(array_merge($ids['dia'], $ids['siguiente']), array_column($rango->json('models.data'), 'id'));

        // per_page=0 cae al default (50), y uno disparatado se acota al techo (200).
        $this->assertSame(50, $this->getJson('api/deposit-movement/from-date/'.self::DIA.'?per_page=0')->json('models.per_page'));
        $this->assertSame(200, $this->getJson('api/deposit-movement/from-date/'.self::DIA.'?per_page=99999')->json('models.per_page'));

        // La fila paginada tiene que ser IGUAL a la del camino de siempre (mismas claves, mismas relaciones embebidas).
        // (se compara la MISMA fila por id: sin `per_page` el orden de filas con el mismo segundo no esta garantizado).
        $fila = $this->getJson('api/deposit-movement/from-date/'.self::DIA.'?per_page=50')->json('models.data.0');
        $sin_paginar = null;
        foreach ($this->getJson('api/deposit-movement/from-date/'.self::DIA)->json('models') as $candidata) {
            if ($candidata['id'] == $fila['id']) {
                $sin_paginar = $candidata;
            }
        }
        $this->assertSame($sin_paginar, $fila, 'la fila paginada tiene que ser identica a la no paginada');
        $this->assertArrayHasKey('articles', $fila);

        // Dia sin movimientos: pagina vacia con total 0, no un error.
        $vacio = $this->getJson('api/deposit-movement/from-date/2026-01-01?per_page=50');
        $vacio->assertStatus(200);
        $this->assertSame(0, $vacio->json('models.total'));
        $this->assertSame([], $vacio->json('models.data'));
    }

    /**
     * Test 4 - notas de crédito sin `per_page`: respuesta de siempre, solo NC del día.
     *
     * @group busqueda
     * @test
     */
    public function notas_de_credito_sin_per_page_devuelven_el_dia_entero_como_siempre()
    {
        $ids = $this->sembrar_notas_de_credito();

        $response = $this->getJson('api/nota-credito/from-date/'.self::DIA);
        $response->assertStatus(200);

        $filas = $response->json('models');

        $this->assertCount(self::FILAS_DEL_DIA, $filas, 'sin per_page tienen que venir todas las NC del dia y ningun pago');
        $this->assertNull($response->json('models.current_page'));
        $this->assertEqualsCanonicalizing($ids['dia'], array_column($filas, 'id'));
    }

    /**
     * Test 5 - notas de crédito con `per_page`: pagina, respeta el día, no se cuela el pago y trae las relaciones.
     *
     * @group busqueda
     * @test
     */
    public function notas_de_credito_con_per_page_paginan_el_dia_sin_repetir_filas()
    {
        $ids = $this->sembrar_notas_de_credito();

        $vistos = [];

        foreach ([1 => 5, 2 => 5, 3 => 2] as $pagina => $esperadas) {
            $response = $this->getJson('api/nota-credito/from-date/'.self::DIA.'?per_page=5&page='.$pagina);
            $response->assertStatus(200);

            $this->assertSame(self::FILAS_DEL_DIA, $response->json('models.total'));
            $this->assertSame(3, $response->json('models.last_page'));
            $this->assertCount($esperadas, $response->json('models.data'));

            foreach ($response->json('models.data') as $fila) {
                $this->assertSame('nota_credito', $fila['status'], 'un pago se colo en el listado de notas de credito');
                foreach (['afip_ticket', 'sale', 'articles', 'client', 'provider', 'nota_credito_descriptions'] as $relacion) {
                    $this->assertArrayHasKey($relacion, $fila, 'la fila paginada no trae la relacion '.$relacion);
                }
            }

            $vistos = array_merge($vistos, array_column($response->json('models.data'), 'id'));
        }

        $this->assertCount(self::FILAS_DEL_DIA, array_unique($vistos));
        $this->assertEqualsCanonicalizing($ids['dia'], $vistos);
    }

    /**
     * Test 6 - el orden es el de siempre (más nuevo primero) y filas con el mismo segundo no se
     * pierden al pasar de página: el desempate por id las ordena de forma total.
     *
     * @group busqueda
     * @test
     */
    public function el_orden_es_estable_con_filas_del_mismo_segundo()
    {
        $this->sembrar_notas_de_credito();

        $pagina_1 = $this->getJson('api/nota-credito/from-date/'.self::DIA.'?per_page=5&page=1')->json('models.data');
        $pagina_2 = $this->getJson('api/nota-credito/from-date/'.self::DIA.'?per_page=5&page=2')->json('models.data');

        $ids = array_merge(array_column($pagina_1, 'id'), array_column($pagina_2, 'id'));

        // Todas comparten created_at (mismo segundo): el orden tiene que ser id descendente.
        $ordenados = $ids;
        rsort($ordenados);
        $this->assertSame($ordenados, $ids, 'con el mismo created_at el orden tiene que ser por id descendente, sin saltos entre paginas');
    }
}
