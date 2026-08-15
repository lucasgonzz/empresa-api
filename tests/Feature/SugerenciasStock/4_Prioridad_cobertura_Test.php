<?php

namespace Tests\Feature\SugerenciasStock;

use App\Jobs\GenerateStockSuggestionChunksJob;
use App\Models\Address;
use App\Models\Article;
use App\Models\ArticlePurchase;
use App\Models\Sale;
use App\Models\StockSuggestion;
use App\Models\StockSuggestionArticle;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Misión sugerencias-inteligentes-stock — P5: prioridad por días hasta quiebre.
 *
 * Protege la fórmula de velocidad (90 días recientes, mezcla 2/3–1/3 con la
 * misma ventana del año anterior), la cobertura stock/velocidad, el ranking
 * 1..N materializado al terminar la corrida, y los filtros que hacen honesto
 * al número: ventas de otra sucursal no cuentan, ventas borradas tampoco.
 */
class Prioridad_cobertura_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    /** @var Address */
    protected $origen;

    /** @var Address */
    protected $destino;

    protected function setUp(): void
    {
        parent::setUp();

        // Los jobs notifican al terminar; el test no depende de Pusher.
        Notification::fake();

        // Sin credencial de Anthropic el job del resumen no se despacha (degradación D28).
        // Con la cola sync de phpunit.xml y la clave real del .env.testing, el pipeline
        // completo saldría a la API de verdad en cada corrida: costo y flakiness.
        config(['services.anthropic.api_key' => null]);

        $this->comercio = User::create([
            'name'     => 'Comercio sugerencias P5',
            'email'    => 'sugerencias-p5-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $this->origen = Address::create([
            'street'  => 'Deposito origen P5',
            'user_id' => $this->comercio->id,
        ]);

        $this->destino = Address::create([
            'street'  => 'Sucursal destino P5',
            'user_id' => $this->comercio->id,
        ]);
    }

    /**
     * Artículo del comercio con déficit en el destino (origen lleno).
     *
     * @param string $nombre
     * @param float $stock_destino
     * @return Article
     */
    protected function articulo_en_deficit($nombre, $stock_destino = 2)
    {
        $article = Article::create([
            'name'    => $nombre,
            'user_id' => $this->comercio->id,
        ]);

        $article->addresses()->attach($this->origen->id, [
            'amount' => 100, 'stock_min' => 10, 'stock_max' => 20,
        ]);
        $article->addresses()->attach($this->destino->id, [
            'amount' => $stock_destino, 'stock_min' => 10, 'stock_max' => 20,
        ]);

        return $article;
    }

    /**
     * Siembra una venta con su línea de article_purchases en la fecha pedida.
     *
     * @param int $article_id
     * @param int $address_id
     * @param float $amount
     * @param \Carbon\Carbon $fecha
     * @param bool $borrada true = la venta queda soft-deleted
     * @return void
     */
    protected function sembrar_venta($article_id, $address_id, $amount, $fecha, $borrada = false)
    {
        $sale = Sale::create([
            'user_id'    => $this->comercio->id,
            'created_at' => $fecha,
        ]);

        if ($borrada) {
            // update directo para no disparar la lógica de borrado del sistema.
            Sale::where('id', $sale->id)->update(['deleted_at' => now()]);
        }

        ArticlePurchase::create([
            'sale_id'    => $sale->id,
            'article_id' => $article_id,
            'address_id' => $address_id,
            'amount'     => $amount,
            'created_at' => $fecha,
        ]);
    }

    /**
     * Crea la sugerencia y corre el pipeline completo en sync.
     *
     * @return StockSuggestion
     */
    protected function correr_sugerencia()
    {
        $suggestion = StockSuggestion::create([
            'modo'          => 'minimo',
            'origen'        => 'absoluto',
            'limite_origen' => 'minimo',
            'status'        => 'pendiente',
            'user_id'       => $this->comercio->id,
        ]);

        (new GenerateStockSuggestionChunksJob($suggestion->id))->handle();

        return $suggestion->fresh();
    }

    /**
     * Línea de la sugerencia para un artículo.
     *
     * @param StockSuggestion $suggestion
     * @param Article $article
     * @return StockSuggestionArticle|null
     */
    protected function linea($suggestion, $article)
    {
        return StockSuggestionArticle::where('stock_suggestion_id', $suggestion->id)
            ->where('article_id', $article->id)
            ->first();
    }

    /**
     * @group sugerencias-stock
     * @test
     */
    public function la_cobertura_es_stock_sobre_velocidad_de_los_ultimos_90_dias()
    {
        $articulo = $this->articulo_en_deficit('Martillo cobertura', 2);

        // 45 unidades en la ventana reciente → velocidad 45/90 = 0.5/día.
        $this->sembrar_venta($articulo->id, $this->destino->id, 45, now()->subDays(10));

        $suggestion = $this->correr_sugerencia();
        $linea = $this->linea($suggestion, $articulo);

        $this->assertNotNull($linea);
        $this->assertEquals('terminado', $suggestion->status);
        $this->assertEqualsWithDelta(0.5, (float) $linea->velocidad_diaria, 0.0001);
        // cobertura = stock destino / velocidad = 2 / 0.5 = 4 días.
        $this->assertEqualsWithDelta(4.0, (float) $linea->cobertura_dias, 0.01);
        $this->assertEqualsWithDelta(2.0, (float) $linea->stock_destino, 0.01);
    }

    /**
     * @group sugerencias-stock
     * @test
     */
    public function con_historia_interanual_la_velocidad_mezcla_dos_tercios_y_un_tercio()
    {
        $articulo = $this->articulo_en_deficit('Martillo interanual', 2);

        // Reciente: 90 unidades → v_reciente = 1/día.
        $this->sembrar_venta($articulo->id, $this->destino->id, 90, now()->subDays(10));
        // Misma ventana del año pasado (hace 400 días): 180 → v_interanual = 2/día.
        $this->sembrar_venta($articulo->id, $this->destino->id, 180, now()->subDays(400));

        $suggestion = $this->correr_sugerencia();
        $linea = $this->linea($suggestion, $articulo);

        $this->assertNotNull($linea);
        // velocidad = (2·1 + 2) / 3 = 1.3333
        $this->assertEqualsWithDelta(1.3333, (float) $linea->velocidad_diaria, 0.0001);
        // cobertura = 2 / 1.3333 = 1.5 días.
        $this->assertEqualsWithDelta(1.5, (float) $linea->cobertura_dias, 0.01);
    }

    /**
     * @group sugerencias-stock
     * @test
     */
    public function sin_ventas_la_cobertura_es_null_y_la_linea_ordena_ultima()
    {
        $con_ventas = $this->articulo_en_deficit('Vendido P5');
        $sin_ventas = $this->articulo_en_deficit('Clavado P5');

        $this->sembrar_venta($con_ventas->id, $this->destino->id, 45, now()->subDays(10));

        $suggestion = $this->correr_sugerencia();

        $linea_vendida = $this->linea($suggestion, $con_ventas);
        $linea_clavada = $this->linea($suggestion, $sin_ventas);

        $this->assertNotNull($linea_vendida);
        $this->assertNotNull($linea_clavada);

        $this->assertNull(
            $linea_clavada->cobertura_dias,
            'Sin ventas la cobertura tiene que ser null (infinita), no cero.'
        );
        $this->assertTrue(
            $linea_clavada->prioridad > $linea_vendida->prioridad,
            'El artículo que no se vende no puede ser más urgente que el que sí: ese es exactamente el error que la priorización corrige.'
        );
    }

    /**
     * @group sugerencias-stock
     * @test
     */
    public function la_prioridad_es_un_ranking_completo_sin_huecos_ni_repetidos()
    {
        $urgente  = $this->articulo_en_deficit('Urgente P5');   // cobertura 2
        $medio    = $this->articulo_en_deficit('Medio P5');     // cobertura 4
        $dormido  = $this->articulo_en_deficit('Dormido P5');   // cobertura null

        $this->sembrar_venta($urgente->id, $this->destino->id, 90, now()->subDays(10));
        $this->sembrar_venta($medio->id, $this->destino->id, 45, now()->subDays(10));

        $suggestion = $this->correr_sugerencia();

        $prioridades = StockSuggestionArticle::where('stock_suggestion_id', $suggestion->id)
            ->orderBy('prioridad')
            ->pluck('prioridad')
            ->toArray();

        $this->assertEquals(
            range(1, count($prioridades)),
            $prioridades,
            'La prioridad tiene que ser 1..N sin huecos ni repetidos.'
        );

        // Y el orden respeta la cobertura ascendente con la null al final.
        $this->assertEquals(1, $this->linea($suggestion, $urgente)->prioridad);
        $this->assertEquals(2, $this->linea($suggestion, $medio)->prioridad);
        $this->assertEquals(3, $this->linea($suggestion, $dormido)->prioridad);
    }

    /**
     * @group sugerencias-stock
     * @test
     */
    public function las_ventas_de_otra_sucursal_no_cuentan_para_el_destino()
    {
        $articulo = $this->articulo_en_deficit('Vendido en otra P5');

        // Ventas fuertes pero en el ORIGEN: la velocidad del destino sigue en cero.
        $this->sembrar_venta($articulo->id, $this->origen->id, 90, now()->subDays(10));

        $suggestion = $this->correr_sugerencia();
        $linea = $this->linea($suggestion, $articulo);

        $this->assertNotNull($linea);
        $this->assertEqualsWithDelta(0.0, (float) $linea->velocidad_diaria, 0.0001);
        $this->assertNull(
            $linea->cobertura_dias,
            'Las ventas de una sucursal no pueden sumar a la velocidad de otra.'
        );
    }

    /**
     * @group sugerencias-stock
     * @test
     */
    public function una_venta_eliminada_no_suma_a_la_velocidad()
    {
        $articulo = $this->articulo_en_deficit('Con venta borrada P5', 2);

        $this->sembrar_venta($articulo->id, $this->destino->id, 45, now()->subDays(10));
        // Misma cantidad pero con la venta soft-deleted: no debe sumar.
        $this->sembrar_venta($articulo->id, $this->destino->id, 45, now()->subDays(12), true);

        $suggestion = $this->correr_sugerencia();
        $linea = $this->linea($suggestion, $articulo);

        $this->assertNotNull($linea);
        // Solo la venta viva: 45/90 = 0.5, no 1.0.
        $this->assertEqualsWithDelta(0.5, (float) $linea->velocidad_diaria, 0.0001);
    }
}
