<?php

namespace Tests\Feature\Precios;

use App\Http\Controllers\Helpers\article\ArticlePricesHelper;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Jobs\ProcessChunkSetFinalPrices;
use App\Models\Article;
use App\Models\PriceChange;
use App\Models\PriceUpdateRun;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Un recalculo de precios que no cambio ningun precio no tiene que registrar ningun cambio.
 *
 * El defecto que estos tests protegen: `articles.final_price` es DECIMAL(22,2), pero la cadena de
 * calculo deja en memoria un float con la cola completa -- el IIBB se aplica por DIVISION
 * (ArticlePricesHelper::aplicar_sale_taxes), y 150 / 0,965 da 155,44041450777202. Se guardaban 2
 * decimales y se comparaban 14, asi que la comparacion de ArticleHelper::setFinalPrice() daba
 * "cambio" en CADA corrida aunque nadie hubiera tocado nada: un price_change espurio por articulo
 * por corrida, `previus_final_price` y `final_price_updated_at` pisados siempre, y el modal
 * "Precios actualizados: N" contando el catalogo entero.
 *
 * DatabaseTransactions (NUNCA RefreshDatabase): la base de testing del slot esta sembrada de antes
 * y un refresh la vaciaria.
 */
class RecalculoNoInflaPreciosCambiadosTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * El reloj y el cache estatico de sale_taxes viven en el PROCESO, no en la base: la
     * transaccion los deja tal cual y se los llevaria puestos el test siguiente.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        ArticlePricesHelper::$sale_taxes_cache = [];

        parent::tearDown();
    }

    /**
     * @return \App\Models\User
     */
    protected function autenticar()
    {
        $user = User::find(500);

        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $this->actingAs($user, 'web');

        return $user;
    }

    /**
     * Articulo con costo y margen, para que setFinalPrice tenga algo que calcular.
     *
     * 🔴 Se devuelve RECARGADO de la base, no el modelo que devuelve create(). Eloquent no
     * hidrata los defaults que pone MySQL, asi que el modelo recien creado tiene `aplicar_iva`
     * en null (la columna es NOT NULL DEFAULT 1) y una primera pasada sobre esa instancia
     * calcularia el precio SIN IVA -- 155,44 en vez de 188,08. Medido en este slot. Eso no es
     * el bug que estos tests persiguen: es una diferencia de montaje que haria fallar el test
     * por el motivo equivocado. Produccion lee los articulos de la base
     * (ProcessChunkSetFinalPrices::handle()), asi que el test tambien.
     *
     * @param  \App\Models\User $user
     * @param  string $nombre
     * @return \App\Models\Article
     */
    protected function crear_articulo($user, $nombre)
    {
        $article = Article::create([
            'name'            => $nombre,
            'user_id'         => $user->id,
            'cost'            => 100,
            'percentage_gain' => 50,
        ]);

        return Article::find($article->id);
    }

    /**
     * Corrida abierta, tal como la deja el productor (mismo armado que
     * RecalculoPreciosNotificacionTest::crear_corrida_abierta()).
     *
     * @param  int $user_id
     * @return \App\Models\PriceUpdateRun
     */
    protected function crear_corrida_abierta($user_id)
    {
        return PriceUpdateRun::create([
            'user_id'          => $user_id,
            'origen'           => 'dolar',
            'status'           => 'en_proceso',
            'total_chunks'     => 1,
            'processed_chunks' => 0,
            'chunks_encolados' => 1,
            'articles_updated' => 0,
            'started_at'       => Carbon::now(),
        ]);
    }

    /**
     * 🔴 Precondicion del escenario, no decoracion.
     *
     * Estos tests solo prueban algo si la cuenta produce un precio que NO cae exacto en 2
     * decimales. Hoy lo garantiza el SaleTax de IIBB (3,5%) que trae la semilla, que se aplica por
     * division. Si mañana alguien lo saca de la base, los tests quedarian verdes SIN PROBAR NADA:
     * memoria y base coincidirian solas y la comparacion no tendria como fallar.
     *
     * Por eso se mide la cola de verdad -- reproduciendo la division sobre la misma base que usa
     * el articulo del test (costo 100 + margen del 50%) -- y se falla ruidosamente si desaparecio.
     *
     * @param  \App\Models\Article $article
     * @param  \App\Models\User $user
     * @return void
     */
    protected function precondicion_cola_de_decimales($article, $user)
    {
        $base = 150.0;

        $res = ArticlePricesHelper::aplicar_sale_taxes($article, $base, $user, []);
        $con_cola = $res['price'];

        $this->assertTrue(
            abs($con_cola - round($con_cola, 2)) > 0.0000001,
            'La cuenta de testing ya no produce un precio con cola de decimales (base '.$base.' quedo en '
            .$con_cola.'). Sin cola, estos tests quedan verdes sin probar nada: revisa si se saco el '
            .'SaleTax de IIBB del usuario 500 de la semilla.'
        );
    }

    /**
     * Criterio: recalcular dos veces seguidas, sin que nadie toque el articulo, no puede registrar
     * ningun cambio de precio.
     *
     * 🔴 El modelo se RECARGA de la base entre las dos pasadas a proposito. Sin recargarlo el test
     * pasa incluso con el bug: la segunda pasada leeria $current_final_price del float sucio que
     * dejo la primera en la misma instancia, y coincidiria consigo mismo. Produccion no hace eso --
     * ProcessChunkSetFinalPrices trae los articulos de la base en cada corrida.
     *
     * 🔴 Y el reloj se ADELANTA una hora entre pasada y pasada. Sin eso la asercion sobre
     * final_price_updated_at es decorativa: las dos pasadas caerian en el mismo segundo y la fecha
     * coincidiria aunque se hubiera pisado.
     *
     * @group precios
     * @test
     */
    public function el_segundo_recalculo_no_registra_un_cambio_de_precio_que_no_existio()
    {
        $user = $this->autenticar();

        $article = $this->crear_articulo($user, 'zz Articulo recalculo estable');

        $this->precondicion_cola_de_decimales($article, $user);

        Carbon::setTestNow('2026-09-02 10:00:00');

        ArticleHelper::setFinalPrice($article, $user->id, $user);

        $despues_de_la_primera = Article::find($article->id);

        $cambios_antes = PriceChange::where('article_id', $article->id)->count();
        $fecha_antes   = $despues_de_la_primera->getRawOriginal('final_price_updated_at');
        $precio_antes  = $despues_de_la_primera->getRawOriginal('final_price');

        $this->assertNotNull(
            $precio_antes,
            'La primera pasada no dejo ningun precio final guardado: el escenario no existe.'
        );

        Carbon::setTestNow('2026-09-02 11:00:00');

        ArticleHelper::setFinalPrice($despues_de_la_primera, $user->id, $user);

        $despues_de_la_segunda = Article::find($article->id);

        $this->assertEquals(
            $cambios_antes,
            PriceChange::where('article_id', $article->id)->count(),
            'El segundo recalculo registro un price_change de un cambio de precio que no existio.'
        );

        $this->assertEquals(
            $fecha_antes,
            $despues_de_la_segunda->getRawOriginal('final_price_updated_at'),
            'El segundo recalculo piso final_price_updated_at sin que el precio cambiara.'
        );

        $this->assertEquals(
            $precio_antes,
            $despues_de_la_segunda->getRawOriginal('final_price'),
            'El precio final guardado no es estable entre dos recalculos seguidos.'
        );
    }

    /**
     * Criterio: una corrida completa sobre precios que no cambiaron no registra ningun articulo, y
     * el modal termina diciendo cero.
     *
     * 🔴 La contraprueba del final no es de mas: sin ella, un "arreglo" que rompiera la deteccion
     * por completo -- no registrar nunca nada -- dejaria este test verde. Se pisa el precio a mano
     * y se exige que ESA corrida SI registre el articulo.
     *
     * @group precios
     * @test
     */
    public function una_corrida_sobre_precios_que_no_cambiaron_no_registra_ningun_articulo()
    {
        $user = $this->autenticar();

        $article = $this->crear_articulo($user, 'zz Articulo corrida sin cambios');

        $this->precondicion_cola_de_decimales($article, $user);

        Notification::fake();

        /*
         * Pasada de calentamiento SIN run_id: no registra nada ni toca contadores, solo deja el
         * final_price calculado. Desde aca, correr el job de vuelta no deberia mover nada.
         */
        $calentamiento = new ProcessChunkSetFinalPrices([$article->id], $user->id);
        $calentamiento->handle();

        $run = $this->crear_corrida_abierta($user->id);

        $chunk = new ProcessChunkSetFinalPrices([$article->id], $user->id, $run->id);
        $chunk->handle();

        $registrados = DB::table('price_update_run_articles')
            ->where('price_update_run_id', $run->id)
            ->count();

        $this->assertEquals(
            0,
            $registrados,
            'La corrida registro un articulo que no cambio de precio: el modal informaria un numero inflado.'
        );

        /*
         * Y el chunk tiene que haber terminado de verdad: un handle() que explotara antes de
         * registrar dejaria 0 filas y este test pasaria por el motivo equivocado.
         */
        $run->refresh();
        $this->assertEquals(
            1,
            (int) $run->processed_chunks,
            'El chunk no se marco como procesado: las 0 filas de arriba no prueban nada.'
        );

        /* Contraprueba: con el precio pisado a mano, la MISMA maquinaria si tiene que registrar. */
        Article::where('id', $article->id)->update(['final_price' => 1]);

        $run_con_cambio = $this->crear_corrida_abierta($user->id);

        $chunk_con_cambio = new ProcessChunkSetFinalPrices([$article->id], $user->id, $run_con_cambio->id);
        $chunk_con_cambio->handle();

        $this->assertEquals(
            1,
            DB::table('price_update_run_articles')->where('price_update_run_id', $run_con_cambio->id)->count(),
            'La corrida no registro un articulo cuyo precio SI cambio: se rompio la deteccion entera.'
        );
    }

    /**
     * Criterio: un articulo sin costo y sin precio conserva su final_price en NULL.
     *
     * 🔴 Este test NO protege contra el bug original: protege contra la regresion que el arreglo
     * podria introducir. round(null, 2) devuelve 0.0 en PHP 7.4, asi que cuantizar el precio final
     * sin una guarda de is_null convertiria un articulo "sin precio" (NULL) en uno "gratis" (0.00)
     * -- y nadie se enteraria, porque la comparacion de setFinalPrice() no lo denuncia: en PHP
     * null == 0 es verdadero.
     *
     * El montaje es lo que hace que el null LLEGUE hasta el redondeo; cada paso va comentado con
     * la rama del pipeline que habilita.
     *
     * 🔴 Y ese montaje es estrecho a proposito, no por comodidad: medido, en la configuracion
     * NORMAL de una cuenta -- con IIBB, o con el IVA del articulo, o con cualquier modo de
     * redondeo prendido -- el null muere aguas arriba y el articulo termina en 0.00 igual, con
     * guarda y todo. Eso es un defecto propio, PREEXISTENTE y distinto del que arregla el
     * redondeo: un articulo sin costo ni precio queda en 0.00 y nada lo registra como cambio,
     * porque null != 0.0 da false. Esta declarado en
     * informes/20260902-recalculo-no-infla-precios-cambiados.md y NO lo cubre este test.
     *
     * @group precios
     * @test
     */
    public function el_articulo_sin_costo_ni_precio_conserva_el_final_price_en_null()
    {
        $user = $this->autenticar();

        /*
         * 1) Listas de precio prendidas. Es lo unico que saltea el early-return del principio de
         *    setFinalPrice(), que para una cuenta comun devuelve antes de calcular nada cuando el
         *    articulo no tiene ni cost ni price. UserHelper::uses_listas_de_precio() lo resuelve
         *    leyendo la columna users.listas_de_precio del dueño (no una extension).
         */
        $user->listas_de_precio = 1;

        /*
         * 1-bis) Y sin ningun modo de redondeo prendido. 4 de los 5 aplastan el null antes de que
         *    llegue al redondeo a 2 decimales -- round(null, -1), ceil(null / 50) * 50,
         *    round(null / 1000) * 1000, round(null) dan todos 0.0; el unico que lo deja pasar es
         *    el de centenas, por su `if ($price > 100)`.
         *
         *    🔴 Se apagan EXPLICITAMENTE aunque el usuario 500 ya los tenga en 0. Si el test se
         *    apoyara en la configuracion de la semilla, el dia que alguien prenda uno de estos
         *    flags este test se pondria rojo y pareceria una regresion del redondeo, cuando en
         *    realidad seria el montaje que dejo de armar el escenario que dice armar.
         */
        $user->redondear_miles_en_vender     = 0;
        $user->redondear_centenas_en_vender  = 0;
        $user->redondear_precios_en_decenas  = 0;
        $user->redondear_de_a_50             = 0;
        $user->redondear_precios_en_centavos = 0;

        $user->save();

        /*
         * 2) Sin sale_taxes activos. Se aplican por DIVISION (null / 0,965 = 0.0), asi que con el
         *    IIBB prendido el null moriria mucho antes del redondeo y este test no probaria nada.
         *    El cache estatico ArticlePricesHelper::$sale_taxes_cache se llena una vez por proceso:
         *    sin vaciarlo, el UPDATE de arriba no tiene ningun efecto sobre este test.
         */
        DB::table('sale_taxes')->where('user_id', $user->id)->update(['activo' => 0]);
        ArticlePricesHelper::$sale_taxes_cache = [];

        /*
         * 3) Articulo sin costo, sin precio y sin margen propio: no hay nada de donde salga un
         *    numero. Y con aplicar_iva en 0 (la columna existe y su default es 1) el bloque de IVA
         *    de venta tampoco lo convierte: null + null * 21 / 100 daria 0.0.
         */
        $article = Article::create([
            'name'            => 'zz Articulo sin costo ni precio',
            'user_id'         => $user->id,
            'cost'            => null,
            'price'           => null,
            'percentage_gain' => null,
            'aplicar_iva'     => 0,
        ]);

        /* Recargado de la base por el mismo motivo que crear_articulo(): los defaults que pone
           MySQL (iva_id, apply_provider_percentage_gain) no vienen hidratados en el modelo que
           devuelve create(), y produccion siempre trabaja con el articulo leido de la base. */
        $article = Article::find($article->id);

        ArticleHelper::setFinalPrice($article, $user->id, $user);

        $this->assertNull(
            Article::find($article->id)->getRawOriginal('final_price'),
            'Un articulo sin costo ni precio quedo con final_price en 0.00: paso de "no tiene precio" a '
            .'"es gratis" en silencio.'
        );
    }
}
