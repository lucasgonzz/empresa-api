<?php

namespace Tests\Feature\AsistenteWhatsapp;

use Illuminate\Support\Facades\DB;

/**
 * Misión agente-tareas-programadas (21/11/2026) — LAS RECURRENCIAS, POSPONER, LISTAR Y CANCELAR.
 *
 * Pedido de Lucas: «si el dueño del negocio dice "hoy no me preguntes, mandame recién el viernes que viene
 * por esto", que se guarde esa tarea y el viernes le vuelve a mandar». Lo que protege este archivo (CA3 alta,
 * CA4 y CA5 del plan):
 *   1. diaria, días de la semana y semanal calculan bien su primera ejecución, y no hay nada más frecuente;
 *   2. posponer mueve la próxima ejecución y la hora vieja ya no despierta nada; «recién el viernes» en una
 *      recurrente y reprogramar una pausada la dejan en marcha;
 *   3. cancelar saca la tarea del camino y consultar lista sólo lo que sigue vivo, por orden de próxima;
 *   4. una tarea de otro negocio no se ve, no se mueve y no se cancela.
 *
 * 🔴 Las herramientas corren de verdad contra la base y el comando real despierta (o no) a la hora. Reloj
 * fijo (sábado 21/11/2026 a las 10:00) y cola fakeada (TareasDelAsistenteTestCase).
 *
 * PHP 7.4: sin match, sin ?->, sin str_contains, sin argumentos nombrados.
 */
class Tareas_del_asistente_posponer_y_cancelar_Test extends TareasDelAsistenteTestCase
{
    /**
     * Programa por la herramienta y devuelve el resultado, fallando el test con el texto del rechazo si no se pudo.
     *
     * @param  array  $input
     * @return array
     */
    protected function programar(array $input)
    {
        $resultado = $this->herramienta('programar_tarea_del_asistente', array_merge([
            'titulo'      => self::TITULO,
            'instruccion' => self::INSTRUCCION,
        ], $input));

        $this->assertFalse($resultado['error'], 'La herramienta rechazó el alta: ' . $resultado['texto']);
        $this->assertIsArray($resultado['datos'], 'El resultado tiene que ser un JSON: ' . $resultado['texto']);

        return $resultado['datos'];
    }

    /**
     * 🔴 CA3 (alta). Las tres recurrencias calculan su primera ejecución: una diaria a la mañana (domingo 09:00),
     * lunes-miércoles-viernes a las 08:30 (el lunes) y semanal los viernes a las 18:00 (el 27/11).
     *
     * @group asistente-whatsapp
     * @test
     */
    public function recurrencias_calculan_la_proxima()
    {
        $this->ahora(self::SABADO);

        $diaria = $this->programar(['titulo' => 'Ventas', 'instruccion' => 'Contame las ventas', 'recurrencia' => 'diaria', 'franja' => 'manana']);
        $this->assertSame('2026-11-22 09:00:00', $this->fila_de($diaria['tarea_id'])->proxima_ejecucion_at);
        $this->assertSame('diaria', $this->fila_de($diaria['tarea_id'])->recurrencia);

        $semana = $this->programar(['titulo' => 'Pedidos', 'instruccion' => 'Revisá los pedidos', 'recurrencia' => 'dias_semana', 'dias' => [1, 3, 5], 'hora' => '08:30']);
        $this->assertSame('2026-11-23 08:30:00', $this->fila_de($semana['tarea_id'])->proxima_ejecucion_at);
        $this->assertSame('1,3,5', $this->fila_de($semana['tarea_id'])->dias);

        $semanal = $this->programar(['titulo' => 'Cierre', 'instruccion' => 'Armá el cierre de la semana', 'recurrencia' => 'semanal', 'dias' => [5], 'hora' => '18:00']);
        $this->assertSame('2026-11-27 18:00:00', $this->fila_de($semanal['tarea_id'])->proxima_ejecucion_at);
    }

    /**
     * 🔴 CA4. «Hoy no, el viernes»: una tarea de hoy a las 17:00 pasa al viernes a la tarde; a las 17:05 de hoy
     * el comando no despierta nada.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function posponer_al_viernes()
    {
        $this->ahora(self::SABADO);

        $alta = $this->programar(['fecha' => '2026-11-21', 'hora' => '17:00']);
        $this->assertSame('2026-11-21 17:00:00', $this->fila_de($alta['tarea_id'])->proxima_ejecucion_at);

        $pospuesta = $this->herramienta('reprogramar_tarea_del_asistente', [
            'tarea_id' => $alta['tarea_id'],
            'fecha'    => '2026-11-27',
            'franja'   => 'tarde',
        ]);

        $this->assertFalse($pospuesta['error'], 'La herramienta rechazó la reprogramación: ' . $pospuesta['texto']);
        $this->assertSame('2026-11-27 17:00:00', $this->fila_de($alta['tarea_id'])->proxima_ejecucion_at);
        $this->assertSame('activa', $this->fila_de($alta['tarea_id'])->estado);
        $this->assertStringContainsString('viernes 27/11 a las 17:00', $pospuesta['datos']['cuando']);

        // A la hora vieja el comando no despierta nada.
        $this->ahora('2026-11-21 17:05:00');

        $this->despertar();

        $this->assertSame([], $this->ejecuciones(), 'La tarea pospuesta no puede correr a la hora vieja.');
    }

    /**
     * 🔴 CA4. «Recién el viernes» en una recurrente (`desde`) la deja para el viernes a su hora y el día
     * siguiente no corre; reprogramar una pausada la deja activa.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function reanudar_desde()
    {
        $this->ahora(self::SABADO);

        $diaria = $this->programar(['titulo' => 'Ventas', 'instruccion' => 'Contame las ventas', 'recurrencia' => 'diaria', 'hora' => '09:00']);
        $this->assertSame('2026-11-22 09:00:00', $this->fila_de($diaria['tarea_id'])->proxima_ejecucion_at);

        $desde = $this->herramienta('reprogramar_tarea_del_asistente', ['tarea_id' => $diaria['tarea_id'], 'desde' => '2026-11-27']);

        $this->assertFalse($desde['error'], 'La herramienta rechazó la reprogramación: ' . $desde['texto']);
        $this->assertSame('2026-11-27 09:00:00', $this->fila_de($diaria['tarea_id'])->proxima_ejecucion_at);

        // El domingo 22/11 a las 09:00 no corre.
        $this->ahora('2026-11-22 09:00:00');

        $this->despertar();

        $this->assertSame([], $this->ejecuciones(), 'La diaria con «recién el viernes» no puede correr antes.');

        // Una pausada (3 fallos seguidos) vuelve a estar activa al reprogramarla.
        $pausada = $this->tarea_del_asistente([
            'titulo'               => 'Pausada',
            'recurrencia'          => 'diaria',
            'hora'                 => '09:00',
            'franja'               => null,
            'fecha_inicio'         => '2026-11-21',
            'estado'               => 'pausada',
            'fallos_seguidos'      => 3,
            'proxima_ejecucion_at' => null,
        ]);

        $reanudada = $this->herramienta('reprogramar_tarea_del_asistente', ['tarea_id' => $pausada->id, 'hora' => '08:00']);

        $this->assertFalse($reanudada['error'], 'La herramienta rechazó la reprogramación: ' . $reanudada['texto']);
        $this->assertSame('activa', $this->fila_de($pausada->id)->estado);
        $this->assertSame('2026-11-22 08:00:00', $this->fila_de($pausada->id)->proxima_ejecucion_at, 'Hoy a las 08:00 ya pasó: la próxima es el día siguiente.');
    }

    /**
     * 🔴 CA5. Cancelar y listar: cancelar deja la tarea cancelada y sin próxima, y a su hora no corre; consultar
     * lista sólo las activas y las pausadas, por orden de próxima, con la hora de «ahora» en palabras.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function cancelar_y_listar()
    {
        $this->ahora(self::SABADO);

        $una_vez = $this->programar(['titulo' => 'Logística', 'fecha' => '2026-11-27', 'franja' => 'tarde']);
        $diaria = $this->programar(['titulo' => 'Ventas', 'instruccion' => 'Contame las ventas', 'recurrencia' => 'diaria', 'hora' => '09:00']);
        $para_cancelar = $this->programar(['titulo' => 'Se cancela', 'fecha' => '2026-11-22', 'hora' => '12:00']);
        $pausada = $this->tarea_del_asistente([
            'titulo'               => 'Pausada',
            'estado'               => 'pausada',
            'proxima_ejecucion_at' => '2026-11-25 09:00:00',
        ]);

        $cancelada = $this->herramienta('cancelar_tarea_del_asistente', ['tarea_id' => $para_cancelar['tarea_id']]);

        $this->assertFalse($cancelada['error'], 'La herramienta rechazó la cancelación: ' . $cancelada['texto']);
        $this->assertSame('cancelada', $this->fila_de($para_cancelar['tarea_id'])->estado);
        $this->assertNull($this->fila_de($para_cancelar['tarea_id'])->proxima_ejecucion_at);

        $listado = $this->herramienta('consultar_tareas_del_asistente');

        $this->assertFalse($listado['error'], 'La herramienta rechazó la consulta: ' . $listado['texto']);
        $this->assertSame('10:00 del sábado 21/11/2026', $listado['datos']['ahora']);

        // Por orden de próxima: la diaria (22/11), la pausada (25/11) y la del viernes (27/11); la cancelada no.
        $this->assertSame(
            [(int) $diaria['tarea_id'], (int) $pausada->id, (int) $una_vez['tarea_id']],
            array_map(function ($tarea) {
                return (int) $tarea['tarea_id'];
            }, $listado['datos']['tareas'])
        );
        $this->assertSame(['activa', 'pausada', 'activa'], array_map(function ($tarea) {
            return $tarea['estado'];
        }, $listado['datos']['tareas']));
        $this->assertSame('Ventas', $listado['datos']['tareas'][0]['titulo']);

        // A la hora de la cancelada (domingo 12:00, dentro del anticipo) no corre.
        $this->ahora('2026-11-22 11:59:00');

        $this->despertar();

        $this->assertSame([], $this->ejecuciones($para_cancelar['tarea_id']), 'Una tarea cancelada no despierta.');
    }

    /**
     * 🔴 CA5. Una tarea de otro negocio no se ve, no se mueve y no se cancela; un id que no existe dice lo mismo
     * (sin delatar que el id es de otro).
     *
     * @group asistente-whatsapp
     * @test
     */
    public function otro_dueno_no()
    {
        $this->ahora(self::SABADO);

        $otro = $this->otro_dueno();

        $ajena = $this->tarea_del_asistente(['owner_id' => $otro->id, 'auth_user_id' => $otro->id, 'titulo' => 'De otro negocio']);
        $propia = $this->programar(['titulo' => 'Mía', 'fecha' => '2026-11-27', 'hora' => '17:00']);

        $cancelar = $this->herramienta('cancelar_tarea_del_asistente', ['tarea_id' => $ajena->id]);

        $this->assertStringNotContainsString('Tool desconocida', $cancelar['texto'], 'La herramienta no existe todavía.');
        $this->assertTrue($cancelar['error'], 'Cancelar una tarea ajena tiene que rechazarse: ' . $cancelar['texto']);
        $this->assertStringContainsString('No encontré esa tarea', $cancelar['texto']);
        $this->assertSame('activa', $this->fila_de($ajena->id)->estado);

        $reprogramar = $this->herramienta('reprogramar_tarea_del_asistente', ['tarea_id' => $ajena->id, 'fecha' => '2026-12-01', 'hora' => '09:00']);

        $this->assertTrue($reprogramar['error'], 'Reprogramar una tarea ajena tiene que rechazarse: ' . $reprogramar['texto']);
        $this->assertStringContainsString('No encontré esa tarea', $reprogramar['texto']);
        $this->assertSame('2026-11-27 17:00:00', $this->fila_de($ajena->id)->proxima_ejecucion_at);

        $inexistente = $this->herramienta('cancelar_tarea_del_asistente', ['tarea_id' => 999999999]);

        $this->assertTrue($inexistente['error']);
        $this->assertSame($cancelar['texto'], $inexistente['texto'], 'Un id ajeno y uno inexistente tienen que dar el mismo mensaje.');

        $listado = $this->herramienta('consultar_tareas_del_asistente');

        $this->assertFalse($listado['error'], 'La herramienta rechazó la consulta: ' . $listado['texto']);
        $this->assertSame([(int) $propia['tarea_id']], array_map(function ($tarea) {
            return (int) $tarea['tarea_id'];
        }, $listado['datos']['tareas']), 'La lista sólo trae las tareas del dueño.');

        $this->assertSame(1, DB::table('ai_tareas_del_asistente')->where('owner_id', $this->comercio->id)->count());
    }
}
