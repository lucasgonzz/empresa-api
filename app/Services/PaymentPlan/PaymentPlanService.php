<?php

namespace App\Services\PaymentPlan;

use App\Models\PaymentPlan;
use App\Models\PaymentPlanCuota;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PaymentPlanService
{
    public function crear_plan_y_cuotas($request, $sale): PaymentPlan
    {
        // Usamos transacción para asegurar consistencia (ver explicación abajo)
        return DB::transaction(function () use ($request, $sale){
            $frequency = $request->frequency ?? 'monthly';
            $interval_in_days = $request->interval_in_days ?? null;

            if ($frequency === 'custom_days' && empty($interval_in_days)) {
                throw new InvalidArgumentException('interval_in_days es requerido cuando frequency=custom_days');
            }

            $total_amount = $sale->total;

            $plan = PaymentPlan::create([
                'sale_id' => $request->sale_id,
                'client_id' => $sale->client_id,
                'cantidad_cuotas' => (int) $request->cantidad_cuotas,
                'total_amount' => $total_amount,
                'frequency' => $frequency,
                'interval_in_days' => $interval_in_days,
                'start_date' => Carbon::parse($request->start_date)->startOfDay(),
                'interest_percent' => $request->interest_percent ?? 0,
                'notes' => $request->notes ?? null,
                'user_id'   => $sale->user_id,
            ]);

            // Monto por cuota
            $monto_por_cuota = null;
            if (!empty($request->amount_per_installment)) {
                $monto_por_cuota = (float) $request->amount_per_installment;
            } elseif (!empty($total_amount)) {
                $monto_por_cuota = round(((float)$total_amount) / (int)$request->cantidad_cuotas, 2);
            }

            $fecha = $plan->start_date->copy();
            for ($i = 1; $i <= $plan->cantidad_cuotas; $i++) {
                PaymentPlanCuota::create([
                    'payment_plan_id'  => $plan->id,
                    'numero_cuota'     => $i,
                    'fecha_vencimiento'=> $fecha->toDateString(),
                    'amount'           => $monto_por_cuota ?? 0,
                    'estado'           => 'pendiente',
                    'sale_id'          => $sale->id,
                    'client_id'        => $sale->client_id,
                    'user_id'          => $sale->user_id,
                    'observations'     => $plan->notes,
                ]);

                // siguiente fecha
                if ($frequency === 'monthly') {
                    $fecha->addMonthNoOverflow();
                } elseif ($frequency === 'weekly') {
                    $fecha->addWeek();
                } elseif ($frequency === 'biweekly') {
                    $fecha->addDays(15);
                } elseif ($frequency === 'custom_days') {
                    $fecha->addDays($interval_in_days);
                }
            }

            return $plan->load('cuotas');
        });
    }
}
