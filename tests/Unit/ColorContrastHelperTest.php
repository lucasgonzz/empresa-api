<?php

namespace Tests\Unit;

use App\Http\Controllers\Helpers\ColorContrastHelper;
use App\Services\LogoPaletteAiService;
use Tests\TestCase;

/**
 * Mision paleta-hover-contraste (28/9/2026): el piso de contraste de hover_text_color contra
 * primary_color subio de 3.0 a 4.5 en LogoPaletteAiService, porque el hover del nav en
 * tienda-spa nunca agranda el texto (nunca califica como "texto grande" de WCAG). Este test fija
 * la formula matematica de ColorContrastHelper y confirma, con el par de colores que motivo el
 * cambio, que best_text_on() corrige un caso que antes pasaba de largo.
 */
class ColorContrastHelperTest extends TestCase
{
    /** @test */
    public function calcula_el_ratio_de_contraste_segun_wcag()
    {
        // Blanco contra negro: el maximo posible, 21.
        $this->assertEqualsWithDelta(21.0, ColorContrastHelper::contrast_ratio('#FFFFFF', '#000000'), 0.01);

        // Mismo color: el minimo posible, 1.
        $this->assertEqualsWithDelta(1.0, ColorContrastHelper::contrast_ratio('#808080', '#808080'), 0.01);
    }

    /** @test */
    public function un_gris_medio_contra_blanco_pasa_el_piso_viejo_pero_no_el_nuevo()
    {
        // Este es el par concreto que expuso el problema: 3.949 esta por encima del piso viejo
        // (3.0, pensado para texto grande) pero por debajo del piso nuevo (4.5), que es el que
        // corresponde porque el hover del nav en tienda-spa nunca agranda el texto.
        $ratio = ColorContrastHelper::contrast_ratio('#808080', '#FFFFFF');

        $this->assertGreaterThanOrEqual(3.0, $ratio);
        $this->assertLessThan(4.5, $ratio);
    }

    /** @test */
    public function el_piso_de_hover_quedo_igualado_al_de_texto_normal()
    {
        $this->assertEquals(
            LogoPaletteAiService::MIN_CONTRAST_TEXT_ON_PRIMARY,
            LogoPaletteAiService::MIN_CONTRAST_HOVER_TEXT_ON_PRIMARY
        );
    }

    /** @test */
    public function best_text_on_corrige_el_caso_marginal_con_contraste_suficiente()
    {
        // Sobre el mismo gris medio, best_text_on tiene que elegir el color con mas contraste
        // (negro casi puro) y ese resultado tiene que superar el piso nuevo (4.5), no solo el
        // viejo (3.0) — si no, la correccion seguiria dejando un hover dificil de distinguir.
        $corrected = ColorContrastHelper::best_text_on('#808080');

        $this->assertEquals('#111111', $corrected);
        $this->assertGreaterThanOrEqual(
            LogoPaletteAiService::MIN_CONTRAST_HOVER_TEXT_ON_PRIMARY,
            ColorContrastHelper::contrast_ratio($corrected, '#808080')
        );
    }
}
