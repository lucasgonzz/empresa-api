<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\Afip\LeyendaIsibCabaHelper;
use App\Models\AfipInformation;
use Illuminate\Http\Request;

class AfipInformationController extends Controller
{

    public function index() {
        $models = AfipInformation::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $this->validar_isib_caba($request);
        /** Persiste configuración AFIP incluyendo nombre opcional del dueño. */
        $model = AfipInformation::create([
            // 'num'                       => $this->num('afip_information'),
            'iva_condition_id'          => $request->iva_condition_id,
            'razon_social'              => $request->razon_social,
            'owner_name'                => $request->owner_name,
            'domicilio_comercial'       => $request->domicilio_comercial,
            'cuit'                      => $request->cuit,
            'ingresos_brutos'           => $request->ingresos_brutos,
            'inicio_actividades'        => $request->inicio_actividades,
            'punto_venta'               => $request->punto_venta,
            'afip_ticket_production'    => $request->afip_ticket_production,
            'address_id'                => $request->address_id,
            'description'               => $request->description,
            'user_id'                   => $this->userId(),
        ]);
        $this->set_isib_caba($model, $request);
        $model->save();
        $this->sendAddModelNotification('afip_information', $model->id);
        return response()->json(['model' => $this->fullModel('AfipInformation', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('AfipInformation', $id)], 200);
    }

    public function update(Request $request, $id) {
        $this->validar_isib_caba($request);
        $model = AfipInformation::find($id);
        $model->iva_condition_id          = $request->iva_condition_id;
        $model->razon_social              = $request->razon_social;
        $model->owner_name                = $request->owner_name;
        $model->domicilio_comercial       = $request->domicilio_comercial;
        $model->cuit                      = $request->cuit;
        $model->ingresos_brutos           = $request->ingresos_brutos;
        $model->inicio_actividades        = $request->inicio_actividades;
        $model->punto_venta               = $request->punto_venta;
        $model->afip_ticket_production    = $request->afip_ticket_production;
        $model->address_id                = $request->address_id;
        $model->description               = $request->description;
        $this->set_isib_caba($model, $request);
        $model->save();
        $this->sendAddModelNotification('afip_information', $model->id);
        return response()->json(['model' => $this->fullModel('AfipInformation', $model->id)], 200);
    }

    /**
     * Un valor fuera de rango (300 tipeado por 3,00) se rechaza con 422 en vez de guardarse en
     * silencio como null: el usuario veria "guardado" y la leyenda no saldria nunca. Se llama al
     * PRINCIPIO de store/update, antes de crear o tocar nada.
     *
     * @param \Illuminate\Http\Request $request
     * @return void
     */
    protected function validar_isib_caba(Request $request) {
        $request->validate([
            'isib_caba_alicuota' => 'nullable|numeric|min:0|max:100',
        ], [
            'isib_caba_alicuota.numeric' => 'La alícuota de Ingresos Brutos CABA tiene que ser un número (por ejemplo 3 o 3.5).',
            'isib_caba_alicuota.min'     => 'La alícuota de Ingresos Brutos CABA no puede ser negativa.',
            'isib_caba_alicuota.max'     => 'La alícuota de Ingresos Brutos CABA es un porcentaje: no puede pasar de 100.',
        ]);
    }

    /**
     * Leyenda ISIB CABA (Res. 169/AGIP/2026): alicuota y Convenio Multilateral del punto de venta.
     *
     * Cada campo se toca SOLO si viene en el request: un SPA viejo (cacheado, o sin desplegar)
     * que no los conoce no los manda, y no tiene que borrarle la configuracion al negocio.
     * La alicuota vacia o cero se guarda como null (no se imprime leyenda).
     *
     * @param \App\Models\AfipInformation $model
     * @param \Illuminate\Http\Request $request
     * @return void
     */
    protected function set_isib_caba($model, Request $request) {
        if ($request->has('isib_caba_alicuota')) {
            $model->isib_caba_alicuota = LeyendaIsibCabaHelper::alicuota_configurada($request->isib_caba_alicuota);
        }
        if ($request->has('isib_caba_convenio_multilateral')) {
            // boolean() y no (bool): "false" u "off" como string tienen que dar false.
            $model->isib_caba_convenio_multilateral = $request->boolean('isib_caba_convenio_multilateral');
        }
    }

    public function destroy($id) {
        $model = AfipInformation::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('AfipInformation', $model->id);
        return response(null);
    }
}
