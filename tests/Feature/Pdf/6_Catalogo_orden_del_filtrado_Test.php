<?php

namespace Tests\Feature\Pdf;

use App\Http\Controllers\Helpers\ArticleTablePdfHelper;
use App\Models\Article;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * El PDF del catálogo tiene que listar los artículos EN EL ORDEN que el usuario pidió: el que
 * eligió con las flechas del listado (ordenar_de en los filtros) o el de la selección
 * (articles_id tal como lo manda la SPA). Hasta la misión catalogo-pdf-encabezado (18/9/2026)
 * ArticleController::tablePdf() terminaba con orderBy('created_at', 'DESC') y pisaba los dos:
 * filtrar por nombre ascendente daba un PDF ordenado por fecha de alta.
 *
 * Los tres artículos se crean con created_at DECRECIENTE en el orden C, A, B, de modo que
 * "created_at DESC" (el orden viejo) dé [C, A, B] y no coincida ni con el orden por nombre
 * ([A, B, C]) ni con el de una selección arbitraria. Si alguien vuelve a meter un orderBy en la
 * carga, estos tests lo ven.
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing está sembrada de antes.
 *
 * @group pdf-catalogo-encabezado
 */
class Catalogo_orden_del_filtrado_Test extends TestCase
{
    use DatabaseTransactions;

    /** Prefijo único de los nombres de esta suite, para que el filtro por texto no pesque otros artículos. */
    const PREFIJO = 'zzcatalogoorden';

    /**
     * Usuario autenticado de los tests de esta suite.
     *
     * @return \App\Models\User
     */
    protected function autenticar()
    {
        $owner = User::find(500);
        if (is_null($owner)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $this->actingAs($owner, 'web');

        return $owner;
    }

    /**
     * Tres artículos activos del 500: C (el más nuevo), A y B (el más viejo).
     * created_at explícito y distinto para cada uno: el orden viejo por fecha es [C, A, B].
     *
     * @return array  ['a' => id, 'b' => id, 'c' => id]
     */
    protected function crear_articulos()
    {
        $ahora = Carbon::now();

        $c = Article::create([
            'user_id' => 500,
            'name' => self::PREFIJO.' C',
            'status' => 'active',
            'created_at' => $ahora->copy()->subMinutes(1),
        ]);
        $a = Article::create([
            'user_id' => 500,
            'name' => self::PREFIJO.' A',
            'status' => 'active',
            'created_at' => $ahora->copy()->subMinutes(2),
        ]);
        $b = Article::create([
            'user_id' => 500,
            'name' => self::PREFIJO.' B',
            'status' => 'active',
            'created_at' => $ahora->copy()->subMinutes(3),
        ]);

        return ['a' => $a->id, 'b' => $b->id, 'c' => $c->id];
    }

    /**
     * Request GET a table-pdf con los parámetros dados (mismo formato que manda la SPA).
     *
     * @param  array  $query
     * @return \Illuminate\Http\Request
     */
    protected function request_con(array $query)
    {
        return Request::create('/api/article/table-pdf', 'GET', $query);
    }

    /**
     * Filtro de texto sobre name tal como lo arma la SPA (ordenar_de puede ir null).
     *
     * @param  string|null  $ordenar_de
     * @param  string       $que_contenga
     * @return array
     */
    protected function filtro_texto_por_nombre($ordenar_de, $que_contenga = self::PREFIJO)
    {
        return [
            'key' => 'name',
            'type' => 'text',
            'que_contenga' => $que_contenga,
            'ordenar_de' => $ordenar_de,
            'igual_que' => '',
            'en_blanco' => 0,
            'no_en_blanco' => 0,
        ];
    }

    /**
     * Filtro que SOLO ordena (sin ningún criterio de valor): es lo que las flechas del listado
     * dejan en un filtro number sobre id, y lo que la SPA nueva suma al export.
     *
     * @param  string  $ordenar_de
     * @return array
     */
    protected function filtro_solo_orden_por_id($ordenar_de)
    {
        return [
            'key' => 'id',
            'type' => 'number',
            'ordenar_de' => $ordenar_de,
            'menor_que' => '',
            'igual_que' => '',
            'mayor_que' => '',
            'en_blanco' => 0,
            'no_en_blanco' => 0,
        ];
    }

    /**
     * Los ids de la suite que vinieron en $ids, en el orden en que vinieron (descarta cualquier
     * otro artículo que pudiera matchear el prefijo por un residuo de otra corrida).
     *
     * @param  array  $ids
     * @param  array  $articulos
     * @return array
     */
    protected function solo_los_de_la_suite(array $ids, array $articulos)
    {
        return array_values(array_intersect($ids, array_values($articulos)));
    }

    /**
     * Seleccionados: los ids salen en el orden en que la SPA los mandó, no por fecha de alta.
     *
     * @test
     */
    public function articles_id_se_respeta_en_el_orden_que_vino()
    {
        $this->autenticar();
        $articulos = $this->crear_articulos();

        $ids = ArticleTablePdfHelper::resolve_article_ids($this->request_con([
            'articles_id' => $articulos['b'].'-'.$articulos['c'].'-'.$articulos['a'],
        ]));

        $this->assertSame([$articulos['b'], $articulos['c'], $articulos['a']], $ids);
    }

    /** @test */
    public function articles_id_descarta_ceros_basura_y_repetidos_conservando_el_primer_orden()
    {
        $this->autenticar();
        $articulos = $this->crear_articulos();

        $ids = ArticleTablePdfHelper::resolve_article_ids($this->request_con([
            'articles_id' => '0-'.$articulos['c'].'-x-'.$articulos['a'].'--'.$articulos['c'].'-'.$articulos['a'].'-0',
        ]));

        $this->assertSame([$articulos['c'], $articulos['a']], $ids);
    }

    /** @test */
    public function sin_articles_id_ni_filters_no_hay_ids()
    {
        $this->autenticar();

        $this->assertSame([], ArticleTablePdfHelper::resolve_article_ids($this->request_con([])));
        $this->assertSame([], ArticleTablePdfHelper::resolve_article_ids($this->request_con(['articles_id' => ''])));
    }

    /**
     * Filtrados: el ordenar_de del filtro manda. Por nombre ASC es [A, B, C] aunque por fecha de
     * alta (el orden viejo) sea [C, A, B].
     *
     * @test
     */
    public function filters_respeta_el_ordenar_de_ascendente()
    {
        $this->autenticar();
        $articulos = $this->crear_articulos();

        $ids = ArticleTablePdfHelper::resolve_article_ids($this->request_con([
            'filters' => json_encode([$this->filtro_texto_por_nombre('ASC')]),
        ]));

        $this->assertSame(
            [$articulos['a'], $articulos['b'], $articulos['c']],
            $this->solo_los_de_la_suite($ids, $articulos),
            'Con ordenar_de ASC por nombre el PDF tiene que listar A, B, C.'
        );
        $this->assertCount(3, $this->solo_los_de_la_suite($ids, $articulos));
    }

    /** @test */
    public function filters_respeta_el_ordenar_de_descendente()
    {
        $this->autenticar();
        $articulos = $this->crear_articulos();

        $ids = ArticleTablePdfHelper::resolve_article_ids($this->request_con([
            'filters' => json_encode([$this->filtro_texto_por_nombre('DESC')]),
        ]));

        $this->assertSame(
            [$articulos['c'], $articulos['b'], $articulos['a']],
            $this->solo_los_de_la_suite($ids, $articulos),
            'Con ordenar_de DESC por nombre el PDF tiene que listar C, B, A.'
        );
    }

    /**
     * El caso real de la SPA nueva: un filtro con valor (que decide QUÉ artículos) más un filtro
     * que solo tiene ordenar_de (que decide el ORDEN). El orden viaja igual.
     *
     * @test
     */
    public function un_filtro_de_valor_mas_uno_que_solo_ordena_ordena_igual()
    {
        $this->autenticar();
        $articulos = $this->crear_articulos();

        // Por id DESC: B (el último creado, id mayor), A, C. Distinto del orden viejo [C, A, B].
        $ids = ArticleTablePdfHelper::resolve_article_ids($this->request_con([
            'filters' => json_encode([
                $this->filtro_texto_por_nombre(null),
                $this->filtro_solo_orden_por_id('DESC'),
            ]),
        ]));

        $this->assertSame(
            [$articulos['b'], $articulos['a'], $articulos['c']],
            $this->solo_los_de_la_suite($ids, $articulos),
            'El filtro que solo ordena tiene que aplicar aunque el criterio de valor venga en otro filtro.'
        );
    }

    /**
     * Sin ordenar_de en ningún filtro, el orden es el del listado: created_at DESC (con id DESC
     * de desempate), o sea [C, A, B].
     *
     * @test
     */
    public function sin_ordenar_de_queda_el_orden_del_listado_por_fecha_de_alta()
    {
        $this->autenticar();
        $articulos = $this->crear_articulos();

        $ids = ArticleTablePdfHelper::resolve_article_ids($this->request_con([
            'filters' => json_encode([$this->filtro_texto_por_nombre(null)]),
        ]));

        $this->assertSame(
            [$articulos['c'], $articulos['a'], $articulos['b']],
            $this->solo_los_de_la_suite($ids, $articulos)
        );
    }

    /**
     * La carga devuelve los modelos exactamente en el orden de los ids (whereIn no garantiza
     * ninguno y el orderBy('created_at') viejo lo pisaba) y con las relaciones del PDF cargadas.
     *
     * @test
     */
    public function load_articles_in_order_devuelve_ese_orden_con_las_relaciones()
    {
        $this->autenticar();
        $articulos = $this->crear_articulos();

        $pedido = [$articulos['b'], $articulos['c'], $articulos['a']];
        $cargados = ArticleTablePdfHelper::load_articles_in_order($pedido, 500, null);

        $this->assertSame($pedido, $cargados->pluck('id')->all(), 'La colección tiene que venir en el orden pedido.');
        $this->assertCount(3, $cargados);

        foreach ($cargados as $articulo) {
            $this->assertTrue($articulo->relationLoaded('category'), 'category tiene que venir eager-loaded.');
            $this->assertTrue($articulo->relationLoaded('images'), 'images tiene que venir eager-loaded.');
            $this->assertTrue($articulo->relationLoaded('provider'), 'provider tiene que venir eager-loaded.');
        }

        // Y el orden inverso también: no es casualidad de la base.
        $inverso = array_reverse($pedido);
        $this->assertSame($inverso, ArticleTablePdfHelper::load_articles_in_order($inverso, 500, null)->pluck('id')->all());
    }

    /** @test */
    public function load_articles_in_order_ignora_ids_de_otro_usuario_y_devuelve_vacio_sin_ids()
    {
        $this->autenticar();
        $articulos = $this->crear_articulos();

        $this->assertCount(0, ArticleTablePdfHelper::load_articles_in_order([], 500, null));

        // Los artículos son del 500: pedidos para otro user_id no aparecen.
        $this->assertCount(0, ArticleTablePdfHelper::load_articles_in_order(array_values($articulos), 500 + 1000000, null));
    }
}
