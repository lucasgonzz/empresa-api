<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Http\Controllers\Helpers\asistente_ia\TareaAfirmadaSinHerramientaIaHelper;
use App\Jobs\ResponderMensajeChatIaJob;
use App\Models\AiConversation;
use App\Models\AiMessage;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Misión agente-tareas-programadas, ARREGLO DE VERACIDAD (23/11/2026) — EL ASISTENTE NO PUEDE AFIRMAR QUE
 * PROGRAMÓ, REPROGRAMÓ O CANCELÓ UNA TAREA SIN HABERLO HECHO.
 *
 * 🔴 EL CASO REAL (demo3, deepseek-flash): cuatro turnos de UNA sola llamada al modelo, sin herramienta,
 * contestaron «Lo cancelé: no te va a escribir más…», «Ya está cancelada: la de ventas diarias no vuelve a
 * correr», «Quedó programada para hoy a las 15:45: te pregunto si ya llamaste al proveedor» y «Quedó
 * programada para hoy a las 15:43: te aviso cómo viene la caja». En la base la tarea seguía activa y las
 * nuevas no existían.
 *
 * Lo que fija este archivo (CA1–CA6 de .mision/PLAN-ARREGLO-VERACIDAD.md):
 *   - CA2/CA4: el texto que afirma la acción sin la herramienta OK reintenta UNA vez con una nota y, si el
 *     modelo insiste, esas oraciones se cambian por un aviso honesto; la base queda como estaba;
 *   - CA5: los cuatro textos reales disparan; con la herramienta OK en el turno, no;
 *   - CA3: no dispara con preguntas, ofrecimientos, la descripción de lo que devolvió la consulta, turnos sin
 *     las herramientas de tareas (empleado, desatendido), cargas armadas en el turno ni textos sobre
 *     pedidos/ventas;
 *   - el detector, frase por frase.
 *
 * 🔴 El job es el real (ResponderMensajeChatIaJob) y el modelo es un doble guionado con Http::fake: ningún test
 * sale a la red.
 *
 * PHP 7.4: sin match, sin nullsafe, sin str_contains, sin argumentos nombrados.
 */
class Tareas_del_asistente_veracidad_Test extends TareasDelAsistenteConModeloTestCase
{
    /** Los cuatro textos reales de la prueba en demo3. */
    const CASO_CANCELE = 'Lo cancelé: no te va a escribir más sobre eso.';
    const CASO_YA_ESTA_CANCELADA = 'Ya está cancelada: la de ventas diarias no vuelve a correr.';
    const CASO_PROGRAMADA_PROVEEDOR = 'Quedó programada para hoy a las 15:45: te pregunto si ya llamaste al proveedor.';
    const CASO_PROGRAMADA_CAJA = 'Quedó programada para hoy a las 15:43: te aviso cómo viene la caja.';

    /** El aviso honesto de cada tipo (CA4). */
    const AVISO_PROGRAMAR = 'Todavía no programé esa tarea';
    const AVISO_REPROGRAMAR = 'Todavía no cambié esa tarea de horario';
    const AVISO_CANCELAR = 'Todavía no cancelé esa tarea';

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * El reloj de la suite queda congelado en la hora REAL de este momento y no en el sábado de las otras
         * suites: el servicio mide el tiempo del turno contra el reloj del sistema, y con el reloj de la app
         * días atrás se creería sin tiempo para seguir y cerraría el turno antes de la segunda vuelta.
         */
        Carbon::setTestNow(null);
        Carbon::setTestNow(Carbon::now());
    }

    /**
     * La fecha de mañana (AAAA-MM-DD) en la zona de la app: el día en que se programan las tareas de prueba.
     *
     * @return string
     */
    protected function manana()
    {
        return Carbon::now()->addDay()->format('Y-m-d');
    }

    /* ─────────────────────────────── el doble guionado ─────────────────────────────── */

    /**
     * El modelo contesta, en orden, cada una de las respuestas; cuando se acaban repite la última (un modelo que
     * insiste). Cada elemento es ['texto', '<lo que dice>'] o ['tool', '<nombre>', <input>].
     *
     * @param  array<int,array>  $guion
     * @return void
     */
    protected function modelo_con_guion(array $guion)
    {
        $this->instalar_el_doble_del_modelo();

        $llamada = 0;

        $this->comportamiento_del_modelo = function () use ($guion, &$llamada) {

            $paso = $guion[min($llamada, count($guion) - 1)];
            $llamada++;

            if ($paso[0] === 'tool') {
                return Http::response([
                    'model'       => 'deepseek-flash-test',
                    'stop_reason' => 'tool_use',
                    'content'     => [['type' => 'tool_use', 'id' => 'toolu_' . uniqid(), 'name' => $paso[1], 'input' => $paso[2]]],
                    'usage'       => ['input_tokens' => 300, 'output_tokens' => 30],
                ], 200);
            }

            return Http::response([
                'model'       => 'deepseek-flash-test',
                'stop_reason' => 'end_turn',
                'content'     => [['type' => 'text', 'text' => $paso[1]]],
                'usage'       => ['input_tokens' => 300, 'output_tokens' => 15],
            ], 200);
        };
    }

    /**
     * El dueño escribe por WhatsApp (POST real del admin) y el job corre con el doble guionado.
     *
     * @param  string  $texto
     * @return \App\Models\AiMessage  El assistant del turno, ya terminado.
     */
    protected function turno_del_dueno($texto)
    {
        $respuesta = $this->mandar(['texto' => $texto, 'whatsapp_message_id' => 'wamid.DUENO' . uniqid()]);

        $respuesta->assertStatus(202);

        $id = (int) $respuesta->json('ai_message_id');

        (new ResponderMensajeChatIaJob($id))->handle();

        return AiMessage::find($id);
    }

    /**
     * Cuántas veces se le habló al modelo en el turno (no cuenta la llamada de cierre sin herramientas).
     *
     * @return int
     */
    protected function vueltas_al_modelo()
    {
        return count($this->pedidos_al_modelo());
    }

    /**
     * true si algún pedido al modelo llevó la nota del reintento de esta guarda.
     *
     * @return bool
     */
    protected function hubo_nota_de_reintento()
    {
        foreach ($this->pedidos_al_modelo() as $cuerpo) {

            if (strpos(implode("\n", $this->textos($cuerpo['messages'])), 'El sistema revisó tu respuesta') !== false) {
                return true;
            }
        }

        return false;
    }

    /** Cuántas tareas del asistente hay en la base del dueño. */
    protected function cantidad_de_tareas()
    {
        return DB::table('ai_tareas_del_asistente')->where('owner_id', $this->comercio->id)->count();
    }

    /* ─────────────────────────────── CA2 / CA4: afirma sin haber hecho ─────────────────────────────── */

    /**
     * 🔴 «Quedó programada…» sin programar_tarea_del_asistente: hay UN reintento con la nota; si el modelo insiste,
     * el texto final NO afirma nada y SÍ trae el aviso honesto. No se creó ninguna tarea.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function programada_sin_herramienta_reintenta_y_avisa_la_verdad()
    {
        $this->modelo_con_guion([['texto', self::CASO_PROGRAMADA_PROVEEDOR]]);

        $turno = $this->turno_del_dueno('Avisame hoy a las 15:45 si ya llamé al proveedor');

        $this->assertSame('listo', $turno->estado);
        $this->assertSame(2, $this->vueltas_al_modelo(), 'Tiene que haber exactamente UN reintento.');
        $this->assertTrue($this->hubo_nota_de_reintento(), 'El reintento lleva la nota del sistema.');
        $this->assertStringNotContainsString('Quedó programada', (string) $turno->contenido);
        $this->assertStringContainsString(self::AVISO_PROGRAMAR, (string) $turno->contenido);
        $this->assertSame(0, $this->cantidad_de_tareas(), 'No se creó ninguna tarea.');
    }

    /**
     * 🔴 «Lo cancelé: no te va a escribir más» sin cancelar_tarea_del_asistente: reintento, aviso honesto y la
     * tarea sigue activa.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function cancelada_sin_herramienta_reintenta_y_avisa_la_verdad()
    {
        $tarea = $this->tarea_del_asistente();

        $this->modelo_con_guion([['texto', self::CASO_CANCELE]]);

        $turno = $this->turno_del_dueno('Cancelame la tarea de logística');

        $this->assertSame(2, $this->vueltas_al_modelo());
        $this->assertTrue($this->hubo_nota_de_reintento());
        $this->assertStringNotContainsString('Lo cancelé', (string) $turno->contenido);
        $this->assertStringContainsString(self::AVISO_CANCELAR, (string) $turno->contenido);
        $this->assertSame('activa', $this->fila_de($tarea->id)->estado, 'La tarea sigue activa.');
    }

    /**
     * 🔴 El segundo texto real de cancelación («Ya está cancelada: la de ventas diarias no vuelve a correr»).
     *
     * @group asistente-whatsapp
     * @test
     */
    public function ya_esta_cancelada_sin_herramienta_avisa_la_verdad()
    {
        $tarea = $this->tarea_del_asistente(['titulo' => 'Ventas diarias']);

        $this->modelo_con_guion([['texto', self::CASO_YA_ESTA_CANCELADA]]);

        $turno = $this->turno_del_dueno('Sacá la tarea de ventas diarias');

        $this->assertStringNotContainsString('Ya está cancelada', (string) $turno->contenido);
        $this->assertStringNotContainsString('no vuelve a correr', (string) $turno->contenido);
        $this->assertStringContainsString(self::AVISO_CANCELAR, (string) $turno->contenido);
        $this->assertSame('activa', $this->fila_de($tarea->id)->estado);
    }

    /**
     * 🔴 «La pasé al viernes» sin reprogramar_tarea_del_asistente: reintento, aviso honesto y la hora de la
     * tarea no se movió.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function reprogramada_sin_herramienta_reintenta_y_avisa_la_verdad()
    {
        $tarea = $this->tarea_del_asistente(['proxima_ejecucion_at' => '2026-11-24 17:00:00', 'fecha_inicio' => '2026-11-24']);

        $this->modelo_con_guion([['texto', 'Listo, la pasé al viernes a las 17 para que no te escriba antes.']]);

        $turno = $this->turno_del_dueno('Pasame la tarea de logística para el viernes');

        $this->assertSame(2, $this->vueltas_al_modelo());
        $this->assertTrue($this->hubo_nota_de_reintento());
        $this->assertStringNotContainsString('la pasé al viernes', (string) $turno->contenido);
        $this->assertStringContainsString(self::AVISO_REPROGRAMAR, (string) $turno->contenido);
        $this->assertSame('2026-11-24 17:00:00', $this->fila_de($tarea->id)->proxima_ejecucion_at, 'La tarea no se movió.');
    }

    /**
     * 🔴 Lo que sí respondía bien se conserva: sólo se cambia la oración que miente.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function solo_se_cambian_las_oraciones_que_mienten()
    {
        $this->modelo_con_guion([['texto', 'Hoy vendiste $120.000 en total. Quedó programada para hoy a las 15:45: te aviso cómo viene la caja.']]);

        $turno = $this->turno_del_dueno('Avisame a las 15:45 cómo viene la caja');

        $this->assertStringContainsString('Hoy vendiste $120.000 en total.', (string) $turno->contenido);
        $this->assertStringNotContainsString('Quedó programada', (string) $turno->contenido);
        $this->assertStringContainsString(self::AVISO_PROGRAMAR, (string) $turno->contenido);
    }

    /* ─────────────────────────────── CA2 / CA5: con la herramienta OK ─────────────────────────────── */

    /**
     * 🔴 El reintento SÍ llama a programar_tarea_del_asistente: la tarea queda hecha y el texto final se conserva
     * sin aviso.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function si_el_reintento_programa_de_verdad_el_texto_se_conserva()
    {
        $this->modelo_con_guion([
            ['texto', self::CASO_PROGRAMADA_CAJA],
            ['tool', 'programar_tarea_del_asistente', [
                'titulo'      => 'Caja de la tarde',
                'instruccion' => 'Mirá cómo viene la caja y contale al dueño',
                'recurrencia' => 'una_vez',
                'fecha'       => $this->manana(),
                'hora'        => '15:43',
            ]],
            ['texto', self::CASO_PROGRAMADA_CAJA],
        ]);

        $turno = $this->turno_del_dueno('Avisame a las 15:43 cómo viene la caja');

        $this->assertSame(1, $this->cantidad_de_tareas(), 'La tarea quedó hecha de verdad.');
        $this->assertStringContainsString('Quedó programada para hoy a las 15:43', (string) $turno->contenido);
        $this->assertStringNotContainsString('Todavía no', (string) $turno->contenido);
    }

    /**
     * 🔴 CA5. Con la herramienta OK en el turno, los cuatro textos reales NO disparan: ni reintento ni aviso.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function con_la_herramienta_ok_no_dispara()
    {
        $tarea = $this->tarea_del_asistente();

        $casos = [
            [self::CASO_CANCELE, ['tool', 'cancelar_tarea_del_asistente', ['tarea_id' => $tarea->id]]],
            [self::CASO_PROGRAMADA_PROVEEDOR, ['tool', 'programar_tarea_del_asistente', [
                'titulo' => 'Proveedor', 'instruccion' => 'Preguntá si ya llamó al proveedor', 'recurrencia' => 'una_vez', 'fecha' => $this->manana(), 'hora' => '15:45',
            ]]],
        ];

        foreach ($casos as $caso) {

            $this->modelo_con_guion([$caso[1], ['texto', $caso[0]]]);

            $turno = $this->turno_del_dueno('Hacelo');

            $this->assertSame($caso[0], (string) $turno->contenido);
            $this->assertStringNotContainsString('Todavía no', (string) $turno->contenido);
        }

        $this->assertSame('cancelada', $this->fila_de($tarea->id)->estado, 'La herramienta canceló de verdad.');
    }

    /* ─────────────────────────────── CA3: lo que NO dispara ─────────────────────────────── */

    /**
     * 🔴 CA3 (b) y (f). Preguntas, ofrecimientos y textos sobre pedidos o entregas: el texto sale tal cual, con una
     * sola llamada al modelo.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function preguntas_ofrecimientos_y_otros_objetos_no_disparan()
    {
        $textos = [
            '¿La cancelo?',
            'Si querés la programo para las 15:45 y te pregunto si llamaste al proveedor.',
            'Puedo programarla para mañana a primera hora, ¿te sirve?',
            'Cancelé el pedido 55 como pediste.',
            'Quedó programada la entrega para el viernes.',
            'Quedó cargada la venta de hoy.',
            'La reunión con el proveedor quedó para el viernes.',
        ];

        foreach ($textos as $texto) {

            $this->modelo_con_guion([['texto', $texto]]);

            $turno = $this->turno_del_dueno('Contame');

            $this->assertSame($texto, (string) $turno->contenido, 'No tiene que tocarse: ' . $texto);
        }
    }

    /**
     * 🔴 CA3 (c). Después de consultar_tareas_del_asistente OK, describir el estado que devolvió no dispara.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function describir_lo_que_devolvio_la_consulta_no_dispara()
    {
        $this->tarea_del_asistente();

        $textos = [
            'La de logística está programada para el viernes a las 17.',
            'La de ventas diarias ya está cancelada: no vuelve a correr.',
            'La de logística quedó programada para el viernes a las 17.',
        ];

        foreach ($textos as $texto) {

            $this->modelo_con_guion([['tool', 'consultar_tareas_del_asistente', []], ['texto', $texto]]);

            // Http::recorded() acumula entre las vueltas del foreach: se mide lo que sumó ESTE turno.
            $antes = $this->vueltas_al_modelo();

            $turno = $this->turno_del_dueno('¿Qué tenés programado?');

            $this->assertSame($texto, (string) $turno->contenido, 'Describe el estado de la consulta: ' . $texto);
            $this->assertSame(2, $this->vueltas_al_modelo() - $antes, 'Consulta y respuesta: sin reintento.');
        }
    }

    /**
     * 🔴 CA3 (d). Si las herramientas de tareas no se ofrecen (un empleado no programa mensajes al teléfono del
     * dueño), la guarda no corre: no hay nada que el modelo pudiera haber llamado.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function sin_las_herramientas_de_tareas_no_dispara()
    {
        $this->modelo_con_guion([['texto', self::CASO_PROGRAMADA_PROVEEDOR]]);

        $conversacion = AiConversation::create([
            'user_id'         => $this->comercio->id,
            'auth_user_id'    => $this->empleado->id,
            'origen'          => AiConversation::ORIGEN_WHATSAPP,
            'last_message_at' => now(),
        ]);

        $this->mensaje($conversacion, 'user', 'listo', ['contenido' => 'Avisame a las 15:45']);
        $assistant = $this->mensaje($conversacion, 'assistant', 'pendiente', ['acciones_habilitadas' => true]);

        (new ResponderMensajeChatIaJob($assistant->id))->handle();

        $this->assertSame(self::CASO_PROGRAMADA_PROVEEDOR, (string) $assistant->fresh()->contenido);
        $this->assertSame(1, $this->vueltas_al_modelo(), 'Sin herramientas de tareas no hay reintento.');
    }

    /**
     * 🔴 CA3 (d). El turno desatendido (lo despertó una tarea) tampoco: no se le ofrece nada que programe.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function el_turno_desatendido_no_dispara()
    {
        $tarea = $this->tarea_del_asistente();

        $turno = $this->ejecucion_en_curso($tarea, 'pendiente', null, 1);

        $this->modelo_con_guion([['texto', self::CASO_PROGRAMADA_CAJA]]);

        (new ResponderMensajeChatIaJob($turno['assistant']->id))->handle();

        $this->assertSame(1, $this->vueltas_al_modelo(), 'El turno desatendido no reintenta.');
        $this->assertStringNotContainsString('Todavía no', (string) $turno['assistant']->fresh()->contenido);
    }

    /**
     * 🔴 CA3 (e). Con una carga de la Agenda armada en el turno (proponer_tarea), «te lo agendé» es esa carga y no
     * una tarea del asistente: no dispara.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function con_un_recordatorio_de_la_agenda_armado_no_dispara()
    {
        $texto = 'Listo, quedó agendado para el viernes: te aviso a las 9 que llames a Juan.';

        $this->modelo_con_guion([
            ['tool', 'proponer_tarea', ['detalle' => 'Llamar a Juan', 'fecha' => '2026-11-27', 'hora' => '09:00']],
            ['texto', $texto],
        ]);

        $turno = $this->turno_del_dueno('Anotame llamar a Juan el viernes a las 9');

        $this->assertStringNotContainsString('Todavía no', (string) $turno->contenido);
        $this->assertFalse($this->hubo_nota_de_reintento(), 'Con la carga armada no hay reintento por esta guarda.');
    }

    /* ─────────────────────────────── el detector, frase por frase ─────────────────────────────── */

    /**
     * Frases que afirman la acción sobre una tarea del asistente: el tipo que tienen que detectar.
     *
     * @return array<int,array{0:string,1:string,2:string}>  [frase, tipo, lo que escribió la persona]
     */
    protected function positivas()
    {
        return [
            [self::CASO_CANCELE, 'cancelar', ''],
            [self::CASO_YA_ESTA_CANCELADA, 'cancelar', ''],
            [self::CASO_PROGRAMADA_PROVEEDOR, 'programar', ''],
            [self::CASO_PROGRAMADA_CAJA, 'programar', ''],
            ['Listo, la programé para el viernes: te escribo ese día a la tarde.', 'programar', ''],
            ['Te la agendé: te aviso el lunes a las 8 cómo vienen las ventas.', 'programar', ''],
            ['Programé una tarea para que mañana te pregunte por la logística.', 'programar', ''],
            ['Listo, la pasé al viernes.', 'reprogramar', 'Pasá la tarea de logística para el viernes'],
            ['Ajusté la tarea: ahora te escribe a las 18.', 'reprogramar', ''],
            ['Quedó para el viernes a las 17, la tarea de logística.', 'reprogramar', ''],
            ['Cancelé la tarea de ventas diarias.', 'cancelar', ''],
            ['Listo, lo cancelé.', 'cancelar', 'Cancelame la tarea de ventas'],
            ['Ya no te va a escribir más: no te va a escribir más sobre eso.', 'cancelar', ''],
        ];
    }

    /**
     * Frases que NO afirman nada sobre una tarea del asistente.
     *
     * @return array<int,array{0:string,1:string}>  [frase, lo que escribió la persona]
     */
    protected function negativas()
    {
        return [
            ['¿La cancelo?', ''],
            ['¿Querés que la programe para el viernes?', ''],
            ['Si querés la programo y te aviso a las 15:45.', ''],
            ['Puedo programarla para mañana.', ''],
            ['No la cancelé todavía: decime si seguimos.', 'Cancelame la tarea'],
            ['Cancelé el pedido 55.', ''],
            ['Cancelé la venta de ayer.', 'Cancelame la tarea de ventas'],
            ['Cancelé la factura A 0001-123.', ''],
            ['Quedó programada la entrega para el viernes.', ''],
            ['Quedó cargada la venta de hoy.', ''],
            ['El turno con el contador quedó para el viernes.', ''],
            ['La tarea de logística está programada para el viernes a las 17.', ''],
            ['La de hoy ya se ejecutó y te escribió a las 9.', ''],
            ['Tenés tres tareas programadas.', ''],
            ['Hoy no hay nada programado.', ''],
            ['Te mando el resumen cuando termine de cargar los datos.', ''],
        ];
    }

    /**
     * 🔴 El detector dispara en las frases que afirman la acción sobre una tarea del asistente.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function el_detector_dispara_en_las_frases_positivas()
    {
        foreach ($this->positivas() as $caso) {

            $tipos = TareaAfirmadaSinHerramientaIaHelper::que_afirma($caso[0], $caso[2], false);

            $this->assertContains($caso[1], $tipos, 'Tiene que detectar «' . $caso[1] . '» en: ' . $caso[0]);
        }
    }

    /**
     * 🔴 El detector NO dispara en preguntas, ofrecimientos, otros objetos ni descripciones de estado.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function el_detector_no_dispara_en_las_frases_negativas()
    {
        foreach ($this->negativas() as $caso) {

            $this->assertSame([], TareaAfirmadaSinHerramientaIaHelper::que_afirma($caso[0], $caso[1], true), 'No tiene que disparar (tras consultar) en: ' . $caso[0]);
        }

        foreach ($this->negativas() as $caso) {

            // Sin consulta, lo único que se tolera es lo que no es un estado afirmado.
            if (strpos($caso[0], 'está programada') !== false || strpos($caso[0], 'ya se ejecutó') !== false) {
                continue;
            }

            $this->assertSame([], TareaAfirmadaSinHerramientaIaHelper::que_afirma($caso[0], $caso[1], false), 'No tiene que disparar en: ' . $caso[0]);
        }
    }

    /**
     * 🔴 El respaldo de una herramienta OK: programar y reprogramar se respaldan entre sí; cancelar sólo con
     * cancelar; una carga `proponer_*` en el turno apaga la guarda.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function el_respaldo_de_las_herramientas()
    {
        $h = TareaAfirmadaSinHerramientaIaHelper::class;

        $this->assertSame(['programar'], $h::acciones_no_hechas(self::CASO_PROGRAMADA_CAJA, '', [], []));
        $this->assertSame([], $h::acciones_no_hechas(self::CASO_PROGRAMADA_CAJA, '', ['programar_tarea_del_asistente'], []));
        $this->assertSame([], $h::acciones_no_hechas(self::CASO_PROGRAMADA_CAJA, '', ['reprogramar_tarea_del_asistente'], []));
        $this->assertSame(['cancelar'], $h::acciones_no_hechas(self::CASO_CANCELE, '', ['programar_tarea_del_asistente'], []));
        $this->assertSame([], $h::acciones_no_hechas(self::CASO_CANCELE, '', ['cancelar_tarea_del_asistente'], []));
        $this->assertSame([], $h::acciones_no_hechas(self::CASO_PROGRAMADA_CAJA, '', [], ['proponer_tarea']));
    }

    /* ═══════════════ RONDA 2: la verdad es lo que dice la BASE, no lo que pasó en este turno ═══════════════ */

    /**
     * Lo que la guarda diría de la frase para el dueño del comercio, con la base REAL de la prueba y sin
     * herramientas en el turno: los tipos de afirmación que la base NO respalda.
     *
     * @param  string  $frase
     * @param  string  $pedido  Lo que escribió la persona.
     * @return array<int,string>
     */
    protected function no_hechas($frase, $pedido)
    {
        return TareaAfirmadaSinHerramientaIaHelper::acciones_no_hechas($frase, $pedido, [], [], $this->comercio->id);
    }

    /**
     * Una tarea ACTIVA del dueño con el título y la hora dados (diaria salvo que se pida otra cosa).
     *
     * @param  string  $titulo
     * @param  string  $hora  HH:MM.
     * @param  array  $mas  Columnas a pisar.
     * @return \App\Models\AiTareaDelAsistente
     */
    protected function tarea_a_las($titulo, $hora, array $mas = [])
    {
        return $this->tarea_del_asistente(array_merge([
            'titulo'               => $titulo,
            'recurrencia'          => 'diaria',
            'hora'                 => $hora,
            'proxima_ejecucion_at' => Carbon::now()->addDay()->format('Y-m-d') . ' ' . $hora . ':00',
        ], $mas));
    }

    /**
     * Una tarea CANCELADA hace un rato.
     *
     * @param  string  $titulo
     * @return \App\Models\AiTareaDelAsistente
     */
    protected function tarea_cancelada($titulo)
    {
        return $this->tarea_a_las($titulo, '08:00', ['estado' => 'cancelada']);
    }

    /**
     * 🔴 HALLAZGO 1. Una confirmación VERDADERA de algo hecho en un turno anterior no dispara: la tarea que la
     * hace cierta está en la base. La misma frase SIN esa tarea (hay otra, de otra hora y otro tema) sí dispara.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function una_confirmacion_verdadera_de_un_turno_anterior_no_dispara()
    {
        // [frase, pedido, cómo se arma la tarea que la hace verdadera, tipo]
        $casos = [
            ['De nada. Te escribo mañana a las 8 con el resumen.', 'Gracias por la tarea del resumen', 'a_las_8', 'programar'],
            ['Sí, quedó programada para mañana a las 8.', '¿Quedó programada la tarea del resumen?', 'a_las_8', 'programar'],
            ['¡De nada! Quedó para el viernes a las 9.', 'Gracias por pasar la tarea al viernes', 'a_las_9', 'reprogramar'],
            ['Tenés una: te aviso a las 9 todos los lunes con los cheques.', 'Qué tareas tengo', 'a_las_9', 'programar'],
            ['Sí, la cancelé hace un rato.', '¿Cancelaste la tarea de ventas diarias?', 'cancelada_ventas', 'cancelar'],
            ['Sí, ya está cancelada.', '¿Ya cancelaste la tarea de cobros semanales?', 'cancelada_cobros', 'cancelar'],
        ];

        foreach ($casos as $caso) {

            DB::table('ai_tareas_del_asistente')->where('owner_id', $this->comercio->id)->delete();

            // SIN la tarea que la hace verdadera: hay otra, de otra hora y otro tema.
            $this->tarea_a_las('Preguntas de logística', '17:00');

            $this->assertSame([$caso[3]], $this->no_hechas($caso[0], $caso[1]), 'Sin la tarea tiene que disparar: ' . $caso[0]);

            // CON la tarea que la hace verdadera.
            if ($caso[2] === 'a_las_8') {
                $this->tarea_a_las('Resumen del día', '08:00');
            } elseif ($caso[2] === 'a_las_9') {
                $this->tarea_a_las('Cheques de los lunes', '09:00');
            } elseif ($caso[2] === 'cancelada_ventas') {
                $this->tarea_cancelada('Ventas diarias');
            } else {
                $this->tarea_cancelada('Cobros semanales');
            }

            $this->assertSame([], $this->no_hechas($caso[0], $caso[1]), 'Con la tarea en la base NO tiene que disparar: ' . $caso[0]);
        }
    }

    /**
     * 🔴 HALLAZGO 1 (segunda lista). Ofertas, ejemplos y condicionales NUNCA disparan, aunque la base no tenga nada
     * ni nada de esa hora, y aunque vayan en la misma oración separados por coma o dos puntos.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function las_ofertas_los_ejemplos_y_los_condicionales_nunca_disparan()
    {
        $frases = [
            'Te aviso a las 15 si querés.',
            'Te escribo a las 9 si te parece bien.',
            'Por ejemplo, te escribo a las 8 con el resumen del día.',
            'Cuando la programe, te aviso a las 9 todos los días.',
            'Te aviso a las 15 si querés: decime y la programo.',
        ];

        foreach ([false, true] as $con_otra_tarea) {

            DB::table('ai_tareas_del_asistente')->where('owner_id', $this->comercio->id)->delete();

            if ($con_otra_tarea) {
                $this->tarea_a_las('Preguntas de logística', '17:00');
            }

            foreach ($frases as $frase) {
                $this->assertSame([], $this->no_hechas($frase, 'Qué podés hacer con las tareas'), 'Una oferta no dispara: ' . $frase);
            }
        }
    }

    /**
     * 🔴 HALLAZGO 1 (nota). Las frases que antes se escapaban y que con la verdad de la base ya no son riesgosas:
     * sin la tarea disparan; con la tarea de esa hora, no.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function las_promesas_con_hora_se_contrastan_con_la_base()
    {
        $casos = [
            ['Listo, te escribo el viernes a las 9.', '09:00', 'programar'],
            ['Dale, mañana a las 8 te mando el resumen.', '08:00', 'programar'],
            ['Listo, a las 20 te escribo con las ventas.', '20:00', 'programar'],
            ['Quedó programado: a las 20:00 te paso las ventas del día.', '20:00', 'programar'],
            ['Programé el resumen de ventas para las 20:00.', '20:00', 'programar'],
            ['Vuelve el viernes 27/11 a las 09:00.', '09:00', 'reprogramar'],
        ];

        foreach ($casos as $caso) {

            DB::table('ai_tareas_del_asistente')->where('owner_id', $this->comercio->id)->delete();

            // Sin nada en la base y con una tarea de OTRA hora: dispara.
            $this->assertSame([$caso[2]], $this->no_hechas($caso[0], 'Programame un aviso, la tarea del resumen'), 'Sin tarea: ' . $caso[0]);

            $this->tarea_a_las('Ventas del día a primera hora', $caso[1] === '08:00' ? '10:00' : '08:00');

            $this->assertSame([$caso[2]], $this->no_hechas($caso[0], 'Programame un aviso, la tarea del resumen'), 'Con una tarea de otra hora: ' . $caso[0]);

            $this->tarea_a_las('Resumen', $caso[1]);

            $this->assertSame([], $this->no_hechas($caso[0], 'Programame un aviso, la tarea del resumen'), 'Con una tarea a esa hora: ' . $caso[0]);
        }
    }

    /**
     * 🔴 HALLAZGO 2a. Cancelar una tarea cuyo TEMA es «ventas»: dispara con la tarea «Ventas del día a primera hora»
     * activa y NO dispara con esa tarea ya cancelada (la mentira no deja nada vivo).
     *
     * @group asistente-whatsapp
     * @test
     */
    public function cancelar_la_tarea_de_ventas_dispara_con_la_tarea_activa_y_no_con_la_cancelada()
    {
        $frases = [
            'La de ventas diarias la cancelé.',
            'Listo, cancelé el resumen de ventas diario.',
            'Listo, las ventas diarias quedaron canceladas.',
            'Lo de las ventas quedó cancelado.',
        ];

        $pedido = 'Cancelame la tarea de ventas diarias';

        foreach ($frases as $frase) {

            DB::table('ai_tareas_del_asistente')->where('owner_id', $this->comercio->id)->delete();

            $this->tarea_a_las('Ventas del día a primera hora', '08:00');

            $this->assertSame(['cancelar'], $this->no_hechas($frase, $pedido), 'Con la tarea activa tiene que disparar: ' . $frase);

            DB::table('ai_tareas_del_asistente')->where('owner_id', $this->comercio->id)->update(['estado' => 'cancelada']);

            $this->assertSame([], $this->no_hechas($frase, $pedido), 'Con la tarea ya cancelada NO tiene que disparar: ' . $frase);
        }
    }

    /**
     * 🔴 HALLAZGO 2a. «Lo de los cobros lo pasé al lunes.» con una tarea de cobros que NO corre el lunes dispara;
     * si la tarea ya corre el lunes, es verdad.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function pasar_lo_de_los_cobros_al_lunes_se_contrasta_con_el_dia_de_la_tarea()
    {
        $frase = 'Lo de los cobros lo pasé al lunes.';
        $pedido = 'Pasá la tarea de cobros al lunes';

        $this->tarea_a_las('Cobros de la semana', '09:00', [
            'recurrencia'          => 'una_vez',
            'proxima_ejecucion_at' => Carbon::now()->next(Carbon::TUESDAY)->format('Y-m-d') . ' 09:00:00',
        ]);

        $this->assertSame(['reprogramar'], $this->no_hechas($frase, $pedido), 'La tarea corre el martes: tiene que disparar.');

        DB::table('ai_tareas_del_asistente')->where('owner_id', $this->comercio->id)
            ->update(['proxima_ejecucion_at' => Carbon::now()->next(Carbon::MONDAY)->format('Y-m-d') . ' 09:00:00']);

        $this->assertSame([], $this->no_hechas($frase, $pedido), 'La tarea ya corre el lunes: es verdad.');
    }

    /**
     * 🔴 HALLAZGO 2a. «Cancelé el pedido 123» y «La venta quedó cancelada» son registros concretos: con tareas
     * activas de otro tema NO disparan. Pero si la persona venía hablando de la TAREA de ventas, gana la tarea.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function un_registro_concreto_no_dispara_pero_gana_la_tarea_si_la_persona_hablaba_de_ella()
    {
        $this->tarea_a_las('Preguntas de logística', '17:00');

        $this->assertSame([], $this->no_hechas('Cancelé el pedido 123.', 'Cancelame el pedido 123'));
        $this->assertSame([], $this->no_hechas('La venta quedó cancelada.', 'Cancelame la venta de ayer'));

        DB::table('ai_tareas_del_asistente')->where('owner_id', $this->comercio->id)->delete();
        $this->tarea_a_las('Ventas del día a primera hora', '08:00');

        $this->assertSame([], $this->no_hechas('Cancelé el pedido 123.', 'Cancelame el pedido 123'));
        $this->assertSame(['cancelar'], $this->no_hechas('Cancelé la venta de ayer.', 'Cancelame la tarea de ventas'), 'Si hablaban de la tarea de ventas, gana la tarea.');
    }

    /**
     * 🔴 Un dueño SIN ninguna tarea no ve nunca el aviso por una frase de cancelación: no hay nada que la mentira
     * deje vivo. Ni en el detector ni en el turno completo (una sola llamada al modelo, texto intacto).
     *
     * @group asistente-whatsapp
     * @test
     */
    public function sin_ninguna_tarea_una_cancelacion_no_dispara()
    {
        foreach ([self::CASO_CANCELE, self::CASO_YA_ESTA_CANCELADA, 'La de ventas diarias la cancelé.', 'Sí, ya está cancelada.'] as $frase) {
            $this->assertSame([], $this->no_hechas($frase, 'Cancelame la tarea de ventas diarias'), $frase);
        }

        $this->modelo_con_guion([['texto', self::CASO_CANCELE]]);

        $turno = $this->turno_del_dueno('Cancelame la tarea de ventas diarias');

        $this->assertSame(self::CASO_CANCELE, (string) $turno->contenido);
        $this->assertSame(1, $this->vueltas_al_modelo());
    }

    /**
     * 🔴 En un turno posterior, la confirmación verdadera pasa entera: sin reintento y sin aviso.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function en_el_turno_posterior_la_confirmacion_verdadera_pasa_sin_reintento()
    {
        $this->tarea_a_las('Resumen del día', '08:00');

        $texto = 'De nada. Te escribo mañana a las 8 con el resumen.';

        $this->modelo_con_guion([['texto', $texto]]);

        $turno = $this->turno_del_dueno('Gracias por la tarea del resumen');

        $this->assertSame($texto, (string) $turno->contenido);
        $this->assertSame(1, $this->vueltas_al_modelo(), 'Sin reintento.');
    }

    /**
     * 🔴 HALLAZGO 2b. La consulta OK en el turno ya no es un salvoconducto: los textos reales que la base desmiente
     * disparan igual (reintento y aviso honesto).
     *
     * @group asistente-whatsapp
     * @test
     */
    public function con_la_consulta_ok_los_textos_reales_que_la_base_desmiente_disparan()
    {
        $this->tarea_a_las('Ventas diarias', '08:00');

        $casos = [
            [self::CASO_YA_ESTA_CANCELADA, 'Sacá la tarea de ventas diarias', self::AVISO_CANCELAR],
            [self::CASO_PROGRAMADA_PROVEEDOR, 'Avisame hoy a las 15:45 si llamé al proveedor', self::AVISO_PROGRAMAR],
            [self::CASO_PROGRAMADA_CAJA, 'Avisame a las 15:43 cómo viene la caja', self::AVISO_PROGRAMAR],
        ];

        foreach ($casos as $caso) {

            $this->modelo_con_guion([['tool', 'consultar_tareas_del_asistente', []], ['texto', $caso[0]]]);

            $antes = $this->vueltas_al_modelo();

            $turno = $this->turno_del_dueno($caso[1]);

            $this->assertStringContainsString($caso[2], (string) $turno->contenido, 'Tiene que avisar la verdad: ' . $caso[0]);
            $this->assertSame(3, $this->vueltas_al_modelo() - $antes, 'Consulta, texto y UN reintento.');
        }
    }

    /**
     * 🔴 La nota del reintento no empuja a duplicar: dice que en la base no figura, manda a consultar si no sabe el
     * id y admite que no lo hizo.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function la_nota_del_reintento_no_empuja_a_duplicar()
    {
        $nota = TareaAfirmadaSinHerramientaIaHelper::nota_para_reintentar();

        $this->assertStringNotContainsString('AHORA', $nota);
        $this->assertStringContainsString('consultar_tareas_del_asistente', $nota);
        $this->assertStringContainsString('no figura', $nota);
    }
}
