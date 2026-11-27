<?php

namespace Tests\Feature\CartelPrecioEfectivo;

use App\Http\Controllers\Helpers\ArticleOfferCashPriceHelper;

/**
 * CA2 y CA3 — el porcentaje del cartel es el mismo que se descuenta al cobrar, y el redondeo
 * al peso (misión soporte-t173-m44).
 *
 * @group cartel_precio_efectivo
 */
class Porcentaje_efectivo_transferencia_Test extends CartelPrecioEfectivoTestCase
{
    /** @test */
    public function test_efectivo_5_da_5()
    {
        $dueno = $this->crear_dueno();
        $this->crear_regla($dueno, $this->crear_metodo('Efectivo'), 5);

        $this->assertSame(5.0, ArticleOfferCashPriceHelper::cash_discount_percentage($dueno->id));
    }

    /** @test */
    public function test_efectivo_5_y_transferencia_8_da_el_mayor()
    {
        $dueno = $this->crear_dueno();
        $this->crear_regla($dueno, $this->crear_metodo('Efectivo'), 5);
        $this->crear_regla($dueno, $this->crear_metodo('Transferencia'), 8);

        $this->assertSame(8.0, ArticleOfferCashPriceHelper::cash_discount_percentage($dueno->id));
    }

    /** @test */
    public function test_solo_debito_da_null()
    {
        $dueno = $this->crear_dueno();
        $this->crear_regla($dueno, $this->crear_metodo('Débito'), 15);

        $this->assertNull(ArticleOfferCashPriceHelper::cash_discount_percentage($dueno->id));
    }

    /** @test */
    public function test_recargo_negativo_da_null()
    {
        $dueno = $this->crear_dueno();
        $this->crear_regla($dueno, $this->crear_metodo('Efectivo'), -10);

        $this->assertNull(ArticleOfferCashPriceHelper::cash_discount_percentage($dueno->id));
    }

    /** @test */
    public function test_sin_reglas_da_null()
    {
        $dueno = $this->crear_dueno();

        $this->assertNull(ArticleOfferCashPriceHelper::cash_discount_percentage($dueno->id));
    }

    /** @test */
    public function test_regla_con_cuotas_se_ignora()
    {
        $dueno = $this->crear_dueno();
        $this->crear_regla($dueno, $this->crear_metodo('Efectivo'), 20, 3);

        $this->assertNull(ArticleOfferCashPriceHelper::cash_discount_percentage($dueno->id));
    }

    /** @test */
    public function test_regla_de_otro_dueno_se_ignora()
    {
        $dueno = $this->crear_dueno();
        $otro = $this->crear_dueno();
        $this->crear_regla($otro, $this->crear_metodo('Efectivo'), 5);

        $this->assertNull(ArticleOfferCashPriceHelper::cash_discount_percentage($dueno->id));
    }

    /** @test */
    public function test_con_dos_reglas_del_mismo_metodo_manda_la_mas_nueva()
    {
        $dueno = $this->crear_dueno();
        $efectivo = $this->crear_metodo('Efectivo');
        $this->crear_regla($dueno, $efectivo, 10, null, '2026-01-01 10:00:00');
        $this->crear_regla($dueno, $efectivo, 5, null, '2026-06-01 10:00:00');

        $this->assertSame(5.0, ArticleOfferCashPriceHelper::cash_discount_percentage($dueno->id));
    }

    /** @test */
    public function test_transferencia_bancaria_cuenta_como_transferencia()
    {
        $dueno = $this->crear_dueno();
        $this->crear_regla($dueno, $this->crear_metodo('Transferencia bancaria'), 7);

        $this->assertSame(7.0, ArticleOfferCashPriceHelper::cash_discount_percentage($dueno->id));
    }

    /** @test */
    public function test_regla_huerfana_de_metodo_borrado_da_null()
    {
        $dueno = $this->crear_dueno();
        $efectivo = $this->crear_metodo('Efectivo');
        $this->crear_regla($dueno, $efectivo, 5);
        $efectivo->delete();

        $this->assertNull(ArticleOfferCashPriceHelper::cash_discount_percentage($dueno->id));
    }

    /** @test */
    public function test_cash_price_redondea_al_peso()
    {
        $this->assertSame(2374.0, ArticleOfferCashPriceHelper::cash_price(2499, 5));
        $this->assertSame(900.0, ArticleOfferCashPriceHelper::cash_price(1000, 10));
        $this->assertSame(2850.0, ArticleOfferCashPriceHelper::cash_price(3000, 5));
        $this->assertSame(1900.0, ArticleOfferCashPriceHelper::cash_price(1999.99, 5));
    }
}
