<?php

namespace App\Http\Controllers;

use App\Exports\ClientExport;
use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\ImageController;
use App\Imports\ClientImport;
use App\Models\Client;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ClientController extends Controller
{

    public function index() {
        $models = Client::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->paginate(25);
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = Client::create([
            'num'                       => $this->num('clients'),
            'name'                      => $request->name,
            'email'                     => $request->email,
            'phone'                     => $request->phone,
            'address'                   => $request->address,
            'cuit'                      => $request->cuit,
            'razon_social'              => $request->razon_social,
            'iva_condition_id'          => $request->iva_condition_id,
            'price_type_id'             => $request->price_type_id,
            'location_id'               => $request->location_id,
            'description'               => $request->description,
            'saldo'                     => $request->saldo,
            'comercio_city_user_id'     => $request->comercio_city_user_id,
            'seller_id'                 => $request->seller_id,
            'user_id'                   => $this->userId(),
        ]);
        $this->sendAddModelNotification('Client', $model->id);
        return response()->json(['model' => $this->fullModel('Client', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('Client', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = Client::find($id);
        $model->name                        = $request->name;
        $model->email                       = $request->email;
        $model->phone                       = $request->phone;
        $model->address                     = $request->address;
        $model->cuit                        = $request->cuit;
        $model->razon_social                = $request->razon_social;
        $model->iva_condition_id            = $request->iva_condition_id;
        $model->price_type_id               = $request->price_type_id;
        $model->location_id                 = $request->location_id;
        $model->description                 = $request->description;
        $model->saldo                       = $request->saldo;
        $model->comercio_city_user_id       = $request->comercio_city_user_id;
        $model->seller_id                   = $request->seller_id;
        $model->save();
        $this->sendAddModelNotification('Client', $model->id);
        return response()->json(['model' => $this->fullModel('Client', $model->id)], 200);
    }

    public function destroy($id) {
        $model = Client::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('Client', $model->id);
        return response(null);
    }

    function import(Request $request) {
        $columns = GeneralHelper::getImportColumns($request);
        Excel::import(new ClientImport($columns, $request->start_row, $request->finish_row), $request->file('models'));
    }

    function export() {
        return Excel::download(new ClientExport, 'comerciocity-clientes'.date_format(Carbon::now(), 'd-m-y').'.xlsx');
    }
}
