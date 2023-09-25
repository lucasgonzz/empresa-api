<?php

namespace App\Imports;

use App\Http\Controllers\CommonLaravel\Helpers\ImportHelper;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\LocalImportHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Provider;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ProviderImport implements ToCollection
{
    
    public function __construct($columns, $create_and_edit, $start_row, $finish_row, $provider_id) {
        $this->columns = $columns;
        $this->create_and_edit = $create_and_edit;
        $this->start_row = $start_row;
        $this->finish_row = $finish_row;
        $this->ct = new Controller();
        $this->provider_id = $provider_id;
        $this->provider = null;
        $this->created_models = 0;
        $this->updated_models = 0;
        $this->setProps();
    }

    function setProps() {
        $this->props_to_set = [
            'num'               => 'numero',
            'name'              => 'nombre',
            'phone'             => 'telefono',
            'address'           => 'direccion',
            'location_id'       => 'localidad',
            'email'             => 'email',
            'iva_condition_id'  => 'condicion_frente_al_iva',
            'razon_social'      => 'razon_social',
            'cuit'              => 'cuit',
            'observations'      => 'observaciones',
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
                    $provider = Provider::where('user_id', $this->ct->userId());
                    if (!is_null(ImportHelper::getColumnValue($row, 'numero', $this->columns))) {
                        $provider = $provider->where('num', ImportHelper::getColumnValue($row, 'numero', $this->columns));
                    } else {
                        $provider = $provider->where('name', ImportHelper::getColumnValue($row, 'nombre', $this->columns));
                    }
                    $provider = $provider->first();
                    $this->saveModel($row, $provider);
                }
            } else if ($this->num_row > $this->finish_row) {
                break;
            }
            $this->num_row++;
        }
    }

    function saveModel($row, $provider) {
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
        // Log::info('data');
        // Log::info($data);
        if (!is_null($provider) && $this->isDataUpdated($provider, $data)) {
            Log::info('actualizando proveedor '.$provider->name);
            $provider->update($data);
        } else if (is_null($provider) && $this->create_and_edit) {
            if (!is_null(ImportHelper::getColumnValue($row, 'numero', $this->columns))) {
                $data['num'] = ImportHelper::getColumnValue($row, 'numero', $this->columns);
            } else {
                $data['num'] = $this->ct->num('providers');
            }
            $data['user_id'] = $this->ct->userId();
            $data['created_at'] = Carbon::now()->subSeconds($this->finish_row - $this->num_row);
            $provider = Provider::create($data);
            Log::info('se creo proveedor '.$provider->name.' con la data: ');
            Log::info($data);
        }
        LocalImportHelper::setSaldoInicial($row, $this->columns, 'provider', $provider);
    }

    function isDataUpdated($provider, $data) {
        return  (isset($data['name']) && $data['name']                              != $provider->name) ||
                (isset($data['phone']) && $data['phone']                            != $provider->phone) ||
                (isset($data['address']) && $data['address']                        != $provider->address) ||
                (isset($data['email']) && $data['email']                            != $provider->email) ||
                (isset($data['razon_social']) && $data['razon_social']              != $provider->razon_social) ||
                (isset($data['cuit']) && $data['cuit']                              != $provider->cuit) ||
                (isset($data['observations']) && $data['observations']                != $provider->observations) ||
                (isset($data['iva_condition_id']) && $data['iva_condition_id']      != $provider->iva_condition_id) ||
                (isset($data['location_id']) && $data['location_id']                != $provider->location_id);
    }
}
