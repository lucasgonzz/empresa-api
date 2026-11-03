<?php

namespace Tests\Feature\ListasSiempre;

use App\Exports\ArticleClientsExport;
use App\Exports\ArticleExport;
use App\Http\Controllers\Helpers\ExportHelper;
use App\Http\Controllers\Helpers\PriceTypeHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\article\ArticlePriceTypeMonedaHelper;
use App\Http\Controllers\Helpers\article\ArticlePricesHelper;
use App\Http\Controllers\Helpers\article\precios\PrecioFinalEnMasivaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\MargenesPorListaIaHelper;
use App\Http\Controllers\Helpers\contabilidad\EstadoResultadosHelper;
use App\Http\Controllers\Pdf\ClientsPdf;
use App\Mail\Advise;
use App\Models\Article;
use App\Models\User;
use App\Services\OfertasClientes\TechoDeDescuentoService;
use Illuminate\Support\Facades\DB;

/**
 * Correcciones del chequeo independiente de la misión prueba-listas-siempre (3/11/2026): los caminos
 * FUERA de setFinalPrice() donde un negocio migrado perdía un precio o veía cambiar uno. El espejo estaba
 * bien; lo que decidía con el flag crudo, no. Los tres primeros casos son las reproducciones del
 * verificador, sin retocar.
 *
 * El Excel con import real está en tests/Import/ListasSiempreExcelConPrecioFinalTest.php.
 */
class Caminos_fuera_de_setFinalPrice_Test extends ListasSiempreTestCase
{
    protected function tearDown(): void
    {
        ExportHelper::clear_article_export_owner_user();

        parent::tearDown();
    }

    /**
     * El payload que arma la SPA para un artículo NUEVO (models/article.js → set_article_price_types):
     * todas las listas con pivot { setear_precio_final: <el de la lista>, incluir..., visible_en_tienda: 0 }.
     */
    protected function payload_de_alta($lista, array $overrides = array())
    {
        return array_merge(array(
            'name'               => 'zz nuevo '.uniqid(),
            'cost'               => 1000,
            'cost_incluye_iva'   => 0,
            'price'              => 500,
            'percentage_gain'    => null,
            'apply_provider_percentage_gain' => 0,
            'iva_id'             => 2,
            'aplicar_iva'        => 1,
            'online'             => 1,
            'precio_pausado'     => 0,
            'in_offer'           => 0,
            'default_in_vender'  => 0,
            'status'             => 'active',
            'price_types'        => array(array(
                'id'    => $lista->id,
                'pivot' => array(
                    'incluir_en_excel_para_clientes' => 1,
                    'setear_precio_final'            => (int) $lista->setear_precio_final,
                    'visible_en_tienda'              => 0,
                ),
            )),
            'price_type_monedas' => array(),
            'tags'               => array(),
            'addresses'          => array(),
        ), $overrides);
    }

    protected function alta($dueno, array $payload)
    {
        $this->actingAs($dueno, 'web');

        $respuesta = $this->postJson('api/article', $payload);
        $respuesta->assertStatus(201);

        return DB::table('articles')->where('id', $respuesta->json('model.id'))->first();
    }

    protected function contexto_de($dueno)
    {
        $contexto = new ContextoDeCargaIa();
        $contexto->owner = $dueno;
        $contexto->owner_id = (int) $dueno->id;
        $contexto->persona = $dueno;
        $contexto->usa_dolares = false;

        return $contexto;
    }

    // ═══ B1 — el alta ═════════════════════════════════════════════════════════════════════════

    /** @test */
    public function un_articulo_nuevo_con_precio_manual_lo_conserva_con_una_sola_lista()
    {
        list($dueno, $lista) = $this->con_precio_final($this->dueno_nuevo('alta-manual'));

        $fila = $this->alta($dueno, $this->payload_de_alta($lista));

        $this->assertEqualsWithDelta(500, (float) $fila->price, self::DELTA, 'El precio manual tipeado en el alta queda en articles.price.');
        $this->assertEqualsWithDelta(500, (float) $fila->final_price, self::DELTA, 'Y el precio final es ese precio manual.');
        $this->assertEqualsWithDelta(500, (float) $this->pivote($fila->id, $lista)->final_price, self::DELTA);
        $this->assertSame(1, (int) $this->pivote($fila->id, $lista)->setear_precio_final);
    }

    /** @test */
    public function un_articulo_nuevo_con_margen_conserva_el_margen()
    {
        list($dueno, $lista) = $this->con_precio_final($this->dueno_nuevo('alta-margen'));

        $fila = $this->alta($dueno, $this->payload_de_alta($lista, array('price' => null, 'percentage_gain' => 30)));

        $this->assertEqualsWithDelta(30, (float) $fila->percentage_gain, self::DELTA);
        $this->assertNull($fila->price);
        $this->assertGreaterThan(1000, (float) $fila->final_price);
        $this->assertMismoPrecio((float) $fila->final_price, (float) $this->pivote($fila->id, $lista)->final_price);
    }

    /** @test */
    public function una_lista_con_setear_por_defecto_no_le_borra_el_precio_al_alta()
    {
        list($dueno, $lista) = $this->con_precio_final($this->dueno_nuevo('alta-setear'));

        DB::table('price_types')->where('id', $lista->id)->update(array('setear_precio_final' => 1));
        $lista->setear_precio_final = 1;

        $fila = $this->alta($dueno, $this->payload_de_alta($lista));

        $this->assertEqualsWithDelta(500, (float) $fila->price, self::DELTA, 'Tildado sin precio: no hay nada que fijar y el del recuadro queda.');
    }

    // ═══ B2 — la masiva ═══════════════════════════════════════════════════════════════════════

    /** @test */
    public function la_masiva_de_precio_final_sigue_moviendo_el_margen_y_el_manual_y_no_el_costo()
    {
        $dueno = $this->dueno_nuevo('masiva');

        $con_margen = $this->articulo($dueno, 'con margen', array('cost' => 1000, 'percentage_gain' => 30));
        $con_manual = $this->articulo($dueno, 'con manual', array('cost' => 1000, 'price' => 2500));

        $antes = PrecioFinalEnMasivaHelper::comercio($dueno);
        $this->assertSame(PrecioFinalEnMasivaHelper::PALANCA_MARGEN, PrecioFinalEnMasivaHelper::palanca(Article::find($con_margen->id), $antes));
        $this->assertSame(PrecioFinalEnMasivaHelper::PALANCA_PRECIO_MANUAL, PrecioFinalEnMasivaHelper::palanca(Article::find($con_manual->id), $antes));

        list($dueno, $lista) = $this->con_precio_final($dueno);
        $despues = PrecioFinalEnMasivaHelper::comercio($dueno);

        $this->assertSame(PrecioFinalEnMasivaHelper::PALANCA_MARGEN, PrecioFinalEnMasivaHelper::palanca(Article::find($con_margen->id), $despues));
        $this->assertSame(PrecioFinalEnMasivaHelper::PALANCA_PRECIO_MANUAL, PrecioFinalEnMasivaHelper::palanca(Article::find($con_manual->id), $despues));

        // Corrida real del ítem: sube 10 % sin tocar el costo.
        $form = array('type' => PrecioFinalEnMasivaHelper::TIPO, 'key' => PrecioFinalEnMasivaHelper::SUBIR, 'value' => 10);

        $cambio = PrecioFinalEnMasivaHelper::aplicar(Article::find($con_manual->id), $form, $dueno);
        $this->assertSame('price', $cambio['prop_key']);
        $this->assertEqualsWithDelta(2750, (float) DB::table('articles')->where('id', $con_manual->id)->value('price'), self::DELTA);

        $cambio = PrecioFinalEnMasivaHelper::aplicar(Article::find($con_margen->id), $form, $dueno);
        $this->assertSame('percentage_gain', $cambio['prop_key']);

        $this->assertEqualsWithDelta(1000, (float) DB::table('articles')->where('id', $con_margen->id)->value('cost'), self::DELTA, 'El costo no se mueve.');
        $this->assertEqualsWithDelta(1000, (float) DB::table('articles')->where('id', $con_manual->id)->value('cost'), self::DELTA, 'El costo no se mueve.');
    }

    /** @test */
    public function con_otras_listas_el_margen_y_el_manual_siguen_siendo_la_palanca_de_precio_final()
    {
        list($dueno, $lista) = $this->con_precio_final($this->dueno_nuevo('masiva-dos'));
        $this->otra_lista($dueno, 'zz Mayorista masiva', 100, 0);

        $comercio = PrecioFinalEnMasivaHelper::comercio($dueno);

        $this->assertTrue($comercio['otras_listas'], 'La tarjeta del asistente tiene que poder avisar que las otras no se mueven.');

        $con_manual = $this->articulo($dueno, 'con manual', array('cost' => 1000, 'price' => 2500));

        $this->assertSame(PrecioFinalEnMasivaHelper::PALANCA_PRECIO_MANUAL, PrecioFinalEnMasivaHelper::palanca(Article::find($con_manual->id), $comercio));
    }

    /** @test */
    public function una_cuenta_con_listas_propias_sigue_moviendo_el_costo()
    {
        $dueno = $this->dueno_nuevo('masiva-propias', array('listas_de_precio' => 1));
        $this->otra_lista($dueno, 'zz Propia masiva');

        $con_margen = $this->articulo($dueno, 'con margen', array('cost' => 1000, 'percentage_gain' => 30));

        $comercio = PrecioFinalEnMasivaHelper::comercio($dueno);

        $this->assertTrue($comercio['listas_de_precio']);
        $this->assertSame(PrecioFinalEnMasivaHelper::PALANCA_COSTO, PrecioFinalEnMasivaHelper::palanca(Article::find($con_margen->id), $comercio));
    }

    // ═══ B3 — el Excel que se exporta ═════════════════════════════════════════════════════════

    /** @test */
    public function con_una_sola_lista_el_excel_trae_las_columnas_del_articulo()
    {
        list($dueno, $lista) = $this->con_precio_final($this->dueno_nuevo('excel-una'));
        $this->actingAs($dueno, 'web');

        $export = new ArticleExport(collect(), $dueno->id);
        ExportHelper::set_article_export_owner_user($dueno);

        $headings = $export->headings();

        foreach (array('Margen de ganancia', 'Precio', 'Precio Final', 'Precio Final Anterior') as $columna) {
            $this->assertContains($columna, $headings, 'Con una sola lista el Excel sigue trayendo «'.$columna.'».');
        }

        $this->assertNotContains('% '.$lista->name, $headings);
        $this->assertNotContains('$ Final '.$lista->name, $headings);
        $this->assertNotContains('Setear precio final '.$lista->name, $headings);
    }

    /** @test */
    public function desde_la_segunda_lista_el_excel_trae_las_columnas_por_lista()
    {
        list($dueno, $lista) = $this->con_precio_final($this->dueno_nuevo('excel-dos'));
        $this->otra_lista($dueno, 'zz Mayorista excel', 100, 0);
        $this->actingAs($dueno, 'web');

        $export = new ArticleExport(collect(), $dueno->id);
        ExportHelper::set_article_export_owner_user($dueno);

        $headings = $export->headings();

        $this->assertContains('% '.$lista->name, $headings);
        $this->assertContains('% zz Mayorista excel', $headings);
        $this->assertNotContains('Margen de ganancia', $headings);
    }

    /** @test */
    public function el_excel_para_clientes_conserva_precio_anterior_y_variacion_con_una_sola_lista()
    {
        list($dueno, $lista) = $this->con_precio_final($this->dueno_nuevo('excel-clientes'));
        $this->actingAs($dueno, 'web');

        $headings = (new ArticleClientsExport(null))->headings();

        $this->assertContains('Precio', $headings);
        $this->assertContains('Precio anterior', $headings);
        $this->assertContains('Variacion %', $headings);
        $this->assertNotContains($lista->name, $headings);

        $this->otra_lista($dueno, 'zz Mayorista clientes', 100, 0);

        $this->assertContains('zz Mayorista clientes', (new ArticleClientsExport(null))->headings());
    }

    // ═══ C1 — el asistente ════════════════════════════════════════════════════════════════════

    /** @test */
    public function el_asistente_acepta_margen_y_precio_del_articulo_con_una_sola_lista()
    {
        $dueno = $this->dueno_nuevo('ia');

        $this->assertFalse(MargenesPorListaIaHelper::margen_suelto_prohibido($dueno), 'Hoy, sin listas, el margen del artículo se acepta.');

        list($dueno, $lista) = $this->con_precio_final($dueno);

        $this->assertFalse(MargenesPorListaIaHelper::margen_suelto_prohibido($dueno), 'Migrado con una sola lista: la ficha lo muestra.');
        $this->assertFalse(MargenesPorListaIaHelper::aplica($dueno), 'Con una sola lista no hay margenes_por_lista.');
        $this->assertNull(MargenesPorListaIaHelper::guarda_de_campos_escondidos($this->contexto_de($dueno), array('percentage_gain' => 30, 'price' => 500)));
        $this->assertSame(array(), MargenesPorListaIaHelper::campos_que_no_aplican($dueno));
        $this->assertStringContainsString('percentage_gain', MargenesPorListaIaHelper::motivo_si_no_aplica($dueno));
        $this->assertStringNotContainsString('dólares', MargenesPorListaIaHelper::motivo_si_no_aplica($dueno));

        $puede = MargenesPorListaIaHelper::para_que_puedo_cargar($this->contexto_de($dueno));
        $this->assertFalse($puede['trabaja_con_listas']);
    }

    /** @test */
    public function el_asistente_sigue_rechazando_el_margen_suelto_a_quien_trabaja_con_listas()
    {
        list($dueno, $lista) = $this->con_precio_final($this->dueno_nuevo('ia-dos'));
        $this->otra_lista($dueno, 'zz Mayorista ia', 100, 0);

        $this->assertTrue(MargenesPorListaIaHelper::margen_suelto_prohibido($dueno));
        $this->assertTrue(MargenesPorListaIaHelper::aplica($dueno));

        $propias = $this->dueno_nuevo('ia-propia', array('listas_de_precio' => 1));
        $this->otra_lista($propias, 'zz Única propia');

        $this->assertTrue(MargenesPorListaIaHelper::margen_suelto_prohibido($propias), 'Una sola lista PROPIA: su precio sale de la lista, no cambia nada.');
    }

    /** @test */
    public function el_margen_de_precio_final_por_el_asistente_mueve_el_precio_del_articulo()
    {
        list($dueno, $lista) = $this->con_precio_final($this->dueno_nuevo('ia-estimado'));
        $this->otra_lista($dueno, 'zz Mayorista estimado', 100, 0);

        $articulo = $this->recalcular($this->articulo($dueno, 'con margen', array('cost' => 1000, 'percentage_gain' => 20)), $dueno);
        $final = (float) $articulo->final_price;

        $estimados = MargenesPorListaIaHelper::precios_estimados(
            $this->contexto_de($dueno),
            $articulo->id,
            array(array('price_type_id' => $lista->id, 'margen' => 50))
        );

        $this->assertEqualsWithDelta($final, $estimados[$lista->id]['antes'], self::DELTA);
        $this->assertGreaterThan($final, $estimados[$lista->id]['despues'], 'El "después" de la tarjeta refleja el margen nuevo.');
        $this->assertMismoPrecio($final, $this->final_price_de($articulo), 'La estimación no deja nada escrito.');
    }

    // ═══ C2 — el mail ═════════════════════════════════════════════════════════════════════════

    /** @test */
    public function el_mail_de_ingreso_de_stock_muestra_el_precio_con_una_sola_lista()
    {
        list($dueno, $lista) = $this->con_precio_final($this->dueno_nuevo('mail'));

        $articulo = $this->recalcular($this->articulo($dueno, 'con stock', array('cost' => 1000, 'percentage_gain' => 20)), $dueno);

        $this->actingAs($dueno, 'web');

        $this->assertTrue((new Advise($articulo))->build()->viewData['mostrar_precio']);

        $this->otra_lista($dueno, 'zz Mayorista mail', 100, 0);

        $this->assertFalse((new Advise($articulo))->build()->viewData['mostrar_precio'], 'Con dos listas el precio depende del comprador.');
    }

    // ═══ C4 — el techo de descuento ═══════════════════════════════════════════════════════════

    /** @test */
    public function el_techo_de_descuento_excluye_al_costo_en_dolares_que_ahora_se_cotiza()
    {
        $dueno = $this->con_extension($this->dueno_nuevo('techo', array('listas_de_precio' => 1)), 'ventas_en_dolares');
        $this->otra_lista($dueno, 'zz Propia techo');

        $articulo = $this->articulo($dueno, 'en dólares', array('cost' => 10, 'cost_in_dollars' => 1));

        $this->assertFalse(TechoDeDescuentoService::el_precio_se_cotiza_y_el_costo_no($articulo, User::find($dueno->id)), 'Con listas propias y dólares cotizar() no corre: como siempre.');

        $con_precio_final = $this->con_extension($this->dueno_nuevo('techo-pf'), 'ventas_en_dolares');
        list($con_precio_final, $lista) = $this->con_precio_final($con_precio_final);

        $articulo = $this->articulo($con_precio_final, 'en dólares', array('cost' => 10, 'cost_in_dollars' => 1));

        $this->assertTrue(TechoDeDescuentoService::el_precio_se_cotiza_y_el_costo_no($articulo, $con_precio_final), 'Con «Precio final» la cadena del artículo cotiza: costo en USD y precio en pesos.');
    }

    // ═══ C5 — Vender, caso 2R ═════════════════════════════════════════════════════════════════

    /** @test */
    public function el_caso_2r_precia_con_el_final_price_y_las_filas_por_moneda_son_informativas()
    {
        $dueno = $this->con_extension($this->dueno_nuevo('2r', array('cotizar_precios_en_dolares' => 0, 'dollar' => 1000)), 'ventas_en_dolares');

        $articulo = $this->recalcular($this->articulo($dueno, '2R', array('cost' => 10, 'cost_in_dollars' => 1, 'percentage_gain' => 50)), $dueno);
        $final_hoy = (float) $articulo->final_price;

        list($dueno, $lista) = $this->con_precio_final($dueno);

        $articulo = $this->recalcular($articulo, $dueno);

        $this->assertMismoPrecio($final_hoy, (float) $articulo->final_price, 'El precio en dólares no cambió.');
        $this->assertMismoPrecio($final_hoy * 1000, (float) $this->fila_moneda($articulo, $lista, 1)->final_price, 'La fila en pesos quedó con el dólar de la cuenta.');

        // La cotización de la venta es otra: la cuenta sube el dólar y nadie recalcula.
        DB::table('users')->where('id', $dueno->id)->update(array('dollar' => 1200));

        $resuelto = ArticlePricesHelper::resolver_precio_de_venta(Article::find($articulo->id), User::find($dueno->id));

        $this->assertSame((int) $lista->id, (int) $resuelto['price_type_id']);
        $this->assertMismoPrecio($final_hoy, (float) $resuelto['final_price'], 'El precio de venta es el final_price en dólares: lo convierte la cotización de la venta.');

        // Aunque el pivote esté viejo: esa lista es la columna del artículo.
        DB::table('article_price_type')->where('article_id', $articulo->id)->where('price_type_id', $lista->id)->update(array('final_price' => 1));

        $resuelto = ArticlePricesHelper::resolver_precio_de_venta(Article::find($articulo->id), User::find($dueno->id));

        $this->assertMismoPrecio($final_hoy, (float) $resuelto['final_price']);
    }

    /** @test */
    public function la_api_le_manda_a_la_spa_cual_es_la_lista_precio_final()
    {
        list($dueno, $lista) = $this->con_precio_final($this->dueno_nuevo('spa'));

        $this->actingAs($dueno, 'web');

        $models = $this->getJson('api/price-type')->assertStatus(200)->json('models');

        $this->assertCount(1, $models);
        $this->assertSame(1, (int) $models[0]['es_precio_final'], 'La SPA decide con esto si Vender precia como sin listas.');
    }

    // ═══ El tilde «Cotizar desde la otra moneda» ══════════════════════════════════════════════

    /** @test */
    public function el_tilde_sigue_la_regla_de_lucas_en_el_espejo()
    {
        // Prendido → tildada la fila en dólares, también con el costo en dólares.
        $prendido = $this->con_extension($this->dueno_nuevo('tilde-on', array('cotizar_precios_en_dolares' => 1)), 'ventas_en_dolares');
        list($prendido, $lista) = $this->con_precio_final($prendido);
        $articulo = $this->recalcular($this->articulo($prendido, 'en pesos', array('cost' => 1000, 'percentage_gain' => 20)), $prendido);

        $this->assertSame(0, (int) $this->fila_moneda($articulo, $lista, 1)->cotizar_desde_otra_moneda);
        $this->assertSame(1, (int) $this->fila_moneda($articulo, $lista, 2)->cotizar_desde_otra_moneda);

        // Apagado → tildada la fila en pesos, también para un artículo con el costo en pesos.
        $apagado = $this->con_extension($this->dueno_nuevo('tilde-off', array('cotizar_precios_en_dolares' => 0)), 'ventas_en_dolares');
        list($apagado, $lista) = $this->con_precio_final($apagado);
        $articulo = $this->recalcular($this->articulo($apagado, 'en pesos', array('cost' => 1000, 'percentage_gain' => 20)), $apagado);

        $this->assertSame(1, (int) $this->fila_moneda($articulo, $lista, 1)->cotizar_desde_otra_moneda);
        $this->assertSame(0, (int) $this->fila_moneda($articulo, $lista, 2)->cotizar_desde_otra_moneda);

        // El número no depende del tilde: el precio del artículo, en pesos.
        $this->assertMismoPrecio((float) $articulo->final_price, (float) $this->fila_moneda($articulo, $lista, 1)->final_price);
        $this->assertMismoPrecio((float) $articulo->final_price / 1000, (float) $this->fila_moneda($articulo, $lista, 2)->final_price);
    }

    /** @test */
    public function la_fila_sin_tilde_es_la_que_escribe_en_el_articulo_aunque_este_en_otra_moneda()
    {
        $apagado = $this->con_extension($this->dueno_nuevo('tilde-write', array('cotizar_precios_en_dolares' => 0, 'dollar' => 1000)), 'ventas_en_dolares');
        list($apagado, $lista) = $this->con_precio_final($apagado);

        $articulo = $this->recalcular($this->articulo($apagado, 'en pesos', array('cost' => 1000, 'percentage_gain' => 20)), $apagado);

        ArticlePriceTypeMonedaHelper::attach_price_type_monedas(Article::find($articulo->id), array(
            array('price_type_id' => $lista->id, 'moneda_id' => 1, 'percentage' => 20, 'final_price' => 9999, 'setear_precio_final' => 0, 'cotizar_desde_otra_moneda' => 1),
            array('price_type_id' => $lista->id, 'moneda_id' => 2, 'percentage' => null, 'final_price' => 3, 'setear_precio_final' => 1, 'cotizar_desde_otra_moneda' => 0),
        ), $apagado);

        $fila = DB::table('articles')->where('id', $articulo->id)->first();

        $this->assertEqualsWithDelta(3000, (float) $fila->price, self::DELTA, 'USD 3 en la tarjeta: el precio del artículo, que está en pesos, se lleva con el dólar de la cuenta.');
        $this->assertNull($fila->percentage_gain, 'Un precio fijado no convive con un margen.');
    }

    // ═══ C8 — dólares sin extensión ═══════════════════════════════════════════════════════════

    /** @test */
    public function opera_en_dolares_quien_tiene_la_extension_o_ya_vendio_en_dolares()
    {
        $dueno = $this->dueno_nuevo('opera');

        $this->assertFalse(UserHelper::opera_en_dolares($dueno));
        $this->assertFalse(UserHelper::opera_en_dolares(null));

        DB::table('sales')->insert(array('user_id' => $dueno->id, 'moneda_id' => 2, 'created_at' => now(), 'updated_at' => now()));

        $this->assertTrue(UserHelper::opera_en_dolares($dueno), 'Vendió en dólares: no hace falta la extensión.');

        $con_extension = $this->con_extension($this->dueno_nuevo('opera-ext'), 'ventas_en_dolares');

        $this->assertTrue(UserHelper::opera_en_dolares($con_extension));
    }

    /** @test */
    public function los_reportes_contestan_en_dolares_sin_la_extension()
    {
        $dueno = $this->dueno_nuevo('reportes');

        $this->assertSame('dolares', EstadoResultadosHelper::moneda_efectiva($dueno->id, 'dolares'), 'El selector ya no se esconde: se contesta.');
        $this->assertSame('pesos', EstadoResultadosHelper::moneda_efectiva($dueno->id, 'pesos'));
        $this->assertSame('pesos', EstadoResultadosHelper::moneda_efectiva($dueno->id, 'consolidado'), 'Sin nada en dólares, consolidado es el reporte en pesos.');

        DB::table('sales')->insert(array('user_id' => $dueno->id, 'moneda_id' => 2, 'created_at' => now(), 'updated_at' => now()));

        $this->assertSame('consolidado', EstadoResultadosHelper::moneda_efectiva($dueno->id, 'consolidado'));
    }

    /** @test */
    public function el_pdf_de_clientes_trae_el_saldo_en_dolares_si_alguno_lo_tiene()
    {
        $dueno = $this->dueno_nuevo('pdf');

        $sin = new ClientsPdf(collect(array((object) array('saldo_dolares' => null))), $dueno);
        $this->assertArrayNotHasKey('Saldo USD', $sin->getFields());

        $con = new ClientsPdf(collect(array((object) array('saldo_dolares' => 0), (object) array('saldo_dolares' => 150))), $dueno);
        $this->assertArrayHasKey('Saldo USD', $con->getFields());
    }
}
