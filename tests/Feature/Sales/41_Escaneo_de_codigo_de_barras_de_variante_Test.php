<?php

namespace Tests\Feature\Sales;

use App\Models\Article;
use App\Models\ArticleVariant;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Mision "codigo-de-barras-de-variantes" (3/10/2026): escanear en Vender el codigo de una VARIANTE.
 *
 * Contrato C2 (plan de la mision): `GET vender/buscar-articulo-por-codido/{code}` busca primero si
 * el codigo es de una variante y, si lo es, devuelve el articulo JUNTO con la variante elegida:
 *   - las claves de siempre (`article`, `variant_id`, `variant`) no cambian de forma;
 *   - se suma `variant_row`: la misma fila que arma la busqueda por nombre (`build_row`), que es el
 *     formato con el que una variante entra al remito.
 * La variante se busca SOLO con la extension `article_variants`, SOLO entre los articulos del duenio
 * y SOLO entre las disponibles (`oculta = false`).
 *
 * Antes la busqueda no miraba la extension, no se acotaba al duenio (con codigos personalizados dos
 * comercios de la misma base pueden repetir codigo) y, con `codigos_de_barra_basados_en_numero_interno`,
 * un codigo que empieza con 0 y no era de ninguna variante dejaba la consulta de articulos SIN ningun
 * filtro: devolvia un articulo cualquiera del duenio.
 *
 * Los tests le pegan al endpoint real, con un usuario fresco por test para no compartir ids ni
 * codigos con nada mas (mismo patron que 40_Busqueda_en_vender_por_nombre_de_variante_Test).
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promocion de constructor, readonly, enum ni #[...].
 */
class Escaneo_de_codigo_de_barras_de_variante_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * Usuario de prueba fresco (un comercio).
     *
     * @param  string $sufijo
     * @return \App\Models\User
     */
    private function usuario_de_test($sufijo)
    {
        return User::create([
            'name'     => 'Comercio escaneo variantes ' . $sufijo,
            'email'    => 'escaneo-variantes-' . $sufijo . '-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);
    }

    /**
     * Prende una extension para un usuario (mismo patron que el test 40).
     *
     * @param  \App\Models\User $user
     * @param  string           $slug
     * @return void
     */
    private function dar_extension($user, $slug)
    {
        $extencion = ExtencionEmpresa::where('slug', $slug)->first();

        if (is_null($extencion)) {
            $extencion = ExtencionEmpresa::forceCreate([
                'slug' => $slug,
                'name' => $slug,
            ]);
        }

        $user->extencions()->attach($extencion->id);
        $user->load('extencions');
    }

    /**
     * Articulo activo minimo.
     *
     * @param  \App\Models\User $user
     * @param  string           $name
     * @param  array            $extra Atributos a sumar o pisar (por ejemplo bar_code).
     * @return \App\Models\Article
     */
    private function articulo($user, $name, $extra = [])
    {
        return Article::create(array_merge([
            'name'        => $name,
            'user_id'     => $user->id,
            'status'      => 'active',
            'final_price' => 100,
        ], $extra));
    }

    /**
     * Variante de un articulo. El `bar_code` es el que se le pase (null = sin codigo).
     *
     * @param  \App\Models\Article $article
     * @param  string              $descripcion
     * @param  string|null         $bar_code
     * @param  bool                $oculta
     * @param  int|null            $price       Precio propio de la variante (null = usa el del articulo).
     * @param  int|null            $stock       Stock de la variante (null = sin stock cargado).
     * @return \App\Models\ArticleVariant
     */
    private function variante($article, $descripcion, $bar_code, $oculta = false, $price = null, $stock = 5)
    {
        return ArticleVariant::create([
            'article_id'          => $article->id,
            'variant_description' => $descripcion,
            'oculta'              => $oculta,
            'price'               => $price,
            'stock'               => $stock,
            'bar_code'            => $bar_code,
        ]);
    }

    /**
     * Escanea un codigo como lo hace Vender: GET buscar-articulo-por-codido/{code}.
     *
     * @param  string $code
     * @return \Illuminate\Testing\TestResponse
     */
    private function escanear($code)
    {
        $res = $this->getJson('api/vender/buscar-articulo-por-codido/' . $code);

        $res->assertStatus(200);

        return $res;
    }

    /**
     * El caso de Lucas: con la extension de variantes, escanear el codigo de una variante devuelve
     * el articulo, la variante y la fila lista para el remito.
     *
     * @group sales
     * @group vender-scan
     * @test
     */
    public function el_codigo_de_una_variante_devuelve_el_articulo_la_variante_y_la_fila_del_remito()
    {
        $user = $this->usuario_de_test('e1');
        $this->dar_extension($user, 'article_variants');
        $this->actingAs($user, 'web');

        $zapatilla = $this->articulo($user, 'Zapatilla');
        $this->variante($zapatilla, 'azul 35', '7790001', false, 120);
        $azul_36 = $this->variante($zapatilla, 'azul 36', '7790002', false, 150);

        $body = $this->escanear('7790002')->json();

        // Claves de siempre.
        $this->assertEquals($zapatilla->id, $body['article']['id']);
        $this->assertEquals($azul_36->id, $body['variant_id']);
        $this->assertEquals($azul_36->id, $body['variant']['id']);
        $this->assertEquals('7790002', $body['variant']['bar_code']);

        // La fila nueva, con la forma de la busqueda por nombre.
        $fila = $body['variant_row'];
        $this->assertTrue($fila['is_variant']);
        $this->assertEquals($zapatilla->id, $fila['id'], 'El id de la fila es el del ARTICULO (asi lo arma la busqueda por nombre).');
        $this->assertEquals($azul_36->id, $fila['variant_id']);
        $this->assertEquals('azul 36', $fila['variant_description']);
        $this->assertEquals('Zapatilla azul 36', $fila['name']);
        $this->assertEquals(150, $fila['final_price'], 'La variante tiene precio propio: manda el suyo.');
        $this->assertEquals('7790002', $fila['bar_code']);
        $this->assertEquals($zapatilla->id, $fila['article']['id']);
        $this->assertArrayHasKey('images', $fila);
        $this->assertArrayHasKey('addresses', $fila);
        $this->assertArrayHasKey('precios_por_metodo_pago', $fila);

        // Cuando el codigo es de una variante, no es la respuesta de "articulo con variantes".
        $this->assertArrayNotHasKey('has_variants', $body);
        $this->assertArrayNotHasKey('variants', $body);
    }

    /**
     * Si la variante no tiene precio propio, la fila usa el precio final del articulo (igual que la
     * busqueda por nombre).
     *
     * @group sales
     * @group vender-scan
     * @test
     */
    public function el_final_price_de_la_fila_usa_el_del_articulo_si_la_variante_no_tiene_precio()
    {
        $user = $this->usuario_de_test('e2');
        $this->dar_extension($user, 'article_variants');
        $this->actingAs($user, 'web');

        $zapatilla = $this->articulo($user, 'Zapatilla');
        $this->variante($zapatilla, 'azul 36', '7790002', false, null);

        $precio_del_articulo = Article::find($zapatilla->id)->final_price;

        $fila = $this->escanear('7790002')->json('variant_row');

        $this->assertNotNull($precio_del_articulo);
        $this->assertEquals($precio_del_articulo, $fila['final_price']);
    }

    /**
     * La fila de variante trae `stock` (el de `article_variants.stock`, null si la variante no tiene),
     * tanto en el `variant_row` del escaneo como en la fila de la busqueda por nombre (contexto
     * `vender`). Sin esa clave, con `check_article_stock_en_vender` la SPA bloquea todas las
     * variantes: `check_stock_mayor_a_cero` pregunta `item.stock === null || item.stock > 0` y una
     * fila sin `stock` queda `undefined`.
     *
     * @group sales
     * @group vender-scan
     * @test
     */
    public function la_fila_de_variante_trae_el_stock_en_el_escaneo_y_en_la_busqueda_por_nombre()
    {
        $user = $this->usuario_de_test('e2b');
        $this->dar_extension($user, 'article_variants');
        $this->actingAs($user, 'web');

        $zapatilla = $this->articulo($user, 'Zapatilla');
        $con_stock = $this->variante($zapatilla, 'azul 35', '7790001', false, null, 7);
        $sin_stock = $this->variante($zapatilla, 'azul 36', '7790002', false, null, null);

        // Escaneo: el variant_row de cada una.
        $fila_con = $this->escanear('7790001')->json('variant_row');
        $this->assertArrayHasKey('stock', $fila_con);
        $this->assertEquals(7, $fila_con['stock']);

        $fila_sin = $this->escanear('7790002')->json('variant_row');
        $this->assertArrayHasKey('stock', $fila_sin, 'Sin stock cargado la clave viene igual, con null.');
        $this->assertNull($fila_sin['stock']);

        // Busqueda por nombre: las dos filas de variante del mismo articulo.
        $res = $this->postJson('api/global-search/article?page=1', [
            'query_value' => 'zapatilla',
            'props'       => ['name', 'provider_code'],
            'contexto'    => 'vender',
            'per_page'    => 50,
        ]);
        $res->assertStatus(200);

        $filas = collect($res->json('models.data'))->keyBy('variant_id');

        $this->assertCount(2, $filas);
        $this->assertArrayHasKey('stock', $filas[$con_stock->id]);
        $this->assertEquals(7, $filas[$con_stock->id]['stock']);
        $this->assertArrayHasKey('stock', $filas[$sin_stock->id]);
        $this->assertNull($filas[$sin_stock->id]['stock']);
    }

    /**
     * Las claves viejas no cambian de forma: `variant` es el modelo ArticleVariant pelado (no se le
     * cuelga el articulo adentro: la consultora de precios lo lee tal cual y duplicaria el payload
     * con el articulo completo, que ya viene en `article` y en `variant_row`).
     *
     * @group sales
     * @group vender-scan
     * @test
     */
    public function las_claves_viejas_conservan_su_forma()
    {
        $user = $this->usuario_de_test('e3');
        $this->dar_extension($user, 'article_variants');
        $this->actingAs($user, 'web');

        $zapatilla = $this->articulo($user, 'Zapatilla');
        $this->variante($zapatilla, 'azul 36', '7790002');

        $body = $this->escanear('7790002')->json();

        foreach (['id', 'article_id', 'variant_description', 'bar_code', 'price', 'stock', 'oculta'] as $clave) {
            $this->assertArrayHasKey($clave, $body['variant'], 'A `variant` le falta la clave ' . $clave);
        }

        $this->assertArrayNotHasKey('article', $body['variant'], '`variant` no lleva el articulo anidado.');

        foreach (['id', 'name', 'images', 'article_variants'] as $clave) {
            $this->assertArrayHasKey($clave, $body['article'], 'A `article` le falta la clave ' . $clave);
        }
    }

    /**
     * SIN la extension `article_variants` el codigo de una variante no se resuelve como variante:
     * sigue la cadena de articulo de siempre (aca, `bar_code` del articulo) y no encuentra nada.
     *
     * @group sales
     * @group vender-scan
     * @test
     */
    public function sin_la_extension_el_codigo_de_una_variante_no_se_resuelve_como_variante()
    {
        $user = $this->usuario_de_test('e4');
        $this->actingAs($user, 'web');

        $zapatilla = $this->articulo($user, 'Zapatilla', ['bar_code' => '7790000']);
        $this->variante($zapatilla, 'azul 36', '7790002');

        $body = $this->escanear('7790002')->json();

        $this->assertNull($body['article'], 'Sin la extension, el codigo de la variante no tiene que devolver nada.');
        $this->assertArrayNotHasKey('variant_row', $body);
        $this->assertArrayNotHasKey('variant_id', $body);

        // Y el articulo sigue escaneandose por su propio codigo, sin variantes ni selector.
        $body = $this->escanear('7790000')->json();

        $this->assertEquals($zapatilla->id, $body['article']['id']);
        $this->assertFalse($body['has_variants']);
        $this->assertArrayNotHasKey('variant_row', $body);
    }

    /**
     * Una variante OCULTA (no disponible) no se ofrece en Vender: su codigo no se resuelve como
     * variante, igual que la busqueda por nombre no la lista.
     *
     * @group sales
     * @group vender-scan
     * @test
     */
    public function una_variante_oculta_no_se_resuelve_como_variante()
    {
        $user = $this->usuario_de_test('e5');
        $this->dar_extension($user, 'article_variants');
        $this->actingAs($user, 'web');

        $zapatilla = $this->articulo($user, 'Zapatilla');
        $this->variante($zapatilla, 'azul 36', '7790002', true);

        $body = $this->escanear('7790002')->json();

        $this->assertNull($body['article']);
        $this->assertArrayNotHasKey('variant_row', $body);
        $this->assertArrayNotHasKey('variant_id', $body);
    }

    /**
     * Aislamiento entre comercios: `article_variants` no tiene `user_id`, y con codigos
     * personalizados dos comercios de la misma base pueden repetir el mismo codigo. La variante del
     * OTRO comercio (creada primero, o sea con el id mas bajo) no puede ganarle a la propia.
     *
     * @group sales
     * @group vender-scan
     * @test
     */
    public function el_mismo_codigo_en_una_variante_de_otro_duenio_no_matchea()
    {
        // Comercio B: creado primero, su variante tiene el id mas bajo.
        $otro = $this->usuario_de_test('e6b');
        $this->dar_extension($otro, 'article_variants');
        $zapatilla_ajena = $this->articulo($otro, 'Zapatilla ajena');
        $variante_ajena = $this->variante($zapatilla_ajena, 'azul 36', '7790002');

        $user = $this->usuario_de_test('e6a');
        $this->dar_extension($user, 'article_variants');
        $this->actingAs($user, 'web');

        // Sin variante propia con ese codigo: no ve la del otro comercio.
        $body = $this->escanear('7790002')->json();
        $this->assertNull($body['article'], 'La variante del otro comercio no se puede escanear.');
        $this->assertArrayNotHasKey('variant_row', $body);

        // Con variante propia con el mismo codigo: gana la propia, no la que tiene el id mas bajo.
        $zapatilla = $this->articulo($user, 'Zapatilla propia');
        $propia = $this->variante($zapatilla, 'rojo 35', '7790002');

        $body = $this->escanear('7790002')->json();

        $this->assertNotEquals($variante_ajena->id, $body['variant_id']);
        $this->assertEquals($propia->id, $body['variant_id']);
        $this->assertEquals($zapatilla->id, $body['article']['id']);
        $this->assertEquals($zapatilla->id, $body['variant_row']['article']['id']);
    }

    /**
     * El codigo de un ARTICULO sigue funcionando como siempre: sin variantes devuelve
     * `has_variants = false`; con variantes disponibles devuelve `has_variants = true` y la lista
     * (sin las ocultas); en ningun caso trae `variant_row`.
     *
     * @group sales
     * @group vender-scan
     * @test
     */
    public function el_codigo_de_un_articulo_sigue_funcionando()
    {
        $user = $this->usuario_de_test('e7');
        $this->dar_extension($user, 'article_variants');
        $this->actingAs($user, 'web');

        $clavo = $this->articulo($user, 'Clavo', ['bar_code' => '1110001']);
        $zapatilla = $this->articulo($user, 'Zapatilla', ['bar_code' => '1110002']);
        $this->variante($zapatilla, 'azul 35', '7790001');
        $this->variante($zapatilla, 'azul 36', '7790002');
        $this->variante($zapatilla, 'rojo 36', '7790003', true); // oculta: no se ofrece

        $sin_variantes = $this->escanear('1110001')->json();

        $this->assertEquals($clavo->id, $sin_variantes['article']['id']);
        $this->assertFalse($sin_variantes['has_variants']);
        $this->assertArrayNotHasKey('variant_row', $sin_variantes);

        $con_variantes = $this->escanear('1110002')->json();

        $this->assertEquals($zapatilla->id, $con_variantes['article']['id']);
        $this->assertTrue($con_variantes['has_variants']);
        $this->assertCount(2, $con_variantes['variants']);
        $this->assertArrayNotHasKey('variant_row', $con_variantes);

        $descripciones = collect($con_variantes['variants'])->pluck('variant_description')->all();
        sort($descripciones);
        $this->assertEquals(['azul 35', 'azul 36'], $descripciones);
    }

    /**
     * Con `codigos_de_barra_basados_en_numero_interno`, el codigo '0' + id resuelve la variante
     * (comportamiento de siempre, ahora acotado al duenio y a las disponibles), aunque la variante
     * tenga otro codigo propio cargado.
     *
     * @group sales
     * @group vender-scan
     * @test
     */
    public function con_numero_interno_el_cero_mas_id_resuelve_la_variante()
    {
        $user = $this->usuario_de_test('e8');
        $this->dar_extension($user, 'article_variants');
        $this->dar_extension($user, 'codigos_de_barra_basados_en_numero_interno');
        $this->actingAs($user, 'web');

        $zapatilla = $this->articulo($user, 'Zapatilla');
        $variante = $this->variante($zapatilla, 'azul 36', '7790002');

        $body = $this->escanear('0' . $variante->id)->json();

        $this->assertEquals($variante->id, $body['variant_id']);
        $this->assertEquals($zapatilla->id, $body['article']['id']);
        $this->assertEquals($variante->id, $body['variant_row']['variant_id']);
    }

    /**
     * Con `codigos_de_barra_basados_en_numero_interno`, un codigo PERSONALIZADO de variante que
     * empieza con 0 tambien resuelve (antes, esa extension salteaba la busqueda exacta por
     * `bar_code` y un codigo asi no se encontraba nunca).
     *
     * @group sales
     * @group vender-scan
     * @test
     */
    public function con_numero_interno_un_codigo_personalizado_que_empieza_con_cero_tambien_resuelve()
    {
        $user = $this->usuario_de_test('e9');
        $this->dar_extension($user, 'article_variants');
        $this->dar_extension($user, 'codigos_de_barra_basados_en_numero_interno');
        $this->actingAs($user, 'web');

        $zapatilla = $this->articulo($user, 'Zapatilla');
        $variante = $this->variante($zapatilla, 'azul 36', '0777000111');

        $body = $this->escanear('0777000111')->json();

        $this->assertEquals($variante->id, $body['variant_id']);
        $this->assertEquals($zapatilla->id, $body['variant_row']['article']['id']);
    }

    /**
     * El defecto de antes: con `codigos_de_barra_basados_en_numero_interno`, un codigo que empieza
     * con 0 y NO es de ninguna variante dejaba la consulta de articulos sin ningun filtro y
     * devolvia un articulo cualquiera del duenio. Ahora sigue la cadena normal (`num`) y no
     * encuentra nada.
     *
     * @group sales
     * @group vender-scan
     * @test
     */
    public function con_numero_interno_un_codigo_con_cero_inexistente_ya_no_devuelve_un_articulo_cualquiera()
    {
        $user = $this->usuario_de_test('e10');
        $this->dar_extension($user, 'article_variants');
        $this->dar_extension($user, 'codigos_de_barra_basados_en_numero_interno');
        $this->actingAs($user, 'web');

        // El comercio tiene articulos (y una variante) pero ninguno responde a este codigo.
        $zapatilla = $this->articulo($user, 'Zapatilla');
        $this->articulo($user, 'Clavo');
        $this->variante($zapatilla, 'azul 36', '7790002');

        $body = $this->escanear('0987654321')->json();

        $this->assertNull($body['article'], 'Un codigo inexistente no puede devolver un articulo cualquiera del comercio.');
        $this->assertArrayNotHasKey('variant_row', $body);
    }

    /**
     * Con `codigos_de_barra_basados_en_numero_interno` el '0' + id tampoco cruza comercios ni
     * resuelve variantes ocultas.
     *
     * @group sales
     * @group vender-scan
     * @test
     */
    public function con_numero_interno_el_cero_mas_id_no_cruza_duenios_ni_ocultas()
    {
        $otro = $this->usuario_de_test('e11b');
        $this->dar_extension($otro, 'article_variants');
        $zapatilla_ajena = $this->articulo($otro, 'Zapatilla ajena');
        $variante_ajena = $this->variante($zapatilla_ajena, 'azul 36', '7790002');

        $user = $this->usuario_de_test('e11a');
        $this->dar_extension($user, 'article_variants');
        $this->dar_extension($user, 'codigos_de_barra_basados_en_numero_interno');
        $this->actingAs($user, 'web');

        $zapatilla = $this->articulo($user, 'Zapatilla');
        $oculta = $this->variante($zapatilla, 'rojo 35', '7790003', true);

        $ajena = $this->escanear('0' . $variante_ajena->id)->json();
        $this->assertNull($ajena['article'], 'El 0 + id de una variante de otro comercio no se resuelve.');
        $this->assertArrayNotHasKey('variant_row', $ajena);

        $propia_oculta = $this->escanear('0' . $oculta->id)->json();
        $this->assertNull($propia_oculta['article'], 'El 0 + id de una variante oculta no se resuelve.');
        $this->assertArrayNotHasKey('variant_row', $propia_oculta);
    }

    /**
     * Con `codigos_de_barra_basados_en_numero_interno`, un codigo que empieza con '0' y NO resuelve
     * como variante (oculta, inexistente) es "no encontrado": no se consulta `articles.num`. Antes
     * caia a `num = '0345'` y MySQL casteaba el texto a 345: devolvia el articulo cuyo `num` es 345,
     * que no tiene nada que ver con lo escaneado. El test anterior de "0 inexistente" no lo veia
     * porque sus articulos no tenian `num`; aca si lo tienen, y coincide con el id de la variante.
     *
     * @group sales
     * @group vender-scan
     * @test
     */
    public function con_numero_interno_un_codigo_con_cero_que_no_es_variante_no_cae_al_num_por_el_cast_de_mysql()
    {
        $user = $this->usuario_de_test('e12');
        $this->dar_extension($user, 'article_variants');
        $this->dar_extension($user, 'codigos_de_barra_basados_en_numero_interno');
        $this->actingAs($user, 'web');

        $zapatilla = $this->articulo($user, 'Zapatilla');
        $oculta = $this->variante($zapatilla, 'rojo 35', '7790003', true);

        // Un articulo cuyo `num` es justo el id de la variante oculta, y otro con un num cualquiera.
        $this->articulo($user, 'Clavo', ['num' => $oculta->id]);
        $this->articulo($user, 'Tuerca', ['num' => 987654]);

        $oculta_por_id = $this->escanear('0' . $oculta->id)->json();
        $this->assertNull($oculta_por_id['article'], '0 + id de una variante oculta no puede devolver el articulo con ese num.');
        $this->assertArrayNotHasKey('variant_row', $oculta_por_id);

        $inexistente = $this->escanear('0987654')->json();
        $this->assertNull($inexistente['article'], 'Un 0 + numero que no es variante no puede devolver el articulo con ese num.');
        $this->assertArrayNotHasKey('variant_row', $inexistente);
    }

    /**
     * Con `codigos_de_barra_basados_en_numero_interno`, el articulo se busca por `num` solo si el
     * codigo es un numero canonico (solo digitos, sin ceros de relleno): '345' lo encuentra; '345abc',
     * '345.0' o '0345' no (el cast de MySQL los habria tomado por 345).
     *
     * @group sales
     * @group vender-scan
     * @test
     */
    public function con_numero_interno_el_articulo_se_busca_por_num_solo_con_el_numero_canonico()
    {
        $user = $this->usuario_de_test('e13');
        $this->dar_extension($user, 'article_variants');
        $this->dar_extension($user, 'codigos_de_barra_basados_en_numero_interno');
        $this->actingAs($user, 'web');

        $clavo = $this->articulo($user, 'Clavo', ['num' => 345]);

        $normal = $this->escanear('345')->json();
        $this->assertEquals($clavo->id, $normal['article']['id'], 'El articulo normal por num se sigue resolviendo.');
        $this->assertFalse($normal['has_variants']);

        foreach (['345abc', '345.0', '0345', 'abc'] as $laxo) {
            $this->assertNull($this->escanear($laxo)->json('article'), 'El codigo "' . $laxo . '" no tiene que matchear num = 345.');
        }
    }
}
