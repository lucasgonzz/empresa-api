<?php

namespace App\Http\Controllers;

use App\Exports\ProviderExport;
use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Imports\ProviderImport;
use App\Models\Provider;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ProviderController extends Controller
{

    public function index() {
        $models = Provider::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->where('status', 'active')
                            ->paginate(100);
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = Provider::create([
            'num'                   => $this->num('providers'),
            'name'                  => $request->name,  
            'phone'                 => $request->phone, 
            'address'               => $request->address,   
            'email'                 => $request->email, 
            'razon_social'          => $request->razon_social,  
            'cuit'                  => $request->cuit,  
            'observations'          => $request->observations,  
            'location_id'           => $request->location_id,   
            'iva_condition_id'      => $request->iva_condition_id,  
            'percentage_gain'       => $request->percentage_gain,   
            'porcentaje_comision_negro'       => $request->porcentaje_comision_negro,   
            'porcentaje_comision_blanco'      => $request->porcentaje_comision_blanco,   
            'dolar'                 => $request->dolar, 
            'user_id'               => $this->userId(),
        ]);

        CreditAccountHelper::crear_credit_accounts('provider', $model->id);

        $this->updateRelationsCreated('Provider', $model->id, $request->childrens);
        $this->sendAddModelNotification('Provider', $model->id);
        return response()->json(['model' => $this->fullModel('Provider', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('Provider', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = Provider::find($id);
        $last_percentage_gain       = $model->percentage_gain;
        $last_dolar                 = $model->dolar;
        $model->name                = $request->name;
        $model->phone               = $request->phone; 
        $model->address             = $request->address;   
        $model->email               = $request->email; 
        $model->razon_social        = $request->razon_social;  
        $model->cuit                = $request->cuit;  
        $model->observations        = $request->observations;  
        $model->location_id         = $request->location_id;   
        $model->iva_condition_id    = $request->iva_condition_id;  
        $model->percentage_gain     = $request->percentage_gain;   
        $model->dolar               = $request->dolar; 
        $model->porcentaje_comision_negro               = $request->porcentaje_comision_negro; 
        $model->porcentaje_comision_blanco              = $request->porcentaje_comision_blanco; 
        $model->save();


        $should_update_prices = false;

        if ($last_percentage_gain != $model->percentage_gain) {
            $should_update_prices = true;
        }

        if ($last_dolar != $model->dolar) {
            $should_update_prices = true;
        }

        if ($should_update_prices) {
            GeneralHelper::checkNewValuesForArticlesPrices($this, 0, 1, 'provider_id', $model->id);
        }

        $this->sendAddModelNotification('Provider', $model->id);
        return response()->json(['model' => $this->fullModel('Provider', $model->id)], 200);
    }

    public function destroy($id) {
        $model = Provider::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('Provider', $model->id);
        return response(null);
    }

    function import(Request $request) {
        $columns = GeneralHelper::getImportColumns($request);
        Excel::import(new ProviderImport($columns, $request->create_and_edit, $request->start_row, $request->finish_row, $request->provider_id), $request->file('models'));
    }

    function export() {
        return Excel::download(new ProviderExport, 'comerciocity-proveedores '.date_format(Carbon::now(), 'd-m-y H:m').'.xlsx');
    }
}
