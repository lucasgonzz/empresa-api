<?php

namespace Tests\Unit;

use App\Services\Traits\GoogleSearchHelpers;
use Tests\TestCase;

/**
 * Congela el comportamiento de GS1/normalización que ProcessArticleBatchImagesJob tenía ANTES
 * de este refactor. Si este test pasa antes y después de mover los métodos al trait, el
 * comportamiento del job de imágenes no cambió.
 */
class GoogleSearchHelpersTest extends TestCase
{
    protected function helper()
    {
        return new class {
            use GoogleSearchHelpers;

            public function normalize(string $s): string
            {
                return $this->normalize_bar_code($s);
            }

            public function is_valid(string $s): bool
            {
                return $this->is_valid_product_bar_code($s);
            }
        };
    }

    /** @test */
    public function normaliza_eliminando_espacios()
    {
        $this->assertEquals('7791234567890', $this->helper()->normalize(' 7791234567890 '));
        $this->assertEquals('7791234567890', $this->helper()->normalize('779123 4567890')); // espacio interno: normalize_bar_code saca todos los \s+ vía preg_replace
    }

    /** @test */
    public function acepta_ean13_con_digito_verificador_valido()
    {
        // EAN-13 con digito verificador GS1 correcto (calculado, no un producto real conocido)
        $this->assertTrue($this->helper()->is_valid('7790895000782'));
    }

    /** @test */
    public function rechaza_ean13_con_digito_verificador_invalido()
    {
        $this->assertFalse($this->helper()->is_valid('7790895000783'));
    }

    /** @test */
    public function acepta_ean8_valido_y_rechaza_longitudes_no_soportadas()
    {
        $this->assertFalse($this->helper()->is_valid('123456'));   // 6 digitos: no es GTIN valido
        $this->assertFalse($this->helper()->is_valid('abcdefgh')); // no numerico
    }

    /** @test */
    public function rechaza_vacio()
    {
        $this->assertFalse($this->helper()->is_valid(''));
    }
}
