<?php

namespace Tests\Unit\BuscadorGeneral;

use App\Http\Controllers\Helpers\GlobalSearchMatchesHelper;
use App\Models\Article;
use App\Models\Provider;
use Tests\TestCase;

/**
 * Grupo 274 · Prompt 01 — unit tests puros de `GlobalSearchMatchesHelper::build()`.
 *
 * Extiende `Tests\TestCase` (no `Tests\EmpresaTestCase`) porque no persiste una sola fila: los
 * modelos se arman en memoria con `new Article()` y las relaciones se inyectan con `setRelation()`.
 * Necesita igual el contenedor de Laravel (y la conexion real de `empresa_testing_s1`) porque
 * `Schema::hasColumn` y `ExtraFiltersHelper::is_numeric_column` resuelven contra el schema real via
 * `information_schema` -- no hay forma de mockear eso sin perder la garantia de que el helper
 * valida columnas de verdad. No usa `RefreshDatabase` ni `DatabaseTransactions`: no hay nada que
 * revertir.
 *
 * @group buscador-general-unit
 */
class GlobalSearchMatchesHelperTest extends TestCase
{
    /**
     * Arma un Article en memoria (nunca persistido).
     *
     * @param array<string,mixed> $overrides
     * @return \App\Models\Article
     */
    protected function articulo($overrides = [])
    {
        $articulo = new Article();

        $defaults = [
            'name'          => null,
            'provider_code' => null,
        ];

        foreach (array_merge($defaults, $overrides) as $campo => $valor) {
            $articulo->$campo = $valor;
        }

        return $articulo;
    }

    /** @test */
    public function query_value_vacio_devuelve_null()
    {
        $rows = [$this->articulo(['name' => 'Pinza universal'])];

        $resultado = GlobalSearchMatchesHelper::build($rows, '   ', ['name'], [], 'articles', new Article());

        $this->assertNull($resultado);
    }

    /** @test */
    public function coleccion_vacia_devuelve_null()
    {
        $resultado = GlobalSearchMatchesHelper::build([], 'pinza', ['name'], [], 'articles', new Article());

        $this->assertNull($resultado);
    }

    /** @test */
    public function caso_del_pedido_de_lucas_sin_solapamiento()
    {
        // Tres articulos cuyo provider_code contiene "8532", dos cuyo name contiene "pinza", sin
        // que ningun articulo coincida por las dos propiedades a la vez.
        $rows = [
            $this->articulo(['provider_code' => '8532', 'name' => 'Martillo']),
            $this->articulo(['provider_code' => '8532-A', 'name' => 'Destornillador']),
            $this->articulo(['provider_code' => 'X-8532', 'name' => 'Serrucho']),
            $this->articulo(['provider_code' => '1000', 'name' => 'Pinza universal']),
            $this->articulo(['provider_code' => '2000', 'name' => 'Pinza de punta']),
        ];

        $resultado = GlobalSearchMatchesHelper::build(
            $rows,
            '8532 pinza',
            ['provider_code', 'name'],
            [],
            'articles',
            new Article()
        );

        $this->assertEquals(5, $resultado['total']);
        $this->assertEquals(0, $resultado['sin_atribuir']);
        $this->assertEquals(0, $resultado['con_multiples']);

        $por_key = $this->propiedades_por_key($resultado['propiedades']);
        $this->assertEquals(3, $por_key['provider_code']);
        $this->assertEquals(2, $por_key['name']);
    }

    /** @test */
    public function un_articulo_que_coincide_por_dos_propiedades_cuenta_en_las_dos_y_con_multiples()
    {
        $rows = [
            // Coincide por las dos: "pinza" esta en el name y en el provider_code a la vez.
            $this->articulo(['provider_code' => 'pinza-8532', 'name' => 'Pinza universal']),
            // Coincide solo por name.
            $this->articulo(['provider_code' => '1000', 'name' => 'Pinza de punta']),
        ];

        $resultado = GlobalSearchMatchesHelper::build(
            $rows,
            'pinza',
            ['provider_code', 'name'],
            [],
            'articles',
            new Article()
        );

        $this->assertEquals(2, $resultado['total']);
        $this->assertEquals(1, $resultado['con_multiples']);

        $por_key = $this->propiedades_por_key($resultado['propiedades']);
        $this->assertEquals(1, $por_key['provider_code']);
        $this->assertEquals(2, $por_key['name']);

        // Este es el test que fija la semantica NO exclusiva: la suma (1 + 2 = 3) supera el total
        // (2) porque el primer articulo cuenta en las dos propiedades a la vez, sin repartirse a
        // una sola. Si alguien mas adelante "arregla" el helper repartiendo cada registro a una
        // sola propiedad por prioridad, esta asercion se pone en rojo -- que es exactamente lo que
        // tiene que pasar.
        $this->assertGreaterThan($resultado['total'], array_sum($por_key));
    }

    /** @test */
    public function un_registro_sin_ninguna_coincidencia_suma_a_sin_atribuir()
    {
        $rows = [
            $this->articulo(['name' => 'Pinza universal', 'provider_code' => '8532']),
            $this->articulo(['name' => 'Martillo', 'provider_code' => '1000']),
        ];

        // Buscamos "pinza": el segundo articulo no coincide por ninguna propiedad tildada.
        $resultado = GlobalSearchMatchesHelper::build(
            $rows,
            'pinza',
            ['name', 'provider_code'],
            [],
            'articles',
            new Article()
        );

        $this->assertEquals(1, $resultado['sin_atribuir']);
    }

    /** @test */
    public function coincidencia_sin_distinguir_mayusculas()
    {
        $rows = [$this->articulo(['name' => 'Pinza universal'])];

        $resultado = GlobalSearchMatchesHelper::build($rows, 'PINZA', ['name'], [], 'articles', new Article());

        $por_key = $this->propiedades_por_key($resultado['propiedades']);
        $this->assertEquals(1, $por_key['name']);
        $this->assertEquals(0, $resultado['sin_atribuir']);
    }

    /** @test */
    public function propiedad_tildada_que_no_aporto_nada_aparece_con_cantidad_cero()
    {
        $rows = [
            $this->articulo(['name' => 'Pinza universal', 'provider_code' => '8532']),
        ];

        $resultado = GlobalSearchMatchesHelper::build(
            $rows,
            'pinza',
            ['name', 'provider_code'],
            [],
            'articles',
            new Article()
        );

        $por_key = $this->propiedades_por_key($resultado['propiedades']);
        $this->assertArrayHasKey('provider_code', $por_key);
        $this->assertEquals(0, $por_key['provider_code']);
    }

    /** @test */
    public function orden_la_propiedad_con_mas_coincidencias_viene_primera()
    {
        $rows = [
            $this->articulo(['name' => 'Pinza universal', 'provider_code' => '8532']),
            $this->articulo(['name' => 'Pinza de punta', 'provider_code' => '1000']),
            $this->articulo(['name' => 'Martillo', 'provider_code' => '2000']),
        ];

        // "pinza" aporta 2 por name, 0 por provider_code.
        $resultado = GlobalSearchMatchesHelper::build(
            $rows,
            'pinza',
            ['provider_code', 'name'],
            [],
            'articles',
            new Article()
        );

        $this->assertEquals('name', $resultado['propiedades'][0]['key']);
        $this->assertEquals(2, $resultado['propiedades'][0]['cantidad']);
        $this->assertEquals('provider_code', $resultado['propiedades'][1]['key']);
        $this->assertEquals(0, $resultado['propiedades'][1]['cantidad']);
    }

    /** @test */
    public function una_relacion_cargada_que_coincide_por_name_cuenta_como_coincidencia_de_relacion()
    {
        $proveedor_pinza = new Provider();
        $proveedor_pinza->name = 'Pinza Hnos SA';

        $proveedor_otro = new Provider();
        $proveedor_otro->name = 'Ferreteria Sur';

        $articulo_con_match = $this->articulo(['name' => 'Martillo']);
        $articulo_con_match->setRelation('provider', $proveedor_pinza);

        $articulo_sin_match = $this->articulo(['name' => 'Serrucho']);
        $articulo_sin_match->setRelation('provider', $proveedor_otro);

        $resultado = GlobalSearchMatchesHelper::build(
            [$articulo_con_match, $articulo_sin_match],
            'pinza',
            [],
            [['relation' => 'provider', 'props' => ['name']]],
            'articles',
            new Article()
        );

        $this->assertNotNull($resultado);

        $relacion = null;
        foreach ($resultado['propiedades'] as $propiedad) {
            if ($propiedad['kind'] === 'relation' && $propiedad['relation'] === 'provider') {
                $relacion = $propiedad;
            }
        }

        $this->assertNotNull($relacion);
        $this->assertEquals(1, $relacion['cantidad']);
        $this->assertEquals(1, $resultado['sin_atribuir']);
    }

    /** @test */
    public function una_key_que_no_existe_como_columna_se_descarta_sin_romper()
    {
        $rows = [$this->articulo(['name' => 'Pinza universal'])];

        $resultado = GlobalSearchMatchesHelper::build(
            $rows,
            'pinza',
            ['name', 'columna_que_no_existe'],
            [],
            'articles',
            new Article()
        );

        $this->assertNotNull($resultado);

        $por_key = $this->propiedades_por_key($resultado['propiedades']);
        $this->assertArrayHasKey('name', $por_key);
        $this->assertArrayNotHasKey('columna_que_no_existe', $por_key);
    }

    /**
     * Aplana el array `propiedades` de la respuesta a un mapa `key => cantidad`, solo para las
     * entradas `kind => 'own'` (las de relacion no tienen `key`, tienen `relation`).
     *
     * @param array $propiedades
     * @return array<string,int>
     */
    protected function propiedades_por_key($propiedades)
    {
        $mapa = [];

        foreach ($propiedades as $propiedad) {
            if ($propiedad['kind'] === 'own') {
                $mapa[$propiedad['key']] = $propiedad['cantidad'];
            }
        }

        return $mapa;
    }
}
