<?php

namespace Tests\Feature\Sucursales;

use Illuminate\Support\Facades\DB;

/**
 * Archivo 3 — lo que NO se guarda: todo valor que viola la invariante responde 422 con
 * `{message}` en espanol, y NO escribe nada.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  LA INVARIANTE
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  O las dos columnas tienen un valor valido, o las dos son NULL. Valido = tipo `recargo` o
 *  `descuento` + porcentaje mayor que 0 (descuento: menor que 100; recargo: hasta 999.99).
 *
 *  Se rechaza con 422 y no se "arregla en silencio" porque es plata: un descuento de 150 % tipeado
 *  por 15 no puede terminar guardado como otra cosa.
 *
 *  "Sin escribir nada" quiere decir DOS cosas, y las dos se miran:
 *   - en el ALTA, que no quede una sucursal a medio escribir (se cuenta antes y despues);
 *   - en la EDICION, que no cambie NI el ajuste NI ningun otro campo del mismo PUT (la validacion
 *     corre antes de tocar el modelo).
 *
 * @group sucursales
 */
class Ajuste_de_precios_invalidos_Test extends SucursalesTestCase
{
    /**
     * Valores que violan la regla del PORCENTAJE o del TIPO, cada uno con su etiqueta para el mensaje
     * del test. Son los siete del plan mas los bordes que el redondeo a dos decimales vuelve
     * invalidos.
     *
     * @return array<string,array>  etiqueta => [tipo, porcentaje]
     */
    protected function valores_invalidos()
    {
        return [
            'tipo desconocido'                         => ['otro', 10],
            'recargo de 0'                             => ['recargo', 0],
            'descuento de 0'                           => ['descuento', 0],
            'recargo negativo'                         => ['recargo', -5],
            'descuento negativo'                       => ['descuento', -5],
            'porcentaje que no es un numero'           => ['recargo', 'abc'],
            'porcentaje en notacion cientifica'        => ['recargo', '1e1'],
            'descuento de 100'                         => ['descuento', 100],
            'descuento de 150'                         => ['descuento', 150],
            'descuento de 99,999 que redondea a 100'   => ['descuento', '99,999'],
            'recargo de 1000'                          => ['recargo', 1000],
            'recargo de 999,999 que redondea a 1000'   => ['recargo', '999,999'],
            'porcentaje que redondea a 0'              => ['recargo', '0,004'],
            'tipo con un array'                        => [['recargo'], 10],
            'tipo booleano verdadero'                  => [true, 10],
            'tipo booleano falso'                      => [false, 10],
            'tipo numerico 1'                          => [1, 10],
            'tipo en texto "1"'                        => ['1', 10],
        ];
    }

    /**
     * Valores que violan la INVARIANTE por faltar una de las dos mitades (plan, caso 6).
     *
     * @return array<string,array>  etiqueta => [tipo, porcentaje]
     */
    protected function mitades_sueltas()
    {
        return [
            'recargo sin porcentaje (null)'            => ['recargo', null],
            'recargo sin porcentaje (texto vacio)'     => ['recargo', ''],
            'descuento sin porcentaje (en blanco)'     => ['descuento', '   '],
            'porcentaje sin tipo (null)'               => [null, 10],
            'porcentaje sin tipo (texto vacio)'        => ['', 10],
            'porcentaje sin tipo (sin_ajuste)'         => ['sin_ajuste', 10],
            'porcentaje sin tipo (0 del ABM)'          => [0, 10],
            'porcentaje sin tipo (texto "0")'          => ['0', 10],
        ];
    }

    /**
     * Test 1 — el ALTA con cualquiera de los valores invalidos: 422 con `message`, y no queda una
     * sucursal de mas.
     *
     * @test
     */
    public function el_alta_con_valores_invalidos_responde_422_y_no_crea_nada()
    {
        $casos = array_merge($this->valores_invalidos(), $this->mitades_sueltas());

        $antes = $this->cantidad_de_sucursales();

        foreach ($casos as $etiqueta => $par) {

            $response = $this->postJson('api/address', $this->payload_sucursal([
                'ajuste_precio_tipo'       => $par[0],
                'ajuste_precio_porcentaje' => $par[1],
            ]));

            $response->assertStatus(422);

            $this->assertTrue(
                is_string($response->json('message')) && $response->json('message') !== '',
                $etiqueta.': el 422 tiene que traer `message` con un texto para el usuario.'
            );

            $this->assertSame(
                $antes,
                $this->cantidad_de_sucursales(),
                $etiqueta.': un 422 en el alta no puede dejar una sucursal creada.'
            );
        }
    }

    /**
     * Test 2 — el ALTA con una sola de las dos claves (la otra ausente): tambien es la invariante rota.
     * Es lo que manda una SPA a medio actualizar o un cliente de la API escrito a mano.
     *
     * @test
     */
    public function el_alta_con_una_sola_de_las_dos_claves_responde_422()
    {
        $antes = $this->cantidad_de_sucursales();

        $this->postJson('api/address', $this->payload_sucursal(['ajuste_precio_tipo' => 'recargo']))
             ->assertStatus(422);

        $this->postJson('api/address', $this->payload_sucursal(['ajuste_precio_porcentaje' => 10]))
             ->assertStatus(422);

        $this->assertSame($antes, $this->cantidad_de_sucursales(), 'Ningun 422 puede dejar una sucursal creada.');
    }

    /**
     * Test 3 — la EDICION con cualquiera de los valores invalidos: 422, el ajuste de partida sigue
     * intacto y NINGUN otro campo del mismo PUT se escribio (se mandan la calle y la ciudad cambiadas
     * a proposito).
     *
     * @test
     */
    public function la_edicion_con_valores_invalidos_responde_422_y_no_escribe_nada()
    {
        $casos = array_merge($this->valores_invalidos(), $this->mitades_sueltas());

        $id = $this->crear_sucursal([
            'street'                   => 'zz Sucursal original',
            'city'                     => 'Cordoba',
            'ajuste_precio_tipo'       => 'recargo',
            'ajuste_precio_porcentaje' => 10,
        ]);

        foreach ($casos as $etiqueta => $par) {

            $response = $this->putJson('api/address/'.$id, $this->payload_sucursal([
                'street'                   => 'zz Sucursal cambiada por un PUT invalido',
                'city'                     => 'Mendoza',
                'ajuste_precio_tipo'       => $par[0],
                'ajuste_precio_porcentaje' => $par[1],
            ]));

            $response->assertStatus(422);

            $this->assertTrue(
                is_string($response->json('message')) && $response->json('message') !== '',
                $etiqueta.': el 422 tiene que traer `message` con un texto para el usuario.'
            );

            $this->assert_ajuste($id, 'recargo', 10, $etiqueta.' (el ajuste de partida tiene que seguir)');

            $fila = DB::table('addresses')->where('id', $id)->first(['street', 'city']);

            $this->assertSame('zz Sucursal original', $fila->street, $etiqueta.': un 422 no puede haber cambiado la calle.');
            $this->assertSame('Cordoba', $fila->city, $etiqueta.': un 422 no puede haber cambiado la ciudad.');
        }
    }

    /**
     * Test 4 — los BORDES validos, para que las reglas de arriba no sean un "rechazar todo": un
     * descuento de 99,99 y un recargo de 999,99 se guardan (el limite del recargo es inclusivo; el del
     * descuento es exclusivo del 100), y un porcentaje diminuto pero que sobrevive al redondeo (0,01)
     * tambien.
     *
     * @test
     */
    public function los_bordes_validos_se_guardan()
    {
        $descuento = $this->crear_sucursal([
            'ajuste_precio_tipo'       => 'descuento',
            'ajuste_precio_porcentaje' => 99.99,
        ]);

        $this->assert_ajuste($descuento, 'descuento', 99.99, 'descuento de 99,99');

        $recargo = $this->crear_sucursal([
            'ajuste_precio_tipo'       => 'recargo',
            'ajuste_precio_porcentaje' => '999,99',
        ]);

        $this->assert_ajuste($recargo, 'recargo', 999.99, 'recargo de 999,99');

        $minimo = $this->crear_sucursal([
            'ajuste_precio_tipo'       => 'recargo',
            'ajuste_precio_porcentaje' => 0.01,
        ]);

        $this->assert_ajuste($minimo, 'recargo', 0.01, 'recargo de 0,01');
    }
}
