<?php

namespace Tests\Feature\Stock;

use App\Models\Article;
use App\Models\ArticleVariant;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Mision "codigo-de-barras-de-variantes" (3/10/2026): guardar el codigo de barras de una variante
 * desde el modal de Variantes.
 *
 * Contrato C1 (plan de la mision): `PUT article-variant/{id}` acepta `bar_code` OPCIONAL.
 *   - Si la clave no viene, el codigo no se toca (la SPA vieja manda solo price, image_url y oculta).
 *   - Reglas, en este orden y ANTES de modificar nada: trim; vacio -> se restituye '0' + id; mas de
 *     20 caracteres -> error; igual al codigo de OTRA variante del mismo duenio (ocultas incluidas)
 *     -> error; igual al codigo de un ARTICULO del mismo duenio -> error.
 *   - Exito: 200 con `model`, como siempre. Error: 422 con `message` en castellano y SIN guardar
 *     nada (ni el precio ni `oculta`).
 *
 * Los tests le pegan al endpoint real, con usuarios frescos (un comercio por test, mas un segundo
 * comercio donde hace falta) dentro de una transaccion que se revierte.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promocion de constructor, readonly, enum ni #[...].
 */
class Variantes_codigo_de_barras_Test extends TestCase
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
            'name'     => 'Comercio codigo variantes ' . $sufijo,
            'email'    => 'codigo-variantes-' . $sufijo . '-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);
    }

    /**
     * Prende una extension para un usuario (mismo patron que el test 40 de Sales).
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
     * @param  array            $extra
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
     * Variante creada como la crea el generador: nace con '0' + id.
     *
     * @param  \App\Models\Article $article
     * @param  string              $descripcion
     * @param  bool                $oculta
     * @param  int|null            $price
     * @return \App\Models\ArticleVariant
     */
    private function variante($article, $descripcion, $oculta = false, $price = null)
    {
        $variante = ArticleVariant::create([
            'article_id'          => $article->id,
            'variant_description' => $descripcion,
            'oculta'              => $oculta,
            'price'               => $price,
        ]);

        $variante->bar_code = '0' . $variante->id;
        $variante->save();

        return $variante;
    }

    /**
     * Codigo guardado en la base (nunca el del modelo en memoria).
     *
     * @param  \App\Models\ArticleVariant $variante
     * @return string|null
     */
    private function codigo_guardado($variante)
    {
        return DB::table('article_variants')->where('id', $variante->id)->value('bar_code');
    }

    /**
     * PUT de la grilla de variantes, con la forma que manda la SPA.
     *
     * @param  \App\Models\ArticleVariant $variante
     * @param  array                      $extra  Claves a sumar o pisar.
     * @return \Illuminate\Testing\TestResponse
     */
    private function guardar($variante, $extra = [])
    {
        return $this->putJson('api/article-variant/' . $variante->id, array_merge([
            'price'     => null,
            'image_url' => null,
            'oculta'    => false,
        ], $extra));
    }

    /**
     * Un codigo nuevo se persiste y vuelve en la respuesta.
     *
     * @group stock
     * @test
     */
    public function guardar_un_codigo_nuevo_lo_persiste_y_lo_devuelve()
    {
        $user = $this->usuario_de_test('g1');
        $this->actingAs($user, 'web');

        $variante = $this->variante($this->articulo($user, 'Zapatilla'), 'azul 36');

        $res = $this->guardar($variante, ['bar_code' => '7790002']);

        $res->assertStatus(200);
        $this->assertEquals('7790002', $res->json('model.bar_code'));
        $this->assertEquals('7790002', $this->codigo_guardado($variante));

        // La respuesta conserva la forma de siempre (la grilla reemplaza la variante con ella).
        $this->assertArrayHasKey('addresses', $res->json('model'));
        $this->assertArrayHasKey('article_property_values', $res->json('model'));
    }

    /**
     * El codigo se recorta con trim. Un codigo con ceros a la izquierda se guarda tal cual (es un
     * texto, no un numero).
     *
     * @group stock
     * @test
     */
    public function el_codigo_se_recorta_y_conserva_los_ceros_a_la_izquierda()
    {
        $user = $this->usuario_de_test('g2');
        $this->actingAs($user, 'web');

        $variante = $this->variante($this->articulo($user, 'Zapatilla'), 'azul 36');

        $res = $this->guardar($variante, ['bar_code' => "  \t0077900021  "]);

        $res->assertStatus(200);
        $this->assertSame('0077900021', $res->json('model.bar_code'));
        $this->assertSame('0077900021', $this->codigo_guardado($variante));
    }

    /**
     * Vaciar el campo restituye el codigo por defecto '0' + id (el que le pone el generador). Vale
     * para vacio, para solo espacios y para null.
     *
     * @group stock
     * @test
     */
    public function un_codigo_vacio_restituye_el_cero_mas_id()
    {
        $user = $this->usuario_de_test('g3');
        $this->actingAs($user, 'web');

        $variante = $this->variante($this->articulo($user, 'Zapatilla'), 'azul 36');
        $por_defecto = '0' . $variante->id;

        foreach (['', '   ', null] as $vacio) {
            $this->guardar($variante, ['bar_code' => '7790002'])->assertStatus(200);
            $this->assertEquals('7790002', $this->codigo_guardado($variante));

            $res = $this->guardar($variante, ['bar_code' => $vacio]);

            $res->assertStatus(200);
            $this->assertSame($por_defecto, $res->json('model.bar_code'));
            $this->assertSame($por_defecto, $this->codigo_guardado($variante));
        }
    }

    /**
     * Compatibilidad con la SPA vieja: si la clave `bar_code` no viene, el codigo no se toca aunque
     * cambien el precio y la disponibilidad.
     *
     * @group stock
     * @test
     */
    public function sin_la_clave_bar_code_el_codigo_no_cambia_aunque_cambie_el_precio()
    {
        $user = $this->usuario_de_test('g4');
        $this->actingAs($user, 'web');

        $variante = $this->variante($this->articulo($user, 'Zapatilla'), 'azul 36', true);
        $this->guardar($variante, ['bar_code' => '7790002', 'oculta' => true])->assertStatus(200);

        $res = $this->putJson('api/article-variant/' . $variante->id, [
            'price'     => 250,
            'image_url' => null,
            'oculta'    => false,
        ]);

        $res->assertStatus(200);
        $this->assertEquals(250, $res->json('model.price'));
        $this->assertEquals('7790002', $res->json('model.bar_code'));
        $this->assertEquals('7790002', $this->codigo_guardado($variante));
        $this->assertEquals(250, DB::table('article_variants')->where('id', $variante->id)->value('price'));
        $this->assertEquals(0, DB::table('article_variants')->where('id', $variante->id)->value('oculta'));
    }

    /**
     * Repetido con OTRA variante del mismo duenio: 422 con mensaje y NADA guardado (ni el precio ni
     * `oculta` ni el codigo). Tambien cuenta una variante oculta de otro articulo del duenio.
     *
     * @group stock
     * @test
     */
    public function repetido_con_otra_variante_del_duenio_da_422_y_no_guarda_nada()
    {
        $user = $this->usuario_de_test('g5');
        $this->actingAs($user, 'web');

        $zapatilla = $this->articulo($user, 'Zapatilla');
        $remera = $this->articulo($user, 'Remera');

        $azul_35 = $this->variante($zapatilla, 'azul 35');
        $azul_36 = $this->variante($zapatilla, 'azul 36', true, 100);
        $remera_negra = $this->variante($remera, 'negra', true); // oculta y de otro articulo

        $this->guardar($azul_35, ['bar_code' => '7790001'])->assertStatus(200);
        $this->guardar($remera_negra, ['bar_code' => '7790009', 'oculta' => true])->assertStatus(200);

        foreach (['7790001', '7790009'] as $repetido) {
            $res = $this->guardar($azul_36, [
                'bar_code' => $repetido,
                'price'    => 999,
                'oculta'   => false,
            ]);

            $res->assertStatus(422);

            $mensaje = $res->json('message');
            $this->assertIsString($mensaje);
            $this->assertNotSame('', trim($mensaje));

            $fila = DB::table('article_variants')->where('id', $azul_36->id)->first();
            $this->assertEquals('0' . $azul_36->id, $fila->bar_code, 'No se tiene que haber guardado el codigo.');
            $this->assertEquals(100, $fila->price, 'No se tiene que haber guardado el precio.');
            $this->assertEquals(1, $fila->oculta, 'No se tiene que haber guardado la disponibilidad.');
        }

        // El mensaje nombra con quien choca, para que el comerciante sepa que corregir.
        $res = $this->guardar($azul_36, ['bar_code' => '7790001']);
        $this->assertStringContainsString('azul 35', $res->json('message'));
    }

    /**
     * Repetido con el codigo de un ARTICULO del mismo duenio: 422 y nada guardado. Incluye el
     * articulo de la propia variante: escanear ese codigo devolveria la variante y el articulo
     * quedaria inescaneable.
     *
     * @group stock
     * @test
     */
    public function repetido_con_un_articulo_del_duenio_da_422_y_no_guarda_nada()
    {
        $user = $this->usuario_de_test('g6');
        $this->actingAs($user, 'web');

        $zapatilla = $this->articulo($user, 'Zapatilla', ['bar_code' => '1110002']);
        $clavo = $this->articulo($user, 'Clavo', ['bar_code' => '1110001']);
        $variante = $this->variante($zapatilla, 'azul 36', false, 100);

        foreach (['1110001', '1110002'] as $repetido) {
            $res = $this->guardar($variante, [
                'bar_code' => $repetido,
                'price'    => 999,
            ]);

            $res->assertStatus(422);
            $this->assertIsString($res->json('message'));

            $fila = DB::table('article_variants')->where('id', $variante->id)->first();
            $this->assertEquals('0' . $variante->id, $fila->bar_code);
            $this->assertEquals(100, $fila->price);
        }

        $this->assertStringContainsString('Clavo', $this->guardar($variante, ['bar_code' => '1110001'])->json('message'));
    }

    /**
     * El codigo de OTRO duenio no cuenta como repetido: los codigos son personalizados por
     * comercio y comparten base. Vale para variantes y para articulos ajenos.
     *
     * @group stock
     * @test
     */
    public function el_codigo_de_otro_duenio_se_acepta()
    {
        $otro = $this->usuario_de_test('g7b');
        $articulo_ajeno = $this->articulo($otro, 'Zapatilla ajena', ['bar_code' => '1110001']);
        $variante_ajena = $this->variante($articulo_ajeno, 'azul 35');
        $variante_ajena->bar_code = '7790001';
        $variante_ajena->save();

        $user = $this->usuario_de_test('g7a');
        $this->actingAs($user, 'web');

        $variante = $this->variante($this->articulo($user, 'Zapatilla'), 'azul 36');

        $this->guardar($variante, ['bar_code' => '7790001'])->assertStatus(200);
        $this->assertEquals('7790001', $this->codigo_guardado($variante));

        $this->guardar($variante, ['bar_code' => '1110001'])->assertStatus(200);
        $this->assertEquals('1110001', $this->codigo_guardado($variante));
    }

    /**
     * Un articulo dado de baja (soft delete) no se puede escanear, asi que su codigo no bloquea.
     *
     * @group stock
     * @test
     */
    public function el_codigo_de_un_articulo_dado_de_baja_no_bloquea()
    {
        $user = $this->usuario_de_test('g8');
        $this->actingAs($user, 'web');

        $baja = $this->articulo($user, 'Clavo viejo', ['bar_code' => '1110001']);
        $baja->delete();

        $variante = $this->variante($this->articulo($user, 'Zapatilla'), 'azul 36');

        $this->guardar($variante, ['bar_code' => '1110001'])->assertStatus(200);
        $this->assertEquals('1110001', $this->codigo_guardado($variante));
    }

    /**
     * Mas de 20 caracteres (el largo de la columna) -> 422 y nada guardado; con justo 20 se acepta.
     *
     * @group stock
     * @test
     */
    public function mas_de_veinte_caracteres_da_422()
    {
        $user = $this->usuario_de_test('g9');
        $this->actingAs($user, 'web');

        $variante = $this->variante($this->articulo($user, 'Zapatilla'), 'azul 36', false, 100);

        $res = $this->guardar($variante, [
            'bar_code' => str_repeat('7', 21),
            'price'    => 999,
        ]);

        $res->assertStatus(422);
        $this->assertIsString($res->json('message'));
        $this->assertEquals('0' . $variante->id, $this->codigo_guardado($variante));
        $this->assertEquals(100, DB::table('article_variants')->where('id', $variante->id)->value('price'));

        $justo = str_repeat('7', 20);
        $this->guardar($variante, ['bar_code' => $justo])->assertStatus(200);
        $this->assertSame($justo, $this->codigo_guardado($variante));
    }

    /**
     * Escribir el mismo codigo que ya tiene la propia variante no es un repetido.
     *
     * @group stock
     * @test
     */
    public function escribir_el_mismo_codigo_que_ya_tiene_la_variante_da_200()
    {
        $user = $this->usuario_de_test('g10');
        $this->actingAs($user, 'web');

        $variante = $this->variante($this->articulo($user, 'Zapatilla'), 'azul 36');

        // Con el '0' + id que le puso el generador...
        $this->guardar($variante, ['bar_code' => '0' . $variante->id])->assertStatus(200);
        $this->assertEquals('0' . $variante->id, $this->codigo_guardado($variante));

        // ...y con uno personalizado, dos veces seguidas.
        $this->guardar($variante, ['bar_code' => '7790002'])->assertStatus(200);
        $this->guardar($variante, ['bar_code' => '7790002'])->assertStatus(200);
        $this->assertEquals('7790002', $this->codigo_guardado($variante));
    }

    /**
     * Un valor que no es texto ni numero (un arreglo) no revienta con un 500: es un 422.
     *
     * @group stock
     * @test
     */
    public function un_valor_que_no_es_texto_da_422_y_no_revienta()
    {
        $user = $this->usuario_de_test('g11');
        $this->actingAs($user, 'web');

        $variante = $this->variante($this->articulo($user, 'Zapatilla'), 'azul 36');

        $res = $this->guardar($variante, ['bar_code' => ['7790002']]);

        $res->assertStatus(422);
        $this->assertIsString($res->json('message'));
        $this->assertEquals('0' . $variante->id, $this->codigo_guardado($variante));
    }

    /**
     * Sin las extensiones de escaneo por numero interno ni por codigo de proveedor, el unico codigo
     * de un articulo que cuenta es su `bar_code` (la cadena de `search_bar_code` tampoco mira otra
     * cosa): un codigo igual a su `num` o a su `provider_code` se acepta.
     *
     * @group stock
     * @test
     */
    public function sin_las_extensiones_un_codigo_igual_al_num_o_al_codigo_de_proveedor_se_acepta()
    {
        $user = $this->usuario_de_test('g12');
        $this->actingAs($user, 'web');

        $this->articulo($user, 'Clavo', ['num' => 555, 'provider_code' => 'ABC-100']);
        $variante = $this->variante($this->articulo($user, 'Zapatilla'), 'azul 36');

        $this->guardar($variante, ['bar_code' => '555'])->assertStatus(200);
        $this->assertEquals('555', $this->codigo_guardado($variante));

        $this->guardar($variante, ['bar_code' => 'abc-100'])->assertStatus(200);
        $this->assertEquals('abc-100', $this->codigo_guardado($variante));
    }

    /**
     * Con `codigos_de_barra_basados_en_numero_interno` el codigo de un articulo es su `num`: una
     * variante con ese mismo numero lo taparia en el escaneo (la variante se resuelve primero), asi
     * que es un repetido. 422, nada guardado y un mensaje que dice QUE campo choca.
     *
     * La comparacion es de entero canonico, no el cast flojo de MySQL (`num = '0555'` da true para
     * el 555): '0555' no es el numero 555 tal como lo escanea nadie, y el articulo sigue
     * escaneandose por '555'. Tampoco chocan los codigos con letras, el num de otro duenio ni el de
     * un articulo dado de baja.
     *
     * @group stock
     * @test
     */
    public function con_numero_interno_un_codigo_igual_al_num_de_un_articulo_del_duenio_da_422()
    {
        $otro = $this->usuario_de_test('g13b');
        $this->articulo($otro, 'Tuerca ajena', ['num' => 777]);

        $user = $this->usuario_de_test('g13a');
        $this->dar_extension($user, 'codigos_de_barra_basados_en_numero_interno');
        $this->actingAs($user, 'web');

        $this->articulo($user, 'Clavo', ['num' => 555]);
        $baja = $this->articulo($user, 'Clavo viejo', ['num' => 888]);
        $baja->delete();

        $variante = $this->variante($this->articulo($user, 'Zapatilla'), 'azul 36', false, 100);

        $res = $this->guardar($variante, ['bar_code' => '555', 'price' => 999]);

        $res->assertStatus(422);
        $this->assertStringContainsString('Clavo', $res->json('message'));
        $this->assertStringContainsString('número interno', $res->json('message'));
        $this->assertEquals('0' . $variante->id, $this->codigo_guardado($variante), 'No se tiene que haber guardado el codigo.');
        $this->assertEquals(100, DB::table('article_variants')->where('id', $variante->id)->value('price'), 'No se tiene que haber guardado el precio.');

        foreach (['0555', 'A555', '777', '888'] as $aceptado) {
            $this->guardar($variante, ['bar_code' => $aceptado])->assertStatus(200);
            $this->assertEquals($aceptado, $this->codigo_guardado($variante));
        }
    }

    /**
     * Con `codigo_proveedor_en_vender` el codigo de un articulo es su `provider_code`, y el scanner
     * de la SPA lo compara sin distinguir mayusculas: 'abc-100' choca con 'ABC-100'. Mensaje
     * distinto al del numero interno. El codigo de proveedor de otro duenio no cuenta.
     *
     * @group stock
     * @test
     */
    public function con_codigo_de_proveedor_un_codigo_igual_al_provider_code_de_un_articulo_del_duenio_da_422()
    {
        $otro = $this->usuario_de_test('g14b');
        $this->articulo($otro, 'Tuerca ajena', ['provider_code' => 'XYZ-9']);

        $user = $this->usuario_de_test('g14a');
        $this->dar_extension($user, 'codigo_proveedor_en_vender');
        $this->actingAs($user, 'web');

        $this->articulo($user, 'Clavo', ['provider_code' => 'ABC-100']);

        $variante = $this->variante($this->articulo($user, 'Zapatilla'), 'azul 36', false, 100);

        foreach (['ABC-100', 'abc-100'] as $repetido) {
            $res = $this->guardar($variante, ['bar_code' => $repetido, 'price' => 999]);

            $res->assertStatus(422);
            $this->assertStringContainsString('Clavo', $res->json('message'));
            $this->assertStringContainsString('proveedor', $res->json('message'));
            $this->assertStringNotContainsString('número interno', $res->json('message'));
            $this->assertEquals('0' . $variante->id, $this->codigo_guardado($variante));
            $this->assertEquals(100, DB::table('article_variants')->where('id', $variante->id)->value('price'));
        }

        $this->guardar($variante, ['bar_code' => 'xyz-9'])->assertStatus(200);
        $this->assertEquals('xyz-9', $this->codigo_guardado($variante));

        // Y un numero igual al num de un articulo no choca: el modo de este duenio es el codigo de proveedor.
        $this->articulo($user, 'Tornillo', ['num' => 321]);
        $this->guardar($variante, ['bar_code' => '321'])->assertStatus(200);
    }

    /**
     * El guardado normaliza igual que el lector: la SPA, al escanear, saca TODO el espacio en blanco
     * del codigo (`code.replace(/\s+/g, '')`). Un "AB 12" guardado tal cual se buscaria como "AB12"
     * y no matchearia nunca. La respuesta ya devuelve el codigo normalizado (la SPA repone el input
     * con eso).
     *
     * @group stock
     * @test
     */
    public function un_codigo_con_espacios_se_guarda_sin_ellos_como_lo_busca_el_lector()
    {
        $user = $this->usuario_de_test('g15');
        $this->actingAs($user, 'web');

        $variante = $this->variante($this->articulo($user, 'Zapatilla'), 'azul 36');

        $res = $this->guardar($variante, ['bar_code' => 'AB 12']);

        $res->assertStatus(200);
        $this->assertSame('AB12', $res->json('model.bar_code'));
        $this->assertSame('AB12', $this->codigo_guardado($variante));
    }

    /**
     * Se sacan los espacios internos de todos los tipos: espacio doble, tab, salto de linea y el
     * espacio no cortable (que los middlewares de Laravel no recortan y un trim() tampoco).
     *
     * @group stock
     * @test
     */
    public function se_sacan_los_espacios_internos_de_todos_los_tipos()
    {
        $user = $this->usuario_de_test('g16');
        $this->actingAs($user, 'web');

        $variante = $this->variante($this->articulo($user, 'Zapatilla'), 'azul 36');

        $res = $this->guardar($variante, ['bar_code' => "A\tB  C\u{00A0}D\n1"]);

        $res->assertStatus(200);
        $this->assertSame('ABCD1', $res->json('model.bar_code'));
        $this->assertSame('ABCD1', $this->codigo_guardado($variante));
    }

    /**
     * Lo que se compara por repetido es el codigo ya normalizado: "AB 12" es "AB12" para el lector,
     * asi que choca con otra variante que tiene "AB12" (escribir el espacio no esquiva la regla).
     *
     * @group stock
     * @test
     */
    public function el_codigo_normalizado_es_el_que_se_compara_por_repetido()
    {
        $user = $this->usuario_de_test('g17');
        $this->actingAs($user, 'web');

        $zapatilla = $this->articulo($user, 'Zapatilla');
        $azul_35 = $this->variante($zapatilla, 'azul 35');
        $azul_36 = $this->variante($zapatilla, 'azul 36', false, 100);

        $this->guardar($azul_35, ['bar_code' => 'AB12'])->assertStatus(200);

        $res = $this->guardar($azul_36, ['bar_code' => 'AB 12', 'price' => 999]);

        $res->assertStatus(422);
        $this->assertStringContainsString('azul 35', $res->json('message'));
        $this->assertEquals('0' . $azul_36->id, $this->codigo_guardado($azul_36));
        $this->assertEquals(100, DB::table('article_variants')->where('id', $azul_36->id)->value('price'));
    }

    /**
     * `/`, `\`, `?`, `#` y `%` rompen la ruta del escaneo (el codigo viaja en la URL sin escapar:
     * dan 404 o se truncan), asi que un codigo con alguno se guardaria bien y no se podria escanear
     * nunca. 422 con un mensaje que dice cuales no se pueden usar, y NADA guardado.
     *
     * @group stock
     * @test
     */
    public function los_caracteres_que_rompen_la_ruta_del_escaneo_dan_422_y_no_guardan_nada()
    {
        $user = $this->usuario_de_test('g18');
        $this->actingAs($user, 'web');

        $variante = $this->variante($this->articulo($user, 'Zapatilla'), 'azul 36', true, 100);

        foreach (['/', '\\', '?', '#', '%'] as $caracter) {
            $res = $this->guardar($variante, [
                'bar_code' => 'AB' . $caracter . '12',
                'price'    => 999,
                'oculta'   => false,
            ]);

            $res->assertStatus(422);
            $this->assertStringContainsString($caracter, $res->json('message'), 'El mensaje tiene que nombrar el caracter ' . $caracter);

            $fila = DB::table('article_variants')->where('id', $variante->id)->first();
            $this->assertEquals('0' . $variante->id, $fila->bar_code, 'No se tiene que haber guardado el codigo con ' . $caracter);
            $this->assertEquals(100, $fila->price, 'No se tiene que haber guardado el precio.');
            $this->assertEquals(1, $fila->oculta, 'No se tiene que haber guardado la disponibilidad.');
        }
    }

    /**
     * Un codigo que despues de normalizar queda vacio (solo espacios de cualquier tipo, incluido el
     * no cortable que ningun recorte de costados saca) restituye '0' + id, como el campo vacio.
     *
     * @group stock
     * @test
     */
    public function un_codigo_de_solo_espacios_de_cualquier_tipo_restituye_el_cero_mas_id()
    {
        $user = $this->usuario_de_test('g19');
        $this->actingAs($user, 'web');

        $variante = $this->variante($this->articulo($user, 'Zapatilla'), 'azul 36');
        $por_defecto = '0' . $variante->id;

        foreach (["\t \n", "\u{00A0}", " \u{00A0} \t"] as $solo_espacios) {
            $this->guardar($variante, ['bar_code' => '7790002'])->assertStatus(200);

            $res = $this->guardar($variante, ['bar_code' => $solo_espacios]);

            $res->assertStatus(200);
            $this->assertSame($por_defecto, $res->json('model.bar_code'));
            $this->assertSame($por_defecto, $this->codigo_guardado($variante));
        }
    }

    /**
     * Un valor con bytes que no son UTF-8 valido es un 422 con mensaje, no un 500 ni un vacio que
     * restituya el codigo por defecto (`preg_replace` devuelve null). Va como formulario porque un
     * JSON con bytes invalidos ni se decodifica.
     *
     * @group stock
     * @test
     */
    public function un_valor_con_bytes_invalidos_da_422_y_no_revienta()
    {
        $user = $this->usuario_de_test('g20');
        $this->actingAs($user, 'web');

        $variante = $this->variante($this->articulo($user, 'Zapatilla'), 'azul 36', false, 100);

        $res = $this->withHeaders(['Accept' => 'application/json'])
                    ->put('api/article-variant/' . $variante->id, [
                        'price'     => 999,
                        'image_url' => null,
                        'oculta'    => 0,
                        'bar_code'  => "AB\xB112",
                    ]);

        $res->assertStatus(422);
        $this->assertIsString($res->json('message'));

        $fila = DB::table('article_variants')->where('id', $variante->id)->first();
        $this->assertEquals('0' . $variante->id, $fila->bar_code);
        $this->assertEquals(100, $fila->price);
    }

    /**
     * `.` y `..` exactos: el servidor los toma como segmentos de ruta al escanear
     * (`.../buscar-articulo-por-codido/..` se normaliza y no llega al controlador), asi que se
     * guardarian bien y no se podrian escanear. Forman parte de la regla de caracteres de la ruta.
     * Un punto adentro de un codigo ('A.B') o una secuencia mas larga ('...') no es un segmento
     * especial y se acepta.
     *
     * @group stock
     * @test
     */
    public function un_codigo_que_es_solo_punto_o_dos_puntos_da_422_y_no_guarda_nada()
    {
        $user = $this->usuario_de_test('g21');
        $this->actingAs($user, 'web');

        $variante = $this->variante($this->articulo($user, 'Zapatilla'), 'azul 36', true, 100);

        foreach (['.', '..'] as $punto) {
            $res = $this->guardar($variante, ['bar_code' => $punto, 'price' => 999, 'oculta' => false]);

            $res->assertStatus(422);
            $this->assertIsString($res->json('message'));

            $fila = DB::table('article_variants')->where('id', $variante->id)->first();
            $this->assertEquals('0' . $variante->id, $fila->bar_code, 'No se tiene que haber guardado "' . $punto . '".');
            $this->assertEquals(100, $fila->price);
            $this->assertEquals(1, $fila->oculta);
        }

        foreach (['A.B', '...', '.5'] as $aceptado) {
            $this->guardar($variante, ['bar_code' => $aceptado])->assertStatus(200);
            $this->assertEquals($aceptado, $this->codigo_guardado($variante));
        }
    }

    /**
     * `PUT article-variant/{id}` solo opera sobre variantes del DUENIO del usuario logueado. Una
     * variante de otro comercio responde 404 ANTES de tocar nada (ni codigo, ni precio, ni `oculta`)
     * y con un mensaje fijo: si la validacion de repetidos corriera sobre una variante ajena, el 422
     * devolveria el nombre del articulo o la variante del otro comercio (un oraculo de lectura entre
     * comercios).
     *
     * @group stock
     * @test
     */
    public function una_variante_de_otro_duenio_da_404_sin_escribir_nada_y_sin_filtrar_nombres()
    {
        $otro = $this->usuario_de_test('g22b');
        $zapatilla_ajena = $this->articulo($otro, 'Zapatilla secreta ajena');
        $ajena = $this->variante($zapatilla_ajena, 'azul 36 secreta', true, 100);
        $vecina_ajena = $this->variante($zapatilla_ajena, 'rojo 35 secreta');

        // La vecina tiene un codigo propio del catalogo ajeno: con ese mismo codigo en el PUT, un 422
        // de repetido nombraria "Zapatilla secreta ajena rojo 35 secreta".
        DB::table('article_variants')->where('id', $vecina_ajena->id)->update(['bar_code' => 'CODIGO-AJENO']);

        $user = $this->usuario_de_test('g22a');
        $this->actingAs($user, 'web');

        foreach ([
            ['bar_code' => 'CODIGO-AJENO', 'price' => 999, 'oculta' => false],
            ['price' => 999, 'oculta' => false],
            ['bar_code' => '7790002', 'price' => 999, 'oculta' => false],
        ] as $cuerpo) {
            $res = $this->guardar($ajena, $cuerpo);

            $res->assertStatus(404);
            $this->assertSame('No se encontró la variante.', $res->json('message'));
            $this->assertStringNotContainsString('secreta', (string) $res->getContent());

            $fila = DB::table('article_variants')->where('id', $ajena->id)->first();
            $this->assertEquals('0' . $ajena->id, $fila->bar_code, 'No se tiene que haber escrito el codigo ajeno.');
            $this->assertEquals(100, $fila->price, 'No se tiene que haber escrito el precio ajeno.');
            $this->assertEquals(1, $fila->oculta, 'No se tiene que haber escrito la disponibilidad ajena.');
        }
    }

    /**
     * Un id que no existe es un 404 con el mismo mensaje (antes era un 500 por usar el modelo nulo).
     * La variante propia sigue funcionando, tambien cuando su articulo esta dado de baja (el dueno
     * sigue siendo el mismo); la de un articulo dado de baja de OTRO comercio no.
     *
     * @group stock
     * @test
     */
    public function un_id_inexistente_da_404_y_la_variante_propia_sigue_andando()
    {
        $otro = $this->usuario_de_test('g23b');
        $baja_ajena = $this->articulo($otro, 'Articulo ajeno de baja');
        $variante_baja_ajena = $this->variante($baja_ajena, 'azul 36');
        $baja_ajena->delete();

        $user = $this->usuario_de_test('g23a');
        $this->actingAs($user, 'web');

        $res = $this->putJson('api/article-variant/999999999', ['price' => 1, 'image_url' => null, 'oculta' => false]);
        $res->assertStatus(404);
        $this->assertSame('No se encontró la variante.', $res->json('message'));

        $propia = $this->variante($this->articulo($user, 'Zapatilla'), 'azul 36');
        $this->guardar($propia, ['bar_code' => '7790002', 'price' => 250])->assertStatus(200);
        $this->assertEquals('7790002', $this->codigo_guardado($propia));

        $baja_propia = $this->articulo($user, 'Articulo propio de baja');
        $variante_baja_propia = $this->variante($baja_propia, 'rojo 35');
        $baja_propia->delete();
        $this->guardar($variante_baja_propia, ['price' => 300])->assertStatus(200);
        $this->assertEquals(300, DB::table('article_variants')->where('id', $variante_baja_propia->id)->value('price'));

        $this->guardar($variante_baja_ajena, ['price' => 300])->assertStatus(404);
        $this->assertNull(DB::table('article_variants')->where('id', $variante_baja_ajena->id)->value('price'));
    }

    /**
     * Un empleado (usuario con `owner_id`) edita las variantes de su duenio: el dueno que cuenta es
     * el del comercio, no el del usuario logueado.
     *
     * @group stock
     * @test
     */
    public function un_empleado_edita_las_variantes_de_su_duenio()
    {
        $duenio = $this->usuario_de_test('g24');

        $empleado = User::create([
            'name'     => 'Empleado codigo variantes',
            'email'    => 'codigo-variantes-empleado-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $duenio->id,
        ]);

        $variante = $this->variante($this->articulo($duenio, 'Zapatilla'), 'azul 36');

        $this->actingAs($empleado, 'web');

        $res = $this->guardar($variante, ['bar_code' => '7790002', 'price' => 250]);

        $res->assertStatus(200);
        $this->assertEquals('7790002', $this->codigo_guardado($variante));
        $this->assertEquals(250, DB::table('article_variants')->where('id', $variante->id)->value('price'));
    }
}
