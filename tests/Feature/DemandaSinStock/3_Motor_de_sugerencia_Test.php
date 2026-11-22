<?php

namespace Tests\Feature\DemandaSinStock;

use App\Jobs\GeneratePurchaseSuggestionChunksJob;
use App\Models\Address;
use App\Models\Article;
use App\Models\ExtencionEmpresa;
use App\Models\PurchaseSuggestion;
use App\Models\PurchaseSuggestionArticle;
use App\Models\User;
use App\Services\PurchaseSuggestion\PurchaseSuggestionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Misión demanda-sin-stock-vender — archivo 3: el motor de sugerencias de
 * compra lee la demanda no satisfecha de Vender (criterios CA6 a CA9).
 *
 * Hoy un artículo en cero, sin ventas y sin mínimo NO entra nunca a la
 * sugerencia (sin velocidad no hay cobertura): justo el que el mostrador
 * preguntó y no había. La misión le agrega un tercer disparador, la demanda
 * de los últimos 30 días, y dos claves nuevas en cada línea.
 *
 * Mismo estilo que SugerenciasCompras/4: se llama al motor directo
 * (calcular_para_articulos), sin pasar por los jobs; sólo CA9 corre el job.
 * Los frenos se siembran directo en `unmet_demands` para probar el motor sin
 * depender del endpoint.
 */
class Motor_de_sugerencia_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User Dueño del comercio. */
    protected $comercio;

    /** @var User Dueño de otro comercio. */
    protected $otro_comercio;

    /** @var Address Depósito del comercio. */
    protected $deposito;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config(['services.anthropic.api_key' => null]);

        $this->comercio = User::create([
            'name'     => 'Comercio motor D3',
            'email'    => 'demanda-d3-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $this->otro_comercio = User::create([
            'name'     => 'Otro comercio motor D3',
            'email'    => 'demanda-d3-otro-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $this->deposito = Address::create([
            'street'  => 'Deposito motor D3',
            'user_id' => $this->comercio->id,
        ]);

        // La corrida completa (CA9) opera sobre el dueño de la instancia.
        config(['app.USER_ID' => $this->comercio->id]);
    }

    /**
     * Cabecera de sugerencia con los parámetros por defecto del algoritmo.
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
     * Artículo del comercio con stock (y mínimo opcional) en el depósito,
     * sin ventas.
     *
     * @param string $nombre
     * @param float $stock
     * @param float|null $stock_min
     * @return Article
     */
    protected function articulo($nombre, $stock, $stock_min = null)
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
     * Siembra un freno de Vender directo en la tabla.
     *
     * @param int $article_id
     * @param array $overrides Cualquier columna de unmet_demands.
     * @return void
     */
    protected function sembrar_freno($article_id, array $overrides = [])
    {
        DB::table('unmet_demands')->insert(array_merge([
            'user_id'          => $this->comercio->id,
            'employee_id'      => $this->comercio->id,
            'article_id'       => $article_id,
            'address_id'       => $this->deposito->id,
            'motivo'           => 'sin_stock',
            'cantidad_pedida'  => null,
            'stock_disponible' => 0,
            'canceled_at'      => null,
            'created_at'       => now()->subDays(2),
            'updated_at'       => now()->subDays(2),
        ], $overrides));
    }

    /**
     * Corre el motor para los artículos pedidos.
     *
     * @param array $article_ids
     * @return array
     */
    protected function calcular(array $article_ids)
    {
        return (new PurchaseSuggestionService($this->crear_suggestion()))->calcular_para_articulos($article_ids);
    }

    /**
     * Busca la línea de un artículo en el array devuelto por el motor.
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
     * Da al comercio la extensión que habilita las sugerencias de compra.
     *
     * @return void
     */
    protected function dar_extension()
    {
        $extencion = ExtencionEmpresa::where('slug', 'agentes_ia')->first();

        if (!$extencion) {
            $extencion = ExtencionEmpresa::forceCreate([
                'slug' => 'agentes_ia',
                'name' => 'Agentes de IA',
            ]);
        }

        $this->comercio->extencions()->attach($extencion->id);
        $this->comercio->load('extencions');
    }

    /**
     * CA6: el caso que hoy se pierde. "Mecha 8" sin ventas, sin mínimo y con
     * stock 0, con dos frenos sin_stock en los últimos 30 días, entra con una
     * línea que sugiere 2 unidades (una por freno).
     *
     * @group demanda-sin-stock
     * @test
     */
    public function articulo_sin_ventas_con_demanda_entra()
    {
        $mecha = $this->articulo('Mecha 8', 0);

        $this->sembrar_freno($mecha->id);
        $this->sembrar_freno($mecha->id, ['created_at' => now()->subDays(10), 'updated_at' => now()->subDays(10)]);

        $lineas = $this->calcular([$mecha->id]);
        $linea = $this->linea_de($lineas, $mecha->id);

        $this->assertNotNull($linea, 'Con demanda reciente el artículo tiene que entrar aunque no tenga ventas ni mínimo.');
        $this->assertEqualsWithDelta(2.0, (float) $linea['cantidad_sugerida'], 0.0001);
        $this->assertSame(2, (int) $linea['demanda_no_satisfecha_veces']);
        $this->assertEqualsWithDelta(2.0, (float) $linea['demanda_no_satisfecha_cantidad'], 0.0001);
    }

    /**
     * CA7: con stock 2 y un freno de 5 unidades, falta comprar 3. Las
     * columnas muestran la demanda bruta (1 vez, 5 unidades).
     *
     * @group demanda-sin-stock
     * @test
     */
    public function stock_insuficiente_sugiere_lo_que_falta()
    {
        $broca = $this->articulo('Broca 10', 2);

        $this->sembrar_freno($broca->id, [
            'motivo'           => 'stock_insuficiente',
            'cantidad_pedida'  => 5,
            'stock_disponible' => 2,
        ]);

        $linea = $this->linea_de($this->calcular([$broca->id]), $broca->id);

        $this->assertNotNull($linea);
        $this->assertEqualsWithDelta(3.0, (float) $linea['cantidad_sugerida'], 0.0001);
        $this->assertSame(1, (int) $linea['demanda_no_satisfecha_veces']);
        $this->assertEqualsWithDelta(5.0, (float) $linea['demanda_no_satisfecha_cantidad'], 0.0001);
    }

    /**
     * CA7: si el artículo ya dispara por mínimo, la cantidad es el MÁXIMO
     * entre mínimo y demanda, no la suma (mínimo 10, stock 4: faltan 6; el
     * freno de 3 queda tapado por esos 6).
     *
     * @group demanda-sin-stock
     * @test
     */
    public function demanda_y_minimo_toman_el_maximo()
    {
        $tornillo = $this->articulo('Tornillo 6', 4, 10);

        $this->sembrar_freno($tornillo->id, [
            'motivo'           => 'stock_insuficiente',
            'cantidad_pedida'  => 3,
            'stock_disponible' => 4,
        ]);

        $linea = $this->linea_de($this->calcular([$tornillo->id]), $tornillo->id);

        $this->assertNotNull($linea, 'Dispara por mínimo.');
        $this->assertEqualsWithDelta(6.0, (float) $linea['cantidad_sugerida'], 0.0001, 'Máximo, no suma (6, no 9).');
        $this->assertSame(1, (int) $linea['demanda_no_satisfecha_veces']);
        $this->assertEqualsWithDelta(3.0, (float) $linea['demanda_no_satisfecha_cantidad'], 0.0001);
    }

    /**
     * CA7: un artículo que dispara por mínimo y no tiene ningún freno lleva
     * las dos columnas nuevas en cero (la clave existe en TODA línea).
     *
     * @group demanda-sin-stock
     * @test
     */
    public function sin_demanda_las_columnas_quedan_en_cero()
    {
        $clavo = $this->articulo('Clavo 2', 2, 10);

        $linea = $this->linea_de($this->calcular([$clavo->id]), $clavo->id);

        $this->assertNotNull($linea);
        $this->assertArrayHasKey('demanda_no_satisfecha_veces', $linea);
        $this->assertArrayHasKey('demanda_no_satisfecha_cantidad', $linea);
        $this->assertSame(0, (int) $linea['demanda_no_satisfecha_veces']);
        $this->assertEqualsWithDelta(0.0, (float) $linea['demanda_no_satisfecha_cantidad'], 0.0001);
    }

    /**
     * CA8: lo que NO cuenta. Un freno cancelado, uno de hace 31 días y uno
     * de otro dueño no hacen entrar al artículo. Como control, un freno de
     * hace 29 días sí lo hace entrar (para que el test no pase por casualidad).
     *
     * @group demanda-sin-stock
     * @test
     */
    public function cancelada_vieja_o_ajena_no_cuenta()
    {
        $cancelado = $this->articulo('Cancelado', 0);
        $viejo = $this->articulo('Viejo', 0);
        $ajeno = $this->articulo('Ajeno', 0);
        $control = $this->articulo('Control', 0);

        $this->sembrar_freno($cancelado->id, ['canceled_at' => now()->subDay()]);

        $this->sembrar_freno($viejo->id, [
            'created_at' => now()->subDays(31),
            'updated_at' => now()->subDays(31),
        ]);

        // Freno de otro dueño apuntando al mismo artículo: no puede contaminar.
        $this->sembrar_freno($ajeno->id, [
            'user_id'     => $this->otro_comercio->id,
            'employee_id' => $this->otro_comercio->id,
            'address_id'  => null,
        ]);

        $this->sembrar_freno($control->id, [
            'created_at' => now()->subDays(29),
            'updated_at' => now()->subDays(29),
        ]);

        $lineas = $this->calcular([$cancelado->id, $viejo->id, $ajeno->id, $control->id]);

        $this->assertNull($this->linea_de($lineas, $cancelado->id), 'Un freno cancelado no cuenta.');
        $this->assertNull($this->linea_de($lineas, $viejo->id), 'Un freno de hace 31 días queda fuera de la ventana de 30.');
        $this->assertNull($this->linea_de($lineas, $ajeno->id), 'Un freno de otro dueño no cuenta.');
        $this->assertNotNull($this->linea_de($lineas, $control->id), 'Un freno de hace 29 días sí cuenta.');
    }

    /**
     * CA8: si el comercio repuso por encima de lo que se había pedido, la
     * demanda vieja no dispara (stock 10 contra un freno de 5).
     *
     * @group demanda-sin-stock
     * @test
     */
    public function repuesto_por_encima_no_dispara()
    {
        $repuesto = $this->articulo('Repuesto', 10);

        $this->sembrar_freno($repuesto->id, [
            'motivo'           => 'stock_insuficiente',
            'cantidad_pedida'  => 5,
            'stock_disponible' => 2,
        ]);

        $this->assertNull($this->linea_de($this->calcular([$repuesto->id]), $repuesto->id));
    }

    /**
     * CA9: la corrida completa (job de generación + chunk con bulk insert)
     * guarda la línea de "Mecha 8" con las columnas nuevas pobladas. Prueba
     * que purchase_suggestion_articles acepta las claves que agrega el motor.
     *
     * @group demanda-sin-stock
     * @test
     */
    public function la_corrida_guarda_las_columnas_de_demanda()
    {
        $this->dar_extension();

        $mecha = $this->articulo('Mecha 8', 0);

        $this->sembrar_freno($mecha->id);
        $this->sembrar_freno($mecha->id, ['created_at' => now()->subDays(5), 'updated_at' => now()->subDays(5)]);

        $suggestion = $this->crear_suggestion();
        (new GeneratePurchaseSuggestionChunksJob($suggestion->id))->handle();
        $suggestion->refresh();

        $this->assertEquals('terminado', $suggestion->status);

        $linea = PurchaseSuggestionArticle::where('purchase_suggestion_id', $suggestion->id)
            ->where('article_id', $mecha->id)
            ->first();

        $this->assertNotNull($linea, 'La corrida completa tiene que dejar la línea del artículo con demanda.');
        $this->assertSame(2, (int) $linea->demanda_no_satisfecha_veces);
        $this->assertEqualsWithDelta(2.0, (float) $linea->demanda_no_satisfecha_cantidad, 0.0001);
        $this->assertEqualsWithDelta(2.0, (float) $linea->cantidad_sugerida, 0.0001);
    }
}
