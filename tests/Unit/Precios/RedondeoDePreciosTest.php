<?php

namespace Tests\Unit\Precios;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Models\User;
use Tests\TestCase;

/**
 * Tarea 4 — unitarias de `ArticleHelper::redondear()`, el cálculo aislado que decide el precio
 * final que ve el cliente.
 *
 * 🔴 El método NO se tocó en la Tarea 4 y no hay que tocarlo sin una razón (el 17/10/2026 se le sumó solo el
 * sentido `redondear_centenas_para_arriba`, que apagado deja todo igual). Estos tests existen para DOCUMENTAR
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
            'redondear_centenas_para_arriba',
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
     * `redondear_centenas_para_arriba`: con centenas prendido el redondeo va siempre a la centena de
     * ARRIBA (ceil), que es lo que hacía el sistema antes de la Tarea 4 y lo que espera Feitoamao.
     * El caso del pedido: 159.907,50 -> 160.000 con la opción, 159.900 sin ella.
     *
     * @group precios-unit
     * @test
     */
    public function centenas_para_arriba_sube_siempre_a_la_centena_de_arriba()
    {
        $con = $this->user(['redondear_centenas_en_vender', 'redondear_centenas_para_arriba']);
        $sin = $this->user(['redondear_centenas_en_vender']);

        $this->assertEquals(160000, ArticleHelper::redondear(159907.50, $con)['price']);
        $this->assertEquals(159900, ArticleHelper::redondear(159907.50, $sin)['price']);

        // 120 sube a 200 con la opción (con round bajaba a 100).
        $this->assertEquals(200, ArticleHelper::redondear(120, $con)['price']);
        $this->assertEquals(100, ArticleHelper::redondear(120, $sin)['price']);

        // Un múltiplo exacto de 100 no se mueve: ceil es idempotente ahí.
        $this->assertEquals(1500, ArticleHelper::redondear(1500, $con)['price']);
    }

    /**
     * Apagada la opción, el desglose del precio conserva el texto histórico ("por centenas"); con
     * ella prendida dice el sentido. Fija que el cambio no tocó lo que ven los clientes que no la usan.
     *
     * @group precios-unit
     * @test
     */
    public function el_desglose_de_centenas_conserva_su_texto_sin_la_opcion()
    {
        $sin = ArticleHelper::redondear(159907.50, $this->user(['redondear_centenas_en_vender']));
        $con = ArticleHelper::redondear(159907.50, $this->user(['redondear_centenas_en_vender', 'redondear_centenas_para_arriba']));

        $this->assertStringContainsString('Redondeando por centenas = ', json_encode($sin['des']));
        $this->assertStringNotContainsString('hacia arriba', json_encode($sin['des']));
        $this->assertStringContainsString('hacia arriba', json_encode($con['des']));
    }

    /**
     * El guard de `> 100` rige también con la opción: un artículo de $80 no se vuelve $100.
     *
     * @group precios-unit
     * @test
     */
    public function centenas_para_arriba_respeta_el_guard_de_mayor_a_100()
    {
        $con = $this->user(['redondear_centenas_en_vender', 'redondear_centenas_para_arriba']);

        $this->assertEquals(80, ArticleHelper::redondear(80, $con)['price']);
        $this->assertEquals(100, ArticleHelper::redondear(100, $con)['price']);
        // Recién arriba de 100 entra: 100,5 -> 200.
        $this->assertEquals(200, ArticleHelper::redondear(100.5, $con)['price']);
    }

    /**
     * La opción sola, sin `redondear_centenas_en_vender`, no hace nada: no es un modo de redondeo
     * propio sino el sentido del de centenas.
     *
     * @group precios-unit
     * @test
     */
    public function centenas_para_arriba_sin_centenas_prendido_no_redondea()
    {
        $solo = $this->user(['redondear_centenas_para_arriba']);

        $this->assertEquals(159907.50, ArticleHelper::redondear(159907.50, $solo)['price']);
        $this->assertEquals(120, ArticleHelper::redondear(120, $solo)['price']);
    }

    /**
     * Un usuario que no tiene el atributo (por ejemplo, una fila anterior a la migración) se
     * comporta como siempre: al más cercano.
     *
     * @group precios-unit
     * @test
     */
    public function sin_el_atributo_de_para_arriba_centenas_sigue_siendo_al_mas_cercano()
    {
        $user = new User();
        $user->redondear_centenas_en_vender = 1;

        $this->assertEquals(100, ArticleHelper::redondear(120, $user)['price']);
        $this->assertEquals(159900, ArticleHelper::redondear(159907.50, $user)['price']);
    }

    /**
     * Encadena como el resto de los flags: centenas hacia arriba y después de a 50 sobre ese
     * resultado (ya es múltiplo de 100, así que de a 50 no lo mueve).
     *
     * @group precios-unit
     * @test
     */
    public function centenas_para_arriba_se_encadena_con_los_otros_flags()
    {
        $user = $this->user(['redondear_centenas_en_vender', 'redondear_centenas_para_arriba', 'redondear_de_a_50']);

        $this->assertEquals(200, ArticleHelper::redondear(104, $user)['price']);
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
