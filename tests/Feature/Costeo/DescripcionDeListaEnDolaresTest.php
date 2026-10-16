<?php

namespace Tests\Feature\Costeo;

use App\Http\Controllers\Helpers\Numbers;
use App\Models\Moneda;
use App\Models\PriceType;
use Tests\EmpresaTestCase;
use Tests\Feature\VentasEnDolares\EscenarioDeMonedas;

/**
 * El "?" de la tarjeta de una lista de precio en una cuenta con `ventas_en_dolares`.
 *
 * Antes de esta mision el endpoint `article/price-type-description/{id}/{price_type_id}` contestaba,
 * para una cuenta con dolares, el desglose del precio final UNICO y nada de la lista: el calculo de
 * las listas en esas cuentas va por ArticlePriceTypeMonedaHelper, que no describia nada. Ahora el
 * front manda `?moneda_id=` y la respuesta trae el tramo de ESA lista en ESA moneda
 * (ArticlePriceTypeMonedaHelper::describir_lista_en_moneda()).
 *
 * Lo que se verifica es que el desglose cuente lo que el calculo hizo -no que invente otra cuenta-:
 * los numeros que se comparan salen de lo que quedo guardado en `article_price_type_monedas`.
 *
 * Escenario: dolar de la cuenta en 1000, articulo con costo 1000 en pesos, sin IVA ni percepciones.
 *
 * @group costeo
 * @group ventas-en-dolares
 */
class DescripcionDeListaEnDolaresTest extends EmpresaTestCase
{
    use EscenarioDeMonedas;

    /** @var \App\Models\PriceType Lista de la cuenta con la que se arman las entradas. */
    protected $lista;

    protected function setUp(): void
    {
        parent::setUp();

        $this->preparar_escenario_2r('2015-03-09 10:00:00', ['listas_de_precio' => 1, 'dollar' => 1000]);

        $this->lista = PriceType::where('user_id', $this->dueno->id)->first();

        if (is_null($this->lista)) {
            $this->lista = PriceType::forceCreate([
                'name'     => 'Mayorista zz',
                'position' => 1,
                'user_id'  => $this->dueno->id,
            ]);
        }
    }

    protected function tearDown(): void
    {
        $this->limpiar_escenario_2r();

        parent::tearDown();
    }

    /**
     * Crea un articulo (por la API, para que el calculo corra de verdad) con una entrada por moneda
     * en la lista del escenario.
     *
     * @param  array  $pesos   Campos de la entrada en pesos (moneda 1).
     * @param  array  $dolares Campos de la entrada en dolares (moneda 2).
     * @return \App\Models\Article
     */
    protected function articulo_con_listas($pesos, $dolares)
    {
        $base = [
            'price_type_id'             => $this->lista->id,
            'percentage'                => 0,
            'final_price'               => 0,
            'setear_precio_final'       => 0,
            'cotizar_desde_otra_moneda' => 0,
        ];

        return $this->crear_articulo('zz Articulo listas en dolares', [
            'cost'               => 1000,
            'percentage_gain'    => 0,
            'price_type_monedas' => [
                array_merge($base, ['moneda_id' => $this->PESO],  $pesos),
                array_merge($base, ['moneda_id' => $this->DOLAR], $dolares),
            ],
        ]);
    }

    /**
     * Pide el "?" de la lista del escenario para una moneda (o sin moneda).
     *
     * @param  \App\Models\Article  $articulo
     * @param  int|null  $moneda_id
     * @return \Illuminate\Testing\TestResponse
     */
    protected function pedir_desglose($articulo, $moneda_id = null)
    {
        $url = 'api/article/price-type-description/'.$articulo->id.'/'.$this->lista->id;

        if (!is_null($moneda_id)) {
            $url .= '?moneda_id='.$moneda_id;
        }

        return $this->getJson($url);
    }

    /**
     * Las lineas del desglose que son de un tipo.
     *
     * @param  array   $detalle
     * @param  string  $tipo
     * @return array
     */
    protected function lineas_de_tipo($detalle, $tipo)
    {
        return array_values(array_filter($detalle, function ($linea) use ($tipo) {
            return $linea['tipo'] === $tipo;
        }));
    }

    /**
     * El precio guardado de la entrada de una moneda, releido de la base (el endpoint recalcula y
     * guarda: lo que cuenta es lo que quedo despues).
     *
     * @param  \App\Models\Article  $articulo
     * @param  int  $moneda_id
     * @return \App\Models\ArticlePriceTypeMoneda
     */
    protected function entrada_guardada($articulo, $moneda_id)
    {
        return $articulo->price_type_monedas()
            ->where('price_type_id', $this->lista->id)
            ->where('moneda_id', $moneda_id)
            ->first();
    }

    /**
     * Una lista en pesos con margen: costo, margen, precio con el margen y total, con los numeros
     * que quedaron guardados.
     *
     * @test
     */
    public function el_desglose_de_una_lista_en_pesos_cuenta_costo_margen_y_total()
    {
        $articulo = $this->articulo_con_listas(
            ['percentage' => 50],
            ['percentage' => 20]
        );

        $response = $this->pedir_desglose($articulo, $this->PESO);
        $response->assertStatus(200);

        $detalle = $response->json('detalle');
        $guardada = $this->entrada_guardada($articulo, $this->PESO);

        $this->assertEquals(1500, (float) $guardada->final_price, 'el escenario tiene que dar 1000 + 50%');

        $secciones = $this->lineas_de_tipo($detalle, 'seccion');
        $nombre_moneda = Moneda::find($this->PESO)->name;
        $titulos = array_column($secciones, 'etiqueta');

        $this->assertContains(
            'Precio de la lista '.$this->lista->name.' en '.$nombre_moneda,
            $titulos,
            'tiene que haber una seccion con el nombre de la lista y la moneda'
        );

        $costo = $this->lineas_de_tipo($detalle, 'costo');
        $this->assertSame(Numbers::price(1000, true), end($costo)['valor']);

        $margenes = array_column($this->lineas_de_tipo($detalle, 'margen'), 'valor');
        $this->assertContains('50%', $margenes);
        $this->assertContains(Numbers::price(1500, true), $margenes, 'precio con el margen aplicado');

        $total = $this->lineas_de_tipo($detalle, 'total');
        $this->assertSame(Numbers::price(1500, true), end($total)['valor'], 'el total es el precio guardado');

        $this->assertSame(
            count($detalle),
            count($response->json('description')),
            '`description` se deriva de `detalle`: una sola emision'
        );
    }

    /**
     * En dolares, el costo de un articulo cargado en pesos se lleva a dolares con el dolar de la
     * cuenta, y el desglose lo dice.
     *
     * @test
     */
    public function el_costo_en_dolares_aclara_que_se_llevo_a_dolares_con_el_dolar_de_la_cuenta()
    {
        $articulo = $this->articulo_con_listas(
            ['percentage' => 50],
            ['percentage' => 20]
        );

        $response = $this->pedir_desglose($articulo, $this->DOLAR);
        $response->assertStatus(200);

        $detalle = $response->json('detalle');
        $guardada = $this->entrada_guardada($articulo, $this->DOLAR);

        $this->assertEqualsWithDelta(1.2, (float) $guardada->final_price, 1e-6, '1000 pesos a $1000 = 1 dolar, mas 20%');

        $costo = $this->lineas_de_tipo($detalle, 'costo');
        $this->assertSame(Numbers::price(1, true), end($costo)['valor']);
        $this->assertStringContainsString('llevado a', end($costo)['detalle']);

        $total = $this->lineas_de_tipo($detalle, 'total');
        $this->assertSame(Numbers::price($guardada->final_price, true), end($total)['valor']);
    }

    /**
     * La moneda que se cotiza desde la otra: el desglose muestra el precio de la otra moneda y la
     * cotizacion que lo lleva a esta, y el total es el precio guardado.
     *
     * @test
     */
    public function una_moneda_cotizada_desde_la_otra_muestra_la_cotizacion()
    {
        // Pesos derivados de dolares: el dolar con 20% (1,2 USD) x $1000 = $1.200.
        $articulo = $this->articulo_con_listas(
            ['cotizar_desde_otra_moneda' => 1],
            ['percentage' => 20]
        );

        $response = $this->pedir_desglose($articulo, $this->PESO);
        $response->assertStatus(200);

        $detalle = $response->json('detalle');
        $guardada = $this->entrada_guardada($articulo, $this->PESO);

        $this->assertEqualsWithDelta(1200, (float) $guardada->final_price, 1e-6, 'USD 1,2 x $1000');

        $cotizaciones = $this->lineas_de_tipo($detalle, 'cotizacion');
        $this->assertCount(1, $cotizaciones);
        $this->assertSame('Cotizado desde '.Moneda::find($this->DOLAR)->name, $cotizaciones[0]['etiqueta']);
        $this->assertStringContainsString('multiplicado por', $cotizaciones[0]['detalle']);
        $this->assertSame(Numbers::price($guardada->final_price, true), $cotizaciones[0]['valor']);

        // El tramo de la otra moneda tambien se cuenta (su margen del 20%).
        $this->assertContains('20%', array_column($this->lineas_de_tipo($detalle, 'margen'), 'valor'));

        $total = $this->lineas_de_tipo($detalle, 'total');
        $this->assertSame(Numbers::price($guardada->final_price, true), end($total)['valor']);
    }

    /**
     * Un precio fijado a mano se informa como tal y el margen sale de ese precio: no hay paso de
     * "precio con el margen aplicado".
     *
     * @test
     */
    public function un_precio_fijado_a_mano_se_informa_como_manual()
    {
        $articulo = $this->articulo_con_listas(
            ['setear_precio_final' => 1, 'final_price' => 2000],
            ['percentage' => 20]
        );

        $response = $this->pedir_desglose($articulo, $this->PESO);
        $response->assertStatus(200);

        $detalle = $response->json('detalle');

        $manuales = $this->lineas_de_tipo($detalle, 'precio_manual');
        $etiquetas = array_column($manuales, 'etiqueta');

        $this->assertContains('Precio fijado a mano', $etiquetas);
        $this->assertContains('Margen resultante', $etiquetas);
        $this->assertNotContains(
            'Precio con el margen aplicado',
            array_column($this->lineas_de_tipo($detalle, 'margen'), 'etiqueta'),
            'con precio a mano no se aplica ningun margen'
        );

        $total = $this->lineas_de_tipo($detalle, 'total');
        $this->assertSame(Numbers::price(2000, true), end($total)['valor']);
    }

    /**
     * Compatibilidad hacia atras: sin `moneda_id` (un front viejo) la respuesta es la de siempre,
     * sin tramo de lista, y no rompe.
     *
     * @test
     */
    public function sin_moneda_id_la_respuesta_no_trae_el_tramo_de_la_lista()
    {
        $articulo = $this->articulo_con_listas(['percentage' => 50], ['percentage' => 20]);

        $response = $this->pedir_desglose($articulo, null);
        $response->assertStatus(200);

        $this->assertNotEmpty($response->json('description'));
        $this->assertStringNotContainsString(
            'CALCULO DEL PRECIO DE LA LISTA',
            implode(' | ', $response->json('description'))
        );
    }

    /**
     * Una moneda que el articulo no tiene en esa lista no rompe nada: contesta 200 sin el tramo.
     *
     * @test
     */
    public function una_moneda_inexistente_no_rompe_y_no_trae_el_tramo_de_la_lista()
    {
        $articulo = $this->articulo_con_listas(['percentage' => 50], ['percentage' => 20]);

        $response = $this->pedir_desglose($articulo, 999);
        $response->assertStatus(200);

        $this->assertStringNotContainsString(
            'CALCULO DEL PRECIO DE LA LISTA',
            implode(' | ', $response->json('description'))
        );
    }
}
