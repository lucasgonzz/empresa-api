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
}
