<?php

namespace App\Imports;

use App\Http\Controllers\CommonLaravel\Helpers\ImportHelper;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\LocalImportHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Client;
use App\Models\CurrentAcount;
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
    }

    function saveModel($row, $client) {
        // LocalImportHelper::saveLocation(ImportHelper::getColumnValue($row, 'localidad', $this->columns), $this->ct);
        // LocalImportHelper::savePriceType(ImportHelper::getColumnValue($row, 'tipo_de_precio', $this->columns), $this->ct);
        // $data = [
        //     'name'              => ImportHelper::getColumnValue($row, 'nombre', $this->columns),
        //     'phone'             => ImportHelper::getColumnValue($row, 'telefono', $this->columns),
        //     'address'           => ImportHelper::getColumnValue($row, 'direccion', $this->columns),
        //     'location_id'       => $this->ct->getModelBy('locations', 'name', ImportHelper::getColumnValue($row, 'localidad', $this->columns), true, 'id'),
        //     'email'             => ImportHelper::getColumnValue($row, 'email', $this->columns),
        //     'iva_condition_id'  => $this->ct->getModelBy('iva_conditions', 'name', ImportHelper::getColumnValue($row, 'condicion_frente_al_iva', $this->columns), false, 'id'),
        //     'razon_social'      => ImportHelper::getColumnValue($row, 'razon_social', $this->columns),
        //     'cuit'              => ImportHelper::getColumnValue($row, 'cuit', $this->columns),
        //     'description'       => ImportHelper::getColumnValue($row, 'descripcion', $this->columns),
        //     'price_type_id'     => $this->ct->getModelBy('price_types', 'name', ImportHelper::getColumnValue($row, 'tipo_de_precio', $this->columns), true, 'id'),
        // ];

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
            LocalImportHelper::saveLocation(ImportHelper::getColumnValue($row, 'localidad', $this->columns), $this->ct);
            $data['location_id'] = $this->ct->getModelBy('locations', 'name', ImportHelper::getColumnValue($row, 'localidad', $this->columns), true, 'id');
        }
        if (!ImportHelper::isIgnoredColumn('vendedor', $this->columns)) {
            LocalImportHelper::saveSeller(ImportHelper::getColumnValue($row, 'vendedor', $this->columns), $this->ct);
            $data['seller_id'] = $this->ct->getModelBy('sellers', 'name', ImportHelper::getColumnValue($row, 'vendedor', $this->columns), true, 'id');
        }
        if (!ImportHelper::isIgnoredColumn('tipo_de_precio', $this->columns)) {
            LocalImportHelper::savePriceType(ImportHelper::getColumnValue($row, 'tipo_de_precio', $this->columns), $this->ct);
            $data['price_type_id'] = $this->ct->getModelBy('price_types', 'name', ImportHelper::getColumnValue($row, 'tipo_de_precio', $this->columns), true, 'id');
        }
        Log::info('data');
        Log::info($data);
        if (!is_null($client) && $this->isDataUpdated($client, $data)) {
            Log::info('actualizando cliente '.$client->name);
            $client->update($data);
        } else if (is_null($client) && $this->create_and_edit) {
            if (isset($row['numero']) && $row['numero'] != '') {
                $data['num'] = $row['numero'];
            } else {
                $data['num'] = $this->ct->num('clients');
            }
            $data['user_id'] = $this->ct->userId();
            $data['created_at'] = Carbon::now()->subSeconds($this->finish_row - $this->num_row);
            $client = Client::create($data);
            Log::info('creando cliente '.$client->name);
        }
        if (!is_null(ImportHelper::getColumnValue($row, 'saldo_actual', $this->columns))) {
            $current_acounts = CurrentAcount::where('client_id', $client->id)
                                            ->get();
            if (count($current_acounts) == 0) {
                $is_for_debe = false;
                $saldo_inicial = (float)ImportHelper::getColumnValue($row, 'saldo_actual', $this->columns);
                if ($saldo_inicial >= 0) {
                    $is_for_debe = true;
                }
                $current_acount = CurrentAcount::create([
                    'detalle'   => 'Saldo inicial',
                    'status'    => $is_for_debe ? 'sin_pagar' : 'pago_from_client',
                    'client_id' => $client->id,
                    'debe'      => $is_for_debe ? $saldo_inicial : null,
                    'haber'     => !$is_for_debe ? $saldo_inicial : null,
                    'saldo'     => $saldo_inicial,
                ]);
            }
        }
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
