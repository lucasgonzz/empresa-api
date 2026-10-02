<?php

namespace Tests\Feature\Sucursales;

/**
 * Archivo 1 — el ALTA de una sucursal guarda el ajuste de precios (recargo o descuento) y la
 * lectura lo devuelve.
 *
 * Lo que fija:
 *
 *  - `POST api/address` con `recargo` 10 guarda las dos columnas, y la respuesta del alta y
 *    `GET api/address` las devuelven tal cual las entrega MySQL: el porcentaje como STRING decimal
 *    (`"10.00"`). La SPA lo convierte con `numero_o_null`; si algun dia llegara como numero, el
 *    contrato cambio y este test avisa.
 *  - un `descuento` con coma decimal (`"5,5"`, como lo tipea un comerciante) se guarda como 5.50.
 *  - el alta SIN las claves del ajuste (la SPA vieja) crea la sucursal como siempre, con las dos
 *    columnas en NULL: ninguna sucursal nace con ajuste "por defecto".
 *  - `tipo` vacio y `porcentaje` vacio en el alta tambien dejan las dos en NULL.
 *
 * @group sucursales
 */
class Ajuste_de_precios_alta_Test extends SucursalesTestCase
{
    /**
     * Test 1 — alta con recargo del 10 %: se guarda y `GET address` lo devuelve como "10.00".
     *
     * @test
     */
    public function el_alta_con_recargo_guarda_el_ajuste_y_la_lectura_lo_devuelve()
    {
        $response = $this->postJson('api/address', $this->payload_sucursal([
            'ajuste_precio_tipo'       => 'recargo',
            'ajuste_precio_porcentaje' => 10,
        ]));

        $response->assertStatus(201);

        $id = (int) $response->json('model.id');

        $this->assert_ajuste($id, 'recargo', 10, 'alta con recargo');

        /* Lo que devuelve el propio alta (la SPA arma el store con esto). */
        $this->assertSame('recargo', $response->json('model.ajuste_precio_tipo'));
        $this->assertSame('10.00', $response->json('model.ajuste_precio_porcentaje'));

        /* Y la lectura del listado, que es de donde Vender saca las sucursales. */
        $sucursal = collect($this->getJson('api/address')->assertStatus(200)->json('models'))->firstWhere('id', $id);

        $this->assertNotNull($sucursal, 'GET api/address no devolvio la sucursal recien creada.');
        $this->assertSame('recargo', $sucursal['ajuste_precio_tipo']);
        $this->assertSame('10.00', $sucursal['ajuste_precio_porcentaje'], 'El porcentaje tiene que viajar como string decimal: la SPA lo convierte.');

        /* Y la lectura individual. */
        $this->assertSame('10.00', $this->getJson('api/address/'.$id)->assertStatus(200)->json('model.ajuste_precio_porcentaje'));
    }

    /**
     * Test 2 — alta con descuento de "5,5" (coma decimal): se guarda 5.50.
     *
     * @test
     */
    public function el_alta_con_descuento_y_coma_decimal_guarda_cinco_con_cincuenta()
    {
        $id = $this->crear_sucursal([
            'ajuste_precio_tipo'       => 'descuento',
            'ajuste_precio_porcentaje' => '5,5',
        ]);

        $this->assert_ajuste($id, 'descuento', 5.5, 'alta con descuento 5,5');

        $this->assertSame(
            '5.50',
            (string) $this->ajuste_guardado($id)->ajuste_precio_porcentaje,
            'La columna es DECIMAL(8,2): tiene que guardar 5.50.'
        );
    }

    /**
     * Test 3 — alta SIN las claves del ajuste (SPA vieja): se crea la sucursal y las dos columnas
     * quedan en NULL.
     *
     * @test
     */
    public function el_alta_sin_las_claves_del_ajuste_deja_las_dos_columnas_en_null()
    {
        $id = $this->crear_sucursal();

        $this->assert_ajuste($id, null, null, 'alta sin las claves del ajuste');

        $this->assertNull($this->getJson('api/address/'.$id)->assertStatus(200)->json('model.ajuste_precio_tipo'));
    }

    /**
     * Test 4 — alta con el tipo y el porcentaje vacios (el ABM nuevo con "Sin ajuste"): se crea la
     * sucursal y las dos columnas quedan en NULL. No es un error: es la forma normal de crear una
     * sucursal desde la SPA nueva.
     *
     * @test
     */
    public function el_alta_con_tipo_y_porcentaje_vacios_deja_las_dos_columnas_en_null()
    {
        $id = $this->crear_sucursal([
            'ajuste_precio_tipo'       => null,
            'ajuste_precio_porcentaje' => null,
        ]);

        $this->assert_ajuste($id, null, null, 'alta con las claves vacias');

        $otro_id = $this->crear_sucursal([
            'ajuste_precio_tipo'       => '',
            'ajuste_precio_porcentaje' => '',
        ]);

        $this->assert_ajuste($otro_id, null, null, 'alta con las claves en texto vacio');
    }

    /**
     * Test 5 — un 0 en el porcentaje con el tipo vacio NO impide crear la sucursal: un campo numerico
     * que arranca en 0 es lo mismo que "sin ajuste".
     *
     * @test
     */
    public function el_alta_con_tipo_vacio_y_porcentaje_cero_crea_la_sucursal_sin_ajuste()
    {
        $id = $this->crear_sucursal([
            'ajuste_precio_tipo'       => null,
            'ajuste_precio_porcentaje' => 0,
        ]);

        $this->assert_ajuste($id, null, null, 'alta con tipo vacio y porcentaje 0');
    }
}
