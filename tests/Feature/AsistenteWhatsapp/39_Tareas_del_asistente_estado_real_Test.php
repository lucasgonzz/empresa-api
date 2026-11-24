<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Models\AiConversation;
use App\Services\AsistenteIa\AsistenteIaService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Misión agente-tareas-programadas, arreglo de PREVENCIÓN (23/11/2026): el asistente recibe en cada turno la lista
 * REAL de tareas del dueño, dentro del bloque de tareas del system que ya existe, más una regla corta.
 *
 * El problema (prueba real en demo3): en una conversación larga el modelo contestó «Lo cancelé», «Ya está cancelada»
 * o «Quedó programada para hoy» sin llamar a la herramienta, imitando sus propias respuestas anteriores. No se toca
 * el texto de ninguna respuesta (el intento por texto se retiró por falsos positivos): sólo se le dan datos
 * verdaderos al contexto.
 *
 * Qué fijan estos tests:
 *   - activas, pausadas y canceladas de las últimas 24 h aparecen con su título y su estado, junto a la regla;
 *   - una cancelada hace más de 24 h no aparece; sin tareas hay una línea que lo dice;
 *   - tras cancelar en un turno, el turno SIGUIENTE (job real, modelo doblado) la ve como cancelada;
 *   - turno desatendido y conversación de empleado: nada de la lista;
 *   - la cantidad de bloques del system no cambia y el agregado no pasa de ~1.500 caracteres.
 *
 * PHP 7.4: sin match, sin nullsafe, sin str_contains, sin argumentos nombrados.
 */
class Tareas_del_asistente_estado_real_Test extends TareasDelAsistenteConModeloTestCase
{
    /** Cómo arranca el agregado: sirve para recortar el bloque del resto del system. */
    const ENCABEZADO = 'Tareas programadas del dueño AHORA';

    /**
     * El system completo (todos los bloques, cacheados o no) que se arma para una conversación de WhatsApp.
     *
     * @param  \App\Models\AiConversation  $conversacion
     * @return array<int,array>
     */
    protected function bloques_del_system($conversacion)
    {
        return (new AsistenteIaService())->build_system_payload($conversacion, $this->comercio, false, true, false);
    }

    /**
     * Todos los textos del system juntos.
     *
     * @param  array  $bloques
     * @return string
     */
    protected function texto_del_system(array $bloques)
    {
        $textos = [];

        foreach ($bloques as $bloque) {
            $textos[] = (string) $bloque['text'];
        }

        return implode("\n\n", $textos);
    }

    /**
     * Lo que va desde el encabezado del agregado hasta el final del system ('' si no está).
     *
     * @param  string  $system
     * @return string
     */
    protected function agregado_de($system)
    {
        $posicion = strpos($system, self::ENCABEZADO);

        return $posicion === false ? '' : substr($system, $posicion);
    }

    /**
     * Una tarea cuyo `updated_at` (cuándo cambió de estado) se fija a mano, en horas hacia atrás.
     *
     * @param  array  $columnas
     * @param  int  $horas_atras
     * @return \App\Models\AiTareaDelAsistente
     */
    protected function tarea_con_cambio_hace($columnas, $horas_atras)
    {
        $tarea = $this->tarea_del_asistente($columnas);

        $momento = Carbon::now()->subHours($horas_atras)->format('Y-m-d H:i:s');

        DB::table('ai_tareas_del_asistente')->where('id', $tarea->id)->update(['updated_at' => $momento]);

        return $tarea;
    }

    /**
     * 🔴 CA1 + CA2. Con una activa, una pausada y una cancelada hace una hora, el system trae los tres títulos con
     * su estado y la regla de que esa lista es la verdad.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function con_activa_pausada_y_cancelada_reciente_el_system_trae_la_lista_y_la_regla()
    {
        $activa = $this->tarea_del_asistente([
            'titulo'      => 'Ventas del día a primera hora',
            'recurrencia' => 'diaria',
            'hora'        => '08:30',
        ]);
        $pausada = $this->tarea_del_asistente(['titulo' => 'Resumen semanal de caja', 'estado' => 'pausada']);
        $cancelada = $this->tarea_con_cambio_hace(['titulo' => 'Preguntas de logística', 'estado' => 'cancelada', 'proxima_ejecucion_at' => null], 1);

        $agregado = $this->agregado_de($this->texto_del_system($this->bloques_del_system($this->conversacion_whatsapp())));

        $this->assertNotSame('', $agregado, 'El system no trae el estado real de las tareas.');

        $this->assertStringContainsString('[id ' . $activa->id . '] «Ventas del día a primera hora»', $agregado);
        $this->assertStringContainsString('todos los días a las 08:30', $agregado);
        $this->assertStringContainsString('(activa)', $agregado);

        $this->assertStringContainsString('[id ' . $pausada->id . '] «Resumen semanal de caja»', $agregado);
        $this->assertStringContainsString('(pausada)', $agregado);

        $this->assertStringContainsString('[id ' . $cancelada->id . '] «Preguntas de logística»', $agregado);
        $this->assertStringContainsString('cancelada hace 1 h', $agregado);

        // La regla: es la verdad, y no se dice que se hizo algo sin llamar a la herramienta en este turno.
        $this->assertStringContainsString('es la verdad', $agregado);
        $this->assertStringContainsString('No digas que programaste, cambiaste o cancelaste', $agregado);
        $this->assertStringContainsString('vale la lista', $agregado);
    }

    /**
     * 🔴 CA1. Una cancelada hace 30 horas ya no aparece (sólo cuentan las últimas 24 h), y las terminadas recientes
     * sí, con lo que les pasó.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function la_cancelada_vieja_no_aparece_y_la_terminada_reciente_si()
    {
        $this->tarea_del_asistente(['titulo' => 'Tarea viva']);
        $this->tarea_con_cambio_hace(['titulo' => 'Cancelada de anteayer', 'estado' => 'cancelada', 'proxima_ejecucion_at' => null], 30);
        $this->tarea_con_cambio_hace(['titulo' => 'Terminada de recién', 'estado' => 'terminada', 'proxima_ejecucion_at' => null], 2);

        $agregado = $this->agregado_de($this->texto_del_system($this->bloques_del_system($this->conversacion_whatsapp())));

        $this->assertStringContainsString('Tarea viva', $agregado);
        $this->assertStringNotContainsString('Cancelada de anteayer', $agregado, 'Una cancelada de hace 30 h no tiene que figurar.');
        $this->assertStringContainsString('Terminada de recién', $agregado);
        $this->assertStringContainsString('terminada hace 2 h', $agregado);
    }

    /**
     * 🔴 CA1. Sin ninguna tarea, una línea que lo dice (y la regla igual: es lo que frena el «ya la programé»).
     *
     * @group asistente-whatsapp
     * @test
     */
    public function sin_tareas_dice_que_no_hay_ninguna()
    {
        $agregado = $this->agregado_de($this->texto_del_system($this->bloques_del_system($this->conversacion_whatsapp())));

        $this->assertStringContainsString('El dueño no tiene ninguna tarea programada.', $agregado);
        $this->assertStringContainsString('No digas que programaste, cambiaste o cancelaste', $agregado);
    }

    /**
     * 🔴 CA1 + CA3. De punta a punta con el job real y el modelo doblado: el primer turno ve la tarea activa; el
     * dueño la cancela (herramienta real); el turno SIGUIENTE ya la ve cancelada y no activa.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function tras_cancelar_el_turno_siguiente_la_ve_cancelada()
    {
        $tarea = $this->tarea_del_asistente(['titulo' => 'Aviso de reposición']);

        $this->el_dueno_escribe('Hola, ¿qué tengo programado?');

        $primero = $this->pedidos_al_modelo();
        $this->assertCount(1, $primero, 'El primer turno tiene que haber llamado una vez al modelo.');

        $system_primero = $this->system_sin_cache($primero[0]);

        $this->assertStringContainsString('[id ' . $tarea->id . '] «Aviso de reposición»', $system_primero);
        $this->assertStringContainsString('(activa)', $system_primero);

        $cancelacion = $this->herramienta('cancelar_tarea_del_asistente', ['tarea_id' => $tarea->id]);
        $this->assertFalse($cancelacion['error'], 'La herramienta de cancelar no tendría que fallar.');

        $this->el_dueno_escribe('Gracias, ¿y ahora?');

        $todos = $this->pedidos_al_modelo();
        $ultimo = $todos[count($todos) - 1];

        $this->assertCount(2, $todos, 'Un llamado al modelo por turno.');

        $system_segundo = $this->system_sin_cache($ultimo);

        $this->assertStringContainsString('[id ' . $tarea->id . '] «Aviso de reposición»', $system_segundo);
        $this->assertStringContainsString('cancelada hace', $system_segundo);
        $this->assertStringNotContainsString('Aviso de reposición»: el', $system_segundo, 'Ya no tiene que figurar como viva.');
        $this->assertStringNotContainsString('(activa)', $this->agregado_de($system_segundo));
    }

    /**
     * 🔴 CA4. Al empleado no se le ofrecen las herramientas de tareas: la lista del dueño no viaja en su system. Y
     * en un turno desatendido (lo despertó una tarea) tampoco: ahí manda el aviso propio de ese turno.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function empleado_y_turno_desatendido_no_reciben_la_lista()
    {
        $this->tarea_del_asistente(['titulo' => 'Tarea secreta del dueño']);

        // El empleado.
        $del_empleado = AiConversation::create([
            'user_id'         => $this->comercio->id,
            'auth_user_id'    => $this->empleado->id,
            'origen'          => AiConversation::ORIGEN_WHATSAPP,
            'last_message_at' => Carbon::now(),
        ]);

        $texto_empleado = $this->texto_del_system($this->bloques_del_system($del_empleado));

        $this->assertStringNotContainsString(self::ENCABEZADO, $texto_empleado);
        $this->assertStringNotContainsString('Tarea secreta del dueño', $texto_empleado);

        // El turno desatendido: una tarea que vence en un minuto despierta su turno.
        $despertada = $this->tarea_del_asistente([
            'titulo'               => 'Se despierta ya',
            'fecha_inicio'         => '2026-11-21',
            'hora'                 => '10:01',
            'franja'               => null,
            'proxima_ejecucion_at' => '2026-11-21 10:01:00',
        ]);

        $this->despertar();

        $ejecuciones = $this->ejecuciones($despertada->id);

        $this->assertCount(1, $ejecuciones, 'El comando no despertó la tarea.');

        $conversacion = AiConversation::find($ejecuciones[0]->ai_conversation_id);

        $texto_desatendido = $this->texto_del_system($this->bloques_del_system($conversacion));

        $this->assertStringContainsString('Este turno no lo escribió el dueño', $texto_desatendido, 'Tendría que seguir el aviso del turno desatendido.');
        $this->assertStringNotContainsString(self::ENCABEZADO, $texto_desatendido);
        $this->assertStringNotContainsString('Tarea secreta del dueño', $texto_desatendido);
    }

    /**
     * 🔴 CA4. La cantidad de bloques del system es la misma con tareas, sin tareas y para el empleado: el estado
     * se suma al final del último bloque no cacheado, no abre uno nuevo ni toca el cacheado.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function la_cantidad_de_bloques_no_cambia_y_el_cacheado_queda_igual()
    {
        $sin_tareas = $this->bloques_del_system($this->conversacion_whatsapp());

        $this->tarea_del_asistente(['titulo' => 'Una']);
        $this->tarea_del_asistente(['titulo' => 'Dos']);
        $this->tarea_con_cambio_hace(['titulo' => 'Tres', 'estado' => 'cancelada', 'proxima_ejecucion_at' => null], 1);

        $con_tareas = $this->bloques_del_system($this->conversacion_whatsapp());

        $del_empleado = $this->bloques_del_system(AiConversation::create([
            'user_id'         => $this->comercio->id,
            'auth_user_id'    => $this->empleado->id,
            'origen'          => AiConversation::ORIGEN_WHATSAPP,
            'last_message_at' => Carbon::now(),
        ]));

        $this->assertCount(count($del_empleado), $sin_tareas, 'Sin tareas, mismos bloques que el empleado.');
        $this->assertCount(count($sin_tareas), $con_tareas, 'Con tareas, mismos bloques que sin tareas.');

        $this->assertSame($sin_tareas[0], $con_tareas[0], 'El bloque cacheado no puede cambiar.');
        $this->assertArrayHasKey('cache_control', $con_tareas[0]);

        foreach ($con_tareas as $bloque) {
            if (strpos((string) $bloque['text'], self::ENCABEZADO) !== false) {
                $this->assertArrayNotHasKey('cache_control', $bloque, 'La lista no puede ir en un bloque cacheado.');
            }
        }
    }

    /**
     * 🔴 CA5. Con las diez activas permitidas, títulos de 120 caracteres y cinco canceladas recientes, el agregado
     * no pasa de 1.500 caracteres y las diez activas siguen a la vista (con id y estado).
     *
     * @group asistente-whatsapp
     * @test
     */
    public function el_agregado_no_pasa_de_1500_caracteres_con_titulos_largos()
    {
        $ids_activas = [];

        for ($i = 1; $i <= 10; $i++) {
            $titulo = 'Título número ' . $i . ' ' . str_repeat('muy largo ', 12);
            $ids_activas[] = (int) $this->tarea_del_asistente(['titulo' => mb_substr($titulo, 0, 120), 'recurrencia' => 'diaria', 'hora' => '08:30'])->id;
        }

        for ($i = 1; $i <= 5; $i++) {
            $this->tarea_con_cambio_hace(['titulo' => mb_substr('Cancelada ' . $i . ' ' . str_repeat('larga ', 20), 0, 120), 'estado' => 'cancelada', 'proxima_ejecucion_at' => null], $i);
        }

        $agregado = $this->agregado_de($this->texto_del_system($this->bloques_del_system($this->conversacion_whatsapp())));

        $this->assertNotSame('', $agregado, 'El system no trae el estado real de las tareas.');
        $this->assertLessThanOrEqual(1500, mb_strlen($agregado), 'El agregado pasa de 1.500 caracteres (' . mb_strlen($agregado) . ').');

        foreach ($ids_activas as $id) {
            $this->assertStringContainsString('[id ' . $id . ']', $agregado, 'Una activa quedó afuera por el tope de tamaño.');
        }

        $this->assertStringContainsString('No digas que programaste, cambiaste o cancelaste', $agregado, 'La regla no se recorta.');
    }
}
