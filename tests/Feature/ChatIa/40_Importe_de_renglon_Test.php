<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\CatalogoDeEscrituraIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\EsquemaDeDatosIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ResumenDeDatosIaHelper;
use App\Http\Controllers\Helpers\CatalogoDeDatosIaHelper;
use App\Models\Article;
use App\Models\Provider;
use App\Models\ProviderOrder;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Misión asistente-omnisciente — el importe del renglón.
 *
 * 🔴 EL DEFECTO QUE ESTE ARCHIVO CIERRA, medido el 21/9/2026 en una conversación real contra
 * Anthropic. El dueño preguntó "¿qué producto es el que más le compro a EL MAYORISTA DEL NORTE?" y
 * el asistente contestó bien. Después preguntó "¿y en plata? ¿cuánto gasté en cada uno?" y contestó
 * $4.905 / $1.800 / $1.400 / $24,70, total $8.130. La verdad era $98.096,80 / $18.000 / $42.000 /
 * $99.500, total $257.596,80.
 *
 * Sumó los COSTOS UNITARIOS. Y no fue una alucinación: `resumir_datos` solo sabía sumar columnas
 * declaradas, y la única columna de plata de un renglón de compra es `cost`, que es el costo de UNA
 * unidad. La plata del renglón —`amount * cost`— no era ninguna columna, así que el modelo no tenía
 * con qué pedirla. Un número chico, creíble y falso, que es la clase de error del hallazgo 1 de
 * `agente-ia-mano-derecha`.
 *
 * Lo que se verifica acá: que `importe` existe en las cinco entidades de renglón, que da lo mismo
 * que la cuenta a mano (incluido el descuento de renglón, que NO está aplicado en el precio
 * guardado), que se puede sumar, ordenar, filtrar y proyectar, y que NO se puede escribir.
 */
class Importe_de_renglon_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        EsquemaDeDatosIaHelper::olvidar();

        $this->comercio = User::create([
            'name'     => 'Comercio importe de renglon',
            'email'    => 'importe-renglon-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);
    }

    /**
     * 🔴 LA PREGUNTA QUE SE CONTESTÓ MAL, ahora contestada bien.
     *
     * Los mismos cuatro artículos, las mismas proporciones: el que más unidades tiene NO es el que
     * más plata, y con `suma cost` el ranking además salía al revés.
     *
     * @group chat-ia
     * @test
     */
    public function cuanto_le_gaste_a_un_proveedor_sale_de_importe_y_no_de_cost()
    {
        $mayorista = Provider::create(['name' => 'EL MAYORISTA DEL NORTE', 'user_id' => $this->comercio->id]);

        $tornillo = Article::create(['name' => 'Tornillo c/tanque 40', 'user_id' => $this->comercio->id]);
        $precinto = Article::create(['name' => 'Precintos 03 40', 'user_id' => $this->comercio->id]);

        $compra = $this->compra($mayorista);

        // Muchísimas unidades muy baratas contra pocas unidades caras.
        $this->renglon_de_compra($compra, $tornillo, 12000, 8.2);
        $this->renglon_de_compra($compra, $precinto, 20, 2560);
        $this->renglon_de_compra($compra, $precinto, 20, 2344.84);

        $resultado = ResumenDeDatosIaHelper::resumir(
            $this->comercio->id,
            'renglon_de_compra',
            [['campo' => 'provider_id', 'operador' => 'contiene', 'valor' => 'mayorista del norte']],
            [['campo' => 'article_id']],
            [['funcion' => 'suma', 'campo' => 'importe'], ['funcion' => 'suma', 'campo' => 'amount']]
        );

        $this->assertArrayNotHasKey('error', $resultado, json_encode($resultado));
        $this->assertEquals(['suma_importe', 'suma_amount'], $resultado['metricas']);
        $this->assertEquals('suma_importe DESC', $resultado['orden'], 'Sin orden explícito manda la primera métrica.');

        // 12000 x 8,2 = 98.400 contra 20 x 2560 + 20 x 2344,84 = 98.096,80.
        $this->assertEquals('Tornillo c/tanque 40', $resultado['grupos'][0]['grupo_etiqueta']);
        $this->assertEquals(98400.0, $resultado['grupos'][0]['suma_importe']);
        $this->assertEquals(12000.0, $resultado['grupos'][0]['suma_amount']);

        $this->assertEquals('Precintos 03 40', $resultado['grupos'][1]['grupo_etiqueta']);
        $this->assertEquals(98096.80, $resultado['grupos'][1]['suma_importe']);
        $this->assertEquals(40.0, $resultado['grupos'][1]['suma_amount']);

        $this->assertEquals(196496.80, $resultado['total_general']['suma_importe']);

        /*
         * Y LA RESPUESTA VIEJA, para que se vea el tamaño del error: sumar `cost` da los costos
         * unitarios. 8,2 contra 4904,84 — el ranking sale dado vuelta y el total es 2,5% del real.
         */
        $vieja = ResumenDeDatosIaHelper::resumir(
            $this->comercio->id,
            'renglon_de_compra',
            [['campo' => 'provider_id', 'operador' => 'contiene', 'valor' => 'mayorista del norte']],
            [['campo' => 'article_id']],
            [['funcion' => 'suma', 'campo' => 'cost']]
        );

        $this->assertEquals(4913.04, $vieja['total_general']['suma_cost']);
        $this->assertNotEquals(
            $resultado['total_general']['suma_importe'],
            $vieja['total_general']['suma_cost'],
            'Si estos dos dieran lo mismo, el campo calculado no estaría haciendo nada.'
        );
    }

    /**
     * EL DESCUENTO DE RENGLÓN, que es lo que hace que `amount * cost` a secas no alcance.
     *
     * El precio y el costo se guardan BRUTOS y el descuento es un porcentaje aparte
     * (SaleHelper.php:1264 y 1268): el importe tiene que aplicarlo, como lo aplican
     * `SaleHelper::getTotalItem()` y `NewProviderOrderHelper::get_total_article()`.
     *
     * @group chat-ia
     * @test
     */
    public function el_importe_aplica_el_descuento_del_renglon_y_el_nulo_no_lo_anula()
    {
        $proveedor = Provider::create(['name' => 'Proveedor con descuento 40', 'user_id' => $this->comercio->id]);
        $articulo = Article::create(['name' => 'Articulo con descuento 40', 'user_id' => $this->comercio->id]);

        $compra = $this->compra($proveedor);

        // Con 10% de descuento: 100 x 50 = 5000, menos 10% = 4500.
        $this->renglon_de_compra($compra, $articulo, 100, 50, 10);

        // 🔴 Sin descuento la columna queda NULL, y un NULL sin COALESCE anularía TODO el producto:
        // 200 x 50 = 10000 se volvería NULL y desaparecería de la suma sin que nada avise.
        $this->renglon_de_compra($compra, $articulo, 200, 50, null);

        $resultado = ResumenDeDatosIaHelper::resumir(
            $this->comercio->id,
            'renglon_de_compra',
            [['campo' => 'provider_id', 'operador' => 'igual', 'valor' => $proveedor->id]],
            [],
            [['funcion' => 'suma', 'campo' => 'importe'], ['funcion' => 'conteo']]
        );

        $this->assertEquals(2, $resultado['total_general']['conteo'], 'Los dos renglones entran.');
        $this->assertEquals(14500.0, $resultado['total_general']['suma_importe'], '4500 con descuento + 10000 sin descuento.');
    }

    /**
     * Las cinco entidades de renglón declaran `importe`, y `renglon_de_venta` además el costo y la
     * ganancia. Es la aserción que denuncia si alguien agrega una entidad hija de renglón y se
     * olvida del campo, o si una tabla gana una columna que le pisa el nombre.
     *
     * @group chat-ia
     * @test
     */
    public function las_cinco_entidades_de_renglon_declaran_importe_como_campo_calculado()
    {
        $esperados = [
            'renglon_de_venta'            => ['importe', 'costo_total', 'ganancia_estimada'],
            'renglon_de_compra'           => ['importe'],
            'renglon_de_presupuesto'      => ['importe'],
            'renglon_de_pedido'           => ['importe'],
            'renglon_de_nota_de_credito'  => ['importe'],
        ];

        foreach ($esperados as $entidad => $campos) {
            $declaracion = EsquemaDeDatosIaHelper::declaracion($entidad);

            $this->assertNotNull($declaracion, $entidad . ': la entidad tiene que existir.');

            foreach ($campos as $campo) {
                $this->assertArrayHasKey($campo, $declaracion['campos'], $entidad . ': falta el campo ' . $campo . '.');
                $this->assertTrue(
                    EsquemaDeDatosIaHelper::es_calculado($declaracion, $campo),
                    $entidad . '.' . $campo . ': tiene que estar marcado como calculado (si la tabla ganó una columna con ese nombre, la real gana y este campo dejó de ser la cuenta).'
                );
                $this->assertEquals('number', $declaracion['campos'][$campo]['tipo'], $entidad . '.' . $campo);

                // 🔴 Y viaja POR DEFECTO: es la plata del renglón, no puede quedar detrás del tope
                // de treinta columnas esperando que alguien la pida.
                $this->assertContains($campo, $declaracion['campos_por_defecto'], $entidad . '.' . $campo . ': tiene que viajar sin pedirlo.');
            }

            // La descripción le dice al modelo qué sumar, en criollo.
            $this->assertStringContainsString('importe', $declaracion['descripcion'], $entidad . ': la descripción tiene que nombrar importe.');
        }
    }

    /**
     * El importe de una venta, con su costo y su ganancia — y la columna `ganancia` persistida, que
     * NO se usa porque está mal calculada (ignora el descuento: SaleHelper.php:1258 y 1262).
     *
     * @group chat-ia
     * @test
     */
    public function el_renglon_de_venta_trae_importe_costo_total_y_ganancia_estimada()
    {
        $articulo = Article::create(['name' => 'Articulo vendido 40', 'user_id' => $this->comercio->id]);

        $venta = Sale::create(['user_id' => $this->comercio->id, 'moneda_id' => 1, 'num' => 4040]);

        // 10 unidades a 100 con 20% de descuento = 800; costaron 10 x 60 = 600; ganancia 200.
        DB::table('article_sale')->insert([
            'sale_id'    => $venta->id,
            'article_id' => $articulo->id,
            'amount'     => 10,
            'price'      => 100,
            'cost'       => 60,
            'discount'   => 20,
            // La persistida, con la fórmula del sistema: (price - cost) * amount, sin el descuento.
            'ganancia'   => 400,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resultado = ResumenDeDatosIaHelper::resumir(
            $this->comercio->id,
            'renglon_de_venta',
            [['campo' => 'numero_venta', 'operador' => 'igual', 'valor' => 4040]],
            [],
            [
                ['funcion' => 'suma', 'campo' => 'importe'],
                ['funcion' => 'suma', 'campo' => 'costo_total'],
                ['funcion' => 'suma', 'campo' => 'ganancia_estimada'],
                ['funcion' => 'suma', 'campo' => 'ganancia'],
            ]
        );

        $this->assertArrayNotHasKey('error', $resultado, json_encode($resultado));
        $this->assertEquals(800.0, $resultado['total_general']['suma_importe'], '10 x 100 menos 20%.');
        $this->assertEquals(600.0, $resultado['total_general']['suma_costo_total'], '10 x 60, sin descuento: el descuento es del precio.');
        $this->assertEquals(200.0, $resultado['total_general']['suma_ganancia_estimada'], 'importe menos costo_total.');

        // 🔴 Y la persistida dice 400: el doble, porque ignora el descuento. Por eso no se usa, y
        // por eso su etiqueta lo dice.
        $this->assertEquals(400.0, $resultado['total_general']['suma_ganancia']);
        $this->assertStringContainsString(
            'NO la uses',
            EsquemaDeDatosIaHelper::declaracion('renglon_de_venta')['campos']['ganancia']['etiqueta']
        );
    }

    /**
     * `importe` se proyecta, se filtra y se ordena en consultar_datos como cualquier número — y el
     * filtro va en el WHERE, así que `registros_encontrados` y la paginación siguen siendo las
     * mismas que cuenta el SQL a mano.
     *
     * @group chat-ia
     * @test
     */
    public function importe_se_proyecta_se_filtra_y_se_ordena_en_consultar_datos()
    {
        $proveedor = Provider::create(['name' => 'Proveedor proyectado 40', 'user_id' => $this->comercio->id]);
        $articulo = Article::create(['name' => 'Articulo proyectado 40', 'user_id' => $this->comercio->id]);

        $compra = $this->compra($proveedor);

        $this->renglon_de_compra($compra, $articulo, 1, 100);      // 100
        $this->renglon_de_compra($compra, $articulo, 10, 100);     // 1000
        $this->renglon_de_compra($compra, $articulo, 100, 100);    // 10000

        $lista = CatalogoDeDatosIaHelper::consultar_datos(
            $this->comercio->id,
            'renglon_de_compra',
            [
                ['campo' => 'provider_id', 'operador' => 'igual', 'valor' => $proveedor->id],
                ['campo' => 'importe', 'operador' => 'mayor_o_igual', 'valor' => 1000],
            ],
            ['campo' => 'importe', 'direccion' => 'DESC'],
            1,
            20,
            ['article_id', 'amount', 'cost', 'importe']
        );

        $this->assertArrayNotHasKey('error', $lista, json_encode($lista));

        // El de 100 queda afuera: el filtro es sobre la cuenta, no sobre el costo unitario (que es
        // 100 en los tres).
        $this->assertEquals(2, $lista['registros_encontrados']);
        $this->assertEquals(2, $lista['registros_en_esta_lista']);

        $this->assertEquals(10000.0, $lista['registros'][0]['importe']);
        $this->assertEquals(1000.0, $lista['registros'][1]['importe']);

        // Proyectado como número, no como texto, y junto a las columnas de las que sale.
        $this->assertEquals(100.0, $lista['registros'][0]['amount']);
        $this->assertEquals(100.0, $lista['registros'][0]['cost']);

        // Y el catálogo se lo ofrece al modelo marcado como calculado.
        $catalogo = CatalogoDeDatosIaHelper::que_puedo_consultar('renglon_de_compra');

        $importe = null;

        foreach ($catalogo['campos'] as $campo) {
            if ($campo['campo'] === 'importe') {
                $importe = $campo;
            }
        }

        $this->assertNotNull($importe, 'importe tiene que estar entre los campos que se ofrecen.');
        $this->assertEquals('number', $importe['tipo']);
        $this->assertArrayHasKey('calculado', $importe);
        $this->assertContains('mayor', $importe['operadores']);
    }

    /**
     * 🔴 UN CAMPO CALCULADO NO SE ESCRIBE. El catálogo de escritura arma sus campos recorriendo las
     * columnas reales de `information_schema`, así que `importe` no puede entrar ni por alta ni por
     * edición — pero eso es una consecuencia, no una decisión, y si alguien cambiara esa fuente
     * tiene que romperse acá.
     *
     * @group chat-ia
     * @test
     */
    public function un_campo_calculado_no_entra_al_catalogo_de_escritura()
    {
        $calculados = ['importe', 'costo_total', 'ganancia_estimada'];

        foreach (array_keys(CatalogoDeEscrituraIaHelper::entidades()) as $entidad) {
            $declaracion = CatalogoDeEscrituraIaHelper::declaracion($entidad);

            if (is_null($declaracion) || ! isset($declaracion['campos'])) {
                continue;
            }

            foreach ($calculados as $calculado) {
                $this->assertArrayNotHasKey(
                    $calculado,
                    $declaracion['campos'],
                    $entidad . ': "' . $calculado . '" es una cuenta, no una columna: no se puede cargar ni editar.'
                );
            }
        }
    }

    /**
     * EL AVISO QUE `en_otra_moneda` NO PUEDE DAR: `cost_in_dollars` es una marca POR RENGLÓN, así
     * que la compra puede estar en pesos y el importe de una de sus líneas estar en dólares.
     *
     * @group chat-ia
     * @test
     */
    public function un_renglon_con_el_costo_en_dolares_se_avisa_aparte_de_la_moneda_del_comprobante()
    {
        $proveedor = Provider::create(['name' => 'Proveedor en dolares 40', 'user_id' => $this->comercio->id]);
        $articulo = Article::create(['name' => 'Articulo en dolares 40', 'user_id' => $this->comercio->id]);

        // La compra está en PESOS: moneda_id 1.
        $compra = $this->compra($proveedor);

        $this->renglon_de_compra($compra, $articulo, 10, 100);
        $this->renglon_de_compra($compra, $articulo, 5, 80, null, 1);

        $resultado = ResumenDeDatosIaHelper::resumir(
            $this->comercio->id,
            'renglon_de_compra',
            [['campo' => 'provider_id', 'operador' => 'igual', 'valor' => $proveedor->id]],
            [],
            [['funcion' => 'suma', 'campo' => 'importe']]
        );

        // La moneda del comprobante sale limpia: por ahí no se entera nadie.
        $this->assertArrayNotHasKey('en_otra_moneda', $resultado, 'La compra está en pesos.');

        // Pero el aviso del renglón sí sale, y dice cuántos son.
        $this->assertArrayHasKey('importe_en_dolares', $resultado, 'Un renglón con el costo en dólares tiene que avisarse.');
        $this->assertEquals(1, $resultado['importe_en_dolares']['registros']);
        $this->assertStringContainsString('DOLARES', $resultado['importe_en_dolares']['aviso']);

        // Y si el modelo filtra por la marca, no hay nada que avisar.
        $solo_pesos = ResumenDeDatosIaHelper::resumir(
            $this->comercio->id,
            'renglon_de_compra',
            [
                ['campo' => 'provider_id', 'operador' => 'igual', 'valor' => $proveedor->id],
                ['campo' => 'cost_in_dollars', 'operador' => 'igual', 'valor' => 'no'],
            ],
            [],
            [['funcion' => 'suma', 'campo' => 'importe']]
        );

        $this->assertArrayNotHasKey('importe_en_dolares', $solo_pesos);
        $this->assertEquals(1000.0, $solo_pesos['total_general']['suma_importe']);
    }

    /**
     * Una compra a un proveedor, en pesos.
     *
     * @param  Provider  $proveedor
     * @return ProviderOrder
     */
    protected function compra($proveedor)
    {
        return ProviderOrder::create([
            'user_id'     => $proveedor->user_id,
            'provider_id' => $proveedor->id,
            'moneda_id'   => 1,
            'created_at'  => now()->subDays(3),
        ]);
    }

    /**
     * Un renglón de compra.
     *
     * @param  ProviderOrder  $compra
     * @param  Article        $articulo
     * @param  float          $cantidad
     * @param  float          $costo
     * @param  float|null     $descuento
     * @param  int            $en_dolares
     * @return void
     */
    protected function renglon_de_compra($compra, $articulo, $cantidad, $costo, $descuento = null, $en_dolares = 0)
    {
        DB::table('article_provider_order')->insert([
            'provider_order_id' => $compra->id,
            'article_id'        => $articulo->id,
            'amount'            => $cantidad,
            'received'          => $cantidad,
            'cost'              => $costo,
            'discount'          => $descuento,
            'cost_in_dollars'   => $en_dolares,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }
}
