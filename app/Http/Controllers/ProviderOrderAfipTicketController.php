<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\providerOrder\FacturaDeCompraHelper;
use App\Models\ProviderOrderAfipTicket;
use Illuminate\Http\Request;
use Carbon\Carbon;

class ProviderOrderAfipTicketController extends Controller
{

    public function index() {
        $models = ProviderOrderAfipTicket::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $issued_at = $request->issued_at;
        if ($issued_at == '') {
            $issued_at = Carbon::now();
        }

        /*
         * Misión `compras-factura-manual-alicuotas` (17/9/2026), dos cambios en este array:
         *
         * 1. `total` NO se toma del request. Lo calcula el servidor, abajo, en
         *    `FacturaDeCompraHelper::guardar_totales()` — el porqué está escrito ahí.
         * 2. `retencion_iibb`, `retencion_iva` y `retencion_ganancias` se fueron. Una factura de
         *    COMPRA no trae retenciones: quien retiene es tu cliente cuando te paga, no el
         *    proveedor cuando te factura, así que ese dato se carga al registrar un cobro en la
         *    cuenta corriente del cliente. Las columnas siguen en la tabla, sin borrar, hasta que
         *    se migre lo que ya está cargado.
         */
        $model = ProviderOrderAfipTicket::create([
            // 'num'                   => $this->num('provider_order_afip_tickets'),
            'code'                  => $request->code,
            'issued_at'             => $issued_at,
            // 'total_iva'             => $request->total_iva,
            'percepcion_iibb'       => $request->percepcion_iibb,
            'percepcion_iva'        => $request->percepcion_iva,
            'provider_order_id'     => $request->model_id,
            'temporal_id'           => $this->getTemporalId($request),
            'user_id'               => $this->userId(),
        ]);

        $this->updateRelationsCreated('provider_order_afip_ticket', $model->id, $request->childrens);

        if (!is_null($request->model_id)) {
            $this->sendAddModelNotification('provider_order', $request->model_id);
        }

        FacturaDeCompraHelper::guardar_totales($model);

        FacturaDeCompraHelper::recalcular_compra($model->provider_order_id);

        return response()->json(['model' => $this->fullModel('ProviderOrderAfipTicket', $model->id)], 201);
    }

    public function show($id) {
        return response()->json(['model' => $this->fullModel('ProviderOrderAfipTicket', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = ProviderOrderAfipTicket::find($id);
        $model->code                  = $request->code;
        $model->issued_at             = $request->issued_at;
        // $model->total_iva             = $request->total_iva;

        $model->percepcion_iibb       = $request->percepcion_iibb;
        $model->percepcion_iva        = $request->percepcion_iva;
        /*
         * `total` no se asigna: lo calcula el servidor en `guardar_totales()`. Y las retenciones
         * salieron de la factura de compra — el porqué de las dos cosas está en store().
         */
        $model->provider_order_id     = $this->get_model_id($request, 'provider_order_id');
        $model->save();
        $this->sendAddModelNotification('provider_order_afip_ticket', $model->id);

        FacturaDeCompraHelper::guardar_totales($model);

        FacturaDeCompraHelper::recalcular_compra($model->provider_order_id);

        return response()->json(['model' => $this->fullModel('ProviderOrderAfipTicket', $model->id)], 200);
    }

    public function destroy($id) {
        $model = ProviderOrderAfipTicket::find($id);

        /*
         * Se guarda ANTES del delete: el recálculo tiene que correr sobre la compra ya sin esta
         * factura, y después de borrar la fila no hay de dónde volver a leer a qué compra colgaba.
         */
        $provider_order_id = $model->provider_order_id;

        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('provider_order_afip_ticket', $model->id);

        FacturaDeCompraHelper::recalcular_compra($provider_order_id);

        return response(null);
    }

    /**
     * Suma el `iva_importe` de las alícuotas de la factura (y, desde la misión
     * `compras-factura-manual-alicuotas`, también su `total`).
     *
     * Queda como envoltorio de `FacturaDeCompraHelper::guardar_totales()` para no dejar sin salida
     * a ningún llamador viejo: era un método público de este controller.
     *
     * @param  \App\Models\ProviderOrderAfipTicket  $model
     * @return void
     */
    function set_total_iva($model) {
        FacturaDeCompraHelper::guardar_totales($model);
    }
}
