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
        // Ronda 2: el estado ya no está al final del system (abre el último bloque), así que se corta hasta la regla.
        return $this->estado_delimitado($system);
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
        // Precondición (misión onboarding-conversacional-whatsapp): el primer mensaje por WhatsApp de un dueño SIN
        // historial arranca el onboarding y crea su propia tarea (activa). Este test habla de la tarea del dueño, así
        // que el dueño ya le había escrito antes al canal (mensaje viejo), como en OnboardingTestCase::con_historial_de_whatsapp.
        $previa = $this->conversacion_whatsapp(now()->subDays(40));
        $mensaje_previo = $this->mensaje($previa, 'user', 'listo', ['contenido' => 'Hola, probando']);
        DB::table('ai_messages')->where('id', $mensaje_previo->id)->update(['created_at' => now()->subDays(40), 'updated_at' => now()->subDays(40)]);

        $tarea = $this->tarea_del_asistente(['titulo' => 'Aviso de reposición']);

        $this->el_dueno_escribe('Hola, ¿qué tengo programado?');

        $primero = $this->pedidos_al_modelo();
        $this->assertCount(1, $primero, 'El primer turno tiene que haber llamado una vez al modelo.');

        $system_primero = $this->system_sin_cache($primero[0]);

        $this->assertStringContainsString('[id ' . $tarea->id . '] «Aviso de reposición»', $system_primero);
        $this->assertStringContainsString('(activa)', $system_primero);

        $cancelacion = $this->herramienta('cancelar_tarea_del_asistente', ['tarea_id' => $tarea->id]);
        $this->assertFalse($cancelacion['error'], 'La herramienta de cancelar no tendría que fallar.');

        // herramienta() deja un assistant 'pendiente' en una conversación aparte; el job real espera a los turnos en
        // curso del dueño y el segundo turno nunca correría. Se cierra: aquí se prueba el estado, no la cola de turnos.
        DB::table('ai_messages')->where('estado', 'pendiente')->update(['estado' => 'listo']);

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
        $this->assertLessThanOrEqual(2000, mb_strlen($agregado), 'El agregado pasa de 2.000 caracteres (' . mb_strlen($agregado) . ').');

        foreach ($ids_activas as $id) {
            $this->assertStringContainsString('[id ' . $id . ']', $agregado, 'Una activa quedó afuera por el tope de tamaño.');
        }

        $this->assertStringContainsString('No digas que programaste, cambiaste o cancelaste', $agregado, 'La regla no se recorta.');
    }

    /**
     * El estado real recortado: desde el encabezado hasta el final de la regla fija (no incluye lo que venga
     * después en el system, venga o no al final).
     *
     * @param  string  $system
     * @return string
     */
    protected function estado_delimitado($system)
    {
        $desde = strpos($system, self::ENCABEZADO);

        if ($desde === false) {
            return '';
        }

        $fin = 'vale la lista: corregilo.';
        $hasta = strpos($system, $fin, $desde);

        return $hasta === false ? substr($system, $desde) : substr($system, $desde, $hasta + strlen($fin) - $desde);
    }

    /**
     * Cuántas tareas dice el texto que no entran («… y N tarea(s) más…»), 0 si no hay esa línea.
     *
     * @param  string  $estado
     * @return int
     */
    protected function cuantas_no_entran($estado)
    {
        return preg_match('/… y (\d+) tareas? más que no entran acá: llamá a consultar_tareas_del_asistente antes de afirmar algo sobre ellas\./u', $estado, $m) ? (int) $m[1] : 0;
    }

    /**
     * 🔴 Ronda 2, hallazgo 1. Diez activas lunes a viernes con títulos cortos y sin canceladas: las diez tienen que
     * estar en el texto (antes se descartaba la última en silencio) y el agregado no pasa de 2.000 caracteres.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function diez_activas_de_lunes_a_viernes_aparecen_las_diez()
    {
        $ids = [];

        for ($i = 1; $i <= 10; $i++) {
            $ids[] = (int) $this->tarea_del_asistente([
                'titulo'               => 'Reporte ' . $i,
                'recurrencia'          => 'dias_semana',
                'dias'                 => '1,2,3,4,5',
                'hora'                 => '08:30',
                'fecha_inicio'         => null,
                'proxima_ejecucion_at' => '2026-11-23 08:30:00',
            ])->id;
        }

        $estado = $this->estado_delimitado($this->texto_del_system($this->bloques_del_system($this->conversacion_whatsapp())));

        $this->assertLessThanOrEqual(2000, mb_strlen($estado), 'Pasa de 2.000 caracteres (' . mb_strlen($estado) . ').');

        foreach ($ids as $id) {
            $this->assertStringContainsString('[id ' . $id . ']', $estado, 'Se descartó una tarea viva sin avisar.');
        }
    }

    /**
     * 🔴 Ronda 2, hallazgo 1. Diez activas de lunes a sábado con fecha de fin, tres pausadas y cinco canceladas
     * recientes (trece vivas): nunca se descarta una viva en silencio. Cada una está en el texto o está contada en la
     * línea «… y N tarea(s) más…», con N exacto; y el agregado no pasa de 2.000 caracteres.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function trece_vivas_con_recientes_ninguna_se_pierde_en_silencio()
    {
        $ids = [];

        for ($i = 1; $i <= 10; $i++) {
            $ids[] = (int) $this->tarea_del_asistente([
                'titulo'               => 'Activa ' . $i . ' ' . str_repeat('de ventas ', 3),
                'recurrencia'          => 'dias_semana',
                'dias'                 => '1,2,3,4,5,6',
                'hora'                 => '08:30',
                'fecha_inicio'         => null,
                'fecha_fin'            => '2026-12-31',
                'proxima_ejecucion_at' => '2026-11-23 08:30:00',
            ])->id;
        }

        for ($i = 1; $i <= 3; $i++) {
            $ids[] = (int) $this->tarea_del_asistente([
                'titulo'               => 'Pausada ' . $i . ' ' . str_repeat('de caja ', 3),
                'recurrencia'          => 'dias_semana',
                'dias'                 => '1,3,5',
                'hora'                 => '09:00',
                'fecha_inicio'         => null,
                'estado'               => 'pausada',
                'proxima_ejecucion_at' => null,
            ])->id;
        }

        for ($i = 1; $i <= 5; $i++) {
            $this->tarea_con_cambio_hace(['titulo' => 'Cancelada ' . $i, 'estado' => 'cancelada', 'proxima_ejecucion_at' => null], $i);
        }

        $estado = $this->estado_delimitado($this->texto_del_system($this->bloques_del_system($this->conversacion_whatsapp())));

        $this->assertLessThanOrEqual(2000, mb_strlen($estado), 'Pasa de 2.000 caracteres (' . mb_strlen($estado) . ').');

        $presentes = 0;

        foreach ($ids as $id) {
            if (strpos($estado, '[id ' . $id . ']') !== false) {
                $presentes++;
            }
        }

        $no_entran = $this->cuantas_no_entran($estado);

        $this->assertSame(13, $presentes + $no_entran, 'Cada viva tiene que estar en el texto o contada en «y N tareas más» (presentes ' . $presentes . ', N ' . $no_entran . ').');
        $this->assertStringContainsString('No digas que programaste, cambiaste o cancelaste', $estado, 'La regla no se recorta.');
    }

    /**
     * 🔴 Ronda 2, hallazgo 2. El estado viaja en el último bloque no cacheado SIN pisar lo que otros tests fijan de ese
     * bloque: la memoria sigue empezando con su etiqueta y su tramo «lo aprendido» no se mide con el estado adentro. El
     * bloque cacheado queda idéntico y la cantidad de bloques no cambia.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function el_estado_viaja_en_el_ultimo_bloque_sin_correr_la_memoria()
    {
        $sin_tareas = $this->bloques_del_system($this->conversacion_whatsapp());

        $this->tarea_del_asistente(['titulo' => 'Una sola']);

        $con_tareas = $this->bloques_del_system($this->conversacion_whatsapp());

        $this->assertGreaterThan(1, count($con_tareas), 'El caso necesita un bloque no cacheado previo.');
        $this->assertSame($sin_tareas[0], $con_tareas[0], 'El bloque cacheado no puede cambiar.');
        $this->assertCount(count($sin_tareas), $con_tareas);

        $ultimo = (string) $con_tareas[count($con_tareas) - 1]['text'];

        $this->assertArrayNotHasKey('cache_control', $con_tareas[count($con_tareas) - 1]);
        $this->assertStringContainsString(self::ENCABEZADO, $ultimo, 'El estado viaja en el último bloque no cacheado.');
        $this->assertStringContainsString('Ahora son las 10:00', $ultimo, 'La hora sigue en el bloque de tareas.');
        $this->assertStringStartsWith('<memoria_del_negocio>', $ultimo, 'La memoria tiene que seguir empezando con su etiqueta.');

        $desde_lo_aprendido = strpos($ultimo, '=== aprendizajes/correcciones.md');

        if ($desde_lo_aprendido !== false) {
            $this->assertStringNotContainsString(self::ENCABEZADO, substr($ultimo, $desde_lo_aprendido), 'El estado no puede quedar dentro del tramo de lo aprendido.');
        }
    }
}
