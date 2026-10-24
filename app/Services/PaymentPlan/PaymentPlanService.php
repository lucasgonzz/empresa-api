<?php

namespace App\Services\PaymentPlan;

use App\Models\CurrentAcount;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanCuota;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Alta de planes de pago sobre una venta y el cálculo que los arma.
 *
 * Misión planes-de-pago-saldo-y-dolares (21/10/2026), pedido de Lucas:
 *  - el importe a financiar es, por defecto, LO QUE FALTA SALDAR de la venta (no su total) y se
 *    puede cambiar desde el modal;
 *  - el plan y sus cuotas llevan la moneda de la venta (pesos o dólares);
 *  - cada cuota puede llevar su propia nota.
 *
 * 🔴 El reparto (`importes_de_las_cuotas`) y las fechas (`fecha_de_la_cuota`) tienen un ESPEJO en
 * la SPA: `src/components/common/payment-plan/cuotas_del_plan.js` arma la vista previa con el mismo
 * algoritmo. Si se cambia uno, se cambia el otro: si no, el modal muestra cuotas que no son las que
 * se guardan.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly ni enum.
 */
class PaymentPlanService
{
    /** Frecuencias que acepta el plan (las del enum de `payment_plans.frequency`). */
    const FRECUENCIAS = ['monthly', 'weekly', 'biweekly', 'custom_days'];

    /** Tope de cuotas de un plan: cubre de sobra 10 años mensuales y frena un tipeo con ceros de más. */
    const MAXIMO_DE_CUOTAS = 120;

    /** Tope del importe a financiar: lo que entra en `payment_plans.total_amount` (decimal 12,2). */
    const MAXIMO_IMPORTE = 9999999999.99;

    /**
     * Cuánto se pagó y cuánto falta de una venta, en la moneda de la venta.
     *
     * Lo pagado sale del DÉBITO de la venta en cuenta corriente (`current_acounts` con su `sale_id` y
     * `haber` NULL): `pagandose` es lo que ya se imputó a ese débito —pagos, notas de crédito—, así
     * que lo que falta es `debe − pagandose`. Es la misma cuenta que usa
     * CurrentAcountPagoHelper para saldar.
     *
     * Se busca el débito a mano y no por `Sale::current_acount()` (un hasOne sin filtro): una NC
     * dirigida también guarda `sale_id`, y el `first()` del hasOne podría devolverla a ella.
     *
     * Una venta que no va a la cuenta corriente (cobrada en el momento, o sin cliente) no tiene
     * débito: se informa con `en_cuenta_corriente = false` y, desde el 22/10/2026, no admite plan
     * (crear_plan_y_cuotas() la rechaza). El total se devuelve igual para que el modal lo muestre.
     *
     * @param \App\Models\Sale $sale
     * @return array{sale_id: int, total: float, pagado: float, saldo_pendiente: float, moneda_id: int, en_cuenta_corriente: bool, planes_con_cuotas_pendientes: int}
     */
    public function saldo_de_venta($sale): array
    {
        /** Total de la venta, en su moneda. */
        $total = round((float) $sale->total, 2);

        /*
            Débito de la venta en su cuenta corriente, si lo tiene.

            🔴 SIN filtro por `user_id`: CurrentAcountFromSaleHelper::crear_current_acount() crea el
            débito sin esa columna (solo client_id / credit_account_id / employee_id), así que un
            `where('user_id', ...)` no encuentra NUNCA el débito de una venta real. Se agregó el 21/10
            como "defensa extra" y dejaba toda venta como fuera de la cuenta corriente (corregido el
            22/10). No hace falta: la venta ya viene validada como del negocio y `sale_id` es único.
        */
        $debito = CurrentAcount::where('sale_id', $sale->id)
                                ->whereNull('haber')
                                ->whereNotNull('debe')
                                ->orderBy('id')
                                ->first();

        if (is_null($debito)) {
            $pagado = 0;
            $saldo_pendiente = $total;
        } elseif ($debito->status === 'pagado') {
            // Saldada. El camino viejo CurrentAcountHelper::saldarCurrentAcount() la marca
            // 'pagado' sin completar `pagandose`: con la resta sola, una venta ya pagada
            // proponía financiar su total.
            $pagado = round((float) $debito->debe, 2);
            $saldo_pendiente = 0;
        } else {
            $pagado = round((float) $debito->pagandose, 2);
            $saldo_pendiente = round((float) $debito->debe - $pagado, 2);
            // Un sobrepago no deja un saldo negativo para financiar.
            if ($saldo_pendiente < 0) {
                $saldo_pendiente = 0;
            }
        }

        /** Planes previos de la venta que todavía tienen cuotas sin pagar: el modal avisa. */
        $planes_con_cuotas_pendientes = PaymentPlanCuota::where('sale_id', $sale->id)
                                                        ->where('user_id', $sale->user_id)
                                                        ->where('estado', 'pendiente')
                                                        ->distinct()
                                                        ->count('payment_plan_id');

        return [
            'sale_id'                      => (int) $sale->id,
            'total'                        => $total,
            'pagado'                       => $pagado,
            'saldo_pendiente'              => $saldo_pendiente,
            'moneda_id'                    => $this->moneda_de_la_venta($sale),
            'en_cuenta_corriente'          => !is_null($debito),
            'planes_con_cuotas_pendientes' => (int) $planes_con_cuotas_pendientes,
        ];
    }

    /**
     * Crea el plan y sus cuotas.
     *
     * Campos del request (todos los nuevos son OPCIONALES, para que cualquier llamador viejo siga
     * andando):
     *  - cantidad_cuotas (obligatorio), start_date (obligatorio), frequency, interval_in_days, notes;
     *  - total_amount: importe a financiar. Si no viaja, lo que falta saldar de la venta;
     *  - cuotas: [{numero_cuota, notes}] — la nota de cada cuota. Sin nota, la cuota hereda la del plan;
     *  - amount_per_installment: importe fijo por cuota (camino viejo, se respeta).
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Models\Sale $sale
     * @return PaymentPlan El plan con sus cuotas cargadas.
     * @throws InvalidArgumentException Si los datos del plan no permiten armarlo (el controller lo devuelve como 422).
     */
    public function crear_plan_y_cuotas($request, $sale): PaymentPlan
    {
        // Un plan sin cliente no tiene cuenta corriente donde se paguen las cuotas.
        if (empty($sale->client_id)) {
            throw new InvalidArgumentException('La venta no tiene cliente: el plan de pago se arma sobre la cuenta de un cliente.');
        }

        /*
            Regla de Lucas (22/10/2026): un plan de pago financia la DEUDA de una venta, y esa deuda
            existe solo si la venta tiene su débito en la cuenta corriente. Sin débito (venta cobrada
            en el momento, que todavía no entró por esperar la factura, o con la cuenta omitida) no hay
            saldo que financiar y pagar una cuota no tiene contra qué imputarse: PagarBtn abre el pago
            sobre `sale.current_acount.credit_account`. Hasta el 21/10 se permitía y se proponía el total.
        */
        if (!$this->saldo_de_venta($sale)['en_cuenta_corriente']) {
            throw new InvalidArgumentException('La venta no está en la cuenta corriente del cliente: el plan de pago se arma sobre una venta que fue a la cuenta corriente.');
        }

        /** Cantidad de cuotas, ya validada como entero. */
        $cantidad_cuotas = $this->validar_cantidad_de_cuotas($request->cantidad_cuotas);

        $frequency = $request->frequency ?? 'monthly';
        if (!in_array($frequency, self::FRECUENCIAS, true)) {
            throw new InvalidArgumentException('La frecuencia del plan no es válida.');
        }

        $interval_in_days = $request->interval_in_days ?? null;
        if ($frequency === 'custom_days') {
            if (!is_numeric($interval_in_days) || (int) $interval_in_days != $interval_in_days || (int) $interval_in_days < 1) {
                throw new InvalidArgumentException('Indicá cada cuántos días vence una cuota (un número entero).');
            }
            $interval_in_days = (int) $interval_in_days;
        }

        if (empty($request->start_date)) {
            throw new InvalidArgumentException('Indicá la fecha de la primera cuota.');
        }

        // Carbon tira su propia excepción (en inglés) con una fecha que no entiende.
        try {
            $start_date = Carbon::parse($request->start_date)->startOfDay();
        } catch (\Exception $e) {
            throw new InvalidArgumentException('La fecha de la primera cuota no es válida.');
        }

        /** Importe a financiar: el que eligió el usuario o, por defecto, lo que falta saldar. */
        $total_amount = $this->importe_a_financiar($request, $sale);

        // Cada cuota tiene que llevar al menos un centavo: con menos, el reparto deja cuotas en $0.
        if ((int) round($total_amount * 100) < $cantidad_cuotas) {
            throw new InvalidArgumentException('El importe a financiar no alcanza para '.$cantidad_cuotas.' cuotas.');
        }

        if (!empty($request->amount_per_installment) && (!is_numeric($request->amount_per_installment) || (float) $request->amount_per_installment <= 0)) {
            throw new InvalidArgumentException('El importe por cuota tiene que ser mayor a cero.');
        }

        /** Moneda del plan y de sus cuotas: la de la venta. */
        $moneda_id = $this->moneda_de_la_venta($sale);

        /** Nota de cada cuota que mandó el modal, indexada por número de cuota. */
        $notas_por_cuota = $this->notas_por_cuota($request->cuotas);

        // Transacción: el plan sin sus cuotas (o con la mitad) no debe quedar nunca guardado.
        return DB::transaction(function () use ($request, $sale, $cantidad_cuotas, $frequency, $interval_in_days, $start_date, $total_amount, $moneda_id, $notas_por_cuota) {

            $plan = PaymentPlan::create([
                'sale_id'          => $sale->id,
                'client_id'        => $sale->client_id,
                'cantidad_cuotas'  => $cantidad_cuotas,
                'total_amount'     => $total_amount,
                'moneda_id'        => $moneda_id,
                'frequency'        => $frequency,
                'interval_in_days' => $interval_in_days,
                'start_date'       => $start_date,
                'interest_percent' => $request->interest_percent ?? 0,
                'notes'            => $request->notes ?? null,
                'user_id'          => $sale->user_id,
            ]);

            /** Importe de cada cuota: fijo si viajó `amount_per_installment`, si no el reparto exacto. */
            if (!empty($request->amount_per_installment)) {
                $importes = array_fill(0, $cantidad_cuotas, round((float) $request->amount_per_installment, 2));
            } else {
                $importes = self::importes_de_las_cuotas($total_amount, $cantidad_cuotas);
            }

            for ($numero_cuota = 1; $numero_cuota <= $cantidad_cuotas; $numero_cuota++) {

                /** Nota propia de la cuota; vacía, hereda la del plan (lo que pasaba siempre). */
                $nota = isset($notas_por_cuota[$numero_cuota]) ? $notas_por_cuota[$numero_cuota] : $plan->notes;

                PaymentPlanCuota::create([
                    'payment_plan_id'   => $plan->id,
                    'numero_cuota'      => $numero_cuota,
                    'fecha_vencimiento' => self::fecha_de_la_cuota($plan->start_date, $numero_cuota, $frequency, $interval_in_days)->toDateString(),
                    'amount'            => $importes[$numero_cuota - 1],
                    'moneda_id'         => $moneda_id,
                    'estado'            => 'pendiente',
                    'sale_id'           => $sale->id,
                    'client_id'         => $sale->client_id,
                    'user_id'           => $sale->user_id,
                    'observations'      => $nota,
                ]);
            }

            return $plan->load('cuotas');
        });
    }

    /**
     * Reparte un importe en cuotas iguales redondeadas a centavos; la ÚLTIMA absorbe la diferencia
     * del redondeo para que la suma dé exacto el importe (1.000.000 en 3 → 333.333,33 ×2 y 333.333,34).
     *
     * Antes cada cuota era `round(total / n, 2)` y la suma podía quedar un centavo corta o larga.
     * Espejo en la SPA: `importes_de_las_cuotas()` de cuotas_del_plan.js.
     *
     * Se trabaja en centavos enteros para que el float no corra la última cuota, y se redondea
     * HACIA ABAJO: con un redondeo común, muchas cuotas sobre un importe chico (1 peso en 120)
     * daban 1 centavo cada una y la última quedaba negativa. Con el piso la última es siempre igual
     * o apenas mayor que las demás.
     *
     * @param float $total Importe a financiar.
     * @param int $cantidad_cuotas Mayor o igual a 1.
     * @return float[] Un importe por cuota, en orden.
     */
    public static function importes_de_las_cuotas($total, $cantidad_cuotas): array
    {
        /** Importe total en centavos. */
        $total_en_centavos = (int) round(((float) $total) * 100);

        /** Centavos de cada cuota menos la última. */
        $centavos_por_cuota = (int) floor($total_en_centavos / $cantidad_cuotas);

        $importes = [];
        for ($i = 1; $i < $cantidad_cuotas; $i++) {
            $importes[] = $centavos_por_cuota / 100;
        }

        // La última se queda con lo que falta para llegar al total.
        $importes[] = ($total_en_centavos - $centavos_por_cuota * ($cantidad_cuotas - 1)) / 100;

        return $importes;
    }

    /**
     * Vencimiento de la cuota N, contado SIEMPRE desde la fecha de inicio y no encadenado sobre la
     * cuota anterior.
     *
     * La diferencia se ve en los meses cortos: con el cálculo encadenado (como era antes), un plan
     * que arranca el 31/1 vencía 28/2 y después 28/3, 28/4... para siempre. Contado desde el inicio
     * vence 31/1, 28/2, 31/3, 30/4: siempre el mismo día del mes, salvo cuando el mes no lo tiene.
     *
     * Espejo en la SPA: `fecha_de_la_cuota()` de cuotas_del_plan.js.
     *
     * @param \Carbon\Carbon $start_date Fecha de la primera cuota.
     * @param int $numero_cuota Desde 1.
     * @param string $frequency monthly | weekly | biweekly | custom_days.
     * @param int|null $interval_in_days Solo para custom_days.
     * @return \Carbon\Carbon
     */
    public static function fecha_de_la_cuota($start_date, $numero_cuota, $frequency, $interval_in_days = null)
    {
        /** Períodos que pasan desde la primera cuota. */
        $periodos = $numero_cuota - 1;

        $fecha = Carbon::parse($start_date)->startOfDay();

        if ($frequency === 'weekly') {
            return $fecha->addWeeks($periodos);
        }
        if ($frequency === 'biweekly') {
            return $fecha->addDays(15 * $periodos);
        }
        if ($frequency === 'custom_days') {
            return $fecha->addDays(((int) $interval_in_days) * $periodos);
        }

        return $fecha->addMonthsNoOverflow($periodos);
    }

    /**
     * Moneda de la venta para el plan: 2 si la venta es en dólares, 1 en cualquier otro caso
     * (NULL, 0 y 1 son pesos: regla de Sale::EXPRESION_EN_PESOS).
     *
     * @param \App\Models\Sale $sale
     * @return int
     */
    protected function moneda_de_la_venta($sale): int
    {
        return ((int) $sale->moneda_id) === 2 ? 2 : 1;
    }

    /**
     * Valida la cantidad de cuotas: un entero entre 1 y MAXIMO_DE_CUOTAS.
     *
     * Se valida acá y no solo en la SPA porque un 0 hacía una división por cero en el reparto.
     *
     * @param mixed $cantidad_cuotas
     * @return int
     * @throws InvalidArgumentException
     */
    protected function validar_cantidad_de_cuotas($cantidad_cuotas): int
    {
        if (!is_numeric($cantidad_cuotas) || (int) $cantidad_cuotas != $cantidad_cuotas) {
            throw new InvalidArgumentException('La cantidad de cuotas tiene que ser un número entero.');
        }

        $cantidad_cuotas = (int) $cantidad_cuotas;

        if ($cantidad_cuotas < 1 || $cantidad_cuotas > self::MAXIMO_DE_CUOTAS) {
            throw new InvalidArgumentException('La cantidad de cuotas tiene que estar entre 1 y '.self::MAXIMO_DE_CUOTAS.'.');
        }

        return $cantidad_cuotas;
    }

    /**
     * Importe a financiar del plan.
     *
     * Si el request trae `total_amount`, ese (tiene que ser mayor a cero). Si no, lo que falta
     * saldar de la venta — el mismo valor que el modal propone por defecto. Un saldo en cero sin
     * importe explícito no arma un plan de cuotas en cero.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Models\Sale $sale
     * @return float
     * @throws InvalidArgumentException
     */
    protected function importe_a_financiar($request, $sale): float
    {
        if (isset($request->total_amount) && $request->total_amount !== '') {

            if (!is_numeric($request->total_amount)) {
                throw new InvalidArgumentException('El importe a financiar tiene que ser un número.');
            }

            // Se valida DESPUÉS de redondear a centavos: 0,004 pasaba el "> 0" y armaba un plan en $0.
            $importe = round((float) $request->total_amount, 2);

            if ($importe <= 0) {
                throw new InvalidArgumentException('El importe a financiar tiene que ser mayor a cero.');
            }
            if ($importe > self::MAXIMO_IMPORTE) {
                throw new InvalidArgumentException('El importe a financiar es demasiado grande.');
            }

            return $importe;
        }

        /** Lo que falta saldar de la venta. */
        $saldo_pendiente = $this->saldo_de_venta($sale)['saldo_pendiente'];

        if ($saldo_pendiente <= 0) {
            throw new InvalidArgumentException('La venta no tiene saldo pendiente para financiar.');
        }

        return $saldo_pendiente;
    }

    /**
     * Notas de cada cuota que mandó el modal, indexadas por número de cuota. Las vacías no entran:
     * esa cuota hereda la nota del plan.
     *
     * @param mixed $cuotas [{numero_cuota, notes}] o null.
     * @return array<int, string>
     */
    protected function notas_por_cuota($cuotas): array
    {
        $notas = [];

        if (!is_array($cuotas)) {
            return $notas;
        }

        foreach ($cuotas as $cuota) {
            if (!is_array($cuota) || !isset($cuota['numero_cuota'])) {
                continue;
            }

            /** Nota de la cuota sin espacios de los costados (lo que no es texto se ignora). */
            $nota = isset($cuota['notes']) && is_scalar($cuota['notes']) ? trim((string) $cuota['notes']) : '';

            if ($nota !== '') {
                $notas[(int) $cuota['numero_cuota']] = $nota;
            }
        }

        return $notas;
    }
}
