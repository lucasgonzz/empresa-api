<?php

namespace Tests\Feature\OnboardingConversacional;

use App\Http\Controllers\Helpers\asistente_ia\TareasDelAsistenteIaHelper;
use App\Jobs\ResponderMensajeChatIaJob;
use App\Models\AiMessage;
use Illuminate\Support\Facades\DB;

/**
 * Misión onboarding-pausa-y-temas-20261125 — dos fallas reales medidas en demo3 (owner 300, 25/11/2026):
 *
 *   Causa 1: el onboarding quedaba `pausado_por_el_dueno` sin que el dueño lo pidiera. Al cruzar los 13 esenciales
 *            el MODELO cancelaba la tarea de seguimiento creyendo que había terminado, y el helper leía esa
 *            cancelación como una pausa del dueño.
 *   Causa 2: el modelo sólo veía 5 claves faltantes, así que sólo podía marcar 5 temas por mensaje; y nada
 *            contrastaba «ya no me quedan preguntas» contra las filas de la base.
 *
 * 🔴 Datos ficticios. El modelo es un doble (secuencia de tool_use / end_turn): se verifica estado, filas y texto final.
 *
 * PHP 7.4: sin match, sin nullsafe, sin str_contains, sin argumentos nombrados.
 */
class Pausa_explicita_y_temas_Test extends OnboardingTestCase
{
    /** Lo que dijo el modelo en demo3 (msg 192) y que la base desmentía. */
    const CIERRE_FALSO = 'Me lo anoto. Como ya no me quedan preguntas del negocio, seguimos con otra cosa.';

    /** El mismo cierre sin «Me lo anoto» (esa frase la revisa otra guarda, la de las anotaciones prometidas). */
    const CIERRE_FALSO_SIN_ANOTAR = 'Como ya no me quedan preguntas del negocio, seguimos con otra cosa.';

    /**
     * Los 17 temas que demo3 tenía sabidos cuando el modelo dijo que no quedaban preguntas.
     *
     * @return array<int,string>
     */
    protected function diecisiete()
    {
        return array_slice(self::TEMAS, 0, 17);
    }

    /**
     * CA2 — Una cancelación vieja (dato previo al arreglo) no pausa: el onboarding sigue y se agenda uno nuevo.
     *
     * @group onboarding-conversacional
     * @test
     */
    public function test_cancelacion_vieja_no_pausa_y_reagenda()
    {
        $this->el_dueno_escribe('Hola, ¿andás?');

        $tarea = $this->tareas_de_onboarding('activa')[0];

        // El dato viejo: la tarea quedó cancelada por el motor (como la dejó el modelo en demo3), sin pausa del dueño.
        TareasDelAsistenteIaHelper::cancelar($this->comercio, ['tarea_id' => $tarea->id]);

        $this->assertSame('cancelada', $this->estado_de_tarea($tarea->id), 'Precondición: la tarea está cancelada.');
        $this->assertSame('en_curso', $this->onboarding()->ia_onboarding_estado);

        $this->el_dueno_escribe('Gracias, seguimos');

        $this->assertSame('en_curso', $this->onboarding()->ia_onboarding_estado, 'Una tarea cancelada no es una pausa del dueño.');

        $activas = $this->tareas_de_onboarding('activa');

        $this->assertCount(1, $activas);
        $this->assertSame('2026-11-24 17:00:00', $activas[0]->proxima_ejecucion_at->format('Y-m-d H:i:s'));
    }

    /**
     * CA3 — La pausa explícita se conserva: sólo `pausar` la escribe y sólo `retomar` la levanta.
     *
     * @group onboarding-conversacional
     * @test
     */
    public function test_pausa_explicita_no_se_levanta_sola()
    {
        $this->el_dueno_escribe('Hola, ¿andás?');

        $this->herramienta('conocimiento_del_negocio', ['accion' => 'pausar']);

        $this->assertSame('pausado_por_el_dueno', $this->onboarding()->ia_onboarding_estado);
        $this->assertCount(0, $this->tareas_de_onboarding('activa'));

        $this->el_dueno_escribe('Uno');
        $this->el_dueno_escribe('Dos');
        $this->el_dueno_escribe('Tres');

        $this->assertSame('pausado_por_el_dueno', $this->onboarding()->ia_onboarding_estado, 'Escribirle no lo despausa.');
        $this->assertCount(0, $this->tareas_de_onboarding('activa'));

        $this->herramienta('conocimiento_del_negocio', ['accion' => 'retomar']);

        $this->assertSame('en_curso', $this->onboarding()->ia_onboarding_estado);
        $this->assertCount(1, $this->tareas_de_onboarding('activa'));
    }

    /**
     * CA4 — Completa los 13 esenciales y sigue hasta los 32, con el recordatorio disparado y cancelado al terminar.
     *
     * @group onboarding-conversacional
     * @test
     */
    public function test_trece_esenciales_sigue_hasta_32_con_recordatorio()
    {
        $this->el_dueno_escribe('Hola, ¿andás?');

        $this->assertSame('en_curso', $this->onboarding()->ia_onboarding_estado);

        $tarea_a = $this->tareas_de_onboarding('activa')[0];

        $esenciales = $this->el_agente_guarda(self::ESENCIALES);

        $this->assertNotNull($this->onboarding()->ia_onboarding_esenciales_at);
        $this->assertArrayHasKey('aviso_onboarding', $esenciales['datos']);
        $this->assertStringContainsString('lo esencial', $esenciales['datos']['aviso_onboarding']);
        $this->assertStringContainsString('13 de 32', $esenciales['datos']['aviso_onboarding']);

        // El modelo cree que terminó e intenta cancelar «su» tarea: se lo rechazan.
        $cancelar = $this->herramienta('cancelar_tarea_del_asistente', ['tarea_id' => $tarea_a->id]);

        $this->assertTrue($cancelar['error']);
        $this->assertSame('activa', $this->estado_de_tarea($tarea_a->id));

        $this->el_dueno_escribe('Seguimos');

        $this->assertSame('en_curso', $this->onboarding()->ia_onboarding_estado);
        $this->assertSame('activa', $this->estado_de_tarea($tarea_a->id), 'La tarea A sigue activa.');

        // El recordatorio se dispara: una ejecución de onboarding y una tarea nueva para +3 días.
        $this->despierta_y_corre_el_turno();

        $this->assertCount(1, $this->ejecuciones(), 'Una ejecución de onboarding.');

        $activas = $this->tareas_de_onboarding('activa');

        $this->assertCount(1, $activas);
        $this->assertSame('2026-11-27 17:00:00', $activas[0]->proxima_ejecucion_at->format('Y-m-d H:i:s'));

        // Los 19 restantes: completo, sin recordatorios.
        $this->el_agente_guarda(array_values(array_diff(self::TEMAS, self::ESENCIALES)));

        $dueno = $this->onboarding();

        $this->assertSame('completo', $dueno->ia_onboarding_estado);
        $this->assertNotNull($dueno->ia_onboarding_completado_at);
        $this->assertCount(0, $this->tareas_de_onboarding('activa'));

        $todas = $this->tareas_de_onboarding();

        $this->assertSame('cancelada', $todas[count($todas) - 1]->estado, 'La última tarea queda cancelada al terminar.');

        $this->el_dueno_escribe('Gracias por todo');

        $this->assertSame('completo', $this->onboarding()->ia_onboarding_estado);
        $this->assertCount(0, $this->tareas_de_onboarding('activa'));
    }

    /**
     * Reproducción causal de demo3 (msg 191→193) con el turno completo: en UN turno el modelo marca los 13 esenciales
     * y cancela la tarea de seguimiento; en el turno siguiente el onboarding tiene que seguir en curso.
     *
     * @group onboarding-conversacional
     * @test
     */
    public function test_el_modelo_cancela_la_tarea_en_el_turno_y_el_onboarding_sigue()
    {
        $this->el_dueno_escribe('Hola, ¿andás?');

        $tarea = $this->tareas_de_onboarding('activa')[0];

        // El turno con herramientas mide su presupuesto de tiempo contra el reloj real (25/11/2026 o después): un reloj
        // fijo en el pasado lo deja sin tiempo para la vuelta de cierre (misma razón que el recorrido de la suite 10).
        $this->ahora('2026-12-13 11:00:00');

        $this->modelo_en_secuencia([
            $this->paso_guardar(self::ESENCIALES),
            ['herramienta' => 'cancelar_tarea_del_asistente', 'input' => ['tarea_id' => $tarea->id]],
            'Me lo anoto. Seguimos de a poco.',
        ]);

        $respuesta = $this->mandar(['texto' => 'Te cuento todo el negocio', 'whatsapp_message_id' => 'wamid.CAUSAL1'])->assertStatus(202);

        (new ResponderMensajeChatIaJob((int) $respuesta->json('ai_message_id')))->handle();

        $this->assertNotNull($this->onboarding()->ia_onboarding_esenciales_at, 'Los 13 esenciales quedaron marcados.');

        $this->el_dueno_escribe('Gracias');

        $this->assertSame('en_curso', $this->onboarding()->ia_onboarding_estado, 'Causa 1: el onboarding no se pausa solo.');
        $this->assertSame('activa', $this->estado_de_tarea($tarea->id));
    }

    /**
     * CA5 — El modelo ve TODAS las claves que faltan (hoy sólo veía 5) y el bloque no pasa de 2500 caracteres.
     *
     * @group onboarding-conversacional
     * @test
     */
    public function test_bloque_lista_todas_las_claves_faltantes()
    {
        $this->el_dueno_escribe('Hola, ¿andás?');

        $bloque = $this->bloque_de_onboarding();

        $this->assertLessThanOrEqual(2500, mb_strlen($bloque), 'Con 32 faltantes el bloque entra en 2500.');
        $this->assertStringContainsString('no la canceles', $bloque);

        $linea = $this->linea_de_claves($bloque);

        foreach (self::TEMAS as $clave) {

            $this->assertStringContainsString($clave, $linea, 'La clave ' . $clave . ' tiene que estar en la lista de faltantes.');
        }

        // Con los 17 de demo3 sabidos: sólo las 15 restantes figuran como faltantes.
        $this->el_agente_guarda($this->diecisiete());
        $this->el_dueno_escribe('Seguimos');

        $linea = $this->linea_de_claves($this->bloque_de_onboarding());

        foreach (array_slice(self::TEMAS, 17) as $clave) {

            $this->assertStringContainsString($clave, $linea);
        }

        foreach ($this->diecisiete() as $clave) {

            $this->assertStringNotContainsString($clave, $linea, 'La clave ' . $clave . ' ya está sabida: no es faltante.');
        }
    }

    /**
     * CA6 — Tras marcar, el aviso trae el progreso real de la base; antes de los 13 esenciales no dice «lo esencial».
     *
     * @group onboarding-conversacional
     * @test
     */
    public function test_aviso_con_el_progreso_de_la_base()
    {
        $this->el_dueno_escribe('Hola, ¿andás?');

        $resultado = $this->el_agente_guarda($this->diecisiete());

        $this->assertArrayHasKey('aviso_onboarding', $resultado['datos']);

        $aviso = $resultado['datos']['aviso_onboarding'];

        $this->assertStringContainsString('17 de 32', $aviso);

        foreach (array_slice(self::TEMAS, 17) as $clave) {

            $this->assertStringContainsString($clave, $aviso, 'El aviso nombra las claves que faltan.');
        }

        // Con 12 de 13 esenciales: progreso sí, «lo esencial» no.
        DB::table('ai_conocimiento_del_negocio')->where('owner_id', $this->comercio->id)->delete();

        $parcial = $this->el_agente_guarda(array_slice(self::ESENCIALES, 0, 12));

        $this->assertArrayHasKey('aviso_onboarding', $parcial['datos']);
        $this->assertStringContainsString('12 de 32', $parcial['datos']['aviso_onboarding']);
        $this->assertStringNotContainsString('lo esencial', $parcial['datos']['aviso_onboarding']);
    }

    /**
     * CA7 — Si el modelo dice que no quedan preguntas y la base dice que faltan, sale un texto verdadero.
     *
     * @group onboarding-conversacional
     * @test
     */
    public function test_no_dice_que_termino_si_faltan_temas()
    {
        $this->el_dueno_escribe('Hola, ¿andás?');
        $this->el_agente_guarda($this->diecisiete());

        // El turno con herramientas mide su presupuesto de tiempo contra el reloj real (25/11/2026 o después): un reloj
        // fijo en el pasado lo deja sin tiempo para la vuelta de cierre (misma razón que el recorrido de la suite 10).
        $this->ahora('2026-12-13 11:00:00');

        $this->modelo_en_secuencia([
            $this->paso_guardar(['historia']),
            self::CIERRE_FALSO,
            self::CIERRE_FALSO,
        ]);

        $respuesta = $this->mandar(['texto' => 'Te sigo contando', 'whatsapp_message_id' => 'wamid.CIERRE1'])->assertStatus(202);

        (new ResponderMensajeChatIaJob((int) $respuesta->json('ai_message_id')))->handle();

        $texto = AiMessage::find((int) $respuesta->json('ai_message_id'))->contenido;

        $this->assertStringNotContainsString('no me quedan preguntas', $texto);
        $this->assertStringContainsString('Todavía me faltan 15 temas', $texto);
        $this->assertCount(17, $this->temas_marcados(), 'La base sigue en 17.');
    }

    /**
     * CA7 — La guarda sólo corre con el onboarding en curso y faltantes: completo o pausado, el texto sale igual;
     * una pregunta como «¿te quedan dudas?» no dispara.
     *
     * @group onboarding-conversacional
     * @test
     */
    public function test_completo_o_pausado_no_se_toca()
    {
        $this->el_dueno_escribe('Hola, ¿andás?');

        $this->onboarding_en('completo');

        $assistant = $this->el_dueno_escribe('Gracias', self::CIERRE_FALSO_SIN_ANOTAR);

        $this->assertSame(self::CIERRE_FALSO_SIN_ANOTAR, $assistant->contenido, 'Completo: sale igual.');

        $this->onboarding_en('pausado_por_el_dueno');

        $assistant = $this->el_dueno_escribe('Gracias de nuevo', self::CIERRE_FALSO_SIN_ANOTAR);

        $this->assertSame(self::CIERRE_FALSO_SIN_ANOTAR, $assistant->contenido, 'Pausado por el dueño: sale igual.');

        $this->onboarding_en('en_curso');

        $assistant = $this->el_dueno_escribe('Dale', '¿Te quedan dudas sobre cómo funciona? Contame cómo se llama tu negocio.');

        $this->assertStringContainsString('¿Te quedan dudas', $assistant->contenido, 'Una pregunta no es una afirmación de cierre.');
        $this->assertStringNotContainsString('Todavía me faltan', $assistant->contenido);
    }

    /**
     * La línea «Claves que faltan (usalas en temas): …» del bloque; falla el test si no está.
     *
     * @param  string  $bloque
     * @return string
     */
    protected function linea_de_claves($bloque)
    {
        foreach (explode("\n", $bloque) as $linea) {

            if (strpos($linea, 'Claves que faltan (usalas en temas):') === 0) {

                return $linea;
            }
        }

        $this->fail('El bloque no tiene la línea «Claves que faltan (usalas en temas): …».');

        return '';
    }
}
