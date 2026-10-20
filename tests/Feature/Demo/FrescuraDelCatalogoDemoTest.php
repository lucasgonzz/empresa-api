<?php

namespace Tests\Feature\Demo;

use App\Http\Controllers\Helpers\article\ArticleEmbeddingsEstadoHelper;
use App\Models\Article;
use App\Models\User;
use App\Services\DemoIndustriaService;
use Database\Seeders\DemoIndustriaArticlesSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FrescuraDelCatalogoDemoTest extends TestCase
{
    use DatabaseTransactions;

    public function test_el_cierre_del_setup_verifica_los_vectores_despues_del_historico()
    {
        config(['broadcasting.default' => 'null']);
        $dueno = User::factory()->create();
        $this->actingAs($dueno, 'web');
        $perfil = config('demo_industrias.ferreteria');
        $perfil['productos'] = [$perfil['productos'][0]];
        $perfil['categorias'] = [$perfil['productos'][0]['category_name']];
        config([
            'app.USER_ID' => $dueno->id,
            'demo_industria_actual' => 'ferreteria',
            'demo_industrias.ferreteria' => $perfil,
        ]);
        (new DemoIndustriaArticlesSeeder())->run();
        $articulo = Article::where('user_id', $dueno->id)->sole();
        $vector = $articulo->embedding;
        $hash = $articulo->embedding_source_hash;
        // El histórico cambia datos operativos después de generar el vector semántico.
        DB::table('articles')->where('id', $articulo->id)->update([
            'updated_at' => now()->subMinute(),
            'embedding_generated_at' => now()->subMinutes(2),
        ]);
        $this->assertSame(1, ArticleEmbeddingsEstadoHelper::estado($dueno->id)['pendiente']);
        DemoIndustriaService::preparar($dueno, 'ferreteria');
        $estado = ArticleEmbeddingsEstadoHelper::estado($dueno->id);
        $this->assertSame(0, $estado['pendiente']);
        $this->assertSame(0, $estado['sin_generar']);
        $this->assertSame($hash, $articulo->fresh()->embedding_source_hash);
        $this->assertSame($vector, $articulo->fresh()->embedding);
    }
}
