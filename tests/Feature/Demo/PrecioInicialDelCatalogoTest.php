<?php

namespace Tests\Feature\Demo;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Models\Article;
use App\Models\User;
use App\Observers\ArticleObserver;
use Database\Seeders\DemoIndustriaArticlesSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PrecioInicialDelCatalogoTest extends TestCase
{
    use DatabaseTransactions;

    public function test_el_precio_recien_sembrado_ya_incluye_el_iva_y_no_cambia_al_recalcular()
    {
        config(['broadcasting.default' => 'null']);
        $dueno = User::factory()->create([
            'iva_included' => 0,
            'aplicar_iva_al_costo' => 0,
            'usar_condicion_fiscal_en_costeo' => 0,
        ]);
        $this->actingAs($dueno, 'web');
        $perfil = config('demo_industrias.ferreteria');
        // Un artículo alcanza para probar el ciclo real de alta, defaults y recálculo.
        $perfil['productos'] = [$perfil['productos'][0]];
        $perfil['categorias'] = [$perfil['productos'][0]['category_name']];
        config([
            'app.USER_ID' => $dueno->id,
            'demo_industria_actual' => 'ferreteria',
            'demo_industrias.ferreteria' => $perfil,
        ]);
        (new DemoIndustriaArticlesSeeder())->run();
        $articulo = Article::where('user_id', $dueno->id)->sole();
        $this->assertSame(1, (int) $articulo->aplicar_iva);
        $esperado = round(round($perfil['productos'][0]['cost'] / 1.21, 2) * 1.5 * 1.21, 2);
        $this->assertEqualsWithDelta($esperado, $articulo->final_price, 0.01);
        $precioInicial = $articulo->final_price;
        ArticleObserver::empezar_semilla();
        try {
            ArticleHelper::setFinalPrice($articulo, $dueno->id);
        } finally {
            ArticleObserver::terminar_semilla();
        }
        $this->assertEqualsWithDelta($precioInicial, $articulo->fresh()->final_price, 0.001);
    }
}
