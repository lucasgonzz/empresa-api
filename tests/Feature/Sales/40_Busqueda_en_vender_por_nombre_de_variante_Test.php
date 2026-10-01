<?php

namespace Tests\Feature\Sales;

use App\Models\Article;
use App\Models\ArticleVariant;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Mision "busqueda-vender-por-variantes" (1/10/2026).
 *
 * El criterio "zapatilla azul" no encontraba nada en Vender cuando "zapatilla" mostraba las cuatro
 * variantes (azul/rojo x 35/36). Causa: la fase SQL del buscador exigia que CADA palabra apareciera
 * en una propiedad del articulo, y "azul" vive solo en `article_variants.variant_description`; el
 * articulo moria en SQL antes de que `VenderSearchHelper::match_descriptors` (que ya filtra bien
 * las variantes por las palabras restantes) llegara a verlo.
 *
 * Esta suite prueba, con la extension `article_variants` prendida:
 *   - "zapatilla" sigue mostrando las 4 variantes (nada cambia para lo que ya andaba);
 *   - "zapatilla azul" -> las 2 azules; "azul" solo -> tambien; "zapatilla azul 36" -> una sola,
 *     incluso con otras variantes que matchean UNA de las palabras ("rojo 36", "azul 35");
 *   - las variantes ocultas ni se ofrecen ni hacen aparecer al articulo;
 *   - el modo de coincidencia 'todas' sobre `name` tambien las encuentra;
 *   - nada se cuela de otro comercio;
 *   - la ruta vieja (`search_nombre`) y los otros contextos del buscador dan lo mismo.
 * Y, sin la extension, que la busqueda queda EXACTAMENTE como estaba: el SQL ni nombra variantes.
 */
class Busqueda_en_vender_por_nombre_de_variante_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * Usuario de prueba fresco, para no compartir ids con ningun otro test del slot.
     *
     * @param  string $sufijo
     * @return \App\Models\User
     */
    private function usuario_de_test($sufijo)
    {
        return User::create([
            'name'     => 'Comercio variantes ' . $sufijo,
            'email'    => 'busqueda-variantes-' . $sufijo . '-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);
    }

    /**
     * Prende una extension para un usuario (mismo patron que 20_Paginado_Real_...).
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
     * @return \App\Models\Article
     */
    private function articulo($user, $name)
    {
        return Article::create([
            'name'        => $name,
            'user_id'     => $user->id,
            'status'      => 'active',
            'final_price' => 100,
        ]);
    }

    /**
     * Agrega una variante con la descripcion que arma el generador (nombres de los valores unidos
     * por espacio).
     *
     * @param  \App\Models\Article $article
     * @param  string              $descripcion
     * @param  bool                $oculta
     * @param  string|null         $bar_code    Codigo de barras propio de la variante.
     * @return \App\Models\ArticleVariant
     */
    private function variante($article, $descripcion, $oculta = false, $bar_code = null)
    {
        return ArticleVariant::create([
            'article_id'          => $article->id,
            'variant_description' => $descripcion,
            'oculta'              => $oculta,
            'stock'               => 5,
            'bar_code'            => $bar_code,
        ]);
    }

    /**
     * El caso de Lucas: "Zapatilla" con Color (azul, rojo) x Talle (35, 36) = 4 variantes visibles.
     *
     * @param  \App\Models\User $user
     * @return \App\Models\Article
     */
    private function zapatilla($user)
    {
        $zapatilla = $this->articulo($user, 'Zapatilla');

        $this->variante($zapatilla, 'rojo 35');
        $this->variante($zapatilla, 'rojo 36');
        $this->variante($zapatilla, 'azul 35');
        $this->variante($zapatilla, 'azul 36');

        return $zapatilla;
    }

    /**
     * Busca como lo hace el modal de Vender: POST global-search/article con contexto.
     *
     * @param  string $query_value
     * @param  array  $props       Props del buscador general (default: name y provider_code, modo alguna).
     * @param  string $contexto
     * @return array  Respuesta completa decodificada.
     */
    private function buscar($query_value, $props = ['name', 'provider_code'], $contexto = 'vender')
    {
        $res = $this->postJson('api/global-search/article?page=1', [
            'query_value' => $query_value,
            'props'       => $props,
            'contexto'    => $contexto,
            'per_page'    => 50,
        ]);

        $res->assertStatus(200);

        return $res->json();
    }

    /**
     * Nombres de las filas devueltas, ordenados, para comparar sin depender del orden.
     *
     * @param  array $respuesta
     * @return array
     */
    private function nombres($respuesta)
    {
        $nombres = collect($respuesta['models']['data'])->pluck('name')->all();
        sort($nombres);

        return $nombres;
    }

    /**
     * "zapatilla" solo: las 4 variantes, como ya andaba antes de esta mision.
     *
     * @group sales
     * @group vender-search
     * @test
     */
    public function zapatilla_sola_sigue_mostrando_las_cuatro_variantes()
    {
        $user = $this->usuario_de_test('v1');
        $this->dar_extension($user, 'article_variants');
        $this->actingAs($user, 'web');
        $this->zapatilla($user);

        $respuesta = $this->buscar('zapatilla');

        $this->assertEquals(
            ['Zapatilla azul 35', 'Zapatilla azul 36', 'Zapatilla rojo 35', 'Zapatilla rojo 36'],
            $this->nombres($respuesta)
        );
        $this->assertEquals(4, $respuesta['models']['total']);
    }

    /**
     * El pedido de Lucas: "zapatilla azul" -> solo las dos azules (los dos talles).
     *
     * @group sales
     * @group vender-search
     * @test
     */
    public function zapatilla_azul_devuelve_solo_las_dos_variantes_azules()
    {
        $user = $this->usuario_de_test('v2');
        $this->dar_extension($user, 'article_variants');
        $this->actingAs($user, 'web');
        $this->zapatilla($user);

        $respuesta = $this->buscar('zapatilla azul');

        $this->assertEquals(['Zapatilla azul 35', 'Zapatilla azul 36'], $this->nombres($respuesta));
        $this->assertEquals(2, $respuesta['models']['total'], 'El total tambien tiene que ser el real: 2.');
    }

    /**
     * "azul" solo (sin el nombre del articulo) tambien las encuentra.
     *
     * @group sales
     * @group vender-search
     * @test
     */
    public function solo_azul_encuentra_las_variantes_azules()
    {
        $user = $this->usuario_de_test('v3');
        $this->dar_extension($user, 'article_variants');
        $this->actingAs($user, 'web');
        $this->zapatilla($user);

        $respuesta = $this->buscar('azul');

        $this->assertEquals(['Zapatilla azul 35', 'Zapatilla azul 36'], $this->nombres($respuesta));
    }

    /**
     * "zapatilla azul 36" -> el unico resultado posible. Las variantes "rojo 36" y "azul 35"
     * matchean UNA de las palabras cada una, pero ninguna matchea las dos: no tienen que aparecer
     * (el articulo pasa el SQL porque cada palabra esta en alguna variante, y el filtro fino por
     * variante es el que decide). Tampoco importa el orden de las palabras.
     *
     * @group sales
     * @group vender-search
     * @test
     */
    public function zapatilla_azul_36_devuelve_el_unico_resultado_posible()
    {
        $user = $this->usuario_de_test('v4');
        $this->dar_extension($user, 'article_variants');
        $this->actingAs($user, 'web');
        $this->zapatilla($user);

        $this->assertEquals(['Zapatilla azul 36'], $this->nombres($this->buscar('zapatilla azul 36')));
        $this->assertEquals(['Zapatilla azul 36'], $this->nombres($this->buscar('36 azul zapatilla')));
        $this->assertEquals(['Zapatilla azul 36'], $this->nombres($this->buscar('azul 36')));
    }

    /**
     * Una palabra que no esta ni en el articulo ni en ninguna variante: cero resultados.
     *
     * @group sales
     * @group vender-search
     * @test
     */
    public function zapatilla_verde_no_devuelve_nada()
    {
        $user = $this->usuario_de_test('v5');
        $this->dar_extension($user, 'article_variants');
        $this->actingAs($user, 'web');
        $this->zapatilla($user);

        $respuesta = $this->buscar('zapatilla verde');

        $this->assertEquals([], $this->nombres($respuesta));
        $this->assertEquals(0, $respuesta['models']['total']);
    }

    /**
     * Un doble espacio entre palabras (o espacios de mas) da lo mismo que uno solo. El filtro fino
     * por variante separaba el criterio con explode(' '): la palabra vacia hacia strpos(..., '')
     * -- warning en PHP 7.4, que Laravel convierte en 500--.
     *
     * @group sales
     * @group vender-search
     * @test
     */
    public function espacios_de_mas_en_el_criterio_dan_lo_mismo()
    {
        $user = $this->usuario_de_test('v6');
        $this->dar_extension($user, 'article_variants');
        $this->actingAs($user, 'web');
        $this->zapatilla($user);

        $this->assertEquals(
            ['Zapatilla azul 35', 'Zapatilla azul 36'],
            $this->nombres($this->buscar('  zapatilla   azul  '))
        );
    }

    /**
     * Las variantes ocultas no se ofrecen en Vender, y por lo tanto no pueden hacer aparecer al
     * articulo. Dos articulos para que el test discrimine la clausula `oculta = 0` del EXISTS:
     *   - "Remera" tiene una variante visible (blanca) y una oculta (negra): "remera negra" no
     *     encuentra nada, "remera blanca" si. Este caso lo atajaria igual el filtro fino de PHP.
     *   - "Mochila" tiene SOLO variantes ocultas (verde): sin `oculta = 0` el SQL dejaria pasar al
     *     articulo, y como no tiene variantes disponibles `match_descriptors` lo devolveria como
     *     fila comun (falso positivo: "mochila verde" ofreceria una mochila sin variante). Con la
     *     clausula, el SQL lo excluye.
     *
     * @group sales
     * @group vender-search
     * @test
     */
    public function las_variantes_ocultas_ni_se_ofrecen_ni_hacen_aparecer_al_articulo()
    {
        $user = $this->usuario_de_test('v7');
        $this->dar_extension($user, 'article_variants');
        $this->actingAs($user, 'web');

        $remera = $this->articulo($user, 'Remera');
        $this->variante($remera, 'blanca', false);
        $this->variante($remera, 'negra', true);

        $mochila = $this->articulo($user, 'Mochila');
        $this->variante($mochila, 'verde', true);

        $this->assertEquals(['Remera blanca'], $this->nombres($this->buscar('remera blanca')));
        $this->assertEquals([], $this->nombres($this->buscar('remera negra')));
        $this->assertEquals([], $this->nombres($this->buscar('negra')));

        // Solo variantes ocultas: no aparece ni por la variante ni como articulo comun.
        $this->assertEquals([], $this->nombres($this->buscar('mochila verde')));
        $this->assertEquals([], $this->nombres($this->buscar('verde')));
    }

    /**
     * Modo de coincidencia 'todas' sobre `name` (el usuario pidio que todas las palabras esten en
     * la MISMA propiedad): la descripcion de las variantes extiende a `name`, asi que tambien
     * encuentra "zapatilla azul".
     *
     * @group sales
     * @group vender-search
     * @test
     */
    public function el_modo_todas_sobre_name_tambien_encuentra_por_variante()
    {
        $user = $this->usuario_de_test('v8');
        $this->dar_extension($user, 'article_variants');
        $this->actingAs($user, 'web');
        $this->zapatilla($user);

        $props = [['key' => 'name', 'keyword_mode' => 'todas']];

        $this->assertEquals(
            ['Zapatilla azul 35', 'Zapatilla azul 36'],
            $this->nombres($this->buscar('zapatilla azul', $props))
        );
        $this->assertEquals(
            ['Zapatilla azul 36'],
            $this->nombres($this->buscar('zapatilla azul 36', $props))
        );
        $this->assertEquals([], $this->nombres($this->buscar('zapatilla verde', $props)));
    }

    /**
     * Si el usuario destildo `name` del buscador, las variantes (que extienden a `name`) no
     * participan: el SQL ni las nombra y "azul" no encuentra nada por esa via.
     *
     * @group sales
     * @group vender-search
     * @test
     */
    public function sin_name_entre_las_props_las_variantes_no_participan()
    {
        $user = $this->usuario_de_test('v9');
        $this->dar_extension($user, 'article_variants');
        $this->actingAs($user, 'web');
        $this->zapatilla($user);

        DB::enableQueryLog();
        $respuesta = $this->buscar('azul', ['provider_code']);
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertEquals([], $this->nombres($respuesta));
        $this->assertSame(0, $this->cantidad_de_exists_de_variantes($log), 'Sin `name` tildado no se agrega ningun EXISTS de variantes.');
    }

    /**
     * Aislamiento entre comercios: article_variants no tiene user_id, asi que lo unico que evita
     * mezclar clientes es que la condicion cuelgue del articulo (que si esta filtrado por user_id).
     * El comercio B tiene una "Zapatilla azul"; el comercio A (con "Zapatilla roja") no la ve.
     *
     * @group sales
     * @group vender-search
     * @test
     */
    public function no_se_cuelan_variantes_de_otro_comercio()
    {
        $otro = $this->usuario_de_test('v10b');
        $this->dar_extension($otro, 'article_variants');
        $zapatilla_ajena = $this->articulo($otro, 'Zapatilla');
        $this->variante($zapatilla_ajena, 'azul 36');

        $user = $this->usuario_de_test('v10a');
        $this->dar_extension($user, 'article_variants');
        $this->actingAs($user, 'web');
        $this->articulo($user, 'Zapatilla'); // propia, SIN variantes

        // Si el EXISTS viera la variante "azul 36" del otro comercio, el articulo propio pasaria el
        // SQL y volveria como fila comun (no tiene variantes disponibles): esa es la fuga que este
        // test detecta.
        $this->assertEquals([], $this->nombres($this->buscar('zapatilla azul')));
        $this->assertEquals([], $this->nombres($this->buscar('azul')));
        $this->assertEquals(['Zapatilla'], $this->nombres($this->buscar('zapatilla')));

    }

    /**
     * Sin la extension `article_variants` la busqueda queda EXACTAMENTE como estaba: "zapatilla"
     * devuelve el articulo sin expandir, "zapatilla azul" no encuentra nada, y el SQL de la fase 1
     * ni nombra a las variantes. Es el pedido de Lucas de que solo cambie para quien las usa.
     *
     * @group sales
     * @group vender-search
     * @test
     */
    public function sin_la_extension_la_busqueda_queda_como_estaba()
    {
        $user = $this->usuario_de_test('v11');
        $this->actingAs($user, 'web');
        $this->zapatilla($user); // las variantes existen en la base, pero el comercio no usa la extension

        DB::enableQueryLog();
        $sola = $this->buscar('zapatilla');
        $con_azul = $this->buscar('zapatilla azul');
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertEquals(['Zapatilla'], $this->nombres($sola), 'Sin la extension el articulo no se expande en variantes.');
        $this->assertEquals([], $this->nombres($con_azul));
        $this->assertSame(0, $this->cantidad_de_exists_de_variantes($log), 'Sin la extension el SQL no agrega ningun EXISTS de variantes.');
    }

    /**
     * Rendimiento (forma del SQL): con la extension, el criterio de DOS palabras agrega exactamente
     * un EXISTS correlacionado por palabra a la consulta liviana de la fase 1 -- no un IN con ids
     * precalculados, no un JOIN, no una consulta extra por articulo.
     *
     * @group sales
     * @group vender-search
     * @test
     */
    public function con_la_extension_el_sql_agrega_un_exists_por_palabra_y_nada_mas()
    {
        $user = $this->usuario_de_test('v12');
        $this->dar_extension($user, 'article_variants');
        $this->actingAs($user, 'web');
        $this->zapatilla($user);

        DB::enableQueryLog();
        $this->buscar('zapatilla azul');
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(2, $this->cantidad_de_exists_de_variantes($log), 'Un EXISTS por palabra (zapatilla, azul).');
    }

    /**
     * La ruta vieja (`search_nombre`) y los otros contextos del buscador general (pedido a
     * proveedor, receta) comparten el filtro fino: tienen que dar lo mismo que Vender.
     *
     * @group sales
     * @group vender-search
     * @test
     */
    public function la_ruta_vieja_y_los_otros_contextos_tambien_buscan_por_variante()
    {
        $user = $this->usuario_de_test('v13');
        $this->dar_extension($user, 'article_variants');
        $this->actingAs($user, 'web');
        $this->zapatilla($user);

        $esperado = ['Zapatilla azul 35', 'Zapatilla azul 36'];

        $this->assertEquals($esperado, $this->nombres($this->buscar('zapatilla azul', ['name', 'provider_code'], 'provider_order')));
        $this->assertEquals($esperado, $this->nombres($this->buscar('zapatilla azul', ['name', 'provider_code'], 'recipe')));

        $res = $this->postJson('api/vender/buscar-articulo-por-nombre/0', ['query_value' => 'zapatilla azul']);
        $res->assertStatus(200);
        $body = $res->json();

        $nombres = collect($body['data'])->pluck('name')->all();
        sort($nombres);

        $this->assertEquals($esperado, $nombres);
        $this->assertEquals(2, $body['total']);

        // Y una sola palabra tambien (rama de una palabra de search_nombre).
        $res = $this->postJson('api/vender/buscar-articulo-por-nombre/0', ['query_value' => 'azul']);
        $res->assertStatus(200);
        $this->assertEquals(2, $res->json()['total']);
    }

    /**
     * Un criterio vacio (busqueda solo por filtros fijos, sin texto) con la extension prendida:
     * antes daba 500 ("strpos(): Empty needle", medido el 1/10/2026 en la linea base). Ahora no hay
     * nada que filtrar por texto: devuelve todo, con las variantes disponibles expandidas y los
     * articulos sin variantes como filas propias.
     *
     * @group sales
     * @group vender-search
     * @test
     */
    public function un_criterio_vacio_con_la_extension_no_revienta_y_devuelve_todo()
    {
        $user = $this->usuario_de_test('v14');
        $this->dar_extension($user, 'article_variants');
        $this->actingAs($user, 'web');
        $this->zapatilla($user);
        $this->articulo($user, 'Clavo');

        $esperado = ['Clavo', 'Zapatilla azul 35', 'Zapatilla azul 36', 'Zapatilla rojo 35', 'Zapatilla rojo 36'];

        $this->assertEquals($esperado, $this->nombres($this->buscar('')));
        $this->assertEquals($esperado, $this->nombres($this->buscar('   ')));
    }

    /**
     * Con `search_bar_code_en_vender`, el talle "36" NO se da por cubierto porque el codigo de barras
     * de OTRA variante lo contenga. Los codigos de barras de las variantes nacen como '0' + id
     * ("01360"), asi que los numeros cortos aparecen adentro con mucha frecuencia; antes, esa sola
     * coincidencia exceptuaba la palabra para TODAS las variantes del articulo y "zapatilla azul 36"
     * devolvia las dos azules en vez del unico resultado posible.
     *
     * @group sales
     * @group vender-search
     * @test
     */
    public function con_codigo_de_barras_en_vender_un_talle_no_se_cubre_con_el_codigo_de_otra_variante()
    {
        $user = $this->usuario_de_test('v15');
        $this->dar_extension($user, 'article_variants');
        $this->dar_extension($user, 'search_bar_code_en_vender');
        $this->actingAs($user, 'web');

        $zapatilla = $this->articulo($user, 'Zapatilla');
        $this->variante($zapatilla, 'rojo 35', false, '01360'); // el codigo contiene "36", la variante no es talle 36
        $this->variante($zapatilla, 'rojo 36', false, '01361');
        $this->variante($zapatilla, 'azul 35', false, '01362');
        $this->variante($zapatilla, 'azul 36', false, '01363');

        $this->assertEquals(['Zapatilla azul 36'], $this->nombres($this->buscar('zapatilla azul 36')));
        $this->assertEquals(['Zapatilla azul 35', 'Zapatilla azul 36'], $this->nombres($this->buscar('zapatilla azul')));
    }

    /**
     * Con `search_bar_code_en_vender`, una palabra que coincide con el codigo de barras de UNA
     * variante (ruta vieja, que ademas busca el codigo parcial de las variantes en SQL) deja
     * solamente esa variante, no las de todo el articulo.
     *
     * @group sales
     * @group vender-search
     * @test
     */
    public function un_codigo_parcial_de_variante_junto_al_nombre_deja_solo_esa_variante()
    {
        $user = $this->usuario_de_test('v16');
        $this->dar_extension($user, 'article_variants');
        $this->dar_extension($user, 'search_bar_code_en_vender');
        $this->actingAs($user, 'web');

        $zapatilla = $this->articulo($user, 'Zapatilla');
        $this->variante($zapatilla, 'rojo 35', false, '7790001');
        $this->variante($zapatilla, 'azul 36', false, '7790002');

        $res = $this->postJson('api/vender/buscar-articulo-por-nombre/0', ['query_value' => 'zapatilla 779000']);
        $res->assertStatus(200);

        $nombres = collect($res->json()['data'])->pluck('name')->all();
        sort($nombres);
        $this->assertEquals(['Zapatilla azul 36', 'Zapatilla rojo 35'], $nombres, 'El fragmento esta en el codigo de las dos variantes: salen las dos.');

        $res = $this->postJson('api/vender/buscar-articulo-por-nombre/0', ['query_value' => 'zapatilla 7790002']);
        $res->assertStatus(200);
        $this->assertEquals(['Zapatilla azul 36'], collect($res->json()['data'])->pluck('name')->all(), 'El fragmento identifica una sola variante: sale solo esa.');
    }

    /**
     * Cuenta cuantos EXISTS contra article_variants filtran por `variant_description` (la
     * condicion que agrega esta mision). No cuenta el EXISTS de codigo de barras que Vender ya
     * tenia con un criterio de una sola palabra, ni el eager load `where article_id in (...)`.
     *
     * @param  array $log Resultado de DB::getQueryLog().
     * @return int
     */
    private function cantidad_de_exists_de_variantes($log)
    {
        $cantidad = 0;

        foreach ($log as $consulta) {
            $cantidad += preg_match_all('/exists\s*\(\s*select\s+\*\s+from\s+`article_variants`[^()]*`variant_description`\s+like/i', $consulta['query']);
        }

        return $cantidad;
    }
}
