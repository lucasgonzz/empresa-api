<?php

namespace Tests\Feature\Precios\RecalculoEnLote;

/**
 * 🔴 EL CORAZÓN DE LA MISIÓN recalculo-precios-motor-rapido (28/9/2026): el motor en lote deja la
 * base EXACTAMENTE igual que el recálculo por artículo de hoy.
 *
 * Cada test arma una configuración de cuenta distinta (las que el cálculo de precios distingue),
 * corre los dos caminos sobre la misma base (RecalculoEnLoteTestCase::comparar_caminos()) y
 * compara, campo por campo: todas las columnas de `articles` (updated_at incluido, con el reloj
 * congelado), las filas de article_price_type y de article_price_type_monedas, los price_changes
 * con sus filas de price_change_price_type, y qué artículos cuentan como "cambiaron de precio".
 *
 * Los escenarios arrancan con una pasada "de calentamiento" del camino de hoy (los precios quedan
 * calculados y estables) y después se le pisa el precio a una parte: así en cada corrida hay
 * artículos que cambian y artículos que no, que son dos caminos distintos del cálculo (el que
 * genera price_change y el que no).
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group recalculo-en-lote
 */
class Equivalencia_con_el_camino_por_articulo_Test extends RecalculoEnLoteTestCase
{
    /**
     * La cuenta de Servian (sección 2 del plan): sin listas, IVA al costo legacy
     * (aplicar_iva_al_costo = 1, sin migrar), redondeo de centavos, sin impuestos sobre ventas,
     * un proveedor con margen del 70 % y descuentos de ficha en porcentaje y en monto.
     *
     * @return void
     */
    public function test_cuenta_simple_con_iva_al_costo_legacy_como_servian()
    {
        $dueno = $this->crear_dueno([
            'aplicar_iva_al_costo'          => 1,
            'usar_condicion_fiscal_en_costeo' => 0,
            'redondear_precios_en_centavos' => 1,
        ]);

        $proveedor = $this->crear_proveedor($dueno, ['percentage_gain' => 70]);

        $con_descuentos = $this->crear_articulo($dueno, ['cost' => 1234.56, 'provider_id' => $proveedor->id]);
        $this->descuento($con_descuentos, ['percentage' => 10]);
        $this->descuento($con_descuentos, ['amount' => 50]);

        $con_iva_10 = $this->crear_articulo($dueno, ['cost' => 999.99, 'provider_id' => $proveedor->id, 'iva_id' => self::IVA_10_5]);
        $this->descuento($con_iva_10, ['percentage' => 7.5]);

        $con_margen_propio = $this->crear_articulo($dueno, ['cost' => 250, 'provider_id' => $proveedor->id, 'percentage_gain' => 35]);

        $sin_cambio = $this->crear_articulo($dueno, ['cost' => 480.40, 'provider_id' => $proveedor->id]);

        $sin_costo_ni_precio = $this->crear_articulo($dueno, ['provider_id' => $proveedor->id]);

        $precio_manual = $this->crear_articulo($dueno, ['price' => 1500, 'apply_provider_percentage_gain' => 0]);

        $ids = [
            $con_descuentos->id,
            $con_iva_10->id,
            $con_margen_propio->id,
            $sin_cambio->id,
            $sin_costo_ni_precio->id,
            $precio_manual->id,
        ];

        /* Calentamiento: precios calculados y estables. */
        $this->recalcular_como_hoy($ids, $dueno->id);

        $this->pisar_precio_final([$con_descuentos->id, $con_iva_10->id, $con_margen_propio->id, $precio_manual->id], 1);

        $r = $this->comparar_caminos($ids, $dueno->id, 2);

        /* Guardas: si el escenario no produjo cambios, la comparación no prueba nada. */
        $this->assertEqualsCanonicalizing(
            [$con_descuentos->id, $con_iva_10->id, $con_margen_propio->id, $precio_manual->id],
            $r['cambiaron_hoy'],
            'El escenario tenía que cambiar exactamente los cuatro precios pisados.'
        );

        $this->assertCount(4, $r['foto_hoy']['cambios'], 'Cuatro artículos con su price_change.');
        $this->assertArrayNotHasKey($sin_cambio->id, $r['foto_hoy']['cambios'], 'El que no cambió de precio no genera price_change.');

        /*
         * updated_at: el camino de hoy se lo pone a todo artículo con costo (el save() con
         * timestamps que sigue al cálculo del costo real), y a ninguno sin costo. El motor lo
         * reproduce: por eso la foto lo compara por valor y acá se deja escrito.
         */
        $this->assertSame(self::AHORA, $r['foto_hoy']['articles'][$sin_cambio->id]['updated_at'], 'Referencia: hoy el recálculo toca updated_at de un artículo con costo aunque su precio no cambie.');
        $this->assertNotSame(self::AHORA, $r['foto_hoy']['articles'][$sin_costo_ni_precio->id]['updated_at'], 'Referencia: hoy el recálculo no toca updated_at de un artículo sin costo.');
    }
}
