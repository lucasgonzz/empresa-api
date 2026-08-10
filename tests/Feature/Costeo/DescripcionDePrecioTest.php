<?php

namespace Tests\Feature\Costeo;

use App\Models\Article;
use App\Models\PriceType;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\EmpresaTestCase;

/**
 * Tests de los dos endpoints del boton "?" del articulo (mision 10, piezas 2 y 5).
 *
 * Cubren tres cosas que salieron de dos hallazgos del 5/8/2026:
 *
 *  - `20260805-final-price-description-500-si-no-existe-el-articulo`: con un id inexistente el
 *    endpoint respondia 500 en vez de 404, y ninguno de los dos endpoints acotaba por cuenta, asi
 *    que un id de otro cliente devolvia su desglose de precios (costo real, margenes, precio final).
 *  - `20260805-desglose-por-lista-margen-propio-y-acentos`: el renglon que explica de donde sale el
 *    margen decia "propio" siempre, porque miraba si el pivote estaba cargado y esa misma funcion
 *    escribe el pivote en cada corrida.
 */
class DescripcionDePrecioTest extends EmpresaTestCase
{
    /**
     * Test 1 - un id que no existe devuelve 404, no 500.
     *
     * @group costeo
     * @test
     */
    public function un_articulo_inexistente_devuelve_404()
    {
        $id_inexistente = ((int) Article::max('id')) + 1000;

        $response = $this->getJson('api/article/final-price-description/'.$id_inexistente);

        $response->assertStatus(404);
        $response->assertJson(['message' => 'No se encontro el articulo']);
    }

    /**
     * Test 2 - un articulo de OTRA cuenta no se puede consultar.
     *
     * Es una fuga de datos entre clientes, no un detalle de prolijidad: la respuesta trae el costo
     * real, los margenes y el precio final del articulo. Y como el endpoint corre con
     * guardar_cambios en true, consultarlo ademas le RECALCULA y reescribe los precios al dueño.
     *
     * @group costeo
     * @test
     */
    public function un_articulo_de_otra_cuenta_no_se_puede_consultar()
    {
        $ajeno = $this->articulo_de_otra_cuenta();

        $response = $this->getJson('api/article/final-price-description/'.$ajeno->id);

        $response->assertStatus(404);

        $response = $this->getJson('api/article/price-type-description/'.$ajeno->id.'/1');

        $response->assertStatus(
            404,
            'el endpoint hermano (desglose por lista) tiene que cerrar la misma puerta'
        );
    }

    /**
     * Test 3 - un articulo propio sigue devolviendo su desglose.
     *
     * La contracara de los dos de arriba: sin esto, cerrar el endpoint con un 404 para todo
     * tambien pasaria los tests anteriores.
     *
     * @group costeo
     * @test
     */
    public function un_articulo_propio_devuelve_su_desglose()
    {
        $articulo = $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA);

        $response = $this->getJson('api/article/final-price-description/'.$articulo->id);

        $response->assertStatus(200);

        $this->assertNotEmpty($response->json('description'), 'el desglose no puede venir vacio');
    }

    /**
     * Test 4 - con el margen igual al default de la lista, el desglose NO dice "propio".
     *
     * Este es el test del hallazgo del desglose que miente. El escenario es el mas comun que hay:
     * un articulo que ya se guardo alguna vez (por eso tiene el pivote cargado) y cuyo margen es
     * el default de su lista. Antes del arreglo, el renglon decia "Margen propio del articulo en
     * esta lista" igual, porque miraba si el pivote estaba cargado en vez de comparar el valor.
     *
     * @group costeo
     * @test
     */
    public function con_el_margen_por_defecto_el_desglose_no_dice_que_es_propio()
    {
        $articulo   = $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA);
        $user       = $this->user_del_fixture();
        $price_type = PriceType::where('user_id', $user->id)->first();

        if (is_null($price_type)) {
            $this->markTestSkipped('el fixture no tiene listas de precio configuradas');
        }

        // El fixture nace sin listas de precio activadas (la bandera la enciende UserSeeder solo
        // para las cuentas que tienen la extension). Sin esto,
        // aplicar_precios_segun_listas_de_precios() ni siquiera corre y el desglose vuelve con el
        // tramo del precio unico y nada mas: el test pasaria por el motivo equivocado.
        $listas_de_precio_original = $user->listas_de_precio;
        $user->listas_de_precio = 1;
        $user->save();

        // El margen del articulo en esta lista queda EXACTAMENTE en el default de la lista, y el
        // pivote queda cargado (que es la condicion que antes disparaba el "propio" equivocado).
        $articulo->price_types()->syncWithoutDetaching([
            $price_type->id => ['percentage' => $price_type->percentage],
        ]);

        $response = $this->getJson('api/article/price-type-description/'.$articulo->id.'/'.$price_type->id);

        // Se restaura la bandera antes de las aserciones: si alguna falla, el fixture ya quedo
        // como estaba y no se le arruina la corrida al test siguiente.
        $user->listas_de_precio = $listas_de_precio_original;
        $user->save();

        $response->assertStatus(200);

        $description = $response->json('description');
        $texto       = is_array($description) ? implode(' | ', $description) : (string) $description;

        $this->assertStringContainsString(
            'CALCULO DEL PRECIO DE LA LISTA',
            $texto,
            'la respuesta tiene que traer el tramo de la lista; si no, el test no esta probando '.
            'el renglon del margen sino un desglose sin listas'
        );

        $this->assertStringNotContainsString(
            'Margen propio del articulo en esta lista',
            $texto,
            'con el margen igual al default de la lista, el desglose NO puede decir que el margen '.
            'es propio del articulo: ese renglon existe para auditar el calculo'
        );

        $this->assertStringContainsString(
            'Margen por defecto de la lista',
            $texto,
            'tiene que decir que el margen es el default de la lista'
        );
    }

    /**
     * Usuario dueño del fixture, resuelto por email como hace el seeder.
     *
     * @return \App\Models\User
     */
    protected function user_del_fixture()
    {
        return User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
    }

    /**
     * Devuelve un articulo que NO es de la cuenta del fixture, creando el minimo indispensable si
     * en la base no hay ninguno. Se crea a mano (sin el helper de alta) porque lo unico que
     * importa para estos tests es que exista una fila de `articles` con otro `user_id`.
     *
     * @return \App\Models\Article
     */
    protected function articulo_de_otra_cuenta()
    {
        $user_fixture = $this->user_del_fixture();

        $ajeno = Article::where('user_id', '!=', $user_fixture->id)->first();

        if (!is_null($ajeno)) {
            return $ajeno;
        }

        $otro_user = User::where('id', '!=', $user_fixture->id)->first();

        if (is_null($otro_user)) {
            $otro_user = User::create([
                'name'       => 'Cuenta ajena de prueba',
                'email'      => 'ajeno-'.time().'@testing.local',
                'doc_number' => '99999999',
                'password'   => bcrypt('1234'),
            ]);
        }

        return Article::create([
            'name'    => 'Articulo de otra cuenta',
            'cost'    => 100,
            'user_id' => $otro_user->id,
            'status'  => 'active',
        ]);
    }
}
