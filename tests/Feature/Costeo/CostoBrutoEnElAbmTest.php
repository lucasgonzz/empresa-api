<?php

namespace Tests\Feature\Costeo;

use App\Models\Article;
use App\Models\Iva;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\EmpresaTestCase;

/**
 * Misión `costo-bruto-por-condicion-fiscal` (20/8/2026) — el ABM del listado interpreta el costo
 * tipeado según la condición fiscal de la cuenta.
 *
 * QUÉ SE ARREGLA. `articles.cost` es NETO por convención del sistema, pero de las cuatro vías que
 * lo escriben a partir de un número tipeado por una persona, sólo la compra a proveedor le sacaba
 * el IVA (`NewProviderOrderHelper:1039`). El ABM hacía `$model->cost = $request->cost` literal, así
 * que un Monotributista que cargaba 1000 desde el listado terminaba costeando 1210:
 * `aplicar_descuentos_e_iva()` le suma el IVA encima de un número que ya lo tenía adentro. Por la
 * compra cargaba 1210 y costeaba 1210. Misma plata, dos resultados según por dónde entrara.
 *
 * Un MT recibe **Factura B**, donde el IVA no viene discriminado: el neto no figura en ningún lado
 * de su comprobante. Pedirle que cargue el neto es pedirle un dato que no tiene.
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
     * @param  string $percentage Valor de `ivas.percentage` ('21', 'Exento'...).
     * @return \App\Models\Article
     */
    private function articulo_con_alicuota($percentage)
    {
        $iva = Iva::where('percentage', $percentage)->first();

        $this->assertNotNull($iva, 'La base de testing no tiene la alícuota '.$percentage.' sembrada.');

        $article = Article::where('user_id', $this->owner()->id)
                            ->where('name', TestingFerreteriaSeeder::ARTICULO_CENTINELA)
                            ->first();

        $this->assertNotNull($article, 'La base de testing no tiene el artículo centinela sembrado.');

        $article->iva_id = $iva->id;
        $article->aplicar_iva = 1;
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
            'aplicar_iva' => 1,
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
     * Deja la cuenta en una condición fiscal y un default de carga, y devuelve una función para
     * restaurar los dos valores.
     *
     * @param  string $condicion 'MT' o 'RRII'.
     * @param  int    $con_iva   Valor de `costos_cargados_con_iva`.
     * @return array             [$user, $condicion_previa, $con_iva_previo]
     */
    private function set_condicion($condicion, $con_iva = 0)
    {
        $user = $this->owner();

        $previos = [$user->condicion_iva_precios, (int) $user->costos_cargados_con_iva];

        $user->condicion_iva_precios = $condicion;
        $user->costos_cargados_con_iva = $con_iva;
        $user->save();

        return $previos;
    }

    /**
     * Restaura lo que devolvió set_condicion(). Va siempre en un `finally`.
     *
     * @param  array $previos
     * @return void
     */
    private function restaurar_condicion($previos)
    {
        $user = $this->owner();
        $user->condicion_iva_precios = $previos[0];
        $user->costos_cargados_con_iva = $previos[1];
        $user->save();
    }

    /**
     * Test 1 — el caso que motivó la misión. Un Monotributista carga 1210 (lo que dice su Factura
     * B) y el sistema guarda 1000 de costo neto, registrando el bruto tipeado.
     *
     * Antes de esta misión `cost` quedaba en 1210 y el costo real trepaba a 1464,10.
     *
     * @group costeo-precios
     * @test
     */
    public function monotributista_carga_el_bruto_y_el_sistema_guarda_el_neto()
    {
        $previos = $this->set_condicion('MT');

        try {

            $article = $this->articulo_con_alicuota('21');

            $response = $this->putJson('api/article/'.$article->id, $this->payload($article, [
                'cost' => 1210,
            ]));

            $response->assertStatus(200);

            $article->refresh();

            $this->assertEqualsWithDelta(
                1000,
                (float) $article->cost,
                self::DELTA,
                'MT carga 1210 con IVA 21%: articles.cost tiene que quedar en el neto (1000)'
            );

            $this->assertEqualsWithDelta(
                1210,
                (float) $article->cost_bruto,
                self::DELTA,
                'cost_bruto guarda el valor exacto que se tipeó, para no recalcularlo después'
            );

        } finally {
            $this->restaurar_condicion($previos);
        }
    }

    /**
     * Test 2 — el costo REAL del monotributista no cambia respecto de lo que pagó. Es la mitad que
     * importa: de nada sirve guardar bien el neto si el pipeline después no reconstruye el bruto.
     *
     * Es también la comprobación de la equivalencia sobre la que se apoya toda la misión: guardar
     * neto y volver a sumar el IVA da lo mismo que haber guardado el bruto, porque descuentos e IVA
     * son multiplicativos y conmutan.
     *
     * @group costeo-precios
     * @test
     */
    public function el_costo_real_del_monotributista_es_lo_que_pago()
    {
        $previos = $this->set_condicion('MT');

        try {

            $article = $this->articulo_con_alicuota('21');

            $this->putJson('api/article/'.$article->id, $this->payload($article, [
                'cost' => 1210,
            ]))->assertStatus(200);

            $article->refresh();

            $this->assertEqualsWithDelta(
                1210,
                (float) $article->costo_real,
                self::DELTA,
                'el costo real de un MT vuelve a ser lo que efectivamente pagó (1210), no 1464,10'
            );

        } finally {
            $this->restaurar_condicion($previos);
        }
    }

    /**
     * Test 3 — Responsable Inscripto con el default de la cuenta en "cargo el neto": el número
     * entra tal cual y `cost_bruto` queda en null, que es como el sistema lee "esto se cargó sin
     * IVA". Es el comportamiento histórico y no tiene que haberse movido.
     *
     * @group costeo-precios
     * @test
     */
    public function responsable_inscripto_que_carga_neto_guarda_el_valor_tal_cual()
    {
        $previos = $this->set_condicion('RRII', 0);

        try {

            $article = $this->articulo_con_alicuota('21');

            $this->putJson('api/article/'.$article->id, $this->payload($article, [
                'cost' => 1000,
            ]))->assertStatus(200);

            $article->refresh();

            $this->assertEqualsWithDelta(1000, (float) $article->cost, self::DELTA,
                'RRII cargando neto: el costo entra tal cual, sin descomponer');

            $this->assertNull($article->cost_bruto,
                'sin bruto tipeado, cost_bruto queda null: es la marca de "se cargó en neto"');

        } finally {
            $this->restaurar_condicion($previos);
        }
    }

    /**
     * Test 4 — el toggle del formulario pisa al default de la cuenta. Mismo RRII del test anterior,
     * misma cuenta configurada en "neto", pero esta carga puntual viene marcada como bruta.
     *
     * @group costeo-precios
     * @test
     */
    public function el_toggle_de_la_carga_pisa_al_default_de_la_cuenta()
    {
        $previos = $this->set_condicion('RRII', 0);

        try {

            $article = $this->articulo_con_alicuota('21');

            $this->putJson('api/article/'.$article->id, $this->payload($article, [
                'cost'              => 1210,
                'cost_incluye_iva'  => 1,
            ]))->assertStatus(200);

            $article->refresh();

            $this->assertEqualsWithDelta(1000, (float) $article->cost, self::DELTA,
                'con cost_incluye_iva en el request, un RRII descompone aunque su cuenta cargue neto');

        } finally {
            $this->restaurar_condicion($previos);
        }
    }

    /**
     * Test 5 — alícuota no numérica. `Exento` y `No Gravado` se guardan como TEXTO en
     * `ivas.percentage`, y cualquier cuenta aritmética sobre ellos tiene que tratarlos como 0. Un
     * artículo exento no tiene IVA que sacar: el costo entra tal cual aunque la cuenta sea MT.
     *
     * @group costeo-precios
     * @test
     */
    public function un_articulo_exento_no_se_descompone_ni_para_monotributista()
    {
        $previos = $this->set_condicion('MT');

        try {

            $article = $this->articulo_con_alicuota('Exento');

            $this->putJson('api/article/'.$article->id, $this->payload($article, [
                'cost' => 1000,
            ]))->assertStatus(200);

            $article->refresh();

            $this->assertEqualsWithDelta(1000, (float) $article->cost, self::DELTA,
                'Exento: no hay IVA que sacar, el costo entra tal cual (mismo criterio que hasIva())');

            $this->assertNull($article->cost_bruto,
                'sin descomposición no hay bruto que registrar');

        } finally {
            $this->restaurar_condicion($previos);
        }
    }

    /**
     * Test 6b — EL payload real del formulario cuando nadie toca el campo de costo.
     *
     * 🔴 Este es el test que faltaba y el que atrapa el bug más caro de la misión. La SPA manda
     * `cost` con el NETO que devolvió el servidor (porque `valor_visible` es una computed de
     * display y el store nunca se muta al abrir) y `cost_incluye_iva` en false. Si el backend
     * descompusiera igual —que es lo que pasa cuando la clave NO viaja, porque
     * `costo_tipeado_es_bruto()` cae al default de la cuenta, y para MT ese default es "bruto"
     * incondicional— entonces cambiarle el nombre a un artículo le hundiría el costo un 21%:
     * 1000 → 826,45 → 683,01, sin necesidad de tocar nunca el campo de costo.
     *
     * Lo encontró el segundo checker de la Fase 5, midiéndolo, después de que un fix anterior
     * sacara la escritura del flag junto con la del costo. Los otros siete tests seguían en verde
     * porque validaban un contrato que el formulario ya no cumplía.
     *
     * @group costeo-precios
     * @test
     */
    public function guardar_sin_tocar_el_costo_no_lo_descompone()
    {
        $previos = $this->set_condicion('MT');

        try {

            $article = $this->articulo_con_alicuota('21');

            // Estado de partida: un artículo ya cargado en bruto (cost neto 1000, bruto 1210).
            $this->putJson('api/article/'.$article->id, $this->payload($article, [
                'cost'             => 1210,
                'cost_incluye_iva' => 1,
            ]))->assertStatus(200);

            $article->refresh();

            $this->assertEqualsWithDelta(1000, (float) $article->cost, self::DELTA);

            // Ahora el guardado que NO toca el costo: el formulario manda el neto del store y el
            // flag apagado. Se cambia otra cosa (el nombre) para que sea un update real.
            $this->putJson('api/article/'.$article->id, $this->payload($article, [
                'cost'             => $article->cost,
                'cost_incluye_iva' => 0,
                'name'             => $article->name.' editado',
            ]))->assertStatus(200);

            $article->refresh();

            $this->assertEqualsWithDelta(
                1000,
                (float) $article->cost,
                self::DELTA,
                'guardar sin tocar el costo no puede descomponerlo: con el flag apagado el numero que llega YA es el neto'
            );

        } finally {
            $this->restaurar_condicion($previos);
        }
    }

    /**
     * Test 6 — idempotencia sobre el camino en que la persona SÍ tipea. Reguardar el bruto que el
     * formulario muestra no puede mover el neto.
     *
     * @group costeo-precios
     * @test
     */
    public function guardar_dos_veces_el_mismo_bruto_no_mueve_el_costo()
    {
        $previos = $this->set_condicion('MT');

        try {

            $article = $this->articulo_con_alicuota('21');

            $this->putJson('api/article/'.$article->id, $this->payload($article, [
                'cost'             => 1210,
                'cost_incluye_iva' => 1,
            ]))->assertStatus(200);

            $article->refresh();
            $primer_neto = (float) $article->cost;

            // Segundo guardado con lo que el formulario mostraría al tipear de nuevo: el bruto
            // registrado, con el flag prendido (es el camino "la persona toca el campo").
            $this->putJson('api/article/'.$article->id, $this->payload($article, [
                'cost'             => $article->cost_bruto,
                'cost_incluye_iva' => 1,
            ]))->assertStatus(200);

            $article->refresh();

            $this->assertEqualsWithDelta(
                $primer_neto,
                (float) $article->cost,
                self::DELTA,
                'reguardar el bruto que muestra el formulario no puede mover el neto: si lo mueve, el costo se hunde 21% por cada guardado'
            );

        } finally {
            $this->restaurar_condicion($previos);
        }
    }

    /**
     * Test 7 — cambiar la alícuota y el costo en el mismo submit. El back-out tiene que usar la
     * alícuota NUEVA, no la que el artículo tenía guardada. Por eso `set_costo_desde_request()`
     * corre después de asignar `iva_id`, y por eso `back_out_iva()` fuerza `load('iva')` en vez de
     * `loadMissing()`.
     *
     * @group costeo-precios
     * @test
     */
    public function cambiar_la_alicuota_y_el_costo_juntos_usa_la_alicuota_nueva()
    {
        $previos = $this->set_condicion('MT');

        try {

            // Arranca en 21% y pasa a 10.5% en el mismo request que cambia el costo.
            $article = $this->articulo_con_alicuota('21');

            $iva_nuevo = Iva::where('percentage', '10.5')->first();

            $this->assertNotNull($iva_nuevo, 'La base de testing no tiene la alícuota 10.5 sembrada.');

            $this->putJson('api/article/'.$article->id, $this->payload($article, [
                'cost'   => 1105,
                'iva_id' => $iva_nuevo->id,
            ]))->assertStatus(200);

            $article->refresh();

            $this->assertEqualsWithDelta(
                1000,
                (float) $article->cost,
                self::DELTA,
                '1105 con la alícuota NUEVA (10,5%) da 1000; con la vieja (21%) daría 913,22'
            );

        } finally {
            $this->restaurar_condicion($previos);
        }
    }

    /**
     * Test 9 — la declaración explícita le gana a `articles.aplicar_iva` (decisión de Lucas,
     * 20/8/2026).
     *
     * Un Responsable Inscripto marca "el costo que estoy cargando incluye IVA" sobre un artículo
     * con `aplicar_iva` apagado. Hasta este cambio el toggle no hacía NADA: `back_out_iva()`
     * cortaba en el guard de `aplicar_iva` y el 1210 se guardaba tal cual en una columna que es
     * NETA por convención, en silencio. El costo quedaba 21% inflado y `cost_bruto` en null, o sea
     * que ni siquiera quedaba rastro de que el número traía IVA adentro.
     *
     * El criterio: `aplicar_iva` es una decisión sobre la VENTA (si al precio se le suma IVA
     * encima), no sobre cómo interpretar un número que una persona acaba de tipear.
     *
     * @group costeo-precios
     * @test
     */
    public function el_toggle_explicito_descompone_aunque_aplicar_iva_este_apagado()
    {
        $previos = $this->set_condicion('RRII', 0);

        try {

            $article = $this->articulo_con_alicuota('21');

            $this->putJson('api/article/'.$article->id, $this->payload($article, [
                'cost'             => 1210,
                'aplicar_iva'      => 0,
                'cost_incluye_iva' => 1,
            ]))->assertStatus(200);

            $article->refresh();

            $this->assertEqualsWithDelta(1000, (float) $article->cost, self::DELTA,
                'si la persona declara que el número incluye IVA, se descompone aunque aplicar_iva esté en 0');

            $this->assertEqualsWithDelta(1210, (float) $article->cost_bruto, self::DELTA,
                'y el bruto tipeado queda registrado: hubo descomposición que reconstruir');

        } finally {
            $this->restaurar_condicion($previos);
        }
    }

    /**
     * Test 10 — el contrapeso del test 9: el DEFAULT de la cuenta no habilita ese bypass.
     *
     * Misma cuenta RRII y mismo artículo con `aplicar_iva = 0`, pero acá nadie declaró nada: el
     * "bruto" sale de `users.costos_cargados_con_iva`, que es una preferencia de la cuenta y no una
     * afirmación sobre este número. Con `aplicar_iva` apagado el sistema no le saca el IVA a ese
     * artículo por ningún lado, así que el costo queda en bruto a propósito (para esa cuenta el IVA
     * de compra de un artículo así es costo, no crédito recuperable) y `cost_bruto` va en null: no
     * hubo descomposición que reconstruir.
     *
     * 🔴 Sin este test, "creerle a la declaración" se podría implementar como "creerle siempre al
     * flag es_bruto", que le cambiaría el costeo a artículos que nadie tocó.
     *
     * @group costeo-precios
     * @test
     */
    public function el_default_de_la_cuenta_no_pasa_por_encima_de_aplicar_iva()
    {
        // La cuenta carga en bruto por default...
        $previos = $this->set_condicion('RRII', 1);

        try {

            $article = $this->articulo_con_alicuota('21');

            // ...pero el request NO trae `cost_incluye_iva`: nadie afirmó nada sobre este número.
            $payload = $this->payload($article, [
                'cost'        => 1210,
                'aplicar_iva' => 0,
            ]);

            $this->assertArrayNotHasKey('cost_incluye_iva', $payload,
                'este test vale sólo si la declaración explícita NO viaja en el request');

            $this->putJson('api/article/'.$article->id, $payload)->assertStatus(200);

            $article->refresh();

            $this->assertEqualsWithDelta(1210, (float) $article->cost, self::DELTA,
                'sin declaración explícita, aplicar_iva = 0 sigue bloqueando el back-out');

            $this->assertNull($article->cost_bruto,
                'sin descomposición no se registra bruto: cost_bruto es null');

        } finally {
            $this->restaurar_condicion($previos);
        }
    }

    /**
     * Test 11 — el límite de lo que la declaración explícita puede pisar.
     *
     * La declaración le gana a `aplicar_iva`, pero NO a `hasIva()`. Un artículo Exento no tiene IVA
     * adentro del número por más que alguien lo afirme: no hay nada que sacar. Es la razón por la
     * que el formulario deshabilita el toggle cuando no hay alícuota real (CostInput.vue) — si acá
     * se descompusiera, la pantalla y la base dirían cosas distintas otra vez.
     *
     * 🔴 `aplicar_iva` va en **1** a propósito, y cambiarlo a 0 rompe el test sin que se ponga en
     * rojo. Con `aplicar_iva = 0` el resultado sería idéntico con y sin la lógica que se quiere
     * probar —el guard de `aplicar_iva` frenaría primero—, así que el test pasaría igual si alguien
     * borrara el chequeo de `hasIva()`. Con `aplicar_iva = 1`, `hasIva()` queda como ÚNICA puerta y
     * el test mide lo que dice medir. Lo encontró el checker de la Fase 5.
     *
     * @group costeo-precios
     * @test
     */
    public function la_declaracion_explicita_no_le_gana_a_un_articulo_exento()
    {
        $previos = $this->set_condicion('RRII', 0);

        try {

            $article = $this->articulo_con_alicuota('Exento');

            $this->putJson('api/article/'.$article->id, $this->payload($article, [
                'cost'             => 1000,
                'aplicar_iva'      => 1,
                'cost_incluye_iva' => 1,
            ]))->assertStatus(200);

            $article->refresh();

            $this->assertEqualsWithDelta(1000, (float) $article->cost, self::DELTA,
                'un artículo Exento no tiene IVA que sacar, lo declare quien lo declare');

            $this->assertNull($article->cost_bruto,
                'y sin descomposición no hay bruto que registrar');

        } finally {
            $this->restaurar_condicion($previos);
        }
    }

    /**
     * Test 12 — guardar cualquier otra cosa del artículo no puede borrar `cost_bruto`.
     *
     * El formulario del listado manda el modelo entero en cada guardado, así que corregirle el
     * nombre a un artículo llega al backend con el mismo `cost` de siempre y `cost_incluye_iva` en
     * `false`, porque nadie tipeó en el campo de costo. El resolvedor devuelve `cost_bruto => null`
     * para esa carga —lo cual es correcto para esa carga—, y escribirlo **borraba** el bruto que la
     * compra a proveedor había registrado. El dato de origen moría en el primer guardado del
     * listado que cambiara cualquier otra cosa.
     *
     * El invariante que fija este test: `cost_bruto` es una propiedad DEL `cost` guardado. Si el
     * `cost` no se movió, el bruto que lo acompaña sigue siendo válido.
     *
     * Lo encontró el checker de la Fase 5, midiéndolo sobre 18 combinaciones.
     *
     * @group costeo-precios
     * @test
     */
    public function guardar_otra_cosa_del_articulo_no_borra_el_bruto_registrado()
    {
        $previos = $this->set_condicion('RRII', 0);

        try {

            $article = $this->articulo_con_alicuota('21');

            // Estado de partida: un bruto ya registrado, como lo deja una compra a proveedor.
            $article->cost = 1000;
            $article->cost_bruto = 1210;
            $article->save();

            /*
             * El payload del formulario cuando alguien cambia el nombre y no toca el costo: `cost`
             * es el mismo de la base y `cost_incluye_iva` viaja en false (lo manda siempre la SPA).
             */
            $this->putJson('api/article/'.$article->id, $this->payload($article, [
                'name'             => 'Nombre corregido a mano',
                'cost'             => $article->cost,
                'cost_incluye_iva' => 0,
            ]))->assertStatus(200);

            $article->refresh();

            $this->assertEqualsWithDelta(1000, (float) $article->cost, self::DELTA,
                'el costo no se movió, que es la premisa del test');

            $this->assertNotNull($article->cost_bruto,
                'el bruto registrado por la compra no puede morir en un guardado que ni tocó el costo');

            $this->assertEqualsWithDelta(1210, (float) $article->cost_bruto, self::DELTA,
                'y sigue siendo el mismo bruto');

        } finally {
            $this->restaurar_condicion($previos);
        }
    }

    /**
     * Test 13 — el contrapeso del 12: si el costo SÍ cambia, el bruto viejo no puede quedar.
     *
     * Es la otra mitad del invariante. Un `cost_bruto` que no corresponde al `cost` actual es peor
     * que no tener ninguno: el formulario del listado lo prefiere sobre recalcular, así que muestra
     * un número que no es el costo del artículo, y confirmarlo lo mueve.
     *
     * @group costeo-precios
     * @test
     */
    public function cambiar_el_costo_en_neto_limpia_el_bruto_viejo()
    {
        $previos = $this->set_condicion('RRII', 0);

        try {

            $article = $this->articulo_con_alicuota('21');

            $article->cost = 1000;
            $article->cost_bruto = 1210;
            $article->save();

            // Ahora sí se tipea un costo nuevo, en neto (el toggle apagado).
            $this->putJson('api/article/'.$article->id, $this->payload($article, [
                'cost'             => 900,
                'cost_incluye_iva' => 0,
            ]))->assertStatus(200);

            $article->refresh();

            $this->assertEqualsWithDelta(900, (float) $article->cost, self::DELTA,
                'el costo nuevo entra tal cual: se cargó en neto');

            $this->assertNull($article->cost_bruto,
                'el bruto de 1000 no puede quedar colgado de un costo de 900');

        } finally {
            $this->restaurar_condicion($previos);
        }
    }
}
