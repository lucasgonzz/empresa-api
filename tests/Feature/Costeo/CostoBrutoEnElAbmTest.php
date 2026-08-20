<?php

namespace Tests\Feature\Costeo;

use App\Models\Article;
use App\Models\Iva;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\EmpresaTestCase;

/**
 * Misión `costo-bruto-por-condicion-fiscal` (20/8/2026) — el ABM del listado tiene DOS inputs de
 * costo, uno para el neto (sin IVA) y otro para el bruto (con IVA), y siempre guarda el neto.
 *
 * QUÉ SE ARREGLA. `articles.cost` es NETO por convención del sistema, pero de las cuatro vías que
 * lo escriben a partir de un número tipeado por una persona, sólo la compra a proveedor le sacaba
 * el IVA. El ABM hacía `$model->cost = $request->cost` literal, así que un Monotributista que
 * cargaba 1000 desde el listado terminaba costeando 1210: `aplicar_descuentos_e_iva()` le suma el
 * IVA encima de un número que ya lo tenía adentro. Por la compra cargaba 1210 y costeaba 1210.
 * Misma plata, dos resultados según por dónde entrara.
 *
 * 🔴 LA REGLA, que es lo que fijan estos tests: **el que carga el costo declara si es neto o
 * bruto**, y nadie más. En el ABM lo declara el input en el que tipeó (`cost_incluye_iva`); en la
 * compra, `provider_orders.precios_incluyen_iva`; en el import, el control de la planilla.
 *
 * Ni `articles.aplicar_iva` ni la condición fiscal de la cuenta participan de esa decisión.
 * `aplicar_iva` es una decisión sobre la VENTA y es ortogonal. Una versión anterior de esta misión
 * los miraba a los dos, y de ahí salieron dos clases de bug que los checkers de la Fase 5 midieron:
 * el mismo importe costeando distinto según por dónde entrara, y un toggle que no hacía nada.
 *
 * Lo único que sigue mandando es `hasIva()`: un artículo Exento, No Gravado o al 0% no tiene IVA
 * adentro por más que alguien lo declare.
 *
 * Los números de estos tests son la especificación, no una sugerencia. 🔴 Está prohibido ajustar un
 * valor esperado para que coincida con lo que devuelve el sistema: si un test queda en rojo, se
 * corrige el código.
 *
 * Se extiende `EmpresaTestCase` (DatabaseTransactions + `actingAs` del owner del fixture), así que
 * la base sembrada del slot no se ensucia. Aun así, los tests que mueven la condición fiscal la
 * restauran en un `finally` explícito: es la regla de aislamiento del CLAUDE.md, y el fallo 78 de
 * `prompts/fallos.md` salió justamente de tests que la mutaban sin restaurarla.
 *
 * @group costeo-precios
 */
class CostoBrutoEnElAbmTest extends EmpresaTestCase
{
    /**
     * Delta de tolerancia para comparaciones de plata (nunca comparación exacta sobre floats).
     */
    const DELTA = 0.01;

    /**
     * Owner del fixture, que es de quien se lee la condición fiscal.
     *
     * @return \App\Models\User
     */
    private function owner()
    {
        return User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
    }

    /**
     * Artículo del fixture sobre el que se prueba, con la alícuota que se le quiera poner.
     *
     * @param  string $percentage  Valor de `ivas.percentage` ('21', 'Exento'...).
     * @param  int    $aplicar_iva Estado del flag de VENTA del artículo. Los tests lo mueven para
     *                             demostrar que ya no participa del costeo.
     * @return \App\Models\Article
     */
    private function articulo_con_alicuota($percentage, $aplicar_iva = 1)
    {
        $iva = Iva::where('percentage', $percentage)->first();

        $this->assertNotNull($iva, 'La base de testing no tiene la alícuota '.$percentage.' sembrada.');

        $article = Article::where('user_id', $this->owner()->id)
                            ->where('name', TestingFerreteriaSeeder::ARTICULO_CENTINELA)
                            ->first();

        $this->assertNotNull($article, 'La base de testing no tiene el artículo centinela sembrado.');

        $article->iva_id = $iva->id;
        $article->aplicar_iva = $aplicar_iva;
        $article->save();

        return $article;
    }

    /**
     * Arma el body de `PUT api/article/{id}`. El controller resuelve el modelo por `$request->id`,
     * no por el parámetro de ruta, así que el id va en el body sí o sí.
     *
     * @param  \App\Models\Article $article
     * @param  array               $overrides
     * @return array
     */
    private function payload($article, $overrides = [])
    {
        /*
         * Se parte de las columnas REALES del artículo y no de un array armado a mano: update()
         * asigna decenas de campos desde el request, y los que no viajan quedan en null. Varios son
         * NOT NULL (`online`, `in_offer`...), así que un payload corto no falla por la lógica que se
         * quiere probar sino con un 500 de integridad de la base.
         */
        return array_merge($article->getAttributes(), [
            'id'          => $article->id,
            'aplicar_iva' => $article->aplicar_iva,
            /*
             * 🔴 El formulario manda SIEMPRE `cost_incluye_iva`, aunque sea en false: declara en
             * cuál de los dos inputs se tipeó. Si la clave no viajara, un guardado que ni toca el
             * costo terminaría descomponiendo un número que ya era neto (1000 → 826,45 → 683,01, un
             * 21% por guardado). Lo midió un checker de la Fase 5, así que el default acá replica el
             * contrato real de la SPA y no una comodidad del test.
             */
            'cost_incluye_iva' => 0,
            /*
             * Los arrays de relaciones van explícitos aunque el test no los use: update() se los
             * pasa derecho a helpers que hacen foreach sin validar (attach_price_types,
             * attach_price_type_monedas, GeneralHelper::attachModels). Sin la clave llegan null y
             * el request muere con un ErrorException antes de llegar al costeo, que es lo que se
             * quiere probar.
             */
            'price_types'        => [],
            'price_type_monedas' => [],
            'tags'               => [],
        ], $overrides);
    }

    /**
     * Deja la cuenta en una condición fiscal y devuelve lo que había, para restaurarlo.
     *
     * @param  string $condicion 'MT' o 'RRII'.
     * @return string|null
     */
    private function set_condicion($condicion)
    {
        $user = $this->owner();

        $previa = $user->condicion_iva_precios;

        $user->condicion_iva_precios = $condicion;
        $user->save();

        return $previa;
    }

    /**
     * Restaura lo que devolvió set_condicion(). Va siempre en un `finally`.
     *
     * @param  string|null $previa
     * @return void
     */
    private function restaurar_condicion($previa)
    {
        $user = $this->owner();
        $user->condicion_iva_precios = $previa;
        $user->save();
    }

    /**
     * Test 1 — el caso que motivó la misión. Se carga 1210 en el input de BRUTO (lo que dice la
     * factura del proveedor) y el sistema guarda 1000 de costo neto.
     *
     * Antes de esta misión `cost` quedaba en 1210 y el costo real trepaba a 1464,10.
     *
     * @group costeo-precios
     * @test
     */
    public function cargar_en_el_input_de_bruto_guarda_el_neto()
    {
        $article = $this->articulo_con_alicuota('21');

        $this->putJson('api/article/'.$article->id, $this->payload($article, [
            'cost'             => 1210,
            'cost_incluye_iva' => 1,
        ]))->assertStatus(200);

        $article->refresh();

        $this->assertEqualsWithDelta(1000, (float) $article->cost, self::DELTA,
            '1210 con IVA al 21% son 1000 de costo neto, que es lo único que se guarda');
    }

    /**
     * Test 2 — la otra mitad: lo tipeado en el input de NETO entra tal cual, sin tocar.
     *
     * @group costeo-precios
     * @test
     */
    public function cargar_en_el_input_de_neto_guarda_el_valor_tal_cual()
    {
        $article = $this->articulo_con_alicuota('21');

        $this->putJson('api/article/'.$article->id, $this->payload($article, [
            'cost'             => 1000,
            'cost_incluye_iva' => 0,
        ]))->assertStatus(200);

        $article->refresh();

        $this->assertEqualsWithDelta(1000, (float) $article->cost, self::DELTA,
            'el input de neto no descompone nada');
    }

    /**
     * Test 3 — el costo real de un Monotributista vuelve a ser lo que efectivamente pagó.
     *
     * Es la comprobación de punta a punta: no alcanza con que `cost` quede en 1000, tiene que ser
     * que el pipeline de precios vuelva a llegar a 1210. Antes de la misión daba 1464,10, porque
     * `aplicar_descuentos_e_iva()` le sumaba el IVA a un número que ya lo tenía adentro.
     *
     * @group costeo-precios
     * @test
     */
    public function el_costo_real_del_monotributista_es_lo_que_pago()
    {
        $previa = $this->set_condicion('MT');

        try {

            $article = $this->articulo_con_alicuota('21');

            $this->putJson('api/article/'.$article->id, $this->payload($article, [
                'cost'             => 1210,
                'cost_incluye_iva' => 1,
            ]))->assertStatus(200);

            $article->refresh();

            $this->assertEqualsWithDelta(1210, (float) $article->costo_real, self::DELTA,
                'el costo real de un MT vuelve a ser lo que efectivamente pagó (1210), no 1464,10');

        } finally {
            $this->restaurar_condicion($previa);
        }
    }

    /**
     * Test 4 — 🔴 `articles.aplicar_iva` NO participa de la decisión.
     *
     * Es una decisión sobre la VENTA —si al precio se le suma IVA encima—, ortogonal a si el número
     * que alguien acaba de tipear trae el IVA adentro. Mientras el back-out lo miró, un Responsable
     * Inscripto que declaraba "esto viene con IVA" sobre un artículo con `aplicar_iva = 0` veía que
     * no pasaba nada: el número se guardaba con el IVA adentro, en una columna que es neta por
     * convención, y en silencio.
     *
     * @group costeo-precios
     * @test
     */
    public function aplicar_iva_apagado_no_bloquea_el_back_out()
    {
        $previa = $this->set_condicion('RRII');

        try {

            $article = $this->articulo_con_alicuota('21', 0);

            $this->putJson('api/article/'.$article->id, $this->payload($article, [
                'cost'             => 1210,
                'cost_incluye_iva' => 1,
            ]))->assertStatus(200);

            $article->refresh();

            $this->assertEqualsWithDelta(1000, (float) $article->cost, self::DELTA,
                'aplicar_iva es una decisión sobre la venta y no puede bloquear el back-out');

        } finally {
            $this->restaurar_condicion($previa);
        }
    }

    /**
     * Test 5 — el límite de lo que la declaración puede pisar: `hasIva()`.
     *
     * `Exento` y `No Gravado` se guardan como TEXTO en `ivas.percentage`, y cualquier cuenta
     * aritmética sobre ellos tiene que tratarlos como 0. Un artículo exento no tiene IVA adentro por
     * más que alguien lo declare: no hay nada que sacar.
     *
     * 🔴 `aplicar_iva` va en **1** a propósito. Con 0, este test pasaría igual aunque alguien borrara
     * el chequeo de `hasIva()`, porque no habría forma de distinguir cuál de los dos guards frenó.
     * Con 1, `hasIva()` queda como ÚNICA puerta y el test mide lo que dice medir.
     *
     * @group costeo-precios
     * @test
     */
    public function un_articulo_exento_no_se_descompone_aunque_se_declare_bruto()
    {
        $article = $this->articulo_con_alicuota('Exento', 1);

        $this->putJson('api/article/'.$article->id, $this->payload($article, [
            'cost'             => 1000,
            'cost_incluye_iva' => 1,
        ]))->assertStatus(200);

        $article->refresh();

        $this->assertEqualsWithDelta(1000, (float) $article->cost, self::DELTA,
            'un artículo Exento no tiene IVA que sacar, lo declare quien lo declare');
    }

    /**
     * Test 6 — 🔴 guardar sin tocar el costo no lo mueve. El test más importante del archivo.
     *
     * El formulario del listado manda el modelo entero en cada guardado, así que corregirle el
     * nombre a un artículo llega con el mismo `cost` de siempre (que ya es NETO, porque es el que
     * devolvió el servidor) y `cost_incluye_iva` en false.
     *
     * Una versión anterior de esta misión no mandaba la clave, y el backend caía a un default por
     * condición fiscal que para Monotributista era "bruto": el back-out corría sobre un número que
     * ya era neto y el costo se hundía **1000 → 826,45 → 683,01**, un 21% por guardado, sin
     * necesidad de ninguna secuencia rara. Es la acción más común del listado. Lo midió el checker
     * de la Fase 5, con los siete tests de entonces en verde.
     *
     * @group costeo-precios
     * @test
     */
    public function guardar_sin_tocar_el_costo_no_lo_descompone()
    {
        $previa = $this->set_condicion('MT');

        try {

            $article = $this->articulo_con_alicuota('21');

            $article->cost = 1000;
            $article->save();

            // El payload de "le corregí el nombre y guardé": mismo cost, declarado como neto.
            $this->putJson('api/article/'.$article->id, $this->payload($article, [
                'name'             => 'Nombre corregido a mano',
                'cost'             => $article->cost,
                'cost_incluye_iva' => 0,
            ]))->assertStatus(200);

            $article->refresh();

            $this->assertEqualsWithDelta(1000, (float) $article->cost, self::DELTA,
                'un guardado que no toca el costo no puede moverlo');

            // Y dos veces seguidas tampoco: el bug se amplificaba en cada guardado.
            $this->putJson('api/article/'.$article->id, $this->payload($article, [
                'cost'             => $article->cost,
                'cost_incluye_iva' => 0,
            ]))->assertStatus(200);

            $article->refresh();

            $this->assertEqualsWithDelta(1000, (float) $article->cost, self::DELTA,
                'ni al segundo guardado: 1000 → 826,45 → 683,01 era el bug');

        } finally {
            $this->restaurar_condicion($previa);
        }
    }

    /**
     * Test 7 — la condición fiscal de la cuenta tampoco decide en el ABM.
     *
     * Un Monotributista que carga en el input de NETO guarda el neto. Antes de esta versión, "MT
     * siempre carga bruto" era incondicional y le habría sacado el IVA a un número que la persona
     * declaró neto. La regla es una sola: manda el input, no la cuenta.
     *
     * @group costeo-precios
     * @test
     */
    public function la_condicion_fiscal_no_decide_en_el_abm()
    {
        $previa = $this->set_condicion('MT');

        try {

            $article = $this->articulo_con_alicuota('21');

            $this->putJson('api/article/'.$article->id, $this->payload($article, [
                'cost'             => 1000,
                'cost_incluye_iva' => 0,
            ]))->assertStatus(200);

            $article->refresh();

            $this->assertEqualsWithDelta(1000, (float) $article->cost, self::DELTA,
                'si el MT declara que tipeó el neto, se le cree: manda el input, no la condición fiscal');

        } finally {
            $this->restaurar_condicion($previa);
        }
    }

    /**
     * Test 8 — cambiar la alícuota y el costo en el mismo submit usa la alícuota NUEVA.
     *
     * `set_costo_desde_request()` corre después de asignar `iva_id`, y por eso `back_out_iva()`
     * fuerza `load('iva')` en vez de `loadMissing()`: sin eso, el artículo trae la relación cacheada
     * de antes del cambio y el back-out calcula con la alícuota vieja. La SPA replica el mismo
     * criterio, leyendo `iva_id` del formulario y no la relación `article.iva`.
     *
     * @group costeo-precios
     * @test
     */
    public function cambiar_la_alicuota_y_el_costo_juntos_usa_la_alicuota_nueva()
    {
        // Arranca en 21% y pasa a 10.5% en el mismo request que cambia el costo.
        $article = $this->articulo_con_alicuota('21');

        $iva_nuevo = Iva::where('percentage', '10.5')->first();

        $this->assertNotNull($iva_nuevo, 'La base de testing no tiene la alícuota 10.5 sembrada.');

        $this->putJson('api/article/'.$article->id, $this->payload($article, [
            'cost'             => 1105,
            'cost_incluye_iva' => 1,
            'iva_id'           => $iva_nuevo->id,
        ]))->assertStatus(200);

        $article->refresh();

        $this->assertEqualsWithDelta(1000, (float) $article->cost, self::DELTA,
            '1105 con la alícuota NUEVA (10,5%) da 1000; con la vieja (21%) daría 913,22');
    }
}
