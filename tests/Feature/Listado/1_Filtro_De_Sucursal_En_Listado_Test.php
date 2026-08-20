<?php

namespace Tests\Feature\Listado;

use App\Models\Address;
use App\Models\Article;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Models\ArticleVariant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Busqueda\BusquedaTestCase;

/**
 * Feature tests del filtro por sucursal del Listado de articulos (19/8/2026).
 *
 * Lo que pidio Lucas, textual: "si se selecciona una sucursal (...) se tendrian que ver los
 * articulos que se les haya seteado en algun momento el stock para esa sucursal", sea ese stock
 * "cero, menor a cero o mayor a cero". O sea: la pregunta es si EXISTE la fila en el pivote
 * address_article, no cuanto hay adentro.
 *
 * Por eso el operador `address_stock_seteado` de ExtraFiltersHelper es un whereHas y no una
 * comparacion numerica sobre pivot.amount: cualquier comparacion dejaria afuera justo los tres
 * casos que se pidio incluir. Los tests 2 a 5 existen para que nadie lo "optimice" a un
 * `where('amount', '>', 0)` sin que la suite se ponga roja.
 *
 * El test 8 cubre la decision de alcance que NO estaba en el pedido y esta documentada en el
 * INFORME: un articulo cuya relacion con la sucursal vive en sus VARIANTES tambien entra. Motivo:
 * get_address_stock() del mixin payment_method_discounts_addresses_columns.js ya le muestra un
 * numero en la columna "Sucursal X" (cae a sumar el stock de las variantes cuando el articulo no
 * tiene fila propia), asi que si el filtro no lo incluyera, un articulo con un numero visible en
 * esa columna desapareceria al filtrar por esa misma sucursal.
 *
 * Extiende BusquedaTestCase por los guards de entorno (base de testing, motor InnoDB, fixture
 * sembrado) y por payload_global_search(), que arma el body con los defaults del endpoint.
 */
class Filtro_De_Sucursal_En_Listado_Test extends BusquedaTestCase
{
    /**
     * Prefijo de los nombres del escenario. Todo lo que crea esta suite lo lleva, para que las
     * aserciones puedan mirar solo lo suyo aunque la base de testing este sembrada de antes.
     */
    const PREFIJO = 'zzfiltrosuc';

    /**
     * Siembra dos sucursales y siete articulos que cubren todos los casos del criterio.
     *
     * @return array<string,mixed>
     */
    protected function sembrar_escenario()
    {
        /** Id del usuario autenticado por el setUp de EmpresaTestCase (el del fixture). */
        $user_id = auth()->id();

        $address_a = Address::create([
            'street'  => self::PREFIJO.' Sucursal A',
            'user_id' => $user_id,
        ]);

        $address_b = Address::create([
            'street'  => self::PREFIJO.' Sucursal B',
            'user_id' => $user_id,
        ]);

        /** Articulos del escenario, indexados por la clave con la que los nombran las aserciones. */
        $articulos = [];

        // Los cuatro valores de pivote que tienen que entrar igual: la fila existe.
        $articulos['a_positivo'] = $this->articulo_con_pivote('a positivo', $user_id, $address_a, 10);
        $articulos['a_cero']     = $this->articulo_con_pivote('a cero', $user_id, $address_a, 0);
        $articulos['a_negativo'] = $this->articulo_con_pivote('a negativo', $user_id, $address_a, -5);
        $articulos['a_null']     = $this->articulo_con_pivote('a null', $user_id, $address_a, null);

        // Fila en la OTRA sucursal: no tiene que aparecer al filtrar por A.
        $articulos['b_positivo'] = $this->articulo_con_pivote('b positivo', $user_id, $address_b, 7);

        // Sin ninguna fila en address_article: es el que el filtro tiene que sacar.
        $articulos['sin_relacion'] = Article::create([
            'name'    => self::PREFIJO.' sin relacion',
            'user_id' => $user_id,
        ]);

        // Articulo con VARIANTES cuyo stock por sucursal vive solo en la sucursal A.
        //
        // 🔴 El escenario se termina de armar pasando por `ArticleHelper::setArticleStockFromAddresses()`,
        // que es el camino REAL de produccion (lo llaman StockMovementController, SetArticleStock,
        // ProductionMovementHelper y ArticleImport), y NO se deja con el attach a mano. La
        // diferencia no es cosmetica: ese metodo REGENERA el pivote del articulo con una fila para
        // CADA sucursal de la cuenta, las que no tienen stock en 0. O sea que despues de esta
        // llamada, este articulo tiene fila en address_article para A **y para B**, con B en 0.
        //
        // Un test que armara este estado a mano probaria un estado que el sistema nunca produce, y
        // fue exactamente asi como la primera version de esta suite dio verde sobre un filtro que
        // en produccion no filtraba nada: preguntar "existe la fila?" devolvia que si para TODAS
        // las sucursales. El test `el_articulo_con_variantes_no_aparece_en_la_sucursal_que_nunca_le_cargaron`
        // es el que protege eso.
        $articulos['variante_a'] = Article::create([
            'name'    => self::PREFIJO.' con variante en a',
            'user_id' => $user_id,
        ]);

        $variante = ArticleVariant::create([
            'article_id'          => $articulos['variante_a']->id,
            'variant_description' => self::PREFIJO.' variante roja',
        ]);

        $variante->addresses()->attach($address_a->id, ['amount' => 3]);

        $articulos['variante_a']->load('article_variants.addresses');

        ArticleHelper::setArticleStockFromAddresses($articulos['variante_a'], false, $user_id);

        return [
            'address_a' => $address_a,
            'address_b' => $address_b,
            'articulos' => $articulos,
        ];
    }

    /**
     * Crea un articulo del escenario con una fila en address_article para la sucursal dada.
     *
     * @param string $sufijo Parte legible del nombre (se le antepone el prefijo de la suite).
     * @param int $user_id
     * @param \App\Models\Address $address
     * @param int|null $amount Valor del pivote: puede ser 0, negativo o null a proposito.
     * @return \App\Models\Article
     */
    protected function articulo_con_pivote($sufijo, $user_id, $address, $amount)
    {
        $articulo = Article::create([
            'name'    => self::PREFIJO.' '.$sufijo,
            'user_id' => $user_id,
        ]);

        $articulo->addresses()->attach($address->id, ['amount' => $amount]);

        return $articulo;
    }

    /**
     * Pega al endpoint real del listado con el filtro de sucursal puesto (o sin el, con 0).
     *
     * @param int $address_id Sucursal elegida, o 0 para "todas".
     * @return \Illuminate\Testing\TestResponse
     */
    protected function buscar_con_sucursal($address_id)
    {
        /** Filtros extra: exactamente los que arma la mutation set_address_id_filtro de la SPA. */
        $extra_filters = [];

        if ($address_id) {
            $extra_filters[] = [
                'key'      => 'address_id',
                'operator' => 'address_stock_seteado',
                'value'    => $address_id,
            ];
        }

        return $this->postJson('api/global-search/article', $this->payload_global_search([
            'query_value'   => self::PREFIJO,
            'props'         => ['name'],
            'extra_filters' => $extra_filters,
            'per_page'      => 200,
        ]));
    }

    /**
     * Ids devueltos por el buscador.
     *
     * @param \Illuminate\Testing\TestResponse $response
     * @return array<int>
     */
    protected function ids_de($response)
    {
        return array_column($response->json('models.data'), 'id');
    }

    /**
     * Criterio 1 - sin sucursal elegida (el select arranca en 0) la query queda como antes: estan
     * los siete articulos del escenario, los de las dos sucursales y el que no tiene ninguna.
     *
     * Sin esta mitad, un filtro que no devolviera nada pasaria los tests de "no aparece".
     *
     * @group listado
     * @test
     */
    public function sin_sucursal_elegida_trae_todos_los_articulos()
    {
        $escenario = $this->sembrar_escenario();

        $response = $this->buscar_con_sucursal(0);

        $response->assertStatus(200);

        $ids = $this->ids_de($response);

        foreach ($escenario['articulos'] as $clave => $articulo) {
            $this->assertContains(
                $articulo->id,
                $ids,
                'sin filtro de sucursal tiene que aparecer el articulo "'.$clave.'"'
            );
        }
    }

    /**
     * Criterios 2, 3, 4 y 5 - con la sucursal A elegida entran los cuatro articulos que tienen
     * fila en address_article para A, valga 10, 0, -5 o null.
     *
     * Los cuatro van en el mismo test a proposito: son el mismo criterio ("la fila existe") medido
     * en sus cuatro valores, y separarlos escondería que lo que se prueba es uno solo.
     *
     * @group listado
     * @test
     */
    public function con_una_sucursal_entran_los_articulos_con_la_relacion_seteada_sea_cual_sea_el_valor()
    {
        $escenario = $this->sembrar_escenario();

        $response = $this->buscar_con_sucursal($escenario['address_a']->id);

        $response->assertStatus(200);

        $ids = $this->ids_de($response);

        $this->assertContains(
            $escenario['articulos']['a_positivo']->id,
            $ids,
            'stock positivo en la sucursal: tiene que aparecer'
        );
        $this->assertContains(
            $escenario['articulos']['a_cero']->id,
            $ids,
            'stock 0 en la sucursal: la relacion esta seteada, tiene que aparecer (pedido explicito de Lucas)'
        );
        $this->assertContains(
            $escenario['articulos']['a_negativo']->id,
            $ids,
            'stock negativo en la sucursal: la relacion esta seteada, tiene que aparecer (pedido explicito de Lucas)'
        );
        $this->assertContains(
            $escenario['articulos']['a_null']->id,
            $ids,
            'fila seteada con amount null: la relacion existe, tiene que aparecer'
        );
    }

    /**
     * Criterio 6 - el articulo sin ninguna fila en address_article no aparece. Es el corazon del
     * filtro: es lo unico que se saca de la tabla.
     *
     * @group listado
     * @test
     */
    public function el_articulo_sin_relacion_con_ninguna_sucursal_no_aparece()
    {
        $escenario = $this->sembrar_escenario();

        $response = $this->buscar_con_sucursal($escenario['address_a']->id);

        $response->assertStatus(200);

        $this->assertNotContains(
            $escenario['articulos']['sin_relacion']->id,
            $this->ids_de($response),
            'un articulo al que nunca se le seteo stock en ninguna sucursal no tiene que aparecer'
        );
    }

    /**
     * Criterio 7 - el articulo que solo tiene relacion con la sucursal B no aparece al filtrar por
     * A. Sin este test, un whereHas mal escrito (sin el where sobre addresses.id) pasaria los
     * anteriores igual: bastaria con tener CUALQUIER sucursal.
     *
     * @group listado
     * @test
     */
    public function el_articulo_de_otra_sucursal_no_aparece()
    {
        $escenario = $this->sembrar_escenario();

        $response = $this->buscar_con_sucursal($escenario['address_a']->id);

        $response->assertStatus(200);

        $ids = $this->ids_de($response);

        $this->assertNotContains(
            $escenario['articulos']['b_positivo']->id,
            $ids,
            'un articulo con stock seteado SOLO en la sucursal B no tiene que aparecer al filtrar por la A'
        );

        // La contracara, para que el test no pase por devolver poco: filtrando por B aparece el de
        // B y no los de A.
        $response_b = $this->buscar_con_sucursal($escenario['address_b']->id);

        $response_b->assertStatus(200);

        $ids_b = $this->ids_de($response_b);

        $this->assertContains($escenario['articulos']['b_positivo']->id, $ids_b);
        $this->assertNotContains($escenario['articulos']['a_positivo']->id, $ids_b);
        $this->assertNotContains($escenario['articulos']['a_cero']->id, $ids_b);
    }

    /**
     * Criterio 8 - el articulo cuya relacion con la sucursal vive en una VARIANTE tambien entra
     * (decision de alcance documentada en el docblock de la clase y en el INFORME).
     *
     * @group listado
     * @test
     */
    public function el_articulo_con_la_relacion_en_una_variante_tambien_aparece()
    {
        $escenario = $this->sembrar_escenario();

        $response = $this->buscar_con_sucursal($escenario['address_a']->id);

        $response->assertStatus(200);

        $this->assertContains(
            $escenario['articulos']['variante_a']->id,
            $this->ids_de($response),
            'la columna "Sucursal A" de la tabla ya le muestra el stock de la variante: si el filtro '.
            'no lo incluyera, desapareceria al filtrar por esa misma sucursal'
        );
    }

    /**
     * 🔴 El test que la Fase 5 hizo falta agregar el 19/8/2026, y el que mas duele si se cae.
     *
     * `ArticleHelper::setArticleStockFromAddresses()` regenera el pivote del articulo con una fila
     * para CADA sucursal de la cuenta, las que no tienen stock en 0 (get_addresses() arma el array
     * con todas en 0, actualizar_article_addresses() hace sync([]) + attach de cada entrada). O sea
     * que el articulo con variantes de este escenario TIENE fila en address_article para la
     * sucursal B, con amount 0, sin que nadie le haya cargado nunca stock ahi.
     *
     * Con el criterio ingenuo ("existe la fila en address_article?"), ese articulo aparecia al
     * filtrar por B, y con el todos los articulos con variantes de la cuenta: el filtro no filtraba
     * NADA justo en los negocios que usan variantes por sucursal, que son los que tienen sucursales
     * en serio. Por eso el operador mira el pivote de las VARIANTES cuando alguna variante tiene
     * sucursales, y el del articulo solo cuando ninguna la tiene.
     *
     * Este test empieza afirmando la premisa (la fila en 0 existe de verdad) para que, si algun dia
     * el backend deja de generarla, quede claro que el test cambio de sentido y no que "se arreglo
     * solo".
     *
     * @group listado
     * @test
     */
    public function el_articulo_con_variantes_no_aparece_en_la_sucursal_que_nunca_le_cargaron()
    {
        $escenario = $this->sembrar_escenario();

        $articulo = $escenario['articulos']['variante_a'];

        // Premisa: el backend dejo una fila en 0 para la sucursal B, que nadie cargo.
        $fila_en_b = $articulo->addresses()
                              ->where('addresses.id', $escenario['address_b']->id)
                              ->first();

        $this->assertNotNull(
            $fila_en_b,
            'premisa del test: setArticleStockFromAddresses tiene que dejar una fila en 0 para TODAS '.
            'las sucursales. Si esto falla, el backend cambio y hay que revisar el criterio del filtro.'
        );
        $this->assertEquals(0, (float) $fila_en_b->pivot->amount);

        // Y aun asi, el articulo NO tiene que aparecer al filtrar por B.
        $response = $this->buscar_con_sucursal($escenario['address_b']->id);

        $response->assertStatus(200);

        $this->assertNotContains(
            $articulo->id,
            $this->ids_de($response),
            'una fila en 0 que genero el backend solo NO es "se le seteo el stock en esa sucursal"'
        );

        // La contracara: en la sucursal que si le cargaron, aparece.
        $response_a = $this->buscar_con_sucursal($escenario['address_a']->id);

        $response_a->assertStatus(200);

        $this->assertContains($articulo->id, $this->ids_de($response_a));
    }

    /**
     * Criterio 9 - aislamiento por usuario: un articulo de otro comercio con stock seteado en una
     * sucursal nuestra no se cuela.
     *
     * El where('user_id') ya vive en globalSearch, pero este test protege algo puntual del cambio:
     * el OR entre las dos relaciones va envuelto en un where(function(){}). Sin ese grupo, el
     * orWhereHas se suelta del AND y se OR-ea contra toda la query anterior —incluido el
     * where('user_id')—, y este test es el que lo denuncia.
     *
     * @group listado
     * @test
     */
    public function no_se_cuela_un_articulo_de_otro_usuario()
    {
        $escenario = $this->sembrar_escenario();

        // Un usuario de verdad y no un id inventado: articles.user_id tiene foreign key contra
        // users, asi que un id inexistente no falla el test, lo revienta con un 1452 antes de
        // llegar a la asercion (medido el 19/8/2026, primera corrida de esta suite).
        $otro_comercio = User::create([
            'name'     => 'Otro comercio '.self::PREFIJO,
            'email'    => self::PREFIJO.'-otro-'.uniqid().'@test.local',
            'password' => Hash::make('secret'),
        ]);

        $articulo_ajeno = Article::create([
            'name'    => self::PREFIJO.' ajeno',
            'user_id' => $otro_comercio->id,
        ]);

        $articulo_ajeno->addresses()->attach($escenario['address_a']->id, ['amount' => 4]);

        $response = $this->buscar_con_sucursal($escenario['address_a']->id);

        $response->assertStatus(200);

        $this->assertNotContains(
            $articulo_ajeno->id,
            $this->ids_de($response),
            'el filtro de sucursal no puede romper el aislamiento por usuario'
        );
    }

    /**
     * Sin dano colateral - el operador nuevo sobre un modelo que NO define addresses() se ignora en
     * silencio, que es el contrato del helper, y la busqueda sigue funcionando.
     *
     * @group listado
     * @test
     */
    public function sobre_un_modelo_sin_sucursales_el_operador_se_ignora_sin_romper()
    {
        $proveedor = $this->proveedor('Rosario');

        $this->assertNotNull($proveedor, 'el fixture tiene que traer el proveedor Rosario');

        $response = $this->postJson('api/global-search/provider', $this->payload_global_search([
            'query_value'   => 'Rosario',
            'props'         => ['name'],
            'extra_filters' => [
                [
                    'key'      => 'address_id',
                    'operator' => 'address_stock_seteado',
                    'value'    => 1,
                ],
            ],
        ]));

        $response->assertStatus(200);

        $this->assertContains(
            $proveedor->id,
            $this->ids_de($response),
            'el operador se ignora sobre un modelo sin addresses(): la busqueda no cambia'
        );
    }
}
