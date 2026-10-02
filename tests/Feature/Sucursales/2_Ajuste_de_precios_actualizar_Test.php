<?php

namespace Tests\Feature\Sucursales;

use Illuminate\Support\Facades\DB;

/**
 * Archivo 2 — ACTUALIZAR una sucursal: el ajuste se cambia, se quita, y NO se pisa cuando la SPA no
 * lo manda.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 LO QUE MAS IMPORTA DE ESTE ARCHIVO: LA COMPATIBILIDAD CON LA SPA VIEJA
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Una SPA anterior a esta mision no conoce las claves del ajuste y no las manda. `update` asigna
 *  los campos de la sucursal uno por uno; si el ajuste se escribiera "siempre", el primer guardado de
 *  una sucursal desde esa SPA le borraria el recargo o el descuento en silencio (tipo y porcentaje a
 *  NULL) y nadie se enteraria hasta que Vender dejara de aplicarlo. Test 1 lo fija.
 *
 *  Las dos puntas de la compatibilidad son del mismo contrato: SPA vieja + API nueva (este archivo)
 *  y SPA nueva + API vieja (la API vieja ignora las claves y no devuelve las columnas: nada que
 *  probar aca, es un no-op de la API anterior).
 *
 * @group sucursales
 */
class Ajuste_de_precios_actualizar_Test extends SucursalesTestCase
{
    /**
     * Test 1 — PUT SIN ninguna de las dos claves sobre una sucursal con ajuste: el ajuste SIGUE ahi,
     * y el resto de los campos si se actualizan (el PUT no quedo ignorado entero).
     *
     * @test
     */
    public function el_put_sin_las_claves_del_ajuste_no_toca_el_ajuste()
    {
        $id = $this->crear_sucursal([
            'ajuste_precio_tipo'       => 'recargo',
            'ajuste_precio_porcentaje' => 12.5,
        ]);

        $this->assert_ajuste($id, 'recargo', 12.5, 'escenario de partida');

        /* El payload de una SPA vieja: los campos de siempre y ninguna clave del ajuste. */
        $this->putJson('api/address/'.$id, $this->payload_sucursal([
            'street' => 'zz Sucursal renombrada por la SPA vieja',
            'city'   => 'Rosario',
        ]))->assertStatus(200);

        $this->assert_ajuste($id, 'recargo', 12.5, 'PUT sin las claves del ajuste');

        $this->assertSame('zz Sucursal renombrada por la SPA vieja', DB::table('addresses')->where('id', $id)->value('street'), 'El PUT tiene que haber actualizado la calle.');
        $this->assertSame('Rosario', DB::table('addresses')->where('id', $id)->value('city'), 'El PUT tiene que haber actualizado la ciudad.');
    }

    /**
     * Test 2 — PUT con `ajuste_precio_tipo` vacio / null: las dos columnas quedan en NULL (se quita el
     * ajuste). Se prueba con null, con texto vacio, con `sin_ajuste` y con el 0 (numerico o de texto)
     * que el motor del ABM le pone a un select sin valor, que son las formas en que la SPA puede decir
     * "sin ajuste".
     *
     * @test
     */
    public function el_put_con_el_tipo_vacio_quita_el_ajuste()
    {
        foreach ([null, '', 'sin_ajuste', 0, '0'] as $vacio) {

            $id = $this->crear_sucursal([
                'ajuste_precio_tipo'       => 'descuento',
                'ajuste_precio_porcentaje' => 5,
            ]);

            $this->assert_ajuste($id, 'descuento', 5, 'escenario de partida');

            $this->putJson('api/address/'.$id, $this->payload_sucursal([
                'ajuste_precio_tipo'       => $vacio,
                'ajuste_precio_porcentaje' => null,
            ]))->assertStatus(200);

            $this->assert_ajuste($id, null, null, 'PUT con el tipo '.var_export($vacio, true));
        }
    }

    /**
     * Test 3 — PUT que CAMBIA el ajuste de recargo a descuento, y que lo agrega a una sucursal que no
     * tenia.
     *
     * @test
     */
    public function el_put_cambia_y_agrega_el_ajuste()
    {
        $id = $this->crear_sucursal([
            'ajuste_precio_tipo'       => 'recargo',
            'ajuste_precio_porcentaje' => 10,
        ]);

        $this->putJson('api/address/'.$id, $this->payload_sucursal([
            'ajuste_precio_tipo'       => 'descuento',
            'ajuste_precio_porcentaje' => '7,25',
        ]))->assertStatus(200);

        $this->assert_ajuste($id, 'descuento', 7.25, 'PUT de recargo a descuento');

        $sin_ajuste = $this->crear_sucursal();

        $this->assert_ajuste($sin_ajuste, null, null, 'escenario de partida');

        $this->putJson('api/address/'.$sin_ajuste, $this->payload_sucursal([
            'ajuste_precio_tipo'       => 'recargo',
            'ajuste_precio_porcentaje' => 3,
        ]))->assertStatus(200);

        $this->assert_ajuste($sin_ajuste, 'recargo', 3, 'PUT que agrega el ajuste');
    }
}
