<?php

namespace Tests\Feature\PruebaGratuita;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\DemoSetupHelper;
use App\Http\Controllers\Helpers\PruebaHelper;
use App\Http\Controllers\Helpers\article\ArticlePricesHelper;
use App\Models\Article;
use App\Models\SaleTax;
use App\Models\User;
use App\Observers\ArticleObserver;
use Database\Seeders\SaleTaxSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Los datos de ejemplo de una PRUEBA gratuita no pueden encarecer los precios del negocio (12/11/2026).
 *
 * Medido en producción: la prueba nacía con Ingresos Brutos al 3 % activo, y un costo de $1.341,03 con
 * 35 % de ganancia e IVA 21 % daba $2.258,32 en vez de $2.190,57. La DEMO sigue naciendo con el impuesto
 * activo, porque de él sale el renglón de IIBB de sus reportes.
 */
class ImpuestosSobreVentasDeLaPruebaTest extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    private $dueno;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        $this->dueno = User::factory()->create([
            'iva_included' => 0,
            'aplicar_iva_al_costo' => 0,
            'usar_condicion_fiscal_en_costeo' => 0,
        ]);
        $this->actingAs($this->dueno, 'web');
        config(['app.USER_ID' => $this->dueno->id]);
        ArticlePricesHelper::$sale_taxes_cache = [];
        $this->armando_prueba(false);
    }

    protected function tearDown(): void
    {
        $this->armando_prueba(false);
        ArticlePricesHelper::$sale_taxes_cache = [];

        parent::tearDown();
    }

    private function armando_prueba(bool $valor): void
    {
        $propiedad = new ReflectionProperty(DemoSetupHelper::class, 'armando_prueba');
        $propiedad->setAccessible(true);
        $propiedad->setValue(null, $valor);
    }

    private function precio_final_de_un_articulo_nuevo(): float
    {
        ArticleObserver::empezar_semilla();
        try {
            $articulo = Article::create([
                'name' => 'Artículo de control',
                'cost' => 1000,
                'percentage_gain' => 35,
                'iva_id' => 2,
                'aplicar_iva' => 1,
                'user_id' => $this->dueno->id,
            ]);
            ArticleHelper::setFinalPrice($articulo, $this->dueno->id);
        } finally {
            ArticleObserver::terminar_semilla();
        }

        return (float) $articulo->fresh()->final_price;
    }

    public function test_una_prueba_recien_armada_no_tiene_impuestos_sobre_ventas_activos_y_el_precio_no_se_encarece(): void
    {
        config(['app.FOR_USER' => PruebaHelper::FOR_USER_PRUEBA]);

        (new SaleTaxSeeder())->run();

        $this->assertSame(1, SaleTax::where('user_id', $this->dueno->id)->count(), 'Ingresos Brutos se crea igual, para que el dueño lo encuentre.');
        $this->assertSame(0, SaleTax::where('user_id', $this->dueno->id)->where('activo', 1)->count());
        // 1000 × 1,35 × 1,21, sin dividir por 0,97.
        $this->assertEqualsWithDelta(1633.50, $this->precio_final_de_un_articulo_nuevo(), 0.005);
    }

    public function test_un_setup_con_el_objeto_prueba_tambien_lo_deja_inactivo_aunque_la_instancia_no_sea_de_prueba(): void
    {
        config(['app.FOR_USER' => 'demo']);
        $this->armando_prueba(true);

        (new SaleTaxSeeder())->run();

        $this->assertSame(0, SaleTax::where('user_id', $this->dueno->id)->where('activo', 1)->count());
    }

    public function test_la_demo_sigue_naciendo_con_ingresos_brutos_activo(): void
    {
        config(['app.FOR_USER' => 'demo']);

        (new SaleTaxSeeder())->run();

        $impuesto = SaleTax::where('user_id', $this->dueno->id)->sole();
        $this->assertSame(1, (int) $impuesto->activo);
        $this->assertEqualsWithDelta(3.0, (float) $impuesto->percentage, 0.001);
        // El mismo artículo, con el impuesto: 1000 × 1,35 ÷ 0,97 × 1,21.
        $this->assertEqualsWithDelta(1684.02, $this->precio_final_de_un_articulo_nuevo(), 0.01);
    }
}
