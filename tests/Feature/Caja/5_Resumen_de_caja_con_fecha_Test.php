<?php

namespace Tests\Feature\Caja;

use App\Models\AperturaCaja;
use App\Models\Caja;
use App\Models\ResumenCaja;
use App\Models\TurnoCaja;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\EmpresaTestCase;

/**
 * Misión destrabar-videos-centro (16/10/2026) — el resumen de caja respeta la FECHA del formulario.
 *
 * `ResumenCajaController::store()` preguntaba `property_exists($request, 'fecha')`, que siempre da
 * falso (los datos del request viven en su bag, no como propiedades): el resumen salía siempre con
 * la fecha de hoy y la franja del turno se buscaba en el día de hoy, así que un resumen de ayer no
 * encontraba la apertura de ayer.
 *
 * La regla de qué cajas entran NO cambia (solo las asignadas al empleado, `Caja.employee_id`, en la
 * sucursal elegida, con una apertura abierta y cerrada dentro de la franja del turno): acá se arma
 * exactamente ese caso, pero AYER.
 *
 * @group caja
 */
class Resumen_de_caja_con_fecha_Test extends EmpresaTestCase
{

    /** Sucursal del escenario: `cajas.address_id` y `resumen_cajas.address_id` son enteros sin FK. */
    const ADDRESS_ID = 987001;

    /**
     * Escenario: un empleado con una caja propia en la sucursal, un turno 08:00-14:00 y una
     * apertura de AYER abierta a las 09:00 y cerrada a las 13:00.
     *
     * @return array [TurnoCaja, User empleado, Caja]
     */
    protected function escenario_de_ayer()
    {
        $dueno_id = auth()->id();

        $empleado = User::create([
            'name'     => 'ZZ Empleada resumen de caja',
            'email'    => 'resumen-caja-empleada-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $dueno_id,
        ]);

        $turno = TurnoCaja::create([
            'name'        => 'ZZ Turno mañana',
            'hora_inicio' => '08:00:00',
            'hora_fin'    => '14:00:00',
            'user_id'     => $dueno_id,
        ]);

        $caja = Caja::create([
            'num'         => 1,
            'name'        => 'ZZ Caja de la empleada',
            'user_id'     => $dueno_id,
            'address_id'  => self::ADDRESS_ID,
            'employee_id' => $empleado->id,
            'saldo'       => 0,
        ]);

        $apertura = AperturaCaja::create([
            'saldo_apertura'       => 1000,
            'saldo_cierre'         => 4500,
            'total_ingresos'       => 4000,
            'total_egresos'        => 500,
            'apertura_employee_id' => $empleado->id,
            'caja_id'              => $caja->id,
        ]);

        $ayer = Carbon::yesterday();
        DB::table('apertura_cajas')->where('id', $apertura->id)->update([
            'created_at' => $ayer->copy()->setTime(9, 0, 0),
            'cerrada_at' => $ayer->copy()->setTime(13, 0, 0),
        ]);

        return [$turno, $empleado, $caja];
    }

    /**
     * Con la fecha de ayer, el resumen queda fechado ayer y toma la apertura de ayer.
     *
     * @test
     */
    public function la_fecha_elegida_se_respeta_y_toma_la_apertura_de_ese_dia()
    {
        list($turno, $empleado, $caja) = $this->escenario_de_ayer();
        $ayer = Carbon::yesterday()->format('Y-m-d');

        $response = $this->postJson('api/resumen-caja', [
            'turno_caja_id' => $turno->id,
            'address_id'    => self::ADDRESS_ID,
            'employee_id'   => $empleado->id,
            'fecha'         => $ayer,
        ]);

        $response->assertStatus(201);

        $resumen = ResumenCaja::find($response->json('model.id'));

        $this->assertSame($ayer, Carbon::parse($resumen->fecha)->format('Y-m-d'), 'El resumen tiene que quedar con la fecha que eligió el usuario, no con la de hoy.');
        $this->assertSame([$caja->id], $resumen->cajas()->pluck('cajas.id')->all(), 'La franja del turno se busca en la fecha elegida: la apertura de ayer tiene que entrar.');
        $this->assertEquals(4000, (float) $resumen->total_ingresos);
        $this->assertEquals(500, (float) $resumen->total_egresos);
    }

    /**
     * Sin fecha (un formulario viejo, o vacía) sigue como siempre: el día de hoy.
     *
     * @test
     */
    public function sin_fecha_sigue_tomando_el_dia_de_hoy()
    {
        list($turno, $empleado) = $this->escenario_de_ayer();

        foreach ([null, ''] as $fecha) {

            $payload = [
                'turno_caja_id' => $turno->id,
                'address_id'    => self::ADDRESS_ID,
                'employee_id'   => $empleado->id,
            ];
            if (!is_null($fecha)) {
                $payload['fecha'] = $fecha;
            }

            $response = $this->postJson('api/resumen-caja', $payload);
            $response->assertStatus(201);

            $resumen = ResumenCaja::find($response->json('model.id'));

            $this->assertSame(Carbon::today()->format('Y-m-d'), Carbon::parse($resumen->fecha)->format('Y-m-d'), 'Sin fecha el resumen es de hoy.');
            $this->assertSame(0, $resumen->cajas()->count(), 'La apertura de ayer no entra en un resumen de hoy.');
        }
    }
}
