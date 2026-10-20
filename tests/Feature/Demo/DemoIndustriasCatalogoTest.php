<?php

namespace Tests\Feature\Demo;

use App\Models\Article;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Description;
use App\Services\ArticleEmbeddingService;
use App\Services\DemoIndustriaEmbeddings;
use App\Services\DemoIndustriaService;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;

class DemoIndustriasCatalogoTest extends TestCase
{
    public function test_cada_rubro_entrega_un_catalogo_con_imagenes_y_vectores_vigentes()
    {
        $perfiles = config('demo_industrias');
        $this->assertCount(14, $perfiles);
        $codigos = [];
        foreach ($perfiles as $slug => $perfil) {
            $this->assertNotNull(DemoIndustriaService::resolver($slug));
            $this->assertStringContainsString('Demo', $perfil['nombre_demo']);
            $this->assertCount(24, $perfil['productos']);
            $this->assertCount(6, $perfil['categorias']);
            $this->comprobar_imagen($perfil['logo_url']);
            foreach ($perfil['banners'] as $banner) {
                $desktop = $this->comprobar_imagen($banner['desktop_url']);
                $mobile = $this->comprobar_imagen($banner['mobile_url']);
                $this->assertGreaterThan($desktop[1], $desktop[0]);
                $this->assertGreaterThan($mobile[0], $mobile[1]);
            }
            $archivo = json_decode(file_get_contents(DemoIndustriaEmbeddings::ruta($slug)), true);
            $this->assertSame('text-embedding-3-small', $archivo['modelo']);
            $this->assertSame(1536, $archivo['dimensiones']);
            $this->assertCount(24, $archivo['articulos']);
            foreach ($perfil['productos'] as $producto) {
                $this->assertContains($producto['category_name'], $perfil['categorias']);
                $this->assertGreaterThan(0, $producto['cost']);
                $this->assertNotEmpty($producto['descripcion']);
                $this->assertArrayNotHasKey($producto['bar_code'], $codigos, 'Código repetido entre rubros.');
                $codigos[$producto['bar_code']] = true;
                $this->comprobar_imagen($producto['image_url']);
                $vector = $archivo['articulos'][$producto['name']];
                $this->assertCount(1536, $vector['embedding']);
                $norma = 0;
                $finito = true;
                foreach ($vector['embedding'] as $valor) {
                    $finito = $finito && is_numeric($valor) && is_finite((float) $valor);
                    $norma += $valor * $valor;
                }
                $this->assertTrue($finito, 'El vector contiene valores no numéricos o infinitos.');
                $this->assertEqualsWithDelta(1, $norma, 0.002, 'El vector debe conservar la normalización del proveedor.');
                $articulo = new Article(['name' => $producto['name'], 'bar_code' => $producto['bar_code']]);
                $articulo->setRelation('category', new Category(['name' => $producto['category_name']]));
                $articulo->setRelation('brand', new Brand(['name' => $producto['brand_name']]));
                $articulo->setRelation('descriptions', new Collection([new Description([
                    'id' => 1, 'title' => 'Descripción', 'content' => $producto['descripcion'],
                ])]));
                $this->assertSame(sha1(app(ArticleEmbeddingService::class)->embedding_for_article($articulo)), $vector['source_hash'], $slug.': '.$producto['name']);
            }
        }
    }

    public function test_un_rubro_desconocido_no_resuelve_ni_permite_rutas_arbitrarias()
    {
        $this->assertNull(DemoIndustriaService::resolver(null));
        $this->assertNull(DemoIndustriaService::resolver('otro'));
        $this->assertNull(DemoIndustriaService::resolver('../ferreteria'));
    }

    private function comprobar_imagen($ruta)
    {
        $this->assertStringStartsWith('demo-industrias/', $ruta);
        $this->assertFileExists(public_path($ruta));
        $dimensiones = getimagesize(public_path($ruta));
        $this->assertNotFalse($dimensiones, $ruta);
        $this->assertGreaterThanOrEqual(200, min($dimensiones[0], $dimensiones[1]));
        return $dimensiones;
    }
}
