<?php

namespace Tests\Import;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\PriceTypeHelper;
use App\Models\Article;
use App\Models\ArticleSurchage;
use App\Models\ExtencionEmpresa;
use App\Models\PriceType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * El Excel de una cuenta con lista «Precio final» (misión prueba-listas-siempre, 3/11/2026, B3 del
 * chequeo independiente), con la importación REAL contra el endpoint.
 *
 * Lo que estaba roto: las columnas de esa lista escribían su pivote, y el espejo de setFinalPrice() lo
 * pisaba en el mismo recálculo: un Excel que cambiaba precios no cambiaba nada, y sin un solo aviso.
 * Esa lista ES el precio del artículo, así que sus columnas son el margen y el precio manual del
 * artículo, y pasan por la misma regla de siempre ("no se pasa de margen a manual desde un Excel").
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
class ListasSiempreExcelConPrecioFinalTest extends ImportTestCase
{
    /** @var \App\Models\PriceType */
    protected $lista;

    /** @var array */
    protected $archivos = array();

    protected function setUp(): void
    {
        parent::setUp();

        PriceTypeHelper::olvidar_precio_final();

        $this->tenant->listas_de_precio = 1;
        $this->tenant->save();
        $this->actingAs($this->tenant, 'web');

        $this->lista = PriceTypeHelper::crear_lista_precio_final($this->tenant->id);
    }

    protected function tearDown(): void
    {
        foreach ($this->archivos as $archivo) {
            @unlink($archivo);
        }

        PriceTypeHelper::olvidar_precio_final();

        parent::tearDown();
    }

    /**
     * Las columnas de este archivo: código, nombre y las tres que importan.
     *
     * @param  array  $filas  [bar_code, nombre, $ final, %, setear]
     * @return string  Nombre del archivo dentro de fixtures/ (se borra al terminar el test).
     */
    protected function archivo(array $filas)
    {
        $nombre = 'zz_listas_siempre_'.uniqid().'.xlsx';
        $ruta = __DIR__.'/fixtures/'.$nombre;

        $libro = new Spreadsheet();
        $libro->getActiveSheet()->fromArray(array_merge(array(array('Codigo', 'Nombre', 'Precio', 'Margen', 'Setear')), $filas), null, 'A1', true);

        (new Xlsx($libro))->save($ruta);

        $this->archivos[] = $ruta;

        return $nombre;
    }

    /**
     * El mapeo con todas las columnas de ImportTestCase apagadas, salvo código y nombre.
     *
     * @param  array  $columnas
     * @return array
     */
    protected function mapeo(array $columnas)
    {
        return array_merge(array(
            'prop_codigo_de_barras'    => 1,
            'prop_nombre'              => 2,
            'prop_sku'                 => -1,
            'prop_codigo_de_proveedor' => -1,
            'prop_costo'               => -1,
            'prop_precio'              => -1,
            'prop_stock_actual'        => -1,
            'prop_iva'                 => -1,
        ), $columnas);
    }

    protected function articulo_del_tenant($bar_code, $nombre, array $props)
    {
        $article = new Article();
        $article->user_id = $this->tenant->id;
        $article->iva_id = 2;
        $article->status = 'active';
        $article->stock = 0;
        $article->bar_code = $bar_code;
        $article->name = $nombre;
        $article->provider_id = $this->providers['A']->id;

        foreach ($props as $campo => $valor) {
            $article->$campo = $valor;
        }

        $article->save();

        ImportTestSeeder::derivar_precio($article, $this->tenant->id);

        return Article::find($article->id);
    }

    protected function assertEspejo($articulo)
    {
        $fresco = DB::table('articles')->where('id', $articulo->id)->first();

        $pivote = DB::table('article_price_type')
            ->where('article_id', $articulo->id)
            ->where('price_type_id', $this->lista->id)
            ->first();

        $this->assertNotNull($pivote, 'El artículo tiene su pivote de «Precio final».');
        $this->assertDecimal($fresco->final_price, $pivote->final_price, 'El pivote es el precio final.');
    }

    /** @test */
    public function con_una_sola_lista_las_columnas_del_articulo_cambian_el_precio()
    {
        $con_margen = $this->articulo_del_tenant('7790201', 'Con margen', array('cost' => 100, 'percentage_gain' => 20));
        $con_manual = $this->articulo_del_tenant('7790202', 'Con manual', array('cost' => 100, 'price' => 500));

        $final_con_margen = (float) $con_margen->final_price;

        $this->assertDecimal(500, $con_manual->final_price);

        $this->importar($this->archivo(array(
            array('7790201', 'Con margen', null, 50, null),
            array('7790202', 'Con manual', 700, null, null),
        )), $this->mapeo(array('prop_precio' => 3, 'prop_margen_de_ganancia' => 4)));

        $con_margen = Article::find($con_margen->id);
        $con_manual = Article::find($con_manual->id);

        $this->assertDecimal(50, $con_margen->percentage_gain);
        $this->assertGreaterThan($final_con_margen, (float) $con_margen->final_price, 'El margen nuevo movió el precio.');
        $this->assertDecimal(700, $con_manual->price);
        $this->assertDecimal(700, $con_manual->final_price);

        $this->assertEspejo($con_margen);
        $this->assertEspejo($con_manual);
    }

    /** @test */
    public function las_columnas_de_la_lista_se_escriben_en_el_articulo()
    {
        // Así exporta el Excel desde la segunda lista: las tres columnas por lista.
        PriceType::create(array('name' => 'zz Mayorista', 'percentage' => 100, 'position' => 0, 'user_id' => $this->tenant->id));

        $con_margen = $this->articulo_del_tenant('7790201', 'Con margen', array('cost' => 100, 'percentage_gain' => 20));
        $con_manual = $this->articulo_del_tenant('7790202', 'Con manual', array('cost' => 100, 'price' => 500));
        $sin_nada   = $this->articulo_del_tenant('7790203', 'Sin nada', array('cost' => 100));

        $final_con_margen = (float) $con_margen->final_price;

        $this->importar($this->archivo(array(
            array('7790201', 'Con margen', 9999, 50, 'No'),
            array('7790202', 'Con manual', 700, null, 'Si'),
            array('7790203', 'Sin nada', 9999, 40, 'No'),
        )), $this->mapeo(array(
            'prop_$_final_precio_final'            => 3,
            'prop_%_precio_final'                  => 4,
            'prop_setear_precio_final_precio_final' => 5,
        )));

        $con_margen = Article::find($con_margen->id);
        $con_manual = Article::find($con_manual->id);
        $sin_nada   = Article::find($sin_nada->id);

        $this->assertDecimal(50, $con_margen->percentage_gain, 'Setear No: manda el margen.');
        $this->assertNull($con_margen->price, 'Setear No: el precio de la fila es el calculado, no uno fijado.');
        $this->assertGreaterThan($final_con_margen, (float) $con_margen->final_price);

        $this->assertDecimal(700, $con_manual->price, 'Setear Sí: manda el precio.');
        $this->assertDecimal(700, $con_manual->final_price);

        $this->assertDecimal(40, $sin_nada->percentage_gain);
        $this->assertNull($sin_nada->price);

        $this->assertEspejo($con_margen);
        $this->assertEspejo($con_manual);
        $this->assertEspejo($sin_nada);
    }

    /** @test */
    public function reimportar_la_misma_planilla_no_sube_los_precios()
    {
        // Así exporta el Excel desde la segunda lista: las tres columnas por lista.
        PriceType::create(array('name' => 'zz Mayorista', 'percentage' => 100, 'position' => 0, 'user_id' => $this->tenant->id));

        $con_manual = $this->articulo_del_tenant('7790202', 'Con manual', array('cost' => 100, 'price' => 500));

        ArticleSurchage::create(array('article_id' => $con_manual->id, 'percentage' => 10, 'luego_del_precio_final' => 1));
        ImportTestSeeder::derivar_precio(Article::find($con_manual->id), $this->tenant->id);

        $this->assertDecimal(550, Article::find($con_manual->id)->final_price, 'Manual 500 + recargo 10 %.');

        $mapeo = $this->mapeo(array(
            'prop_$_final_precio_final'             => 3,
            'prop_%_precio_final'                   => 4,
            'prop_setear_precio_final_precio_final' => 5,
        ));

        // La planilla exportada trae el precio con el recargo adentro: 550.
        $planilla = array(array('7790202', 'Con manual', 550, null, 'Si'));

        foreach (array(1, 2) as $vuelta) {

            $this->importar($this->archivo($planilla), $mapeo);

            $fresco = Article::find($con_manual->id);

            $this->assertDecimal(500, $fresco->price, 'Vuelta '.$vuelta.': el precio manual no se toca.');
            $this->assertDecimal(550, $fresco->final_price, 'Vuelta '.$vuelta.': el precio final no sube.');
            $this->assertEspejo($fresco);
        }

        // Un número distinto sí es un cambio, y sigue la regla de la ficha: el recargo va encima.
        $this->importar($this->archivo(array(array('7790202', 'Con manual', 600, null, 'Si'))), $mapeo);

        $fresco = Article::find($con_manual->id);

        $this->assertDecimal(600, $fresco->price);
        $this->assertDecimal(660, $fresco->final_price);
    }

    /** @test */
    public function reimportar_solo_la_columna_de_precio_no_fija_un_precio_calculado()
    {
        PriceType::create(array('name' => 'zz Mayorista', 'percentage' => 100, 'position' => 0, 'user_id' => $this->tenant->id));

        $calculado = $this->articulo_del_tenant('7790203', 'Calculado', array('cost' => 100));

        $this->importar($this->archivo(array(
            array('7790203', 'Calculado', (float) $calculado->final_price, null, null),
        )), $this->mapeo(array('prop_$_final_precio_final' => 3)));

        $this->assertNull(Article::find($calculado->id)->price, 'El número que ya tenía no es un precio fijado a mano.');
    }

    /** @test */
    public function setear_si_sobre_un_precio_calculado_lo_fija_aunque_el_numero_sea_el_mismo()
    {
        PriceType::create(array('name' => 'zz Mayorista', 'percentage' => 100, 'position' => 0, 'user_id' => $this->tenant->id));

        $calculado = $this->articulo_del_tenant('7790203', 'Calculado', array('cost' => 100));
        $final = (float) $calculado->final_price;

        $this->importar($this->archivo(array(
            array('7790203', 'Calculado', $final, null, 'Si'),
        )), $this->mapeo(array(
            'prop_$_final_precio_final'             => 3,
            'prop_setear_precio_final_precio_final' => 5,
        )));

        $this->assertDecimal($final, Article::find($calculado->id)->price, 'Es tildar «Setear precio final» en la tarjeta.');
    }

    /**
     * Cuenta con precios por moneda, dueño que no cotiza, dólar 1000, y un artículo con costo USD 10 y
     * manual USD 15. El Excel exporta su fila en pesos: 15000. Reproducción de la revisión final.
     */
    protected function manual_en_dolares($bar_code)
    {
        PriceType::create(array('name' => 'zz Mayorista', 'percentage' => 100, 'position' => 0, 'user_id' => $this->tenant->id));

        $extension = ExtencionEmpresa::where('slug', 'ventas_en_dolares')->first();

        if (is_null($extension)) {
            $extension = ExtencionEmpresa::forceCreate(array('name' => 'ventas_en_dolares', 'slug' => 'ventas_en_dolares'));
        }

        $this->tenant->extencions()->syncWithoutDetaching(array($extension->id));

        DB::table('users')->where('id', $this->tenant->id)->update(array('cotizar_precios_en_dolares' => 0, 'dollar' => 1000));

        $this->tenant = User::find($this->tenant->id);
        $this->actingAs($this->tenant, 'web');

        $articulo = $this->articulo_del_tenant($bar_code, 'Manual USD', array('cost' => 10, 'cost_in_dollars' => 1, 'price' => 15));

        ArticleHelper::setFinalPrice(Article::find($articulo->id), null, $this->tenant);

        $this->assertDecimal(15000, DB::table('article_price_type_monedas')->where('article_id', $articulo->id)->where('price_type_id', $this->lista->id)->where('moneda_id', 1)->value('final_price'));

        return Article::find($articulo->id);
    }

    protected function mapeo_con_setear()
    {
        return $this->mapeo(array(
            'prop_$_final_precio_final'             => 3,
            'prop_%_precio_final'                   => 4,
            'prop_setear_precio_final_precio_final' => 5,
        ));
    }

    /** @test */
    public function la_columna_en_pesos_no_se_guarda_como_dolares()
    {
        $articulo = $this->manual_en_dolares('7791009');

        $this->importar($this->archivo(array(array('7791009', 'Manual USD', 15000, null, null))), $this->mapeo(array('prop_$_final_precio_final' => 3)));

        $this->assertDecimal(15, Article::find($articulo->id)->price, 'Lo que el Excel exportó no es un cambio.');
        $this->assertDecimal(15, Article::find($articulo->id)->final_price);
    }

    /** @test */
    public function la_planilla_tal_cual_exporta_no_cambia_nada()
    {
        $articulo = $this->manual_en_dolares('7791007');

        foreach (array(1, 2) as $vuelta) {
            $this->importar($this->archivo(array(array('7791007', 'Manual USD', 15000, null, 'Si'))), $this->mapeo_con_setear());

            $this->assertDecimal(15, Article::find($articulo->id)->price, 'Vuelta '.$vuelta);
            $this->assertDecimal(15, Article::find($articulo->id)->final_price, 'Vuelta '.$vuelta);
        }
    }

    /** @test */
    public function un_precio_editado_en_pesos_se_convierte_a_la_moneda_del_articulo()
    {
        $articulo = $this->manual_en_dolares('7791010');

        $this->importar($this->archivo(array(array('7791010', 'Manual USD', 16000, null, 'Si'))), $this->mapeo_con_setear());

        $this->assertDecimal(16, Article::find($articulo->id)->price, '16000 pesos con el dólar a 1000.');
        $this->assertDecimal(16, Article::find($articulo->id)->final_price);
    }

    /** @test */
    public function si_el_dolar_cambio_la_misma_planilla_sigue_sin_ser_un_cambio()
    {
        $articulo = $this->manual_en_dolares('7791011');

        DB::table('users')->where('id', $this->tenant->id)->update(array('dollar' => 1200));
        $this->tenant = User::find($this->tenant->id);
        $this->actingAs($this->tenant, 'web');

        $this->importar($this->archivo(array(array('7791011', 'Manual USD', 15000, null, 'Si'))), $this->mapeo_con_setear());

        $this->assertDecimal(15, Article::find($articulo->id)->price, 'Compara con la fila en pesos, no con el dólar de hoy.');
    }

    /** @test */
    public function sin_dolar_en_la_cuenta_la_columna_no_se_toma()
    {
        $articulo = $this->manual_en_dolares('7791012');

        DB::table('users')->where('id', $this->tenant->id)->update(array('dollar' => 0));
        $this->tenant = User::find($this->tenant->id);
        $this->actingAs($this->tenant, 'web');

        $this->importar($this->archivo(array(array('7791012', 'Manual USD', 16000, null, 'Si'))), $this->mapeo_con_setear());

        $this->assertDecimal(15, Article::find($articulo->id)->price, 'No se inventa una cotización.');
    }

    /** @test */
    public function un_articulo_nuevo_en_dolares_nace_con_el_precio_convertido()
    {
        $this->manual_en_dolares('7791013');

        $this->importar($this->archivo(array(array('7791099', 'Nuevo USD', 20000, null, null, 'USD'))), $this->mapeo(array(
            'prop_$_final_precio_final' => 3,
            'prop_moneda'               => 6,
        )));

        $nuevo = Article::where('user_id', $this->tenant->id)->where('bar_code', '7791099')->first();

        $this->assertNotNull($nuevo);
        $this->assertDecimal(20, $nuevo->price);
    }

    /** @test */
    public function un_articulo_en_pesos_de_una_cuenta_con_monedas_toma_el_numero_tal_cual()
    {
        $this->manual_en_dolares('7791014');

        $en_pesos = $this->articulo_del_tenant('7791015', 'En pesos', array('cost' => 100, 'price' => 500));
        ArticleHelper::setFinalPrice(Article::find($en_pesos->id), null, $this->tenant);

        $this->importar($this->archivo(array(array('7791015', 'En pesos', 700, null, 'Si'))), $this->mapeo_con_setear());

        $this->assertDecimal(700, Article::find($en_pesos->id)->price);
    }

    /** @test */
    public function un_articulo_nuevo_nace_con_el_precio_de_la_columna_de_la_lista()
    {
        $this->importar($this->archivo(array(
            array('7790299', 'Nuevo con precio', 800, null, null),
        )), $this->mapeo(array('prop_$_final_precio_final' => 3)));

        $nuevo = Article::where('user_id', $this->tenant->id)->where('bar_code', '7790299')->first();

        $this->assertNotNull($nuevo);
        $this->assertDecimal(800, $nuevo->price);
        $this->assertDecimal(800, $nuevo->final_price);
        $this->assertEspejo($nuevo);
    }

    /** @test */
    public function la_regla_de_no_pasar_de_margen_a_manual_sigue_valiendo()
    {
        $con_margen = $this->articulo_del_tenant('7790201', 'Con margen', array('cost' => 100, 'percentage_gain' => 20));

        $this->importar($this->archivo(array(
            array('7790201', 'Con margen', 700, null, 'Si'),
        )), $this->mapeo(array(
            'prop_$_final_precio_final'            => 3,
            'prop_setear_precio_final_precio_final' => 5,
        )));

        $con_margen = Article::find($con_margen->id);

        $this->assertDecimal(20, $con_margen->percentage_gain);
        $this->assertNull($con_margen->price, 'Igual que la columna «Precio» de siempre: se saltea y se reporta.');
    }
}
