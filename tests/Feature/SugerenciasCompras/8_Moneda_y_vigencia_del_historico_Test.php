<?php

namespace Tests\Feature\SugerenciasCompras;

use App\Http\Controllers\Helpers\providerOrder\NewProviderOrderHelper;
use App\Models\Article;
use App\Models\Provider;
use App\Models\ProviderOrder;
use App\Models\ProviderPriceOffer;
use App\Models\User;
use App\Services\Compras\OfertasDeProveedorService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Misión sugerencias-compra-proveedores — archivo 8 (pasada de arreglos
 * post-chequeo): A1, A3 y A11 de OfertasDeProveedorService /
 * NewProviderOrderHelper.
 *
 * A1 -- un costo en dólares se guardaba como si fueran pesos:
 * catalogar_costo_proveedor() nunca mandaba moneda_id y registrar_lote()
 * completaba con el default (1 = Peso). Se prueba SIN sembrar la fila a
 * mano: se invoca NewProviderOrderHelper::attach_articles() de punta a
 * punta (mismo patrón que el tercer punto de captura del archivo 3), para
 * probar el camino real que lee cost_in_dollars del pivot de la línea y
 * provider_order->moneda_id, no una simulación.
 *
 * A3 -- un proveedor con precio estable desaparecía de la competencia a los
 * 120 días: registrar_lote() leía "la última fila" sin ventana de fecha, así
 * que un costo que nunca cambia dejaba una sola fila que terminaba fuera de
 * la ventana de mejores_ofertas_para(). Se prueba moviendo el reloj con
 * Carbon::setTestNow() (mismo patrón que
 * registrar_lote_dedupea_por_cambio_de_costo_no_por_dia del archivo 3).
 *
 * A11 -- mejores_ofertas_para() desempataba dos filas del mismo día por
 * `id DESC`, así que una importación cargada más tarde le ganaba a una
 * compra real del mismo día -- el dato más confiable del histórico.
 *
 * @group sugerencias-compras
 */
class Moneda_y_vigencia_del_historico_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        // Si no, el pipeline sale a la API real de Anthropic. Ningún test de
        // este archivo usa IA, pero es la regla obligatoria de setUp de toda
        // la misión (§11 del plan), la misma en los otros 7 archivos.
        config(['services.anthropic.api_key' => null]);

        $this->comercio = User::create([
            'name'     => 'Comercio moneda y vigencia P8',
            'email'    => 'sugerencias-compras-p8-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);
    }

    /**
     * Proveedor propio de este archivo: ids frescos, cero chance de pisar el
     * fixture de otro archivo de esta misma suite que corre en paralelo
     * sobre la misma base (empresa_testing_s1).
     *
     * @param string $sufijo
     * @return Provider
     */
    protected function crear_proveedor($sufijo)
    {
        return Provider::create(['name' => 'Proveedor P8 ' . $sufijo, 'user_id' => $this->comercio->id]);
    }

    /**
     * Artículo propio de este archivo.
     *
     * $cost_inicial (opcional) se usa SOLO para los tests que compran: si se
     * deja en null, articles.cost queda null y update_cost() (NewProviderOrderHelper)
     * detecta "cambió" en cuanto se compra, y dispara
     * ArticleHelper::setFinalPrice($article) SIN pasarle usuario -- bug
     * preexistente y ajeno a esta misión (revienta con "Trying to get
     * property 'percentage_gain' of non-object" porque $user llega null a
     * calcular_base_antes_de_listas()). Igualar el costo inicial al costo de
     * la compra hace que update_cost() no detecte cambio y no dispare ese
     * camino -- sigue siendo el camino real de principio a fin
     * (attach_articles -> update_article -> catalogar_costo_proveedor), solo
     * que sin tropezar con un efecto colateral que no tiene nada que ver con
     * A1/A3/A11.
     *
     * @param string $sufijo
     * @param float|null $cost_inicial
     * @return Article
     */
    protected function crear_articulo($sufijo, $cost_inicial = null)
    {
        return Article::create([
            'name'    => 'Articulo P8 ' . $sufijo,
            'user_id' => $this->comercio->id,
            'cost'    => $cost_inicial,
        ]);
    }

    /**
     * Orden de compra mínima -- mismo molde que el tercer punto de captura
     * del archivo 3: update_prices=1 (gate de catalogar_costo_proveedor()) y
     * precios_incluyen_iva=false para que back_out_iva() nunca entre en
     * juego (este archivo es sobre moneda, no sobre IVA).
     *
     * @param Provider $provider
     * @param int $moneda_id 1 = Peso, 2 = Dólar (la moneda de la ORDEN completa).
     * @return ProviderOrder
     */
    protected function crear_orden($provider, $moneda_id)
    {
        return ProviderOrder::create([
            'num'                      => mt_rand(9000000, 9999999),
            'provider_id'              => $provider->id,
            'provider_order_status_id' => 1,
            'update_stock'             => 0,
            'update_prices'            => 1,
            'generate_current_acount'  => 0,
            'precios_incluyen_iva'     => false,
            'moneda_id'                => $moneda_id,
            'user_id'                  => $this->comercio->id,
        ]);
    }

    /**
     * Invoca el camino real de la compra (NewProviderOrderHelper::attach_articles()
     * -> update_article() -> catalogar_costo_proveedor(), con update_prices=1)
     * para un solo artículo -- ningún atajo ni simulación.
     *
     * @param ProviderOrder $orden
     * @param Article $articulo
     * @param float $cost
     * @param bool $cost_in_dollars Flag de LA LÍNEA (article_provider_order.cost_in_dollars).
     * @return void
     */
    protected function comprar($orden, $articulo, $cost, $cost_in_dollars)
    {
        $helper = new NewProviderOrderHelper($orden, [
            [
                'id'            => $articulo->id,
                'bar_code'      => $articulo->bar_code,
                'provider_code' => 'PC-P8',
                'pivot'         => [
                    'cost'            => $cost,
                    'amount'          => 1,
                    'received'        => 1,
                    'price'           => null,
                    'discount'        => null,
                    'iva_id'          => null,
                    'cost_in_dollars' => $cost_in_dollars ? 1 : 0,
                    'update_provider' => 1,
                ],
            ],
        ]);

        $helper->attach_articles();
    }

    /**
     * Busca la oferta de origen='compra' de un par (artículo, proveedor).
     *
     * @param Article $articulo
     * @param Provider $provider
     * @return ProviderPriceOffer|null
     */
    protected function oferta_de_compra($articulo, $provider)
    {
        return ProviderPriceOffer::where('article_id', $articulo->id)
            ->where('provider_id', $provider->id)
            ->where('origen', 'compra')
            ->first();
    }

    /**
     * A1: una compra tipeada en dólares (cost_in_dollars=1 en LA LÍNEA) sobre
     * una orden en pesos (moneda_id=1) tiene que dejar la oferta con
     * moneda_id=2. Es exactamente el caso del hallazgo: antes de este
     * arreglo, esta compra de USD 10 quedaba anotada como oferta de $10 --
     * ese proveedor ganaba "el más barato" por ~1000x en la corrida
     * siguiente del motor de sugerencias.
     *
     * @group sugerencias-compras
     * @test
     */
    public function compra_en_dolares_sobre_orden_en_pesos_deja_moneda_id_2()
    {
        $provider = $this->crear_proveedor('dolares');
        $articulo = $this->crear_articulo('dolares', 10.0);
        $orden    = $this->crear_orden($provider, 1); // orden en pesos

        $this->comprar($orden, $articulo, 10.0, true); // línea puntual tildada en dólares

        $oferta = $this->oferta_de_compra($articulo, $provider);

        $this->assertNotNull($oferta, 'catalogar_costo_proveedor() tiene que dejar la oferta de esta compra.');
        $this->assertEquals(
            2,
            (int) $oferta->moneda_id,
            'cost_in_dollars=1 en la línea tiene que dejar moneda_id=2, aunque la orden entera sea en pesos.'
        );
        // El costo NUNCA se convierte acá (ni el que se guarda en article_provider ni
        // el del histórico): sigue siendo el literal tipeado. Lo único que cambia con
        // este arreglo es la ETIQUETA de moneda, para que el motor sepa que no compite
        // en pesos contra las demás ofertas.
        $this->assertEquals(10.0, (float) $oferta->cost);
    }

    /**
     * A1, segundo camino de la regla (el OR con provider_order->moneda_id):
     * una orden ENTERA en dólares también tiene que dejar moneda_id=2 aunque
     * la línea puntual NO tenga tildado cost_in_dollars -- get_total_article()
     * nunca convierte cuando la orden ya es en dólares (su `if` solo dispara
     * con moneda_id==1), así que ahí el costo tipeado ya está directo en esa
     * moneda sin que haga falta tildar nada por línea.
     *
     * @group sugerencias-compras
     * @test
     */
    public function compra_en_orden_moneda_dolares_deja_moneda_id_2_aunque_la_linea_no_este_tildada()
    {
        $provider = $this->crear_proveedor('orden-dolares');
        $articulo = $this->crear_articulo('orden-dolares', 25.0);
        $orden    = $this->crear_orden($provider, 2); // orden ENTERA en dólares

        $this->comprar($orden, $articulo, 25.0, false); // línea SIN tildar cost_in_dollars

        $oferta = $this->oferta_de_compra($articulo, $provider);

        $this->assertNotNull($oferta);
        $this->assertEquals(2, (int) $oferta->moneda_id);
        $this->assertEquals(25.0, (float) $oferta->cost);
    }

    /**
     * A1: el caso de siempre, sin regresión -- una compra en pesos (ni la
     * línea ni la orden marcadas en dólares) sigue dejando moneda_id=1.
     *
     * @group sugerencias-compras
     * @test
     */
    public function compra_en_pesos_deja_moneda_id_1()
    {
        $provider = $this->crear_proveedor('pesos');
        $articulo = $this->crear_articulo('pesos', 555.0);
        $orden    = $this->crear_orden($provider, 1);

        $this->comprar($orden, $articulo, 555.0, false);

        $oferta = $this->oferta_de_compra($articulo, $provider);

        $this->assertNotNull($oferta);
        $this->assertEquals(1, (int) $oferta->moneda_id);
        $this->assertEquals(555.0, (float) $oferta->cost);
    }

    /**
     * A3: con el mismo costo, reconfirmar ANTES de DIAS_RECONFIRMACION (30)
     * días no puede dejar fila nueva (sigue siendo la misma "verdad" de
     * precio); a partir de esos 30 días sí, aunque el costo sea idéntico --
     * si no, ese proveedor sale de la ventana de vigencia de
     * mejores_ofertas_para() (120 días por default) sin haber dejado de
     * ofertar. $ahora_real fijo ANTES de cualquier setTestNow(), para que
     * cada offset sea explícito y no dependa de encadenar sumas sobre un
     * testNow ya movido.
     *
     * @group sugerencias-compras
     * @test
     */
    public function costo_igual_reconfirmado_despues_de_dias_reconfirmacion_deja_fila_nueva_antes_no()
    {
        $provider = $this->crear_proveedor('reconfirmacion');
        $articulo = $this->crear_articulo('reconfirmacion');
        $ahora_real = Carbon::now();

        OfertasDeProveedorService::registrar_lote(
            [$articulo->id => [$provider->id => ['cost' => 800]]],
            $this->comercio->id,
            'importacion',
            null
        );

        $this->assertEquals(
            1,
            ProviderPriceOffer::where('article_id', $articulo->id)->where('provider_id', $provider->id)->count(),
            'Primera oferta: tiene que quedar 1 fila.'
        );

        try {
            // +29 días, mismo costo: todavía DENTRO de la ventana de
            // reconfirmación (30) -> no puede dejar fila nueva.
            Carbon::setTestNow($ahora_real->copy()->addDays(29));

            OfertasDeProveedorService::registrar_lote(
                [$articulo->id => [$provider->id => ['cost' => 800]]],
                $this->comercio->id,
                'importacion',
                null
            );

            $this->assertEquals(
                1,
                ProviderPriceOffer::where('article_id', $articulo->id)->where('provider_id', $provider->id)->count(),
                'A los 29 días, con el mismo costo, todavía NO tiene que reconfirmar (DIAS_RECONFIRMACION = 30).'
            );

            // +30 días desde el momento original (>= DIAS_RECONFIRMACION):
            // ya pasó el plazo -> tiene que reconfirmar con una fila nueva.
            Carbon::setTestNow($ahora_real->copy()->addDays(30));

            OfertasDeProveedorService::registrar_lote(
                [$articulo->id => [$provider->id => ['cost' => 800]]],
                $this->comercio->id,
                'importacion',
                null
            );

            $filas = ProviderPriceOffer::where('article_id', $articulo->id)
                ->where('provider_id', $provider->id)
                ->orderBy('fecha')
                ->get();

            $this->assertCount(
                2,
                $filas,
                'A los 30 días (>= DIAS_RECONFIRMACION), con el mismo costo, tiene que dejar una fila nueva de reconfirmación.'
            );
            $this->assertEquals(800.0, (float) $filas[1]->cost, 'La fila de reconfirmación tiene el MISMO costo, no uno distinto.');
            $this->assertEquals($ahora_real->copy()->addDays(30)->toDateString(), $filas[1]->fecha);
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * A11: dos filas del MISMO día -- una 'compra' creada primero (id más
     * bajo) y una 'importacion' creada después (id más alto) -- tienen que
     * desempatar a favor de 'compra', el dato más confiable, y no del id más
     * alto. Escenario realista: la compra se cargó a la mañana y la
     * importación de la lista de precios del proveedor llegó a la tarde del
     * mismo día.
     *
     * @group sugerencias-compras
     * @test
     */
    public function mejores_ofertas_para_desempata_el_mismo_dia_a_favor_de_compra()
    {
        $provider = $this->crear_proveedor('desempate');
        $articulo = $this->crear_articulo('desempate');
        $hoy = now()->toDateString();

        $oferta_compra = ProviderPriceOffer::create([
            'user_id'       => $this->comercio->id,
            'article_id'    => $articulo->id,
            'provider_id'   => $provider->id,
            'cost'          => 900.0,
            'moneda_id'     => 1,
            'origen'        => 'compra',
            'fecha'         => $hoy,
            'referencia_id' => null,
        ]);

        $oferta_importacion = ProviderPriceOffer::create([
            'user_id'       => $this->comercio->id,
            'article_id'    => $articulo->id,
            'provider_id'   => $provider->id,
            'cost'          => 950.0,
            'moneda_id'     => 1,
            'origen'        => 'importacion',
            'fecha'         => $hoy,
            'referencia_id' => null,
        ]);

        $this->assertGreaterThan(
            $oferta_compra->id,
            $oferta_importacion->id,
            'La importación tiene que tener el id más alto para que este test pruebe algo: sin esto, id DESC ya elegiría bien por casualidad.'
        );

        $resultado = OfertasDeProveedorService::mejores_ofertas_para([$articulo->id], $this->comercio->id, 120);

        $this->assertArrayHasKey($articulo->id, $resultado);
        $this->assertArrayHasKey($provider->id, $resultado[$articulo->id]);

        $elegida = $resultado[$articulo->id][$provider->id];

        $this->assertEquals(
            'compra',
            $elegida['origen'],
            'El desempate del mismo día tiene que preferir origen=compra, aunque tenga el id más bajo.'
        );
        $this->assertEquals(900.0, (float) $elegida['cost']);
    }
}
