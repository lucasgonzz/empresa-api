<?php

namespace Tests\Feature\Costeo;

use App\Http\Controllers\Helpers\DesglosePrecioHelper;
use App\Models\PriceType;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\EmpresaTestCase;

/**
 * Tests del desglose ESTRUCTURADO del precio (mision del 21/8/2026).
 *
 * Hasta esta mision los dos endpoints del boton "?" devolvian `description`: un array de strings ya
 * concatenados. Ahora devuelven ademas `detalle`, con una entrada por renglon y su `tipo`, para que
 * el front pueda pintar cada componente del precio con su icono y su color en vez de adivinar de
 * que habla cada renglon leyendo el texto.
 *
 * 🔴 Estos tests son la contracara de DescripcionDePrecioTest, que quedo SIN TOCAR a proposito: aquel
 * verifica que `description` sigue siendo exactamente lo de antes (es lo que ven los bundles viejos
 * de la PWA y los desgloses ya guardados en base), y este verifica que `detalle` existe, esta
 * completo y no diverge de aquel. Si alguna vez hay que ajustarle una asercion a DescripcionDePrecioTest,
 * no es que ese test quedo viejo: es que se rompio la compatibilidad.
 */
class DesgloseEstructuradoTest extends EmpresaTestCase
{
    /**
     * Test 1 - toda entrada del detalle es una entrada estructurada completa.
     *
     * Es el test que atrapa el riesgo real de esta mision: los renglones se emiten en 43 sitios
     * repartidos en dos helpers, y uno que quedara sin convertir dejaria el array mezclado (strings
     * sueltos entre objetos). El front lo tolera --pinta ese renglon como nota-- justamente para que
     * un olvido no sea una pantalla en blanco, asi que sin este test la mezcla no se notaria nunca.
     *
     * @group costeo
     * @test
     */
    public function toda_entrada_del_detalle_es_estructurada()
    {
        $articulo = $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA);

        $response = $this->getJson('api/article/final-price-description/'.$articulo->id);

        $response->assertStatus(200);

        $detalle = $response->json('detalle');

        $this->assertNotEmpty($detalle, 'el desglose estructurado no puede venir vacio');

        foreach ($detalle as $posicion => $linea) {

            $this->assertIsArray(
                $linea,
                'la entrada '.$posicion.' del desglose no es una entrada estructurada: quedo un '.
                'sitio de emision sin convertir'
            );

            foreach (['tipo', 'clave', 'etiqueta', 'detalle', 'valor', 'texto'] as $clave) {
                $this->assertArrayHasKey(
                    $clave,
                    $linea,
                    'a la entrada '.$posicion.' le falta la clave "'.$clave.'"'
                );
            }
        }
    }

    /**
     * Test 2 - `description` es exactamente los `texto` del `detalle`, en el mismo orden.
     *
     * Esta es LA costura de la mision. `description` no se emite aparte: se deriva de `detalle` con
     * DesglosePrecioHelper::solo_textos(). Este test es lo que hace que "una sola emision, dos
     * vistas" sea verdad y no una intencion: si mañana alguien agrega un renglon a una sola de las
     * dos, o cambia el orden, acá se ve.
     *
     * @group costeo
     * @test
     */
    public function description_es_exactamente_los_textos_del_detalle()
    {
        $articulo = $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA);

        $response = $this->getJson('api/article/final-price-description/'.$articulo->id);

        $response->assertStatus(200);

        $description = $response->json('description');
        $detalle     = $response->json('detalle');

        $this->assertSame(
            $description,
            array_column($detalle, 'texto'),
            'description tiene que ser exactamente los textos del detalle, en el mismo orden: es lo '.
            'que ven los bundles viejos de la PWA'
        );
    }

    /**
     * Test 3 - todos los tipos pertenecen al catalogo cerrado.
     *
     * Un tipo inventado no explota en el front: cae en el neutro por defecto, que existe para que
     * agregar un tipo en el backend no rompa la pantalla de una cuenta que todavia no recargo el
     * bundle. El precio de esa tolerancia es que un tipo mal escrito se pintaria gris para siempre
     * sin que nadie se entere. Este test es lo que lo evita.
     *
     * @group costeo
     * @test
     */
    public function todos_los_tipos_pertenecen_al_catalogo()
    {
        $articulo = $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA);

        $response = $this->getJson('api/article/final-price-description/'.$articulo->id);

        $response->assertStatus(200);

        $tipos_validos = DesglosePrecioHelper::tipos();

        foreach ($response->json('detalle') as $posicion => $linea) {
            $this->assertContains(
                $linea['tipo'],
                $tipos_validos,
                'la entrada '.$posicion.' tiene el tipo "'.$linea['tipo'].'", que no esta en el '.
                'catalogo de DesglosePrecioHelper::tipos()'
            );
        }
    }

    /**
     * Test 4 - el desglose de una lista no trae la seccion del precio final unico.
     *
     * Es la regresion que arreglo la tarea 9 de la mision 10: los renglones posteriores a las listas
     * -margen del proveedor, de la categoria, del articulo- son del precio final UNICO y NO
     * participan del precio de la lista, asi que mostrarlos sugiere que el precio de la lista sale
     * de ahi.
     *
     * Lo que cambia hoy es COMO se verifica: antes el corte se hacia buscando el string exacto
     * 'CALCULO DEL PRECIO FINAL', y ese titulo lo emiten TRES sitios distintos de ArticleHelper --o
     * sea que un espacio de mas en uno solo rompia el corte en esa rama nada mas, en silencio--.
     * Ahora se corta por la clave `precio_final`, y este test la mira a ella.
     *
     * @group costeo
     * @test
     */
    public function el_desglose_de_una_lista_no_trae_la_seccion_del_precio_final_unico()
    {
        $articulo   = $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA);
        $user       = $this->user_del_fixture();
        $price_type = PriceType::where('user_id', $user->id)->first();

        if (is_null($price_type)) {
            $this->markTestSkipped('el fixture no tiene listas de precio configuradas');
        }

        // El fixture nace sin listas de precio activadas. Sin esto,
        // aplicar_precios_segun_listas_de_precios() ni siquiera corre y el desglose vuelve con el
        // tramo del precio unico y nada mas: el test pasaria por el motivo equivocado.
        $listas_de_precio_original = $user->listas_de_precio;
        $user->listas_de_precio = 1;
        $user->save();

        $response = $this->getJson('api/article/price-type-description/'.$articulo->id.'/'.$price_type->id);

        // Se restaura la bandera antes de las aserciones: si alguna falla, el fixture ya quedo como
        // estaba y no se le arruina la corrida al test siguiente.
        $user->listas_de_precio = $listas_de_precio_original;
        $user->save();

        $response->assertStatus(200);

        $claves = array_column($response->json('detalle'), 'clave');

        $this->assertContains(
            DesglosePrecioHelper::CLAVE_LISTA,
            $claves,
            'el desglose tiene que traer el tramo de la lista; si no, el test no esta probando el '.
            'corte sino un desglose sin listas'
        );

        $this->assertNotContains(
            DesglosePrecioHelper::CLAVE_PRECIO_FINAL,
            $claves,
            'el desglose de UNA lista no puede traer la seccion del precio final unico: sus '.
            'renglones no participan del precio de esa lista'
        );
    }

    /**
     * Test 5 - una lista con nombre acentuado sigue siendo una seccion.
     *
     * Cierra el hallazgo 20260805-desglose-por-lista-margen-propio-y-acentos, punto 2: el front
     * decidia que un renglon era titulo comparando `des === des.toUpperCase()`, y con una lista
     * llamada "Publico" con tilde esa comparacion daba false, asi que el encabezado se dibujaba como
     * un renglon mas del desglose.
     *
     * Con `tipo` la heuristica de mayusculas ya no existe en ningun lado del camino nuevo. Este test
     * lo deja escrito para que nadie la reintroduzca.
     *
     * @group costeo
     * @test
     */
    public function una_lista_con_nombre_acentuado_sigue_siendo_seccion()
    {
        $articulo   = $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA);
        $user       = $this->user_del_fixture();
        $price_type = PriceType::where('user_id', $user->id)->first();

        if (is_null($price_type)) {
            $this->markTestSkipped('el fixture no tiene listas de precio configuradas');
        }

        $listas_de_precio_original = $user->listas_de_precio;
        $user->listas_de_precio = 1;
        $user->save();

        $nombre_original = $price_type->name;
        $price_type->name = 'Público';
        $price_type->save();

        $response = $this->getJson('api/article/price-type-description/'.$articulo->id.'/'.$price_type->id);

        // Se restaura todo antes de asertar, por el mismo motivo que el test de arriba.
        $price_type->name = $nombre_original;
        $price_type->save();
        $user->listas_de_precio = $listas_de_precio_original;
        $user->save();

        $response->assertStatus(200);

        $encabezado = null;

        foreach ($response->json('detalle') as $linea) {
            if ($linea['clave'] === DesglosePrecioHelper::CLAVE_LISTA) {
                $encabezado = $linea;
                break;
            }
        }

        $this->assertNotNull(
            $encabezado,
            'el desglose tiene que traer el encabezado de la lista'
        );

        $this->assertSame(
            DesglosePrecioHelper::SECCION,
            $encabezado['tipo'],
            'el encabezado de una lista con nombre acentuado tiene que seguir siendo una seccion'
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
}
