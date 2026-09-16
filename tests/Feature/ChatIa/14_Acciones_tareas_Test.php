<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\agenda\AgendaCompletarHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaTareaIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Expense;
use App\Models\ExtencionEmpresa;
use App\Models\MovimientoCaja;
use App\Models\Pending;
use App\Models\PendingCompleted;
use App\Models\UnidadFrecuencia;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Agenda\AgendaTestCase;

/**
 * Misión asistente-ia-acciones — las tres cargas de la Agenda que propone el asistente: una tarea
 * nueva, cambios en una tarea y marcar una ocurrencia como hecha.
 *
 * Extiende AgendaTestCase para usar los mismos helpers que la suite de la Agenda (crear tareas por
 * el endpoint real, el bloque de gasto en efectivo con el payload de la pantalla, y la limpieza de
 * tareas, realizadas y movimientos de caja).
 *
 * Lo que protege: que la tarjeta valide con las reglas y los mensajes de la pantalla
 * (AgendaTareaHelper), que una edición reancle la regla igual que `PUT api/pending`, que una tarea
 * editada entre la propuesta y el clic corte con 422, y que marcar hecha pase por
 * AgendaCompletarHelper (candado, transacción y gasto) con su monto estimado o sin gasto.
 *
 * @group chat-ia
 */
class Acciones_tareas_Test extends AgendaTestCase
{
    /** Delta para comparar montos. */
    const DELTA = 0.01;

    /** @var User */
    protected $dueno;

    /** @var AsistenteIaService */
    protected $service;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $extencion = ExtencionEmpresa::where('slug', 'asistente_ia')->first();

        if (is_null($extencion)) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => 'asistente_ia', 'name' => 'Asistente IA']);
        }

        $this->dueno->extencions()->syncWithoutDetaching([$extencion->id]);

        $this->service = new AsistenteIaService();
    }

    /**
     * @param User|null $persona
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function conversacion($persona = null)
    {
        $persona = is_null($persona) ? $this->dueno : $persona;

        $conversation = AiConversation::create([
            'user_id'      => $this->dueno->id,
            'auth_user_id' => $persona->id,
        ]);

        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => 'Agendame algo',
        ]);

        $assistant = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => true,
        ]);

        return [$conversation, $assistant];
    }

    /**
     * @param AiConversation $conversation
     * @param AiMessage $assistant
     * @param string $herramienta
     * @param array $input
     * @return array
     */
    protected function herramienta($conversation, $assistant, $herramienta, array $input)
    {
        $resultados = $this->service->execute_tool_calls([[
            'type'  => 'tool_use',
            'id'    => 'toolu_' . uniqid(),
            'name'  => $herramienta,
            'input' => $input,
        ]], $conversation, $assistant);

        $this->assertArrayNotHasKey('is_error', $resultados[0], 'La herramienta devolvió una falla técnica: ' . $resultados[0]['content']);

        return json_decode($resultados[0]['content'], true);
    }

    /**
     * Deja el assistant en listo y confirma la tarjeta.
     *
     * @param AiConversation $conversation
     * @param AiMessage $assistant
     * @param int $tarjeta_id
     * @return \Illuminate\Testing\TestResponse
     */
    protected function confirmar($conversation, $assistant, $tarjeta_id)
    {
        $assistant->contenido = 'Te dejé la tarjeta para confirmar.';
        $assistant->estado = 'listo';
        $assistant->save();

        $antes = $this->max_id_movimiento_caja();

        $response = $this->postJson('api/ai-conversations/' . $conversation->id . '/acciones/' . $tarjeta_id . '/confirmar');

        $this->registrar_movimientos_caja_nuevos($antes);

        return $response;
    }

    /**
     * Renglón de la presentación por etiqueta.
     *
     * @param array $presentacion
     * @param string $etiqueta
     * @return string|null
     */
    protected function renglon(array $presentacion, $etiqueta)
    {
        foreach ($presentacion['renglones'] as $renglon) {
            if ($renglon['etiqueta'] === $etiqueta) {
                return $renglon['valor'];
            }
        }

        return null;
    }

    /**
     * @test
     */
    public function proponer_y_confirmar_una_tarea_puntual()
    {
        list($conversation, $assistant) = $this->conversacion();

        $pasado_manana = Carbon::today()->addDays(2);

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_tarea', [
            'detalle' => 'Llamar al contador P14',
            'fecha'   => $pasado_manana->format('Y-m-d'),
            'notas'   => 'Por el IVA',
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertEquals('tarea_nueva', $respuesta['tipo']);

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        // La clave lleva detalle y fecha para que dos tareas distintas del mismo turno no se pisen.
        $this->assertEquals('tarea_nueva:llamar al contador p14:' . $pasado_manana->format('Y-m-d'), $tarjeta->clave);
        $this->assertEquals('Tarea en la agenda', $tarjeta->presentacion['titulo']);
        $this->assertEquals('Llamar al contador P14', $this->renglon($tarjeta->presentacion, 'Qué'));
        $this->assertStringContainsString($pasado_manana->format('d/m/Y'), $this->renglon($tarjeta->presentacion, 'Cuándo'));
        $this->assertEquals('Por el IVA', $this->renglon($tarjeta->presentacion, 'Notas'));

        $confirmar = $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id']);

        $confirmar->assertStatus(200);
        $this->assertEquals('confirmada', $confirmar->json('model.estado'));
        $this->assertEquals('pending', $confirmar->json('model.resultado.ruta.name'));
        $this->assertEquals('Ver en la Agenda', $confirmar->json('model.resultado.ruta.texto'));
        $this->assertStringContainsString($pasado_manana->format('d/m/Y'), $confirmar->json('model.resultado.texto'));

        $tarea = Pending::where('user_id', $this->dueno->id)->where('detalle', 'Llamar al contador P14')->first();

        $this->assertNotNull($tarea);
        $this->tareas_creadas[] = $tarea->id;

        $this->assertEquals($pasado_manana->format('Y-m-d'), Carbon::parse($tarea->fecha_realizacion)->format('Y-m-d'));
        $this->assertFalse((bool) $tarea->completado);
        $this->assertFalse((bool) $tarea->es_recurrente);
        $this->assertEquals('Por el IVA', $tarea->notas);
    }

    /**
     * Un cliente sin `unidad_frecuencias` sembradas no puede tener una tarea que se repite: el
     * asistente lo dice en vez de guardar una regla rota.
     *
     * @test
     */
    public function una_tarea_que_se_repite_sin_unidades_cargadas_da_un_error_explicito()
    {
        /*
         * El caso es el de un cliente viejo sin `unidad_frecuencias` sembradas, así que la tabla se
         * vacía acá adentro: la transacción del test la repone al terminar. Antes esto era un
         * markTestSkipped() mirando si la tabla estaba vacía, y el día que la base del slot las tuvo
         * sembradas el test dejó de correr sin que nada fallara (el 15/9/2026 pasó justamente eso).
         */
        UnidadFrecuencia::query()->delete();

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_tarea', [
            'detalle' => 'Pagar el alquiler P14',
            'fecha'   => Carbon::today()->format('Y-m-d'),
            'repetir' => ['cada' => 1, 'unidad' => 'mes'],
        ]);

        $this->assertFalse($respuesta['ok']);
        $this->assertStringContainsString('unidades de repetición', $respuesta['error']);
        $this->assertEquals(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
    }

    /**
     * @test
     */
    public function proponer_y_confirmar_una_tarea_que_se_repite()
    {
        $mes = $this->unidad('month');

        list($conversation, $assistant) = $this->conversacion();

        $desde = Carbon::today()->addDays(1);
        $hasta = Carbon::today()->addMonths(6);

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_tarea', [
            'detalle' => 'Pagar el alquiler P14',
            'fecha'   => $desde->format('Y-m-d'),
            'repetir' => ['cada' => 1, 'unidad' => 'mes', 'hasta' => $hasta->format('Y-m-d')],
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $cuando = $this->renglon($tarjeta->presentacion, 'Cuándo');

        $this->assertStringContainsString('se repite cada 1 mes', $cuando);
        $this->assertStringContainsString('hasta ' . $hasta->format('d/m/Y'), $cuando);

        $confirmar = $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id']);

        $confirmar->assertStatus(200);

        $tarea = Pending::where('user_id', $this->dueno->id)->where('detalle', 'Pagar el alquiler P14')->first();

        $this->assertNotNull($tarea);
        $this->tareas_creadas[] = $tarea->id;

        $this->assertTrue((bool) $tarea->es_recurrente);
        $this->assertEquals($mes->id, (int) $tarea->unidad_frecuencia_id);
        $this->assertEquals(1, (int) $tarea->cantidad_frecuencia);
        $this->assertEquals($hasta->format('Y-m-d'), Carbon::parse($tarea->fecha_fin_recurrencia)->format('Y-m-d'));
    }

    /**
     * Los cambios se muestran "antes → después" y, al confirmar, una regla nueva con la primera
     * fecha en el pasado se reancla a la próxima ocurrencia (igual que `PUT api/pending`).
     *
     * @test
     */
    public function proponer_cambios_muestra_antes_y_despues_y_al_confirmar_reancla_la_regla()
    {
        $this->unidad('month');

        $base = Carbon::today()->subMonths(3);

        $tarea = $this->crear_tarea_recurrente('month', 1, $base->format('Y-m-d'), ['detalle' => 'Revisar stock P14']);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_cambios_en_tarea', [
            'tarea_id' => $tarea->id,
            'repetir'  => ['cada' => 2, 'unidad' => 'mes'],
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertEquals('tarea_editar', $respuesta['tipo']);

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertEquals('tarea:' . $tarea->id, $tarjeta->clave);
        $this->assertEquals('Cambios en la tarea', $tarjeta->presentacion['titulo']);
        $this->assertEquals('Revisar stock P14', $this->renglon($tarjeta->presentacion, 'Tarea'), 'Si el detalle no cambia, la tarjeta igual dice de qué tarea se trata.');
        $this->assertEquals('cada 1 mes → cada 2 meses', $this->renglon($tarjeta->presentacion, 'Repetición'));
        $this->assertEquals(PropuestaTareaIaHelper::AVISO_CAMBIO_DE_REGLA, $tarjeta->presentacion['aviso']);

        $confirmar = $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id']);

        $confirmar->assertStatus(200);
        $this->assertEquals('Tarea actualizada', $confirmar->json('model.resultado.texto'));

        $tarea->refresh();

        $this->assertEquals(2, (int) $tarea->cantidad_frecuencia);
        $this->assertTrue(
            Carbon::parse($tarea->fecha_realizacion)->gte(Carbon::today()),
            'La regla nueva se reancla a la primera ocurrencia desde hoy: lo ya hecho queda en Realizadas.'
        );
        $this->assertEquals($base->day, Carbon::parse($tarea->fecha_realizacion)->day, 'El día del mes se conserva.');
    }

    /**
     * 🔴 Si alguien edita la tarea entre la propuesta y el clic, confirmar no pisa ese cambio: 422 y
     * el motivo queda en la tarjeta.
     *
     * @test
     */
    public function una_tarea_editada_entre_la_propuesta_y_el_clic_da_422()
    {
        $tarea = $this->crear_tarea(['detalle' => 'Comprar cinta P14', 'fecha_realizacion' => Carbon::today()->format('Y-m-d')]);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_cambios_en_tarea', [
            'tarea_id' => $tarea->id,
            'detalle'  => 'Comprar cinta y pegamento P14',
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        // El reloj avanza un minuto para que el updated_at de la edición sea distinto.
        $this->fijar_reloj_en(Carbon::now()->addMinute());

        $this->putJson('api/pending/' . $tarea->id, [
            'detalle'           => 'Comprar cinta (editada a mano) P14',
            'fecha_realizacion' => Carbon::today()->format('Y-m-d'),
            'es_recurrente'     => false,
        ])->assertStatus(200);

        $confirmar = $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id']);

        $confirmar->assertStatus(422);
        $this->assertEquals(PropuestaTareaIaHelper::MENSAJE_TAREA_CAMBIADA, $confirmar->json('model.error_mensaje'));
        $this->assertEquals('propuesta', $confirmar->json('model.estado'));
        $this->assertEquals('Comprar cinta (editada a mano) P14', $tarea->fresh()->detalle, 'La edición a mano no se pisó.');
    }

    /**
     * @test
     */
    public function marcar_hecha_sin_gasto_completa_la_tarea()
    {
        $tarea = $this->crear_tarea(['detalle' => 'Barrer el deposito P14', 'fecha_realizacion' => Carbon::today()->format('Y-m-d')]);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_marcar_tarea_hecha', [
            'tarea_id' => $tarea->id,
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertEquals('tarea_completar', $respuesta['tipo']);

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertEquals('tarea:' . $tarea->id . ':' . Carbon::today()->format('Y-m-d'), $tarjeta->clave);
        $this->assertEquals('Marcar como hecha', $tarjeta->presentacion['titulo']);
        $this->assertEquals('Barrer el deposito P14', $this->renglon($tarjeta->presentacion, 'Tarea'));
        $this->assertNull($tarjeta->presentacion['aviso'], 'Sin gasto asociado no hay nada que avisar.');

        $confirmar = $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id']);

        $confirmar->assertStatus(200);
        $this->assertEquals('Tarea marcada como hecha', $confirmar->json('model.resultado.texto'));

        $realizada = PendingCompleted::where('pending_id', $tarea->id)->first();

        $this->assertNotNull($realizada);
        $this->assertNull($realizada->expense_id);
        $this->assertTrue((bool) $tarea->fresh()->completado);
    }

    /**
     * 🔴 Marcar hecha una tarea SIN subcategoría, mandando el bloque `gasto`: antes el bloque se
     * descartaba en silencio (la tarjeta salía sin gasto, la IA no se enteraba y al confirmar no
     * había ningún gasto). Tiene que cortar con el motivo y sin tarjeta.
     *
     * @test
     */
    public function marcar_hecha_con_gasto_sobre_una_tarea_sin_subcategoria_da_error()
    {
        $caja = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_EFECTIVO);
        $this->asegurar_caja_abierta($caja);
        $metodo = $this->resolver_metodo_pago_por_nombre(TestingFerreteriaSeeder::PAGO_EFECTIVO);

        $tarea = $this->crear_tarea(['detalle' => 'Barrer sin subcategoria P14', 'fecha_realizacion' => Carbon::today()->format('Y-m-d')]);

        $this->assertNull($tarea->expense_concept_id, 'La tarea de este test no tiene gasto asociado.');

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_marcar_tarea_hecha', [
            'tarea_id' => $tarea->id,
            'gasto'    => [
                'monto' => 2500,
                'pagos' => [['metodo_de_pago_id' => $metodo->id, 'caja_id' => $caja->id]],
            ],
        ]);

        $this->assertFalse($respuesta['ok']);
        $this->assertStringContainsString('no tiene gasto asociado', $respuesta['error']);
        $this->assertStringContainsString('Barrer sin subcategoria P14', $respuesta['error']);
        $this->assertStringContainsString('subcategoría de gasto', $respuesta['error']);

        $this->assertEquals(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count(), 'No se arma ninguna tarjeta.');
        $this->assertEquals(0, PendingCompleted::where('pending_id', $tarea->id)->count());
        $this->assertFalse((bool) $tarea->fresh()->completado);
    }

    /**
     * @test
     */
    public function marcar_hecha_con_gasto_usa_el_monto_estimado_y_registra_el_gasto()
    {
        $caja = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_EFECTIVO);
        $this->asegurar_caja_abierta($caja);
        $metodo = $this->resolver_metodo_pago_por_nombre(TestingFerreteriaSeeder::PAGO_EFECTIVO);

        $tarea = $this->crear_tarea_con_gasto(5000, ['fecha_realizacion' => Carbon::today()->format('Y-m-d')]);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_marcar_tarea_hecha', [
            'tarea_id' => $tarea->id,
            'gasto'    => [
                'pagos' => [['metodo_de_pago_id' => $metodo->id, 'caja_id' => $caja->id]],
            ],
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertEquals(PropuestaTareaIaHelper::AVISO_MONTO_ESTIMADO, $tarjeta->presentacion['aviso']);
        $this->assertStringContainsString('$ 5.000', $this->renglon($tarjeta->presentacion, 'Gasto'));
        $this->assertEquals('Efectivo · $ 5.000 → Caja Efectivo', $this->renglon($tarjeta->presentacion, 'Pago'));
        $this->assertEqualsWithDelta(5000, (float) $tarjeta->datos['expense']['amount'], self::DELTA);

        $confirmar = $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id']);

        $confirmar->assertStatus(200);

        $realizada = PendingCompleted::where('pending_id', $tarea->id)->first();

        $this->assertNotNull($realizada->expense_id);

        $gasto = Expense::find($realizada->expense_id);

        $this->gastos_creados_por_escenarios[] = $gasto->id;

        $this->assertEqualsWithDelta(5000, (float) $gasto->amount, self::DELTA);
        $this->assertEquals('Agenda: Pagar el alquiler', $gasto->observations);
        $this->assertEquals('Tarea marcada como hecha y Gasto N° ' . $gasto->num . ' registrado', $confirmar->json('model.resultado.texto'));

        $movimientos = MovimientoCaja::where('expense_id', $gasto->id)->get();

        $this->assertCount(1, $movimientos);
        $this->assertEqualsWithDelta(5000, (float) $movimientos[0]->egreso, self::DELTA);
    }

    /**
     * @test
     */
    public function marcar_hecha_con_sin_gasto_no_registra_el_gasto()
    {
        $tarea = $this->crear_tarea_con_gasto(5000, ['fecha_realizacion' => Carbon::today()->format('Y-m-d')]);

        $gastos_antes = Expense::where('user_id', $this->dueno->id)->count();

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_marcar_tarea_hecha', [
            'tarea_id'  => $tarea->id,
            'sin_gasto' => true,
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertEquals(PropuestaTareaIaHelper::AVISO_SIN_GASTO, $tarjeta->presentacion['aviso']);
        $this->assertNull($this->renglon($tarjeta->presentacion, 'Gasto'));

        $confirmar = $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id']);

        $confirmar->assertStatus(200);
        $this->assertEquals('Tarea marcada como hecha', $confirmar->json('model.resultado.texto'));

        $this->assertNull(PendingCompleted::where('pending_id', $tarea->id)->first()->expense_id);
        $this->assertEquals($gastos_antes, Expense::where('user_id', $this->dueno->id)->count());
    }

    /**
     * @test
     */
    public function una_ocurrencia_ya_hecha_da_error_al_proponer_y_422_al_confirmar()
    {
        $this->unidad('week');

        $tarea = $this->crear_tarea_recurrente('week', 1, Carbon::today()->format('Y-m-d'), ['detalle' => 'Limpiar vidriera P14']);

        list($conversation, $assistant) = $this->conversacion();

        $primera = $this->herramienta($conversation, $assistant, 'proponer_marcar_tarea_hecha', [
            'tarea_id' => $tarea->id,
            'fecha'    => Carbon::today()->format('Y-m-d'),
        ]);

        $this->assertTrue($primera['ok'], json_encode($primera));

        // Alguien la marca desde la pantalla.
        $this->marcar_hecha($tarea, Carbon::today()->format('Y-m-d'))->assertStatus(201);

        $segunda = $this->herramienta($conversation, $assistant, 'proponer_marcar_tarea_hecha', [
            'tarea_id' => $tarea->id,
            'fecha'    => Carbon::today()->format('Y-m-d'),
        ]);

        $this->assertFalse($segunda['ok']);
        $this->assertEquals(AgendaCompletarHelper::MENSAJE_YA_COMPLETADA, $segunda['error']);

        // Y la tarjeta que ya estaba propuesta tampoco la puede marcar de nuevo.
        $confirmar = $this->confirmar($conversation, $assistant, $primera['tarjeta_id']);

        $confirmar->assertStatus(422);
        $this->assertEquals(AgendaCompletarHelper::MENSAJE_YA_COMPLETADA, $confirmar->json('model.error_mensaje'));
        $this->assertEquals(1, PendingCompleted::where('pending_id', $tarea->id)->count());
    }

    /**
     * @test
     */
    public function una_fecha_que_no_es_ocurrencia_de_la_tarea_da_error()
    {
        $this->unidad('month');

        $tarea = $this->crear_tarea_recurrente('month', 1, Carbon::today()->format('Y-m-d'), ['detalle' => 'Pagar la luz P14']);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_marcar_tarea_hecha', [
            'tarea_id' => $tarea->id,
            'fecha'    => Carbon::today()->addDays(3)->format('Y-m-d'),
        ]);

        $this->assertFalse($respuesta['ok']);
        $this->assertEquals(AgendaCompletarHelper::MENSAJE_FECHA_INVALIDA, $respuesta['error']);
        $this->assertEquals(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
    }

    /**
     * Una tarea que se repite tiene muchas fechas posibles: el asistente pregunta cuál, con las
     * vencidas y las próximas.
     *
     * @test
     */
    public function una_tarea_que_se_repite_sin_fecha_pregunta_cual_se_hizo()
    {
        $this->unidad('week');

        $tarea = $this->crear_tarea_recurrente('week', 1, Carbon::today()->subWeeks(2)->format('Y-m-d'), ['detalle' => 'Control de stock P14']);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_marcar_tarea_hecha', [
            'tarea_id' => $tarea->id,
        ]);

        $this->assertFalse($respuesta['ok']);
        $this->assertEquals(['qué fecha de la tarea se hizo'], $respuesta['faltan']);
        $this->assertNotEmpty($respuesta['opciones']['fechas']);
        $this->assertArrayHasKey('dia', $respuesta['opciones']['fechas'][0], 'Las fechas se ofrecen con el día de la semana.');

        // Y consultar_tareas la encuentra por su texto, con sus vencidas.
        $tareas = $this->herramienta($conversation, $assistant, 'consultar_tareas', ['busqueda' => 'Control de stock P14']);

        $this->assertNotEmpty($tareas['vencidas']);
        $this->assertEquals($tarea->id, $tareas['vencidas'][0]['tarea_id']);
        $this->assertEquals('cada 1 semana', $tareas['vencidas'][0]['se_repite']);
    }

    /**
     * @test
     */
    public function una_tarea_de_otro_dueno_no_se_encuentra()
    {
        $otro_dueno = User::create([
            'name'     => 'Comercio ajeno P14',
            'email'    => 'acciones-p14-ajeno-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $ajena = Pending::create([
            'detalle'           => 'Tarea ajena P14',
            'fecha_realizacion' => Carbon::today()->format('Y-m-d 00:00:00'),
            'es_recurrente'     => 0,
            'completado'        => 0,
            'user_id'           => $otro_dueno->id,
        ]);

        list($conversation, $assistant) = $this->conversacion();

        foreach (['proponer_cambios_en_tarea', 'proponer_marcar_tarea_hecha'] as $herramienta) {
            $respuesta = $this->herramienta($conversation, $assistant, $herramienta, ['tarea_id' => $ajena->id]);

            $this->assertFalse($respuesta['ok']);
            $this->assertEquals('No encontré esa tarea en tu agenda.', $respuesta['error']);
        }

        $this->assertEquals(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());

        Pending::where('id', $ajena->id)->delete();
    }

    /**
     * 🔴 "Agendame llamar al contador el jueves y pagar el alquiler el viernes" son dos
     * proponer_tarea en el MISMO mensaje. Con la clave literal `tarea_nueva`, la segunda reemplazaba
     * a la primera: quedaba una sola tarjeta y un "Reemplazada por una versión corregida" sobre algo
     * que nadie corrigió, y la tarea que la persona pidió se perdía. Las dos tienen que quedar
     * confirmables, y una corrección del MISMO pedido (mismo detalle y fecha) sí tiene que reemplazar.
     *
     * @test
     */
    public function dos_tareas_distintas_del_mismo_mensaje_no_se_pisan_y_la_correccion_si_reemplaza()
    {
        list($conversation, $assistant) = $this->conversacion();

        $jueves = Carbon::today()->addDays(2);
        $viernes = Carbon::today()->addDays(3);

        $contador = $this->herramienta($conversation, $assistant, 'proponer_tarea', [
            'detalle' => 'Llamar al contador P14 bis',
            'fecha'   => $jueves->format('Y-m-d'),
        ]);

        $alquiler = $this->herramienta($conversation, $assistant, 'proponer_tarea', [
            'detalle' => 'Pagar el alquiler P14 bis',
            'fecha'   => $viernes->format('Y-m-d'),
        ]);

        $this->assertTrue($contador['ok'], json_encode($contador));
        $this->assertTrue($alquiler['ok'], json_encode($alquiler));

        $this->assertEquals([], $contador['reemplazo']);
        $this->assertEquals([], $alquiler['reemplazo'], 'La segunda tarea no reemplaza a la primera.');

        $this->assertEquals('propuesta', AiMessageAction::find($contador['tarjeta_id'])->estado);
        $this->assertEquals('propuesta', AiMessageAction::find($alquiler['tarjeta_id'])->estado);

        // Mismo pedido escrito distinto (mayúsculas y espacios de más): es una corrección, reemplaza.
        $corregida = $this->herramienta($conversation, $assistant, 'proponer_tarea', [
            'detalle' => '  LLAMAR   al Contador P14 bis ',
            'fecha'   => $jueves->format('Y-m-d'),
            'notas'   => 'A las 10',
        ]);

        $this->assertTrue($corregida['ok'], json_encode($corregida));
        $this->assertEquals([$contador['tarjeta_id']], $corregida['reemplazo']);
        $this->assertEquals('reemplazada', AiMessageAction::find($contador['tarjeta_id'])->estado);
        $this->assertEquals('propuesta', AiMessageAction::find($alquiler['tarjeta_id'])->estado, 'La corrección del contador no toca el alquiler.');

        // Y la misma tarea para OTRO día es otra tarea, no una corrección.
        $otro_dia = $this->herramienta($conversation, $assistant, 'proponer_tarea', [
            'detalle' => 'Llamar al contador P14 bis',
            'fecha'   => $viernes->format('Y-m-d'),
        ]);

        $this->assertTrue($otro_dia['ok'], json_encode($otro_dia));
        $this->assertEquals([], $otro_dia['reemplazo']);
        $this->assertEquals('propuesta', AiMessageAction::find($corregida['tarjeta_id'])->estado);
    }
}
