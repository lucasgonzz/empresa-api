<?php

namespace Tests\Feature\Sales;

use App\Models\Article;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Misión 55 — `previus-next-by-id/{model_name}/{id}`: abrir una venta guardada por su id.
 *
 * Antes, abrir una venta para editarla eran dos requests y ninguna usaba el id: se pedía la
 * POSICIÓN de la venta en el listado ordenado por id DESC y después la venta de esa posición, con
 * un `withAll()->take($index)->get()` que en un comercio con 35 mil ventas hidrataba miles de
 * ventas completas para llegar a una sola.
 *
 * Lo que estos tests protegen, en orden de gravedad:
 *
 *  1. Que devuelva **esa** venta y no otra. Es la regresión que más caro sale: el endpoint viejo
 *     devolvía "la venta que está en la posición N", así que un error de traducción entregaba la
 *     venta equivocada y el usuario editaba la de otro cliente sin darse cuenta.
 *  2. Que traiga los precios del pivot. Sin ellos VENDER pinta todas las líneas en $0, que es el
 *     bug que reportó San Cayetano.
 *  3. Que no cruce comercios. El endpoint scopea por `user_id` y eso tiene que valer también
 *     cuando el id existe pero es de otro.
 *
 * DatabaseTransactions y no RefreshDatabase: la base de testing del slot está sembrada de antes y
 * un refresh la vaciaría, rompiendo el resto de las suites.
 */
class Abrir_venta_por_id_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * El comercio de las pruebas.
     *
     * @var int
     */
    const USER_ID = 500;

    /**
     * Otro comercio, para el caso de la venta ajena.
     *
     * Se clona el usuario de pruebas en vez de inventar un id suelto: `sales.user_id` tiene
     * foreign key contra `users`, así que un id que no existe no llega ni a insertarse y el test
     * fallaría por la FK sin haber medido nunca el scope del endpoint.
     *
     * @return \App\Models\User
     */
    protected function otro_comercio()
    {
        $otro = User::find(self::USER_ID)->replicate();
        $otro->email = 'zz_test_otro_comercio_mision_55@ejemplo.test';

        /*
         * `articles_export_key` es el único índice único de `users` además de la PK, y
         * `replicate()` lo copia. Hoy no choca porque en la base del slot está en null, pero el
         * día que se llene este test —el único que mide el scope por comercio— pasaría a fallar
         * por una violación de índice y no por lo que mide.
         */
        $otro->articles_export_key = null;
        $otro->save();

        return $otro;
    }

    /**
     * Crea una venta del comercio indicado.
     *
     * @param  int  $user_id
     * @param  array  $overrides
     * @return \App\Models\Sale
     */
    protected function crear_venta($user_id = self::USER_ID, $overrides = [])
    {
        return Sale::create(array_merge([
            'user_id'                    => $user_id,
            'client_id'                  => null,
            'omitir_en_cuenta_corriente' => 0,
            'save_current_acount'        => 0,
            'terminada'                  => 1,
            'is_cerrada'                 => 0,
            'caja_id'                    => null,
            'sub_total'                  => 250,
            'total'                      => 250,
        ], $overrides));
    }

    /**
     * Le engancha un artículo a la venta con un precio de pivot explícito.
     *
     * @param  \App\Models\Sale  $sale
     * @param  float  $price
     * @param  float  $amount
     * @return \App\Models\Article
     */
    protected function enganchar_articulo($sale, $price, $amount = 2)
    {
        $article = Article::where('user_id', self::USER_ID)->first();

        $this->assertNotNull($article, 'La base de testing no tiene ningún artículo del user 500.');

        DB::table('article_sale')->insert([
            'sale_id'    => $sale->id,
            'article_id' => $article->id,
            'amount'     => $amount,
            'price'      => $price,
            'cost'       => 10,
        ]);

        return $article;
    }

    /**
     * Autentica y pide la venta.
     *
     * @param  int  $sale_id
     * @return \Illuminate\Testing\TestResponse
     */
    protected function pedir_venta($sale_id)
    {
        $this->actingAs(User::find(self::USER_ID), 'web');

        return $this->get('api/previus-next-by-id/sale/' . $sale_id);
    }

    /**
     * Devuelve la venta pedida —esa y no otra— con el precio con el que se guardó cada línea.
     *
     * @group sales
     * @test
     * @return void
     */
    public function devuelve_la_venta_del_id_con_los_precios_del_pivot()
    {
        $venta = $this->crear_venta();
        $article = $this->enganchar_articulo($venta, 125.5);

        /*
         * Una segunda venta, más nueva, a propósito: con el endpoint viejo la venta que se traía
         * dependía de cuántas ventas hubiera por delante, así que este es el caso que distingue
         * "trae la venta del id" de "trae la que está en esa posición".
         */
        $mas_nueva = $this->crear_venta();

        $respuesta = $this->pedir_venta($venta->id);

        $respuesta->assertStatus(200);

        $modelo = $respuesta->json('model');

        $this->assertNotNull($modelo, 'El endpoint no devolvió la venta.');
        $this->assertEquals($venta->id, $modelo['id'], 'Devolvió otra venta: el id no coincide con el pedido.');
        $this->assertNotEquals($mas_nueva->id, $modelo['id']);

        $this->assertArrayHasKey('articles', $modelo, 'La venta viene sin articles: VENDER no tendría items para mostrar.');
        $this->assertCount(1, $modelo['articles']);

        $linea = $modelo['articles'][0];

        $this->assertEquals($article->id, $linea['id']);
        $this->assertArrayHasKey('pivot', $linea);
        $this->assertEquals(125.5, (float) $linea['pivot']['price'], 'El precio guardado no llega en el pivot: los items se pintan en $0.');
        $this->assertEquals(2, (float) $linea['pivot']['amount']);
    }

    /**
     * Un precio guardado en 0 tiene que llegar como 0, no como null ni ausente. Es la mitad
     * backend de la causa 2 del bug: del lado del front, un 0 falsy se caía al precio actual.
     *
     * @group sales
     * @test
     * @return void
     */
    public function un_precio_guardado_en_cero_llega_como_el_string_decimal_y_no_como_null()
    {
        $venta = $this->crear_venta();
        $this->enganchar_articulo($venta, 0);

        $linea = $this->pedir_venta($venta->id)->json('model.articles.0');

        $this->assertNotNull($linea);
        $this->assertNotNull($linea['pivot']['price'], 'El precio 0 llegó como null.');

        /*
         * assertSame sobre el string y no un cast a float: el TIPO es el dato. La columna es
         * decimal y PDO la devuelve como string, así que lo que llega al front es "0.00", que en
         * javascript es truthy. Eso importa porque la misión 55 daba por sentado lo contrario —
         * que un 0 falsy hacía caer la línea al precio actual del artículo—, y con un cast a
         * float esta aserción taparía justamente el dato que lo refuta. Si algún día el precio
         * empieza a llegar como número, este test se pone en rojo y avisa que la premisa cambió.
         */
        $this->assertSame('0.00', $linea['pivot']['price'], 'El precio del pivot dejó de llegar como string decimal.');
    }

    /**
     * La ruta exige autenticación. Es barato y es lo único que protege de que alguien la mueva
     * fuera del grupo de `auth:sanctum` sin darse cuenta: sin auth, devolvería ventas de un
     * comercio a cualquiera que sepa un id.
     *
     * @group sales
     * @test
     * @return void
     */
    public function la_ruta_exige_autenticacion()
    {
        $venta = $this->crear_venta();

        $respuesta = $this->getJson('api/previus-next-by-id/sale/' . $venta->id);

        $respuesta->assertStatus(401);
    }

    /**
     * La venta de otro comercio no se devuelve, aunque el id exista.
     *
     * @group sales
     * @test
     * @return void
     */
    public function no_devuelve_la_venta_de_otro_comercio()
    {
        $ajena = $this->crear_venta($this->otro_comercio()->id);

        $respuesta = $this->pedir_venta($ajena->id);

        $respuesta->assertStatus(200);
        $this->assertNull($respuesta->json('model'), 'Devolvió una venta de otro user_id.');
    }

    /**
     * Un id que no existe devuelve null, no un error.
     *
     * @group sales
     * @test
     * @return void
     */
    public function devuelve_null_para_una_venta_inexistente()
    {
        $inexistente = Sale::max('id') + 100000;

        $respuesta = $this->pedir_venta($inexistente);

        $respuesta->assertStatus(200);
        $this->assertNull($respuesta->json('model'));
    }

    /**
     * Deja `actualizandose_por_id` marcado, igual que el endpoint viejo: es lo que sostiene el
     * bloqueo de edición concurrente, y perderlo sería una regresión silenciosa.
     *
     * @group sales
     * @test
     * @return void
     */
    public function marca_actualizandose_por_id_igual_que_antes()
    {
        $venta = $this->crear_venta(self::USER_ID, ['actualizandose_por_id' => null]);

        /*
         * Se le pone una fecha vieja a mano. Si se dejara la de creación, el request corre en el
         * mismo segundo y las dos fechas darían iguales aunque Eloquent hubiera tocado la
         * columna: la aserción de abajo pasaría siempre, midiendo nada. Medido: con este update
         * la aserción se pone en rojo al sacar `timestamps = false`; sin él, no.
         */
        DB::table('sales')->where('id', $venta->id)->update(['updated_at' => '2020-01-01 00:00:00']);

        $updated_at_antes = Sale::find($venta->id)->updated_at;

        $this->pedir_venta($venta->id);

        $venta_despues = Sale::find($venta->id);

        $this->assertEquals(
            self::USER_ID,
            $venta_despues->actualizandose_por_id,
            'No quedó marcado actualizandose_por_id: el bloqueo de edición concurrente dejaría de funcionar.'
        );

        /*
         * La otra mitad del "igual que antes": el endpoint escribe con `timestamps = false`, así
         * que abrir una venta para mirarla no puede cambiarle la fecha de modificación. Sin esta
         * aserción, sacar esa línea deja los otros tests en verde y ensucia el dato que Lucas usa
         * para saber cuándo se tocó una venta.
         */
        $this->assertEquals(
            (string) $updated_at_antes,
            (string) $venta_despues->updated_at,
            'Abrir la venta le movió updated_at: el endpoint dejó de escribir con timestamps = false.'
        );
    }
}
