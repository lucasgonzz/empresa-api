<?php

namespace Tests\Feature\ProduccionV2;

use App\Models\Address;

/**
 * El potencial de armado: cuantas unidades se pueden armar con las partes que ya estan
 * terminadas en stock, cual es el insumo que lo limita y cuantas quedan vendibles.
 *
 * El segundo metodo es el que atrapa la trampa que hace que este numero salga INFLADO: un mismo
 * articulo puede ser insumo de la misma ruta mas de una vez, en estados distintos, y si se toma
 * un solo renglon en vez de sumarlos, el potencial promete mas unidades de las que alcanzan. Es
 * un numero con el que se decide una venta.
 */
class Potencial_de_armado_Test extends ProduccionV2TestCase
{
    /**
     * Devuelve la fila del payload que corresponde a un articulo, por nombre.
     *
     * @param  array   $models
     * @param  string  $article_name
     * @return array|null
     */
    private function fila_de($models, $article_name)
    {
        foreach ($models as $fila) {
            if ($fila['article_name'] === $article_name) {
                return $fila;
            }
        }

        return null;
    }

    /**
     * El minimo de floor(stock / cantidad) sobre los insumos, con el limitante visible.
     *
     * @group produccion_v2
     * @test
     */
    public function el_potencial_es_el_minimo_y_muestra_el_insumo_limitante()
    {
        $corte = $this->crear_estado('Corte potencial', 1);

        $silla = $this->crear_articulo('Silla potencial test', 3);

        $estructura = $this->crear_articulo('Estructura potencial test', 10);
        $madera     = $this->crear_articulo('Madera potencial test', 40);
        $remache    = $this->crear_articulo('Remache potencial test', 400);

        $receta = $this->crear_receta($silla);

        $this->crear_ruta($receta, [
            ['article' => $estructura, 'amount' => 1, 'order_production_status_id' => $corte->id],
            ['article' => $madera,     'amount' => 2, 'order_production_status_id' => $corte->id],
            ['article' => $remache,    'amount' => 8, 'order_production_status_id' => $corte->id],
        ]);

        $respuesta = $this->get('api/potencial-de-armado');

        $respuesta->assertStatus(200);

        $fila = $this->fila_de($respuesta->json('models'), 'Silla potencial test');

        $this->assertNotNull($fila);

        /* floor(10/1) = 10, floor(40/2) = 20, floor(400/8) = 50 -> gana el 10. */
        $this->assertEquals(10, $fila['potencial']);

        /* 3 que ya hay en stock + 10 que se pueden armar. */
        $this->assertEquals(13, $fila['vendibles']);
        $this->assertEquals(3, $fila['stock_actual']);

        $this->assertEquals('Estructura potencial test', $fila['insumo_limitante']['article_name']);
        $this->assertEquals(10, $fila['insumo_limitante']['posible']);

        $this->assertFalse($fila['sin_ruta']);
        $this->assertFalse($fila['sin_insumos']);
        $this->assertEquals(0, $fila['renglones_ignorados']);

        /* Sin from_address_id en la ruta, el stock de los insumos es el global. */
        $this->assertNull($fila['address_id']);

        $this->assertCount(3, $fila['insumos']);
    }

    /**
     * 🔴 EL INSUMO REPETIDO. Las cantidades del mismo articulo en la misma ruta se SUMAN.
     *
     * Si no se sumaran, el remache diria cantidad 8 (o 4) en vez de 12, y el potencial que ese
     * insumo permite saldria mas alto de lo que es. Con estos numeros el limitante sigue siendo
     * la estructura, asi que el error no se veria en el total: por eso el test mira el renglon
     * del remache, que es donde la trampa se hace visible.
     *
     * @group produccion_v2
     * @test
     */
    public function las_cantidades_de_un_insumo_repetido_en_la_ruta_se_suman()
    {
        $soldado    = $this->crear_estado('Soldado repetido', 1);
        $pre_armado = $this->crear_estado('Pre armado repetido', 2);

        $silla = $this->crear_articulo('Silla repetido test', 0);

        $estructura = $this->crear_articulo('Estructura repetido test', 10);
        $remache    = $this->crear_articulo('Remache repetido test', 400);

        $receta = $this->crear_receta($silla);

        /* El remache aparece DOS VECES: 8 en un estado y 4 en el otro. Total por unidad: 12. */
        $this->crear_ruta($receta, [
            ['article' => $estructura, 'amount' => 1, 'order_production_status_id' => $soldado->id],
            ['article' => $remache,    'amount' => 8, 'order_production_status_id' => $soldado->id],
            ['article' => $remache,    'amount' => 4, 'order_production_status_id' => $pre_armado->id],
        ]);

        $respuesta = $this->get('api/potencial-de-armado');

        $respuesta->assertStatus(200);

        $fila = $this->fila_de($respuesta->json('models'), 'Silla repetido test');

        $this->assertNotNull($fila);

        /* El remache viaja como UN renglon con la cantidad sumada, no como dos. */
        $this->assertCount(2, $fila['insumos']);

        $fila_remache = null;

        foreach ($fila['insumos'] as $insumo) {
            if ($insumo['article_name'] === 'Remache repetido test') {
                $fila_remache = $insumo;
            }
        }

        $this->assertNotNull($fila_remache);

        /* 8 + 4, no 8 y no 4. */
        $this->assertEquals(12, $fila_remache['cantidad_por_unidad']);

        /* floor(400/12) = 33 */
        $this->assertEquals(33, $fila_remache['posible']);

        /* Y el limitante sigue siendo la estructura, con 10. */
        $this->assertEquals(10, $fila['potencial']);
        $this->assertEquals('Estructura repetido test', $fila['insumo_limitante']['article_name']);
    }

    /**
     * Si la ruta declara de que deposito salen los insumos, el potencial usa el stock DE ESE
     * DEPOSITO y no el total.
     *
     * Es la misma cascada que usa el consumo real: sin esto el numero prometeria unidades que se
     * podrian armar con insumos que estan en otra sucursal.
     *
     * @group produccion_v2
     * @test
     */
    public function el_potencial_usa_el_stock_del_deposito_de_la_ruta()
    {
        $corte = $this->crear_estado('Corte deposito', 1);

        /* `addresses` no tiene columna `name`: el nombre del deposito vive en `street`. */
        $deposito = Address::create([
            'street'    => 'Deposito potencial test',
            'user_id'   => $this->comercio()->id,
        ]);

        $silla = $this->crear_articulo('Silla deposito test', 0);

        /*
         * El insumo tiene 500 en total pero solo 6 en el deposito del que la ruta saca. El
         * potencial tiene que decir 3, no 250.
         */
        $tabla = $this->crear_articulo('Tabla deposito test', 500);
        $tabla->addresses()->attach($deposito->id, ['amount' => 6]);

        $receta = $this->crear_receta($silla);

        $this->crear_ruta($receta, [
            ['article' => $tabla, 'amount' => 2, 'order_production_status_id' => $corte->id],
        ], [
            'from_address_id' => $deposito->id,
        ]);

        $respuesta = $this->get('api/potencial-de-armado');

        $respuesta->assertStatus(200);

        $fila = $this->fila_de($respuesta->json('models'), 'Silla deposito test');

        $this->assertNotNull($fila);

        $this->assertEquals($deposito->id, $fila['address_id']);

        /* floor(6/2) = 3, no floor(500/2) = 250. */
        $this->assertEquals(3, $fila['potencial']);
        $this->assertEquals(6, $fila['insumo_limitante']['stock']);

        /* Y el deposito con el que se midio viaja tambien en el renglon del insumo. */
        $this->assertEquals($deposito->id, $fila['insumo_limitante']['address_id']);
        $this->assertEquals($deposito->id, $fila['insumos'][0]['address_id']);
    }

    /**
     * 🔴 EL DEPOSITO SALE DEL RENGLON DEL INSUMO CUANDO LA RUTA NO LO DECLARA.
     *
     * Es el nivel del medio de la cascada del consumo real, el "Deposito" que la interfaz expone
     * en cada insumo de la ruta. Si el potencial se saltea ese nivel y cae al stock global,
     * SOBREESTIMA: la pantalla promete unidades que el consumo real no puede armar porque el
     * stock esta en otra sucursal.
     *
     * @group produccion_v2
     * @test
     */
    public function el_potencial_usa_el_deposito_del_renglon_del_insumo_si_la_ruta_no_lo_declara()
    {
        $corte = $this->crear_estado('Corte deposito renglon', 1);

        $central = Address::create([
            'street'    => 'Central renglon test',
            'user_id'   => $this->comercio()->id,
        ]);

        $norte = Address::create([
            'street'    => 'Norte renglon test',
            'user_id'   => $this->comercio()->id,
        ]);

        $silla = $this->crear_articulo('Silla deposito renglon test', 0);

        /* 500 en total: 494 en Norte y 6 en Central, que es el deposito del renglon. */
        $tabla = $this->crear_articulo('Tabla deposito renglon test', 500);
        $tabla->addresses()->attach($central->id, ['amount' => 6]);
        $tabla->addresses()->attach($norte->id, ['amount' => 494]);

        $receta = $this->crear_receta($silla);

        /* La ruta NO declara "Deposito insumos": el deposito sale del renglon. */
        $this->crear_ruta($receta, [
            [
                'article'                       => $tabla,
                'amount'                        => 2,
                'order_production_status_id'    => $corte->id,
                'address_id'                    => $central->id,
            ],
        ]);

        $respuesta = $this->get('api/potencial-de-armado');

        $respuesta->assertStatus(200);

        $fila = $this->fila_de($respuesta->json('models'), 'Silla deposito renglon test');

        $this->assertNotNull($fila);

        /* El de nivel producto es el de la RUTA, y la ruta no tiene: null. */
        $this->assertNull($fila['address_id']);

        /* Pero el del insumo es Central, que es donde lo mando el renglon. */
        $this->assertEquals($central->id, $fila['insumos'][0]['address_id']);
        $this->assertEquals($central->id, $fila['insumo_limitante']['address_id']);

        /* floor(6/2) = 3, no floor(500/2) = 250. */
        $this->assertEquals(6, $fila['insumos'][0]['stock']);
        $this->assertEquals(3, $fila['potencial']);
    }

    /**
     * El "Deposito insumos" de la ruta le gana al del renglon: es el orden de la cascada del
     * consumo real, donde el nivel de la ruta esta arriba del nivel del renglon.
     *
     * @group produccion_v2
     * @test
     */
    public function el_deposito_de_la_ruta_le_gana_al_del_renglon()
    {
        $corte = $this->crear_estado('Corte precedencia', 1);

        $de_la_ruta = Address::create([
            'street'    => 'De la ruta precedencia test',
            'user_id'   => $this->comercio()->id,
        ]);

        $del_renglon = Address::create([
            'street'    => 'Del renglon precedencia test',
            'user_id'   => $this->comercio()->id,
        ]);

        $silla = $this->crear_articulo('Silla precedencia test', 0);

        $tabla = $this->crear_articulo('Tabla precedencia test', 100);
        $tabla->addresses()->attach($de_la_ruta->id, ['amount' => 20]);
        $tabla->addresses()->attach($del_renglon->id, ['amount' => 80]);

        $receta = $this->crear_receta($silla);

        $this->crear_ruta($receta, [
            [
                'article'                       => $tabla,
                'amount'                        => 2,
                'order_production_status_id'    => $corte->id,
                'address_id'                    => $del_renglon->id,
            ],
        ], [
            'from_address_id' => $de_la_ruta->id,
        ]);

        $respuesta = $this->get('api/potencial-de-armado');

        $respuesta->assertStatus(200);

        $fila = $this->fila_de($respuesta->json('models'), 'Silla precedencia test');

        $this->assertNotNull($fila);

        $this->assertEquals($de_la_ruta->id, $fila['insumos'][0]['address_id']);

        /* floor(20/2) = 10 (el de la ruta), no floor(80/2) = 40 (el del renglon). */
        $this->assertEquals(20, $fila['insumos'][0]['stock']);
        $this->assertEquals(10, $fila['potencial']);
    }

    /**
     * Una receta sin ruta cargada no rompe el listado: viaja con potencial 0 y la marca
     * `sin_ruta`, para que la pantalla pueda decir que falta configurarla.
     *
     * @group produccion_v2
     * @test
     */
    public function una_receta_sin_ruta_viaja_marcada_y_no_rompe()
    {
        $silla = $this->crear_articulo('Silla sin ruta test', 7);

        $this->crear_receta($silla);

        $respuesta = $this->get('api/potencial-de-armado');

        $respuesta->assertStatus(200);

        $fila = $this->fila_de($respuesta->json('models'), 'Silla sin ruta test');

        $this->assertNotNull($fila);

        $this->assertTrue($fila['sin_ruta']);
        $this->assertEquals(0, $fila['potencial']);
        $this->assertEquals(7, $fila['vendibles']);
        $this->assertNull($fila['insumo_limitante']);
        $this->assertNull($fila['recipe_route_id']);
    }
}
