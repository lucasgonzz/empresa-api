<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\providerOrder\FacturaDeCompraHelper;
use App\Models\ProviderOrderAfipTicket;
use App\Models\ProviderOrderAfipTicketIva;
use Illuminate\Http\Request;

class ProviderOrderAfipTicketIvaController extends Controller
{

    /**
     * Lo que se le contesta al que intenta tocar las alícuotas de una factura que calcula el
     * sistema. Dice cómo salir: el modo de facturación vive en la compra, no en la factura, y
     * pasarlo a Manual es un clic.
     */
    const MENSAJE_MODO_AUTOMATICO = 'Las alícuotas de IVA de esta factura las calcula el sistema a '.
                                    'partir de los artículos, porque la compra está en modo de '.
                                    'facturación Automático. Para editarlas a mano, cambiá el modo '.
                                    'de facturación de la compra a Manual.';

    public function index() {
        $models = ProviderOrderAfipTicketIva::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {

        if (FacturaDeCompraHelper::factura_en_modo_automatico($request->model_id)) {
            return response()->json(['message' => self::MENSAJE_MODO_AUTOMATICO], 422);
        }

        $model = ProviderOrderAfipTicketIva::create([
            'provider_order_afip_ticket_id'     => $request->model_id,
            'iva_id'                            => $request->iva_id,
            'neto'                              => $request->neto,
            'iva_importe'                       => $request->iva_importe,
            'temporal_id'                       => $this->getTemporalId($request),
            // 'user_id'                           => $this->userId(),
        ]);
        $this->sendAddModelNotification('ProviderOrderAfipTicketIva', $model->id);

        $this->recalcular_factura_y_compra($model->provider_order_afip_ticket_id);

        return response()->json(['model' => $this->fullModel('ProviderOrderAfipTicketIva', $model->id)], 201);
    }

    public function show($id) {
        return response()->json(['model' => $this->fullModel('ProviderOrderAfipTicketIva', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = ProviderOrderAfipTicketIva::find($id);

        /*
         * Se chequea contra la factura a la que la alícuota pertenece HOY, no contra la que venga
         * en el request: lo que se está por pisar es esta fila, y el que manda es el modo de
         * facturación de su compra actual.
         */
        if (FacturaDeCompraHelper::factura_en_modo_automatico($model->provider_order_afip_ticket_id)) {
            return response()->json(['message' => self::MENSAJE_MODO_AUTOMATICO], 422);
        }

        $model->provider_order_afip_ticket_id       = $request->provider_order_afip_ticket_id;
        $model->iva_id                              = $request->iva_id;
        $model->neto                                = $request->neto;
        $model->iva_importe                         = $request->iva_importe;
        $model->save();
        $this->sendAddModelNotification('ProviderOrderAfipTicketIva', $model->id);

        $this->recalcular_factura_y_compra($model->provider_order_afip_ticket_id);

        return response()->json(['model' => $this->fullModel('ProviderOrderAfipTicketIva', $model->id)], 200);
    }

    public function destroy($id) {
        $model = ProviderOrderAfipTicketIva::find($id);

        if (FacturaDeCompraHelper::factura_en_modo_automatico($model->provider_order_afip_ticket_id)) {
            return response()->json(['message' => self::MENSAJE_MODO_AUTOMATICO], 422);
        }

        // Se guarda antes de borrar, por el mismo motivo que en la factura: después del delete no
        // hay de dónde volver a leer a qué factura colgaba esta alícuota.
        $provider_order_afip_ticket_id = $model->provider_order_afip_ticket_id;

        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('ProviderOrderAfipTicketIva', $model->id);

        $this->recalcular_factura_y_compra($provider_order_afip_ticket_id);

        return response(null);
    }

    /**
     * Rehace los totales de la factura a la que cuelga esta alícuota y, con ellos, el total de la
     * compra y la deuda con el proveedor.
     *
     * Las alícuotas son los sumandos del total de la factura (`Σ neto + iva_importe`), así que
     * agregar, editar o borrar una lo cambia — y ese total, con
     * `total_from_provider_order_afip_tickets` prendido, es el que arma el total de la compra. Sin
     * este paso, editar una alícuota dejaba los tres números (factura, compra, cuenta corriente)
     * desalineados hasta el próximo guardado manual de la compra entera.
     *
     * No hace nada si la alícuota todavía no cuelga de ninguna factura (alta con `temporal_id`
     * desde el formulario): en ese caso los totales los calcula el store de la factura, cuando la
     * engancha.
     *
     * @param  int|string|null  $provider_order_afip_ticket_id
     * @return void
     */
    protected function recalcular_factura_y_compra($provider_order_afip_ticket_id)
    {
        if (is_null($provider_order_afip_ticket_id) || $provider_order_afip_ticket_id === '') {
            return;
        }

        $ticket = ProviderOrderAfipTicket::find($provider_order_afip_ticket_id);

        if (is_null($ticket)) {
            return;
        }

        FacturaDeCompraHelper::guardar_totales($ticket);

        FacturaDeCompraHelper::recalcular_compra($ticket->provider_order_id);
    }
}
