<?php

namespace Tests\Feature\Precios\RecalculoEnLote;

use App\Models\Article;
use App\Models\ArticleDiscountBlanco;
use App\Models\ArticlePriceTypeMoneda;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 🔴 EL CORAZÓN DE LA MISIÓN recalculo-precios-motor-rapido (28/9/2026): el motor en lote deja la
 * base EXACTAMENTE igual que el recálculo por artículo de hoy.
 *
 * Cada test arma una configuración de cuenta distinta (las que el cálculo de precios distingue),
 * corre los dos caminos sobre la misma base (RecalculoEnLoteTestCase::comparar_caminos()) y
 * compara, campo por campo: todas las columnas de `articles` (updated_at incluido, con el reloj
 * congelado), las filas de article_price_type y de article_price_type_monedas, los price_changes
 * con sus filas de price_change_price_type, y qué artículos cuentan como "cambiaron de precio".
 * Cada comparación corre DOS pasadas en cada camino: la segunda no tiene que cambiar nada.
 *
 * Casi todas usan el escenario general (RecalculoEnLoteTestCase::escenario_general()): un
 * artículo por cada rama del cálculo que no depende de la cuenta (descuentos y recargos en % y
 * monto, antes y después del precio final; margen propio; dólar global y del proveedor; lista de
 * precios del proveedor; unidades individuales; margen de categoría; precio manual; margen con
 * precio viejo, que es la rama que borra `price`; sin costo ni precio; sin IVA; y uno que no cambia).
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group recalculo-en-lote
 */
class Equivalencia_con_el_camino_por_articulo_Test extends RecalculoEnLoteTestCase
{
    /**
     * La cuenta de Servian (sección 2 del plan): sin listas, IVA al costo legacy
     * (aplicar_iva_al_costo = 1, sin migrar), redondeo de centavos, sin impuestos sobre ventas,
     * un proveedor con margen del 70 % y descuentos de ficha en porcentaje y en monto.
     *
     * @return void
     */
    public function test_cuenta_simple_con_iva_al_costo_legacy_como_servian()
    {
        $dueno = $this->crear_dueno([
            'aplicar_iva_al_costo'            => 1,
            'usar_condicion_fiscal_en_costeo' => 0,
            'redondear_precios_en_centavos'   => 1,
        ]);

        $proveedor = $this->crear_proveedor($dueno, ['percentage_gain' => 70]);

        $con_descuentos = $this->crear_articulo($dueno, ['cost' => 1234.56, 'provider_id' => $proveedor->id]);
        $this->descuento($con_descuentos, ['percentage' => 10]);
        $this->descuento($con_descuentos, ['amount' => 50]);

        $con_iva_10 = $this->crear_articulo($dueno, ['cost' => 999.99, 'provider_id' => $proveedor->id, 'iva_id' => self::IVA_10_5]);
        $this->descuento($con_iva_10, ['percentage' => 7.5]);

        $con_margen_propio = $this->crear_articulo($dueno, ['cost' => 250, 'provider_id' => $proveedor->id, 'percentage_gain' => 35]);

        $sin_cambio = $this->crear_articulo($dueno, ['cost' => 480.40, 'provider_id' => $proveedor->id]);

        $sin_costo_ni_precio = $this->crear_articulo($dueno, ['provider_id' => $proveedor->id]);

        $precio_manual = $this->crear_articulo($dueno, ['price' => 1500, 'apply_provider_percentage_gain' => 0]);

        $ids = [
            $con_descuentos->id,
            $con_iva_10->id,
            $con_margen_propio->id,
            $sin_cambio->id,
            $sin_costo_ni_precio->id,
            $precio_manual->id,
        ];

        /* Calentamiento: precios calculados y estables. */
        $this->recalcular_como_hoy($ids, $dueno->id);

        $this->pisar_precio_final([$con_descuentos->id, $con_iva_10->id, $con_margen_propio->id, $precio_manual->id], 1);

        $r = $this->comparar_caminos($ids, $dueno->id, 2);

        /* Guardas: si el escenario no produjo cambios, la comparación no prueba nada. */
        $this->assertEqualsCanonicalizing(
            [$con_descuentos->id, $con_iva_10->id, $con_margen_propio->id, $precio_manual->id],
            $r['cambiaron_hoy'],
            'El escenario tenía que cambiar exactamente los cuatro precios pisados.'
        );

        $this->assertCount(4, $r['foto_hoy']['cambios'], 'Cuatro artículos con su price_change.');
        $this->assertArrayNotHasKey($sin_cambio->id, $r['foto_hoy']['cambios'], 'El que no cambió de precio no genera price_change.');

        /*
         * updated_at: el camino de hoy se lo pone a todo artículo con costo (el save() con
         * timestamps que sigue al cálculo del costo real), y a ninguno sin costo. El motor lo
         * reproduce: por eso la foto lo compara por valor y acá se deja escrito.
         */
        $this->assertSame(self::AHORA, $r['foto_hoy']['articles'][$sin_cambio->id]['updated_at'], 'Referencia: hoy el recálculo toca updated_at de un artículo con costo aunque su precio no cambie.');
        $this->assertNotSame(self::AHORA, $r['foto_hoy']['articles'][$sin_costo_ni_precio->id]['updated_at'], 'Referencia: hoy el recálculo no toca updated_at de un artículo sin costo.');
    }

    /**
     * Cuenta migrada Responsable Inscripto: el IVA no va al costo, se suma después del margen.
     * Con margen general de la cuenta y el escenario completo.
     *
     * @return void
     */
    public function test_cuenta_migrada_responsable_inscripto()
    {
        $dueno = $this->crear_dueno([
            'usar_condicion_fiscal_en_costeo' => 1,
            'condicion_iva_precios'           => User::CONDICION_RRII,
            'aplicar_iva_al_costo'            => 1,
            'percentage_gain'                 => 10,
        ]);

        $articulos = $this->escenario_general($dueno);

        $this->comparar_escenario_general($dueno, $articulos);
    }

    /**
     * Cuenta migrada Monotributista: el IVA no participa del precio en ningún punto.
     *
     * @return void
     */
    public function test_cuenta_migrada_monotributista()
    {
        $dueno = $this->crear_dueno([
            'usar_condicion_fiscal_en_costeo' => 1,
            'condicion_iva_precios'           => User::CONDICION_MT,
        ]);

        $articulos = $this->escenario_general($dueno);

        $this->comparar_escenario_general($dueno, $articulos);
    }

    /**
     * listas_de_precio = 1 con tres listas y recargos de lista (en % y en monto). Un artículo con
     * margen propio en una lista, otro con el precio de una lista fijado a mano, y otro sin
     * ninguna lista atada antes de la corrida (la corrida las ata: INSERT en el pivot).
     *
     * @return void
     */
    public function test_listas_de_precio_con_tres_listas_y_recargos_de_lista()
    {
        $dueno = $this->crear_dueno(['listas_de_precio' => 1]);

        $minorista = $this->crear_lista($dueno, 'Minorista', 60, 3, [['percentage' => 5], ['amount' => 12.5]]);
        $mayorista = $this->crear_lista($dueno, 'Mayorista', 35, 2, [['percentage' => 2.5]]);
        $gremio    = $this->crear_lista($dueno, 'Gremio', 20, 1);

        $articulos = $this->escenario_general($dueno, function ($dueno, $articulos) use ($minorista, $mayorista, $gremio) {

            /* Margen propio del artículo en una lista. */
            Article::find($articulos['completo'])->price_types()->attach($mayorista->id, ['percentage' => 42]);

            /* El precio de la lista fijado a mano: el margen se deriva de él y no se redondea. */
            Article::find($articulos['margen_propio'])->price_types()->attach($minorista->id, [
                'setear_precio_final' => 1,
                'final_price'         => 777.77,
            ]);
        });

        /* Uno que llega a la corrida sin ninguna lista atada: la corrida las ata. */
        DB::table('article_price_type')->where('article_id', $articulos['lista_del_proveedor'])->delete();

        $r = $this->comparar_escenario_general($dueno, $articulos);

        $this->assertGreaterThanOrEqual(3 * count($articulos), count($r['foto_hoy']['pivots']), 'Cada artículo tenía que quedar con sus tres listas.');

        $con_listas = 0;
        foreach ($r['foto_hoy']['cambios'] as $cambios) {
            foreach ($cambios as $cambio) {
                if (count($cambio['listas']) > 0) {
                    $con_listas++;
                }
            }
        }
        $this->assertGreaterThan(0, $con_listas, 'Los price_changes tenían que llevar el precio de cada lista.');
    }

    /**
     * Extensión lista_de_precios_por_categoria (golonorte): las listas y sus márgenes salen de la
     * subcategoría (si tiene porcentajes) o de la categoría. Varios artículos por categoría, para
     * que el memo del modo lote se reuse; una subcategoría sin porcentajes (cae a la categoría) y
     * una lista de la categoría sin porcentaje (la rama que pone las cinco columnas en null).
     *
     * @return void
     */
    public function test_listas_por_categoria_y_subcategoria()
    {
        $dueno = $this->crear_dueno([], ['lista_de_precios_por_categoria']);

        $lista_a = $this->crear_lista($dueno, 'Lista A', null, 1, [['percentage' => 3]]);
        $lista_b = $this->crear_lista($dueno, 'Lista B', null, 2, [['amount' => 10]]);

        $categoria = $this->crear_categoria($dueno);
        $categoria->price_types()->attach($lista_a->id, ['percentage' => 45]);
        $categoria->price_types()->attach($lista_b->id, ['percentage' => null]);

        $con_porcentajes = $this->crear_subcategoria($dueno, $categoria);
        $con_porcentajes->price_types()->attach($lista_a->id, ['percentage' => 55]);
        $con_porcentajes->price_types()->attach($lista_b->id, ['percentage' => 65]);

        $sin_porcentajes = $this->crear_subcategoria($dueno, $categoria);

        $proveedor = $this->crear_proveedor($dueno, ['percentage_gain' => 20]);

        $ids = [];
        $a_pisar = [];

        for ($i = 0; $i < 3; $i++) {

            $en_categoria = $this->crear_articulo($dueno, ['cost' => 100 + $i * 17.3, 'provider_id' => $proveedor->id, 'category_id' => $categoria->id]);
            $en_sub       = $this->crear_articulo($dueno, ['cost' => 250 + $i * 9.1, 'provider_id' => $proveedor->id, 'category_id' => $categoria->id, 'sub_category_id' => $con_porcentajes->id]);
            $en_sub_vacia = $this->crear_articulo($dueno, ['cost' => 75 + $i * 3.33, 'provider_id' => $proveedor->id, 'category_id' => $categoria->id, 'sub_category_id' => $sin_porcentajes->id]);

            /* La lista B de la categoría no tiene porcentaje: solo actualiza el par si ya existe. */
            $en_categoria->price_types()->attach($lista_b->id, ['percentage' => 99, 'final_price' => 5]);

            $ids[] = $en_categoria->id;
            $ids[] = $en_sub->id;
            $ids[] = $en_sub_vacia->id;

            $a_pisar[] = $en_categoria->id;
            $a_pisar[] = $en_sub->id;
        }

        $this->recalcular_como_hoy($ids, $dueno->id);

        $this->pisar_precio_final($a_pisar, 1);

        /* Y los pivots viejos, para que la corrida los tenga que reescribir. */
        DB::table('article_price_type')->whereIn('article_id', $ids)->update(['final_price' => 1, 'price' => 1]);

        $r = $this->comparar_caminos($ids, $dueno->id, 2);

        $this->assertCount(count($a_pisar), $r['cambiaron_hoy']);
        $this->assertGreaterThanOrEqual(2 * count($ids) - 3, count($r['foto_hoy']['pivots']), 'Los artículos tenían que quedar atados a las listas de su categoría o subcategoría.');
    }

    /**
     * Extensión ventas_en_dolares (listas por lista y por moneda), con y sin cotización cruzada:
     * normal, pesos derivado de dólares, dólares derivado de pesos, las dos marcadas (vuelve al
     * normal), un precio fijado a mano en una moneda, y un artículo sin costo que solo cotiza
     * desde el precio fijo de la otra moneda. El espejo del precio en pesos llega a la pivot.
     *
     * @return void
     */
    public function test_ventas_en_dolares_con_y_sin_cotizacion_cruzada()
    {
        $dueno = $this->crear_dueno(['listas_de_precio' => 1, 'dollar' => 1250.75], ['ventas_en_dolares']);

        $lista_1 = $this->crear_lista($dueno, 'Pesos y dolares 1', 40, 1);
        $lista_2 = $this->crear_lista($dueno, 'Pesos y dolares 2', 25, 2);

        $proveedor = $this->crear_proveedor($dueno, ['percentage_gain' => 10]);

        $configuraciones = [
            'normal'          => [0, 0, 0, 0],
            'pesos_de_dolar'  => [1, 0, 0, 0],
            'dolar_de_pesos'  => [0, 1, 0, 0],
            'ambos_marcados'  => [1, 1, 0, 0],
            'pesos_fijado'    => [0, 0, 1, 0],
        ];

        $ids = [];

        foreach ($configuraciones as $nombre => $config) {

            $article = $this->crear_articulo($dueno, [
                'name'            => 'zz Dolares ' . $nombre,
                'cost'            => 80 + count($ids) * 11.5,
                'cost_in_dollars' => count($ids) % 2,
                'provider_id'     => $proveedor->id,
            ]);

            foreach ([$lista_1, $lista_2] as $lista) {

                $article->price_types()->attach($lista->id, ['percentage' => $lista->percentage, 'final_price' => 3]);

                $this->entrada_de_moneda($article, $lista, self::ARS, [
                    'percentage'                => 50,
                    'final_price'               => $config[2] ? 45678.9 : 0,
                    'setear_precio_final'       => $config[2],
                    'cotizar_desde_otra_moneda' => $config[0],
                ]);

                $this->entrada_de_moneda($article, $lista, self::USD, [
                    'percentage'                => 30,
                    'final_price'               => $config[3] ? 19.99 : 0,
                    'setear_precio_final'       => $config[3],
                    'cotizar_desde_otra_moneda' => $config[1],
                ]);
            }

            $ids[] = $article->id;
        }

        /* Sin costo: solo cotiza pesos desde el precio fijo en dólares. */
        $sin_costo = $this->crear_articulo($dueno, ['name' => 'zz Dolares sin costo', 'provider_id' => $proveedor->id]);
        $sin_costo->price_types()->attach($lista_1->id, ['percentage' => 40, 'final_price' => 3]);
        $this->entrada_de_moneda($sin_costo, $lista_1, self::ARS, ['percentage' => 0, 'final_price' => 0, 'setear_precio_final' => 0, 'cotizar_desde_otra_moneda' => 1]);
        $this->entrada_de_moneda($sin_costo, $lista_1, self::USD, ['percentage' => 0, 'final_price' => 12.34, 'setear_precio_final' => 1, 'cotizar_desde_otra_moneda' => 0]);
        $ids[] = $sin_costo->id;

        $this->recalcular_como_hoy($ids, $dueno->id);

        /* Precios viejos en las entradas y en la pivot: la corrida los tiene que reescribir. */
        $this->pisar_precio_final($ids, 1);
        DB::table('article_price_type_monedas')->whereIn('article_id', $ids)->where('setear_precio_final', 0)->update(['final_price' => 1, 'percentage' => 1]);
        DB::table('article_price_type')->whereIn('article_id', $ids)->update(['final_price' => 1]);

        $r = $this->comparar_caminos($ids, $dueno->id, 2);

        $this->assertGreaterThanOrEqual(20, count($r['foto_hoy']['monedas']), 'Cada artículo tenía que tener sus entradas por moneda.');

        $pesos_recalculados = 0;
        foreach ($r['foto_hoy']['monedas'] as $entrada) {
            if ((int) $entrada['moneda_id'] === self::ARS && (float) $entrada['final_price'] > 1) {
                $pesos_recalculados++;
            }
        }
        $this->assertGreaterThan(5, $pesos_recalculados, 'La corrida tenía que recalcular los precios en pesos.');

        $espejados = 0;
        foreach ($r['foto_hoy']['pivots'] as $pivot) {
            if ((float) $pivot['final_price'] > 1) {
                $espejados++;
            }
        }
        $this->assertGreaterThan(5, $espejados, 'El precio en pesos tenía que quedar espejado en la pivot.');
    }

    /**
     * Extensión articulos_precios_en_blanco: descuentos, recargos y margen "en blanco", más un
     * impuesto sobre ventas que también se aplica ahí.
     *
     * @return void
     */
    public function test_articulos_con_precios_en_blanco()
    {
        $dueno = $this->crear_dueno([], ['articulos_precios_en_blanco']);

        $this->impuesto_sobre_ventas($dueno, 3.5);

        $articulos = $this->escenario_general($dueno, function ($dueno, $articulos) {

            DB::table('articles')->whereIn('id', [$articulos['completo'], $articulos['margen_propio']])->update(['percentage_gain_blanco' => 22.5]);

            ArticleDiscountBlanco::create(['article_id' => $articulos['completo'], 'percentage' => 8]);
            DB::table('article_surchage_blancos')->insert(['article_id' => $articulos['completo'], 'percentage' => 4]);
        });

        $r = $this->comparar_escenario_general($dueno, $articulos);

        $this->assertNotNull($r['foto_hoy']['articles'][$articulos['completo']]['final_price_blanco'], 'La corrida tenía que calcular el precio en blanco.');
    }

    /**
     * Impuestos sobre ventas: uno para todos los artículos y otro solo para algunos (pivot
     * article_sale_tax). Se aplican por división, así que dejan cola de decimales.
     *
     * @return void
     */
    public function test_impuestos_sobre_ventas_para_todos_y_por_articulo()
    {
        $dueno = $this->crear_dueno();

        $this->impuesto_sobre_ventas($dueno, 3.5);
        $por_articulo = $this->impuesto_sobre_ventas($dueno, 1.2, false);

        $articulos = $this->escenario_general($dueno, function ($dueno, $articulos) use ($por_articulo) {
            foreach (['completo', 'dolar_global', 'estable'] as $nombre) {
                DB::table('article_sale_tax')->insert(['article_id' => $articulos[$nombre], 'sale_tax_id' => $por_articulo->id]);
            }
        });

        $this->comparar_escenario_general($dueno, $articulos);
    }

    /**
     * Los cinco modos de redondeo del precio de venta, cada uno en su cuenta.
     *
     * @return array
     */
    public function modos_de_redondeo()
    {
        return [
            'miles'    => ['redondear_miles_en_vender'],
            'centenas' => ['redondear_centenas_en_vender'],
            'decenas'  => ['redondear_precios_en_decenas'],
            'de_a_50'  => ['redondear_de_a_50'],
            'centavos' => ['redondear_precios_en_centavos'],
        ];
    }

    /**
     * @dataProvider modos_de_redondeo
     *
     * @param  string $flag
     * @return void
     */
    public function test_modos_de_redondeo($flag)
    {
        $dueno = $this->crear_dueno([$flag => 1, 'listas_de_precio' => 1]);

        $this->crear_lista($dueno, 'Redondeada', 33, 1, [['percentage' => 1]]);

        $articulos = $this->escenario_general($dueno);

        $this->comparar_escenario_general($dueno, $articulos);
    }

    /**
     * aplicar_descuentos_en_articulos_antes_del_margen_de_ganancia en 0 y en 1: en 0, los
     * descuentos y los recargos se aplican (también) después del margen.
     *
     * @return array
     */
    public function descuentos_antes_o_despues_del_margen()
    {
        return [
            'antes del margen (1)'   => [1],
            'despues del margen (0)' => [0],
        ];
    }

    /**
     * @dataProvider descuentos_antes_o_despues_del_margen
     *
     * @param  int $valor
     * @return void
     */
    public function test_descuentos_antes_o_despues_del_margen($valor)
    {
        $dueno = $this->crear_dueno(['aplicar_descuentos_en_articulos_antes_del_margen_de_ganancia' => $valor]);

        $articulos = $this->escenario_general($dueno);

        $this->comparar_escenario_general($dueno, $articulos);
    }

    /**
     * Proveedor con price_from_cost_mas_iva (precio = costo de lista + IVA), sin listas y con
     * listas de precio.
     *
     * @return array
     */
    public function cuentas_para_price_from_cost_mas_iva()
    {
        return [
            'sin listas' => [0],
            'con listas' => [1],
        ];
    }

    /**
     * @dataProvider cuentas_para_price_from_cost_mas_iva
     *
     * @param  int $listas_de_precio
     * @return void
     */
    public function test_proveedor_con_precio_desde_costo_mas_iva($listas_de_precio)
    {
        $dueno = $this->crear_dueno(['listas_de_precio' => $listas_de_precio]);

        if ($listas_de_precio) {
            $this->crear_lista($dueno, 'Lista', 30, 1, [['amount' => 5]]);
        }

        $articulos = $this->escenario_general($dueno, function ($dueno, $articulos) {

            $proveedor = $this->crear_proveedor($dueno, ['percentage_gain' => 50, 'price_from_cost_mas_iva' => 1, 'dolar' => 1111.11]);

            DB::table('articles')->whereIn('id', [
                $articulos['completo'],
                $articulos['dolar_del_proveedor'],
                $articulos['unidades_individuales'],
                $articulos['estable'],
            ])->update(['provider_id' => $proveedor->id]);
        });

        $this->comparar_escenario_general($dueno, $articulos);
    }

    /**
     * Crea una entrada de article_price_type_monedas.
     *
     * @param  \App\Models\Article   $article
     * @param  \App\Models\PriceType $lista
     * @param  int                   $moneda_id
     * @param  array                 $atributos
     * @return void
     */
    protected function entrada_de_moneda($article, $lista, $moneda_id, array $atributos)
    {
        ArticlePriceTypeMoneda::create(array_merge([
            'article_id'    => $article->id,
            'price_type_id' => $lista->id,
            'moneda_id'     => $moneda_id,
        ], $atributos));
    }
}
