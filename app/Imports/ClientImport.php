<?php

namespace App\Imports;

use App\Http\Controllers\CommonLaravel\Helpers\ImportHelper;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Http\Controllers\Helpers\LocalImportHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Client;
use App\Models\CurrentAcount;
use App\Models\User;
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
            'cuil'                    =>    'cuil',
            'dni'                     =>    'dni',
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
            
        $user = User::find(UserHelper::userId());

        $functions_to_execute = [
            [
                'btn_text'      => 'Actualizar lista de clientes',
                'function_name' => 'update_clients_after_import',
                'btn_variant'   => 'primary',
            ],
        ];


        $user->notify(new GlobalNotification([
            'message_text'              => 'Importacion de Excel finalizada correctamente',
            'color_variant'             => 'success',
            'functions_to_execute'      => $functions_to_execute,
            'info_to_show'              => [],
            'owner_id'                  => $user->id,
            'is_only_for_auth_user'     => false,
        ]));
    }

    function saveModel($row, $client) {
        $existing_client = !is_null($client);
        $data = [];
        foreach ($this->props_to_set as $key => $value) {
            if (!is_null(ImportHelper::getColumnValue($row, $value, $this->columns))) {

                // CUIT y CUIL se normalizan sin guiones, igual que en ClientController.
                if ($key == 'cuit' || $key == 'cuil') {
                    $documento = ImportHelper::getColumnValue($row, $value, $this->columns);
                    $documento = str_replace('-', '', $documento);

                    $data[$key] = $documento;
                } else {

                    $data[$key] = ImportHelper::getColumnValue($row, $value, $this->columns);
                }
            }
        }
        $iva_condition_excel = ImportHelper::getColumnValueByAliases($row, [
            'condicion_frente_al_iva',
            'condicion frente al iva',
        ], $this->columns);

        if (!is_null($iva_condition_excel)) {
            $iva_condition_id = LocalImportHelper::getIvaConditionId($iva_condition_excel);

            if (!is_null($iva_condition_id)) {
                $data['iva_condition_id'] = $iva_condition_id;
            } else {
                Log::warning('Importacion clientes: condicion frente al iva no reconocida ['.$iva_condition_excel.']');
            }
        }
        // Provincia y localidad: la localidad se resuelve dentro de su provincia para permitir homónimos.
        $provincia_name = ImportHelper::getColumnValue($row, 'provincia', $this->columns);
        $localidad_name = ImportHelper::getColumnValue($row, 'localidad', $this->columns);
        $provincia_id = null;

        if (!is_null($provincia_name)) {
            $provincia_id = LocalImportHelper::saveProvincia($provincia_name, $this->ct);
            $data['provincia_id'] = $provincia_id;
        }

        if (!is_null($localidad_name)) {
            if (!is_null($provincia_id)) {
                $data['location_id'] = LocalImportHelper::saveLocationWithProvincia($localidad_name, $provincia_id, $this->ct);
            } else {
                LocalImportHelper::saveLocation($localidad_name, $this->ct);
                $data['location_id'] = $this->ct->getModelBy('locations', 'name', $localidad_name, true, 'id');
            }
        }
        if (!is_null(ImportHelper::getColumnValue($row, 'vendedor', $this->columns))) {
            LocalImportHelper::saveSeller(ImportHelper::getColumnValue($row, 'vendedor', $this->columns), $this->ct);
            $data['seller_id'] = $this->ct->getModelBy('sellers', 'name', ImportHelper::getColumnValue($row, 'vendedor', $this->columns), true, 'id');
        }
        if (!is_null(ImportHelper::getColumnValueByAliases($row, ['tipo_de_precio', 'tipo de precio'], $this->columns))) {
            $tipo_de_precio = ImportHelper::getColumnValueByAliases($row, ['tipo_de_precio', 'tipo de precio'], $this->columns);
            LocalImportHelper::savePriceType($tipo_de_precio, $this->ct);
            $data['price_type_id'] = $this->ct->getModelBy('price_types', 'name', $tipo_de_precio, true, 'id');
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
            
            CreditAccountHelper::crear_credit_accounts('client', $client->id);

            Log::info('creando cliente '.$client->name);
        }

        if (!is_null($client)) {
            LocalImportHelper::procesarSaldoImportacion($row, $this->columns, 'client', $client, $existing_client);
        }
    }

    function isDataUpdated($client, $data) {
        return  (isset($data['name']) && $data['name']                              != $client->name) ||
                (isset($data['phone']) && $data['phone']                            != $client->phone) ||
                (isset($data['address']) && $data['address']                        != $client->address) ||
                (isset($data['email']) && $data['email']                            != $client->email) ||
                (isset($data['razon_social']) && $data['razon_social']              != $client->razon_social) ||
                (isset($data['cuit']) && $data['cuit']                              != $client->cuit) ||
                (isset($data['cuil']) && $data['cuil']                              != $client->cuil) ||
                (isset($data['dni']) && $data['dni']                                != $client->dni) ||
                (isset($data['description']) && $data['description']                != $client->description) ||
                (isset($data['iva_condition_id']) && $data['iva_condition_id']      != $client->iva_condition_id) ||
                (isset($data['provincia_id']) && $data['provincia_id']              != $client->provincia_id) ||
                (isset($data['location_id']) && $data['location_id']                != $client->location_id) ||
                (isset($data['seller_id']) && $data['seller_id']                    != $client->seller_id) ||
                (isset($data['price_type_id']) && $data['price_type_id']            != $client->price_type_id);
    }
}
