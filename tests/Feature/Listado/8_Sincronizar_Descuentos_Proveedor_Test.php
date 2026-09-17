<?php

namespace Tests\Feature\Listado;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\article\ArticleProviderDiscountHelper;
use App\Models\Article;
use App\Models\ArticleDiscount;
use App\Models\ArticleSurchage;
use App\Models\Provider;
use App\Models\ProviderDiscount;
use App\Models\ProviderOrderDiscount;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Notification;
use Tests\EmpresaTestCase;

/**
 * Mision `sincronizar-descuentos-proveedor` (17/9/2026).
 *
 * El boton "Sincronizar articulos" de la ficha del proveedor: un camino NUEVO, distinto del que ya
 * existia (`propagar_a_articulos()`), con tres diferencias que son justamente lo que estos tests
 * fijan.
 *
 * 🔴 LO QUE MAS IMPORTA DE ESTE ARCHIVO:
 *
 *   1. El modo "todos" alcanza a articulos que NUNCA tuvieron un descuento de la ficha. Eso es
 *      poder crear descuentos donde no habia ninguno, o sea mover el costo de un catalogo entero.
 *   2. Los tres caminos sobre los articulos con descuentos de una COMPRA (saltear / pisar /
 *      agregar) deciden si una bonificacion negociada —que la ficha NO puede reponer— sobrevive.
 *      `saltear` es el default y es el unico que no destruye nada.
 *   3. El descuento MANUAL del usuario no se toca en ninguno de los tres modos, ni con el tilde de
 *      pisar editados a mano.
 *   4. La preferencia `aplicar_descuentos_proveedor_al_asignar` NO gatea este camino (decision de
 *      Lucas, 17/9/2026) pero SIGUE gateando el viejo. Las dos mitades estan cubiertas: si alguien
 *      "unifica" los dos caminos, uno de los dos tests se pone rojo.
 *
 * ⚠️ La sincronizacion real corre en cola (`ProcessSincronizarDescuentosProveedorJob`). Estos tests
 * ponen la conexion de colas en `sync` para que el endpoint ejecute el job en el mismo request: asi
 * se recorre el camino completo (ruta -> controller -> job -> helper) y no solo la mitad. Las
 * notificaciones van a `Notification::fake()`, que es lo unico que se reemplaza.
 *
 * Los numeros son la especificacion. 🔴 Esta prohibido ajustar un valor esperado para que coincida
 * con lo que devuelve el sistema: si un test queda en rojo, se corrige el codigo.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promocion de constructor, readonly, enum ni #[...].
 *
 * @group costeo-precios
 */
class Sincronizar_Descuentos_Proveedor_Test extends EmpresaTestCase
{
    /**
     * Delta de tolerancia para comparaciones de plata (nunca comparacion exacta sobre floats).
     */
    const DELTA = 0.01;

    /**
     * Prefijo de los proveedores de esta suite. Prefijo `zz` como el resto de los fixtures de
     * Listado: no se toca ningun proveedor del seeder, que tiene expectativas en la suite e2e.
     */
    const PROVEEDOR = 'zz Proveedor Sincronizar Descuentos';

    /**
     * Costo bruto de todos los articulos de esta suite. Redondo a proposito: los costos esperados
     * de cada test son cuentas que se pueden verificar de cabeza.
     */
    const COSTO = 1000;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        /*
         * El aviso final del job (GlobalNotification) sale por Pusher y no es lo que estos tests
         * miden. Se lo intercepta para que la corrida no dependa de un broadcast.
         */
        Notification::fake();

        /*
         * 🔴 La cola en `sync` para que el `dispatch()` del controller ejecute el job EN EL MISMO
         * REQUEST. Sin esto, `.env.testing` tiene `QUEUE_CONNECTION=database`: el dispatch
         * insertaria una fila en `jobs` y ningun test veria un solo descuento tocado — todos
         * pasarian en verde sin ejecutar una linea de la sincronizacion.
         */
        config(['queue.default' => 'sync']);
    }

    /* ==================================================================================
     * FIXTURES
     * ================================================================================== */

    /**
     * @return \App\Models\User
     */
    private function owner()
    {
        return User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
    }

    /**
     * Deja la preferencia del comercio en el estado que pide el test.
     *
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
     * Proveedor limpio para el test que lo pida: sin descuentos en la ficha, sin articulos
     * asignados y sin descuentos tagueados sueltos.
     *
     * La limpieza no es paranoia: si una corrida anterior murio a la mitad (Ctrl+C, deadlock), la
     * transaccion de ese test no revirtio y sus articulos siguen colgando de este proveedor. El
     * universo que escanea la sincronizacion es "todos los articulos del proveedor", asi que una
     * sobra de otra corrida cambia los contadores del preview sin que nada lo explique.
     *
     * @param  string $sufijo Para que un test que necesita dos proveedores no los mezcle.
     * @return \App\Models\Provider
     */
    private function proveedor_de_la_suite($sufijo = '')
    {
        $provider = Provider::firstOrCreate([
            'name'    => self::PROVEEDOR . ($sufijo === '' ? '' : ' ' . $sufijo),
            'user_id' => $this->owner()->id,
        ]);

        ProviderDiscount::where('provider_id', $provider->id)->delete();
        ArticleDiscount::where('provider_id', $provider->id)->delete();
        Article::where('provider_id', $provider->id)->delete();

        return $provider->fresh();
    }

    /**
     * Carga un descuento en la ficha del proveedor POR EL ENDPOINT REAL, que es el unico que
     * escribe la columna `nombre`.
     *
     * @param  \App\Models\Provider $provider
     * @param  float|null $percentage
     * @param  string|null $nombre
     * @return \App\Models\ProviderDiscount
     */
    private function descuento_del_proveedor($provider, $percentage, $nombre = null)
    {
        $response = $this->postJson('api/provider-discount', [
            'model_id'   => $provider->id,
            'percentage' => $percentage,
            'nombre'     => $nombre,
        ]);

        $response->assertStatus(201);

        return ProviderDiscount::find($response->json('model.id'));
    }

    /**
     * Articulo del proveedor, con su costo real ya calculado.
     *
     * El costo arranca calculado como el de cualquier articulo real del sistema: sin esto queda en
     * 0 y un test que afirma "el costo no se movio" pasaria por el motivo equivocado.
     *
     * @param  \App\Models\Provider $provider
     * @param  string $nombre
     * @return \App\Models\Article
     */
    private function articulo_del_proveedor($provider, $nombre)
    {
        $article = Article::create([
            'name'            => $nombre,
            'user_id'         => $this->owner()->id,
            'cost'            => self::COSTO,
            'percentage_gain' => 50,
            'aplicar_iva'     => 0,
            'provider_id'     => $provider->id,
        ]);

        $article = $article->fresh();

        ArticleHelper::setFinalPrice($article, $this->owner()->id);

        return $article->fresh();
    }

    /**
     * Le deja al articulo un `article_discount` tagueado con origen FICHA, del porcentaje que se
     * pida. Es el estado que deja hoy la preferencia al asignar el proveedor, y el mismo fixture
     * que usa `7_Propagar_Descuentos_Proveedor_Test`.
     *
     * @param  \App\Models\Article  $article
     * @param  \App\Models\Provider $provider
     * @param  float $percentage
     * @param  bool  $editado_a_mano
     * @return \App\Models\ArticleDiscount
     */
    private function descuento_de_ficha($article, $provider, $percentage, $editado_a_mano = false)
    {
        $descuento = ArticleDiscount::create([
            'article_id'     => $article->id,
            'provider_id'    => $provider->id,
            'percentage'     => $percentage,
            'tipo'           => ArticleDiscount::TIPO_BONIFICACION_PROVEEDOR,
            'editado_a_mano' => $editado_a_mano ? 1 : 0,
            'origen'         => ArticleDiscount::ORIGEN_FICHA_PROVEEDOR,
        ]);

        $this->recalcular($article);

        return $descuento;
    }

    /**
     * Le deja al articulo la bonificacion negociada en una COMPRA.
     *
     * 🔴 Va por `create_tagged_discounts()` con un `ProviderOrderDiscount` de verdad, que es
     * exactamente lo que hace `NewProviderOrderHelper::materializar_descuentos_proveedor_en_articulos()`
     * al confirmar una compra (la unica diferencia con `sync_provider_discounts()` es el barrido
     * previo, que acá no corresponde porque el articulo puede tener ademas los de la ficha).
     *
     * Escribirlo con un `ArticleDiscount::create([...'origen' => 'compra'])` a mano tambien
     * "funcionaria", pero seria fabricar la fila que el codigo espera en vez de dejar que la
     * escriba quien la escribe en produccion.
     *
     * @param  \App\Models\Article  $article
     * @param  \App\Models\Provider $provider
     * @param  float|null $percentage
     * @param  float|null $monto
     * @return void
     */
    private function descuento_de_compra($article, $provider, $percentage, $monto = null)
    {
        $de_la_compra = new ProviderOrderDiscount([
            'percentage' => $percentage,
            'monto'      => $monto,
        ]);

        ArticleProviderDiscountHelper::create_tagged_discounts(
            $article,
            $provider->id,
            collect([$de_la_compra]),
            0,
            ArticleDiscount::ORIGEN_COMPRA
        );

        $this->recalcular($article);
    }

    /**
     * Descuento MANUAL del usuario, cargado por el formulario real de la ficha del articulo. Nace
     * con `provider_id` null y `origen` manual, que es lo que lo deja fuera de toda propagacion.
     *
     * @param  \App\Models\Article $article
     * @param  float $percentage
     * @return \App\Models\ArticleDiscount
     */
    private function descuento_manual($article, $percentage)
    {
        $response = $this->postJson('api/article-discount', [
            'model_id'       => $article->id,
            'percentage'     => $percentage,
            'amount'         => null,
            'show_in_online' => 0,
        ]);

        $response->assertStatus(201);

        return ArticleDiscount::find($response->json('model.id'));
    }

    /**
     * @param  \App\Models\Article $article
     * @return void
     */
    private function recalcular($article)
    {
        $fresco = Article::find($article->id);

        $fresco->unsetRelation('article_discounts');

        ArticleHelper::setFinalPrice($fresco, $this->owner()->id);
    }

    /* ==================================================================================
     * LECTURAS
     * ================================================================================== */

    /**
     * @param  \App\Models\Provider $provider
     * @param  array $payload
     * @return \Illuminate\Testing\TestResponse
     */
    private function sincronizar($provider, $payload = [])
    {
        return $this->putJson('api/provider/' . $provider->id . '/sincronizar-descuentos', $payload);
    }

    /**
     * @param  \App\Models\Provider $provider
     * @return array
     */
    private function preview($provider)
    {
        $response = $this->getJson('api/provider/' . $provider->id . '/sincronizar-descuentos/preview');

        $response->assertStatus(200);

        return json_decode($response->getContent(), true);
    }

    /**
     * Descuentos tagueados a algun proveedor (los que la sincronizacion puede tocar).
     *
     * @param  int $article_id
     * @return \Illuminate\Support\Collection
     */
    private function tagueados($article_id)
    {
        return ArticleDiscount::where('article_id', $article_id)
                                ->whereNotNull('provider_id')
                                ->orderBy('id')
                                ->get();
    }

    /**
     * @param  int $article_id
     * @param  string $origen
     * @return \Illuminate\Support\Collection
     */
    private function tagueados_de_origen($article_id, $origen)
    {
        return $this->tagueados($article_id)->filter(function ($descuento) use ($origen) {
            return $descuento->origen === $origen;
        })->values();
    }

    /**
     * @param  int $article_id
     * @return float
     */
    private function costo_real($article_id)
    {
        return (float) Article::find($article_id)->costo_real;
    }

    /* ==================================================================================
     * 1. LOS DOS ALCANCES
     * ================================================================================== */

    /**
     * `solo_con_descuentos` se comporta como la propagacion de hoy: alcanza al articulo que YA
     * tiene un descuento de la ficha y no le crea nada al que no tiene ninguno.
     *
     * Es la mitad conservadora del boton, y la que hace que el modo "todos" signifique algo: si los
     * dos alcances hicieran lo mismo, la pregunta del modal seria decorativa.
     *
     * @test
     */
    public function solo_con_descuentos_actualiza_al_que_ya_tenia_y_no_le_crea_nada_al_que_no()
    {
        $this->set_preferencia(1);

        $provider = $this->proveedor_de_la_suite();
        $descuento = $this->descuento_del_proveedor($provider, 10, 'Bonificacion general');

        $con    = $this->articulo_del_proveedor($provider, 'zz Sincro solo-con CON');
        $sin    = $this->articulo_del_proveedor($provider, 'zz Sincro solo-con SIN');

        $this->descuento_de_ficha($con, $provider, 10);

        /* El proveedor renegocia: de 10 a 15. */
        $this->putJson('api/provider-discount/' . $descuento->id, [
            'percentage' => 15,
            'nombre'     => 'Bonificacion general',
        ])->assertStatus(200);

        $this->sincronizar($provider, ['alcance' => 'solo_con_descuentos'])->assertStatus(200);

        $del_con = $this->tagueados($con->id);

        $this->assertCount(1, $del_con, 'El articulo que ya tenia el descuento queda con uno solo.');

        $this->assertEqualsWithDelta(
            15,
            (float) $del_con->first()->percentage,
            self::DELTA,
            'Y con el porcentaje nuevo de la ficha.'
        );

        $this->assertEqualsWithDelta(850, $this->costo_real($con->id), self::DELTA);

        $this->assertCount(
            0,
            $this->tagueados($sin->id),
            'Con alcance "solo_con_descuentos", al articulo sin ningun descuento NO se le crea nada: '.
            'eso es lo que hace el modo "todos", y es la unica diferencia entre los dos.'
        );

        $this->assertEqualsWithDelta(
            self::COSTO,
            $this->costo_real($sin->id),
            self::DELTA,
            'Y por lo tanto su costo no se mueve.'
        );
    }

    /**
     * 🔴 EL ALCANCE NUEVO: el articulo del proveedor que no tenia NINGUN descuento recibe los de la
     * ficha, con el origen, la relacion y el nombre.
     *
     * Hasta hoy ese articulo era invisible para toda propagacion (`propagar_a_articulos()` recorre
     * `ArticleDiscount::where('provider_id')`, o sea solo a los que ya tienen uno).
     *
     * @test
     */
    public function todos_le_crea_los_descuentos_al_articulo_que_no_tenia_ninguno()
    {
        $this->set_preferencia(1);

        $provider = $this->proveedor_de_la_suite();
        $descuento = $this->descuento_del_proveedor($provider, 10, 'Acuerdo anual 2026');

        $article = $this->articulo_del_proveedor($provider, 'zz Sincro todos sin descuentos');

        $this->assertCount(0, $this->tagueados($article->id), 'Precondicion: arranca sin descuentos.');

        $this->sincronizar($provider, ['alcance' => 'todos'])->assertStatus(200);

        $vigentes = $this->tagueados($article->id);

        $this->assertCount(1, $vigentes, 'Tiene que quedar con el unico descuento de la ficha.');

        $vigente = $vigentes->first();

        $this->assertEqualsWithDelta(10, (float) $vigente->percentage, self::DELTA);

        $this->assertEquals(
            ArticleDiscount::ORIGEN_FICHA_PROVEEDOR,
            $vigente->origen,
            'El origen tiene que decir que lo puso la FICHA: es la columna con la que se decide '.
            'si una propagacion posterior puede rehacerlo.'
        );

        $this->assertEquals(
            $descuento->id,
            (int) $vigente->provider_discount_id,
            'Y tiene que quedar relacionado con el descuento puntual del proveedor del que salio '.
            '(punto 2 del pedido de Lucas).'
        );

        $this->assertEquals(
            'Acuerdo anual 2026',
            $vigente->nombre,
            'Con el nombre copiado, que es lo que se muestra en la ficha del articulo sin pagar un JOIN.'
        );

        $this->assertEqualsWithDelta(
            900,
            $this->costo_real($article->id),
            self::DELTA,
            'Y el costo real tiene que reflejar el 10% recien creado.'
        );
    }

    /* ==================================================================================
     * 2. LAS TRES ACCIONES SOBRE LOS DESCUENTOS DE COMPRA
     * ================================================================================== */

    /**
     * 🔴 SALTEAR (el default) no toca al articulo que tiene una bonificacion negociada en una
     * compra. Es el unico de los tres caminos que no destruye ni duplica nada.
     *
     * @test
     */
    public function todos_con_saltear_no_toca_el_articulo_con_descuentos_de_compra()
    {
        $this->set_preferencia(1);

        $provider = $this->proveedor_de_la_suite();
        $this->descuento_del_proveedor($provider, 10, 'Bonif ficha');

        $article = $this->articulo_del_proveedor($provider, 'zz Sincro saltear');

        $this->descuento_de_compra($article, $provider, 20);

        $de_la_compra = $this->tagueados($article->id)->first();

        $this->assertEqualsWithDelta(
            800,
            $this->costo_real($article->id),
            self::DELTA,
            'Precondicion: 1000 menos el 20% negociado en la compra.'
        );

        $this->sincronizar($provider, [
            'alcance'              => 'todos',
            'accion_sobre_compras' => 'saltear',
        ])->assertStatus(200);

        $vigentes = $this->tagueados($article->id);

        $this->assertCount(
            1,
            $vigentes,
            'Salteando, el articulo queda exactamente como estaba: ni se le agrega la ficha ni se le quita nada.'
        );

        $this->assertEquals($de_la_compra->id, $vigentes->first()->id, 'Y es la MISMA fila, no una recreada.');

        $this->assertEqualsWithDelta(20, (float) $vigentes->first()->percentage, self::DELTA);

        $this->assertEqualsWithDelta(
            800,
            $this->costo_real($article->id),
            self::DELTA,
            'Y el costo real no se movio un centavo.'
        );
    }

    /**
     * PISAR deja SOLO los descuentos de la ficha: la bonificacion de la compra se pierde (el
     * usuario lo eligio con el numero a la vista). El descuento MANUAL, en cambio, sobrevive: no
     * esta tagueado y no es de nadie mas para borrar.
     *
     * @test
     */
    public function todos_con_pisar_deja_solo_los_de_la_ficha_y_el_manual_sobrevive()
    {
        $this->set_preferencia(1);

        $provider = $this->proveedor_de_la_suite();
        $this->descuento_del_proveedor($provider, 10, 'Bonif ficha');

        $article = $this->articulo_del_proveedor($provider, 'zz Sincro pisar');

        $this->descuento_de_compra($article, $provider, 20);

        $manual = $this->descuento_manual($article, 5);

        $this->sincronizar($provider, [
            'alcance'              => 'todos',
            'accion_sobre_compras' => 'pisar',
        ])->assertStatus(200);

        $vigentes = $this->tagueados($article->id);

        $this->assertCount(
            1,
            $vigentes,
            'Pisando queda un solo descuento tagueado: el de la ficha. El de la compra se reemplazo.'
        );

        $this->assertEquals(ArticleDiscount::ORIGEN_FICHA_PROVEEDOR, $vigentes->first()->origen);

        $this->assertEqualsWithDelta(10, (float) $vigentes->first()->percentage, self::DELTA);

        $this->assertCount(
            0,
            $this->tagueados_de_origen($article->id, ArticleDiscount::ORIGEN_COMPRA),
            'La bonificacion de la compra ya no esta: es lo que el usuario acepto perder.'
        );

        /* 🔴 Y el manual sigue vivo: la opcion "pisar" habla de los tagueados, no de lo que cargo una persona. */
        $vivo = ArticleDiscount::find($manual->id);

        $this->assertNotNull($vivo, 'El descuento manual del usuario no se puede borrar nunca.');

        $this->assertEqualsWithDelta(5, (float) $vivo->percentage, self::DELTA, 'Ni cambiarle el porcentaje.');

        /* 1000 x 0,90 (ficha) x 0,95 (manual) = 855. El 20% de la compra ya no participa. */
        $this->assertEqualsWithDelta(
            855,
            $this->costo_real($article->id),
            self::DELTA,
            'El costo real combina el descuento de la ficha y el manual, y NADA de la compra.'
        );
    }

    /**
     * AGREGAR deja los dos grupos, en cascada — y es IDEMPOTENTE: correrlo dos veces da exactamente
     * lo mismo que correrlo una.
     *
     * ⚠️ La idempotencia es la parte que no se ve: si "agregar" apilara una copia mas de la ficha en
     * vez de rehacerla, cada click del boton bajaria el costo un escalon, sin error y sin aviso.
     *
     * @test
     */
    public function todos_con_agregar_deja_los_dos_grupos_y_correrlo_dos_veces_da_lo_mismo()
    {
        $this->set_preferencia(1);

        $provider = $this->proveedor_de_la_suite();
        $this->descuento_del_proveedor($provider, 10, 'Bonif ficha');

        $article = $this->articulo_del_proveedor($provider, 'zz Sincro agregar');

        $this->descuento_de_compra($article, $provider, 20);

        $this->sincronizar($provider, [
            'alcance'              => 'todos',
            'accion_sobre_compras' => 'agregar',
        ])->assertStatus(200);

        $this->assertCount(
            2,
            $this->tagueados($article->id),
            'Agregando, el articulo queda con los dos grupos: el de la compra y el de la ficha.'
        );

        $this->assertCount(1, $this->tagueados_de_origen($article->id, ArticleDiscount::ORIGEN_COMPRA));
        $this->assertCount(1, $this->tagueados_de_origen($article->id, ArticleDiscount::ORIGEN_FICHA_PROVEEDOR));

        /* 1000 x 0,80 x 0,90 = 720. Es la opcion que duplica, y el numero queda escrito. */
        $this->assertEqualsWithDelta(
            720,
            $this->costo_real($article->id),
            self::DELTA,
            'Los descuentos se aplican en CASCADA, no sumados: 1000 con 20% y 10% da 720, no 700.'
        );

        /* 🔴 Segunda corrida: tiene que dar exactamente lo mismo. */
        $this->sincronizar($provider, [
            'alcance'              => 'todos',
            'accion_sobre_compras' => 'agregar',
        ])->assertStatus(200);

        $this->assertCount(
            2,
            $this->tagueados($article->id),
            'Correr la sincronizacion dos veces no puede apilar una copia mas de la ficha.'
        );

        $this->assertEqualsWithDelta(
            720,
            $this->costo_real($article->id),
            self::DELTA,
            'Y el costo real tiene que quedar en el mismo numero: la operacion es idempotente.'
        );
    }

    /**
     * 🔴 El descuento MANUAL (`provider_id` null) no se toca en NINGUNO de los tres modos, ni con el
     * tilde de pisar los editados a mano prendido.
     *
     * Cada modo corre sobre su propio proveedor y su propio articulo: encadenarlos sobre el mismo
     * articulo dejaria que el segundo modo corriera sobre lo que dejo el primero, y el test no diria
     * nada sobre el tercero.
     *
     * @test
     */
    public function el_descuento_manual_no_se_toca_en_ninguno_de_los_tres_modos()
    {
        $modos = ['saltear', 'pisar', 'agregar'];

        foreach ($modos as $modo) {

            $this->set_preferencia(1);

            $provider = $this->proveedor_de_la_suite($modo);
            $this->descuento_del_proveedor($provider, 10, 'Bonif ficha');

            $article = $this->articulo_del_proveedor($provider, 'zz Sincro manual ' . $modo);

            $this->descuento_de_compra($article, $provider, 20);

            $manual = $this->descuento_manual($article, 5);

            $this->sincronizar($provider, [
                'alcance'               => 'todos',
                'accion_sobre_compras'  => $modo,
                'pisar_editados_a_mano' => true,
            ])->assertStatus(200);

            $vivo = ArticleDiscount::find($manual->id);

            $this->assertNotNull(
                $vivo,
                'Modo "' . $modo . '": el descuento manual del usuario no se puede borrar.'
            );

            $this->assertEqualsWithDelta(
                5,
                (float) $vivo->percentage,
                self::DELTA,
                'Modo "' . $modo . '": ni cambiarle el porcentaje.'
            );

            $this->assertNull(
                $vivo->provider_id,
                'Modo "' . $modo . '": ni taguearlo al proveedor, que lo pondria bajo el gobierno de la ficha.'
            );

            $this->assertEquals(
                ArticleDiscount::ORIGEN_MANUAL,
                $vivo->origen,
                'Modo "' . $modo . '": ni cambiarle el origen.'
            );
        }
    }

    /* ==================================================================================
     * 3. LA PREFERENCIA DE LA CUENTA
     * ================================================================================== */

    /**
     * 🔴 El camino nuevo NO consulta `aplicar_descuentos_proveedor_al_asignar` (decision de Lucas,
     * §2.1 del plan): es una accion explicita, sobre un proveedor puntual, con un modal que dice
     * cuantos articulos toca. Gatearla dejaria un boton mudo que devuelve 0 sin explicar que la
     * causa es un tilde en otra pantalla.
     *
     * La preferencia viene APAGADA en casi todos los comercios, asi que si alguien le pusiera el
     * gate, el boton no funcionaria para practicamente nadie.
     *
     * @test
     */
    public function la_preferencia_apagada_no_frena_el_boton_de_sincronizar()
    {
        $this->set_preferencia(0);

        $provider = $this->proveedor_de_la_suite();
        $this->descuento_del_proveedor($provider, 10, 'Bonif ficha');

        $article = $this->articulo_del_proveedor($provider, 'zz Sincro preferencia apagada');

        $this->sincronizar($provider, ['alcance' => 'todos'])->assertStatus(200);

        $vigentes = $this->tagueados($article->id);

        $this->assertCount(
            1,
            $vigentes,
            'Con la preferencia apagada el boton TIENE que funcionar igual: es una accion explicita.'
        );

        $this->assertEqualsWithDelta(10, (float) $vigentes->first()->percentage, self::DELTA);

        $this->assertEqualsWithDelta(900, $this->costo_real($article->id), self::DELTA);
    }

    /**
     * 🔴 La contracara, y es tan importante como la de arriba: al camino VIEJO no se le movio el
     * gate. `propagar_a_articulos()` sigue sin hacer nada con la preferencia apagada.
     *
     * Sin este test, "sacar el gate" para que ande el boton nuevo habria sido un cambio silencioso
     * que le mueve los costos a ~40 comercios que nunca pidieron descuentos copiados.
     *
     * @test
     */
    public function la_preferencia_apagada_sigue_frenando_la_propagacion_vieja()
    {
        $this->set_preferencia(0);

        $provider = $this->proveedor_de_la_suite();
        $descuento = $this->descuento_del_proveedor($provider, 10, 'Bonif ficha');

        $article = $this->articulo_del_proveedor($provider, 'zz Propagacion vieja apagada');

        $this->descuento_de_ficha($article, $provider, 10);

        $this->putJson('api/provider-discount/' . $descuento->id, [
            'percentage' => 15,
            'nombre'     => 'Bonif ficha',
        ])->assertStatus(200);

        $this->putJson('api/provider/' . $provider->id . '/propagar-descuentos', [
            'pisar_editados_a_mano' => true,
        ])->assertStatus(200);

        $vigentes = $this->tagueados($article->id);

        $this->assertCount(1, $vigentes);

        $this->assertEqualsWithDelta(
            10,
            (float) $vigentes->first()->percentage,
            self::DELTA,
            'La propagacion vieja sigue gateada por la preferencia: con la preferencia apagada no '.
            'puede tocar un solo descuento.'
        );

        $this->assertEqualsWithDelta(900, $this->costo_real($article->id), self::DELTA);
    }

    /* ==================================================================================
     * 4. EL PROVEEDOR SIN NADA QUE PROPAGAR
     * ================================================================================== */

    /**
     * 🔴 Un proveedor sin un solo porcentaje utilizable no destruye nada, y el preview lo dice
     * ANTES de que el usuario confirme.
     *
     * Sin la guarda, sincronizar "nada" seria borrar: se irian los descuentos tagueados que dejaron
     * las compras y el import, no se crearia ninguno, y un catalogo entero pasaria a costo bruto de
     * golpe. Y aca pesa mas que en el camino viejo, porque el modo "todos" alcanza mas articulos.
     *
     * La fila del proveedor EXISTE pero esta vacia (`percentage` null): el has_many del formulario
     * deja agregar una fila sin completarla, asi que una guarda que cuente FILAS no corta.
     *
     * @test
     */
    public function un_proveedor_sin_porcentajes_utilizables_no_destruye_nada()
    {
        $this->set_preferencia(1);

        $provider = $this->proveedor_de_la_suite();

        /* Fila creada y nunca completada: existe, pero no tiene porcentaje. */
        $this->descuento_del_proveedor($provider, null, 'Fila sin completar');

        $article = $this->articulo_del_proveedor($provider, 'zz Sincro ficha vacia');

        $this->descuento_de_compra($article, $provider, 20);

        $de_la_compra = $this->tagueados($article->id)->first();

        $preview = $this->preview($provider);

        $this->assertFalse(
            $preview['hay_descuentos_en_la_ficha'],
            'El preview tiene que decir que no hay con que sincronizar: una fila vacia no es un descuento.'
        );

        $this->sincronizar($provider, [
            'alcance'               => 'todos',
            'accion_sobre_compras'  => 'pisar',
            'pisar_editados_a_mano' => true,
        ])->assertStatus(200);

        $vigentes = $this->tagueados($article->id);

        $this->assertCount(
            1,
            $vigentes,
            'Ni con "pisar" ni con el tilde: sin porcentajes en la ficha no hay nada que rehacer, y '.
            'menos que borrar.'
        );

        $this->assertEquals($de_la_compra->id, $vigentes->first()->id, 'Es la misma fila de la compra.');

        $this->assertEqualsWithDelta(
            800,
            $this->costo_real($article->id),
            self::DELTA,
            'Y el costo real no puede saltar al bruto.'
        );
    }

    /* ==================================================================================
     * 5. EL NOMBRE: RENOMBRE Y BORRADO
     * ================================================================================== */

    /**
     * Renombrar un descuento del proveedor le cambia el nombre a los `article_discounts` que
     * salieron de EL, y no toca los que salieron de otro descuento del mismo proveedor.
     *
     * 🔴 La segunda mitad es la que importa: el UPDATE masivo filtra por `provider_discount_id`. Si
     * filtrara por `provider_id` —que es el error natural, porque es la columna que ya existia—,
     * renombrar una bonificacion le pondria ese nombre a TODAS las del proveedor.
     *
     * @test
     */
    public function renombrar_un_descuento_del_proveedor_solo_renombra_los_suyos()
    {
        $this->set_preferencia(1);

        $provider = $this->proveedor_de_la_suite();

        $descuento_a = $this->descuento_del_proveedor($provider, 10, 'Bonif A');
        $descuento_b = $this->descuento_del_proveedor($provider, 5, 'Bonif B');

        $article = $this->articulo_del_proveedor($provider, 'zz Sincro renombre');

        $this->sincronizar($provider, ['alcance' => 'todos'])->assertStatus(200);

        $this->assertCount(2, $this->tagueados($article->id), 'Precondicion: el articulo tiene las dos bonificaciones.');

        $del_a = ArticleDiscount::where('provider_discount_id', $descuento_a->id)->get();
        $del_b = ArticleDiscount::where('provider_discount_id', $descuento_b->id)->get();

        $this->assertCount(1, $del_a, 'Precondicion: uno salio de la bonificacion A.');
        $this->assertCount(1, $del_b, 'Precondicion: el otro, de la B.');
        $this->assertEquals('Bonif A', $del_a->first()->nombre);
        $this->assertEquals('Bonif B', $del_b->first()->nombre);

        /* El comercio renombra la A. */
        $this->putJson('api/provider-discount/' . $descuento_a->id, [
            'percentage' => 10,
            'nombre'     => 'Acuerdo anual 2027',
        ])->assertStatus(200);

        $this->assertEquals(
            'Acuerdo anual 2027',
            ArticleDiscount::find($del_a->first()->id)->nombre,
            'El articulo tiene que mostrar el nombre NUEVO (decision de Lucas: siempre el actual).'
        );

        $this->assertEquals(
            'Bonif B',
            ArticleDiscount::find($del_b->first()->id)->nombre,
            'Y el descuento que salio de OTRA bonificacion del mismo proveedor no se puede tocar.'
        );
    }

    /**
     * Borrar un descuento del proveedor deja al articulo con el nombre que tenia (es una foto y
     * sobrevive) y con `provider_discount_id` apuntando a nada — que es exactamente el dato
     * correcto: "esto vino de un descuento que ya no existe".
     *
     * Y la ficha del articulo tiene que seguir abriendo: si algo hiciera un JOIN asumiendo que la
     * relacion existe, esta es la fila que lo rompe.
     *
     * @test
     */
    public function borrar_un_descuento_del_proveedor_deja_el_nombre_y_no_rompe_la_ficha()
    {
        $this->set_preferencia(1);

        $provider = $this->proveedor_de_la_suite();
        $descuento = $this->descuento_del_proveedor($provider, 10, 'Bonif que se borra');

        $article = $this->articulo_del_proveedor($provider, 'zz Sincro borrado');

        $this->sincronizar($provider, ['alcance' => 'todos'])->assertStatus(200);

        $del_articulo = $this->tagueados($article->id)->first();

        $this->assertEquals($descuento->id, (int) $del_articulo->provider_discount_id, 'Precondicion.');

        $this->deleteJson('api/provider-discount/' . $descuento->id)->assertStatus(200);

        $this->assertNull(ProviderDiscount::find($descuento->id), 'Precondicion: el descuento del proveedor ya no existe.');

        $huerfano = ArticleDiscount::find($del_articulo->id);

        $this->assertNotNull($huerfano, 'El descuento del articulo no se borra en cascada.');

        $this->assertEquals(
            'Bonif que se borra',
            $huerfano->nombre,
            'El nombre es una copia y sobrevive al borrado del descuento del proveedor.'
        );

        $this->assertEquals(
            $descuento->id,
            (int) $huerfano->provider_discount_id,
            'Y la relacion queda huerfana a proposito: dice de donde salio, aunque ya no exista.'
        );

        $this->assertNull(
            $huerfano->provider_discount,
            'La relacion resuelta tiene que dar null, no explotar.'
        );

        $this->getJson('api/article/' . $article->id)
                ->assertStatus(200, 'La ficha del articulo tiene que seguir abriendo con el descuento huerfano adentro.');
    }

    /* ==================================================================================
     * 6. MONTO O PORCENTAJE, NUNCA LOS DOS
     * ================================================================================== */

    /**
     * El alta de un descuento con monto Y porcentaje se rechaza con 422 y no crea nada.
     *
     * El guard va en la API y no solo en la SPA: los dos repos se despliegan por separado y nunca
     * llegan juntos a produccion, y ademas cualquier cliente HTTP puede postear las dos columnas.
     *
     * @test
     */
    public function el_alta_de_un_descuento_con_monto_y_porcentaje_se_rechaza()
    {
        $provider = $this->proveedor_de_la_suite();
        $article = $this->articulo_del_proveedor($provider, 'zz Alta descuento conflictiva');

        $antes = ArticleDiscount::where('article_id', $article->id)->count();

        $this->postJson('api/article-discount', [
            'model_id'       => $article->id,
            'percentage'     => 10,
            'amount'         => 500,
            'show_in_online' => 0,
        ])->assertStatus(422);

        $this->assertEquals(
            $antes,
            ArticleDiscount::where('article_id', $article->id)->count(),
            'Una alta rechazada no puede dejar la fila creada igual.'
        );
    }

    /**
     * Limpiar el porcentaje y dejar solo el monto tiene que poder guardarse.
     *
     * ⚠️ MEDIDO, no asumido: la cadena vacia que manda la SPA NUNCA llega asi al controller. El
     * middleware global `ConvertEmptyStringsToNull` (`app/Http/Kernel.php:23`) la convierte en null
     * antes, y la rama del guard que se ejecuta es la de `is_null()`. Verificado por mutacion:
     * revertir la rama del string vacio deja este test en VERDE, y revertir la de null lo pone en
     * rojo. O sea que el guard esta bien, pero la razon escrita en su docblock —"se pregunta por el
     * VACIO y no por el null"— no es la que lo sostiene en esta aplicacion.
     *
     * @test
     */
    public function el_alta_con_el_porcentaje_vacio_y_el_monto_cargado_se_acepta()
    {
        $provider = $this->proveedor_de_la_suite();
        $article = $this->articulo_del_proveedor($provider, 'zz Alta descuento porcentaje vacio');

        $response = $this->postJson('api/article-discount', [
            'model_id'       => $article->id,
            'percentage'     => '',
            'amount'         => 500,
            'show_in_online' => 0,
        ]);

        $response->assertStatus(
            201,
            'Un porcentaje vacio no entra en conflicto con el monto: la cadena vacia es "sin cargar".'
        );

        $creado = ArticleDiscount::find($response->json('model.id'));

        $this->assertNotNull($creado);

        $this->assertEqualsWithDelta(500, (float) $creado->amount, self::DELTA, 'Y el monto se guardo.');
    }

    /**
     * El `0` cuenta como VACIO, a proposito: un descuento de 0% no descuenta nada, asi que no tiene
     * por que chocar con el monto. Asi se decidio y asi queda fijado — si mañana alguien decide lo
     * contrario, que sea cambiando este test y no por accidente.
     *
     * @test
     */
    public function el_alta_con_porcentaje_cero_y_el_monto_cargado_se_acepta()
    {
        $provider = $this->proveedor_de_la_suite();
        $article = $this->articulo_del_proveedor($provider, 'zz Alta descuento porcentaje cero');

        $response = $this->postJson('api/article-discount', [
            'model_id'       => $article->id,
            'percentage'     => 0,
            'amount'         => 500,
            'show_in_online' => 0,
        ]);

        $response->assertStatus(201, 'Un 0% no descuenta nada: no es un valor "cargado" que choque con el monto.');

        $creado = ArticleDiscount::find($response->json('model.id'));

        $this->assertNotNull($creado);

        $this->assertEqualsWithDelta(500, (float) $creado->amount, self::DELTA);
    }

    /**
     * El mismo guard rige para los RECARGOS: el pedido de Lucas los nombra en el punto 5.
     *
     * @test
     */
    public function el_alta_de_un_recargo_con_monto_y_porcentaje_se_rechaza()
    {
        $provider = $this->proveedor_de_la_suite();
        $article = $this->articulo_del_proveedor($provider, 'zz Alta recargo conflictiva');

        $antes = ArticleSurchage::where('article_id', $article->id)->count();

        $this->postJson('api/article-surchage', [
            'model_id'   => $article->id,
            'percentage' => 10,
            'amount'     => 500,
        ])->assertStatus(422);

        $this->assertEquals(
            $antes,
            ArticleSurchage::where('article_id', $article->id)->count(),
            'Un recargo rechazado tampoco puede quedar creado.'
        );
    }

    /**
     * 🔴 UNA FILA VIEJA CON LOS DOS CAMPOS SE SIGUE PUDIENDO GUARDAR.
     *
     * `aplicar_descuentos()` viene aceptando filas con porcentaje Y monto desde siempre (las deja
     * una compra cuyo formulario expone los dos campos sin exclusividad), asi que hay comercios que
     * ya las tienen. Rechazar todo request que traiga los dos convertia un guardado inocente —abrir
     * un descuento viejo, no tocar nada, apretar Guardar— en un error sobre un dato que el sistema
     * mismo dejo entrar.
     *
     * @test
     */
    public function una_fila_vieja_con_los_dos_campos_se_puede_volver_a_guardar()
    {
        $provider = $this->proveedor_de_la_suite();
        $article = $this->articulo_del_proveedor($provider, 'zz Fila legada');

        /* Lo que deja una compra: porcentaje Y monto en la misma fila. */
        $this->descuento_de_compra($article, $provider, 10, 500);

        $legada = $this->tagueados($article->id)->first();

        $this->assertEqualsWithDelta(10, (float) $legada->percentage, self::DELTA, 'Precondicion.');
        $this->assertEqualsWithDelta(500, (float) $legada->amount, self::DELTA, 'Precondicion.');

        $this->putJson('api/article-discount/' . $legada->id, [
            'percentage'     => 10,
            'amount'         => 500,
            'show_in_online' => 0,
        ])->assertStatus(
            200,
            'Guardar una fila legada sin cambiarle ninguno de los dos valores no puede fallar: el '.
            'usuario no hizo nada malo.'
        );
    }

    /**
     * Pero editar ESA MISMA fila dejandola en conflicto si se rechaza: eso ya es editar el
     * conflicto, que es lo que la regla viene a impedir.
     *
     * @test
     */
    public function editar_la_fila_vieja_dejandola_en_conflicto_se_rechaza()
    {
        $provider = $this->proveedor_de_la_suite();
        $article = $this->articulo_del_proveedor($provider, 'zz Fila legada editada');

        $this->descuento_de_compra($article, $provider, 10, 500);

        $legada = $this->tagueados($article->id)->first();

        $this->putJson('api/article-discount/' . $legada->id, [
            'percentage'     => 15,
            'amount'         => 500,
            'show_in_online' => 0,
        ])->assertStatus(422);

        $sin_tocar = ArticleDiscount::find($legada->id);

        $this->assertEqualsWithDelta(
            10,
            (float) $sin_tocar->percentage,
            self::DELTA,
            'Un request rechazado no puede dejar la mitad del cambio aplicada.'
        );

        $this->assertEqualsWithDelta(500, (float) $sin_tocar->amount, self::DELTA);

        $this->assertEquals(
            0,
            (int) $sin_tocar->editado_a_mano,
            'Y ni siquiera puede llegar a marcar la fila como editada a mano: la guarda va primero.'
        );
    }

    /* ==================================================================================
     * 7. EL PREVIEW Y LOS GUARDS DEL ENDPOINT
     * ================================================================================== */

    /**
     * El preview reparte los articulos en grupos EXCLUYENTES que suman el total, y un articulo con
     * descuentos de la ficha Y de una compra cae en `con_descuentos_de_compra`.
     *
     * 🔴 Ese ultimo punto es una diferencia deliberada con `propagar_a_articulos()`, que mira solo
     * las filas de la ficha y lo actualizaria. Aca su destino lo decide el usuario, y es lo que hace
     * que el numero del modal signifique algo: esta eligiendo sobre los articulos que tienen datos
     * que no se pueden reconstruir.
     *
     * @test
     */
    public function el_preview_reparte_en_grupos_excluyentes_que_suman_el_total()
    {
        $this->set_preferencia(1);

        $provider = $this->proveedor_de_la_suite();
        $this->descuento_del_proveedor($provider, 15, 'Bonif ficha');

        $sin      = $this->articulo_del_proveedor($provider, 'zz Preview sincro sin');
        $al_dia   = $this->articulo_del_proveedor($provider, 'zz Preview sincro al dia');
        $desact   = $this->articulo_del_proveedor($provider, 'zz Preview sincro desactualizado');
        $editado  = $this->articulo_del_proveedor($provider, 'zz Preview sincro editado');
        $mixto    = $this->articulo_del_proveedor($provider, 'zz Preview sincro mixto');

        $this->descuento_de_ficha($al_dia, $provider, 15);
        $this->descuento_de_ficha($desact, $provider, 10);
        $this->descuento_de_ficha($editado, $provider, 40, true);

        /* El mixto tiene las DOS cosas: lo de la ficha y la bonificacion de una compra. */
        $this->descuento_de_ficha($mixto, $provider, 15);
        $this->descuento_de_compra($mixto, $provider, 20);

        $preview = $this->preview($provider);

        $this->assertTrue($preview['hay_descuentos_en_la_ficha']);

        $this->assertEquals(5, $preview['total_articulos'], 'Los cinco articulos del proveedor.');

        $this->assertEquals(1, $preview['sin_descuentos'], 'El que no tiene ningun descuento tagueado.');
        $this->assertEquals(1, $preview['al_dia'], 'El que ya tiene el porcentaje de hoy.');
        $this->assertEquals(1, $preview['desactualizados'], 'El que tiene la copia vieja.');
        $this->assertEquals(1, $preview['editados_a_mano'], 'El que una persona edito.');

        $this->assertEquals(
            1,
            $preview['con_descuentos_de_compra'],
            'El que tiene descuentos de la ficha Y de una compra cae ACA, no entre los "al dia": su '.
            'destino lo decide el usuario en el modal.'
        );

        $suma = $preview['sin_descuentos']
            + $preview['al_dia']
            + $preview['desactualizados']
            + $preview['editados_a_mano']
            + $preview['con_descuentos_de_compra'];

        $this->assertEquals(
            $preview['total_articulos'],
            $suma,
            'Los grupos son excluyentes: tienen que sumar el total, o el modal esta contando dos '.
            'veces al mismo articulo o dejando alguno afuera.'
        );

        /* Y el que no tiene nada existe de verdad, no es un hueco del fixture. */
        $this->assertCount(0, $this->tagueados($sin->id));
    }

    /**
     * 🔴 Un `alcance` o una `accion_sobre_compras` fuera de la lista blanca se rechazan con 422.
     *
     * Aceptar cualquier string dejaria que un valor mal escrito —o una SPA vieja mandando otro—
     * cayera en silencio en una rama que nadie eligio. Y una de esas ramas borra bonificaciones de
     * compras.
     *
     * @test
     */
    public function un_alcance_o_una_accion_fuera_de_la_lista_blanca_se_rechazan()
    {
        $provider = $this->proveedor_de_la_suite();
        $this->descuento_del_proveedor($provider, 10, 'Bonif ficha');

        $this->sincronizar($provider, ['alcance' => 'todo'])
                ->assertStatus(422, 'Un alcance que no existe no puede caer en una rama por default.');

        $this->sincronizar($provider, ['accion_sobre_compras' => 'borrar'])
                ->assertStatus(422, 'Ni una accion sobre compras que no existe.');

        /* Y la clave AUSENTE no es un valor invalido: se cae al default conservador. */
        $this->sincronizar($provider, [])
                ->assertStatus(200, 'Sin las claves, el endpoint tiene que caer al default seguro, no fallar.');
    }

    /**
     * 🔴 Las tres rutas nuevas contestan 404 con un proveedor de OTRA cuenta.
     *
     * No es prolijidad: sin el scope, un id ajeno dejaria sincronizarle el catalogo a otro comercio
     * —o sea moverle los costos— y exportarle la lista de sus articulos.
     *
     * @test
     */
    public function las_tres_rutas_rechazan_un_proveedor_de_otra_cuenta()
    {
        $ajeno = $this->proveedor_de_otra_cuenta();

        $this->getJson('api/provider/' . $ajeno->id . '/sincronizar-descuentos/preview')
                ->assertStatus(404, 'El preview no puede contar los articulos de otro comercio.');

        $this->putJson('api/provider/' . $ajeno->id . '/sincronizar-descuentos', ['alcance' => 'todos'])
                ->assertStatus(404, 'Y menos sincronizarle el catalogo.');

        $this->getJson('api/provider/' . $ajeno->id . '/sincronizar-descuentos/exportar-conflictos')
                ->assertStatus(404, 'Ni exportarle la lista de sus articulos.');
    }

    /**
     * Proveedor de una cuenta distinta a la del fixture. Mismo criterio que
     * `DescripcionDePrecioTest::articulo_de_otra_cuenta()`.
     *
     * @return \App\Models\Provider
     */
    private function proveedor_de_otra_cuenta()
    {
        $owner = $this->owner();

        $otro_user = User::where('id', '!=', $owner->id)
                            ->where('owner_id', null)
                            ->first();

        if (is_null($otro_user)) {

            $otro_user = User::create([
                'name'       => 'Cuenta ajena de prueba',
                'email'      => 'ajeno-sincro-' . time() . '@testing.local',
                'doc_number' => '99999999',
                'password'   => bcrypt('1234'),
            ]);
        }

        return Provider::create([
            'name'    => 'zz Proveedor de otra cuenta',
            'user_id' => $otro_user->id,
        ]);
    }
}
