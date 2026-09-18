<?php

namespace Tests\Feature\Sales;

use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * La tira de dias de la semana (`GET api/previus-day/{model}/{index}/{date_param?}`) ya no baja las
 * filas completas de los 7 dias: cada dia trae `cantidad` (int) y `models` con SOLO el id de cada
 * fila.
 *
 * `models` se mantiene porque la SPA vieja lee `day.models.length`; `cantidad` es lo que lee la
 * nueva. Si `models` desaparece o deja de tener el mismo largo, la tira de dias de todos los
 * modulos fechados (ventas, compras, presupuestos, gastos...) muestra cero movimientos.
 */
class Previus_Day_Liviano_Test extends TestCase
{
    // DatabaseTransactions (no RefreshDatabase): la base de testing esta sembrada y compartida por
    // el slot. Mismo criterio que 18_Fecha_De_Pedido_En_Reportes_Test.
    use DatabaseTransactions;

    public $user_id = 500;

    /**
     * Con ventas de hoy, el dia de hoy las cuenta en `cantidad` y las lista en `models` con solo la
     * clave `id` en cada elemento; los demas dias vienen con la misma forma.
     *
     * Se compara con `>=` y por contencion, no con un igual exacto: la base del slot arrastra ventas
     * de hoy que dejan tests preexistentes sin transaccion (13_Costo_dividido... y 14_Costo_dividido...
     * postean a `api/sale` y no limpian), y este test no puede depender de cuantas quedaron.
     *
     * @group sales
     * @test
     */
    public function cada_dia_trae_cantidad_y_solo_los_ids()
    {
        $this->actingAs(User::find($this->user_id), 'web');

        /* Mediodia de hoy: bien adentro del dia, lejos de cualquier borde de medianoche. */
        $hoy = Carbon::now()->setTime(12, 0, 0);

        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = Sale::create([
                'user_id'      => $this->user_id,
                'address_id'   => 1,
                'moneda_id'    => 1,
                'total'        => 100,
                'sub_total'    => 100,
                'terminada'    => 1,
                'confirmed'    => 1,
                'created_at'   => $hoy->format('Y-m-d H:i:s'),
                'terminada_at' => $hoy->format('Y-m-d H:i:s'),
            ])->id;
        }

        $response = $this->getJson('api/previus-day/sale/0/created_at');
        $response->assertStatus(200);

        $days = $response->json('days');
        $this->assertCount(7, $days, 'La semana en curso tiene siete dias.');

        $dia_de_hoy = null;

        foreach ($days as $day) {
            $this->assertArrayHasKey('date', $day);
            $this->assertArrayHasKey('models', $day);
            $this->assertArrayHasKey('cantidad', $day, 'Cada dia tiene que traer cantidad.');
            $this->assertIsInt($day['cantidad']);
            $this->assertSame(count($day['models']), $day['cantidad'],
                'cantidad y models.length tienen que decir lo mismo: la SPA vieja lee uno y la nueva el otro.');

            foreach ($day['models'] as $model) {
                $this->assertSame(['id'], array_keys($model),
                    'Cada elemento de models tiene que traer SOLO el id: la tira no usa nada mas.');
            }

            if (substr($day['date'], 0, 10) === $hoy->format('Y-m-d')) {
                $dia_de_hoy = $day;
            }
        }

        $this->assertNotNull($dia_de_hoy, 'El dia de hoy tiene que estar en la tira de la semana en curso.');
        $this->assertGreaterThanOrEqual(3, $dia_de_hoy['cantidad']);

        $ids_de_hoy = array_column($dia_de_hoy['models'], 'id');
        foreach ($ids as $id) {
            $this->assertContains($id, $ids_de_hoy, 'Cada venta creada hoy tiene que estar entre los ids del dia.');
        }

        /* Y lo que dice la tira es lo que hay en la base para ese dia, ni una mas ni una menos. */
        $inicio_del_dia = $hoy->copy()->startOfDay();
        $en_la_base = Sale::where('user_id', $this->user_id)
                        ->whereBetween('created_at', [
                            $inicio_del_dia->format('Y-m-d H:i:s'),
                            $inicio_del_dia->copy()->addDay()->format('Y-m-d H:i:s'),
                        ])
                        ->count();
        $this->assertSame($en_la_base, $dia_de_hoy['cantidad']);
    }
}
