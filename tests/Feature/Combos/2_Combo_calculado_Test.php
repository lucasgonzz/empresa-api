<?php

namespace Tests\Feature\Combos;

use App\Http\Controllers\Helpers\combo\ComboCalculadoHelper;
use App\Models\Provider;

/**
 * La cuenta del combo calculado (misión combos-calculados, 30/9/2026): costo, precio, precios por
 * lista y qué lista es "la de por defecto".
 *
 * Lo que protegen, en orden de importancia:
 *
 *  - que el costo del combo sea Σ (costo unitario del componente x cantidad) con LA MISMA regla que
 *    la venta (`costo_real`, o `cost` si es NULL; en dólares cotizado; y dividido por las unidades
 *    individuales). Es la base de la ganancia de la venta: si sale mal, todo lo demás sale mal;
 *  - que `combos.price` sea el precio de la lista por defecto (mayor `position`, desempate por id),
 *    porque es lo que lee una tienda vieja que no conoce `combo_price_type`;
 *  - que un combo MANUAL no se toque nunca (el check nace apagado en todos los combos existentes);
 *  - que recalcular sea idempotente (no reescribe filas que no cambiaron).
 *
 * Los números de los artículos se escriben a mano (ver ComboCalculadoTestCase): la cuenta que se
 * mide es la del combo.
 *
 * @group combos-calculados
 */
class Combo_calculado_Test extends ComboCalculadoTestCase
{
    /**
     * Sin listas: costo y precio salen de sumar los componentes por su cantidad, y no se escribe
     * ninguna fila en `combo_price_type`.
     *
     * @test
     */
    public function sin_listas_el_costo_y_el_precio_son_la_suma_de_los_componentes()
    {
        $this->con_listas(0);

        $a = $this->nuevo_articulo(['costo_real' => 100, 'final_price' => 250]);
        $b = $this->nuevo_articulo(['costo_real' => 40, 'final_price' => 90]);

        $combo = $this->combo_calculado([[$a, 2], [$b, 1]]);

        $calculo = ComboCalculadoHelper::guardar($combo);

        $this->assertNotNull($calculo, 'un combo calculado tiene que devolver la cuenta que hizo');

        // 2 x 100 + 1 x 40 = 240 de costo; 2 x 250 + 1 x 90 = 590 de precio.
        $this->assertSame(240.0, $this->costo_en_base($combo));
        $this->assertSame(590.0, $this->precio_en_base($combo));
        $this->assertSame([], $this->precios_por_lista_en_base($combo), 'sin listas no hay filas por lista');
    }

    /**
     * El costo del artículo es por BULTO y el precio por unidad individual: el combo lleva unidades
     * individuales, así que se divide. Sin esto una "caja x 12" costaría 12 veces de más.
     *
     * @test
     */
    public function el_costo_se_divide_por_las_unidades_individuales_del_articulo()
    {
        $this->con_listas(0);

        // Una caja de 12 que cuesta 120: cada unidad cuesta 10 y se vende a 15.
        $caja = $this->nuevo_articulo(['costo_real' => 120, 'unidades_individuales' => 12, 'final_price' => 15]);

        $combo = $this->combo_calculado([[$caja, 3]]);

        ComboCalculadoHelper::guardar($combo);

        $this->assertSame(30.0, $this->costo_en_base($combo), '3 unidades a 10 cada una');
        $this->assertSame(45.0, $this->precio_en_base($combo), '3 unidades a 15 cada una');
    }

    /**
     * `costo_real` manda; `cost` solo cuando `costo_real` es NULL. Es la lectura que hace la venta
     * (`SaleHelper::getCost`), y el combo tiene que costar lo mismo que sus artículos sueltos.
     *
     * @test
     */
    public function el_costo_cae_a_cost_cuando_costo_real_es_null()
    {
        $this->con_listas(0);

        $con_real  = $this->nuevo_articulo(['cost' => 999, 'costo_real' => 100, 'final_price' => 200]);
        $solo_cost = $this->nuevo_articulo(['cost' => 60, 'costo_real' => null, 'final_price' => 120]);

        $combo = $this->combo_calculado([[$con_real, 1], [$solo_cost, 1]]);

        ComboCalculadoHelper::guardar($combo);

        $this->assertSame(160.0, $this->costo_en_base($combo), '100 del costo_real + 60 del cost, no 999');
    }

    /**
     * Un componente en dólares se cotiza a pesos con el dólar global del dueño...
     *
     * @test
     */
    public function un_componente_en_dolares_se_cotiza_con_el_dolar_del_dueno()
    {
        $this->con_listas(0);

        $this->dueno->dollar                     = 1000;
        $this->dueno->cotizar_precios_en_dolares = 1;
        $this->dueno->save();

        // 2 dólares de costo, 3.000 pesos de precio.
        $usd = $this->nuevo_articulo(['costo_real' => 2, 'cost_in_dollars' => 1, 'final_price' => 3000]);

        $combo = $this->combo_calculado([[$usd, 2]]);

        ComboCalculadoHelper::guardar($combo);

        $this->assertSame(4000.0, $this->costo_en_base($combo), '2 x (2 USD x 1000)');
        $this->assertSame(6000.0, $this->precio_en_base($combo));
    }

    /**
     * ...y con el dólar del proveedor cuando lo tiene (que gana sobre el global), igual que el
     * precio de venta del artículo. Costo y precio del combo hablan del mismo dólar.
     *
     * @test
     */
    public function un_componente_en_dolares_prefiere_el_dolar_de_su_proveedor()
    {
        $this->con_listas(0);

        $this->dueno->dollar                     = 1000;
        $this->dueno->cotizar_precios_en_dolares = 1;
        $this->dueno->save();

        $proveedor = Provider::create(['name' => 'zz Proveedor en dolares', 'user_id' => self::DUENO, 'dolar' => 1200]);

        $usd = $this->nuevo_articulo(['costo_real' => 2, 'cost_in_dollars' => 1, 'final_price' => 3000, 'provider_id' => $proveedor->id]);

        $combo = $this->combo_calculado([[$usd, 1]]);

        ComboCalculadoHelper::guardar($combo);

        $this->assertSame(2400.0, $this->costo_en_base($combo), '2 USD x 1200 del proveedor, no x 1000 global');
    }

    /**
     * Un artículo con costo en dólares en una cuenta que NO cotiza en dólares no se convierte:
     * `ArticleHelper::cotizar()` lo deja como está, igual que hizo con su precio de venta.
     *
     * @test
     */
    public function sin_cotizar_en_dolares_el_costo_no_se_convierte()
    {
        $this->con_listas(0);

        $this->dueno->dollar                     = 1000;
        $this->dueno->cotizar_precios_en_dolares = 0;
        $this->dueno->save();

        $usd = $this->nuevo_articulo(['costo_real' => 2, 'cost_in_dollars' => 1, 'final_price' => 3000]);

        $combo = $this->combo_calculado([[$usd, 1]]);

        ComboCalculadoHelper::guardar($combo);

        $this->assertSame(2.0, $this->costo_en_base($combo));
    }

    /**
     * Con listas: una fila por cada lista del dueño, con la suma de los precios de ESA lista, y
     * `combos.price` = la lista por defecto (la de mayor `position`).
     *
     * 🔴 `combos.price` es lo que lee una tienda que todavía no conoce `combo_price_type`.
     *
     * @test
     */
    public function con_listas_hay_un_precio_por_lista_y_combos_price_es_el_de_la_lista_por_defecto()
    {
        $this->con_listas(1);

        $baja = $this->lista('Mostrador', 90);
        $alta = $this->lista('Mayorista', 91);

        /*
         * Una lista de position 0, la MÁS BAJA de la cuenta, con un precio que no se parece a
         * ningún otro: sin ella, la primera lista que recorre el cálculo sería una del fixture
         * (sin pivote, que cae al precio de la lista por defecto del artículo) y un combo que
         * eligiera "la primera lista" en vez de "la de mayor position" daría el mismo número por
         * casualidad y el test no se enteraría.
         */
        $piso = $this->lista('Piso', 0);

        $a = $this->nuevo_articulo(['costo_real' => 100, 'final_price' => 500]);
        $b = $this->nuevo_articulo(['costo_real' => 50, 'final_price' => 500]);

        $this->precio_en_lista($a, $piso, 999);
        $this->precio_en_lista($b, $piso, 999);
        $this->precio_en_lista($a, $baja, 300);
        $this->precio_en_lista($a, $alta, 260);
        $this->precio_en_lista($b, $baja, 200);
        $this->precio_en_lista($b, $alta, 170);

        $combo = $this->combo_calculado([[$a, 2], [$b, 1]]);

        ComboCalculadoHelper::guardar($combo);

        $precios = $this->precios_por_lista_en_base($combo);

        $this->assertSame(2997.0, $precios[$piso->id], '2 x 999 + 999');
        $this->assertSame(800.0, $precios[$baja->id], '2 x 300 + 200');
        $this->assertSame(690.0, $precios[$alta->id], '2 x 260 + 170');

        $this->assertSame(690.0, $this->precio_en_base($combo), 'combos.price es la lista de mayor position');
        $this->assertSame(250.0, $this->costo_en_base($combo), 'el costo no depende de la lista: 2 x 100 + 50');

        // Una fila por cada lista del dueño (el fixture ya trae otras cuatro), ni una de más.
        $this->assertCount(
            \App\Models\PriceType::where('user_id', self::DUENO)->count(),
            $precios,
            'una fila por lista del dueño'
        );
    }

    /**
     * A igual `position`, gana la lista de id más alto: el mismo desempate que
     * `ArticlePricesHelper::resolver_precio_de_venta()`. Sin él, el precio del combo dependería del
     * orden en que MySQL devuelve las filas.
     *
     * @test
     */
    public function a_igual_position_la_lista_por_defecto_es_la_de_id_mas_alto()
    {
        $this->con_listas(1);

        $primera  = $this->lista('Empate uno', 95);
        $segunda  = $this->lista('Empate dos', 95);

        $this->assertGreaterThan($primera->id, $segunda->id);

        $a = $this->nuevo_articulo();

        $this->precio_en_lista($a, $primera, 111);
        $this->precio_en_lista($a, $segunda, 222);

        $combo = $this->combo_calculado([[$a, 1]]);

        ComboCalculadoHelper::guardar($combo);

        $this->assertSame(222.0, $this->precio_en_base($combo), 'gana la segunda: mismo position, id más alto');
    }

    /**
     * Un componente al que le falta el pivote de una lista cae a SU lista por defecto (lo decide
     * `resolver_precio_de_venta()`, no el combo). Queda fijado para que nadie lo "arregle" acá.
     *
     * @test
     */
    public function un_componente_sin_pivote_en_una_lista_cae_a_su_lista_por_defecto()
    {
        $this->con_listas(1);

        $baja = $this->lista('Sin pivote baja', 92);
        $alta = $this->lista('Con pivote alta', 93);

        $a = $this->nuevo_articulo();

        // Solo tiene precio en la lista alta.
        $this->precio_en_lista($a, $alta, 400);

        $combo = $this->combo_calculado([[$a, 1]]);

        ComboCalculadoHelper::guardar($combo);

        $precios = $this->precios_por_lista_en_base($combo);

        $this->assertSame(400.0, $precios[$alta->id]);
        $this->assertSame(400.0, $precios[$baja->id], 'sin pivote en esa lista, usa la lista por defecto del artículo');
    }

    /**
     * Un componente sin precio (NULL) suma 0: el combo no inventa un precio ni revienta.
     *
     * @test
     */
    public function un_componente_sin_precio_suma_cero()
    {
        $this->con_listas(0);

        $con_precio = $this->nuevo_articulo(['final_price' => 100]);
        $sin_precio = $this->nuevo_articulo(['final_price' => null]);

        $combo = $this->combo_calculado([[$con_precio, 1], [$sin_precio, 5]]);

        ComboCalculadoHelper::guardar($combo);

        $this->assertSame(100.0, $this->precio_en_base($combo));
    }

    /**
     * Un componente BORRADO sigue contando con sus últimos valores: sacarlo abarataría el combo en
     * silencio. Lo que corresponde es que no se pueda vender, y eso lo dice el stock (ver el test
     * de stock), no el precio.
     *
     * @test
     */
    public function un_componente_borrado_sigue_contando_en_el_costo_y_el_precio()
    {
        $this->con_listas(0);

        $a = $this->nuevo_articulo(['costo_real' => 10, 'final_price' => 30]);
        $b = $this->nuevo_articulo(['costo_real' => 20, 'final_price' => 50]);

        $combo = $this->combo_calculado([[$a, 1], [$b, 1]]);

        $b->delete();

        ComboCalculadoHelper::guardar($combo);

        $this->assertSame(30.0, $this->costo_en_base($combo));
        $this->assertSame(80.0, $this->precio_en_base($combo));
    }

    /**
     * 🔴 Un combo MANUAL no se toca nunca: es el caso de todos los combos existentes al desplegar
     * (el check nace apagado). Ni el costo ni el precio que cargó la persona, ni filas por lista.
     *
     * @test
     */
    public function un_combo_manual_no_se_recalcula()
    {
        $this->con_listas(0);

        $a = $this->nuevo_articulo(['costo_real' => 100, 'final_price' => 250]);

        $combo = $this->combo([[$a, 2]], ['cost' => 7, 'price' => 999, 'calcular_desde_articulos' => 0]);

        $this->assertNull(ComboCalculadoHelper::guardar($combo), 'un combo manual no hace ninguna cuenta');

        ComboCalculadoHelper::recalcular_por_articulos([$a->id]);
        ComboCalculadoHelper::recalcular_de_un_dueno(self::DUENO);

        $this->assertSame(7.0, $this->costo_en_base($combo));
        $this->assertSame(999.0, $this->precio_en_base($combo));
        $this->assertSame([], $this->precios_por_lista_en_base($combo));
    }

    /**
     * Idempotente: recalcular dos veces lo mismo no reescribe nada la segunda vez. Se mide con los
     * ids de `combo_price_type` (un borrar+insertar los cambiaría) y con la fecha de modificación
     * del combo.
     *
     * @test
     */
    public function recalcular_dos_veces_no_reescribe_nada()
    {
        $this->con_listas(1);

        $lista = $this->lista('Idempotente', 94);

        $a = $this->nuevo_articulo(['costo_real' => 100]);
        $this->precio_en_lista($a, $lista, 300);

        $combo = $this->combo_calculado([[$a, 1]]);

        ComboCalculadoHelper::guardar($combo);

        $ids_antes      = \Illuminate\Support\Facades\DB::table('combo_price_type')->where('combo_id', $combo->id)->orderBy('id')->pluck('id')->all();
        $modificado_antes = $this->fila($combo)->updated_at;

        $this->assertNotEmpty($ids_antes);

        // Un segundo de diferencia: si se reescribiera, updated_at cambiaría.
        sleep(1);

        ComboCalculadoHelper::guardar($combo);

        $ids_despues = \Illuminate\Support\Facades\DB::table('combo_price_type')->where('combo_id', $combo->id)->orderBy('id')->pluck('id')->all();

        $this->assertSame($ids_antes, $ids_despues, 'las filas por lista no se borran y se vuelven a insertar');
        $this->assertSame($modificado_antes, $this->fila($combo)->updated_at, 'el combo no se reescribe si no cambió');
    }

    /**
     * Una lista que el dueño saca de circulación desaparece del combo en el próximo recálculo (no
     * queda un precio fantasma en una lista que ya no existe).
     *
     * @test
     */
    public function una_lista_borrada_desaparece_de_los_precios_del_combo()
    {
        $this->con_listas(1);

        $lista = $this->lista('Efimera', 96);

        $a = $this->nuevo_articulo();
        $this->precio_en_lista($a, $lista, 300);

        $combo = $this->combo_calculado([[$a, 1]]);

        ComboCalculadoHelper::guardar($combo);

        $this->assertArrayHasKey($lista->id, $this->precios_por_lista_en_base($combo));

        $lista->delete();

        ComboCalculadoHelper::guardar($combo);

        $this->assertArrayNotHasKey($lista->id, $this->precios_por_lista_en_base($combo));
    }
}
