<?php

namespace Tests\Feature\Horarios;

use App\Models\BusinessHoursConfig;
use App\Models\User;
use App\Services\BusinessHoursReader;
use Carbon\Carbon;
use Tests\EmpresaTestCase;

/**
 * Lectura del horario comercial (App\Services\BusinessHoursReader): las dos preguntas que el
 * agente de WhatsApp tiene que poder contestar —"¿hasta que hora abren hoy?" y "¿abren el dia
 * X?"— sin tocar JSON crudo.
 *
 * 🔴 LO QUE MAS PROTEGE ESTE ARCHIVO ES LA DIFERENCIA ENTRE `null` Y `false`.
 * "No hay dato" y "cerrado" son dos cosas distintas y viajan en dos niveles: `configurado:
 * false` a nivel payload y `abierto: null` adentro de un dia. Un lector que colapse cualquiera
 * de los dos a `false` le dice a un comprador que el comercio esta cerrado un martes a las 10
 * de la manana. Por eso aca hay `assertNull` + `assertNotFalse` donde otro archivo pondria un
 * `assertFalse` distraido.
 *
 * El otro invariante que se protege: el lector NO reimplementa la resolucion. `semana` llega ya
 * resuelta del admin y `dias_crudos` es comodidad de lectura, nunca fuente de verdad.
 *
 * ⚠️ "Hoy" se calcula SIEMPRE con `Carbon::now(self::TZ)`, el mismo timezone que usa el lector.
 * Hardcodear un dia de la semana haria que estos tests pasen hoy y fallen el martes que viene.
 *
 * @group horarios
 */
class Lectura_de_horarios_Test extends EmpresaTestCase
{
    /** Zona horaria del comercio, la misma que viaja en el payload del admin. */
    const TZ = 'America/Argentina/Buenos_Aires';

    /** Claves de dia por indice de Carbon::dayOfWeek (0 = domingo). */
    const DIAS = ['domingo', 'lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado'];

    /** Etiquetas de dia por indice de Carbon::dayOfWeek. */
    const LABELS = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];

    /**
     * Deja la tabla espejo vacia para que cada test arranque de "no hay horario cargado". Corre
     * adentro de la transaccion de `DatabaseTransactions`.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        BusinessHoursConfig::query()->delete();
    }

    /**
     * Owner de la instancia, con el criterio del contrato.
     *
     * ⚠️ Ningun test puede asumir que el usuario que autentica el fixture es el owner.
     *
     * @return \App\Models\User
     */
    protected function owner()
    {
        return User::whereNull('owner_id')->orderBy('id')->first();
    }

    /**
     * El indice de dia de HOY, leido en el timezone del comercio (no el del servidor).
     *
     * @return int
     */
    protected function dow_de_hoy()
    {
        return (int) Carbon::now(self::TZ)->dayOfWeek;
    }

    /**
     * Un dia de `semana` tal como lo arma el emisor.
     *
     * @param int   $dow       Indice de Carbon::dayOfWeek (0 = domingo).
     * @param array $overrides Claves a pisar.
     *
     * @return array
     */
    protected function dia($dow, array $overrides = [])
    {
        $base = [
            'dia_semana' => $dow,
            'dia'        => self::DIAS[$dow],
            'dia_label'  => self::LABELS[$dow],
            'abierto'    => true,
            'estado'     => 'con_horario',
            'origen'     => 'todos_los_dias',
            'rangos'     => [['desde' => '09:00', 'hasta' => '18:00']],
            'cierre'     => '18:00',
        ];

        return array_merge($base, $overrides);
    }

    /**
     * Un dia CERRADO tal como lo manda el admin: dia propio, cero rangos, `abierto` en false.
     *
     * @param int $dow Indice de Carbon::dayOfWeek.
     *
     * @return array
     */
    protected function dia_cerrado($dow)
    {
        return $this->dia($dow, [
            'abierto' => false,
            'estado'  => 'cerrado',
            'origen'  => 'dia_propio',
            'rangos'  => [],
            'cierre'  => null,
        ]);
    }

    /**
     * Un dia SIN CONFIGURAR tal como lo manda el admin: `abierto` en null, no en false.
     *
     * @param int $dow Indice de Carbon::dayOfWeek.
     *
     * @return array
     */
    protected function dia_sin_configurar($dow)
    {
        return $this->dia($dow, [
            'abierto' => null,
            'estado'  => 'sin_configurar',
            'origen'  => 'sin_configurar',
            'rangos'  => [],
            'cierre'  => null,
        ]);
    }

    /**
     * Escribe la fila espejo del owner con la semana pedida (aca se testea el LECTOR, asi que se
     * siembra con el modelo y no por el endpoint).
     *
     * @param array $semana Dias resueltos, en la forma del contrato.
     * @param array $extra  Columnas a pisar (timezone, dias_crudos, configurado).
     *
     * @return \App\Models\BusinessHoursConfig
     */
    protected function sembrar_semana(array $semana, array $extra = [])
    {
        $valores = array_merge([
            'timezone'       => self::TZ,
            'actualizado_en' => '2026-08-25T10:00:00-03:00',
            'configurado'    => count($semana) > 0,
            'semana'         => $semana,
            'dias_crudos'    => [],
            'recibido_at'    => Carbon::now(),
        ], $extra);

        return BusinessHoursConfig::updateOrCreate(['user_id' => $this->owner()->id], $valores);
    }

    /**
     * Los siete dias, con los overrides por indice que pida el test.
     *
     * @param array $por_dow Mapa dow => dia ya armado, para pisar el dia por defecto.
     *
     * @return array
     */
    protected function semana_completa(array $por_dow = [])
    {
        $semana = [];

        for ($dow = 0; $dow < 7; $dow++) {
            $semana[] = isset($por_dow[$dow]) ? $por_dow[$dow] : $this->dia($dow);
        }

        return $semana;
    }

    /**
     * El lector del horario de la instancia.
     *
     * @return \App\Services\BusinessHoursReader
     */
    protected function lector()
    {
        return BusinessHoursReader::for_instance();
    }

    /**
     * Test 1 — "¿hasta que hora abren hoy?" con un dia de dos turnos (manana y tarde).
     *
     * 🔴 El cierre es el MAYOR `hasta` del dia ('21:00'), no el fin del primer rango ('13:00').
     * Un comercio que corta al mediodia es lo normal en el rubro: contestar "cierran a las 13"
     * un dia que atienden hasta las 21 le hace perder una venta al negocio.
     *
     * @group horarios
     * @test
     */
    public function hasta_que_hora_abre_hoy()
    {
        $hoy = $this->dow_de_hoy();

        $this->sembrar_semana($this->semana_completa([
            $hoy => $this->dia($hoy, [
                'rangos' => [
                    ['desde' => '08:00', 'hasta' => '13:00'],
                    ['desde' => '16:00', 'hasta' => '21:00'],
                ],
                'cierre' => '21:00',
            ]),
        ]));

        $lector = $this->lector();

        $this->assertTrue($lector->hay_dato(), 'Con la semana sembrada tiene que haber dato.');
        $this->assertTrue($lector->abierto_hoy(), 'Hoy el comercio abre.');

        $this->assertEquals(
            '21:00',
            $lector->cierre_de_hoy(),
            'El cierre de hoy es el mayor `hasta` del dia, no el fin del primer turno.'
        );

        $this->assertNotEquals('13:00', $lector->cierre_de_hoy(), 'El corte del mediodia no es la hora de cierre.');

        $detalle = $lector->cierre_de_hoy_detallado();

        $this->assertNull($detalle['motivo'], 'Cuando hay hora de cierre no hay motivo que explicar.');
        $this->assertEquals('con_horario', $detalle['estado']);
    }

    /**
     * Test 2 — 🔴 SIN FILA, EL LECTOR NO DICE "CERRADO". Un comercio del que no se sabe nada no
     * esta cerrado: no se sabe. `assertNull` + `assertNotFalse` porque justamente la diferencia
     * entre los dos valores es todo el contrato.
     *
     * @group horarios
     * @test
     */
    public function sin_fila_el_cierre_de_hoy_no_dice_cerrado()
    {
        $this->assertNull(
            BusinessHoursConfig::where('user_id', $this->owner()->id)->first(),
            'Precondicion del test: el owner no tiene horario cargado.'
        );

        $lector = $this->lector();

        $this->assertFalse($lector->hay_dato(), 'Sin fila no hay dato.');

        $abierto_hoy = $lector->abierto_hoy();

        $this->assertNull($abierto_hoy, 'Sin fila, `abierto_hoy()` es null: no se sabe.');
        $this->assertNotFalse($abierto_hoy, '🔴 "No se sabe" NO puede leerse como "cerrado".');

        $this->assertNull($lector->cierre_de_hoy(), 'Sin fila no hay hora de cierre que dar.');

        $detalle = $lector->cierre_de_hoy_detallado();

        $this->assertEquals('sin_dato', $detalle['motivo'], 'El motivo es "sin_dato", nunca "cerrado_hoy".');
        $this->assertNull($detalle['abierto']);
        $this->assertNotFalse($detalle['abierto'], '🔴 Tampoco en el detalle se colapsa el "no se sabe".');

        // Y la semana igual tiene sus siete renglones, todos en la forma "sin dato".
        $semana = $lector->semana();

        $this->assertCount(7, $semana, 'La semana que ve el consumidor siempre tiene siete dias.');

        foreach ($semana as $dow => $dia) {
            $this->assertNull($dia['abierto'], 'El dia '.$dow.' sin dato no puede venir como cerrado.');
            $this->assertFalse($dia['hay_dato']);
        }
    }

    /**
     * Test 3 — 🔴 EL TEST CENTRAL DEL ARCHIVO: un dia CERRADO y un dia SIN DATO llegan los dos
     * con `rangos: []` y `cierre: null`, y aun asi tienen que quedar distinguibles.
     *
     * `assertFalse` para el cerrado, `assertNull` para el que no tiene dato. Si el lector los
     * emparejara, la unica forma de darse cuenta seria un comprador enojado.
     *
     * @group horarios
     * @test
     */
    public function un_dia_cerrado_se_distingue_de_un_dia_sin_dato()
    {
        // Domingo cerrado (el comercio lo declaro), miercoles sin configurar (nadie lo cargo).
        $this->sembrar_semana($this->semana_completa([
            0 => $this->dia_cerrado(0),
            3 => $this->dia_sin_configurar(3),
        ]));

        $lector = $this->lector();

        $domingo   = $lector->dia(0);
        $miercoles = $lector->dia(3);

        $this->assertFalse($domingo['abierto'], 'El domingo esta cerrado: `abierto` es false.');
        $this->assertEquals('cerrado', $domingo['estado']);
        $this->assertTrue($domingo['hay_dato'], 'Un dia cerrado SI tiene dato: se sabe que esta cerrado.');

        $this->assertNull($miercoles['abierto'], 'El miercoles no tiene horario cargado: `abierto` es null.');
        $this->assertNotFalse($miercoles['abierto'], '🔴 Sin horario cargado NO es lo mismo que cerrado.');
        $this->assertEquals('sin_configurar', $miercoles['estado']);

        $this->assertNotSame(
            $domingo['abierto'],
            $miercoles['abierto'],
            'Cerrado y sin dato tienen que ser dos valores distintos, no dos nombres del mismo.'
        );

        // Los dos comparten rangos vacios y cierre null: si el lector decidiera por ahi, serian
        // indistinguibles. El campo que gobierna es `abierto`.
        $this->assertSame([], $domingo['rangos']);
        $this->assertSame([], $miercoles['rangos']);
        $this->assertNull($domingo['cierre']);
        $this->assertNull($miercoles['cierre']);
    }

    /**
     * Test 4 — "¿abren el dia X?", por numero de dia y por fecha, dando lo mismo. La version por
     * fecha ubica el dia en el timezone del COMERCIO, no en el del servidor.
     *
     * @group horarios
     * @test
     */
    public function el_horario_del_dia_x_por_numero_y_por_fecha()
    {
        $this->sembrar_semana($this->semana_completa([
            3 => $this->dia(3, [
                'rangos' => [['desde' => '08:30', 'hasta' => '16:30']],
                'cierre' => '16:30',
            ]),
        ]));

        $lector = $this->lector();

        $por_numero = $lector->dia(3);

        $this->assertEquals('miercoles', $por_numero['dia']);
        $this->assertEquals('Miércoles', $por_numero['dia_label'], 'La etiqueta viaja con tilde.');
        $this->assertTrue($por_numero['abierto']);
        $this->assertEquals('16:30', $por_numero['cierre']);

        // Un miercoles concreto, armado en el timezone del comercio para no depender del hoy.
        $un_miercoles = Carbon::now(self::TZ)->startOfWeek(Carbon::SUNDAY)->addDays(3);

        $this->assertSame(3, (int) $un_miercoles->dayOfWeek, 'Precondicion: la fecha armada es un miercoles.');

        $this->assertSame(
            $por_numero,
            $lector->dia_de_fecha($un_miercoles),
            'Preguntar por numero de dia y por fecha tiene que dar exactamente lo mismo.'
        );
    }

    /**
     * Test 5 — una semana incompleta (el contrato dice que llegan siete, pero un emisor viejo o
     * un dato a medias puede traer menos) se completa con "sin dato", NUNCA con "cerrado".
     *
     * @group horarios
     * @test
     */
    public function una_semana_incompleta_se_completa_con_sin_dato()
    {
        // Solo llegan lunes y martes.
        $this->sembrar_semana([$this->dia(1), $this->dia(2)]);

        $lector = $this->lector();
        $semana = $lector->semana();

        $this->assertCount(7, $semana, 'La semana que ve el consumidor siempre tiene siete renglones.');

        $this->assertTrue($semana[1]['hay_dato'], 'El lunes vino en el payload.');
        $this->assertTrue($semana[2]['hay_dato'], 'El martes vino en el payload.');

        foreach ([0, 3, 4, 5, 6] as $dow) {
            $this->assertFalse($semana[$dow]['hay_dato'], 'El dia '.$dow.' no vino en el payload.');
            $this->assertNull($semana[$dow]['abierto'], 'Un dia que no vino no puede presentarse como cerrado.');
            $this->assertNotFalse($semana[$dow]['abierto'], '🔴 Faltar en el payload no es estar cerrado.');
            $this->assertEquals('sin_configurar', $semana[$dow]['estado']);
            // La etiqueta igual se resuelve por respaldo: el consumidor siempre tiene como nombrarlo.
            $this->assertEquals(self::LABELS[$dow], $semana[$dow]['dia_label']);
        }
    }

    /**
     * Test 6 — 🔴 EL LECTOR NO REIMPLEMENTA LA PRECEDENCIA. `semana` llega ya resuelta del admin
     * y es la unica fuente de verdad; `dias_crudos` viaja como comodidad de lectura y NO
     * gobierna ninguna respuesta.
     *
     * Aca los dos se contradicen a proposito: `semana` dice que hoy esta cerrado y `dias_crudos`
     * trae un "todos los dias de 09:00 a 22:00". Si el lector mirara `dias_crudos` para decidir,
     * habria dos criterios sobre el mismo invariante y el dia que el admin agregue un caso
     * (feriados, medio dia) uno de los dos se iba a olvidar.
     *
     * @group horarios
     * @test
     */
    public function el_lector_no_reimplementa_la_precedencia()
    {
        $hoy = $this->dow_de_hoy();

        $crudos = [
            [
                'dia'       => 'todos',
                'dia_label' => 'Todos los días',
                'rangos'    => [['desde' => '09:00', 'hasta' => '22:00']],
            ],
        ];

        $this->sembrar_semana(
            $this->semana_completa([$hoy => $this->dia_cerrado($hoy)]),
            ['dias_crudos' => $crudos]
        );

        $lector = $this->lector();

        $this->assertFalse(
            $lector->abierto_hoy(),
            'Gana `semana` (hoy cerrado), no `dias_crudos` (que dice que abre todos los dias).'
        );

        $this->assertNull($lector->cierre_de_hoy(), 'Hoy no abre, asi que no hay hora de cierre.');
        $this->assertNotEquals('22:00', $lector->cierre_de_hoy(), 'El 22:00 de `dias_crudos` no puede filtrarse.');

        $detalle = $lector->cierre_de_hoy_detallado();

        $this->assertEquals('cerrado_hoy', $detalle['motivo'], 'El motivo sale de `semana`.');

        /* Y `dias_crudos` sigue estando, verbatim, para que el agente pueda citarlo si quiere.
         *
         * ⚠️ `assertEquals` y no `assertSame` a proposito: la columna es `json` y MySQL normaliza
         * el ORDEN de las claves de un objeto al guardarlo (las ordena por largo de clave, asi que
         * 'dia_label' queda despues de 'rangos'). Eso es del motor, no del lector: las claves y los
         * valores vuelven todos y sin re-mapear, que es lo que "verbatim" promete. Exigir el orden
         * seria testear una decision de MySQL que ningun codigo de este repo controla. */
        $this->assertEquals($crudos, $lector->dias_crudos(), '`dias_crudos` se devuelve tal como llego.');
    }

    /**
     * Test 7 — un dia al que le falta la clave `abierto` (emisor viejo, o un campo que se cayo en
     * el camino) se lee como "sin dato", JAMAS como "cerrado". Es el caso donde un `(bool)`
     * distraido rompe el contrato: `(bool) null` da `false` y ahi se pierde el tercer estado.
     *
     * @group horarios
     * @test
     */
    public function un_abierto_ausente_se_lee_como_sin_dato_y_no_como_cerrado()
    {
        $hoy = $this->dow_de_hoy();

        $dia_sin_abierto = $this->dia($hoy);
        unset($dia_sin_abierto['abierto']);

        $this->sembrar_semana($this->semana_completa([$hoy => $dia_sin_abierto]));

        $lector = $this->lector();

        $abierto_hoy = $lector->abierto_hoy();

        $this->assertNull($abierto_hoy, 'Sin la clave `abierto`, el dia se lee como "no se sabe".');
        $this->assertNotFalse($abierto_hoy, '🔴 Una clave que no vino no puede convertirse en "cerrado".');

        $detalle = $lector->cierre_de_hoy_detallado();

        $this->assertEquals('sin_dato', $detalle['motivo'], 'El motivo es "sin_dato", no "cerrado_hoy".');
        $this->assertNull($detalle['cierre'], 'Sin saber si abre, no se da una hora de cierre.');
    }
}
