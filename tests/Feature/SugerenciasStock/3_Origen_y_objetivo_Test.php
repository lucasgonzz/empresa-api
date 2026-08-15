<?php

namespace Tests\Feature\SugerenciasStock;

use App\Models\Address;
use App\Models\Article;
use App\Models\StockSuggestion;
use App\Models\User;
use App\Services\StockSuggestion\StockSuggestionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Misión sugerencias-inteligentes-stock — P4: depósitos de origen, objetivo
 * máximo y el cálculo scopeado por comercio.
 *
 * Protege los cuatro escalones de obtenerOrigen() (designados sanos →
 * designados en déficit → no designados sin déficit → cualquiera), la rama
 * nueva modo='maximo' y el filtro por user_id del dueño de la sugerencia.
 *
 * El caso testigo "sin designados" es el seguro de compatibilidad: con la
 * columna es_deposito_origen en 0 en todas las sucursales, el resultado tiene
 * que ser byte a byte el del sistema viejo.
 */
class Origen_y_objetivo_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * Comercio dueño de los artículos del test (se crea uno propio para no
     * depender del fixture de la base del slot).
     *
     * @var User
     */
    protected $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->comercio = User::create([
            'name'     => 'Comercio sugerencias P4',
            'email'    => 'sugerencias-p4-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);
    }

    /**
     * Crea una sucursal del comercio del test.
     *
     * @param string $nombre
     * @param bool $designada
     * @return Address
     */
    protected function sucursal($nombre, $designada = false)
    {
        return Address::create([
            'street'             => $nombre,
            'user_id'            => $this->comercio->id,
            'es_deposito_origen' => $designada,
        ]);
    }

    /**
     * Crea un artículo del comercio con stock por sucursal.
     *
     * @param string $nombre
     * @param array $stocks [address_id => ['amount' =>, 'stock_min' =>, 'stock_max' =>]]
     * @param int|null $user_id Dueño del artículo (default: el comercio del test)
     * @return Article
     */
    protected function articulo_con_stock($nombre, array $stocks, $user_id = null)
    {
        $article = Article::create([
            'name'    => $nombre,
            'user_id' => $user_id !== null ? $user_id : $this->comercio->id,
        ]);

        foreach ($stocks as $address_id => $pivot) {
            $article->addresses()->attach($address_id, $pivot);
        }

        return $article;
    }

    /**
     * Crea la sugerencia y devuelve las líneas calculadas para esos artículos.
     *
     * @param array $article_ids
     * @param string $modo
     * @param string $limite_origen
     * @return \Illuminate\Support\Collection
     */
    protected function calcular(array $article_ids, $modo = 'minimo', $limite_origen = 'minimo')
    {
        $suggestion = StockSuggestion::create([
            'modo'          => $modo,
            'origen'        => 'absoluto',
            'limite_origen' => $limite_origen,
            'status'        => 'pendiente',
            'user_id'       => $this->comercio->id,
        ]);

        $service = new StockSuggestionService($suggestion);

        return $service->getSuggestionsForArticles($article_ids);
    }

    /**
     * Caso testigo: sin ningún depósito designado, el resultado es el del
     * sistema viejo — origen por mayor stock, déficit contra el mínimo.
     *
     * @group sugerencias-stock
     * @test
     */
    public function sin_designados_el_resultado_es_el_historico()
    {
        $grande = $this->sucursal('Deposito grande');
        $chica  = $this->sucursal('Sucursal chica');

        $articulo = $this->articulo_con_stock('Tornillo testigo', [
            $grande->id => ['amount' => 100, 'stock_min' => 10, 'stock_max' => 20],
            $chica->id  => ['amount' => 2,   'stock_min' => 10, 'stock_max' => 20],
        ]);

        $lineas = $this->calcular([$articulo->id]);

        $this->assertCount(1, $lineas);
        $this->assertEquals($grande->id, $lineas[0]['from_address_id']);
        $this->assertEquals($chica->id, $lineas[0]['to_address_id']);
        // needed = 10 - 2 = 8; disponible = 100 - 10 = 90 → mueve 8.
        $this->assertEquals(8, $lineas[0]['suggested_amount']);
    }

    /**
     * @group sugerencias-stock
     * @test
     */
    public function el_designado_sano_es_el_origen_aunque_otro_tenga_mas_stock()
    {
        $grande    = $this->sucursal('Deposito con mas stock');
        $designado = $this->sucursal('Deposito designado', true);
        $chica     = $this->sucursal('Sucursal en deficit');

        $articulo = $this->articulo_con_stock('Clavo designado', [
            $grande->id    => ['amount' => 100, 'stock_min' => 10, 'stock_max' => 20],
            $designado->id => ['amount' => 50,  'stock_min' => 10, 'stock_max' => 20],
            $chica->id     => ['amount' => 2,   'stock_min' => 10, 'stock_max' => 20],
        ]);

        $lineas = $this->calcular([$articulo->id]);

        $this->assertCount(1, $lineas);
        $this->assertEquals(
            $designado->id,
            $lineas[0]['from_address_id'],
            'Con un depósito designado sano, el origen tiene que ser ese aunque otro tenga más stock.'
        );
        $this->assertEquals($chica->id, $lineas[0]['to_address_id']);
    }

    /**
     * Escalón 2: el designado en déficit (bajo su mínimo) sigue siendo el
     * origen mientras tenga stock por encima de su límite de vaciado.
     *
     * @group sugerencias-stock
     * @test
     */
    public function el_designado_en_deficit_pero_con_stock_sigue_siendo_el_origen()
    {
        $grande    = $this->sucursal('Deposito no designado');
        $designado = $this->sucursal('Deposito designado bajo minimo', true);
        $chica     = $this->sucursal('Sucursal destino');

        // El designado está bajo su propio mínimo (15 < 20) pero con
        // limite_origen 'sin_limite' tiene stock disponible para repartir.
        $articulo = $this->articulo_con_stock('Tuerca central', [
            $grande->id    => ['amount' => 100, 'stock_min' => 10, 'stock_max' => 20],
            $designado->id => ['amount' => 15,  'stock_min' => 20, 'stock_max' => 40],
            $chica->id     => ['amount' => 2,   'stock_min' => 10, 'stock_max' => 20],
        ]);

        $lineas = $this->calcular([$articulo->id], 'minimo', 'sin_limite');

        $this->assertNotEmpty($lineas);
        foreach ($lineas as $linea) {
            $this->assertEquals(
                $designado->id,
                $linea['from_address_id'],
                'El designado en déficit con stock disponible tiene que seguir siendo el origen (escalón 2).'
            );
        }
        // Nunca se sugiere moverle stock a él mismo.
        $destinos = collect($lineas)->pluck('to_address_id');
        $this->assertFalse($destinos->contains($designado->id));
    }

    /**
     * Escalón 3: un designado vacío no paraliza la red — el cálculo cae al
     * comportamiento histórico.
     *
     * @group sugerencias-stock
     * @test
     */
    public function el_designado_vacio_cede_el_lugar_al_comportamiento_viejo()
    {
        $grande    = $this->sucursal('Deposito lleno');
        $designado = $this->sucursal('Deposito designado vacio', true);
        $chica     = $this->sucursal('Sucursal con deficit');

        $articulo = $this->articulo_con_stock('Arandela vacia', [
            $grande->id    => ['amount' => 100, 'stock_min' => 10, 'stock_max' => 20],
            $designado->id => ['amount' => 0,   'stock_min' => 0,  'stock_max' => 10],
            $chica->id     => ['amount' => 2,   'stock_min' => 10, 'stock_max' => 20],
        ]);

        $lineas = $this->calcular([$articulo->id]);

        $this->assertCount(1, $lineas);
        $this->assertEquals(
            $grande->id,
            $lineas[0]['from_address_id'],
            'Con el designado vacío, el origen tiene que volver a ser el de mayor stock (comportamiento viejo).'
        );
    }

    /**
     * @group sugerencias-stock
     * @test
     */
    public function el_modo_maximo_repone_hasta_el_stock_max()
    {
        $grande = $this->sucursal('Deposito origen maximo');
        $chica  = $this->sucursal('Sucursal a llenar');

        $articulo = $this->articulo_con_stock('Bulon maximo', [
            $grande->id => ['amount' => 100, 'stock_min' => 10, 'stock_max' => 20],
            $chica->id  => ['amount' => 2,   'stock_min' => 10, 'stock_max' => 30],
        ]);

        $lineas = $this->calcular([$articulo->id], 'maximo');

        $this->assertCount(1, $lineas);
        // Objetivo = stock_max del destino (30): needed = 30 - 2 = 28.
        $this->assertEquals(
            28,
            $lineas[0]['suggested_amount'],
            'Con modo maximo el objetivo tiene que ser el stock_max del destino, no el mínimo ni el ideal.'
        );
    }

    /**
     * @group sugerencias-stock
     * @test
     */
    public function los_articulos_de_otro_comercio_no_entran_al_calculo()
    {
        $otro_comercio = User::create([
            'name'     => 'Otro comercio P4',
            'email'    => 'sugerencias-p4-ajeno-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $grande = $this->sucursal('Deposito propio');
        $chica  = $this->sucursal('Sucursal propia');

        $mio = $this->articulo_con_stock('Articulo propio', [
            $grande->id => ['amount' => 100, 'stock_min' => 10, 'stock_max' => 20],
            $chica->id  => ['amount' => 2,   'stock_min' => 10, 'stock_max' => 20],
        ]);

        // Mismo escenario de déficit pero con dueño ajeno (sucursales propias
        // del test para aislar; lo que importa es el user_id del artículo).
        $ajeno = $this->articulo_con_stock('Articulo ajeno', [
            $grande->id => ['amount' => 100, 'stock_min' => 10, 'stock_max' => 20],
            $chica->id  => ['amount' => 2,   'stock_min' => 10, 'stock_max' => 20],
        ], $otro_comercio->id);

        // Se piden explícitamente los DOS ids: el filtro por user_id tiene que
        // dejar afuera al ajeno incluso pedido por id.
        $lineas = $this->calcular([$mio->id, $ajeno->id]);

        $articulos_sugeridos = collect($lineas)->pluck('article_id');

        $this->assertTrue($articulos_sugeridos->contains($mio->id));
        $this->assertFalse(
            $articulos_sugeridos->contains($ajeno->id),
            'Un artículo de otro user_id no puede entrar al cálculo aunque se pida por id.'
        );
    }
}
