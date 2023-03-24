<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\Provider;
use Illuminate\Http\Request;

class ProviderController extends Controller
{

    public function index() {
        $models = Provider::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = Provider::create([
            'num'                   => $this->num('Providers'),
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
            'dolar'                 => $request->dolar, 
            'user_id'               => $this->userId(),
        ]);

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
        $model->save();
        GeneralHelper::checkNewValuesForArticlesPrices($this, $last_percentage_gain, $model->percentage_gain, 'provider_id', $model->id);
        GeneralHelper::checkNewValuesForArticlesPrices($this, $last_dolar, $model->dolar, 'provider_id', $model->id);
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
}
