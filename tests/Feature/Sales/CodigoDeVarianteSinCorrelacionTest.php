<?php

namespace Tests\Feature\Sales;

use App\Http\Controllers\Helpers\GlobalSearchQueryHelper;
use App\Http\Controllers\Helpers\VenderSearchHelper;
use App\Models\Article;
use App\Models\ArticleVariant;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CodigoDeVarianteSinCorrelacionTest extends TestCase
{
    use DatabaseTransactions;

    private $dueno;
    private $otro;
    private $articulos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dueno = User::create(['name' => 'Prueba de códigos', 'email' => uniqid() . '@test.local', 'password' => 'prueba']);
        $this->otro = User::create(['name' => 'Otro comercio', 'email' => uniqid() . '@test.local', 'password' => 'prueba']);
        $this->actingAs($this->dueno, 'web');
        $this->articulos = [];
        foreach ([
            ['Llave de prueba', 'PROV-A', 'PADRE-A'],
            ['Pinza de prueba', 'PROV-B', 'PADRE-B'],
            ['Otra pieza', 'PROV-C', null],
            ['Pieza invisible', 'PROV-D', null],
        ] as $datos) {
            $this->articulos[] = Article::create(['user_id' => $this->dueno->id, 'status' => 'active',
                'name' => $datos[0], 'provider_code' => $datos[1], 'bar_code' => $datos[2]]);
        }
        ArticleVariant::create(['article_id' => $this->articulos[0]->id, 'bar_code' => 'VAR-A', 'variant_description' => 'roja', 'oculta' => false, 'stock' => 2]);
        ArticleVariant::create(['article_id' => $this->articulos[1]->id, 'bar_code' => 'VAR-OCULTA', 'variant_description' => 'azul', 'oculta' => true, 'stock' => 3]);
        ArticleVariant::create(['article_id' => $this->articulos[2]->id, 'bar_code' => 'DUPLICADO', 'variant_description' => 'verde', 'oculta' => false, 'stock' => 0]);
        ArticleVariant::create(['article_id' => $this->articulos[2]->id, 'bar_code' => 'DUPLICADO', 'variant_description' => 'verde dos', 'oculta' => false, 'stock' => 1]);
        ArticleVariant::create(['article_id' => $this->articulos[3]->id, 'bar_code' => 'DUPLICADO', 'variant_description' => 'gris', 'oculta' => false, 'stock' => 1]);
        $ajeno = Article::create(['user_id' => $this->otro->id, 'status' => 'active', 'name' => 'Ajeno']);
        ArticleVariant::create(['article_id' => $ajeno->id, 'bar_code' => 'SOLO-AJENO', 'oculta' => false]);
        ArticleVariant::create(['article_id' => $ajeno->id, 'bar_code' => 'DUPLICADO', 'oculta' => false]);
        foreach ([['status' => 'inactive'], ['status' => 'active', 'deleted_at' => now()]] as $estado) {
            $excluido = Article::create(array_merge(['user_id' => $this->dueno->id, 'name' => 'No debe ofrecerse'], $estado));
            ArticleVariant::create(['article_id' => $excluido->id, 'bar_code' => 'DUPLICADO', 'oculta' => false]);
        }
    }

    private function extension($activa)
    {
        $extension = ExtencionEmpresa::firstOrCreate(['slug' => 'article_variants'], ['name' => 'Variantes']);
        if ($activa) {
            $this->dueno->extencions()->syncWithoutDetaching([$extension->id]);
        } else {
            $this->dueno->extencions()->detach($extension->id);
        }
        $this->dueno->load('extencions');
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->dueno, 'web');
    }

    private function consulta($texto, $nuevo, $modelo = Article::class)
    {
        $codigo = $nuevo ? VenderSearchHelper::bar_code_condition_callback() : function ($sub, $palabras) {
            if (count($palabras) !== 1) {
                return;
            }
            $sub->orWhere('bar_code', $palabras[0]);
            $sub->orWhereHas('article_variants', function ($variante) use ($palabras) {
                $variante->where('bar_code', $palabras[0]);
            });
        };
        $query = $modelo::where('user_id', $this->dueno->id)->where('status', 'active');
        return GlobalSearchQueryHelper::apply($query, $texto, ['name', 'provider_code'], [], 'or', 'articles',
            new $modelo(), $codigo, VenderSearchHelper::variant_description_condition_callback(), [])->orderBy('articles.id');
    }

    public function test_in_y_exists_ofrecen_los_mismos_articulos_con_y_sin_extension()
    {
        foreach ([false, true] as $extension) {
            $this->extension($extension);
            foreach (['VAR-A', 'VAR-OCULTA', 'PADRE-A', 'PROV-B', 'Llave', 'DUPLICADO', 'Llave prueba', 'SOLO-AJENO', 'SIN-COINCIDENCIA'] as $texto) {
                $anterior = $this->consulta($texto, false)->pluck('articles.id')->all();
                $nuevo = $this->consulta($texto, true)->pluck('articles.id')->all();
                $this->assertSame($anterior, $nuevo, $texto . ' con extensión ' . (int) $extension);
            }
        }
        $this->assertSame([$this->articulos[1]->id], $this->consulta('VAR-OCULTA', true)->pluck('articles.id')->all());
        $this->assertSame([$this->articulos[2]->id, $this->articulos[3]->id], $this->consulta('DUPLICADO', true)->pluck('articles.id')->all());
        $this->assertSame([], $this->consulta('SOLO-AJENO', true)->pluck('articles.id')->all());
    }

    public function test_conserva_los_scopes_y_condiciones_de_la_relacion()
    {
        // La relación alternativa tiene filtro de stock y el modelo relacionado agrega scope de
        // ocultas. Derivarla evita perderlos si la relación real agrega condiciones en el futuro.
        foreach (['VAR-A', 'VAR-OCULTA', 'DUPLICADO'] as $texto) {
            $anterior = $this->consulta($texto, false, ArticuloConRelacionAcotadaDePrueba::class)->pluck('articles.id')->all();
            $nuevo = $this->consulta($texto, true, ArticuloConRelacionAcotadaDePrueba::class)->pluck('articles.id')->all();
            $this->assertSame($anterior, $nuevo);
        }
        $this->assertSame([], $this->consulta('VAR-OCULTA', true, ArticuloConRelacionAcotadaDePrueba::class)->pluck('articles.id')->all());
    }

    public function test_el_subquery_sql_no_se_correlaciona_ni_materializa_ids_en_php()
    {
        $query = $this->consulta('VAR-A', true);
        $sql = $query->toSql();
        $this->assertStringContainsString('`articles`.`id` in (select `article_variants`.`article_id` from `article_variants`', $sql);
        $this->assertStringNotContainsString('`articles`.`id` = `article_variants`.`article_id`', $sql);
        $this->assertStringNotContainsString('exists (select', $sql);
        $this->assertContains('VAR-A', $query->getBindings());
        $this->assertStringNotContainsString(' in (' . $this->articulos[0]->id, $sql);
    }

    public function test_las_varias_palabras_no_agregan_un_match_exacto_indebido()
    {
        $nuevo = $this->consulta('Llave prueba', true);
        $this->assertStringNotContainsString(' in (select', $nuevo->toSql());
        $this->assertSame([$this->articulos[0]->id], $nuevo->pluck('articles.id')->all());
    }
}

class ArticuloConRelacionAcotadaDePrueba extends Article
{
    protected $table = 'articles';

    public function article_variants()
    {
        return $this->hasMany(VarianteConScopeDePrueba::class, 'article_id')->where('article_variants.stock', '>', 0);
    }
}

class VarianteConScopeDePrueba extends ArticleVariant
{
    protected $table = 'article_variants';

    protected static function booted()
    {
        static::addGlobalScope('visible_en_prueba', function ($query) {
            $query->where('article_variants.oculta', false);
        });
    }
}
