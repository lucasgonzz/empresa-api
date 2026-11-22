<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Models\AiMessage;
use Illuminate\Support\Facades\DB;

/**
 * Misión agente-tareas-programadas, ENMIENDA 1 / E1 (22/11/2026) — EL RESULTADO LLEGA AUNQUE LA VENTANA DE 24 H
 * ESTÉ CERRADA.
 *
 * Con la ventana cerrada (el caso normal de un aviso a las 9 de la mañana) el admin manda sólo una plantilla
 * («…Ya te dejé el mensaje: respondeme por acá y seguimos») y sellaba `entregada` como si el dueño hubiera leído
 * el texto. Contrato nuevo, opcional y compatible hacia atrás: el sello puede traer `via` («texto» | «plantilla»);
 * si no viene (admin viejo) la empresa lo DEDUCE. Con `plantilla`, el PRIMER turno de WhatsApp que el dueño inicia
 * entrega el resultado por código —la respuesta del turno EMPIEZA con el texto guardado—, y recién entonces se
 * marca `mostrada_at`.
 *
 * 🔴 Los endpoints son los reales y el job del turno es el real (ResponderMensajeChatIaJob); sólo el modelo es un
 * doble (Http::fake): el texto del resultado NO lo devuelve el doble, tiene que ponerlo el código. Cola fakeada y
 * reloj fijo (TareasDelAsistenteTestCase).
 *
 * PHP 7.4: sin match, sin ?->, sin str_contains, sin argumentos nombrados.
 */
class Tareas_del_asistente_entrega_con_ventana_cerrada_Test extends TareasDelAsistenteConModeloTestCase
{
    /** El resultado de la tarea: una frase que el doble del modelo jamás diría. */
    const RESULTADO = 'Ojo con el pedido de Ferretería Sur: lleva tres días sin respuesta.';

    /** Lo que contesta el doble del modelo al mensaje del dueño. */
    const RESPUESTA = 'Dale, decime qué querés ver primero.';

    /**
     * El resultado listo y sellado por un admin NUEVO con `via`. Deja el reloj a las 17:02 del viernes.
     *
     * @param  string|null  $via
     * @param  string  $titulo
     * @param  string  $resultado
     * @param  string  $hora
     * @return object  La fila de la ejecución antes del sello.
     */
    protected function resultado_sellado($via, $titulo = 'Pedidos sin respuesta', $resultado = self::RESULTADO, $hora = '17:00')
    {
        $ejecucion = $this->resultado_listo($titulo, $resultado, $hora);

        $this->sellar($ejecucion, $via)->assertStatus(200)->assertJson(['ok' => true]);

        return $ejecucion;
    }

    /**
     * 🔴 E1. Sello con via=plantilla: la ejecución queda `entregada` con `entregada_via = plantilla`. El siguiente
     * turno de WhatsApp del dueño devuelve una respuesta que EMPIEZA con el resultado guardado (puesto por código)
     * y sigue con lo que contestó el modelo; marca `mostrada_at`; el modelo sabe que ya va al principio; un segundo
     * turno ya no lo repite. Y el texto del historial es el mismo que lee el admin.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function con_plantilla_el_siguiente_turno_entrega_el_resultado()
    {
        $ejecucion = $this->resultado_sellado('plantilla');

        $fila = $this->ejecucion_cruda($ejecucion->id);

        $this->assertSame('entregada', $fila->estado);
        $this->assertSame('plantilla', $fila->entregada_via);
        $this->assertNull($fila->mostrada_at);

        // El dueño contesta media hora después.
        $this->ahora('2026-11-27 17:30:00');

        $turno = $this->el_dueno_escribe('Dale, contame', self::RESPUESTA);

        $this->assertSame('listo', $turno->estado);
        $this->assertStringStartsWith(self::RESULTADO, (string) $turno->contenido, 'La respuesta del turno tiene que EMPEZAR con el resultado guardado.');
        $this->assertStringContainsString(self::RESPUESTA, (string) $turno->contenido, 'Después del resultado sigue lo que contesta el modelo.');
        $this->assertGreaterThan(strlen(self::RESULTADO), strpos((string) $turno->contenido, self::RESPUESTA));

        // Es lo mismo que lee el admin por el polling del mensaje.
        $leido = $this->getJson('api/admin-sync/asistente/mensajes/' . $turno->id, $this->headers());

        $leido->assertStatus(200);
        $this->assertSame((string) $turno->contenido, $leido->json('contenido'));

        $this->assertSame('2026-11-27 17:30:00', $this->ejecucion_cruda($ejecucion->id)->mostrada_at);

        // Al modelo se le avisó (en el system NO cacheado) que el resultado ya va al principio de su respuesta.
        $pedidos = $this->pedidos_al_modelo();

        $this->assertNotEmpty($pedidos);

        $system = $this->system_sin_cache($pedidos[count($pedidos) - 1]);

        $this->assertStringContainsString('al principio de tu respuesta', $system);
        $this->assertStringContainsString(self::RESULTADO, $system);

        // Un segundo turno ya no lo repite y no cambia la marca.
        $this->ahora('2026-11-27 17:40:00');

        $segundo = $this->el_dueno_escribe('Gracias', 'De nada.');

        $this->assertSame('De nada.', (string) $segundo->contenido);
        $this->assertSame('2026-11-27 17:30:00', $this->ejecucion_cruda($ejecucion->id)->mostrada_at);

        $pedidos = $this->pedidos_al_modelo();

        $this->assertStringNotContainsString('al principio de tu respuesta', $this->system_sin_cache($pedidos[count($pedidos) - 1]));
    }

    /**
     * 🔴 E1. Con via=texto salió el texto completo: no se antepone nada y la marca queda en null.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function con_texto_no_se_antepone_nada()
    {
        $ejecucion = $this->resultado_sellado('texto');

        $this->assertSame('texto', $this->ejecucion_cruda($ejecucion->id)->entregada_via);

        $this->ahora('2026-11-27 17:30:00');

        $turno = $this->el_dueno_escribe('Dale, contame', self::RESPUESTA);

        $this->assertSame(self::RESPUESTA, (string) $turno->contenido);
        $this->assertNull($this->ejecucion_cruda($ejecucion->id)->mostrada_at);
    }

    /**
     * 🔴 E1. Un valor de `via` desconocido se trata como ausente: se deduce (acá, sin mensajes del dueño en las
     * 24 h previas, «plantilla»).
     *
     * @group asistente-whatsapp
     * @test
     */
    public function un_via_desconocido_se_deduce()
    {
        $ejecucion = $this->resultado_sellado('paloma');

        $this->assertSame('plantilla', $this->ejecucion_cruda($ejecucion->id)->entregada_via);
    }

    /**
     * 🔴 E1. Sello SIN via (el admin de hoy): se deduce «texto» si el dueño escribió por WhatsApp en las 24 h
     * previas al sello (la ventana está abierta)...
     *
     * @group asistente-whatsapp
     * @test
     */
    public function sin_via_se_deduce_texto_si_escribio_en_las_24_horas()
    {
        // El dueño escribió esa mañana, en una conversación suya.
        $this->ahora('2026-11-27 09:00:00');

        $conversacion = $this->conversacion_whatsapp();
        $this->mensaje($conversacion, 'user', 'listo', ['contenido' => 'Buen día, ¿cómo vamos?']);

        $ejecucion = $this->resultado_sellado(null);

        $this->assertSame('texto', $this->ejecucion_cruda($ejecucion->id)->entregada_via);

        $this->ahora('2026-11-27 17:30:00');

        $turno = $this->el_dueno_escribe('Dale, contame', self::RESPUESTA);

        $this->assertSame(self::RESPUESTA, (string) $turno->contenido, 'Con la ventana abierta salió el texto: no se repite.');
    }

    /**
     * 🔴 E1. ...y «plantilla» si no: ni un mensaje del dueño en las 24 h (uno de hace 25 h no cuenta), y el
     * mensaje sintético de la propia tarea (tipo tarea_programada) tampoco cuenta como mensaje del dueño.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function sin_via_se_deduce_plantilla_si_no_escribio()
    {
        // Escribió hace 25 horas y nada más.
        $this->ahora('2026-11-26 16:00:00');

        $conversacion = $this->conversacion_whatsapp();
        $this->mensaje($conversacion, 'user', 'listo', ['contenido' => 'Anotame algo para mañana']);

        $ejecucion = $this->resultado_sellado(null);

        $this->assertSame('plantilla', $this->ejecucion_cruda($ejecucion->id)->entregada_via);

        $this->ahora('2026-11-27 17:30:00');

        $turno = $this->el_dueno_escribe('Dale, contame', self::RESPUESTA);

        $this->assertStringStartsWith(self::RESULTADO, (string) $turno->contenido);
        $this->assertNotNull($this->ejecucion_cruda($ejecucion->id)->mostrada_at);
    }

    /**
     * 🔴 E1. Si el turno falla no se marca `mostrada_at` (y el siguiente turno sí lo entrega).
     *
     * @group asistente-whatsapp
     * @test
     */
    public function si_el_turno_falla_no_se_marca_y_se_reintenta()
    {
        $ejecucion = $this->resultado_sellado('plantilla');

        $this->ahora('2026-11-27 17:30:00');

        $this->modelo_falla();

        $respuesta = $this->mandar(['texto' => 'Dale, contame', 'whatsapp_message_id' => 'wamid.FALLA1']);

        $respuesta->assertStatus(202);

        (new \App\Jobs\ResponderMensajeChatIaJob((int) $respuesta->json('ai_message_id')))->handle();

        $fallido = AiMessage::find((int) $respuesta->json('ai_message_id'));

        $this->assertSame('error', $fallido->estado, 'El doble tiene que haber hecho fallar el turno.');
        $this->assertNull($this->ejecucion_cruda($ejecucion->id)->mostrada_at, 'Un turno fallido no marca el resultado como mostrado.');

        $this->ahora('2026-11-27 17:35:00');

        $bueno = $this->el_dueno_escribe('Probá de nuevo', self::RESPUESTA);

        $this->assertStringStartsWith(self::RESULTADO, (string) $bueno->contenido);
        $this->assertSame('2026-11-27 17:35:00', $this->ejecucion_cruda($ejecucion->id)->mostrada_at);
    }

    /**
     * 🔴 E1. Pasadas 24 h desde que se mandó la plantilla, el resultado ya no se entrega.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function pasadas_24_horas_no_se_entrega()
    {
        $ejecucion = $this->resultado_sellado('plantilla');

        // El sello fue a las 17:01 del viernes: a las 17:02 del sábado ya pasaron 24 h.
        $this->ahora('2026-11-28 17:02:00');

        $turno = $this->el_dueno_escribe('Hola, perdón la demora', self::RESPUESTA);

        $this->assertSame(self::RESPUESTA, (string) $turno->contenido);
        $this->assertNull($this->ejecucion_cruda($ejecucion->id)->mostrada_at);
    }

    /**
     * 🔴 E1. Un turno desatendido (lo despertó otra tarea) no dispara la entrega: no lo inició el dueño. La
     * respuesta de ese turno no lleva el resultado pendiente y no se marca.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function un_turno_desatendido_no_dispara_la_entrega()
    {
        $ejecucion = $this->resultado_sellado('plantilla');

        // Otra tarea vence a las 18:00 y su turno corre sin que el dueño haya escrito.
        $otra = $this->tarea_del_asistente([
            'titulo'               => 'Resumen de la tarde',
            'hora'                 => '18:00',
            'proxima_ejecucion_at' => '2026-11-27 18:00:00',
        ]);

        $this->ahora('2026-11-27 17:59:00');

        $this->despertar();

        $turno_de_la_otra = $this->ejecuciones($otra->id);

        $this->assertCount(1, $turno_de_la_otra, 'La otra tarea tiene que haberse despertado.');

        $this->modelo_contesta('Las ventas de la tarde vienen bien.');

        (new \App\Jobs\ResponderMensajeChatIaJob((int) $turno_de_la_otra[0]->ai_message_id))->handle();

        $assistant = AiMessage::find((int) $turno_de_la_otra[0]->ai_message_id);

        $this->assertSame('Las ventas de la tarde vienen bien.', (string) $assistant->contenido);
        $this->assertNull($this->ejecucion_cruda($ejecucion->id)->mostrada_at, 'El turno desatendido no entrega nada.');
    }

    /**
     * 🔴 E1. Varias pendientes: en orden de ocurrencia, cada una con su título, como mucho tres por turno; la
     * cuarta sale en el turno siguiente.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function varias_pendientes_en_orden_y_maximo_tres()
    {
        $a = $this->resultado_sellado('plantilla', 'Cobranzas', 'RESULTADO-A: tres cheques vencen mañana.', '09:00');
        $b = $this->resultado_sellado('plantilla', 'Stock', 'RESULTADO-B: faltan veinte cajas de tornillos.', '10:00');
        $c = $this->resultado_sellado('plantilla', 'Ventas', 'RESULTADO-C: la caja del jueves cerró en 1.200.000.', '11:00');
        $d = $this->resultado_sellado('plantilla', 'Pedidos', 'RESULTADO-D: dos pedidos de la tienda esperan.', '12:00');

        $this->ahora('2026-11-27 13:00:00');

        $primero = (string) $this->el_dueno_escribe('Hola', self::RESPUESTA)->contenido;

        $this->assertStringStartsWith('«Cobranzas»', $primero, 'Con varias pendientes cada una va encabezada por su título.');

        $pos_a = strpos($primero, 'RESULTADO-A');
        $pos_b = strpos($primero, 'RESULTADO-B');
        $pos_c = strpos($primero, 'RESULTADO-C');

        $this->assertNotFalse($pos_a);
        $this->assertNotFalse($pos_b);
        $this->assertNotFalse($pos_c);
        $this->assertTrue($pos_a < $pos_b && $pos_b < $pos_c, 'Van en orden de ocurrencia.');
        $this->assertFalse(strpos($primero, 'RESULTADO-D'), 'Como mucho tres por turno.');
        $this->assertGreaterThan($pos_c, strpos($primero, self::RESPUESTA), 'Lo que contesta el modelo va después de los resultados.');

        $this->assertNotNull($this->ejecucion_cruda($a->id)->mostrada_at);
        $this->assertNotNull($this->ejecucion_cruda($b->id)->mostrada_at);
        $this->assertNotNull($this->ejecucion_cruda($c->id)->mostrada_at);
        $this->assertNull($this->ejecucion_cruda($d->id)->mostrada_at);

        $this->ahora('2026-11-27 13:10:00');

        $segundo = (string) $this->el_dueno_escribe('¿Y lo otro?', self::RESPUESTA)->contenido;

        $this->assertStringStartsWith('RESULTADO-D: dos pedidos de la tienda esperan.', $segundo, 'La cuarta sale en el turno siguiente (sola: sin encabezado).');
        $this->assertFalse(strpos($segundo, 'RESULTADO-A'));
        $this->assertNotNull($this->ejecucion_cruda($d->id)->mostrada_at);
    }

    /**
     * 🔴 E1. Es del dueño de la instancia: el resultado pendiente de OTRO negocio de la base compartida no se
     * entrega, y una conversación del dueño por otro canal que no es WhatsApp no dispara nada.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function solo_entrega_lo_del_dueno_del_turno()
    {
        $otro = $this->otro_dueno();
        $de_otro = $this->tarea_del_asistente(['owner_id' => $otro->id, 'auth_user_id' => $otro->id]);

        $ajena = DB::table('ai_tareas_del_asistente_ejecuciones')->insertGetId([
            'tarea_id'      => $de_otro->id,
            'owner_id'      => $otro->id,
            'ocurrencia_at' => '2026-11-27 17:00:00',
            'estado'        => 'entregada',
            'texto'         => 'RESULTADO-AJENO: esto es de otro comercio.',
            'entregada_at'  => '2026-11-27 17:01:00',
            'entregada_via' => 'plantilla',
            'created_at'    => '2026-11-27 16:58:00',
            'updated_at'    => '2026-11-27 17:01:00',
        ]);

        $this->ahora('2026-11-27 17:30:00');

        $turno = $this->el_dueno_escribe('Hola', self::RESPUESTA);

        $this->assertSame(self::RESPUESTA, (string) $turno->contenido);
        $this->assertNull($this->ejecucion_cruda($ajena)->mostrada_at);
    }
}
