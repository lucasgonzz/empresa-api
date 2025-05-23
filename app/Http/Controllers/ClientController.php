<?php

namespace App\Http\Controllers;

use App\Exports\ClientExport;
use App\Http\Controllers\AfipConstanciaInscripcionController;
use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\CommonLaravel\SearchController;
use App\Http\Controllers\Pdf\ClientsPdf;
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
                            ->paginate(100);
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = Client::create([
            'num'                       => $this->num('clients'),
            'name'                      => $request->name,
            'email'                     => $request->email,
            'phone'                     => $request->phone,
            'address'                   => $request->address,
            'cuil'                      => $this->getCuit($request->cuil),
            'cuit'                      => $this->getCuit($request->cuit),
            'dni'                       => $request->dni,
            'razon_social'              => $request->razon_social,
            'iva_condition_id'          => $request->iva_condition_id,
            'price_type_id'             => $request->price_type_id,
            'location_id'               => $request->location_id,
            'description'               => $request->description,
            'saldo'                     => $request->saldo,
            'comercio_city_user_id'     => $request->comercio_city_user_id,
            'seller_id'                 => $request->seller_id,
            'link_google_maps'          => $request->link_google_maps,
            'pasar_ventas_a_la_cuenta_corriente_sin_esperar_a_facturar'                 => $request->pasar_ventas_a_la_cuenta_corriente_sin_esperar_a_facturar,
            'address_id'                => $request->address_id,
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
        $model->cuil                        = $this->getCuit($request->cuil);
        $model->cuit                        = $this->getCuit($request->cuit);
        $model->dni                         = $request->dni;
        $model->razon_social                = $request->razon_social;
        $model->iva_condition_id            = $request->iva_condition_id;
        $model->price_type_id               = $request->price_type_id;
        $model->location_id                 = $request->location_id;
        $model->description                 = $request->description;
        $model->saldo                       = $request->saldo;
        $model->comercio_city_user_id       = $request->comercio_city_user_id;
        $model->seller_id                   = $request->seller_id;
        $model->pasar_ventas_a_la_cuenta_corriente_sin_esperar_a_facturar                   = $request->pasar_ventas_a_la_cuenta_corriente_sin_esperar_a_facturar;
        $model->address_id                  = $request->address_id;
        $model->link_google_maps                  = $request->link_google_maps;
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
        Excel::import(new ClientImport($columns, $request->create_and_edit, $request->start_row, $request->finish_row), $request->file('models'));
    }

    function export() {
        return Excel::download(new ClientExport, 'comerciocity-clientes '.date_format(Carbon::now(), 'd-m-y H:m').'.xlsx');
    }

    function getCuit($cuit) {
        return str_replace('-', '', $cuit);
    }

    function get_afip_information_by_cuit($cuit) {
        $ct = new AfipConstanciaInscripcionController();
        $data = $ct->get_constancia_inscripcion($cuit);

        if ($data['hubo_un_error']) {
            return response()->json([
                'hubo_un_error'     => true,
                'error'             => $data['error'],
            ]);
        } else {
            $client_model = Client::where('user_id', $this->userId())
                                    ->where('cuit', $cuit)
                                    ->withAll()
                                    ->first();
            return response()->json([
                'client_model'  => $client_model,
                'afip_data'     => $data['afip_data'],
            ]);
        }
    }

    function pdf(Request $request) {

        $jsonData = $request->query('filters');
        $filters = json_decode($jsonData, true);

        $search_ct = new SearchController();
        $models = $search_ct->search($request, 'client', $filters);

        new ClientsPdf($models);
    }
}
