<?php

namespace App\Http\Controllers;

use App\Models\PaymentPlan;
use App\Models\Sale;
use App\Services\PaymentPlan\PaymentPlanService;
use Illuminate\Http\Request;
use InvalidArgumentException;

class PaymentPlanController extends Controller
{

    // FROM DATES
    public function index($from_date = null, $until_date = null) {
        $models = PaymentPlan::where('user_id', $this->userId())
                        ->orderBy('created_at', 'DESC')
                        ->withAll();

        if (!is_null($from_date)) {
            if (!is_null($until_date)) {
                $models = $models->whereDate('created_at', '>=', $from_date)
                                ->whereDate('created_at', '<=', $until_date);
            } else {
                $models = $models->whereDate('created_at', $from_date);
            }
        }

        $models = $models->get();
        return response()->json(['models' => $models], 200);
    }

    /**
     * Lo que el modal «Nuevo plan de pago» necesita de la venta para proponer el importe a
     * financiar: total, lo pagado, lo que falta saldar y la moneda (misión
     * planes-de-pago-saldo-y-dolares, 21/10/2026).
     *
     * @param int $sale_id
     * @return \Illuminate\Http\JsonResponse 200 con los datos, 404 si la venta no es del negocio.
     */
    public function saldo_de_venta($sale_id) {
        $sale = $this->venta_del_negocio($sale_id);

        if (is_null($sale)) {
            return response()->json(['message' => 'No se encontró la venta.'], 404);
        }

        return response()->json(app(PaymentPlanService::class)->saldo_de_venta($sale), 200);
    }

    /**
     * Crea el plan de pago y sus cuotas. Los campos que acepta están en
     * PaymentPlanService::crear_plan_y_cuotas().
     *
     * Valida en el back (a diferencia de la regla general del repo) porque un dato malo acá rompe
     * plata: una cantidad de cuotas en 0 hacía una división por cero y un importe negativo armaba
     * cuotas negativas. Los errores vuelven como 422 con `message`, que la SPA muestra tal cual.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse 201 con el plan y sus cuotas; 404 / 422 ante error.
     */
    public function store(Request $request)
    {
        // Solo una venta del propio negocio: antes era un Sale::find() pelado.
        $sale = $this->venta_del_negocio($request->sale_id);

        if (is_null($sale)) {
            return response()->json(['message' => 'No se encontró la venta.'], 404);
        }

        try {
            $plan = app(PaymentPlanService::class)->crear_plan_y_cuotas(
                $request,
                $sale
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['model'    => $plan], 201);
    }

    /**
     * La venta, solo si es del negocio del usuario logueado.
     *
     * @param mixed $sale_id
     * @return \App\Models\Sale|null
     */
    protected function venta_del_negocio($sale_id) {
        if (empty($sale_id)) {
            return null;
        }

        return Sale::where('id', $sale_id)
                    ->where('user_id', $this->userId())
                    ->first();
    }
}
