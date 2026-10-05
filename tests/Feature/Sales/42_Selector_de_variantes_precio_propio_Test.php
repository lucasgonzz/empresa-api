<?php

namespace Tests\Feature\Sales;

use App\Http\Controllers\Helpers\article\ArticlePricesHelper;
use App\Models\Article;
use App\Models\ArticleVariant;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\CurrentAcountPaymentMethodDiscount;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Mision "selector-de-variantes-con-precio-propio" (3/10/2026): escanear en Vender el codigo de un
 * ARTICULO que tiene variantes disponibles abre el selector de variantes, y cada variante tiene que
 * ofrecerse con SU precio.
 *
 * Contrato C2 (plan de la mision): `GET vender/buscar-articulo-por-codido/{code}`, rama
 * `has_variants: true`, devuelve en `variants[]` un elemento por variante disponible con:
 *   - las claves de siempre (`variant_id`, `variant_description`, `final_price`, `bar_code`,
 *     `images`, `addresses`, `oculta`), que no cambian de nombre, de forma ni de significado;
 *     `final_price` es el precio propio de la variante si lo tiene y, si no, el del articulo (desde
 *     el Prompt 525; la SPA no lo leia y vendia todo al precio del articulo);
 *   - una clave NUEVA y opcional: `precios_por_metodo_pago`, el mismo desglose por metodo de pago que
 *     ya llevan las filas de variante de la busqueda por nombre y del escaneo por codigo de variante
 *     (Capa 3, `ArticlePricesHelper::calcular_precios_por_metodo_pago_con_tarjeta_incluida`), pero
 *     calculado sobre el `final_price` DE ESA VARIANTE. Un cliente viejo la ignora y sigue como siempre.
 *
 * Por que la clave nueva: el item que arma el selector copia `precios_por_metodo_pago` del articulo, y
 * el `price` absoluto de cada metodo seria el del articulo aunque la variante tenga otro precio: un
 * dato incoherente con el `final_price` del mismo item. Hoy la SPA solo lee el porcentaje, que no
 * depende del precio, pero es una trampa latente.
 *
 * Los tests le pegan al endpoint real, con un usuario fresco por test para no compartir ids ni
 * codigos con nada mas (mismo patron que 41_Escaneo_de_codigo_de_barras_de_variante_Test).
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promocion de constructor, readonly, enum ni #[...].
 */
class Selector_de_variantes_precio_propio_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * Claves que tiene hoy (y tiene que seguir teniendo) cada elemento de `variants[]`, mas la nueva.
     * Se comparan como conjunto EXACTO: si alguien suma, saca o renombra una clave sin pasar por el
     * contrato, el test lo ve.
     *
     * @var string[]
     */
    private $claves_de_una_variante = [
        'addresses',
        'bar_code',
        'final_price',
        'images',
        'oculta',
        'precios_por_metodo_pago',
        'variant_description',
        'variant_id',
    ];

    /**
     * El cache de la Capa 3 es una propiedad estatica (vive lo que viva el proceso de phpunit). Cada
     * usuario de test es fresco y tiene su propia clave, pero se vacia igual al empezar y al terminar
     * para que ningun test dependa del orden ni deje basura para el siguiente.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        ArticlePricesHelper::$payment_method_layer3_cache = [];
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        ArticlePricesHelper::$payment_method_layer3_cache = [];

        parent::tearDown();
    }

    /**
     * Usuario de prueba fresco (un comercio).
     *
     * @param  string $sufijo
     * @return \App\Models\User
     */
    private function usuario_de_test($sufijo)
    {
        return User::create([
            'name'     => 'Comercio selector variantes ' . $sufijo,
            'email'    => 'selector-variantes-' . $sufijo . '-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);
    }

    /**
     * Prende una extension para un usuario (mismo patron que los tests 40 y 41).
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
     * Articulo activo minimo, con codigo de barras para escanearlo.
     *
     * @param  \App\Models\User $user
     * @param  string           $name
     * @param  string           $bar_code
     * @return \App\Models\Article
     */
    private function articulo($user, $name, $bar_code)
    {
        return Article::create([
            'name'        => $name,
            'user_id'     => $user->id,
            'status'      => 'active',
            'final_price' => 100,
            'bar_code'    => $bar_code,
        ]);
    }

    /**
     * Variante de un articulo.
     *
     * @param  \App\Models\Article $article
     * @param  string              $descripcion
     * @param  string|null         $bar_code
     * @param  int|float|null      $price       Precio propio de la variante (null = usa el del articulo).
     * @param  bool                $oculta
     * @return \App\Models\ArticleVariant
     */
    private function variante($article, $descripcion, $bar_code, $price = null, $oculta = false)
    {
        return ArticleVariant::create([
            'article_id'          => $article->id,
            'variant_description' => $descripcion,
            'oculta'              => $oculta,
            'price'               => $price,
            'stock'               => 5,
            'bar_code'            => $bar_code,
        ]);
    }

    /**
     * Escanea un codigo como lo hace Vender: GET buscar-articulo-por-codido/{code}.
     *
     * @param  string $code
     * @return array Cuerpo de la respuesta, decodificado.
     */
    private function escanear($code)
    {
        $res = $this->getJson('api/vender/buscar-articulo-por-codido/' . $code);

        $res->assertStatus(200);

        return $res->json();
    }

    /**
     * Las variantes de la respuesta, indexadas por `variant_id`.
     *
     * @param  array $body
     * @return \Illuminate\Support\Collection
     */
    private function variantes_por_id($body)
    {
        return collect($body['variants'])->keyBy('variant_id');
    }

    /**
     * Deja al usuario con "el precio de etiqueta ya incluye el recargo de tarjeta" (Capa 3) y con dos
     * metodos de pago: uno con recargo del 10% (descuento -10) y otro sin regla (0%). Con eso el
     * multiplicador maximo es 1,10 y:
     *   - el metodo con recargo paga exactamente la etiqueta;
     *   - el metodo sin regla paga etiqueta / 1,10, o sea 9,09% menos que la etiqueta.
     * Los metodos se crean con nombres propios dentro de la transaccion del test (el catalogo de
     * metodos es global, asi que el test los busca por id y no cuenta cuantos hay).
     *
     * @param  \App\Models\User $user
     * @return array{con_recargo: \App\Models\CurrentAcountPaymentMethod, sin_regla: \App\Models\CurrentAcountPaymentMethod}
     */
    private function activar_recargo_de_tarjeta($user)
    {
        $user->update(['precio_base_incluye_tarjeta' => true]);

        $con_recargo = CurrentAcountPaymentMethod::create(['name' => 'Credito test selector']);
        $sin_regla   = CurrentAcountPaymentMethod::create(['name' => 'Efectivo test selector']);

        CurrentAcountPaymentMethodDiscount::create([
            'current_acount_payment_method_id' => $con_recargo->id,
            'discount_percentage'              => -10, // negativo = recargo del 10%
            'user_id'                          => $user->id,
        ]);

        return ['con_recargo' => $con_recargo, 'sin_regla' => $sin_regla];
    }

    /**
     * Fila del desglose de un metodo de pago, o null si el desglose no lo trae.
     *
     * @param  array $precios_por_metodo_pago
     * @param  int   $metodo_id
     * @return array|null
     */
    private function fila_del_metodo($precios_por_metodo_pago, $metodo_id)
    {
        foreach ($precios_por_metodo_pago['precios_por_metodo'] as $fila) {
            if ((int) $fila['current_acount_payment_method_id'] === (int) $metodo_id) {
                return $fila;
            }
        }

        return null;
    }

    /**
     * El corazon de la mision: cada variante sale con SU precio. La que tiene precio propio, el suyo; la
     * que no, el del articulo. (La API ya lo hacia: lo que cambia es que la SPA ahora lo lee. Este test
     * lo fija para que nadie lo rompa del lado de la API.)
     *
     * @group sales
     * @group vender-scan
     * @test
     */
    public function cada_variante_trae_su_precio_propio_o_el_del_articulo()
    {
        $user = $this->usuario_de_test('s1');
        $this->dar_extension($user, 'article_variants');
        $this->actingAs($user, 'web');

        $zapatilla = $this->articulo($user, 'Zapatilla', '1110002');
        $con_precio = $this->variante($zapatilla, 'azul 35', '7790001', 250);
        $sin_precio = $this->variante($zapatilla, 'azul 36', '7790002', null);

        $precio_del_articulo = Article::find($zapatilla->id)->final_price;
        $this->assertNotNull($precio_del_articulo);
        $this->assertNotEquals(250, $precio_del_articulo, 'El caso no distingue nada si el articulo ya vale 250.');

        $variantes = $this->variantes_por_id($this->escanear('1110002'));

        $this->assertCount(2, $variantes);
        $this->assertEquals(250, $variantes[$con_precio->id]['final_price'], 'La variante con precio propio manda el suyo.');
        $this->assertEquals($precio_del_articulo, $variantes[$sin_precio->id]['final_price'], 'La variante sin precio propio usa el del articulo.');
    }

    /**
     * Un precio propio de 0 es un precio propio (igual que en la busqueda por nombre y en el escaneo del
     * codigo de la variante): no se confunde con "no tiene" y no cae al del articulo. Y el desglose no
     * divide por cero.
     *
     * @group sales
     * @group vender-scan
     * @test
     */
    public function una_variante_con_precio_propio_cero_vale_cero_y_no_el_del_articulo()
    {
        $user = $this->usuario_de_test('s2');
        $this->dar_extension($user, 'article_variants');
        $this->actingAs($user, 'web');

        $metodos = $this->activar_recargo_de_tarjeta($user);

        $zapatilla = $this->articulo($user, 'Zapatilla', '1110002');
        $gratis = $this->variante($zapatilla, 'azul 35', '7790001', 0);

        $variante = $this->variantes_por_id($this->escanear('1110002'))[$gratis->id];

        $this->assertEquals(0, $variante['final_price'], 'Un precio propio de 0 es un precio propio.');

        $fila = $this->fila_del_metodo($variante['precios_por_metodo_pago'], $metodos['sin_regla']->id);
        $this->assertNotNull($fila);
        $this->assertEquals(0, $fila['price']);
        $this->assertEquals(0, $fila['discount_percentage_vs_etiqueta'], 'Con precio 0 el porcentaje es 0 (sin dividir por cero).');
    }

    /**
     * La clave nueva esta en TODAS las variantes. Sin `precio_base_incluye_tarjeta` en el usuario
     * (el caso de casi todos los comercios) vale `null`: el mismo valor que ya devuelven la busqueda
     * por nombre y el escaneo de una variante.
     *
     * @group sales
     * @group vender-scan
     * @test
     */
    public function cada_variante_trae_precios_por_metodo_pago_y_es_null_sin_el_flag_de_tarjeta()
    {
        $user = $this->usuario_de_test('s3');
        $this->dar_extension($user, 'article_variants');
        $this->actingAs($user, 'web');

        $zapatilla = $this->articulo($user, 'Zapatilla', '1110002');
        $this->variante($zapatilla, 'azul 35', '7790001', 250);
        $this->variante($zapatilla, 'azul 36', '7790002', null);

        $body = $this->escanear('1110002');

        $this->assertCount(2, $body['variants']);

        foreach ($body['variants'] as $variante) {
            $this->assertArrayHasKey('precios_por_metodo_pago', $variante, 'Cada variante trae la clave nueva.');
            $this->assertNull($variante['precios_por_metodo_pago'], 'Sin precio_base_incluye_tarjeta el desglose es null.');
        }
    }

    /**
     * Con el flag de tarjeta prendido y un recargo configurado, el `price` de cada metodo sale del
     * `final_price` DE LA VARIANTE y no del articulo: dos variantes con precios distintos dan desgloses
     * distintos. El porcentaje contra la etiqueta, en cambio, es el mismo (no depende del precio).
     *
     * @group sales
     * @group vender-scan
     * @test
     */
    public function el_desglose_por_metodo_de_cada_variante_sale_del_precio_de_esa_variante()
    {
        $user = $this->usuario_de_test('s4');
        $this->dar_extension($user, 'article_variants');
        $this->actingAs($user, 'web');

        $metodos = $this->activar_recargo_de_tarjeta($user);

        $zapatilla = $this->articulo($user, 'Zapatilla', '1110002');
        $cara  = $this->variante($zapatilla, 'azul 35', '7790001', 250);
        $comun = $this->variante($zapatilla, 'azul 36', '7790002', null);

        $precio_del_articulo = (float) Article::find($zapatilla->id)->final_price;
        $this->assertGreaterThan(0, $precio_del_articulo);

        $variantes = $this->variantes_por_id($this->escanear('1110002'));

        $casos = [
            [$cara->id,  250.0],
            [$comun->id, $precio_del_articulo],
        ];

        $porcentajes = [];

        foreach ($casos as $caso) {
            list($variant_id, $precio_esperado) = $caso;

            $desglose = $variantes[$variant_id]['precios_por_metodo_pago'];

            $this->assertNotNull($desglose, 'Con el flag prendido y un recargo configurado hay desglose.');
            $this->assertEquals(10, $desglose['recargo_max_percentage'], 'El recargo maximo es el 10% configurado.');

            // El metodo con recargo del 10% paga la etiqueta tal cual: la de ESTA variante.
            $con_recargo = $this->fila_del_metodo($desglose, $metodos['con_recargo']->id);
            $this->assertNotNull($con_recargo);
            $this->assertEqualsWithDelta($precio_esperado, (float) $con_recargo['price'], 0.01);
            $this->assertEquals(0, $con_recargo['discount_percentage_vs_etiqueta']);

            // El metodo sin regla paga etiqueta / 1,10 (mismo calculo que el helper, escrito aparte).
            $sin_regla = $this->fila_del_metodo($desglose, $metodos['sin_regla']->id);
            $this->assertNotNull($sin_regla);
            $this->assertEqualsWithDelta(round($precio_esperado / 1.10, 2), (float) $sin_regla['price'], 0.01);

            $porcentajes[] = $sin_regla['discount_percentage_vs_etiqueta'];
        }

        // Dos variantes con precios distintos -> desgloses con precios distintos...
        $desglose_cara  = $variantes[$cara->id]['precios_por_metodo_pago'];
        $desglose_comun = $variantes[$comun->id]['precios_por_metodo_pago'];

        $this->assertNotEquals(
            $this->fila_del_metodo($desglose_cara, $metodos['sin_regla']->id)['price'],
            $this->fila_del_metodo($desglose_comun, $metodos['sin_regla']->id)['price'],
            'Si los precios de las variantes son distintos, el desglose tiene que ser distinto.'
        );

        // ...pero el porcentaje contra la etiqueta es el mismo en las dos (1 - 1/1,10 = 9,09%).
        $this->assertEquals($porcentajes[0], $porcentajes[1]);
        $this->assertEqualsWithDelta(9.09, (float) $porcentajes[0], 0.01);
    }

    /**
     * Las claves de siempre siguen y no se suma ninguna otra: el conjunto de claves de cada variante es
     * exactamente las 7 de hoy mas `precios_por_metodo_pago`. `article` y `has_variants` no cambian de
     * forma y la respuesta NO trae `variant_row` (esa clave es solo del escaneo del codigo de una
     * variante). `final_price` sigue valiendo lo mismo que antes.
     *
     * @group sales
     * @group vender-scan
     * @test
     */
    public function las_claves_de_siempre_siguen_y_la_respuesta_conserva_su_forma()
    {
        $user = $this->usuario_de_test('s5');
        $this->dar_extension($user, 'article_variants');
        $this->actingAs($user, 'web');

        $zapatilla = $this->articulo($user, 'Zapatilla', '1110002');
        $azul_35 = $this->variante($zapatilla, 'azul 35', '7790001', 250);
        $this->variante($zapatilla, 'azul 36', '7790002', null);

        $body = $this->escanear('1110002');

        // Respuesta: article + has_variants + variants, y nada de variant_row / variant / variant_id.
        $this->assertEquals($zapatilla->id, $body['article']['id']);
        $this->assertTrue($body['has_variants']);
        $this->assertArrayNotHasKey('variant_row', $body);
        $this->assertArrayNotHasKey('variant_id', $body);
        $this->assertArrayNotHasKey('variant', $body);

        foreach (['id', 'name', 'images', 'article_variants'] as $clave) {
            $this->assertArrayHasKey($clave, $body['article'], 'A `article` le falta la clave ' . $clave);
        }

        // Cada variante: el conjunto exacto de claves.
        $esperadas = $this->claves_de_una_variante;
        sort($esperadas);

        foreach ($body['variants'] as $variante) {
            $claves = array_keys($variante);
            sort($claves);

            $this->assertEquals($esperadas, $claves, 'Las claves de cada variante cambiaron.');
        }

        // Y los valores de siempre.
        $variante = $this->variantes_por_id($body)[$azul_35->id];

        $this->assertEquals('azul 35', $variante['variant_description']);
        $this->assertEquals('7790001', $variante['bar_code']);
        $this->assertEquals(250, $variante['final_price']);
        $this->assertEquals(false, $variante['oculta']);
        $this->assertIsArray($variante['images']);
        $this->assertIsArray($variante['addresses']);
    }

    /**
     * Las variantes ocultas siguen sin ofrecerse (comportamiento de hoy): ni en la lista ni, claro, con
     * desglose.
     *
     * @group sales
     * @group vender-scan
     * @test
     */
    public function las_variantes_ocultas_no_se_ofrecen()
    {
        $user = $this->usuario_de_test('s6');
        $this->dar_extension($user, 'article_variants');
        $this->actingAs($user, 'web');

        $zapatilla = $this->articulo($user, 'Zapatilla', '1110002');
        $visible = $this->variante($zapatilla, 'azul 35', '7790001', 250);
        $this->variante($zapatilla, 'rojo 36', '7790003', 300, true); // oculta: no se ofrece

        $body = $this->escanear('1110002');

        $this->assertTrue($body['has_variants']);
        $this->assertCount(1, $body['variants']);
        $this->assertEquals($visible->id, $body['variants'][0]['variant_id']);
    }

    /**
     * Un articulo SIN variantes disponibles sigue devolviendo `has_variants: false` y sin `variants`,
     * tanto si no tiene ninguna variante como si todas las que tiene estan ocultas.
     *
     * @group sales
     * @group vender-scan
     * @test
     */
    public function un_articulo_sin_variantes_disponibles_sigue_devolviendo_has_variants_false()
    {
        $user = $this->usuario_de_test('s7');
        $this->dar_extension($user, 'article_variants');
        $this->actingAs($user, 'web');

        $clavo = $this->articulo($user, 'Clavo', '1110001');
        $oculto = $this->articulo($user, 'Tuerca', '1110003');
        $this->variante($oculto, 'chica', '7790009', 50, true);

        foreach ([[$clavo, '1110001'], [$oculto, '1110003']] as $caso) {
            list($articulo, $codigo) = $caso;

            $body = $this->escanear($codigo);

            $this->assertEquals($articulo->id, $body['article']['id']);
            $this->assertFalse($body['has_variants']);
            $this->assertArrayNotHasKey('variants', $body);
            $this->assertArrayNotHasKey('variant_row', $body);
        }
    }
}
