<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\ArticleController;
use App\Http\Controllers\Helpers\asistente_ia\CatalogoDeEscrituraIaHelper as Catalogo;
use Tests\TestCase;

class CatalogoArticuloConHistorialTest extends TestCase
{
    public function test_el_wrapper_de_historial_no_oculta_los_campos_del_alta_ni_la_edicion()
    {
        foreach (['store', 'update'] as $metodo) {
            $campos = Catalogo::claves_que_lee(ArticleController::class, $metodo);
            foreach (['name', 'provider_id', 'category_id', 'cepa_id', 'bodega_id', 'presentacion', 'percentage_gain', 'iva_id', 'online'] as $campo) {
                $this->assertContains($campo, $campos, $metodo.' debe leer '.$campo);
            }
        }
    }

    public function test_el_catalogo_sigue_excluyendo_stock_aunque_se_lea_en_otro_metodo()
    {
        $declaracion = Catalogo::declaracion('article');
        $this->assertArrayHasKey('name', $declaracion['campos']);
        $this->assertArrayHasKey('provider_id', $declaracion['campos']);
        // Truvari (13/11/2026, F2): publicar o despublicar en la tienda por el ABM genérico.
        $this->assertArrayHasKey('online', $declaracion['campos']);
        $this->assertArrayNotHasKey('stock', $declaracion['campos']);
        $this->assertArrayNotHasKey('final_price', $declaracion['campos']);
        $this->assertNotContains('stock', Catalogo::claves_que_lee(ArticleController::class, 'store'));
    }
}
