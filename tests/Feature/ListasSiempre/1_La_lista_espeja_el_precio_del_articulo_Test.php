<?php

namespace Tests\Feature\ListasSiempre;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\PriceTypeHelper;
use App\Http\Controllers\Helpers\article\ArticlePricesHelper;
use App\Models\Article;
use App\Models\Category;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * D1 de la misión prueba-listas-siempre: la lista «Precio final» da EL MISMO NÚMERO que daba
 * `articles.final_price` sin listas, en todos los casos que una cadena de listas no reproduce —el
 * margen del proveedor, el de la categoría, el del artículo, el precio manual, el costo en dólares
 * cotizado y sin cotizar, el artículo sin costo ni precio—, y lo sigue dando cuando un margen cambia.
 *
 * El método: el mismo dueño calcula cada artículo SIN listas (el cliente de hoy), se lo pasa a «siempre
 * con listas» y se vuelve a calcular. El precio final no se mueve, el pivote de la lista es ese número y
 * el resolvedor de Vender devuelve ese número.
 */
class La_lista_espeja_el_precio_del_articulo_Test extends ListasSiempreTestCase
{
    /**
     * Los artículos con los cuatro márgenes y el manual, para un dueño.
     *
     * @param  \App\Models\User  $dueno
     * @return array  [clave => Article]
     */
    protected function catalogo($dueno)
    {
        $proveedor = Provider::create(array('name' => 'zz Proveedor con margen '.uniqid(), 'user_id' => $dueno->id, 'percentage_gain' => 30));
        $categoria = Category::create(array('name' => 'zz Categoría con margen '.uniqid(), 'user_id' => $dueno->id, 'percentage_gain' => 25));

        $catalogo = array(
            'margen_del_articulo' => $this->articulo($dueno, 'margen del artículo', array('cost' => 1000, 'percentage_gain' => 50)),
            'margen_del_proveedor' => $this->articulo($dueno, 'margen del proveedor', array('cost' => 1000, 'percentage_gain' => 10, 'provider_id' => $proveedor->id, 'apply_provider_percentage_gain' => 1)),
            'margen_de_la_categoria' => $this->articulo($dueno, 'margen de la categoría', array('cost' => 1000, 'category_id' => $categoria->id)),
            'precio_manual' => $this->articulo($dueno, 'precio manual', array('cost' => 1000, 'price' => 1234.56)),
            'costo_en_dolares' => $this->articulo($dueno, 'costo en dólares', array('cost' => 10, 'cost_in_dollars' => 1, 'percentage_gain' => 20)),
            'sin_costo_ni_precio' => $this->articulo($dueno, 'sin costo ni precio', array()),
        );

        // El que no tiene costo ni precio conserva el precio final que tuviera (el retorno temprano).
        DB::table('articles')->where('id', $catalogo['sin_costo_ni_precio']->id)->update(array('final_price' => 777));

        return $catalogo;
    }

    /**
     * Calcula cada artículo sin listas y devuelve los precios finales.
     *
     * @param  array             $catalogo
     * @param  \App\Models\User  $dueno
     * @return array  [clave => float|null]
     */
    protected function precios_sin_listas(array $catalogo, $dueno)
    {
        $precios = array();

        foreach ($catalogo as $clave => $articulo) {
            $this->recalcular($articulo, $dueno);
            $precios[$clave] = $this->final_price_de($articulo);
        }

        return $precios;
    }

    /** @test */
    public function el_mismo_numero_con_los_cuatro_margenes_el_manual_y_los_dolares()
    {
        $dueno = $this->dueno_nuevo('espejo');

        $catalogo = $this->catalogo($dueno);
        $sin_listas = $this->precios_sin_listas($catalogo, $dueno);

        // Que los casos sean distintos entre sí (si no, el test no distinguiría nada).
        $this->assertGreaterThan(1000, $sin_listas['margen_del_articulo']);
        $this->assertNotEquals($sin_listas['margen_del_articulo'], $sin_listas['margen_del_proveedor'], 'El margen del proveedor tiene que notarse.');
        $this->assertNotEquals($sin_listas['margen_del_articulo'], $sin_listas['margen_de_la_categoria'], 'El margen de la categoría tiene que notarse.');
        $this->assertEqualsWithDelta(1234.56, $sin_listas['precio_manual'], self::DELTA, 'El precio manual manda tal cual.');
        $this->assertGreaterThan(10000, $sin_listas['costo_en_dolares'], 'El costo en dólares se cotiza a pesos.');
        $this->assertEqualsWithDelta(777, $sin_listas['sin_costo_ni_precio'], self::DELTA);

        list($dueno, $lista) = $this->con_precio_final($dueno);

        $this->assertSame(1, (int) $lista->es_precio_final);
        $this->assertSame(PriceTypeHelper::NOMBRE_PRECIO_FINAL, $lista->name);

        foreach ($catalogo as $clave => $articulo) {

            $this->recalcular($articulo, $dueno);

            $this->assertMismoPrecio($sin_listas[$clave], $this->final_price_de($articulo), 'Con la lista «Precio final» el precio final del caso "'.$clave.'" tiene que ser el mismo.');

            $pivote = $this->pivote($articulo, $lista);

            $this->assertNotNull($pivote, 'Falta el pivote de «Precio final» del caso "'.$clave.'".');
            $this->assertMismoPrecio($sin_listas[$clave], is_null($pivote->final_price) ? null : (float) $pivote->final_price, 'El pivote de «Precio final» del caso "'.$clave.'" tiene que ser el precio final.');

            $resuelto = ArticlePricesHelper::resolver_precio_de_venta(Article::find($articulo->id), User::find($dueno->id));

            $this->assertSame('lista_por_defecto', $resuelto['origen'], 'Vender tiene que resolver por la lista.');
            $this->assertSame((int) $lista->id, (int) $resuelto['price_type_id']);
            $this->assertMismoPrecio($sin_listas[$clave], $resuelto['final_price'], 'Lo que Vender cobra en el caso "'.$clave.'" tiene que ser el precio final de siempre.');
        }

        // El pivote lleva el margen del artículo y la marca de precio manual, que es lo que la tarjeta muestra.
        $this->assertEqualsWithDelta(50, (float) $this->pivote($catalogo['margen_del_articulo'], $lista)->percentage, 0.001);
        $this->assertSame(0, (int) $this->pivote($catalogo['margen_del_articulo'], $lista)->setear_precio_final);
        $this->assertSame(1, (int) $this->pivote($catalogo['precio_manual'], $lista)->setear_precio_final);
        $this->assertNotNull($this->pivote($catalogo['margen_del_articulo'], $lista)->monto_ganancia, 'La ganancia se calcula como en cualquier lista.');
        $this->assertNull($this->pivote($catalogo['sin_costo_ni_precio'], $lista)->monto_ganancia, 'Sin costo no hay ganancia que mostrar.');
    }

    /** @test */
    public function sigue_al_margen_del_proveedor_cuando_cambia()
    {
        $dueno = $this->dueno_nuevo('proveedor');

        $proveedor = Provider::create(array('name' => 'zz Proveedor que cambia '.uniqid(), 'user_id' => $dueno->id, 'percentage_gain' => 30));
        $articulo = $this->articulo($dueno, 'sigue al proveedor', array('cost' => 1000, 'provider_id' => $proveedor->id, 'apply_provider_percentage_gain' => 1));

        list($dueno, $lista) = $this->con_precio_final($dueno);

        $this->recalcular($articulo, $dueno);
        $antes = $this->final_price_de($articulo);

        $proveedor->percentage_gain = 60;
        $proveedor->save();

        $this->recalcular($articulo, $dueno);
        $despues = $this->final_price_de($articulo);

        $this->assertGreaterThan($antes, $despues, 'Subir el margen del proveedor sube el precio final.');
        $this->assertMismoPrecio($despues, (float) $this->pivote($articulo, $lista)->final_price, 'La lista «Precio final» lo sigue: no queda "horneada" con el margen viejo.');
    }

    /** @test */
    public function con_el_costo_en_dolares_sin_cotizar_el_precio_queda_en_dolares_como_hoy()
    {
        $dueno = $this->dueno_nuevo('sin-cotizar', array('cotizar_precios_en_dolares' => 0));

        $articulo = $this->articulo($dueno, 'en dólares sin cotizar', array('cost' => 10, 'cost_in_dollars' => 1, 'percentage_gain' => 20));

        $this->recalcular($articulo, $dueno);
        $sin_listas = $this->final_price_de($articulo);

        $this->assertLessThan(100, $sin_listas, 'Sin cotizar, el precio final queda en dólares (chico).');

        list($dueno, $lista) = $this->con_precio_final($dueno);

        $this->recalcular($articulo, $dueno);

        $this->assertMismoPrecio($sin_listas, $this->final_price_de($articulo));
        $this->assertMismoPrecio($sin_listas, (float) $this->pivote($articulo, $lista)->final_price, 'El pivote va en la moneda del artículo, como la columna.');
        $this->assertSame(2, PriceTypeHelper::moneda_nativa_del_precio(Article::find($articulo->id), User::find($dueno->id)));
    }

    /**
     * Una cuenta que además tiene otras listas: las otras siguen con su cadena (base de antes de los
     * márgenes, con el margen de la lista) y la «Precio final» sigue siendo el precio del artículo,
     * también cuando el artículo tiene precio manual (antes, con listas, el manual se ignoraba y las
     * listas no se calculaban en esa rama).
     *
     * @test
     */
    public function con_otras_listas_cada_una_sigue_su_cadena_y_el_manual_manda_en_precio_final()
    {
        $dueno = $this->dueno_nuevo('dos-listas');

        $con_margen = $this->articulo($dueno, 'con margen', array('cost' => 1000, 'percentage_gain' => 50));
        $manual = $this->articulo($dueno, 'manual con otras listas', array('cost' => 1000, 'price' => 1234.56));

        $this->recalcular($con_margen, $dueno);
        $this->recalcular($manual, $dueno);
        $precio_con_margen = $this->final_price_de($con_margen);

        list($dueno, $precio_final) = $this->con_precio_final($dueno);

        // Con position por debajo de la «Precio final» (que nace con 1): la lista por defecto sigue
        // siendo la de mayor position, como siempre, y acá es la «Precio final».
        $mayorista = $this->otra_lista($dueno, 'zz Mayorista', 100, 0);

        $this->recalcular($con_margen, $dueno);
        $this->recalcular($manual, $dueno);

        $this->assertMismoPrecio($precio_con_margen, $this->final_price_de($con_margen));
        $this->assertMismoPrecio($precio_con_margen, (float) $this->pivote($con_margen, $precio_final)->final_price);

        $pivote_mayorista = $this->pivote($con_margen, $mayorista);
        $this->assertNotNull($pivote_mayorista);
        $this->assertNotEqualsWithDelta($precio_con_margen, (float) $pivote_mayorista->final_price, self::DELTA, 'La lista común tiene su propio precio (margen 100 % sobre la base).');
        $this->assertEqualsWithDelta(100, (float) $pivote_mayorista->percentage, 0.001);

        $this->assertEqualsWithDelta(1234.56, $this->final_price_de($manual), self::DELTA, 'El precio manual manda aunque la cuenta tenga otras listas.');
        $this->assertEqualsWithDelta(1234.56, (float) $this->pivote($manual, $precio_final)->final_price, self::DELTA);
        $this->assertSame(1, (int) $this->pivote($manual, $precio_final)->setear_precio_final);
        $this->assertNotNull($this->pivote($manual, $mayorista), 'Las otras listas se calculan también para un artículo con precio manual.');
        $this->assertNotNull($this->pivote($manual, $mayorista)->final_price);

        // La «Precio final» es la lista por defecto: position por encima de las demás.
        $resuelto = ArticlePricesHelper::resolver_precio_de_venta(Article::find($con_margen->id), User::find($dueno->id));
        $this->assertSame((int) $precio_final->id, (int) $resuelto['price_type_id']);
    }

    /**
     * D3: «Precio final» existe en el motor por moneda (extensión `ventas_en_dolares`): la fila de la
     * moneda nativa lleva el precio del artículo, la otra se deriva con el dólar de la cuenta y queda
     * marcada «cotizar desde la otra moneda», y el precio final sigue siendo el de una cuenta sin listas
     * (cotizado a pesos cuando el dueño cotiza).
     *
     * @test
     */
    public function en_el_motor_por_moneda_la_fila_nativa_es_el_precio_y_la_otra_se_cotiza()
    {
        $dueno = $this->dueno_nuevo('monedas');
        $dueno = $this->con_extension($dueno, 'ventas_en_dolares');

        $en_pesos = $this->articulo($dueno, 'en pesos con monedas', array('cost' => 1000, 'percentage_gain' => 50));
        $en_dolares = $this->articulo($dueno, 'en dólares con monedas', array('cost' => 10, 'cost_in_dollars' => 1, 'percentage_gain' => 20));

        $this->recalcular($en_pesos, $dueno);
        $this->recalcular($en_dolares, $dueno);
        $precio_pesos = $this->final_price_de($en_pesos);
        $precio_dolares = $this->final_price_de($en_dolares);

        $this->assertGreaterThan(10000, $precio_dolares, 'Sin listas, el costo en dólares se cotiza a pesos.');

        list($dueno, $lista) = $this->con_precio_final($dueno);

        $this->recalcular($en_pesos, $dueno);
        $this->recalcular($en_dolares, $dueno);

        foreach (array('en_pesos' => array($en_pesos, $precio_pesos), 'en_dolares' => array($en_dolares, $precio_dolares)) as $clave => $par) {

            list($articulo, $precio) = $par;

            $this->assertMismoPrecio($precio, $this->final_price_de($articulo), 'El precio final no cambia con el motor por moneda ('.$clave.').');
            $this->assertMismoPrecio($precio, (float) $this->pivote($articulo, $lista)->final_price, 'El pivote en pesos es el precio final ('.$clave.').');

            $pesos = $this->fila_moneda($articulo, $lista, 1);
            $usd = $this->fila_moneda($articulo, $lista, 2);

            $this->assertNotNull($pesos, 'Falta la fila en pesos ('.$clave.').');
            $this->assertNotNull($usd, 'Falta la fila en dólares ('.$clave.').');

            $this->assertMismoPrecio($precio, (float) $pesos->final_price);
            $this->assertSame(0, (int) $pesos->cotizar_desde_otra_moneda, 'Con el dueño cotizando a pesos, la fila nativa es la de pesos.');
            $this->assertEqualsWithDelta($precio / 1000, (float) $usd->final_price, 0.01, 'La fila en dólares se deriva con el dólar de la cuenta ('.$clave.').');
            $this->assertSame(1, (int) $usd->cotizar_desde_otra_moneda);
        }
    }
}
