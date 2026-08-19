<?php

namespace Tests\Feature\SugerenciasCompras;

use App\Models\Address;
use App\Models\Article;
use App\Models\ArticlePurchase;
use App\Models\PurchaseSuggestion;
use App\Models\Provider;
use App\Models\ProviderPriceOffer;
use App\Models\Sale;
use App\Models\User;
use App\Services\Compras\OfertasDeProveedorService;
use App\Services\PurchaseSuggestion\PurchaseSuggestionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Misión sugerencias-compra-proveedores — archivo 4: el motor
 * (PurchaseSuggestionService) que decide QUÉ artículos entran, CUÁNTO
 * comprar de cada uno y A QUIÉN conviene comprarle.
 *
 * Todos los tests llaman a PurchaseSuggestionService::calcular_para_articulos()
 * directo, sin pasar por los jobs: acá se prueba el cálculo puro, no el
 * pipeline (eso es el archivo 5).
 *
 * R5 es el riesgo que más importa de este archivo: que la velocidad global se
 * pida directo a CoberturaService::velocidades_globales() y NUNCA se
 * reconstruya sumando velocidades_para() por sucursal (esa mezcla no es
 * aditiva). R6 protege la decisión explícita de Lucas de que las ofertas en
 * dólares se guarden y se muestren pero no compitan por "el más barato".
 */
class Motor_de_la_sugerencia_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    /** @var Address */
    protected $deposito;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config(['services.anthropic.api_key' => null]);

        $this->comercio = User::create([
            'name'     => 'Comercio motor P4',
            'email'    => 'sugerencias-compras-p4-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $this->deposito = Address::create([
            'street'  => 'Deposito motor P4',
            'user_id' => $this->comercio->id,
        ]);
    }

    /**
     * Cabecera de sugerencia con los cuatro parámetros del algoritmo
     * explícitos (los defaults de la clase), para que cada test sea legible
     * sin tener que ir a mirar las constantes.
     *
     * @param array $overrides
     * @return PurchaseSuggestion
     */
    protected function crear_suggestion(array $overrides = [])
    {
        return PurchaseSuggestion::create(array_merge([
            'user_id'                 => $this->comercio->id,
            'status'                  => 'pendiente',
            'origen_generacion'       => 'manual',
            'dias_punto_pedido'       => 15,
            'dias_cobertura_objetivo' => 30,
            'dias_lead_time'          => 7,
            'dias_vigencia_oferta'    => 120,
        ], $overrides));
    }

    /**
     * Artículo con stock y (opcionalmente) mínimo cargado en UN solo
     * depósito, sin ventas: dispara (si dispara) solo por el disparador de
     * mínimo, con cobertura_dias null (sin velocidad no hay cobertura).
     *
     * @param string $nombre
     * @param float $stock
     * @param float|null $stock_min
     * @return Article
     */
    protected function articulo_con_minimo($nombre, $stock, $stock_min = null)
    {
        $article = Article::create([
            'name'    => $nombre,
            'user_id' => $this->comercio->id,
        ]);

        $article->addresses()->attach($this->deposito->id, [
            'amount'    => $stock,
            'stock_min' => $stock_min,
        ]);

        return $article;
    }

    /**
     * Artículo con stock y una venta reciente, sin mínimo cargado: dispara
     * (si dispara) solo por cobertura.
     *
     * @param string $nombre
     * @param float $stock
     * @param float $unidades_recientes Vendidas en los últimos 90 días.
     * @param int $dias_atras
     * @return Article
     */
    protected function articulo_con_venta($nombre, $stock, $unidades_recientes, $dias_atras = 10)
    {
        $article = Article::create([
            'name'    => $nombre,
            'user_id' => $this->comercio->id,
        ]);

        $article->addresses()->attach($this->deposito->id, ['amount' => $stock]);

        $this->sembrar_venta($article->id, $this->deposito->id, $unidades_recientes, now()->subDays($dias_atras));

        return $article;
    }

    /**
     * Siembra una venta con su línea de article_purchases en la fecha pedida
     * (molde de SugerenciasStock).
     *
     * @param int $article_id
     * @param int $address_id
     * @param float $amount
     * @param \Carbon\Carbon $fecha
     * @return void
     */
    protected function sembrar_venta($article_id, $address_id, $amount, $fecha)
    {
        $sale = Sale::create([
            'user_id'    => $this->comercio->id,
            'created_at' => $fecha,
        ]);

        ArticlePurchase::create([
            'sale_id'    => $sale->id,
            'article_id' => $article_id,
            'address_id' => $address_id,
            'amount'     => $amount,
            'created_at' => $fecha,
        ]);
    }

    /**
     * Marca a $provider como titular del artículo (articles.provider_id) y,
     * opcionalmente, deja el pivot article_provider con un costo cargado.
     *
     * @param Article $article
     * @param Provider $provider
     * @param int|null $costo_pivot article_provider.cost es INTEGER: sin decimales.
     * @return void
     */
    protected function dar_titular($article, $provider, $costo_pivot = null)
    {
        $article->provider_id = $provider->id;
        $article->save();

        if ($costo_pivot !== null) {
            $article->providers()->attach($provider->id, ['cost' => $costo_pivot]);
        }
    }

    /**
     * Oferta vigente de precio de un proveedor para un artículo.
     *
     * @param Article $article
     * @param Provider $provider
     * @param float $cost
     * @param array $overrides
     * @return ProviderPriceOffer
     */
    protected function crear_oferta($article, $provider, $cost, array $overrides = [])
    {
        return ProviderPriceOffer::create(array_merge([
            'user_id'       => $this->comercio->id,
            'article_id'    => $article->id,
            'provider_id'   => $provider->id,
            'cost'          => $cost,
            'moneda_id'     => 1,
            'origen'        => 'importacion',
            'fecha'         => now()->toDateString(),
            'referencia_id' => null,
        ], $overrides));
    }

    /**
     * Busca en el array crudo devuelto por calcular_para_articulos() la línea
     * de un artículo puntual.
     *
     * @param array $lineas
     * @param int $article_id
     * @return array|null
     */
    protected function linea_de(array $lineas, $article_id)
    {
        foreach ($lineas as $linea) {
            if ((int) $linea['article_id'] === (int) $article_id) {
                return $linea;
            }
        }

        return null;
    }

    /**
     * R5, el riesgo que más importa de este archivo: la velocidad global de
     * DOS sucursales tiene que salir de mezclar las VENTAS SUMADAS de ambas
     * (velocidades_globales), no de sumar la velocidad YA MEZCLADA de cada
     * sucursal (velocidades_para) — la mezcla 2/3-1/3 no es aditiva por el
     * branch "si hay interanual".
     *
     * Sucursal A: 90 u. recientes, sin interanual -> mezcla_A = 1.0 (v_reciente sola).
     * Sucursal B: sin recientes, 180 u. interanual -> mezcla_B = (2*0+2)/3 = 0.6667.
     * Suma de mezclas por sucursal = 1.6667 (el resultado BUGUEADO).
     *
     * Sumando las VENTAS primero: reciente total 90, interanual total 180 ->
     * mezcla_total = (2*1.0 + 2.0)/3 = 1.3333 (el resultado CORRECTO).
     *
     * @group sugerencias-compras
     * @test
     */
    public function la_velocidad_global_mezcla_las_ventas_sumadas_no_la_suma_de_mezclas_por_sucursal()
    {
        $otro_deposito = Address::create([
            'street'  => 'Otro deposito P4 R5',
            'user_id' => $this->comercio->id,
        ]);

        $articulo = Article::create(['name' => 'Bulon R5', 'user_id' => $this->comercio->id]);

        // Stock total 10 entre las dos sucursales: con la velocidad correcta
        // (1.3333) la cobertura es 10/1.3333 = 7.5 días, que dispara igual.
        $articulo->addresses()->attach($this->deposito->id, ['amount' => 6]);
        $articulo->addresses()->attach($otro_deposito->id, ['amount' => 4]);

        // Sucursal A (deposito): 90 unidades recientes, nada interanual.
        $this->sembrar_venta($articulo->id, $this->deposito->id, 90, now()->subDays(10));
        // Sucursal B (otro_deposito): nada reciente, 180 unidades hace 400 días.
        $this->sembrar_venta($articulo->id, $otro_deposito->id, 180, now()->subDays(400));

        $suggestion = $this->crear_suggestion();
        $lineas = (new PurchaseSuggestionService($suggestion))->calcular_para_articulos([$articulo->id]);

        $linea = $this->linea_de($lineas, $articulo->id);

        $this->assertNotNull($linea, 'Con cobertura 7.5 días (<=15) el artículo tiene que disparar.');
        $this->assertEqualsWithDelta(
            1.3333,
            (float) $linea['velocidad_diaria'],
            0.0001,
            'La velocidad global tiene que salir de mezclar las ventas sumadas de las dos sucursales (1.3333), no de sumar la velocidad ya mezclada de cada una (1.6667).'
        );
    }

    /**
     * Blindaje mecánico de R5: el motor no puede reconstruir la velocidad
     * global reusando velocidades_para() (el método por sucursal) — tiene
     * que pedirle el número ya global al método hermano.
     *
     * @group sugerencias-compras
     * @test
     */
    public function el_motor_nunca_llama_a_velocidades_para()
    {
        $ruta = app_path('Services/PurchaseSuggestion/PurchaseSuggestionService.php');
        $contenido = file_get_contents($ruta);

        $this->assertStringNotContainsString(
            'velocidades_para(',
            $contenido,
            'PurchaseSuggestionService no puede llamar a velocidades_para(): esa mezcla es por sucursal y no es aditiva (R5).'
        );
    }

    /**
     * @group sugerencias-compras
     * @test
     */
    public function dispara_por_cobertura_por_debajo_del_punto_de_pedido()
    {
        // Stock 2, venta de 45 u. en los últimos 90 días -> velocidad 0.5/día,
        // cobertura 2/0.5 = 4 días (<=15: dispara).
        $articulo = $this->articulo_con_venta('Tornillo cobertura P4', 2, 45);

        $suggestion = $this->crear_suggestion();
        $lineas = (new PurchaseSuggestionService($suggestion))->calcular_para_articulos([$articulo->id]);
        $linea = $this->linea_de($lineas, $articulo->id);

        $this->assertNotNull($linea);
        $this->assertEqualsWithDelta(4.0, (float) $linea['cobertura_dias'], 0.01);
        // cantidad_velocidad = 0.5*(30+7) - 2 = 16.5 -> ceil = 17.
        $this->assertEquals(17.0, (float) $linea['cantidad_sugerida']);
    }

    /**
     * @group sugerencias-compras
     * @test
     */
    public function dispara_por_minimo_con_cobertura_null_por_falta_de_ventas()
    {
        // Sin ventas: velocidad 0 -> cobertura null (infinita, no cero).
        $articulo = $this->articulo_con_minimo('Tuerca minimo P4', 3, 10);

        $suggestion = $this->crear_suggestion();
        $lineas = (new PurchaseSuggestionService($suggestion))->calcular_para_articulos([$articulo->id]);
        $linea = $this->linea_de($lineas, $articulo->id);

        $this->assertNotNull($linea);
        $this->assertNull($linea['cobertura_dias']);
        // cantidad_minimo = 10 - 3 = 7.
        $this->assertEquals(7.0, (float) $linea['cantidad_sugerida']);
    }

    /**
     * cantidad_sugerida es el MAX entre los dos disparadores (acá gana el de
     * cobertura, más grande que el de mínimo), redondeado para arriba.
     *
     * @group sugerencias-compras
     * @test
     */
    public function la_cantidad_es_el_maximo_entre_los_dos_disparadores_redondeado_para_arriba()
    {
        $articulo = Article::create(['name' => 'Arandela max P4', 'user_id' => $this->comercio->id]);

        // Dispara minimo (5 < 6, cantidad_minimo = 1) Y cobertura (2.5/dia,
        // cobertura = 5/2.5 = 2 dias, cantidad_velocidad = 2.5*37-5 = 87.5).
        $articulo->addresses()->attach($this->deposito->id, ['amount' => 5, 'stock_min' => 6]);
        $this->sembrar_venta($articulo->id, $this->deposito->id, 225, now()->subDays(10));

        $suggestion = $this->crear_suggestion();
        $lineas = (new PurchaseSuggestionService($suggestion))->calcular_para_articulos([$articulo->id]);
        $linea = $this->linea_de($lineas, $articulo->id);

        $this->assertNotNull($linea);
        $this->assertEqualsWithDelta(2.5, (float) $linea['velocidad_diaria'], 0.0001);
        // max(87.5, 1) = 87.5 -> ceil = 88 (no 1, que es lo que pedía el mínimo).
        $this->assertEquals(88.0, (float) $linea['cantidad_sugerida']);
    }

    /**
     * Piso 1: aunque la cuenta de ambos disparadores dé cero o negativo, la
     * línea que SÍ disparó nunca puede sugerir comprar 0.
     *
     * @group sugerencias-compras
     * @test
     */
    public function la_cantidad_nunca_baja_de_uno_aunque_el_calculo_de_cero()
    {
        // Con objetivo y lead time en 0, el target de cobertura es el stock
        // mismo: cantidad_velocidad = velocidad*0 - stock = negativo.
        $suggestion = $this->crear_suggestion([
            'dias_cobertura_objetivo' => 0,
            'dias_lead_time'          => 0,
        ]);

        // Stock 10, venta de 900 u. -> velocidad 10/dia, cobertura 10/10=1 dia (<=15: dispara).
        $articulo = $this->articulo_con_venta('Clavo piso uno P4', 10, 900);

        $lineas = (new PurchaseSuggestionService($suggestion))->calcular_para_articulos([$articulo->id]);
        $linea = $this->linea_de($lineas, $articulo->id);

        $this->assertNotNull($linea);
        $this->assertEquals(
            1.0,
            (float) $linea['cantidad_sugerida'],
            'max(cantidad_velocidad, cantidad_minimo) dio 0 o menos, pero la línea disparó: tiene que sugerir al menos 1.'
        );
    }

    /**
     * @group sugerencias-compras
     * @test
     */
    public function elige_al_proveedor_de_menor_costo_vigente()
    {
        $titular = Provider::create(['name' => 'Proveedor titular P4a', 'user_id' => $this->comercio->id]);
        $barato  = Provider::create(['name' => 'Proveedor barato P4a', 'user_id' => $this->comercio->id]);
        $medio   = Provider::create(['name' => 'Proveedor medio P4a', 'user_id' => $this->comercio->id]);

        $articulo = $this->articulo_con_minimo('Pintura elige barato P4', 2, 10);
        $this->dar_titular($articulo, $titular, 100);

        $this->crear_oferta($articulo, $barato, 80);
        $this->crear_oferta($articulo, $medio, 90);

        $suggestion = $this->crear_suggestion();
        $lineas = (new PurchaseSuggestionService($suggestion))->calcular_para_articulos([$articulo->id]);
        $linea = $this->linea_de($lineas, $articulo->id);

        $this->assertNotNull($linea);
        $this->assertEquals($barato->id, $linea['provider_id']);
        $this->assertEqualsWithDelta(80.0, (float) $linea['costo_estimado'], 0.0001);
        $this->assertEquals($titular->id, $linea['provider_id_titular']);
        $this->assertEqualsWithDelta(100.0, (float) $linea['costo_proveedor_titular'], 0.0001);
        // cantidad_minimo = 10 - 2 = 8 -> ahorro = (100-80)*8 = 160.
        $this->assertEqualsWithDelta(160.0, (float) $linea['ahorro_estimado'], 0.0001);
    }

    /**
     * Empate exacto de costo -> gana el titular, aunque su costo salga de una
     * oferta propia y no del pivot (que a propósito queda con OTRO valor acá,
     * para probar que no es de ahí de donde sale costo_proveedor_titular).
     *
     * @group sugerencias-compras
     * @test
     */
    public function en_un_empate_exacto_gana_el_titular()
    {
        $titular = Provider::create(['name' => 'Proveedor titular P4b', 'user_id' => $this->comercio->id]);
        $otro    = Provider::create(['name' => 'Proveedor empatado P4b', 'user_id' => $this->comercio->id]);

        $articulo = $this->articulo_con_minimo('Cable empate P4', 2, 10);
        // Pivot con un costo BIEN distinto: si el resultado usara esto, el
        // test lo detectaría.
        $this->dar_titular($articulo, $titular, 999);

        $this->crear_oferta($articulo, $titular, 80);
        $this->crear_oferta($articulo, $otro, 80);

        $suggestion = $this->crear_suggestion();
        $lineas = (new PurchaseSuggestionService($suggestion))->calcular_para_articulos([$articulo->id]);
        $linea = $this->linea_de($lineas, $articulo->id);

        $this->assertNotNull($linea);
        $this->assertEquals($titular->id, $linea['provider_id'], 'En un empate exacto tiene que ganar el titular.');
        $this->assertEqualsWithDelta(80.0, (float) $linea['costo_proveedor_titular'], 0.0001, 'El costo del titular sale de SU oferta vigente (80), no del pivot (999).');
        $this->assertNull($linea['ahorro_estimado'], 'Cuando el elegido ES el titular no hay ahorro que mostrar.');
    }

    /**
     * Sin titular, el empate lo rompe el menor provider_id (determinismo
     * entre corridas).
     *
     * @group sugerencias-compras
     * @test
     */
    public function sin_titular_el_empate_lo_gana_el_menor_provider_id()
    {
        $proveedor_x = Provider::create(['name' => 'Proveedor X P4c', 'user_id' => $this->comercio->id]);
        $proveedor_y = Provider::create(['name' => 'Proveedor Y P4c', 'user_id' => $this->comercio->id]);

        $articulo = $this->articulo_con_minimo('Cinta sin titular P4', 2, 10);
        // A propósito SIN dar_titular: articles.provider_id queda null.

        $this->crear_oferta($articulo, $proveedor_x, 50);
        $this->crear_oferta($articulo, $proveedor_y, 50);

        $suggestion = $this->crear_suggestion();
        $lineas = (new PurchaseSuggestionService($suggestion))->calcular_para_articulos([$articulo->id]);
        $linea = $this->linea_de($lineas, $articulo->id);

        $this->assertNotNull($linea);
        $this->assertEquals(min($proveedor_x->id, $proveedor_y->id), $linea['provider_id']);
        $this->assertNull($linea['provider_id_titular']);
    }

    /**
     * @group sugerencias-compras
     * @test
     */
    public function una_oferta_fuera_de_vigencia_no_compite()
    {
        $vieja  = Provider::create(['name' => 'Proveedor oferta vieja P4', 'user_id' => $this->comercio->id]);
        $vigente = Provider::create(['name' => 'Proveedor oferta vigente P4', 'user_id' => $this->comercio->id]);

        $articulo = $this->articulo_con_minimo('Silicona vigencia P4', 2, 10);
        // Sin titular: si la vieja compitiera, ganaría por ser más barata.

        $this->crear_oferta($articulo, $vieja, 10, ['fecha' => now()->subDays(200)->toDateString()]);
        $this->crear_oferta($articulo, $vigente, 200);

        $suggestion = $this->crear_suggestion(); // dias_vigencia_oferta = 120 por default.
        $lineas = (new PurchaseSuggestionService($suggestion))->calcular_para_articulos([$articulo->id]);
        $linea = $this->linea_de($lineas, $articulo->id);

        $this->assertNotNull($linea);
        $this->assertEquals(
            $vigente->id,
            $linea['provider_id'],
            'La oferta de hace 200 días está fuera de la ventana de 120: no puede competir aunque sea más barata.'
        );
        $this->assertEqualsWithDelta(200.0, (float) $linea['costo_estimado'], 0.0001);
    }

    /**
     * @group sugerencias-compras
     * @test
     */
    public function sin_ofertas_vigentes_cae_al_costo_de_referencia_del_titular()
    {
        $titular = Provider::create(['name' => 'Proveedor unico P4', 'user_id' => $this->comercio->id]);

        $articulo = $this->articulo_con_minimo('Masilla sin ofertas P4', 2, 10);
        $this->dar_titular($articulo, $titular, 123);
        // Sin ninguna fila en provider_price_offers para este artículo.

        $suggestion = $this->crear_suggestion();
        $lineas = (new PurchaseSuggestionService($suggestion))->calcular_para_articulos([$articulo->id]);
        $linea = $this->linea_de($lineas, $articulo->id);

        $this->assertNotNull($linea);
        $this->assertEquals($titular->id, $linea['provider_id']);
        $this->assertEqualsWithDelta(123.0, (float) $linea['costo_estimado'], 0.0001, 'Sin oferta vigente, el costo cae al pivot article_provider.cost del titular.');
        $this->assertNull($linea['oferta_fecha'], 'El costo vino del pivot, no de una oferta fechada.');
        $this->assertNull($linea['ahorro_estimado']);
    }

    /**
     * Sin titular y sin ninguna oferta, la línea NO se descarta: el usuario
     * necesita ver que le falta comprar eso, agrupado como "sin proveedor".
     *
     * @group sugerencias-compras
     * @test
     */
    public function sin_titular_y_sin_ofertas_la_linea_queda_con_provider_id_null()
    {
        $articulo = $this->articulo_con_minimo('Grifería huerfana P4', 1, 10);

        $suggestion = $this->crear_suggestion();
        $lineas = (new PurchaseSuggestionService($suggestion))->calcular_para_articulos([$articulo->id]);
        $linea = $this->linea_de($lineas, $articulo->id);

        $this->assertNotNull($linea, 'Sin proveedor la línea igual tiene que aparecer.');
        $this->assertNull($linea['provider_id']);
        $this->assertNull($linea['provider_id_titular']);
        $this->assertNull($linea['costo_estimado']);
        $this->assertNull($linea['costo_proveedor_titular']);
    }

    /**
     * R6: las ofertas en dólares se guardan y se pueden consultar, pero NO
     * compiten por "el más barato" (comparar pesos contra dólares sin
     * cotización es la clase de error que este proyecto ya pagó caro).
     *
     * @group sugerencias-compras
     * @test
     */
    public function las_ofertas_en_dolares_no_compiten_pero_la_fila_existe_y_se_puede_consultar()
    {
        $en_dolares = Provider::create(['name' => 'Proveedor en USD P4', 'user_id' => $this->comercio->id]);
        $en_pesos   = Provider::create(['name' => 'Proveedor en ARS P4', 'user_id' => $this->comercio->id]);

        $articulo = $this->articulo_con_minimo('Tornillo moneda P4', 2, 10);

        // Carísimamente más barato en USD (1 vs 50) pero NO puede ganar.
        $this->crear_oferta($articulo, $en_dolares, 1, ['moneda_id' => 2]);
        $this->crear_oferta($articulo, $en_pesos, 50, ['moneda_id' => 1]);

        $suggestion = $this->crear_suggestion();
        $lineas = (new PurchaseSuggestionService($suggestion))->calcular_para_articulos([$articulo->id]);
        $linea = $this->linea_de($lineas, $articulo->id);

        $this->assertNotNull($linea);
        $this->assertEquals(
            $en_pesos->id,
            $linea['provider_id'],
            'La oferta en dólares es más barata pero no puede competir contra la de pesos.'
        );

        // Y la fila en dólares existe y se puede leer: no se descartó al escribirla.
        $todas = OfertasDeProveedorService::mejores_ofertas_para([$articulo->id], $this->comercio->id, 120);
        $this->assertArrayHasKey($en_dolares->id, $todas[$articulo->id]);
        $this->assertEquals(2, (int) $todas[$articulo->id][$en_dolares->id]['moneda_id']);
        $this->assertEqualsWithDelta(1.0, (float) $todas[$articulo->id][$en_dolares->id]['cost'], 0.0001);
    }

    /**
     * @group sugerencias-compras
     * @test
     */
    public function los_articulos_inactivos_no_entran_a_la_sugerencia()
    {
        $articulo = $this->articulo_con_minimo('Cerradura inactiva P4', 2, 10);
        $articulo->status = 'inactive';
        $articulo->save();

        $suggestion = $this->crear_suggestion();
        $lineas = (new PurchaseSuggestionService($suggestion))->calcular_para_articulos([$articulo->id]);

        $this->assertEmpty($lineas, 'Un artículo inactive tiene que quedar afuera aunque dispare por mínimo.');
    }
}
