<?php

namespace Tests\Feature\Listado;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\article\ArticleProviderDiscountHelper;
use App\Models\Article;
use App\Models\ArticleDiscount;
use App\Models\Provider;
use App\Models\ProviderDiscount;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\EmpresaTestCase;

/**
 * Mision `descuentos-proveedor-propagar` (4/9/2026).
 *
 * Cambiar un descuento de un proveedor no le movia nada a los articulos que ya lo tenian copiado, y
 * el recalculo de precios que ya existia corria pero devolvia el mismo precio, porque lee las copias
 * viejas. Ahora, al guardar el proveedor, se le pregunta al usuario si propaga el cambio.
 *
 * 🔴 LO QUE MAS IMPORTA DE ESTE ARCHIVO SON DOS TESTS:
 *   - con la preferencia APAGADA no pasa absolutamente nada (es el default de ~40 comercios);
 *   - un descuento EDITADO A MANO no se pisa salvo que el usuario lo pida explicitamente.
 *
 * Los numeros son la especificacion. 🔴 Esta prohibido ajustar un valor esperado para que coincida
 * con lo que devuelve el sistema: si un test queda en rojo, se corrige el codigo.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promocion de constructor, readonly, enum ni #[...].
 *
 * @group costeo-precios
 */
class Propagar_Descuentos_Proveedor_Test extends EmpresaTestCase
{
    const DELTA = 0.01;

    const PROVEEDOR = 'zz Proveedor Propagar Descuentos';

    /**
     * @return \App\Models\User
     */
    private function owner()
    {
        return User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
    }

    /**
     * @param  int $valor 0 apagada, 1 prendida.
     * @return void
     */
    private function set_preferencia($valor)
    {
        $owner = $this->owner();
        $owner->aplicar_descuentos_proveedor_al_asignar = $valor;
        $owner->save();
    }

    /**
     * Proveedor limpio para esta suite.
     *
     * @return \App\Models\Provider
     */
    private function proveedor_de_la_suite()
    {
        $provider = Provider::firstOrCreate([
            'name'    => self::PROVEEDOR,
            'user_id' => $this->owner()->id,
        ]);

        ProviderDiscount::where('provider_id', $provider->id)->delete();

        return $provider->fresh();
    }

    /**
     * Articulo con un descuento tagueado al proveedor, del porcentaje que se pida. Simula el estado
     * que deja la preferencia al asignar el proveedor.
     *
     * @param  \App\Models\Provider $provider
     * @param  string $nombre
     * @param  float|null $percentage Porcentaje del descuento tagueado. Null = sin descuento.
     * @return \App\Models\Article
     */
    private function articulo_con_descuento_de($provider, $nombre, $percentage, $editado_a_mano = false)
    {
        $article = Article::create([
            'name'        => $nombre,
            'user_id'     => $this->owner()->id,
            'cost'        => 1000,
            'percentage_gain' => 50,
            'aplicar_iva' => 0,
            'provider_id' => $provider->id,
        ]);

        if (!is_null($percentage)) {
            ArticleDiscount::create([
                'article_id'     => $article->id,
                'provider_id'    => $provider->id,
                'percentage'     => $percentage,
                'tipo'           => ArticleDiscount::TIPO_BONIFICACION_PROVEEDOR,
                'editado_a_mano' => $editado_a_mano ? 1 : 0,
            ]);
        }

        /*
         * El articulo arranca con su costo_real ya calculado, como cualquier articulo real del
         * sistema. Sin esto queda en 0 y un test que afirma "el costo no se movio" pasaria por el
         * motivo equivocado: no porque el codigo lo respete, sino porque nunca hubo un numero.
         */
        $article = $article->fresh();

        ArticleHelper::setFinalPrice($article, $this->owner()->id);

        return $article->fresh();
    }

    /**
     * Simula el cambio de un descuento del proveedor tal como lo hace la pantalla: por el endpoint
     * real de `provider_discount`, que es quien registra el `percentage_anterior`.
     *
     * @param  \App\Models\ProviderDiscount $provider_discount
     * @param  float $nuevo_percentage
     * @return void
     */
    private function cambiar_descuento_del_proveedor($provider_discount, $nuevo_percentage)
    {
        $this->putJson('api/provider-discount/'.$provider_discount->id, [
            'percentage' => $nuevo_percentage,
        ])->assertStatus(200);
    }

    /**
     * @param  int $article_id
     * @return \Illuminate\Support\Collection
     */
    private function descuentos_tagueados($article_id)
    {
        return ArticleDiscount::where('article_id', $article_id)
                                ->whereNotNull('provider_id')
                                ->get();
    }

    /**
     * 🔴 El test que protege a los comercios que no prendieron la preferencia.
     *
     * @test
     */
    public function apagada_no_propaga_nada_ni_con_el_flag_prendido()
    {
        $this->set_preferencia(0);

        $provider = $this->proveedor_de_la_suite();
        $descuento = ProviderDiscount::create(['provider_id' => $provider->id, 'percentage' => 10]);

        $article = $this->articulo_con_descuento_de($provider, 'zz Propagar apagada', 10);

        $this->cambiar_descuento_del_proveedor($descuento, 15);

        $response = $this->putJson('api/provider/'.$provider->id.'/propagar-descuentos', [
            'pisar_editados_a_mano' => true,
        ]);

        $response->assertStatus(200);

        $vigentes = $this->descuentos_tagueados($article->id);

        $this->assertCount(1, $vigentes);

        $this->assertEqualsWithDelta(
            10,
            (float) $vigentes->first()->percentage,
            self::DELTA,
            'Con la preferencia apagada no se le puede tocar el descuento al articulo.'
        );
    }

    /**
     * El caso central: el articulo tenia el valor viejo del proveedor y se actualiza al nuevo, con
     * el costo real moviendose de verdad.
     *
     * @test
     */
    public function prendida_actualiza_el_articulo_desactualizado_y_mueve_el_costo()
    {
        $this->set_preferencia(1);

        $provider = $this->proveedor_de_la_suite();
        $descuento = ProviderDiscount::create(['provider_id' => $provider->id, 'percentage' => 10]);

        $article = $this->articulo_con_descuento_de($provider, 'zz Propagar desactualizado', 10);

        $this->cambiar_descuento_del_proveedor($descuento, 15);

        /* Antes de propagar, el articulo sigue con el 10% viejo: esa es la premisa del pedido. */
        $this->assertEqualsWithDelta(
            10,
            (float) $this->descuentos_tagueados($article->id)->first()->percentage,
            self::DELTA,
            'Precondicion: cambiar el descuento del proveedor NO toca el articulo por si solo.'
        );

        $response = $this->putJson('api/provider/'.$provider->id.'/propagar-descuentos', []);

        $response->assertStatus(200);

        $vigentes = $this->descuentos_tagueados($article->id);

        $this->assertCount(1, $vigentes);

        $this->assertEqualsWithDelta(
            15,
            (float) $vigentes->first()->percentage,
            self::DELTA,
            'Despues de propagar el articulo tiene que tener el porcentaje nuevo.'
        );

        $this->assertEqualsWithDelta(
            850,
            (float) $article->fresh()->costo_real,
            self::DELTA,
            'Y el costo real tiene que reflejar el 15%, no el 10%.'
        );
    }

    /**
     * 🔴 Un descuento que alguien edito a mano refleja una decision comercial para ESE articulo y no
     * se pisa por defecto.
     *
     * @test
     */
    public function prendida_respeta_el_editado_a_mano_salvo_que_se_pida_pisarlo()
    {
        $this->set_preferencia(1);

        $provider = $this->proveedor_de_la_suite();
        $descuento = ProviderDiscount::create(['provider_id' => $provider->id, 'percentage' => 10]);

        /* 40% no es ni el valor viejo (10) ni el nuevo (15): solo pudo ponerlo una persona. */
        $editado = $this->articulo_con_descuento_de($provider, 'zz Propagar editado a mano', 40, true);
        $normal  = $this->articulo_con_descuento_de($provider, 'zz Propagar normal', 10);

        $this->cambiar_descuento_del_proveedor($descuento, 15);

        /* Sin el flag: el editado se respeta, el desactualizado se actualiza. */
        $this->putJson('api/provider/'.$provider->id.'/propagar-descuentos', [])
                ->assertStatus(200);

        $this->assertEqualsWithDelta(
            40,
            (float) $this->descuentos_tagueados($editado->id)->first()->percentage,
            self::DELTA,
            'El descuento editado a mano no se puede pisar sin que lo pidan.'
        );

        $this->assertEqualsWithDelta(
            15,
            (float) $this->descuentos_tagueados($normal->id)->first()->percentage,
            self::DELTA,
            'El que estaba desactualizado si se actualiza en la misma corrida.'
        );

        /* Con el flag: ahora si. */
        $this->putJson('api/provider/'.$provider->id.'/propagar-descuentos', [
            'pisar_editados_a_mano' => true,
        ])->assertStatus(200);

        $this->assertEqualsWithDelta(
            15,
            (float) $this->descuentos_tagueados($editado->id)->first()->percentage,
            self::DELTA,
            'Con el tilde prendido, el editado a mano tambien se actualiza.'
        );
    }

    /**
     * 🔴 Los descuentos que el usuario cargo a mano en el articulo (`provider_id` null) no se tocan
     * en ninguno de los dos casos: la opcion de "pisar" habla de los tagueados que difieren, no de
     * los que el usuario cargo aparte.
     *
     * @test
     */
    public function prendida_nunca_toca_los_descuentos_manuales_del_articulo()
    {
        $this->set_preferencia(1);

        $provider = $this->proveedor_de_la_suite();
        $descuento = ProviderDiscount::create(['provider_id' => $provider->id, 'percentage' => 10]);

        $article = $this->articulo_con_descuento_de($provider, 'zz Propagar con manual', 10);

        $manual = ArticleDiscount::create([
            'article_id'  => $article->id,
            'provider_id' => null,
            'percentage'  => 3,
            'tipo'        => ArticleDiscount::TIPO_OTRO,
        ]);

        $this->cambiar_descuento_del_proveedor($descuento, 15);

        $this->putJson('api/provider/'.$provider->id.'/propagar-descuentos', [
            'pisar_editados_a_mano' => true,
        ])->assertStatus(200);

        $this->assertNotNull(
            ArticleDiscount::find($manual->id),
            'El descuento manual del usuario no se puede borrar nunca.'
        );

        $this->assertEqualsWithDelta(
            3,
            (float) ArticleDiscount::find($manual->id)->percentage,
            self::DELTA,
            'Ni cambiarle el porcentaje.'
        );

        /* Cascada de los dos: 1000 x 0,85 x 0,97 = 824,50. */
        $this->assertEqualsWithDelta(
            824.50,
            (float) $article->fresh()->costo_real,
            self::DELTA,
            'El costo real tiene que combinar el descuento nuevo del proveedor y el manual.'
        );
    }

    /**
     * Agregar un descuento al proveedor se lo agrega a los articulos; borrarlo se lo saca. No es
     * solo cambiar un porcentaje: el conjunto completo tiene que quedar igual al del proveedor.
     *
     * @test
     */
    public function prendida_agregar_y_borrar_descuentos_del_proveedor_se_refleja_en_los_articulos()
    {
        $this->set_preferencia(1);

        $provider = $this->proveedor_de_la_suite();
        ProviderDiscount::create(['provider_id' => $provider->id, 'percentage' => 10]);

        $article = $this->articulo_con_descuento_de($provider, 'zz Propagar alta y baja', 10);

        /* El proveedor ahora tiene DOS bonificaciones. */
        ProviderDiscount::create(['provider_id' => $provider->id, 'percentage' => 5]);

        $this->putJson('api/provider/'.$provider->id.'/propagar-descuentos', [])
                ->assertStatus(200);

        $this->assertCount(
            2,
            $this->descuentos_tagueados($article->id),
            'El descuento nuevo del proveedor tiene que aparecer en el articulo.'
        );

        /* 1000 x 0,90 x 0,95 = 855, en cascada. */
        $this->assertEqualsWithDelta(
            855,
            (float) $article->fresh()->costo_real,
            self::DELTA,
            'El costo real tiene que aplicar los dos en cascada.'
        );

        /* Y ahora el proveedor se queda con uno solo. */
        ProviderDiscount::where('provider_id', $provider->id)->where('percentage', 5)->delete();

        $this->putJson('api/provider/'.$provider->id.'/propagar-descuentos', [])
                ->assertStatus(200);

        $this->assertCount(
            1,
            $this->descuentos_tagueados($article->id),
            'El descuento borrado del proveedor tiene que desaparecer del articulo.'
        );

        $this->assertEqualsWithDelta(
            900,
            (float) $article->fresh()->costo_real,
            self::DELTA,
            'Y el costo real tiene que volver a reflejar solo el 10%.'
        );
    }

    /**
     * El preview cuenta bien las tres categorias: es lo que la ventana le muestra al usuario para
     * que decida, asi que un numero mal ahi es una decision tomada con informacion falsa.
     *
     * @test
     */
    public function el_preview_cuenta_al_dia_desactualizados_y_editados_a_mano()
    {
        $this->set_preferencia(1);

        $provider = $this->proveedor_de_la_suite();
        $descuento = ProviderDiscount::create(['provider_id' => $provider->id, 'percentage' => 10]);

        $desactualizado = $this->articulo_con_descuento_de($provider, 'zz Preview viejo', 10);
        $editado        = $this->articulo_con_descuento_de($provider, 'zz Preview editado', 40, true);

        $this->cambiar_descuento_del_proveedor($descuento, 15);

        /* Este ya tiene el valor NUEVO: esta al dia y no hay que tocarlo. */
        $al_dia = $this->articulo_con_descuento_de($provider, 'zz Preview al dia', 15);

        $response = $this->getJson('api/provider/'.$provider->id.'/propagar-descuentos/preview');

        $response->assertStatus(200);

        $preview = json_decode($response->getContent(), true);

        $this->assertEquals(1, $preview['desactualizados'], 'Uno tenia el valor viejo del proveedor.');
        $this->assertEquals(1, $preview['editados_a_mano'], 'Uno tiene un porcentaje que solo pudo poner una persona.');
        $this->assertEquals(1, $preview['al_dia'], 'Uno ya tiene el porcentaje nuevo.');
        $this->assertEquals(3, $preview['total']);
        $this->assertTrue($preview['preferencia_activa']);
    }

    /**
     * 🔴 La marca se pone SOLA cuando una persona edita el descuento desde la ficha del articulo, y
     * a partir de ahi la propagacion lo respeta.
     *
     * Este test existe porque los demas ponen `editado_a_mano` a mano en el fixture: con ese fixture
     * sacar el marcado del controller dejaba la suite entera en verde (verificado por mutacion). El
     * unico que ejercita el camino real —el endpoint que el formulario usa— es este.
     *
     * Cubre ademas que un guardado que NO cambia el porcentaje no marca nada: si marcara, cualquier
     * guardado del formulario sacaria al descuento del gobierno del proveedor para siempre.
     *
     * @test
     */
    public function editar_el_descuento_desde_el_articulo_lo_marca_y_la_propagacion_lo_respeta()
    {
        $this->set_preferencia(1);

        $provider = $this->proveedor_de_la_suite();
        ProviderDiscount::create(['provider_id' => $provider->id, 'percentage' => 10]);

        $article = $this->articulo_con_descuento_de($provider, 'zz Editado por endpoint', 10);

        $descuento = $this->descuentos_tagueados($article->id)->first();

        $this->assertEquals(
            0,
            (int) $descuento->editado_a_mano,
            'Precondicion: el descuento que puso el sistema no nace marcado.'
        );

        /* Un guardado que NO toca el porcentaje no puede marcarlo. */
        $this->putJson('api/article-discount/'.$descuento->id, [
            'percentage'     => 10,
            'amount'         => null,
            'show_in_online' => 0,
        ])->assertStatus(200);

        $this->assertEquals(
            0,
            (int) ArticleDiscount::find($descuento->id)->editado_a_mano,
            'Guardar sin cambiar el numero no puede sacar al descuento del gobierno del proveedor.'
        );

        /* Ahora si: una persona le cambia el porcentaje desde la ficha. */
        $this->putJson('api/article-discount/'.$descuento->id, [
            'percentage'     => 40,
            'amount'         => null,
            'show_in_online' => 0,
        ])->assertStatus(200);

        $this->assertEquals(
            1,
            (int) ArticleDiscount::find($descuento->id)->editado_a_mano,
            'Editar el porcentaje desde la ficha tiene que marcarlo como editado a mano.'
        );

        /* Y desde ahi la propagacion lo respeta sin que nadie haya tocado el fixture. */
        $this->putJson('api/provider/'.$provider->id.'/propagar-descuentos', [])
                ->assertStatus(200);

        $this->assertEqualsWithDelta(
            40,
            (float) ArticleDiscount::find($descuento->id)->percentage,
            self::DELTA,
            'La propagacion tiene que respetar el descuento que la persona edito.'
        );
    }

    /**
     * 🔴 EL DESCUENTO DE MONTO FIJO QUE DEJO UNA COMPRA SOBREVIVE A LA PROPAGACION.
     *
     * Lo encontro el chequeo independiente y era el hallazgo mas caro. `article_discounts` no tiene
     * columna de origen: la compra, el import y la ficha del proveedor escriben todos con
     * `provider_id` y `tipo = bonificacion_proveedor`. Lo unico que los separa es la forma: la ficha
     * del proveedor SOLO tiene porcentajes, asi que un tagueado con `amount` solo pudo dejarlo una
     * compra, con la bonificacion de monto fijo que se negocio ahi.
     *
     * La primera version barria todos los tagueados del proveedor y recreaba desde la ficha: los
     * $1500 de una compra se iban para siempre, el costo del articulo subia, y no habia con que
     * reponerlos. Encima el articulo caia SIEMPRE en "desactualizado", asi que el modal lo ofrecia
     * como una actualizacion de rutina.
     *
     * @test
     */
    public function prendida_no_borra_el_descuento_de_monto_fijo_que_dejo_una_compra()
    {
        $this->set_preferencia(1);

        $provider = $this->proveedor_de_la_suite();
        ProviderDiscount::create(['provider_id' => $provider->id, 'percentage' => 10]);

        $article = $this->articulo_con_descuento_de($provider, 'zz Propagar con monto de compra', 10);

        /* Lo que deja NewProviderOrderHelper al confirmar una compra con bonificacion en pesos. */
        $de_la_compra = ArticleDiscount::create([
            'article_id'  => $article->id,
            'provider_id' => $provider->id,
            'percentage'  => null,
            'amount'      => 1500,
            'tipo'        => ArticleDiscount::TIPO_BONIFICACION_PROVEEDOR,
        ]);

        $this->putJson('api/provider/'.$provider->id.'/propagar-descuentos', [
            'pisar_editados_a_mano' => true,
        ])->assertStatus(200);

        $vivo = ArticleDiscount::find($de_la_compra->id);

        $this->assertNotNull(
            $vivo,
            'El descuento de monto fijo de una compra no se puede borrar: la ficha del proveedor no tiene con que reponerlo.'
        );

        $this->assertEqualsWithDelta(
            1500,
            (float) $vivo->amount,
            self::DELTA,
            'Y tiene que conservar el monto negociado en esa compra.'
        );
    }

    /**
     * 🔴 UN PROVEEDOR SIN DESCUENTOS EN LA FICHA NO VACIA A SUS ARTICULOS.
     *
     * El otro hallazgo caro del chequeo: propagar "nada" era destruir. Se borraban los descuentos
     * tagueados que habian dejado las compras y el import, no se creaba ninguno, y un catalogo
     * entero pasaba a costo bruto de golpe — con la ventana presentandolo como rutina.
     *
     * @test
     */
    public function prendida_un_proveedor_sin_descuentos_no_borra_nada()
    {
        $this->set_preferencia(1);

        /* proveedor_de_la_suite() deja la ficha SIN provider_discounts. */
        $provider = $this->proveedor_de_la_suite();

        $article = $this->articulo_con_descuento_de($provider, 'zz Propagar sin descuentos en ficha', 10);

        $this->putJson('api/provider/'.$provider->id.'/propagar-descuentos', [
            'pisar_editados_a_mano' => true,
        ])->assertStatus(200);

        $this->assertCount(
            1,
            $this->descuentos_tagueados($article->id),
            'Sin descuentos en la ficha del proveedor no hay nada que propagar, y menos que borrar.'
        );

        $this->assertEqualsWithDelta(
            900,
            (float) $article->fresh()->costo_real,
            self::DELTA,
            'El costo real no puede saltar al bruto.'
        );
    }

    /**
     * 🔴 Una fila de descuento VACIA en la ficha del proveedor tampoco vacia a los articulos.
     *
     * Es el mismo destrozo del test de arriba por otro camino, y se encontro verificando el arreglo:
     * `provider_discounts.percentage` es nullable y el has_many del formulario deja agregar una fila
     * sin completarla. Una guarda que cuente FILAS deja pasar a ese proveedor —tiene una— aunque no
     * tenga ni un porcentaje con que rehacer nada. La guarda cuenta porcentajes utilizables.
     *
     * @test
     */
    public function prendida_una_fila_de_descuento_vacia_no_borra_nada()
    {
        $this->set_preferencia(1);

        $provider = $this->proveedor_de_la_suite();

        /* Fila creada y nunca completada: existe, pero no tiene porcentaje. */
        ProviderDiscount::create(['provider_id' => $provider->id, 'percentage' => null]);

        $article = $this->articulo_con_descuento_de($provider, 'zz Propagar con fila vacia', 10);

        $this->putJson('api/provider/'.$provider->id.'/propagar-descuentos', [
            'pisar_editados_a_mano' => true,
        ])->assertStatus(200);

        $this->assertCount(
            1,
            $this->descuentos_tagueados($article->id),
            'Una fila de descuento sin completar no habilita a borrar los descuentos de los articulos.'
        );

        $this->assertEqualsWithDelta(
            900,
            (float) $article->fresh()->costo_real,
            self::DELTA,
            'Y el costo real no puede saltar al bruto.'
        );
    }

    /**
     * 🔴 El "Mostrar en la tienda online" sobrevive a la propagacion.
     *
     * Sin esto, cada propagacion apagaba el tilde en silencio (la columna es default 0) y el
     * articulo perdia el precio tachado y el badge de oferta en el ecommerce —lo que arma
     * `tienda-spa/src/mixins/generals.js`, que filtra por ese flag—. El precio que paga el comprador
     * no cambiaba, asi que es la clase de cosa que nadie reporta durante meses.
     *
     * @test
     */
    public function prendida_conserva_el_mostrar_en_la_tienda_online()
    {
        $this->set_preferencia(1);

        $provider = $this->proveedor_de_la_suite();
        $descuento_proveedor = ProviderDiscount::create(['provider_id' => $provider->id, 'percentage' => 10]);

        $article = $this->articulo_con_descuento_de($provider, 'zz Propagar con tienda online', 10);

        $tagueado = $this->descuentos_tagueados($article->id)->first();
        $tagueado->show_in_online = 1;
        $tagueado->save();

        $this->cambiar_descuento_del_proveedor($descuento_proveedor, 15);

        $this->putJson('api/provider/'.$provider->id.'/propagar-descuentos', [])
                ->assertStatus(200);

        $vigente = $this->descuentos_tagueados($article->id)->first();

        $this->assertEqualsWithDelta(
            15,
            (float) $vigente->percentage,
            self::DELTA,
            'Precondicion: el descuento se actualizo.'
        );

        $this->assertEquals(
            1,
            (int) $vigente->show_in_online,
            'Y tiene que seguir mostrandose en la tienda online, como lo habia dejado el comercio.'
        );
    }

    /**
     * Un descuento marcado como editado a mano que HOY coincide con el del proveedor deja de contar
     * como editado: no hay nada que decidir ni nada que perder.
     *
     * Sin esta salida, un articulo que alguien edito una vez y despues dejo igual al del proveedor
     * quedaba marcado para siempre, y la ventana aparecia en todos los guardados de ese proveedor
     * aunque no hubiera nada para actualizar — hasta volverse ruido que se confirma sin leer.
     *
     * @test
     */
    public function la_marca_no_cuenta_si_el_valor_volvio_a_coincidir_con_el_del_proveedor()
    {
        $this->set_preferencia(1);

        $provider = $this->proveedor_de_la_suite();
        ProviderDiscount::create(['provider_id' => $provider->id, 'percentage' => 10]);

        /* Marcado, pero con el MISMO porcentaje que tiene el proveedor hoy. */
        $article = $this->articulo_con_descuento_de($provider, 'zz Marca que ya no aplica', 10, true);

        $response = $this->getJson('api/provider/'.$provider->id.'/propagar-descuentos/preview');

        $response->assertStatus(200);

        $preview = json_decode($response->getContent(), true);

        $this->assertEquals(0, $preview['editados_a_mano'], 'Ya no difiere del proveedor: no hay nada que preguntar.');
        $this->assertEquals(1, $preview['al_dia']);
        $this->assertEquals(0, $preview['desactualizados']);
    }

    /**
     * La clasificacion es la pieza que decide todo lo demas, y se prueba aparte de la base para
     * cubrir las combinaciones sin fabricar un artículo por cada una.
     *
     * @test
     */
    public function la_clasificacion_distingue_las_tres_situaciones()
    {
        /* Cada item es [porcentaje, editado_a_mano]. */
        $con = function ($items) {
            return collect($items)->map(function ($item) {
                return (object) [
                    'percentage'     => $item[0],
                    'editado_a_mano' => isset($item[1]) ? $item[1] : 0,
                ];
            });
        };

        $actuales = ['15.00'];

        $this->assertEquals(
            'al_dia',
            ArticleProviderDiscountHelper::clasificar_articulo($con([[15]]), $actuales)
        );

        $this->assertEquals(
            'desactualizado',
            ArticleProviderDiscountHelper::clasificar_articulo($con([[10]]), $actuales),
            'Tiene el valor viejo y nadie lo edito: se actualiza sin preguntar.'
        );

        $this->assertEquals(
            'editado_a_mano',
            ArticleProviderDiscountHelper::clasificar_articulo($con([[40, 1]]), $actuales)
        );

        /* Le falta uno que el proveedor agrego: desactualizado, no "al dia". */
        $this->assertEquals(
            'desactualizado',
            ArticleProviderDiscountHelper::clasificar_articulo($con([[15]]), ['15.00', '5.00'])
        );

        /*
         * 🔴 El caso que rompio la version anterior: al proveedor le BORRARON un descuento, asi que
         * al articulo le sobra el de 5%. Ese 5% lo puso el sistema, no una persona: tiene que salir
         * como desactualizado y actualizarse sin preguntar.
         */
        $this->assertEquals(
            'desactualizado',
            ArticleProviderDiscountHelper::clasificar_articulo($con([[15], [5]]), $actuales),
            'Un descuento que el proveedor borro no convierte al articulo en editado a mano.'
        );

        /* Uno editado a mano gana aunque el otro este al dia: se pregunta, no se pisa. */
        $this->assertEquals(
            'editado_a_mano',
            ArticleProviderDiscountHelper::clasificar_articulo($con([[15], [40, 1]]), $actuales)
        );

        /* Distintas formas del mismo numero no pueden contar como distintas. */
        $this->assertEquals(
            'al_dia',
            ArticleProviderDiscountHelper::clasificar_articulo($con([['15.00']]), $actuales)
        );
    }
}
