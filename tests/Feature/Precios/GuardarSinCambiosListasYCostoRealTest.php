<?php

namespace Tests\Feature\Precios;

use App\Http\Controllers\Helpers\article\ArticlePricesHelper;
use App\Models\Article;
use App\Models\ArticleDiscount;
use App\Models\Iva;
use App\Models\PriceType;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ListasSiempre\ListasSiempreTestCase;

/**
 * Guardar un artículo sin cambiarle nada NO mueve las listas ni el costo real (misión
 * guardar-sin-cambios-listas-y-costo-real).
 *
 * 🔴 QUE PROTEGE. Contrato de actualización de clientes: abrir un artículo existente en la ficha y
 * guardarlo sin cambios no puede cambiar ningún valor de precio ni de costo, ni del artículo ni de sus
 * listas. La 4.3.72 cerró los derivados (`base_margen*`) y el «precio anterior». Quedaron dos casos de
 * producción (humo de actualización, paso 1, con la 4.3.73):
 *
 *  - CASO A (Golden-Breeze, artículo 10): cuenta con ventas en dólares, listas con precio fijado a mano
 *    por moneda y las filas de `article_price_type` en NULL. El espejo de las monedas en pesos hacia la
 *    pivot las completaba (-100/0.00, 220.18/1108.00, ...) aunque nadie tocó las listas.
 *  - CASO B (MBMalizia, artículo 2689): `costo_real` guardado sin la bonificación del proveedor (15 %)
 *    que la fila de `article_discounts` ya tiene; el guardado lo recalculaba aplicándola.
 *
 * Un cambio REAL (costo, IVA, proveedor, precio de una lista) se sigue calculando como siempre.
 *
 * Cada test crea sus datos (dueño propio, sin residuos).
 *
 * IMPORTANTE: PHP 7.4, sin sintaxis de PHP 8 (ver .mision/RESTRICCIONES.md).
 *
 * @group precios-por-lista
 */
class GuardarSinCambiosListasYCostoRealTest extends ListasSiempreTestCase
{
    const ARS = 1;
    const USD = 2;

    protected function tearDown(): void
    {
        ArticlePricesHelper::$sale_taxes_cache = [];

        parent::tearDown();
    }

    // ------------------------------------------------------------------------------------------
    // Fotos y caminos (mismos criterios que GuardarSinCambiosDerivadosNullTest)
    // ------------------------------------------------------------------------------------------

    /**
     * Foto de todo lo que el guardado podría mover: fila de `articles`, filas de `article_price_type` y de
     * `article_price_type_monedas` del artículo, y el historial. Sólo se saca `updated_at`.
     *
     * @return array clave 'tabla[selector].columna' => valor (string o null)
     */
    protected function foto($article_id)
    {
        $foto = [];

        $aplanar = function ($prefijo, $fila) use (&$foto) {
            foreach ((array) $fila as $columna => $valor) {
                if ($columna === 'updated_at') {
                    continue;
                }
                $foto[$prefijo . '.' . $columna] = is_null($valor) ? null : (string) $valor;
            }
        };

        $aplanar('articles', DB::table('articles')->where('id', $article_id)->first());

        foreach (DB::table('article_price_type')->where('article_id', $article_id)->orderBy('price_type_id')->get() as $fila) {
            $aplanar('article_price_type[lista=' . $fila->price_type_id . ']', $fila);
        }

        foreach (DB::table('article_price_type_monedas')->where('article_id', $article_id)->orderBy('price_type_id')->orderBy('moneda_id')->get() as $fila) {
            $aplanar('article_price_type_monedas[lista=' . $fila->price_type_id . ',moneda=' . $fila->moneda_id . ']', $fila);
        }

        $foto['price_changes.count'] = (string) DB::table('price_changes')->where('article_id', $article_id)->count();

        return $foto;
    }

    protected function diferencias($antes, $despues)
    {
        $diferencias = [];

        foreach (array_unique(array_merge(array_keys($antes), array_keys($despues))) as $clave) {

            $a = array_key_exists($clave, $antes) ? $antes[$clave] : '<<no existe>>';
            $d = array_key_exists($clave, $despues) ? $despues[$clave] : '<<no existe>>';

            if ($a !== $d) {
                $diferencias[$clave] = [$a, $d];
            }
        }

        ksort($diferencias);

        return $diferencias;
    }

    protected function describir($diferencias)
    {
        $lineas = [];

        foreach ($diferencias as $clave => $par) {
            $lineas[] = $clave . ': ' . var_export($par[0], true) . ' -> ' . var_export($par[1], true);
        }

        return "\n" . implode("\n", $lineas);
    }

    /**
     * Camino de la ficha: GET api/article/{id} y PUT del `model` devuelto, con los ajustes que pida el
     * test (`$ajustar` recibe el modelo y lo devuelve).
     *
     * @return \Illuminate\Testing\TestResponse
     */
    protected function guardar_sin_cambios($article_id, $ajustar = null)
    {
        $modelo = $this->getJson('api/article/' . $article_id)->assertOk()->json('model');

        if (!is_null($ajustar)) {
            $modelo = $ajustar($modelo);
        }

        return $this->putJson('api/article/' . $article_id, $modelo);
    }

    protected function assertSinDiferencias($antes, $despues, $mensaje)
    {
        $diferencias = $this->diferencias($antes, $despues);

        $this->assertEquals([], $diferencias, $mensaje . $this->describir($diferencias));
    }

    // ------------------------------------------------------------------------------------------
    // CASO A — Golden-Breeze: pivots en NULL con monedas cargadas
    // ------------------------------------------------------------------------------------------

    /**
     * Cuenta «tipo Golden-Breeze»: dólar 1300, cotiza en dólares, IVA al costo, descuentos antes del margen,
     * RRII, ventas en dólares, `listas_de_precio` prendido y SIN lista «Precio final» (las listas de Golden
     * son seis, todas con precio fijado a mano y margen NULL).
     *
     * @return array [User, PriceType[]]
     */
    protected function cuenta_golden($cantidad_de_listas = 6)
    {
        $dueno = $this->dueno_nuevo('golden-lc', [
            'listas_de_precio'           => 1,
            'dollar'                     => 1300,
            'cotizar_precios_en_dolares' => 1,
            'aplicar_iva_al_costo'       => 1,
            'aplicar_descuentos_en_articulos_antes_del_margen_de_ganancia' => 1,
            'condicion_iva_precios'      => 'RRII',
        ]);

        $dueno = $this->con_extension($dueno, 'ventas_en_dolares');

        DB::table('sale_taxes')->where('user_id', $dueno->id)->delete();
        ArticlePricesHelper::$sale_taxes_cache = [];

        $listas = [];

        for ($i = 0; $i < $cantidad_de_listas; $i++) {
            $listas[] = PriceType::create([
                'name'                                 => 'zz Golden lista ' . ($i + 1),
                'percentage'                           => null,
                'position'                             => $i + 1,
                'user_id'                              => $dueno->id,
                'setear_precio_final'                  => 1,
                'incluir_en_lista_de_precios_de_excel' => 1,
            ]);
        }

        $this->actingAs($dueno, 'web');

        return [User::find($dueno->id), $listas];
    }

    /**
     * Artículo 10 de Golden: costo 0.22 en dólares, IVA 21 % al costo, y por lista una fila en pesos con el
     * precio fijado a mano (listas 1 y 2 en 0.00, el resto 1108/1108/1254/1085) y la fila en dólares
     * fijada en 0.00. Se lo lleva a un estado estable (recalculado y guardado una vez por la ficha) y recién
     * ahí se lo deja como lo mide producción: filas de `article_price_type` en NULL.
     *
     * @return \App\Models\Article
     */
    protected function articulo_golden_con_pivots_en_null($dueno, array $listas)
    {
        $iva_21 = Iva::where('percentage', 21)->first();

        $articulo = $this->articulo($dueno, 'golden10', [
            'cost'            => '0.22',
            'cost_in_dollars' => 1,
            'aplicar_iva'     => 1,
            'iva_id'          => $iva_21->id,
        ]);

        $precios_en_pesos = [0, 0, 1108, 1108, 1254, 1085];

        foreach ($listas as $i => $lista) {

            $articulo->price_types()->attach($lista->id, [
                'percentage'                     => null,
                'setear_precio_final'            => 0,
                'incluir_en_excel_para_clientes' => 1,
            ]);

            foreach ([self::ARS, self::USD] as $moneda) {
                $articulo->price_type_monedas()->create([
                    'price_type_id'             => $lista->id,
                    'moneda_id'                 => $moneda,
                    'percentage'                => 0,
                    'final_price'               => $moneda === self::ARS ? $precios_en_pesos[$i] : 0,
                    'setear_precio_final'       => 1,
                    'cotizar_desde_otra_moneda' => 0,
                ]);
            }
        }

        $this->recalcular($articulo, $dueno);

        // La primera pasada por la ficha normaliza lo que NADA tiene que ver con el precio (nombre, slug,
        // marca de Tienda Nube): la foto «antes» ya es un estado estable.
        $this->guardar_sin_cambios($articulo->id)->assertOk();

        // Estado medido en Golden: las pivots nunca se espejaron.
        DB::table('article_price_type')->where('article_id', $articulo->id)->update([
            'percentage'               => null,
            'final_price'              => null,
            'previus_final_price'      => null,
            'precio_luego_de_recargos' => null,
            'monto_ganancia'           => null,
            'setear_precio_final'      => 0,
        ]);

        return Article::find($articulo->id);
    }

    /**
     * CA1. Hoy falla: el PUT sin cambios completa las 6 pivots con el espejo de las monedas en pesos
     * (-100.00/0.00 en las listas 1 y 2, 220.18/1108.00, ... en las otras): 12 valores. Esperado: siguen NULL
     * y la foto completa es la misma.
     *
     * @return void
     */
    public function test_a_golden_put_sin_cambios_deja_las_pivots_en_null()
    {
        list($dueno, $listas) = $this->cuenta_golden(6);
        $articulo = $this->articulo_golden_con_pivots_en_null($dueno, $listas);

        $antes = $this->foto($articulo->id);

        $this->guardar_sin_cambios($articulo->id)->assertOk();

        $despues = $this->foto($articulo->id);

        foreach ($listas as $lista) {
            $pivote = $this->pivote($articulo, $lista);

            $this->assertNull($pivote->percentage, 'percentage NULL de la lista ' . $lista->id . ' fue completado por un guardado sin cambios.');
            $this->assertNull($pivote->final_price, 'final_price NULL de la lista ' . $lista->id . ' fue completado por un guardado sin cambios.');
        }

        $this->assertSinDiferencias($antes, $despues, 'Guardar sin cambios movió la foto completa:');
    }

    /**
     * CA2. Cambio REAL de costo por la ficha (0.22 -> 0.44): las pivots se espejan como siempre, las seis,
     * con el mismo valor que las filas en pesos de las monedas, y el margen de las listas con precio a mano
     * se re-deriva del costo nuevo.
     *
     * @return void
     */
    public function test_a_golden_un_cambio_real_de_costo_espeja_todas_las_pivots_como_siempre()
    {
        list($dueno, $listas) = $this->cuenta_golden(6);
        $articulo = $this->articulo_golden_con_pivots_en_null($dueno, $listas);

        $this->guardar_sin_cambios($articulo->id, function ($modelo) {
            $modelo['cost'] = '0.44';

            return $modelo;
        })->assertOk();

        foreach ($listas as $i => $lista) {

            $pivote = $this->pivote($articulo, $lista);
            $moneda = DB::table('article_price_type_monedas')
                ->where('article_id', $articulo->id)
                ->where('price_type_id', $lista->id)
                ->where('moneda_id', self::ARS)
                ->first();

            $this->assertNotNull($pivote->final_price, 'Un cambio real de costo no espejó el precio de la lista ' . $lista->id . '.');
            $this->assertNotNull($pivote->percentage, 'Un cambio real de costo no espejó el margen de la lista ' . $lista->id . '.');
            $this->assertEquals((float) $moneda->final_price, (float) $pivote->final_price, 'final_price de la pivot distinto de la fila en pesos, lista ' . $lista->id);
            $this->assertEquals((float) $moneda->percentage, (float) $pivote->percentage, 'percentage de la pivot distinto de la fila en pesos, lista ' . $lista->id);
        }

        // 1108 sobre un costo de 0.44 x 1.21 x 1300 = 692.12: 60,09 %.
        $this->assertEqualsWithDelta(60.09, (float) $this->pivote($articulo, $listas[2])->percentage, 0.01);
    }

    /**
     * CA3. Cambio REAL de una lista por la ficha (la fila en pesos de la lista 3 pasa de 1108 a 1200):
     * las pivots se espejan como siempre; la de esa lista queda en 1200.
     *
     * @return void
     */
    public function test_a_golden_un_cambio_real_de_una_lista_espeja_las_pivots_como_siempre()
    {
        list($dueno, $listas) = $this->cuenta_golden(6);
        $articulo = $this->articulo_golden_con_pivots_en_null($dueno, $listas);

        $lista_editada = $listas[2];

        $this->guardar_sin_cambios($articulo->id, function ($modelo) use ($lista_editada) {

            foreach ($modelo['price_type_monedas'] as $i => $fila) {
                if ((int) $fila['price_type_id'] === (int) $lista_editada->id && (int) $fila['moneda_id'] === self::ARS) {
                    $modelo['price_type_monedas'][$i]['final_price'] = 1200;
                }
            }

            return $modelo;
        })->assertOk();

        $this->assertEquals(1200, (float) $this->pivote($articulo, $lista_editada)->final_price, 'El precio nuevo de la lista no llegó a la pivot.');

        foreach ($listas as $lista) {
            $pivote = $this->pivote($articulo, $lista);

            $this->assertNotNull($pivote->final_price, 'Un cambio real de una lista no espejó la lista ' . $lista->id . '.');
            $this->assertNotNull($pivote->percentage, 'Un cambio real de una lista no espejó el margen de la lista ' . $lista->id . '.');
        }
    }

    // ------------------------------------------------------------------------------------------
    // CASO B — MBMalizia: costo_real sin la bonificación del proveedor
    // ------------------------------------------------------------------------------------------

    /**
     * Cuenta «tipo MBMalizia»: dólar 1450, IVA al costo, descuentos antes del margen, sin listas.
     *
     * @return \App\Models\User
     */
    protected function cuenta_malizia()
    {
        $dueno = $this->dueno_nuevo('malizia-lc', [
            'listas_de_precio'           => 0,
            'dollar'                     => 1450,
            'cotizar_precios_en_dolares' => 0,
            'aplicar_iva_al_costo'       => 1,
            'aplicar_descuentos_en_articulos_antes_del_margen_de_ganancia' => 1,
            'condicion_iva_precios'      => 'RRII',
        ]);

        DB::table('sale_taxes')->where('user_id', $dueno->id)->delete();
        ArticlePricesHelper::$sale_taxes_cache = [];

        $this->actingAs($dueno, 'web');

        return User::find($dueno->id);
    }

    /**
     * Artículo 2689 de MBMalizia: costo 10.400.000, IVA 10,5 %, con proveedor, el `costo_real` guardado en
     * 11.492.000 (cost x 1,105: SIN la bonificación) y una fila de descuento `bonificacion_proveedor` de 15 %
     * de origen `ficha_proveedor` que se agregó después, sin recalcular.
     *
     * @return array [Article, Provider]
     */
    protected function articulo_malizia($dueno)
    {
        $iva_10_5 = Iva::where('percentage', '10.5')->first();

        $proveedor = Provider::create(['name' => 'zz Proveedor malizia', 'user_id' => $dueno->id]);

        $articulo = $this->articulo($dueno, 'malizia2689', [
            'cost'                           => '10400000',
            'aplicar_iva'                    => 1,
            'iva_id'                         => $iva_10_5->id,
            'provider_id'                    => $proveedor->id,
            'apply_provider_percentage_gain' => 1,
        ]);

        $this->recalcular($articulo, $dueno);

        // Normaliza nombre/slug/Tienda Nube: la foto «antes» ya es un estado estable.
        $this->guardar_sin_cambios($articulo->id)->assertOk();

        $this->assertEquals(11492000, (float) DB::table('articles')->where('id', $articulo->id)->value('costo_real'), 'Precondición: costo_real = cost x 1,105.');

        ArticleDiscount::create([
            'article_id' => $articulo->id,
            'percentage' => 15,
            'tipo'       => ArticleDiscount::TIPO_BONIFICACION_PROVEEDOR,
            'origen'     => ArticleDiscount::ORIGEN_FICHA_PROVEEDOR,
            'provider_id' => $proveedor->id,
        ]);

        return [Article::find($articulo->id), $proveedor];
    }

    /**
     * CA4. Hoy falla: el PUT sin cambios recalcula `costo_real` aplicando el 15 % (9.768.200). Esperado:
     * `costo_real` y toda la foto quedan como estaban.
     *
     * @return void
     */
    public function test_b_malizia_put_sin_cambios_no_cambia_el_costo_real()
    {
        $dueno = $this->cuenta_malizia();
        list($articulo) = $this->articulo_malizia($dueno);

        $antes = $this->foto($articulo->id);

        $this->guardar_sin_cambios($articulo->id)->assertOk();

        $despues = $this->foto($articulo->id);

        $this->assertEquals(11492000, (float) DB::table('articles')->where('id', $articulo->id)->value('costo_real'), 'Un guardado sin cambios movió el costo real.');

        $this->assertSinDiferencias($antes, $despues, 'Guardar sin cambios movió la foto completa:');
    }

    /**
     * CA5a. Cambio REAL de costo (10.400.000 -> 11.000.000): `costo_real` se recalcula con la bonificación
     * y el IVA, como siempre: 11.000.000 x 0,85 x 1,105.
     *
     * @return void
     */
    public function test_b_malizia_un_cambio_real_de_costo_recalcula_el_costo_real()
    {
        $dueno = $this->cuenta_malizia();
        list($articulo) = $this->articulo_malizia($dueno);

        $this->guardar_sin_cambios($articulo->id, function ($modelo) {
            $modelo['cost'] = '11000000';

            return $modelo;
        })->assertOk();

        $this->assertEquals(10331750, (float) DB::table('articles')->where('id', $articulo->id)->value('costo_real'), 'Un cambio real de costo no recalculó el costo real.');
    }

    /**
     * CA5b. Cambio REAL de IVA (10,5 % -> 21 %): `costo_real` se recalcula: 10.400.000 x 0,85 x 1,21.
     *
     * @return void
     */
    public function test_b_malizia_un_cambio_real_de_iva_recalcula_el_costo_real()
    {
        $dueno = $this->cuenta_malizia();
        list($articulo) = $this->articulo_malizia($dueno);

        $iva_21 = Iva::where('percentage', 21)->first();

        $this->guardar_sin_cambios($articulo->id, function ($modelo) use ($iva_21) {
            $modelo['iva_id'] = $iva_21->id;

            return $modelo;
        })->assertOk();

        $this->assertEquals(10696400, (float) DB::table('articles')->where('id', $articulo->id)->value('costo_real'), 'Un cambio real de IVA no recalculó el costo real.');
    }

    /**
     * CA5c. Cambio REAL de proveedor: `costo_real` se recalcula con los descuentos que el artículo tenga.
     *
     * @return void
     */
    public function test_b_malizia_un_cambio_real_de_proveedor_recalcula_el_costo_real()
    {
        $dueno = $this->cuenta_malizia();
        list($articulo) = $this->articulo_malizia($dueno);

        $otro = Provider::create(['name' => 'zz Otro proveedor malizia', 'user_id' => $dueno->id]);

        $this->guardar_sin_cambios($articulo->id, function ($modelo) use ($otro) {
            $modelo['provider_id'] = $otro->id;

            return $modelo;
        })->assertOk();

        $this->assertEquals(9768200, (float) DB::table('articles')->where('id', $articulo->id)->value('costo_real'), 'Un cambio real de proveedor no recalculó el costo real.');
    }
}
