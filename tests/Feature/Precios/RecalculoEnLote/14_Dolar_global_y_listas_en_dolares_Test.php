<?php

namespace Tests\Feature\Precios\RecalculoEnLote;

use App\Events\BackgroundProcessUpdated;
use App\Jobs\ProcessChunkSetFinalPrices;
use App\Jobs\ProcessSetFinalPrices;
use App\Models\ArticlePriceTypeMoneda;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/**
 * El alcance del dólar global incluye los artículos con listas EN DÓLARES, aunque su costo esté en
 * pesos (misión recalculo-precios-motor-rapido, seguimiento del 29/9/2026).
 *
 * ArticlePriceTypeMonedaHelper calcula el precio en dólares (moneda_id = 2) de un artículo con el
 * costo en pesos dividiendo por el dólar de la cuenta. El alcance del dólar solo tomaba los
 * artículos con costo en dólares y los que cotizan desde otra moneda, así que al cambiar el dólar
 * esos precios en dólares quedaban viejos. Se prueba de punta a punta (productor → lotes con el
 * motor → finalizador, cola sync) y además que una rama del reparto que se agotó no se vuelve a
 * consultar.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group recalculo-en-lote
 */
class Dolar_global_y_listas_en_dolares_Test extends RecalculoEnLoteTestCase
{
    /** @var array SQL ejecutado mientras $contando está prendido. */
    protected $consultas = [];

    /** @var bool */
    protected $contando = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->consultas = [];
        $this->contando  = false;

        DB::listen(function ($query) {
            if ($this->contando) {
                $this->consultas[] = $query->sql;
            }
        });
    }

    /**
     * Un artículo con el costo en pesos y una lista en dólares: al cambiar el dólar de la cuenta,
     * el recálculo del dólar le recalcula el precio en dólares. Uno en pesos sin listas en dólares
     * queda afuera, como antes.
     *
     * @return void
     */
    public function test_un_articulo_en_pesos_con_lista_en_dolares_cambia_al_cambiar_el_dolar()
    {
        Notification::fake();
        Event::fake([BackgroundProcessUpdated::class]);

        $dueno = $this->crear_dueno(['listas_de_precio' => 1, 'dollar' => 1000], ['ventas_en_dolares']);

        $lista = $this->crear_lista($dueno, 'Pesos y dolares', 50, 1);

        $en_pesos_con_dolares = $this->crear_articulo($dueno, ['cost' => 100000]);
        $en_pesos_sin_dolares = $this->crear_articulo($dueno, ['cost' => 100000]);

        foreach ([$en_pesos_con_dolares, $en_pesos_sin_dolares] as $article) {
            $article->price_types()->attach($lista->id, ['percentage' => 50, 'final_price' => 1]);
            $this->entrada($article, $lista, self::ARS);
        }

        $this->entrada($en_pesos_con_dolares, $lista, self::USD);

        /* Precios calculados con el dólar a 1.000. */
        $this->recalcular_con_el_motor([$en_pesos_con_dolares->id, $en_pesos_sin_dolares->id], $dueno->id);

        $usd_antes = (float) $this->precio_de($en_pesos_con_dolares, self::USD);
        $this->assertEqualsWithDelta(181.5, $usd_antes, 0.001, 'Precondición: 100.000 / 1.000 × 1,5 × 1,21.');

        /* Pisado a propósito en el que queda afuera, para ver que no se toca. */
        DB::table('article_price_type_monedas')->where('article_id', $en_pesos_sin_dolares->id)->update(['final_price' => 1]);

        /* Cambia el dólar de la cuenta, y llega el recálculo que eso despacha. */
        DB::table('users')->where('id', $dueno->id)->update(['dollar' => 2000]);

        (new ProcessSetFinalPrices($dueno->id, null, null, true, 'dolar', 'Cotización cargada a mano'))->handle();

        $this->assertEqualsWithDelta(90.75, (float) $this->precio_de($en_pesos_con_dolares, self::USD), 0.001, 'El precio en dólares de un artículo en pesos no se recalculó con el dólar nuevo.');

        $this->assertEquals(1, (float) $this->precio_de($en_pesos_sin_dolares, self::ARS), 'Un artículo en pesos sin listas en dólares no depende del dólar: no se tenía que recalcular.');
    }

    /**
     * La rama de las listas se agota en la primera página y ya no se consulta; la de los
     * artículos en dólares sigue hasta el final. Y al revés.
     *
     * @return void
     */
    public function test_una_rama_agotada_no_se_vuelve_a_consultar()
    {
        config(['app.RECALCULO_PRECIOS_LOTE' => 2]);

        $dueno = $this->crear_dueno(['listas_de_precio' => 1], ['ventas_en_dolares']);
        $lista = $this->crear_lista($dueno, 'Dolares', 30, 1);

        /* Primero uno en pesos con lista en dólares, después cinco con costo en dólares. */
        $con_lista = $this->crear_articulo($dueno, ['cost' => 10])->id;
        $this->entrada_id($con_lista, $lista, self::USD);

        $en_dolares = [];
        for ($i = 0; $i < 5; $i++) {
            $en_dolares[] = $this->crear_articulo($dueno, ['cost' => 10, 'cost_in_dollars' => 1])->id;
        }

        $r = $this->repartir($dueno);

        $this->assertSame([[$con_lista, $en_dolares[0]], [$en_dolares[1], $en_dolares[2]], [$en_dolares[3], $en_dolares[4]]], $r['lotes']);
        $this->assertSame(1, $r['consultas_de_listas'], 'La rama de las listas se agotó en la primera página y se siguió consultando.');

        /* Al revés: uno en dólares primero y cinco con listas en dólares. */
        $dueno_2 = $this->crear_dueno(['listas_de_precio' => 1], ['ventas_en_dolares']);
        $lista_2 = $this->crear_lista($dueno_2, 'Dolares 2', 30, 1);

        $uno_en_dolares = $this->crear_articulo($dueno_2, ['cost' => 10, 'cost_in_dollars' => 1])->id;

        $con_listas = [];
        for ($i = 0; $i < 5; $i++) {
            $con_listas[] = $this->crear_articulo($dueno_2, ['cost' => 10])->id;
            $this->entrada_id($con_listas[$i], $lista_2, self::USD);
        }

        $r = $this->repartir($dueno_2);

        $this->assertSame([[$uno_en_dolares, $con_listas[0]], [$con_listas[1], $con_listas[2]], [$con_listas[3], $con_listas[4]]], $r['lotes']);
        $this->assertSame(1, $r['consultas_de_articulos'], 'La rama de los artículos en dólares se agotó en la primera página y se siguió consultando.');
    }

    /**
     * 🔴 El borde de la regla de "agotada": una rama que devolvió MENOS que el lote pero cuyo
     * último id quedó afuera del corte de la unión todavía tiene ids por despachar. Con lote 3 y
     * los ids en este orden —c1 (lista en dólares), d1, d2, d3 (costo en dólares), c2 (lista en
     * dólares), d4 (costo en dólares)—, la primera página trae [d1, d2, d3] y [c1, c2], la unión
     * corta en [c1, d1, d2], y c2 sigue pendiente: la rama de las listas no se puede dar por
     * agotada ahí, o c2 no se recalcula nunca.
     *
     * @return void
     */
    public function test_una_rama_con_ids_despues_del_corte_no_se_da_por_agotada()
    {
        config(['app.RECALCULO_PRECIOS_LOTE' => 3]);

        $dueno = $this->crear_dueno(['listas_de_precio' => 1], ['ventas_en_dolares']);
        $lista = $this->crear_lista($dueno, 'Dolares', 30, 1);

        $c1 = $this->crear_articulo($dueno, ['cost' => 10])->id;
        $this->entrada_id($c1, $lista, self::USD);

        $d1 = $this->crear_articulo($dueno, ['cost' => 10, 'cost_in_dollars' => 1])->id;
        $d2 = $this->crear_articulo($dueno, ['cost' => 10, 'cost_in_dollars' => 1])->id;
        $d3 = $this->crear_articulo($dueno, ['cost' => 10, 'cost_in_dollars' => 1])->id;

        $c2 = $this->crear_articulo($dueno, ['cost' => 10])->id;
        $this->entrada_id($c2, $lista, self::USD);

        $d4 = $this->crear_articulo($dueno, ['cost' => 10, 'cost_in_dollars' => 1])->id;

        $r = $this->repartir($dueno);

        $this->assertSame([[$c1, $d1, $d2], [$d3, $c2, $d4]], $r['lotes'], 'Se perdió un artículo de la rama de las listas que había quedado después del corte.');

        /* El mismo borde en la otra rama: d1 < c1 < c2 < c3 < d2 < c4. */
        $dueno_2 = $this->crear_dueno(['listas_de_precio' => 1], ['ventas_en_dolares']);
        $lista_2 = $this->crear_lista($dueno_2, 'Dolares 2', 30, 1);

        $e1 = $this->crear_articulo($dueno_2, ['cost' => 10, 'cost_in_dollars' => 1])->id;

        $l = [];
        for ($i = 0; $i < 3; $i++) {
            $l[] = $this->crear_articulo($dueno_2, ['cost' => 10])->id;
            $this->entrada_id($l[$i], $lista_2, self::USD);
        }

        $e2 = $this->crear_articulo($dueno_2, ['cost' => 10, 'cost_in_dollars' => 1])->id;

        $l[] = $this->crear_articulo($dueno_2, ['cost' => 10])->id;
        $this->entrada_id($l[3], $lista_2, self::USD);

        $r = $this->repartir($dueno_2);

        $this->assertSame([[$e1, $l[0], $l[1]], [$l[2], $e2, $l[3]]], $r['lotes'], 'Se perdió un artículo de la rama de los artículos en dólares que había quedado después del corte.');
    }

    /* ------------------------------------------------------------------------------------------
     * Ayudantes
     * ---------------------------------------------------------------------------------------- */

    /**
     * Corre el productor del dólar global con la cola falsa y devuelve los lotes encolados y
     * cuántas veces consultó cada rama.
     *
     * @param  \App\Models\User $dueno
     * @return array
     */
    protected function repartir($dueno)
    {
        Notification::fake();
        Queue::fake();

        $this->consultas = [];
        $this->contando  = true;

        (new ProcessSetFinalPrices($dueno->id, null, null, true, 'dolar'))->handle();

        $this->contando = false;

        $lotes = [];

        foreach (Queue::pushed(ProcessChunkSetFinalPrices::class) as $chunk) {
            $propiedad = new \ReflectionProperty($chunk, 'article_ids');
            $propiedad->setAccessible(true);
            $lotes[] = array_map('intval', $propiedad->getValue($chunk));
        }

        $consultas_de_listas = 0;
        $consultas_de_articulos = 0;

        foreach ($this->consultas as $sql) {
            if (preg_match('/^select distinct `article_price_type_monedas`\.`article_id` from `article_price_type_monedas`/i', $sql)) {
                $consultas_de_listas++;
            }
            if (preg_match('/^select `articles`\.`id` from `articles` where `user_id` = \? and `cost_in_dollars` = \?/i', $sql)) {
                $consultas_de_articulos++;
            }
        }

        return [
            'lotes'                  => $lotes,
            'consultas_de_listas'    => $consultas_de_listas,
            'consultas_de_articulos' => $consultas_de_articulos,
        ];
    }

    /**
     * @param  \App\Models\Article   $article
     * @param  \App\Models\PriceType $lista
     * @param  int                   $moneda_id
     * @return void
     */
    protected function entrada($article, $lista, $moneda_id)
    {
        $this->entrada_id($article->id, $lista, $moneda_id);
    }

    /**
     * Una entrada normal (margen de la lista, sin precio fijado ni cotización cruzada).
     *
     * @param  int                   $article_id
     * @param  \App\Models\PriceType $lista
     * @param  int                   $moneda_id
     * @return void
     */
    protected function entrada_id($article_id, $lista, $moneda_id)
    {
        ArticlePriceTypeMoneda::create([
            'article_id'                => $article_id,
            'price_type_id'             => $lista->id,
            'moneda_id'                 => $moneda_id,
            'percentage'                => 50,
            'final_price'               => 0,
            'setear_precio_final'       => 0,
            'cotizar_desde_otra_moneda' => 0,
        ]);
    }

    /**
     * @param  \App\Models\Article $article
     * @param  int                 $moneda_id
     * @return string|null
     */
    protected function precio_de($article, $moneda_id)
    {
        return DB::table('article_price_type_monedas')
                    ->where('article_id', $article->id)
                    ->where('moneda_id', $moneda_id)
                    ->value('final_price');
    }
}
