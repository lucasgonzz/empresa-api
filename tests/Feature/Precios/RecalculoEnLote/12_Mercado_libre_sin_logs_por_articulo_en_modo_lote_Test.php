<?php

namespace Tests\Feature\Precios\RecalculoEnLote;

use App\Http\Controllers\Helpers\article\precios\RecalculoDePreciosEnLote;
use App\Http\Controllers\Helpers\import\article\motor\PreciosEnLote;
use App\Models\Article;
use App\Models\Image;
use App\Models\SyncToMeliArticle;
use App\Models\User;
use App\Services\MercadoLibre\ProductService;
use Illuminate\Support\Facades\Log;

/**
 * ProductService::add_article_to_sync() no loguea por artículo en modo lote (misión
 * recalculo-precios-motor-rapido, seguimiento del 29/9/2026).
 *
 * ArticleHelper::setFinalPrice() la llama con cada artículo que recalcula. En un cliente con
 * USA_MERCADO_LIBRE prendido, cada recálculo escribía un Log::info y un Log::error POR ARTÍCULO del
 * catálogo (casi ninguno es de Mercado Libre). En modo lote no se loguea; fuera de él, como
 * siempre. Lo que se encola (SyncToMeliArticle) y los return, idénticos en los dos modos.
 *
 * La variable de entorno se fuerza en el test (y se restaura): no depende de lo que tenga el
 * .env.testing del slot.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group recalculo-en-lote
 */
class Mercado_libre_sin_logs_por_articulo_en_modo_lote_Test extends RecalculoEnLoteTestCase
{
    /** @var array Valores de USA_MERCADO_LIBRE antes del test, para restaurarlos. */
    protected $entorno_previo = [];

    /** @var \App\Models\User */
    protected $dueno;

    /** @var array [nombre => Article] */
    protected $articulos = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->entorno_previo = [
            'env'    => array_key_exists('USA_MERCADO_LIBRE', $_ENV) ? $_ENV['USA_MERCADO_LIBRE'] : null,
            'server' => array_key_exists('USA_MERCADO_LIBRE', $_SERVER) ? $_SERVER['USA_MERCADO_LIBRE'] : null,
            'getenv' => getenv('USA_MERCADO_LIBRE'),
        ];

        $_ENV['USA_MERCADO_LIBRE']    = 'true';
        $_SERVER['USA_MERCADO_LIBRE'] = 'true';
        putenv('USA_MERCADO_LIBRE=true');

        $this->assertTrue((bool) env('USA_MERCADO_LIBRE', false), 'Precondición: USA_MERCADO_LIBRE tiene que estar prendida para este test.');

        $this->dueno = $this->crear_dueno();

        /*
         * Cada artículo falla UNA sola de las condiciones y cumple todas las demás: así, si
         * alguno de los return cambiara en modo lote, ese artículo se encolaría y el test lo ve.
         */
        $completo = ['cost' => 10, 'mercado_libre' => 1, 'meli_category_id' => 'MLA1234', 'stock' => 5];

        $this->articulos['no_es_de_mercado_libre'] = $this->crear_articulo($this->dueno, array_merge($completo, ['mercado_libre' => 0]));
        $this->articulos['sin_categoria']          = $this->crear_articulo($this->dueno, array_merge($completo, ['meli_category_id' => null]));
        $this->articulos['sin_stock']              = $this->crear_articulo($this->dueno, array_merge($completo, ['stock' => null]));
        $this->articulos['sin_imagenes']           = $this->crear_articulo($this->dueno, $completo);
        $this->articulos['de_mercado_libre']       = $this->crear_articulo($this->dueno, $completo);

        foreach (['no_es_de_mercado_libre', 'sin_categoria', 'sin_stock', 'de_mercado_libre'] as $nombre) {
            Image::create([
                'hosting_url'    => 'https://example.test/zz-imagen-' . $nombre . '.jpg',
                'imageable_type' => 'article',
                'imageable_id'   => $this->articulos[$nombre]->id,
            ]);
        }
    }

    protected function tearDown(): void
    {
        foreach (['env' => '_ENV', 'server' => '_SERVER'] as $clave => $global) {
            if (is_null($this->entorno_previo[$clave])) {
                if ($global === '_ENV') {
                    unset($_ENV['USA_MERCADO_LIBRE']);
                } else {
                    unset($_SERVER['USA_MERCADO_LIBRE']);
                }
            } else if ($global === '_ENV') {
                $_ENV['USA_MERCADO_LIBRE'] = $this->entorno_previo[$clave];
            } else {
                $_SERVER['USA_MERCADO_LIBRE'] = $this->entorno_previo[$clave];
            }
        }

        if ($this->entorno_previo['getenv'] === false) {
            putenv('USA_MERCADO_LIBRE');
        } else {
            putenv('USA_MERCADO_LIBRE=' . $this->entorno_previo['getenv']);
        }

        parent::tearDown();
    }

    /**
     * En modo lote: ningún log, y se encola exactamente el único artículo que es de Mercado Libre.
     *
     * @return void
     */
    public function test_en_modo_lote_no_loguea_y_encola_lo_mismo()
    {
        Log::spy();

        PreciosEnLote::activar($this->dueno, $this->dueno->id);

        try {
            foreach ($this->articulos as $article) {
                ProductService::add_article_to_sync(Article::find($article->id));
            }
        } finally {
            PreciosEnLote::descartar();
        }

        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('error');

        $this->assertEncoladoSoloElDeMercadoLibre();
    }

    /**
     * Fuera del modo lote: los mismos logs de siempre, uno por artículo, y lo mismo encolado.
     *
     * @return void
     */
    public function test_fuera_del_modo_lote_loguea_como_siempre_y_encola_lo_mismo()
    {
        Log::spy();

        foreach ($this->articulos as $article) {
            ProductService::add_article_to_sync(Article::find($article->id));
        }

        Log::shouldHaveReceived('info')->with('add_article_to_sync')->times(count($this->articulos));

        Log::shouldHaveReceived('error')->with('Artículo Mercado Libre: ID ' . $this->articulos['no_es_de_mercado_libre']->id)->once();
        Log::shouldHaveReceived('error')->with('Artículo sin categoria de Mercado Libre: ID ' . $this->articulos['sin_categoria']->id)->once();
        Log::shouldHaveReceived('error')->with('Artículo sin stock para Mercado Libre: ID ' . $this->articulos['sin_stock']->id)->once();
        Log::shouldHaveReceived('error')->with('Artículo sin imagenes para Mercado Libre: ID ' . $this->articulos['sin_imagenes']->id)->once();

        $this->assertEncoladoSoloElDeMercadoLibre();
    }

    /**
     * De punta a punta: el motor recalcula los cinco y no deja ningún log de Mercado Libre.
     *
     * @return void
     */
    public function test_el_motor_no_deja_logs_de_mercado_libre_por_articulo()
    {
        $ids = [];
        foreach ($this->articulos as $article) {
            $ids[] = $article->id;
        }

        Log::spy();

        RecalculoDePreciosEnLote::recalcular($ids, User::find($this->dueno->id));

        Log::shouldNotHaveReceived('info', ['add_article_to_sync']);
        Log::shouldNotHaveReceived('error');

        $this->assertEncoladoSoloElDeMercadoLibre();
    }

    /**
     * @return void
     */
    protected function assertEncoladoSoloElDeMercadoLibre()
    {
        $ids = [];
        foreach ($this->articulos as $article) {
            $ids[] = $article->id;
        }

        $encolados = SyncToMeliArticle::whereIn('article_id', $ids)->where('status', 'pendiente')->pluck('article_id')->all();

        $this->assertEquals([$this->articulos['de_mercado_libre']->id], array_map('intval', $encolados), 'Lo encolado para Mercado Libre no es lo de siempre.');
    }
}
