<?php

namespace Tests\Unit\Iva;

use App\Http\Controllers\Helpers\SaleHelper;
use Tests\TestCase;

/**
 * Congela el contrato de SaleHelper::normalize_iva_percentage_for_pivot (grupo 275, prompt 02):
 * el valor a persistir en iva_percentage de los pivots (article_sale y article_current_acount)
 * nunca es una cadena vacia (se lee como 0 y da un IVA de cero silencioso), nunca se redondea ni
 * se pasa por float, y las etiquetas fiscales ('Exento', 'No Gravado') viajan intactas.
 *
 * Test sin base de datos: no escribe una sola fila, todo son funciones puras sobre strings.
 *
 * @group iva-pivot
 */
class IvaPercentagePivotTest extends TestCase
{
    /** @test */
    public function exento_se_devuelve_exacto()
    {
        $this->assertSame('Exento', SaleHelper::normalize_iva_percentage_for_pivot('Exento'));
    }

    /** @test */
    public function no_gravado_se_devuelve_exacto()
    {
        $this->assertSame('No Gravado', SaleHelper::normalize_iva_percentage_for_pivot('No Gravado'));
    }

    /** @test */
    public function un_entero_se_devuelve_como_string()
    {
        $this->assertSame('21', SaleHelper::normalize_iva_percentage_for_pivot(21));
    }

    /** @test */
    public function decimal_como_string_no_se_reformatea()
    {
        // Este test es el que impide que alguien meta un number_format ahi adentro: '10.5' tiene
        // que salir '10.5', no '10.50' ni un float.
        $this->assertSame('10.5', SaleHelper::normalize_iva_percentage_for_pivot('10.5'));
    }

    /** @test */
    public function null_devuelve_null()
    {
        $this->assertNull(SaleHelper::normalize_iva_percentage_for_pivot(null));
    }

    /** @test */
    public function cadena_vacia_devuelve_null()
    {
        $this->assertNull(SaleHelper::normalize_iva_percentage_for_pivot(''));
    }

    /** @test */
    public function solo_espacios_devuelve_null()
    {
        $this->assertNull(SaleHelper::normalize_iva_percentage_for_pivot('   '));
    }

    /** @test */
    public function recorta_espacios_alrededor_del_valor()
    {
        $this->assertSame('21', SaleHelper::normalize_iva_percentage_for_pivot('  21  '));
    }

    /** @test */
    public function etiqueta_desconocida_se_persiste_igual()
    {
        // No hay lista blanca: un IVA propio del cliente (IvaController) se persiste tal cual,
        // sin recortar a 20 caracteres ni descartarse.
        $this->assertSame('Percepcion especial', SaleHelper::normalize_iva_percentage_for_pivot('Percepcion especial'));
    }

    /**
     * Documenta el contrato que consume AfipItemCalculator (get_price_without_iva,
     * monto_iva_del_precio): la condicion exacta que usan esos metodos para decidir si un item
     * esta gravado es `$article_iva !== 'No Gravado' && $article_iva !== 'Exento'`. Si mañana
     * alguien normaliza 'Exento' a '0', este test se pone en rojo.
     *
     * @test
     */
    public function exento_y_no_gravado_caen_del_lado_de_no_gravado()
    {
        $exento = SaleHelper::normalize_iva_percentage_for_pivot('Exento');
        $no_gravado = SaleHelper::normalize_iva_percentage_for_pivot('No Gravado');

        $this->assertFalse($exento !== 'No Gravado' && $exento !== 'Exento');
        $this->assertFalse($no_gravado !== 'No Gravado' && $no_gravado !== 'Exento');
    }

    /** @test */
    public function una_alicuota_numerica_cae_del_lado_de_gravado()
    {
        $veintiuno = SaleHelper::normalize_iva_percentage_for_pivot(21);

        $this->assertTrue($veintiuno !== 'No Gravado' && $veintiuno !== 'Exento');
    }
}
