<?php

namespace Tests\Feature\Sales;

use App\Models\Article;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `set_costo_ventas` tiene que dejar el costo UNITARIO en `article_sale.cost` y ser idempotente
 * (mision saneo-ganancia-ventas, 17/9/2026).
 *
 * Hasta el 17/9/2026 el comando hacia `$cost *= $amount` antes de persistir, o sea escribia el
 * costo TOTAL de la linea en una columna que por convencion es unitaria — la convencion la fijan
 * `SaleHelper::attachArticle()`, `SaleTotalesHelper::set_total_cost()` y
 * `ContabilidadRepository::costo_mercaderia_vendida()`, que multiplican por la cantidad DESPUES
 * de leerla. Consecuencia medida en golonorte: `SUM(sales.ganancia)` en −$2.327.527.825, y cada
 * corrida del comando multiplicaba de nuevo.
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing del slot esta sembrada de antes
 * y un refresh la vaciaria.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promocion de constructor, readonly, enum ni #[...].
 */
class Costo_unitario_en_set_costo_ventas_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var \App\Models\User */
    protected $user;

    /**
     * Costo unitario del articulo de prueba.
     *
     * @var float
     */
    public $costo_unitario = 850;

    /**
     * Precio unitario de la linea.
     *
     * @var float
     */
    public $precio_unitario = 955;

    /**
     * Cantidad vendida. Es el factor por el que el comando inflaba el costo.
     *
     * @var float
     */
    public $cantidad = 15;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::find(500);

        if (is_null($this->user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $this->actingAs($this->user, 'web');
    }

    /**
     * Articulo minimo, creado directo en la base.
     *
     * @param  array  $props
     * @return \App\Models\Article
     */
    protected function crear_articulo(array $props = [])
    {
        $article = new Article();

        $article->user_id = $this->user->id;
        $article->name = 'ZZ Test costo unitario ' . uniqid();
        $article->status = 'active';
        $article->iva_id = 2;
        $article->costo_real = $this->costo_unitario;

        foreach ($props as $campo => $valor) {
            $article->$campo = $valor;
        }

        $article->save();

        return $article;
    }

    /**
     * Venta con una sola linea, sembrada con el costo unitario ya correcto: lo que el test mide
     * es que el comando NO lo rompa.
     *
     * @param  \App\Models\Article  $article
     * @param  array  $props_venta
     * @return \App\Models\Sale
     */
    protected function crear_venta_con_una_linea($article, array $props_venta = [])
    {
        $sale = new Sale();

        $sale->user_id = $this->user->id;
        $sale->total = $this->precio_unitario * $this->cantidad;
        $sale->terminada = 1;

        foreach ($props_venta as $campo => $valor) {
            $sale->$campo = $valor;
        }

        $sale->save();

        $sale->articles()->attach($article->id, [
            'amount' => $this->cantidad,
            'price' => $this->precio_unitario,
            'cost' => $this->costo_unitario,
            'ganancia' => ($this->precio_unitario - $this->costo_unitario) * $this->cantidad,
        ]);

        return $sale;
    }

    /**
     * @param  \App\Models\Sale  $sale
     * @param  \App\Models\Article  $article
     * @return object
     */
    protected function linea($sale, $article)
    {
        return DB::table('article_sale')
            ->where('sale_id', $sale->id)
            ->where('article_id', $article->id)
            ->first();
    }

    /**
     * Corre el comando acotado a la venta del test (el argumento es "desde este id en adelante",
     * y la venta recien creada es la ultima).
     *
     * @param  \App\Models\Sale  $sale
     * @return void
     */
    protected function correr_comando($sale)
    {
        Artisan::call('set_costo_ventas', [
            'user_id' => $this->user->id,
            'from_sale_id' => $sale->id,
        ]);
    }

    /**
     * @group sales
     * @test
     */
    public function el_costo_de_la_linea_queda_unitario_no_el_total()
    {
        $article = $this->crear_articulo();
        $sale = $this->crear_venta_con_una_linea($article);

        $this->correr_comando($sale);

        $linea = $this->linea($sale, $article);

        $this->assertEquals(
            $this->costo_unitario,
            (float) $linea->cost,
            'article_sale.cost quedo con el costo TOTAL de la linea; por convencion esa columna es el costo UNITARIO'
        );

        $this->assertEquals(
            ($this->precio_unitario - $this->costo_unitario) * $this->cantidad,
            (float) $linea->ganancia,
            'article_sale.ganancia tiene que ser el total de la linea, igual que en SaleHelper::attachArticle'
        );

        $sale->refresh();

        $this->assertEquals(
            $this->costo_unitario * $this->cantidad,
            (float) $sale->total_cost,
            'sales.total_cost tiene que ser la suma de costo_unitario x cantidad'
        );
    }

    /**
     * @group sales
     * @test
     */
    public function correrlo_dos_veces_no_cambia_el_dato()
    {
        $article = $this->crear_articulo();
        $sale = $this->crear_venta_con_una_linea($article);

        $this->correr_comando($sale);

        $primera = $this->linea($sale, $article);
        $sale->refresh();
        $total_cost_primera = (float) $sale->total_cost;

        $this->correr_comando($sale);

        $segunda = $this->linea($sale, $article);
        $sale->refresh();

        $this->assertEquals(
            (float) $primera->cost,
            (float) $segunda->cost,
            'La segunda corrida volvio a multiplicar el costo: el comando no es idempotente'
        );

        $this->assertEquals(
            (float) $primera->ganancia,
            (float) $segunda->ganancia,
            'La segunda corrida cambio la ganancia de la linea'
        );

        $this->assertEquals(
            $total_cost_primera,
            (float) $sale->total_cost,
            'La segunda corrida cambio el total_cost de la venta'
        );
    }

    /**
     * La otra mitad de la idempotencia: un costo que ya esta guardado en el pivot ya esta
     * cotizado en la moneda de la venta (es lo que devuelve `SaleHelper::getCost()` cuando el
     * item trae pivot). Volver a cotizarlo en cada corrida multiplicaba el costo por el valor del
     * dolar una vez por corrida.
     *
     * @group sales
     * @test
     */
    public function no_vuelve_a_cotizar_un_costo_que_ya_estaba_guardado()
    {
        $article = $this->crear_articulo(['cost_in_dollars' => 1]);

        $dollar_original = $this->user->dollar;
        $cotizar_original = $this->user->cotizar_precios_en_dolares;

        $this->user->dollar = 1000;
        $this->user->cotizar_precios_en_dolares = 0;
        $this->user->timestamps = false;
        $this->user->save();

        $sale = $this->crear_venta_con_una_linea($article, ['moneda_id' => 1, 'valor_dolar' => 1000]);

        $this->correr_comando($sale);

        $linea = $this->linea($sale, $article);

        $this->user->dollar = $dollar_original;
        $this->user->cotizar_precios_en_dolares = $cotizar_original;
        $this->user->save();

        $this->assertEquals(
            $this->costo_unitario,
            (float) $linea->cost,
            'El costo guardado en el pivot se volvio a cotizar a dolar: cada corrida lo multiplica de nuevo'
        );
    }
}
