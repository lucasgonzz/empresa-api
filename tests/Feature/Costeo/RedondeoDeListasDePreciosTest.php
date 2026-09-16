<?php

namespace Tests\Feature\Costeo;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\DesglosePrecioHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\article\ArticlePricesHelper;
use App\Models\Article;
use App\Models\ArticleDiscount;
use App\Models\ArticleSurchage;
use App\Models\Category;
use App\Models\ExtencionEmpresa;
use App\Models\PriceType;
use App\Models\PriceTypeSurchage;
use App\Models\SaleTax;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * El redondeo del usuario llega al precio final de CADA LISTA, por los dos caminos. 16/9/2026.
 *
 * Reportado por golonorte: redondeo de centavos prendido y los tres precios de lista con centavos.
 * La causa es que `ArticleHelper::redondear()` se aplicaba solo al precio final UNICO del articulo
 * -- y en una cuenta con listas el precio que se cobra sale del pivote
 * (`ArticlePricesHelper::resolver_precio_de_venta()` devuelve `pivot->final_price`), no de
 * `article->final_price`. O sea que la configuracion, prendida, no tocaba ningun precio que el
 * negocio cobre.
 *
 * 🔴 DE DONDE SALEN LOS NUMEROS: de la regla, a mano, no de correr el codigo. Cuenta legacy
 * (`usar_condicion_fiscal_en_costeo = 0` + `aplicar_iva_al_costo = 1`), asi que el IVA entra al
 * COSTO y el precio de lista no lo vuelve a sumar. Con `cost = 1000` y alicuota 21%:
 *
 *     costo_real = 1000 x 1,21                    = 1210
 *     precio de la lista al 33% = 1210 x 1,33     = 1609,30   <- con centavos, a proposito
 *
 * Y sobre esos 1609,30, cada una de las cinco reglas de `redondear()`:
 *
 *     | regla                          | cuenta                        | esperado |
 *     |--------------------------------|-------------------------------|----------|
 *     | ninguna                        | -                             | 1609,30  |
 *     | redondear_precios_en_centavos  | round(1609,30)                | 1609     |
 *     | redondear_precios_en_decenas   | round(1609,30, -1)            | 1610     |
 *     | redondear_de_a_50              | ceil(1609,30 / 50) x 50       | 1650     |
 *     | redondear_centenas_en_vender   | round(1609,30, -2)            | 1600     |
 *     | redondear_miles_en_vender      | round(1609,30 / 1000) x 1000  | 2000     |
 *
 * El caso de golonorte va aparte y con sus numeros reales de produccion, medidos el 16/9/2026:
 * `cost = 1045.454545` -> `costo_real = 1264,999999` -> margen 30% -> 1644,4999987 -> **1644**.
 * Ese es el numero que Lucas tiene que ver en la tarjeta MAYORISTA del articulo 1244 en lugar de
 * $1.644,50. Ojo con el 1644 y no 1645: ver el comentario del test, el valor que se redondea es el
 * que se CALCULA, no el que se muestra.
 *
 * @group costeo-precios
 */
class RedondeoDeListasDePreciosTest extends TestCase
{
    use DatabaseTransactions;

    const DELTA = 0.005;

    const SLUG_LISTA_CATEGORIA = 'lista_de_precios_por_categoria';
    const SLUG_VENTAS_DOLARES = 'ventas_en_dolares';

    /** Las cinco columnas de redondeo, tal como las lee ArticleHelper::redondear(). */
    const REGLAS = [
        'redondear_miles_en_vender',
        'redondear_centenas_en_vender',
        'redondear_precios_en_decenas',
        'redondear_de_a_50',
        'redondear_precios_en_centavos',
    ];

    /** @var \App\Models\User */
    protected $user;

    /** @var \App\Models\Article */
    protected $pinza;

    /** @var \App\Models\PriceType */
    protected $distribuidor;

    /** @var \App\Models\PriceType */
    protected $mayorista;

    /** @var \App\Models\Category */
    protected $category;

    /** @var array<string,mixed> */
    protected $user_snapshot = [];

    /** @var array<string,mixed> */
    protected $pinza_snapshot = [];

    /** @var \App\Models\SaleTax|null */
    protected $iibb;

    protected $iibb_activo_original;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::find(500);
        if (is_null($this->user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($this->user, 'web');

        $this->pinza = Article::where('name', 'Pinza')->where('user_id', 500)->first();
        if (is_null($this->pinza)) {
            $this->markTestSkipped('La base de testing no tiene el articulo "Pinza" sembrado (TestingFerreteriaSeeder).');
        }

        $this->distribuidor = PriceType::where('user_id', 500)->where('name', 'Distribuidor')->first();
        $this->mayorista = PriceType::where('user_id', 500)->where('name', 'Mayorista')->first();
        if (is_null($this->distribuidor) || is_null($this->mayorista)) {
            $this->markTestSkipped('La base de testing no tiene los price_types "Distribuidor"/"Mayorista" sembrados.');
        }

        // Snapshot a mano de todo lo que esta suite muta, mismo criterio que CascadaDePreciosTest:
        // varias filas tocadas son globales (PriceType, ExtencionEmpresa, SaleTax) y esta suite no
        // pasa por Tests\EmpresaTestCase.
        $this->user_snapshot = [
            'usar_condicion_fiscal_en_costeo' => $this->user->usar_condicion_fiscal_en_costeo,
            'condicion_iva_precios'           => $this->user->condicion_iva_precios,
            'aplicar_iva_al_costo'            => $this->user->aplicar_iva_al_costo,
            'listas_de_precio'                => $this->user->listas_de_precio,
            'percentage_gain'                 => $this->user->percentage_gain,
            'aplicar_descuentos_en_articulos_antes_del_margen_de_ganancia' => $this->user->aplicar_descuentos_en_articulos_antes_del_margen_de_ganancia,
        ];

        foreach (self::REGLAS as $regla) {
            $this->user_snapshot[$regla] = $this->user->$regla;
        }

        $this->pinza_snapshot = [
            'cost'            => $this->pinza->cost,
            'costo_real'      => $this->pinza->costo_real,
            'price'           => $this->pinza->price,
            'final_price'     => $this->pinza->final_price,
            'percentage_gain' => $this->pinza->percentage_gain,
            'category_id'     => $this->pinza->category_id,
            'sub_category_id' => $this->pinza->sub_category_id,
            'base_margen'     => $this->pinza->base_margen,
        ];

        // Cuenta legacy: el IVA va al costo. Es la configuracion de golonorte y la que hace que el
        // numero de la tabla del docblock sea derivable a mano de una sola multiplicacion.
        $this->user->usar_condicion_fiscal_en_costeo = 0;
        $this->user->condicion_iva_precios = 'RRII';
        $this->user->aplicar_iva_al_costo = 1;
        $this->user->percentage_gain = null;
        // El bloque de descuentos/recargos DESPUES del margen: es el que contiene el llamado a
        // aplicar_recargos() que perdia el desglose, y el que tiene prendido golonorte.
        $this->user->aplicar_descuentos_en_articulos_antes_del_margen_de_ganancia = 0;
        $this->apagar_todos_los_redondeos();

        $this->pinza->cost = 1000;
        $this->pinza->price = null;
        $this->pinza->percentage_gain = null;
        $this->pinza->sub_category_id = null;
        $this->pinza->save();

        /**
         * 🔴 La base de testing del slot tiene descuentos SEMBRADOS sobre este articulo por
         * corridas anteriores -- medidos el 16/9/2026 en `empresa_testing_s2`: dos bonificaciones de
         * proveedor, 5% y 10%, con fecha 3/9/2026. Se aplican al costo (`aplicar_descuentos_e_iva()`)
         * y dejan el costo real en 855 en lugar de 1000, asi que el precio de la lista deja de ser
         * derivable de la multiplicacion del docblock y el test mide otra cosa.
         *
         * Se borran ACA y no se restauran a mano: `DatabaseTransactions` revierte el delete al
         * cerrar cada test. Mismo criterio que la desactivacion del IIBB de mas abajo -- lo que esta
         * suite prueba es el redondeo, no la interaccion con lo que alguien dejo en la base.
         */
        ArticleDiscount::where('article_id', $this->pinza->id)->delete();
        ArticleSurchage::where('article_id', $this->pinza->id)->delete();
        $this->pinza->load(['article_discounts', 'article_surchages']);

        // Margen 33% en la lista Distribuidor (camino de listas propias) y en la categoria temporal
        // para la lista Mayorista (camino por categoria).
        $this->pinza->price_types()->syncWithoutDetaching([
            $this->distribuidor->id => ['percentage' => 33, 'setear_precio_final' => 0],
        ]);

        $this->category = new Category();
        $this->category->user_id = 500;
        $this->category->name = 'zz-temp-categoria-redondeo-listas';
        $this->category->save();
        $this->category->price_types()->attach($this->mayorista->id, ['percentage' => 33]);

        // Mismo motivo que en CascadaDePreciosTest: con el IIBB del fixture activo el numero
        // dejaria de ser derivable de una multiplicacion y el test estaria midiendo otra cosa.
        $this->iibb = SaleTax::where('user_id', 500)->where('name', TestingFerreteriaSeeder::IMPUESTO_IIBB)->first();
        if (!is_null($this->iibb)) {
            $this->iibb_activo_original = $this->iibb->activo;
            $this->iibb->activo = 0;
            $this->iibb->save();
            ArticlePricesHelper::$sale_taxes_cache = [];
        }

        $this->desactivar_extension(self::SLUG_VENTAS_DOLARES);
    }

    protected function tearDown(): void
    {
        if (is_null($this->pinza) || is_null($this->user)) {
            parent::tearDown();
            return;
        }

        if (!is_null($this->iibb)) {
            $this->iibb->activo = $this->iibb_activo_original;
            $this->iibb->save();
            ArticlePricesHelper::$sale_taxes_cache = [];
        }

        PriceTypeSurchage::where('name', 'zz-temp-recargo-redondeo')->delete();

        $this->pinza->price_types()->detach([$this->distribuidor->id, $this->mayorista->id]);

        if (!is_null($this->category)) {
            $this->category->price_types()->detach();
            $this->category->delete();
        }

        $this->desactivar_extension(self::SLUG_LISTA_CATEGORIA);
        $this->desactivar_extension(self::SLUG_VENTAS_DOLARES);

        $this->pinza->refresh();
        foreach ($this->pinza_snapshot as $columna => $valor) {
            $this->pinza->$columna = $valor;
        }
        $this->pinza->timestamps = false;
        $this->pinza->save();

        $this->user->refresh();
        foreach ($this->user_snapshot as $columna => $valor) {
            $this->user->$columna = $valor;
        }
        $this->user->save();

        parent::tearDown();
    }

    protected function apagar_todos_los_redondeos()
    {
        foreach (self::REGLAS as $regla) {
            $this->user->$regla = 0;
        }
        $this->user->save();
        $this->user = $this->user->fresh();
    }

    protected function prender_solo($regla)
    {
        foreach (self::REGLAS as $cada) {
            $this->user->$cada = $cada === $regla ? 1 : 0;
        }
        $this->user->save();
        $this->user = $this->user->fresh();
    }

    /**
     * Mismo helper que CascadaDePreciosTest, y por el mismo bug real: `UserHelper::hasExtencion()`
     * lee `$user->extencions` como propiedad magica cacheada, y sync/detach no invalidan esa cache.
     */
    protected function activar_extension($slug)
    {
        $extension = ExtencionEmpresa::where('slug', $slug)->first();
        if (is_null($extension)) {
            $extension = new ExtencionEmpresa();
            $extension->name = $slug;
            $extension->slug = $slug;
            $extension->save();
        }
        $this->user->extencions()->syncWithoutDetaching([$extension->id]);
        $this->user->load('extencions');
    }

    protected function desactivar_extension($slug)
    {
        $extension = ExtencionEmpresa::where('slug', $slug)->first();
        if (!is_null($extension)) {
            $this->user->extencions()->detach($extension->id);
        }
        $this->user->load('extencions');
    }

    /** Camino de listas propias del usuario: `listas_de_precio = 1`. */
    protected function configurar_listas_propias()
    {
        $this->user->listas_de_precio = 1;
        $this->user->save();
        $this->desactivar_extension(self::SLUG_LISTA_CATEGORIA);
        $this->pinza->category_id = null;
        $this->pinza->save();
        $this->refrescar();
    }

    /** Camino por categoria: `listas_de_precio = 0` + extension `lista_de_precios_por_categoria`. */
    protected function configurar_por_categoria()
    {
        $this->user->listas_de_precio = 0;
        $this->user->save();
        $this->activar_extension(self::SLUG_LISTA_CATEGORIA);
        $this->pinza->category_id = $this->category->id;
        $this->pinza->save();
        $this->refrescar();
    }

    protected function refrescar()
    {
        $this->user = $this->user->fresh();
        $this->user->load('extencions');
        $this->pinza = $this->pinza->fresh();
    }

    /** Recalcula y persiste, sin pedir desglose. Es lo que hace el ABM al guardar un articulo. */
    protected function recalcular()
    {
        return ArticleHelper::setFinalPrice($this->pinza, null, $this->user, null, true);
    }

    /**
     * Lo mismo, pero pidiendo el desglose: es exactamente lo que hacen los dos endpoints del boton
     * "?" (`ArticleController::get_final_price_description()` y `::get_price_type_description()`).
     * Con un id de lista se pide el de esa lista; con null, el del precio final unico.
     */
    protected function desglose($price_type_id = null)
    {
        return ArticleHelper::setFinalPrice($this->pinza, null, $this->user, null, true, null, true, $price_type_id);
    }

    protected function precio_de_lista($price_type_id, $columna = 'final_price')
    {
        $this->pinza->load('price_types');
        $pivot = $this->pinza->price_types->where('id', $price_type_id)->first();

        if (is_null($pivot) || is_null($pivot->pivot->$columna)) {
            return null;
        }

        return (float) $pivot->pivot->$columna;
    }

    /**
     * Las cinco reglas, una por una, sobre el camino de listas propias.
     *
     * @test
     */
    public function cada_regla_de_redondeo_llega_al_precio_de_la_lista()
    {
        $this->configurar_listas_propias();

        $esperado = [
            'redondear_precios_en_centavos' => 1609.0,
            'redondear_precios_en_decenas'  => 1610.0,
            'redondear_de_a_50'             => 1650.0,
            'redondear_centenas_en_vender'  => 1600.0,
            'redondear_miles_en_vender'     => 2000.0,
        ];

        foreach ($esperado as $regla => $precio) {

            $this->prender_solo($regla);
            $this->recalcular();

            $this->assertEqualsWithDelta(
                $precio,
                $this->precio_de_lista($this->distribuidor->id),
                self::DELTA,
                'Con '.$regla.' prendida, el precio de la lista Distribuidor tiene que quedar en '.$precio
            );
        }
    }

    /**
     * La guarda de no-regresion para las cuentas SIN redondeo, que son la mayoria: el precio de la
     * lista tiene que seguir saliendo con sus centavos, exactamente como antes de esta mision.
     *
     * @test
     */
    public function sin_ninguna_regla_prendida_el_precio_de_la_lista_no_se_toca()
    {
        $this->configurar_listas_propias();
        $this->apagar_todos_los_redondeos();

        $this->recalcular();

        $this->assertEqualsWithDelta(
            1609.30,
            $this->precio_de_lista($this->distribuidor->id),
            self::DELTA,
            'Sin redondeo, el precio de la lista tiene que conservar los centavos'
        );
    }

    /**
     * El camino por categoria redondea igual que el principal. Es el invariante que el prompt
     * 379/03 queria (misma configuracion -> mismo precio, sin importar por que camino pase el
     * articulo), sostenido ahora desde el lado de redondear los dos en vez de no redondear ninguno.
     *
     * @test
     */
    public function el_camino_por_categoria_redondea_igual_que_el_principal()
    {
        $this->configurar_por_categoria();
        $this->prender_solo('redondear_precios_en_centavos');

        $this->recalcular();

        $this->assertEqualsWithDelta(
            1609.0,
            $this->precio_de_lista($this->mayorista->id),
            self::DELTA,
            'El precio de la lista por categoria tambien tiene que quedar redondeado'
        );
    }

    /**
     * El caso que reporto Lucas, con los numeros reales de produccion del articulo 1244 de
     * golonorte: costo 1045,454545, cuenta legacy (IVA al costo), margen 30% por categoria.
     *
     * @test
     */
    public function el_caso_de_golonorte_queda_en_1644()
    {
        $this->pinza->cost = 1045.454545;
        $this->pinza->save();

        $this->category->price_types()->updateExistingPivot($this->mayorista->id, ['percentage' => 30]);

        $this->configurar_por_categoria();
        $this->prender_solo('redondear_precios_en_centavos');

        $this->recalcular();

        $this->pinza->refresh();

        $this->assertEqualsWithDelta(
            1264.999999,
            (float) $this->pinza->costo_real,
            0.000005,
            'El costo real de partida tiene que ser el mismo que se midio en produccion'
        );

        /**
         * 🔴 1644, no 1645, y la diferencia importa: `$1.644,50` es lo que se VE, y es el pivote
         * guardado con dos decimales. El valor que se calcula es
         * 1264,999999 x 1,30 = **1644,4999987**, que redondeado da 1644. Redondear lo que se muestra
         * en lugar de lo que se calcula daria 1645 (round half up sobre 1644,50) -- el mismo error de
         * clase que APRENDER_NO_PARCHEAR #: una observacion sobre un valor renderizado no es una
         * observacion sobre el valor guardado.
         *
         * De donde sale el arrastre: `costo_real` se persiste con seis decimales y 1000/1,21 x 1,21
         * no vuelve exacto a 1265.
         */
        $this->assertEqualsWithDelta(
            1644.0,
            $this->precio_de_lista($this->mayorista->id),
            self::DELTA,
            'El precio mayorista tiene que quedar sin centavos: 1644'
        );
    }

    /**
     * Con un recargo de lista de por medio, las DOS columnas que persiste el pivote quedan
     * redondeadas y la ganancia cierra contra el precio redondeado.
     *
     * Numeros, a mano: 1609,30 -> redondeo de centavos -> 1609 -> el recargo resta 5% -> 1528,55 ->
     * redondeo de centavos -> 1529. La ganancia es neta de IVA y de impuestos sobre ventas, pero en
     * una cuenta legacy el IVA no participa del precio de la lista (ya esta en el costo) y no hay
     * sale_taxes activos, asi que la base es el precio mismo: 1529 - 1210 = 319.
     *
     * @test
     */
    public function con_recargo_de_lista_las_dos_columnas_quedan_redondeadas()
    {
        $this->configurar_listas_propias();
        $this->prender_solo('redondear_precios_en_centavos');

        $recargo = new PriceTypeSurchage();
        $recargo->name = 'zz-temp-recargo-redondeo';
        $recargo->price_type_id = $this->distribuidor->id;
        $recargo->percentage = 5;
        $recargo->save();

        $this->distribuidor->load('price_type_surchages');
        $this->refrescar();

        $this->recalcular();

        $this->assertEqualsWithDelta(
            1609.0,
            $this->precio_de_lista($this->distribuidor->id),
            self::DELTA,
            'final_price del pivote: el precio antes de los recargos, redondeado'
        );

        $this->assertEqualsWithDelta(
            1529.0,
            $this->precio_de_lista($this->distribuidor->id, 'precio_luego_de_recargos'),
            self::DELTA,
            'precio_luego_de_recargos: el recargo volvio a meter centavos y hay que redondear de nuevo'
        );

        $this->assertEqualsWithDelta(
            319.0,
            $this->precio_de_lista($this->distribuidor->id, 'monto_ganancia'),
            self::DELTA,
            'La ganancia tiene que cerrar contra el precio REDONDEADO, no contra el de antes'
        );
    }

    /**
     * El desglose del precio final unico no se vacia.
     *
     * `ArticleHelper.php` pasaba `$des` en la posicion del flag `$luego_del_precio_final` de
     * `aplicar_recargos()`, asi que el 4.º parametro quedaba en `[]` y todo lo acumulado hasta ahi
     * se perdia: el modal del boton "?" quedaba con los dos renglones que se emiten despues
     * (redondeo y precio final). Reproducido contra la produccion de golonorte el 16/9/2026.
     *
     * @test
     */
    public function el_desglose_del_precio_final_unico_no_se_vacia()
    {
        $this->configurar_listas_propias();
        $this->prender_solo('redondear_precios_en_centavos');

        $detalle = $this->desglose();

        $this->assertIsArray($detalle, 'El endpoint del "?" espera un array de renglones');

        $tiene_seccion_de_costo = false;

        foreach ($detalle as $linea) {
            if (DesglosePrecioHelper::es_seccion($linea, DesglosePrecioHelper::CLAVE_COSTO_REAL)) {
                $tiene_seccion_de_costo = true;
                break;
            }
        }

        $this->assertTrue(
            $tiene_seccion_de_costo,
            'El desglose tiene que arrancar en la seccion del costo real: si arranca en el redondeo, '
            .'alguien volvio a pasar $des en la posicion del flag de aplicar_recargos()'
        );

        $this->assertGreaterThan(
            2,
            count($detalle),
            'Dos renglones es el sintoma exacto del desglose descartado'
        );
    }

    /**
     * El "?" de una lista en una cuenta por categoria devuelve el desglose de ESA lista, y no el del
     * precio final unico -- que es lo que devolvia antes, y por eso el numero del modal no coincidia
     * con el de la tarjeta.
     *
     * @test
     */
    public function el_desglose_de_una_lista_por_categoria_es_el_de_esa_lista()
    {
        $this->configurar_por_categoria();
        $this->prender_solo('redondear_precios_en_centavos');

        $detalle = $this->desglose($this->mayorista->id);

        $this->assertIsArray($detalle);

        $tiene_seccion_de_lista = false;
        $tiene_seccion_del_precio_final_unico = false;
        $cierre = null;

        foreach ($detalle as $linea) {
            if (DesglosePrecioHelper::es_seccion($linea, DesglosePrecioHelper::CLAVE_LISTA)) {
                $tiene_seccion_de_lista = true;
            }
            if (DesglosePrecioHelper::es_seccion($linea, DesglosePrecioHelper::CLAVE_PRECIO_FINAL)) {
                $tiene_seccion_del_precio_final_unico = true;
            }
            if (is_array($linea) && isset($linea['etiqueta']) && $linea['etiqueta'] === 'Precio final de la lista') {
                $cierre = $linea;
            }
        }

        $this->assertTrue(
            $tiene_seccion_de_lista,
            'El desglose tiene que traer la seccion de la lista que se pidio'
        );

        $this->assertFalse(
            $tiene_seccion_del_precio_final_unico,
            'La seccion del precio final unico no pertenece al desglose de una lista: sugiere que el '
            .'precio de la lista sale de ahi, y no sale'
        );

        $this->assertNotNull($cierre, 'El desglose de una lista tiene que cerrar con el precio de la lista');

        $this->assertSame(
            Numbers::price(1609, true),
            $cierre['valor'],
            'El renglon de cierre tiene que mostrar el precio REDONDEADO de la lista, el mismo que la tarjeta'
        );
    }
}
