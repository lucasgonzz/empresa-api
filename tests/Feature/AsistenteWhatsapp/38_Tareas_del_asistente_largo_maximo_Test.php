<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Http\Controllers\Helpers\asistente_ia\EntregaDeResultadosDeTareasIaHelper;

/**
 * Misión agente-tareas-programadas, RONDA 3 — EL MENSAJE QUE SALE NUNCA SUPERA LOS 4096 CARACTERES.
 *
 * El hallazgo (rechazo de las dos revisiones de la ronda 2): al anteponer los resultados de las tareas a la
 * respuesta del modelo, el mensaje final podía pasar los 4096 caracteres de un mensaje de WhatsApp. El admin lo
 * manda tal cual, Meta lo rechaza y, como `mostrada_at` ya quedó marcada, el dueño pierde la respuesta Y los
 * resultados. Medidos: 1 resultado de 3500 + respuesta de 2900 = 4102; 2 de 2100 + 300 = 4098; 3 de 1500 + 80 =
 * 4100; 1 de 3500 + 3500 = 4702.
 *
 * Regla de reparto que fijan estos tests:
 *   - la respuesta del modelo NUNCA se recorta ni se pierde;
 *   - los resultados se recortan al espacio que queda (el aviso «seguí en la app» cuenta adentro);
 *   - si el espacio no alcanza para mostrar algo útil de un resultado, ese resultado NO se antepone en este turno
 *     y NO se marca como mostrado: queda para el turno siguiente (dentro de sus 24 h).
 *
 * Se mide siempre con mb_strlen (el largo en caracteres, no en bytes).
 *
 * PHP 7.4: sin match, sin nullsafe, sin str_contains, sin argumentos nombrados.
 */
class Tareas_del_asistente_largo_maximo_Test extends TareasDelAsistenteConModeloTestCase
{
    /** El tope de un mensaje de WhatsApp. */
    const TOPE = 4096;

    /**
     * Un texto de exactamente `$largo` caracteres (multibyte a propósito: ñ, tildes y emoji).
     *
     * @param  int  $largo
     * @param  string  $marca  Letra con la que arranca, para reconocerlo en el mensaje.
     * @return string
     */
    protected function texto_de($largo, $marca = 'R')
    {
        $base = $marca . 'ñandú sólo vió pingüinos 🙂 ';

        $texto = '';

        while (mb_strlen($texto) < $largo) {
            $texto .= $base;
        }

        $texto = mb_substr($texto, 0, $largo);

        // Que no termine en espacio: el código recorta los bordes de la respuesta y el largo medido tiene que ser el real.
        return $largo > 0 && mb_substr($texto, -1) === ' ' ? mb_substr($texto, 0, $largo - 1) . '.' : $texto;
    }

    /**
     * Pendientes de prueba: `$cantidad` resultados de `$largo` caracteres.
     *
     * @param  int  $cantidad
     * @param  int  $largo
     * @return array<int, array{id: int, titulo: string, texto: string}>
     */
    protected function pendientes_de($cantidad, $largo)
    {
        $pendientes = [];

        for ($i = 1; $i <= $cantidad; $i++) {
            $pendientes[] = ['id' => $i, 'titulo' => 'Tarea ' . $i, 'texto' => $this->texto_de($largo, (string) $i)];
        }

        return $pendientes;
    }

    /**
     * 🔴 Los cuatro casos medidos en la revisión: el mensaje armado no pasa de 4096 y la respuesta del modelo
     * sale entera, al final.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function los_cuatro_casos_medidos_no_pasan_de_4096()
    {
        $casos = [[1, 3500, 2900], [2, 2100, 300], [3, 1500, 80], [1, 3500, 3500]];

        foreach ($casos as $caso) {

            list($cantidad, $largo, $largo_respuesta) = $caso;

            $respuesta = $this->texto_de($largo_respuesta, 'Z');

            $salida = EntregaDeResultadosDeTareasIaHelper::anteponer($this->pendientes_de($cantidad, $largo), $respuesta);

            $etiqueta = $cantidad . 'x' . $largo . ' + ' . $largo_respuesta;

            $this->assertLessThanOrEqual(self::TOPE, mb_strlen($salida), 'Pasa de 4096 (' . mb_strlen($salida) . ') con ' . $etiqueta);
            $this->assertTrue(mb_substr($salida, -mb_strlen($respuesta)) === $respuesta, 'La respuesta del modelo tiene que salir entera, al final (' . $etiqueta . ').');
            $this->assertTrue(mb_check_encoding($salida, 'UTF-8'), 'Se partió un carácter (' . $etiqueta . ').');
        }
    }

    /**
     * 🔴 Una respuesta de exactamente 4096 caracteres sale sola, sin resultados (no hay lugar), y entera.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function una_respuesta_de_4096_sale_sola()
    {
        $respuesta = $this->texto_de(4096, 'Z');

        $salida = EntregaDeResultadosDeTareasIaHelper::anteponer($this->pendientes_de(1, 800), $respuesta);

        $this->assertTrue($respuesta === $salida, 'La respuesta de 4096 tiene que salir sola y entera (salió de ' . mb_strlen($salida) . ').');
    }

    /**
     * 🔴 Respuesta vacía: sólo los resultados, sin separador colgando y dentro del tope.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function con_respuesta_vacia_salen_los_resultados_dentro_del_tope()
    {
        $salida = EntregaDeResultadosDeTareasIaHelper::anteponer($this->pendientes_de(3, 3500), '');

        $this->assertLessThanOrEqual(self::TOPE, mb_strlen($salida));
        $this->assertStringContainsString('«Tarea 1»', $salida);
        $this->assertStringContainsString('«Tarea 3»', $salida);
        $this->assertSame($salida, rtrim($salida), 'No tiene que sobrar un separador al final.');
    }

    /**
     * 🔴 Barrido: 1 a 3 resultados × largos de resultado × largos de respuesta. En TODAS el mensaje cabe, y la
     * respuesta (si cabe sola) sale entera al final.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function barrido_de_combinaciones_siempre_dentro_del_tope()
    {
        $largos_de_resultado = [1, 150, 200, 450, 1000, 1500, 2100, 3000, 3500];
        $largos_de_respuesta = [0, 1, 80, 300, 1000, 2000, 2900, 3500, 3700, 3800, 3900, 4000, 4090, 4096];

        $combinaciones = 0;

        for ($cantidad = 1; $cantidad <= 3; $cantidad++) {

            foreach ($largos_de_resultado as $largo) {

                foreach ($largos_de_respuesta as $largo_respuesta) {

                    $respuesta = $largo_respuesta ? $this->texto_de($largo_respuesta, 'Z') : '';

                    $salida = EntregaDeResultadosDeTareasIaHelper::anteponer($this->pendientes_de($cantidad, $largo), $respuesta);

                    $etiqueta = $cantidad . 'x' . $largo . ' + ' . $largo_respuesta;

                    $this->assertLessThanOrEqual(self::TOPE, mb_strlen($salida), 'Pasa de 4096 con ' . $etiqueta);
                    $this->assertTrue($respuesta === '' || mb_substr($salida, -mb_strlen($respuesta)) === $respuesta, 'Se perdió o recortó la respuesta con ' . $etiqueta);

                    $combinaciones++;
                }
            }
        }

        $this->assertSame(3 * count($largos_de_resultado) * count($largos_de_respuesta), $combinaciones);
    }

    /**
     * 🔴 De punta a punta: resultado de 3500 + respuesta del modelo de 2900 → el mensaje guardado (el que lee el
     * admin) cabe en 4096, termina con la respuesta entera y el resultado queda mostrado.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function de_punta_a_punta_un_resultado_largo_y_una_respuesta_larga_caben()
    {
        $resultado = $this->texto_de(3500, 'Q');
        $respuesta = $this->texto_de(2900, 'Z');

        $ejecucion = $this->resultado_listo('Pedidos', $resultado);

        $this->sellar($ejecucion, 'plantilla')->assertStatus(200);

        $this->ahora('2026-11-27 17:30:00');

        $turno = $this->el_dueno_escribe('Dale, contame', $respuesta);

        $contenido = (string) $turno->contenido;

        $this->assertLessThanOrEqual(self::TOPE, mb_strlen($contenido));
        $this->assertTrue(mb_substr($contenido, -mb_strlen($respuesta)) === $respuesta, 'La respuesta del modelo tiene que salir entera, al final.');
        $this->assertStringStartsWith('Qñandú', $contenido);
    }

    /**
     * 🔴 Respuestas de 4096 y de 4000: el resultado no entra, no se antepone, NO se marca mostrado; y en el turno
     * siguiente, con una respuesta corta, sí se entrega y recién ahí se marca.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function si_no_entra_queda_pendiente_para_el_turno_siguiente()
    {
        $resultado = $this->texto_de(3000, 'Q');

        $ejecucion = $this->resultado_listo('Pedidos', $resultado);

        $this->sellar($ejecucion, 'plantilla')->assertStatus(200);

        foreach ([4096, 4000] as $indice => $largo) {

            $this->ahora('2026-11-27 17:' . (10 + $indice) . ':00');

            $respuesta = $this->texto_de($largo, 'Z');

            $turno = $this->el_dueno_escribe('Pregunta ' . $largo, $respuesta);

            $this->assertSame('listo', $turno->estado);
            $this->assertTrue($respuesta === (string) $turno->contenido, 'La respuesta de ' . $largo . ' tiene que salir sola y entera (salió de ' . mb_strlen((string) $turno->contenido) . ').');
            $this->assertNull($this->ejecucion_cruda($ejecucion->id)->mostrada_at, 'Si no entró, no se marca mostrada (' . $largo . ').');
        }

        $this->ahora('2026-11-27 17:20:00');

        $corto = $this->el_dueno_escribe('Ahora sí, contame', 'Listo, decime.');

        $this->assertStringStartsWith('Qñandú', (string) $corto->contenido);
        $this->assertStringEndsWith('Listo, decime.', (string) $corto->contenido);
        $this->assertLessThanOrEqual(self::TOPE, mb_strlen((string) $corto->contenido));
        $this->assertSame('2026-11-27 17:20:00', $this->ejecucion_cruda($ejecucion->id)->mostrada_at);
    }
}
