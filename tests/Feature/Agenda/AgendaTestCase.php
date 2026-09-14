<?php

namespace Tests\Feature\Agenda;

use App\Models\Expense;
use App\Models\Pending;
use App\Models\PendingCompleted;
use App\Models\UnidadFrecuencia;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\Concerns\EscenariosDePlata;
use Tests\EmpresaTestCase;

/**
 * Base de las suites de la Agenda (misión agenda-tareas-calendario, 14/9/2026). Extiende
 * EmpresaTestCase (guards de entorno + actingAs del usuario del fixture) y suma:
 *
 * - `EscenariosDePlata` para resolver cajas, métodos de pago y conceptos del fixture, abrir la
 *   caja de efectivo y limpiar lo que se generó en caja (movimientos, saldos, aperturas).
 * - Helpers para crear tareas y marcarlas por los endpoints REALES (`POST api/pending`,
 *   `POST api/pending-completed`), nunca con `Model::create()`: lo que se prueba es el contrato.
 * - Una limpieza explícita en tearDown de todo lo que la agenda crea (tareas, realizadas y los
 *   gastos que salieron de marcarlas), igual que hace Tesoreria/1_Gasto_Comision_Test.php.
 *
 * ⚠️ El fixture (TestingFerreteriaSeeder) NO siembra `unidad_frecuencias`: se crean con
 * firstOrCreate adentro de la transacción de cada test (ver unidad()).
 */
abstract class AgendaTestCase extends EmpresaTestCase
{
    use EscenariosDePlata;

    /**
     * Ids de las tareas creadas por crear_tarea(), para el cleanup.
     *
     * @var array<int,int>
     */
    protected $tareas_creadas = [];

    /**
     * Libera lo de la agenda y después lo del trait (gastos, movimientos, cajas), antes del
     * rollback de DatabaseTransactions.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->limpiar_agenda();

        $this->limpiar_escenarios();

        parent::tearDown();
    }

    /**
     * Unidad de frecuencia por slug (`day`/`week`/`month`/`year`, los de UnidadFrecuenciaSeeder).
     *
     * @param string $slug
     * @return \App\Models\UnidadFrecuencia
     */
    protected function unidad($slug)
    {
        $nombres = [
            'day'   => 'Dia',
            'week'  => 'Semana',
            'month' => 'Mes',
            'year'  => 'Año',
        ];

        if (!isset($nombres[$slug])) {
            $this->fail('Unidad de frecuencia desconocida: "'.$slug.'".');
        }

        $unidad = UnidadFrecuencia::where('slug', $slug)->first();

        if (!is_null($unidad)) {
            return $unidad;
        }

        // Atributo por atributo: el modelo no declara $guarded/$fillable y create() lo rechaza
        // (el modelo queda fuera del alcance de la misión).
        $unidad = new UnidadFrecuencia();
        $unidad->name = $nombres[$slug];
        $unidad->slug = $slug;
        $unidad->save();

        return $unidad;
    }

    /**
     * Crea una tarea por `POST api/pending` y la devuelve refrescada desde la base. Aborta con el
     * cuerpo completo de la respuesta si el endpoint no devolvió 201.
     *
     * @param array<string,mixed> $overrides Claves del body del contrato §2 a pisar.
     * @return \App\Models\Pending
     */
    protected function crear_tarea(array $overrides = [])
    {
        $payload = array_merge([
            'detalle'               => 'Tarea de test',
            'fecha_realizacion'     => Carbon::today()->format('Y-m-d'),
            'es_recurrente'         => false,
            'unidad_frecuencia_id'  => null,
            'cantidad_frecuencia'   => null,
            'fecha_fin_recurrencia' => null,
            'expense_concept_id'    => null,
            'expense_amount'        => null,
            'notas'                 => null,
        ], $overrides);

        $response = $this->postJson('api/pending', $payload);

        if ($response->getStatusCode() !== 201) {
            $this->fail('POST api/pending devolvió '.$response->getStatusCode().'. Cuerpo: '.$response->getContent());
        }

        $id = $response->json('model.id');

        $this->tareas_creadas[] = $id;

        return Pending::find($id);
    }

    /**
     * Atajo para una tarea recurrente: cada `$cantidad` unidades `$slug` desde `$base`.
     *
     * @param string $slug
     * @param int $cantidad
     * @param string $base Y-m-d
     * @param array<string,mixed> $overrides
     * @return \App\Models\Pending
     */
    protected function crear_tarea_recurrente($slug, $cantidad, $base, array $overrides = [])
    {
        return $this->crear_tarea(array_merge([
            'detalle'               => 'Recurrente cada '.$cantidad.' '.$slug,
            'fecha_realizacion'     => $base,
            'es_recurrente'         => true,
            'unidad_frecuencia_id'  => $this->unidad($slug)->id,
            'cantidad_frecuencia'   => $cantidad,
        ], $overrides));
    }

    /**
     * Tarea con el concepto de gasto operativo del fixture ('Alquiler') y un monto estimado.
     *
     * @param float $monto
     * @param array<string,mixed> $overrides
     * @return \App\Models\Pending
     */
    protected function crear_tarea_con_gasto($monto, array $overrides = [])
    {
        $concepto = $this->resolver_concepto_gasto_por_nombre(TestingFerreteriaSeeder::CONCEPTO_GASTO_OPERATIVO);

        return $this->crear_tarea(array_merge([
            'detalle'               => 'Pagar el alquiler',
            'expense_concept_id'    => $concepto->id,
            'expense_amount'        => $monto,
        ], $overrides));
    }

    /**
     * Bloque `expense` del body de `POST pending-completed` (contrato §2) pagado en efectivo por
     * la caja de efectivo del fixture, que se deja abierta. `payment_methods` tiene exactamente
     * la forma que produce MultiPaymentMethods en la SPA.
     *
     * @param float $monto
     * @param array<string,mixed> $overrides Claves del bloque `expense` a pisar.
     * @return array
     */
    protected function gasto_en_efectivo($monto, array $overrides = [])
    {
        $caja = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_EFECTIVO);

        $this->asegurar_caja_abierta($caja);

        $metodo = $this->resolver_metodo_pago_por_nombre(TestingFerreteriaSeeder::PAGO_EFECTIVO);

        return array_merge([
            'amount'            => $monto,
            'moneda_id'         => 1,
            'importe_iva'       => 0,
            'observations'      => '',
            'payment_methods'   => [
                $this->fila_metodo_de_pago($metodo->id, $monto, $caja->id),
            ],
        ], $overrides);
    }

    /**
     * Una fila de `payment_methods` con todas las claves que manda MultiPaymentMethods.
     *
     * @param int $current_acount_payment_method_id
     * @param float $monto
     * @param int $caja_id
     * @return array
     */
    protected function fila_metodo_de_pago($current_acount_payment_method_id, $monto, $caja_id)
    {
        return [
            'current_acount_payment_method_id'  => $current_acount_payment_method_id,
            'amount'                            => $monto,
            'caja_id'                           => $caja_id,
            'moneda_id'                         => 1,
            'cotizacion'                        => 0,
            'amount_cotizado'                   => 0,
            'cuota_id'                          => 0,
            'bank'                              => '',
            'payment_date'                      => '',
            'num'                               => '',
            'credit_card_id'                    => 0,
            'credit_card_payment_plan_id'       => 0,
        ];
    }

    /**
     * `POST api/pending-completed` para una ocurrencia. Registra los movimientos de caja y el gasto
     * que hayan salido, para que limpiar_escenarios() los borre en el tearDown.
     *
     * @param \App\Models\Pending $pending
     * @param string $fecha Y-m-d de la ocurrencia.
     * @param array<string,mixed> $extra Resto del body (`notas`, `sin_gasto`, `expense`).
     * @return \Illuminate\Testing\TestResponse
     */
    protected function marcar_hecha($pending, $fecha, array $extra = [])
    {
        $antes = $this->max_id_movimiento_caja();

        $response = $this->postJson('api/pending-completed', array_merge([
            'pending_id'        => $pending->id,
            'fecha_realizacion' => $fecha,
        ], $extra));

        $this->registrar_movimientos_caja_nuevos($antes);

        $expense_id = $response->json('expense.id');

        if (!is_null($expense_id)) {
            $this->gastos_creados_por_escenarios[] = $expense_id;
        }

        return $response;
    }

    /**
     * `GET api/pending-agenda/{desde}/{hasta}` decodificado. Aborta si no fue 200.
     *
     * @param string $desde
     * @param string $hasta
     * @return array
     */
    protected function agenda($desde, $hasta)
    {
        $response = $this->getJson('api/pending-agenda/'.$desde.'/'.$hasta);

        if ($response->getStatusCode() !== 200) {
            $this->fail('GET api/pending-agenda devolvió '.$response->getStatusCode().'. Cuerpo: '.$response->getContent());
        }

        return $response->json();
    }

    /**
     * Las ocurrencias de una tarea dentro de un array de Ocurrencia del contrato.
     *
     * @param array $ocurrencias
     * @param \App\Models\Pending $pending
     * @return array
     */
    protected function ocurrencias_de(array $ocurrencias, $pending)
    {
        return array_values(array_filter($ocurrencias, function ($ocurrencia) use ($pending) {
            return $ocurrencia['pending_id'] == $pending->id;
        }));
    }

    /**
     * Solo las fechas (Y-m-d) de un array de Ocurrencia, en el orden en que vinieron.
     *
     * @param array $ocurrencias
     * @return array<int,string>
     */
    protected function fechas_de(array $ocurrencias)
    {
        return array_map(function ($ocurrencia) {
            return $ocurrencia['fecha'];
        }, $ocurrencias);
    }

    /**
     * Borra las realizadas y las tareas que crearon los tests, más los gastos de la agenda que
     * no hayan pasado por marcar_hecha() (por ejemplo los que quedaron por un fallo a mitad de
     * camino). Los movimientos de caja los limpia el trait.
     *
     * @return void
     */
    protected function limpiar_agenda()
    {
        if (count($this->tareas_creadas)) {

            $realizadas = PendingCompleted::whereIn('pending_id', $this->tareas_creadas)->get();

            foreach ($realizadas as $realizada) {

                if (!is_null($realizada->expense_id) && !in_array($realizada->expense_id, $this->gastos_creados_por_escenarios)) {
                    $this->gastos_creados_por_escenarios[] = $realizada->expense_id;
                }

                $realizada->delete();
            }

            Pending::whereIn('id', $this->tareas_creadas)->delete();
        }

        $this->tareas_creadas = [];
    }
}
