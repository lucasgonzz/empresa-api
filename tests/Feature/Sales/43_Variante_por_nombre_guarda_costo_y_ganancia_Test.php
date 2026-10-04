<?php

namespace Tests\Feature\Sales;

use App\Models\Article;
use App\Models\ArticleVariant;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use Tests\EmpresaTestCase;
use Tests\Feature\VentasEnDolares\EscenarioDeMonedas;

/**
 * Mision "variante-por-nombre-con-costo" (3/10/2026): una variante elegida por NOMBRE en Vender se
 * vendia con `cost` null y ganancia = precio, y sin conversion de moneda para un articulo en dolares.
 *
 * CAUSA. `VenderSearchHelper::build_row()` arma la fila de una variante PLANA y la SPA la manda tal
 * cual como item de `POST api/sale` (`ArticleName.setSelected`: `{...fila, is_article: true}`).
 * `SaleHelper::getCost()` lee `costo_real` / `cost` de la RAIZ del item y
 * `CotizacionDeVentaHelper::item_esta_en_dolares()` lee `cost_in_dollars` de la raiz: con la fila plana
 * no estaban, y `attachArticle()` guardaba `cost = null` y `ganancia = price * amount`.
 *
 * ARREGLO. La fila de variante suma `cost`, `costo_real` y `cost_in_dollars` del articulo (la variante
 * no tiene costo propio). Lo demas que necesita el costo (`unidades_individuales`, `iva_id`) la API lo
 * lee de la base cuando el item no lo trae.
 *
 * POR QUE ESTOS TESTS NO ARMAN LA FILA A MANO. Los tests de la mision anterior (codigo de barras de
 * variantes) armaban la fila a mano y no veian este defecto: lo encontraron tres verificadores
 * leyendo el codigo. Aca la fila sale de la RESPUESTA REAL del buscador, el item se arma como lo arma la
 * SPA (la fila + `is_article`, `amount`, `price_vender` y la variante) y se guarda con `POST api/sale`.
 * Si alguien saca las claves de `build_row`, la venta vuelve a salir sin costo y estos tests se ponen
 * rojos.
 *
 * Reusa el escenario de la suite `VentasEnDolares` (`EscenarioDeMonedas`): duenio 500, extension
 * `ventas_en_dolares`, `cotizar_precios_en_dolares = 0` (el `final_price` de un articulo en dolares
 * queda EN DOLARES y la SPA lo convierte a la moneda de la venta antes de mandarlo).
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promocion de constructor, readonly, enum ni #[...].
 *
 * @group sales
 * @group vender-variantes
 */
class Variante_por_nombre_guarda_costo_y_ganancia_Test extends EmpresaTestCase
{
    use EscenarioDeMonedas;

    /** Tolerancia para comparar importes con decimales. */
    const DELTA = 0.005;

    /** @var string Palabra unica por test: el buscador encuentra solo los articulos de este test. */
    protected $palabra;

    protected function setUp(): void
    {
        parent::setUp();

        $this->preparar_escenario_2r('2015-09-14 10:00:00');

        $this->dar_extension_al_dueno('article_variants');

        $this->palabra = 'zzvarcosto' . substr(uniqid(), -7);
    }

    protected function tearDown(): void
    {
        $this->limpiar_escenario_2r();

        parent::tearDown();
    }

    /**
     * Prende una extension para el duenio del fixture y lo vuelve a autenticar con ella cargada.
     *
     * @param  string $slug
     * @return void
     */
    protected function dar_extension_al_dueno($slug)
    {
        $extencion = ExtencionEmpresa::where('slug', $slug)->first();

        if (is_null($extencion)) {
            $extencion = ExtencionEmpresa::forceCreate([
                'slug' => $slug,
                'name' => $slug,
            ]);
        }

        $this->dueno->extencions()->syncWithoutDetaching([$extencion->id]);

        $this->dueno = User::find($this->dueno->id);
        $this->dueno->load('extencions');

        $this->actingAs($this->dueno, 'web');
    }

    /**
     * Variante de un articulo.
     *
     * @param  \App\Models\Article $article
     * @param  string              $descripcion
     * @param  int|null            $price       Precio propio de la variante (null = usa el del articulo).
     * @param  string|null         $bar_code
     * @return \App\Models\ArticleVariant
     */
    protected function variante($article, $descripcion, $price, $bar_code = null)
    {
        return ArticleVariant::create([
            'article_id'          => $article->id,
            'variant_description' => $descripcion,
            'oculta'              => false,
            'price'               => $price,
            'stock'               => 50,
            'bar_code'            => $bar_code,
        ]);
    }

    /**
     * Articulo con costo, alta por la API (el precio lo calcula el sistema: costo 1000 + 50 % = 1500).
     *
     * @param  string $sufijo
     * @param  array  $campos Columnas a pisar (cost, cost_in_dollars...).
     * @return \App\Models\Article
     */
    protected function articulo_con_costo($sufijo, $campos = [])
    {
        return $this->crear_articulo($this->palabra . ' ' . $sufijo, array_merge([
            'cost'            => 1000,
            'cost_in_dollars' => 0,
            'percentage_gain' => 50,
        ], $campos), 100);
    }

    /**
     * Busca por nombre como el modal de Vender: `POST global-search/article` con contexto `vender`.
     *
     * @return array Filas (`models.data`).
     */
    protected function filas_del_buscador_general()
    {
        $res = $this->postJson('api/global-search/article?page=1', [
            'query_value' => $this->palabra,
            'props'       => ['name', 'provider_code'],
            'contexto'    => 'vender',
            'per_page'    => 50,
        ]);

        $res->assertStatus(200);

        return $res->json('models.data');
    }

    /**
     * Busca por nombre por la ruta vieja de Vender (`VenderController::search_nombre`).
     *
     * @return array Filas (`data`).
     */
    protected function filas_de_la_ruta_vieja()
    {
        $res = $this->postJson('api/vender/buscar-articulo-por-nombre/0', [
            'query_value' => $this->palabra,
        ]);

        $res->assertStatus(200);

        return $res->json('data');
    }

    /**
     * La fila de UNA variante dentro de lo que devolvio el buscador.
     *
     * @param  array                    $filas
     * @param  \App\Models\ArticleVariant $variante
     * @return array
     */
    protected function fila_de($filas, $variante)
    {
        foreach ($filas as $fila) {
            if (!empty($fila['is_variant']) && $fila['variant_id'] == $variante->id) {
                return $fila;
            }
        }

        $this->fail('El buscador no devolvio la variante ' . $variante->id . ' (' . $variante->variant_description . ').');
    }

    /**
     * El precio que la SPA le pone a la linea: `convertir_precio_a_moneda_de_la_venta()` de
     * `mixins/generals.js`, que decide leyendo `item.cost_in_dollars` de la RAIZ del item (con
     * `cotizar_precios_en_dolares = 0`). Si la fila no trae la clave, no convierte: es el defecto.
     *
     * @param  array $fila
     * @param  int   $moneda_id
     * @param  float $valor_dolar
     * @return float
     */
    protected function price_vender_de_la_fila($fila, $moneda_id, $valor_dolar)
    {
        $precio = (float) $fila['final_price'];

        $en_dolares = !empty($fila['cost_in_dollars']);

        if ($moneda_id == 2) {
            return $en_dolares ? $precio : $precio / (float) $valor_dolar;
        }

        return $en_dolares ? $precio * (float) $valor_dolar : $precio;
    }

    /**
     * El item de una variante elegida por nombre, como lo arma la SPA: la fila tal cual
     * (`ArticleName.setSelected`) mas lo que le suman `set_item_vender` y `add_item_to_sale`.
     *
     * @param  array $fila
     * @param  float $amount
     * @param  float $price_vender
     * @return array
     */
    protected function item_de_variante_por_nombre($fila, $amount, $price_vender)
    {
        return array_merge($fila, [
            'is_article'                  => true,
            'article_variant_id'          => $fila['variant_id'],
            'price_type_personalizado_id' => 0,
            'amount'                      => $amount,
            'price_vender'                => $price_vender,
        ]);
    }

    /**
     * Vende una variante elegida por nombre y devuelve la linea de `article_sale`.
     *
     * @param  \App\Models\Article        $articulo
     * @param  \App\Models\ArticleVariant $variante
     * @param  float                      $amount
     * @param  int                        $moneda_id
     * @param  float|null                 $valor_dolar
     * @return object
     */
    protected function vender_por_nombre($articulo, $variante, $amount, $moneda_id = 1, $valor_dolar = null)
    {
        $fila = $this->fila_de($this->filas_del_buscador_general(), $variante);

        $valor = is_null($valor_dolar) ? $this->VALOR_DOLAR : $valor_dolar;

        $items = [$this->item_de_variante_por_nombre($fila, $amount, $this->price_vender_de_la_fila($fila, $moneda_id, $valor))];

        $venta = $this->guardar_venta($this->payload_venta($moneda_id, $valor, $items));

        $linea = $this->pivot_de($venta, $articulo);

        $this->assertNotNull($linea, 'La venta no tiene la linea del articulo.');

        return $linea;
    }

    /**
     * La fila de una variante elegida por nombre trae en la RAIZ el costo del articulo: es lo que
     * `SaleHelper::getCost()` y la conversion de moneda leen. Las dos rutas de busqueda por nombre.
     *
     * @test
     */
    public function la_fila_de_la_variante_por_nombre_trae_el_costo_del_articulo_en_la_raiz()
    {
        $articulo = $this->articulo_con_costo('Zapatilla');
        $azul     = $this->variante($articulo, 'azul 36', 2000);

        $articulo = Article::find($articulo->id);

        $rutas = [
            'buscador general (contexto vender)' => $this->filas_del_buscador_general(),
            'ruta vieja search_nombre'           => $this->filas_de_la_ruta_vieja(),
        ];

        foreach ($rutas as $ruta => $filas) {
            $fila = $this->fila_de($filas, $azul);

            $this->assertArrayHasKey('cost', $fila, $ruta . ': falta `cost` en la raiz de la fila.');
            $this->assertArrayHasKey('costo_real', $fila, $ruta . ': falta `costo_real` en la raiz de la fila.');
            $this->assertArrayHasKey('cost_in_dollars', $fila, $ruta . ': falta `cost_in_dollars` en la raiz de la fila.');

            $this->assertEqualsWithDelta(1000, (float) $fila['cost'], self::DELTA, $ruta);
            $this->assertEquals($articulo->costo_real, $fila['costo_real'], $ruta);
            $this->assertEquals(0, (int) $fila['cost_in_dollars'], $ruta);

            // El costo viaja igual que en el articulo anidado: no se expone nada nuevo.
            $this->assertEquals($fila['article']['cost'], $fila['cost'], $ruta);
        }
    }

    /**
     * El caso reportado: una variante con precio propio elegida por nombre se vende con el costo del
     * articulo y la ganancia es (precio de la variante - costo) x cantidad, no el precio entero.
     *
     * @test
     */
    public function una_variante_con_precio_propio_elegida_por_nombre_se_vende_con_costo_y_ganancia()
    {
        $articulo = $this->articulo_con_costo('Zapatilla');
        $azul     = $this->variante($articulo, 'azul 36', 2000);
        $this->variante($articulo, 'rojo 36', null);

        $linea = $this->vender_por_nombre($articulo, $azul, 2);

        $this->assertNotNull($linea->cost, 'La linea quedo con cost NULL: es el defecto de la variante por nombre.');
        $this->assertEqualsWithDelta(1000, (float) $linea->cost, self::DELTA);
        $this->assertEqualsWithDelta(2000, (float) $linea->price, self::DELTA);
        $this->assertEqualsWithDelta((2000 - 1000) * 2, (float) $linea->ganancia, self::DELTA, 'La ganancia no puede ser el precio entero.');

        // La variante sigue siendo la elegida.
        $this->assertEquals($azul->id, $linea->article_variant_id);
        $this->assertEquals('azul 36', $linea->variant_description);
    }

    /**
     * Una variante SIN precio propio usa el del articulo: el costo y la ganancia salen igual.
     *
     * @test
     */
    public function una_variante_sin_precio_propio_elegida_por_nombre_se_vende_con_costo_y_ganancia()
    {
        $articulo = $this->articulo_con_costo('Zapatilla');
        $this->variante($articulo, 'azul 36', 2000);
        $rojo = $this->variante($articulo, 'rojo 36', null);

        $precio_del_articulo = (float) Article::find($articulo->id)->final_price;

        $linea = $this->vender_por_nombre($articulo, $rojo, 3);

        $this->assertEqualsWithDelta(1000, (float) $linea->cost, self::DELTA);
        $this->assertEqualsWithDelta($precio_del_articulo, (float) $linea->price, self::DELTA);
        $this->assertEqualsWithDelta(($precio_del_articulo - 1000) * 3, (float) $linea->ganancia, self::DELTA);
        $this->assertEquals($rojo->id, $linea->article_variant_id);
    }

    /**
     * Paridad con el escaneo: la MISMA variante, por nombre y por su codigo de barras, guarda el mismo
     * costo y la misma ganancia. Cierra la clase "tres caminos, tres formas de item" para estos dos.
     *
     * @test
     */
    public function la_misma_variante_por_nombre_y_por_codigo_guarda_lo_mismo()
    {
        $articulo = $this->articulo_con_costo('Zapatilla');
        $codigo   = 'zz' . substr(uniqid(), -9);
        $azul     = $this->variante($articulo, 'azul 36', 2000, $codigo);

        // Por nombre.
        $por_nombre = $this->vender_por_nombre($articulo, $azul, 2);

        // Por codigo: como lo arma ArticleBarCode.vue (el articulo completo con la variante encima).
        $res = $this->getJson('api/vender/buscar-articulo-por-codido/' . $codigo);
        $res->assertStatus(200);
        $body = $res->json();

        $this->assertNotEmpty($body['variant_row'], 'El escaneo no devolvio la fila de la variante.');

        $item = array_merge($body['article'], $body['variant_row'], [
            'is_article'                  => true,
            'article_variant_id'          => $body['variant_row']['variant_id'],
            'price_type_personalizado_id' => 0,
            'amount'                      => 2,
            'price_vender'                => $this->price_vender_de_la_fila($body['variant_row'], 1, $this->VALOR_DOLAR),
        ]);

        $venta_por_codigo = $this->guardar_venta($this->payload_venta(1, $this->VALOR_DOLAR, [$item]));
        $por_codigo = $this->pivot_de($venta_por_codigo, $articulo);

        $this->assertEqualsWithDelta((float) $por_codigo->cost, (float) $por_nombre->cost, self::DELTA);
        $this->assertEqualsWithDelta((float) $por_codigo->ganancia, (float) $por_nombre->ganancia, self::DELTA);
        $this->assertEqualsWithDelta((float) $por_codigo->price, (float) $por_nombre->price, self::DELTA);
    }

    /**
     * Un articulo con `unidades_individuales`: el costo de la linea es el del bulto dividido por las
     * unidades. La fila de la variante no trae `unidades_individuales`; la API las lee de la base.
     *
     * @test
     */
    public function el_costo_se_divide_por_las_unidades_individuales_del_articulo()
    {
        $articulo = $this->articulo_con_costo('Pack', ['cost' => 1200]);

        Article::where('id', $articulo->id)->update([
            'costo_real'            => 1200,
            'unidades_individuales' => 12,
        ]);

        $azul = $this->variante($articulo, 'azul 36', 500);

        $linea = $this->vender_por_nombre($articulo, $azul, 4);

        $this->assertEqualsWithDelta(100, (float) $linea->cost, self::DELTA, 'Costo del bulto (1200) / 12 unidades.');
        $this->assertEqualsWithDelta((500 - 100) * 4, (float) $linea->ganancia, self::DELTA);
    }

    /**
     * Articulo en DOLARES, venta en PESOS: el costo se convierte con el valor del dolar de la venta y
     * el precio de la variante (que esta en dolares) tambien. Sin `cost_in_dollars` en la raiz de la
     * fila ninguna de las dos conversiones ocurre.
     *
     * @test
     */
    public function una_variante_de_un_articulo_en_dolares_se_vende_en_pesos_con_costo_y_precio_convertidos()
    {
        $articulo = $this->articulo_con_costo('Importada', ['cost' => 10, 'cost_in_dollars' => 1]);
        $azul     = $this->variante($articulo, 'azul 36', 20);

        $fila = $this->fila_de($this->filas_del_buscador_general(), $azul);
        $this->assertEquals(1, (int) $fila['cost_in_dollars'], 'La fila no marca que el articulo esta en dolares.');

        $linea = $this->vender_por_nombre($articulo, $azul, 2, 1, 1200);

        $this->assertEqualsWithDelta(20 * 1200, (float) $linea->price, self::DELTA, 'El precio de la variante (USD 20) se vende convertido a pesos.');
        $this->assertEqualsWithDelta(10 * 1200, (float) $linea->cost, self::DELTA, 'El costo (USD 10) se convierte con el dolar de la venta.');
        $this->assertEqualsWithDelta((20 * 1200 - 10 * 1200) * 2, (float) $linea->ganancia, self::DELTA);
    }

    /**
     * Articulo en DOLARES, venta en DOLARES: el costo ya esta en la moneda de la venta, no se toca.
     *
     * @test
     */
    public function una_variante_de_un_articulo_en_dolares_se_vende_en_dolares_sin_convertir()
    {
        $articulo = $this->articulo_con_costo('Importada', ['cost' => 10, 'cost_in_dollars' => 1]);
        $azul     = $this->variante($articulo, 'azul 36', 20);

        $linea = $this->vender_por_nombre($articulo, $azul, 2, 2, 1200);

        $this->assertEqualsWithDelta(20, (float) $linea->price, self::DELTA);
        $this->assertEqualsWithDelta(10, (float) $linea->cost, self::DELTA);
        $this->assertEqualsWithDelta((20 - 10) * 2, (float) $linea->ganancia, self::DELTA);
    }

    /**
     * Un articulo SIN costo cargado: la fila trae las claves en null y la venta se guarda igual (no
     * revienta). Es el mismo resultado de siempre para ese caso: sin costo no hay con que calcular.
     *
     * @test
     */
    public function una_variante_de_un_articulo_sin_costo_se_vende_sin_romper()
    {
        $articulo = $this->articulo_con_costo('Sin costo', ['cost' => null]);
        $azul     = $this->variante($articulo, 'azul 36', 700);

        $fila = $this->fila_de($this->filas_del_buscador_general(), $azul);

        $this->assertArrayHasKey('cost', $fila);
        $this->assertNull($fila['cost']);

        $linea = $this->vender_por_nombre($articulo, $azul, 1);

        $this->assertEqualsWithDelta(700, (float) $linea->price, self::DELTA);
        $this->assertEquals($azul->id, $linea->article_variant_id);
    }

    /**
     * Las claves de siempre de la fila de una variante siguen y no cambian de valor: el arreglo es solo
     * aditivo. Y la fila de un articulo SIN variantes no se toca.
     *
     * @test
     */
    public function las_claves_de_siempre_de_la_fila_no_cambian()
    {
        $articulo = $this->articulo_con_costo('Zapatilla');
        $azul     = $this->variante($articulo, 'azul 36', 2000, 'zz' . substr(uniqid(), -9));

        $sin_variantes = $this->articulo_con_costo('Medias');

        $filas = $this->filas_del_buscador_general();
        $fila  = $this->fila_de($filas, $azul);

        $this->assertTrue($fila['is_variant']);
        $this->assertEquals($articulo->id, $fila['id']);
        $this->assertEquals($azul->id, $fila['variant_id']);
        $this->assertEquals('azul 36', $fila['variant_description']);
        $this->assertEquals(2000, $fila['final_price']);
        // El alta por la API normaliza el nombre (primera letra en mayuscula): se compara contra el guardado.
        $this->assertEquals(Article::find($articulo->id)->name . ' azul 36', $fila['name']);
        $this->assertEquals(50, $fila['stock']);
        $this->assertEquals($azul->bar_code, $fila['bar_code']);
        $this->assertEquals($articulo->id, $fila['article']['id']);
        $this->assertArrayHasKey('images', $fila);
        $this->assertArrayHasKey('addresses', $fila);
        $this->assertArrayHasKey('precios_por_metodo_pago', $fila);
        $this->assertArrayHasKey('price_types', $fila);

        // El articulo sin variantes viene como el modelo mismo, sin marca de variante.
        $fila_medias = null;

        foreach ($filas as $f) {
            if ($f['id'] == $sin_variantes->id) {
                $fila_medias = $f;
            }
        }

        $this->assertNotNull($fila_medias, 'El articulo sin variantes tiene que aparecer.');
        $this->assertFalse((bool) $fila_medias['is_variant']);
        $this->assertArrayNotHasKey('variant_id', $fila_medias);
    }
}
