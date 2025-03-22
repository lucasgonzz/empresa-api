<?php

namespace App\Imports;

use App\Http\Controllers\CommonLaravel\Helpers\ImportHelper;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\LocalImportHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\import\ClientImportHelper;
use App\Models\Client;
use App\Models\CurrentAcount;
use App\Notifications\GlobalNotification;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ClientImport implements ToCollection {

    function __construct($columns, $create_and_edit, $start_row, $finish_row) {
        $this->columns = $columns;
        $this->create_and_edit = $create_and_edit;
        Log::info($this->columns);
        $this->start_row = $start_row;
        $this->finish_row = $finish_row;
        $this->ct = new Controller();
        $this->setProps();
    }

    function setProps() {
        $this->props_to_set = [
            'name'                    =>    'nombre',                    
            'phone'                   =>    'telefono',                  
            'address'                 =>    'direccion',                 
            'email'                   =>    'email',                     
            'razon_social'            =>    'razon_social',             
            'cuit'                    =>    'cuit',                      
            'description'             =>    'descripcion',               
        ];       
    }

    function checkRow($row) {
        return !is_null(ImportHelper::getColumnValue($row, 'nombre', $this->columns));
    }

    public function collection(Collection $rows) {
        $this->num_row = 1;
        if (is_null($this->finish_row) || $this->finish_row == '') {
            $this->finish_row = count($rows);
        } 
        foreach ($rows as $row) {
            if ($this->num_row >= $this->start_row && $this->num_row <= $this->finish_row) {
                if ($this->checkRow($row)) {
                    $client = Client::where('user_id', $this->ct->userId());
                    if (!is_null(ImportHelper::getColumnValue($row, 'numero', $this->columns))) {
                        $client = $client->where('num', ImportHelper::getColumnValue($row, 'numero', $this->columns));
                    } else {
                        $client = $client->where('name', ImportHelper::getColumnValue($row, 'nombre', $this->columns));
                    }
                    $client = $client->first();
                    $this->saveModel($row, $client);
                }
            } else if ($this->num_row > $this->finish_row) {
                break;
            }
            $this->num_row++;
        }

        $this->enviar_notificacion();
    }

    function enviar_notificacion() {
            
        $user = UserHelper::user();

        $functions_to_execute = [
            [
                'btn_text'      => 'Actualizar lista de clientes',
                'function_name' => 'update_clients_after_import',
                'btn_variant'   => 'primary',
            ],
        ];

        $user->notify(new GlobalNotification(
            'Importacion de Excel finalizada correctamente',
            'success',
            $functions_to_execute,
            $user->id,
            false,
        ));
    }

    function saveModel($row, $client) {
        $data = [];
        foreach ($this->props_to_set as $key => $value) {
            if (!ImportHelper::isIgnoredColumn($value, $this->columns)) {
                $data[$key] = ImportHelper::getColumnValue($row, $value, $this->columns);
            }
        }
        if (!ImportHelper::isIgnoredColumn('condicion_frente_al_iva', $this->columns)) {
            $data['iva_condition_id'] = $this->ct->getModelBy('iva_conditions', 'name', ImportHelper::getColumnValue($row, 'condicion_frente_al_iva', $this->columns), false, 'id');
        }
        if (!ImportHelper::isIgnoredColumn('localidad', $this->columns)) {

            // if (
            //     env('FOR_USER') == 'golo_norte'
            //     && env('APP_ENV') == 'local'
            // ) {

            //     $res = ClientImportHelper::formateo_golonorte($row, $this->columns);

            //     if ($res['localidad']) {
            //         $data['address'] = $res['direccion'];

            //         LocalImportHelper::saveLocation($res['localidad'], $this->ct);
                    
            //         $data['location_id'] = $this->ct->getModelBy('locations', 'name', $res['localidad'], true, 'id');
            //     }
            // } else {

                LocalImportHelper::saveLocation(ImportHelper::getColumnValue($row, 'localidad', $this->columns), $this->ct);

                $data['location_id'] = $this->ct->getModelBy('locations', 'name', ImportHelper::getColumnValue($row, 'localidad', $this->columns), true, 'id');
            // }

        }
        if (!ImportHelper::isIgnoredColumn('vendedor', $this->columns)) {
            LocalImportHelper::saveSeller(ImportHelper::getColumnValue($row, 'vendedor', $this->columns), $this->ct);
            $data['seller_id'] = $this->ct->getModelBy('sellers', 'name', ImportHelper::getColumnValue($row, 'vendedor', $this->columns), true, 'id');
        }
        if (!ImportHelper::isIgnoredColumn('tipo_de_precio', $this->columns)) {
            LocalImportHelper::savePriceType(ImportHelper::getColumnValue($row, 'tipo_de_precio', $this->columns), $this->ct);
            $data['price_type_id'] = $this->ct->getModelBy('price_types', 'name', ImportHelper::getColumnValue($row, 'tipo_de_precio', $this->columns), true, 'id');
        }
        // Log::info('data');
        // Log::info($data);
        if (!is_null($client) && $this->isDataUpdated($client, $data)) {
            Log::info('actualizando cliente '.$client->name);
            $client->update($data);
        } else if (is_null($client) && $this->create_and_edit) {
            if (!is_null(ImportHelper::getColumnValue($row, 'numero', $this->columns))) {
                $data['num'] = ImportHelper::getColumnValue($row, 'numero', $this->columns);
                Log::info('saco num del excel, data:');
                Log::info($data);
            } else {
                $data['num'] = $this->ct->num('clients');
            }
            $data['user_id'] = $this->ct->userId();
            $data['created_at'] = Carbon::now()->subSeconds($this->finish_row - $this->num_row);
            $client = Client::create($data);
            Log::info('creando cliente '.$client->name);
        }
        LocalImportHelper::setSaldoInicial($row, $this->columns, 'client', $client);
    }

    function isDataUpdated($client, $data) {
        return  (isset($data['name']) && $data['name']                              != $client->name) ||
                (isset($data['phone']) && $data['phone']                            != $client->phone) ||
                (isset($data['address']) && $data['address']                        != $client->address) ||
                (isset($data['email']) && $data['email']                            != $client->email) ||
                (isset($data['razon_social']) && $data['razon_social']              != $client->razon_social) ||
                (isset($data['cuit']) && $data['cuit']                              != $client->cuit) ||
                (isset($data['description']) && $data['description']                != $client->description) ||
                (isset($data['iva_condition_id']) && $data['iva_condition_id']      != $client->iva_condition_id) ||
                (isset($data['location_id']) && $data['location_id']                != $client->location_id) ||
                (isset($data['seller_id']) && $data['seller_id']                    != $client->seller_id) ||
                (isset($data['price_type_id']) && $data['price_type_id']            != $client->price_type_id);
    }
}
