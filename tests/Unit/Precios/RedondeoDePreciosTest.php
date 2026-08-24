<?php

namespace Tests\Unit\Precios;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Models\User;
use Tests\TestCase;

/**
 * Tarea 4 — unitarias de `ArticleHelper::redondear()`, el cálculo aislado que decide el precio
 * final que ve el cliente.
 *
 * 🔴 El método NO se tocó en esta tarea y no hay que tocarlo. Estos tests existen para DOCUMENTAR
 * el comportamiento que la fachada `modo_redondeo` tiene que preservar: son la línea base contra la
 * que se compara si algún día alguien decide unificar los `ceil`/`round` o cambiar el orden de los
 * `if`. Si alguno de estos se pone rojo sin que nadie haya cambiado el método a propósito, lo que
 * se rompió es el cálculo de precios de los ~40 clientes.
 *
 * Dos cosas que los tests fijan y que son fáciles de romper "limpiando" el método:
 *
 * 1. **Los cinco flags se encadenan.** No hay ningún return temprano: cada `if` pisa el precio del
 *    anterior. Por eso dos flags prendidos dan un resultado que no es el de ninguno de los dos por
 *    separado, y por eso el select no puede colapsar esa combinación a un valor único.
 * 2. **El de a 50 se aplica DESPUÉS del de a 10**, aunque en la lista del formulario el 50 se
 *    muestre antes que el 10 (ahí el orden es de paso más grande a más chico, para el que lee).
 *
 * No toca la base: arma un User en memoria y nunca lo persiste.
 */
class RedondeoDePreciosTest extends TestCase
{
    /**
     * Usuario en memoria con las columnas de redondeo pedidas en 1 y el resto en 0.
     *
     * @param array<int, string> $columnas_prendidas
     * @return \App\Models\User
     */
    protected function user(array $columnas_prendidas = [])
    {
        $user = new User();

        $columnas = [
            'redondear_miles_en_vender',
            'redondear_centenas_en_vender',
            'redondear_precios_en_decenas',
            'redondear_de_a_50',
            'redondear_precios_en_centavos',
        ];

        foreach ($columnas as $columna) {
            $user->$columna = in_array($columna, $columnas_prendidas, true) ? 1 : 0;
        }

        return $user;
    }

    /**
     * @group precios-unit
     * @test
     */
    public function sin_ningun_flag_el_precio_no_se_toca()
    {
        $resultado = ArticleHelper::redondear(1234.56, $this->user());

        $this->assertEquals(1234.56, $resultado['price']);
    }

    /**
     * @group precios-unit
     * @test
     */
    public function miles_redondea_al_mil_mas_cercano()
    {
        $this->assertEquals(1000, ArticleHelper::redondear(1234, $this->user(['redondear_miles_en_vender']))['price']);
        $this->assertEquals(2000, ArticleHelper::redondear(1567, $this->user(['redondear_miles_en_vender']))['price']);
    }

    /**
     * El guard de `> 100` es el que hace que un artículo de $80 NO se redondee. Es exactamente el
     * que le faltaba al redondeo de display de la SPA, que mostraba ese artículo en $100.
     *
     * @group precios-unit
     * @test
     */
    public function centenas_no_toca_los_precios_menores_o_iguales_a_100()
    {
        $this->assertEquals(80, ArticleHelper::redondear(80, $this->user(['redondear_centenas_en_vender']))['price']);
        $this->assertEquals(100, ArticleHelper::redondear(100, $this->user(['redondear_centenas_en_vender']))['price']);
    }

    /**
     * @group precios-unit
     * @test
     */
    public function centenas_redondea_a_la_centena_mas_cercana_y_no_siempre_hacia_arriba()
    {
        // 120 baja a 100: es round, no ceil. Con ceil daría 200.
        $this->assertEquals(100, ArticleHelper::redondear(120, $this->user(['redondear_centenas_en_vender']))['price']);
        $this->assertEquals(200, ArticleHelper::redondear(180, $this->user(['redondear_centenas_en_vender']))['price']);
    }

    /**
     * @group precios-unit
     * @test
     */
    public function decenas_redondea_a_la_decena_mas_cercana()
    {
        $this->assertEquals(120, ArticleHelper::redondear(123, $this->user(['redondear_precios_en_decenas']))['price']);
        $this->assertEquals(130, ArticleHelper::redondear(126, $this->user(['redondear_precios_en_decenas']))['price']);
    }

    /**
     * @group precios-unit
     * @test
     */
    public function de_a_50_siempre_va_hacia_arriba()
    {
        // 104 -> 150, no 100: es el único de los cinco que usa ceil.
        $this->assertEquals(150, ArticleHelper::redondear(104, $this->user(['redondear_de_a_50']))['price']);
        $this->assertEquals(50, ArticleHelper::redondear(1, $this->user(['redondear_de_a_50']))['price']);
        // Un precio que ya es múltiplo de 50 no sube: ceil es idempotente ahí.
        $this->assertEquals(100, ArticleHelper::redondear(100, $this->user(['redondear_de_a_50']))['price']);
    }

    /**
     * @group precios-unit
     * @test
     */
    public function centavos_elimina_los_decimales()
    {
        $this->assertEquals(1235, ArticleHelper::redondear(1234.56, $this->user(['redondear_precios_en_centavos']))['price']);
        $this->assertEquals(1234, ArticleHelper::redondear(1234.4, $this->user(['redondear_precios_en_centavos']))['price']);
    }

    /**
     * 🔴 La razón de fondo por la que el select es una fachada y no una columna nueva.
     *
     * Con 104: `centenas` lo lleva a 100 y después `de_a_50` lo deja en 100. Con `de_a_50` solo,
     * el resultado es 150. O sea que la combinación no equivale a ninguna de las dos opciones por
     * separado, y colapsarla a un valor único le cambiaría el precio al cliente que la tenga.
     *
     * @group precios-unit
     * @test
     */
    public function dos_flags_encadenados_dan_un_resultado_propio()
    {
        $combinado = ArticleHelper::redondear(104, $this->user(['redondear_centenas_en_vender', 'redondear_de_a_50']))['price'];
        $solo_50   = ArticleHelper::redondear(104, $this->user(['redondear_de_a_50']))['price'];

        $this->assertEquals(100, $combinado);
        $this->assertEquals(150, $solo_50);
        $this->assertNotEquals($combinado, $solo_50);
    }

    /**
     * El de a 50 corre después del de a 10, aunque el formulario los liste al revés.
     *
     * @group precios-unit
     * @test
     */
    public function el_de_a_50_se_aplica_despues_del_de_a_10()
    {
        // 104 -> decenas -> 100 -> de a 50 -> 100.
        // Si el orden fuera al revés: 104 -> de a 50 -> 150 -> decenas -> 150.
        $resultado = ArticleHelper::redondear(104, $this->user(['redondear_precios_en_decenas', 'redondear_de_a_50']))['price'];

        $this->assertEquals(100, $resultado);
    }
}
