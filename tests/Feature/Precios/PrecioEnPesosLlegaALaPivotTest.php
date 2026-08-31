<?php

namespace Tests\Feature\Precios;

use App\Http\Controllers\Helpers\article\ArticlePriceTypeMonedaHelper;
use App\Models\Article;
use App\Models\PriceType;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * El precio en pesos que se recalcula por lista y moneda tiene que quedar TAMBIEN en la pivot
 * `article_price_type`.
 *
 * 🔴 QUE PROTEGE, y no es un detalle interno. `ArticleHelper` elige entre
 * `ArticlePriceTypeMonedaHelper` y `ArticlePricesHelper::aplicar_precios_segun_listas_de_precios()`
 * con un if/else EXCLUYENTE sobre la extension `ventas_en_dolares`. Con la extension prendida, la
 * pivot `article_price_type` dejaba de escribirse — y esa pivot es la que lee la TIENDA
 * (`tienda-api`, `ArticleHelper::checkPriceTypes()`).
 *
 * Medido en pantalla el 30/8/2026 sobre la demo: se le subio el costo un 20% al articulo 45,
 * `price_type_monedas` paso de 10.116,80 a 12.140,15 y el ERP mostro el numero nuevo; la pivot
 * quedo en 10.429,69 y **la tienda siguio publicando 10.429,69**. No tardaba: no se actualizaba
 * nunca. Cualquier comercio con la extension prendida publicaba precios que no eran los de su
 * sistema, sin ninguna señal en pantalla.
 *
 * El sintoma no se ve desde el ERP —ahi todo se muestra bien—, asi que sin este test la regresion
 * volveria a pasar en silencio y se descubriria de nuevo mirando la tienda de un cliente.
 *
 * @group precios-por-lista
 */
class PrecioEnPesosLlegaALaPivotTest extends TestCase
{
    use DatabaseTransactions;

    /** Moneda pesos, tal como la usa el helper. */
    const ARS = 1;

    /** Moneda dolares. */
    const USD = 2;

    /** @var \App\Models\User */
    protected $user;

    /** @var \App\Models\Article */
    protected $article;

    /** @var \App\Models\PriceType */
    protected $lista;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::find(500);

        if (is_null($this->user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $this->lista = PriceType::create([
            'name'       => 'zz Lista del espejo',
            'user_id'    => 500,
            'position'   => 3,
            'percentage' => 50,
        ]);

        $this->article = Article::create([
            'name'    => 'zz Articulo del espejo a la pivot',
            'user_id' => 500,
            'cost'    => 100,
            'status'  => 'active',
        ]);

        $this->article->price_types()->attach($this->lista->id, [
            'percentage'  => 50,
            /* La pivot arranca con un precio VIEJO a proposito: si el espejo no corre, el test ve
               este numero y se pone rojo. Un null no serviria — no distinguiria "no se escribio"
               de "se escribio null". */
            'final_price' => 999,
        ]);

        // Las dos monedas de la lista, que es lo que el helper recalcula.
        $this->article->price_type_monedas()->create([
            'price_type_id'             => $this->lista->id,
            'moneda_id'                 => self::ARS,
            'percentage'                => 50,
            'final_price'               => 0,
            'setear_precio_final'       => 0,
            'cotizar_desde_otra_moneda' => 0,
        ]);

        $this->article->price_type_monedas()->create([
            'price_type_id'             => $this->lista->id,
            'moneda_id'                 => self::USD,
            'percentage'                => 50,
            'final_price'               => 0,
            'setear_precio_final'       => 0,
            'cotizar_desde_otra_moneda' => 0,
        ]);

        $this->article->load('price_type_monedas');
    }

    /**
     * 🔴 Despues de recalcular, la pivot tiene el MISMO precio en pesos que `price_type_monedas`.
     *
     * @return void
     */
    public function test_el_precio_en_pesos_queda_espejado_en_la_pivot(): void
    {
        ArticlePriceTypeMonedaHelper::aplicar_precios_por_price_type_y_moneda(
            $this->article,
            100,
            $this->user
        );

        $en_pesos = $this->article->price_type_monedas()
            ->where('moneda_id', self::ARS)
            ->where('price_type_id', $this->lista->id)
            ->first();

        $this->assertNotNull($en_pesos, 'No quedo la fila en pesos de price_type_monedas.');

        $pivot = $this->article->price_types()->find($this->lista->id);

        $this->assertNotNull($pivot, 'Se perdio la relacion del articulo con la lista.');

        $this->assertNotEquals(
            '999',
            (string) $pivot->pivot->final_price,
            'La pivot quedo con el precio VIEJO: el espejo no corrio. Es exactamente lo que hacia '
                . 'que la tienda publicara precios distintos de los del sistema.'
        );

        $this->assertEquals(
            round((float) $en_pesos->final_price, 2),
            round((float) $pivot->pivot->final_price, 2),
            'El precio en pesos de price_type_monedas y el de la pivot no coinciden. La tienda lee '
                . 'la pivot, asi que publicaria un numero distinto del que muestra el ERP.'
        );
    }

    /**
     * Y sigue coincidiendo despues de un SEGUNDO recalculo con otro costo: lo que se protege es
     * que la pivot acompañe, no que se haya escrito una vez.
     *
     * @return void
     */
    public function test_la_pivot_acompania_un_cambio_de_costo(): void
    {
        ArticlePriceTypeMonedaHelper::aplicar_precios_por_price_type_y_moneda(
            $this->article,
            100,
            $this->user
        );

        $primero = (float) $this->article->price_types()->find($this->lista->id)->pivot->final_price;

        // El mismo gesto que se hizo a mano en la demo: subirle el costo y volver a recalcular.
        $this->article->load('price_type_monedas');

        ArticlePriceTypeMonedaHelper::aplicar_precios_por_price_type_y_moneda(
            $this->article,
            200,
            $this->user
        );

        $en_pesos = $this->article->price_type_monedas()
            ->where('moneda_id', self::ARS)
            ->where('price_type_id', $this->lista->id)
            ->first();

        $segundo = (float) $this->article->price_types()->find($this->lista->id)->pivot->final_price;

        $this->assertNotEquals(
            round($primero, 2),
            round($segundo, 2),
            'La pivot no se movio al cambiar el costo: es el caso medido en la demo, donde el ERP '
                . 'mostraba el precio nuevo y la tienda seguia con el viejo.'
        );

        $this->assertEquals(
            round((float) $en_pesos->final_price, 2),
            round($segundo, 2),
            'Despues del segundo recalculo la pivot y price_type_monedas volvieron a separarse.'
        );
    }
}
