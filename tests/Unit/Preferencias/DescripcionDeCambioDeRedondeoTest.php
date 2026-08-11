<?php

namespace Tests\Unit\Preferencias;

use App\Http\Controllers\Helpers\UserProfileChangeDescriptionHelper;
use App\Models\User;
use Tests\TestCase;

/**
 * Tarea 4 — el renglón de aviso que ven las OTRAS sesiones cuando alguien cambia el modo de
 * redondeo, producido por `UserProfileChangeDescriptionHelper::build_change_descriptions()`.
 *
 * Por qué existe este archivo: la tarea 4 le agregó 93 líneas a ese helper —el `continue` que
 * suprime las cuatro líneas de tildes, el renglón único, `derivar_modo_redondeo()` y
 * `texto_modo_redondeo()`— y no tenían **ningún** test, ni acá ni en `develop`. La fase de
 * verificación encontró en esas líneas un texto mal armado que decía
 * `"Opciones de redondeo: de de a 100 a de a 50"`, con el "de" duplicado, y no había nada que lo
 * atajara. Estos tests son ese algo.
 *
 * Es unitario y no feature a propósito: el helper es estático y recibe dos arrays, así que no hace
 * falta base ni request. No hereda de `TestCase` por la base sino por el autoload de la app.
 */
class DescripcionDeCambioDeRedondeoTest extends TestCase
{
    /**
     * Arma un snapshot con las cinco columnas de redondeo, prendiendo las que se le pasen.
     *
     * @param array<int, string> $prendidas
     * @return array<string, int>
     */
    private function snapshot(array $prendidas = [])
    {
        $snap = [];

        foreach (array_keys(User::COLUMNAS_MODO_REDONDEO) as $columna) {
            $snap[$columna] = in_array($columna, $prendidas, true) ? 1 : 0;
        }

        return $snap;
    }

    /**
     * Devuelve el renglón de redondeo de la lista, o null si no lo hay.
     *
     * @param array<int, string> $lines
     * @return string|null
     */
    private function renglon_de_redondeo(array $lines)
    {
        foreach ($lines as $line) {
            if (strpos($line, UserProfileChangeDescriptionHelper::LABEL_MODO_REDONDEO) === 0) {
                return $line;
            }
        }

        return null;
    }

    /**
     * 🔴 El test del bug: el texto no puede decir "de de a 100".
     *
     * `texto_modo_redondeo()` ya devuelve "de a 100", así que el molde común de los demás campos
     * (`: de X a Y`) duplicaba el "de". Se afirma el string completo y no un `assertNotContains`,
     * porque lo que hay que fijar es el formato, no la ausencia de un typo puntual.
     *
     * @group preferencias
     * @test
     */
    public function el_renglon_de_redondeo_no_duplica_el_de()
    {
        $lines = UserProfileChangeDescriptionHelper::build_change_descriptions(
            $this->snapshot(['redondear_centenas_en_vender']),
            $this->snapshot(['redondear_de_a_50']),
            null,
            null
        );

        $this->assertEquals('Opciones de redondeo: de a 100 → de a 50', $this->renglon_de_redondeo($lines));
        $this->assertStringNotContainsString('de de a', $this->renglon_de_redondeo($lines));
    }

    /**
     * Apagar el redondeo es el caso que la tarea 4 arregló en el endpoint, así que también tiene
     * que producir un aviso legible.
     *
     * @group preferencias
     * @test
     */
    public function apagar_el_redondeo_se_describe_como_sin_redondeo()
    {
        $lines = UserProfileChangeDescriptionHelper::build_change_descriptions(
            $this->snapshot(['redondear_de_a_50']),
            $this->snapshot([]),
            null,
            null
        );

        $this->assertEquals('Opciones de redondeo: de a 50 → sin redondeo', $this->renglon_de_redondeo($lines));
    }

    /**
     * 🔴 El renglón es UNO SOLO, aunque el cambio de modo mueva dos columnas. Es el motivo por el
     * que existe el `continue`: antes salían hasta cuatro líneas de tildes que el usuario nunca vio,
     * porque en la pantalla ya no hay tildes sino un select.
     *
     * @group preferencias
     * @test
     */
    public function un_cambio_de_modo_produce_una_sola_linea_y_no_una_por_columna()
    {
        $lines = UserProfileChangeDescriptionHelper::build_change_descriptions(
            $this->snapshot(['redondear_centenas_en_vender']),
            $this->snapshot(['redondear_de_a_50']),
            null,
            null
        );

        // Ninguna línea puede ser la de una tilde suelta. Va ANTES del assertCount a propósito: si
        // el `continue` desapareciera, las dos aserciones fallarían, pero esta dice CUÁL es el
        // renglón de más y la otra sólo dice "esperaba 1, hay 3".
        //
        // Se comparan las ETIQUETAS de `FIELD_LABELS`, que es lo que el helper emitiría de verdad
        // en ese caso — no los nombres de columna, que el helper no emite nunca y por lo tanto
        // nunca podrían fallar.
        $etiquetas_de_tildes = [
            'Redondear de a 1000',
            'Redondear centenas al vender',
            'Redondear precios en decenas',
            'Redondear de a 50',
            'Redondear precios en centavos',
        ];

        foreach ($etiquetas_de_tildes as $etiqueta) {
            foreach ($lines as $line) {
                $this->assertStringNotContainsString($etiqueta, $line);
            }
        }

        // Dos columnas se movieron (centenas 1->0 y de_a_50 0->1) y sale un solo renglón.
        $this->assertCount(1, $lines);
    }

    /**
     * Contracara: si el modo no cambió, no hay ningún renglón de redondeo. Sin esta línea base el
     * test de arriba pasaría igual con un helper que emitiera el renglón siempre.
     *
     * @group preferencias
     * @test
     */
    public function guardar_sin_cambiar_el_modo_no_genera_ningun_renglon()
    {
        $sin_cambios = $this->snapshot(['redondear_de_a_50']);

        $lines = UserProfileChangeDescriptionHelper::build_change_descriptions(
            $sin_cambios,
            $sin_cambios,
            null,
            null
        );

        $this->assertNull($this->renglon_de_redondeo($lines));
    }

    /**
     * Una combinación de dos o más columnas se describe como personalizada, con la misma regla que
     * `User::getModoRedondeoAttribute()`. Si las dos derivaciones se separaran, el aviso diría una
     * cosa y el select mostraría otra.
     *
     * @group preferencias
     * @test
     */
    public function una_combinacion_de_dos_columnas_se_describe_como_personalizada()
    {
        $lines = UserProfileChangeDescriptionHelper::build_change_descriptions(
            $this->snapshot([]),
            $this->snapshot(['redondear_centenas_en_vender', 'redondear_de_a_50']),
            null,
            null
        );

        $this->assertEquals('Opciones de redondeo: sin redondeo → combinacion personalizada', $this->renglon_de_redondeo($lines));
    }
}
