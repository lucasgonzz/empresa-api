<?php

namespace Tests\Feature\Semilla;

use App\Console\Commands\SembrarDatosDePrueba;
use App\Models\AperturaCaja;
use App\Models\Caja;
use App\Models\MovimientoCaja;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use ReflectionMethod;
use ReflectionProperty;
use Tests\EmpresaTestCase;

/**
 * Misión M9 (demo-setup-mas-rapido, 13/10/2026): `semilla:datos` abría las 10 cajas del usuario
 * todos los días y las cerraba todas las noches -- 740 aperturas y 730 cierres para los 74 días de
 * la siembra de tres meses --, cuando solo **249** pares caja-día tienen un movimiento. Dos tercios
 * de esas aperturas nacían y morían vacías.
 *
 * 🔴 EL INVARIANTE QUE ESTE ARCHIVO CUIDA, y que es la única razón por la que el recorte es
 * delicado: **todo `MovimientoCaja` tiene que caer en una apertura ABIERTA de SU caja**.
 * `MovimientoCajaHelper::get_current_aperutra_caja()` resuelve la última `AperturaCaja` de esa caja
 * por `created_at DESC` y no acepta que le pasen una desde afuera, así que el movimiento de una caja
 * que hoy no está abierta se cuelga de una apertura ya cerrada. Eso no tira ninguna excepción: deja
 * el dato mal.
 *
 * El mecanismo que se construyó no adivina qué caja se va a mover: deja abierta la que no se movió
 * (con su apertura vacía esperando) y cierra solo la que tiene movimientos. Este archivo prueba las
 * cuatro propiedades de ese mecanismo con tres cajas y movimientos puestos a mano.
 *
 * Por qué no se corre `semilla:datos` de verdad: la corrida de tres meses tarda ~70 segundos y
 * siembra 469 operaciones por el camino real de los controllers, que no entra en una suite que
 * gobierna merges (mismo criterio que `AlineacionLocalDemoTest`, que tampoco corre
 * `DemoSetupHelper::run()`). Lo que se prueba acá son los dos métodos que deciden, invocados
 * directo. El invariante sobre la corrida COMPLETA se verificó con SQL contra la base
 * `empresa_demo_m9` el 13/10/2026 y el resultado está en el informe de la misión.
 *
 * @group semilla
 */
class AperturasDeCajaDeLaSemillaTest extends EmpresaTestCase
{
    /** @var \App\Console\Commands\SembrarDatosDePrueba */
    private $comando;

    /** @var \Illuminate\Support\Collection<int,\App\Models\Caja> */
    private $cajas;

    /** @var \Carbon\Carbon */
    private $dia;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        /*
            Tres cajas propias de este test, no las del fixture: así los conteos son exactos y no
            dependen de cuántas cajas tenga la base del slot. Arrancan CERRADAS, que es el estado en
            el que `preparar_aperturas_previas()` deja a todas antes del primer día sembrado.
        */
        $this->cajas = collect([]);

        for ($i = 1; $i <= 3; $i++) {
            $this->cajas->push(Caja::create([
                'num'                      => 900 + $i,
                'name'                     => 'zz Caja M9 ' . $i,
                'abierta'                  => 0,
                'saldo'                    => 0,
                'current_apertura_caja_id' => null,
                'user_id'                  => $user->id,
            ]));
        }

        $this->comando = new SembrarDatosDePrueba();

        /* `user_id` es protected y lo setea `handle()`, que acá no corre. */
        $propiedad = new ReflectionProperty(SembrarDatosDePrueba::class, 'user_id');
        $propiedad->setAccessible(true);
        $propiedad->setValue($this->comando, $user->id);

        $this->dia = Carbon::parse('2026-03-02');
    }

    /**
     * Invoca uno de los dos métodos protegidos del comando.
     *
     * @param string $metodo
     * @param \Carbon\Carbon $dia
     * @return void
     */
    private function invocar($metodo, Carbon $dia)
    {
        $reflejo = new ReflectionMethod(SembrarDatosDePrueba::class, $metodo);
        $reflejo->setAccessible(true);
        $reflejo->invokeArgs($this->comando, [$this->cajas, $dia]);
    }

    /**
     * Estado fresco de las tres cajas, indexado por id.
     *
     * @return array<int,int|null> caja_id => current_apertura_caja_id
     */
    private function estado()
    {
        return Caja::whereIn('id', $this->cajas->pluck('id')->all())
            ->orderBy('id')
            ->pluck('current_apertura_caja_id', 'id')
            ->all();
    }

    /** @return int */
    private function aperturas_de_mis_cajas()
    {
        return AperturaCaja::whereIn('caja_id', $this->cajas->pluck('id')->all())->count();
    }

    /**
     * Un movimiento colgado de la apertura que esa caja tiene abierta ahora, como lo haría el camino
     * real (que resuelve la apertura y escribe el movimiento adentro de los helpers de caja).
     *
     * @param \App\Models\Caja $caja
     * @return void
     */
    private function mover(Caja $caja)
    {
        $fresca = Caja::find($caja->id);

        $this->assertNotNull(
            $fresca->current_apertura_caja_id,
            'El test quiso mover una caja cerrada: eso es justamente lo que no puede pasar nunca.'
        );

        MovimientoCaja::create([
            'ingreso'          => 1000,
            'egreso'           => 0,
            'saldo'            => 1000,
            'apertura_caja_id' => $fresca->current_apertura_caja_id,
            'caja_id'          => $fresca->id,
        ]);
    }

    /**
     * Test 1 — el primer día se abren TODAS las cajas, porque todas están cerradas.
     *
     * @return void
     */
    public function test_el_primer_dia_se_abren_todas_las_cajas()
    {
        $this->invocar('abrir_cajas_del_dia', $this->dia);

        $this->assertSame(3, $this->aperturas_de_mis_cajas());

        foreach ($this->estado() as $caja_id => $apertura_id) {
            $this->assertNotNull(
                $apertura_id,
                'La caja ' . $caja_id . ' quedó sin apertura el primer día: cualquier movimiento '
                    . 'suyo se colgaría de la nada.'
            );
        }
    }

    /**
     * Test 2 — a la noche se cierra SOLO la caja que tuvo movimientos; las otras quedan abiertas con
     * la misma apertura.
     *
     * @return void
     */
    public function test_a_la_noche_se_cierra_solo_la_caja_que_se_movio()
    {
        $this->invocar('abrir_cajas_del_dia', $this->dia);

        $antes = $this->estado();

        $this->mover($this->cajas[0]);

        $this->invocar('cerrar_cajas_del_dia', $this->dia);

        $despues = $this->estado();

        $this->assertNull(
            $despues[$this->cajas[0]->id],
            'La caja que se movió tiene que quedar cerrada.'
        );

        foreach ([1, 2] as $indice) {
            $id = $this->cajas[$indice]->id;

            $this->assertNotNull(
                $despues[$id],
                'La caja que NO se movió tiene que quedar abierta: su apertura vacía es la que va a '
                    . 'recibir su primer movimiento el día que finalmente se mueva.'
            );

            $this->assertSame(
                $antes[$id],
                $despues[$id],
                'Y tiene que ser LA MISMA apertura, no una nueva.'
            );
        }

        $this->assertSame(
            3,
            $this->aperturas_de_mis_cajas(),
            'Cerrar no crea aperturas.'
        );

        /* La apertura cerrada quedó efectivamente cerrada. */
        $cerrada = AperturaCaja::where('caja_id', $this->cajas[0]->id)->first();

        $this->assertNotNull($cerrada->cerrada_at, 'La apertura de la caja que se movió quedó sin cerrar.');
    }

    /**
     * Test 3 — al día siguiente se abre SOLO la que quedó cerrada. Es el corazón del recorte: tres
     * días con una sola caja moviéndose dan 3 aperturas más la primera de cada caja, no 9.
     *
     * @return void
     */
    public function test_al_dia_siguiente_se_abre_solo_la_que_quedo_cerrada()
    {
        $this->invocar('abrir_cajas_del_dia', $this->dia);
        $this->mover($this->cajas[0]);
        $this->invocar('cerrar_cajas_del_dia', $this->dia);

        $antes = $this->estado();

        $segundo_dia = $this->dia->copy()->addDay();

        $this->invocar('abrir_cajas_del_dia', $segundo_dia);

        $despues = $this->estado();

        $this->assertSame(
            4,
            $this->aperturas_de_mis_cajas(),
            'El segundo día tiene que agregar UNA sola apertura (la de la caja que se cerró anoche), '
                . 'no tres. Con el comportamiento viejo serían 6.'
        );

        $this->assertNotNull($despues[$this->cajas[0]->id], 'La caja cerrada tiene que reabrirse.');

        foreach ([1, 2] as $indice) {
            $id = $this->cajas[$indice]->id;

            $this->assertSame(
                $antes[$id],
                $despues[$id],
                'La caja que ya estaba abierta no puede recibir una apertura nueva: tendría dos '
                    . 'aperturas abiertas y `get_current_aperutra_caja()` resolvería la más nueva, '
                    . 'dejando la vieja abierta para siempre.'
            );
        }
    }

    /**
     * Test 4 — una caja que se mueve DESPUÉS de varios días sin moverse cuelga su movimiento de una
     * apertura ABIERTA, y esa apertura se cierra esa misma noche.
     *
     * 🔴 Es el test que cubre el modo de falla de este cambio. Si el mecanismo dejara la caja
     * cerrada los días que no se mueve, el movimiento del día 3 se colgaría de la apertura cerrada
     * del día 1 y nadie se enteraría. Y si la apertura que espera no se cerrara la noche en que
     * finalmente se mueve, iría acumulando los movimientos de todos los días siguientes --
     * `set_apertura_caja_ingresos_egresos()` recorre TODOS los movimientos de la apertura en CADA
     * movimiento nuevo, así que eso es O(n²) y sale más lento que el estado anterior.
     *
     * @return void
     */
    public function test_la_caja_que_espera_recibe_su_movimiento_en_una_apertura_abierta_y_se_cierra_esa_noche()
    {
        $this->invocar('abrir_cajas_del_dia', $this->dia);

        $dormida = $this->cajas[2];

        $apertura_original = Caja::find($dormida->id)->current_apertura_caja_id;

        /* Dos días en los que se mueve otra caja y la tercera no. */
        for ($i = 0; $i < 2; $i++) {
            $dia = $this->dia->copy()->addDays($i);

            $this->invocar('abrir_cajas_del_dia', $dia);
            $this->mover($this->cajas[0]);
            $this->invocar('cerrar_cajas_del_dia', $dia);
        }

        $this->assertSame(
            $apertura_original,
            Caja::find($dormida->id)->current_apertura_caja_id,
            'La caja que no se movió tiene que seguir con la misma apertura abierta.'
        );

        /* Tercer día: ahora sí se mueve. */
        $tercer_dia = $this->dia->copy()->addDays(2);

        $this->invocar('abrir_cajas_del_dia', $tercer_dia);

        $this->mover($dormida);

        $movimiento = MovimientoCaja::where('caja_id', $dormida->id)->first();

        $this->assertSame(
            (int) $apertura_original,
            (int) $movimiento->apertura_caja_id,
            'El movimiento tiene que haber caído en la apertura que venía esperando abierta.'
        );

        $apertura = AperturaCaja::find($apertura_original);

        $this->assertNull(
            $apertura->cerrada_at,
            'Esa apertura tenía que estar ABIERTA cuando llegó el movimiento.'
        );

        $this->invocar('cerrar_cajas_del_dia', $tercer_dia);

        $this->assertNull(
            Caja::find($dormida->id)->current_apertura_caja_id,
            'La noche en que finalmente se movió, la caja tiene que cerrarse: si no, su apertura '
                . 'sigue juntando los movimientos de todos los días que vienen.'
        );

        $this->assertNotNull(
            AperturaCaja::find($apertura_original)->cerrada_at,
            'Y la apertura tiene que quedar cerrada.'
        );
    }

    /**
     * Test 5 — los contadores que informan la planilla y el cronómetro cuentan lo que de verdad
     * pasó.
     *
     * `resumen_aperturas.sembradas` era `dias * cajas`, una multiplicación. Con este cambio esa
     * cuenta informaría casi el triple de las aperturas que existen, y la planilla de control es lo
     * que Lucas usa para comparar una corrida con otra.
     *
     * @return void
     */
    public function test_los_contadores_cuentan_las_aperturas_reales()
    {
        $this->invocar('abrir_cajas_del_dia', $this->dia);
        $this->mover($this->cajas[0]);
        $this->invocar('cerrar_cajas_del_dia', $this->dia);
        $this->invocar('abrir_cajas_del_dia', $this->dia->copy()->addDay());

        $propiedad = new ReflectionProperty(SembrarDatosDePrueba::class, 'costo_de_las_cajas');
        $propiedad->setAccessible(true);

        $costo = $propiedad->getValue($this->comando);

        $this->assertSame(4, $costo['aperturas'], '3 del primer día más 1 del segundo.');
        $this->assertSame(1, $costo['cierres'], 'Se cerró una sola caja.');
        $this->assertGreaterThan(0, $costo['segundos'], 'El cronómetro de las cajas tiene que medir algo.');
    }
}
